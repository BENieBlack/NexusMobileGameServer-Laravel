<?php

namespace Database\Seeders\Recording\Scenarios;

use Database\Seeders\MstConfigSeeder;
use Database\Seeders\MstPlayerLevelSeeder;
use Database\Seeders\Recording\RecordingMasterExporter;
use Database\Seeders\Recording\RecordingScenarioSeeder;
use Illuminate\Support\Facades\DB;
use Nexus\Core\Utilities\ClockUtility;
use NexusVersion\Constants\DeployKeyConst;

/**
 * RecordingDeploySeeder
 *
 * 目的: 動画撮影用のデプロイを用意する。次の 2 つを満たす。
 * 1. sys_deploy が空でも version API（GET /api/auth/version）が成功する。有効かつ start_at を過ぎた
 *    sys_deploy が 1 件も無いと SystemDataException（299）になり、タイトル画面の最初で止まる。
 * 2. 配信ファイルとして `mst_player_level`（スタミナ最大値）と `mst_config`（回復間隔）を取得できる。
 *    home-player-info のマスター差分・スタミナ表示を撮るために必要。
 *
 * 配信するのは mst の全 `mst_*` テーブル（運営ツールの書き出しと同じ範囲）。2 テーブルだけにしないのは、
 * クライアントが更新 DB を「配信されたテーブルだけ」で組み、必須テーブル（mst_gacha・mst_unit・mst_equipment）が
 * 欠けると切り替えに失敗し、配信されないテーブルは同梱マスターで補われず空になるため
 * （master_data/data/local/master_database.gd の open_downloaded）。
 *
 * 作るもの: sys_deploy / sys_deploy_master / sys_deploy_asset 各 1 行と、sys_deploy_master_table
 * （mst のテーブルグループごとに 1 行。mst_player_level・mst_config を必ず含む）。
 * SQLite は RecordingMasterExporter が mst DB の現在の行から書き出し、
 * config('recording.masterdata_dir')（tool-web が配信する tool/public/masterdata を api-php にマウントした先）
 * の `{マニフェストハッシュ}/` に置く。mst の両テーブルが空なら、先に MstPlayerLevelSeeder・MstConfigSeeder で入れる
 * （行があるときは触らない。これらは truncate するため。mst_player_level は string の id を持ち、
 * MstPlayerLevelSeeder が MstPlayerLevel::makeId で作る）。
 *
 * deploy_key は予約キー（DEPLOY_KEY = 7777777777）。version API は start_at が現在以前の有効なデプロイのうち
 * start_at が最も新しいものを最新とする（同値は deploy_key 降順 → id 降順。deploy_key の大小では決まらない。
 * 設計: db.md 3.3・11.2）。録画用は start_at = 現在で登録するので、本物があっても録画用が最新になる。
 * start_at が未来の行（予約済みの版）がある環境では、その版が start_at に達すると最新が入れ替わり録画用が
 * 黙って無効になるので、何も追加しない（理由を表示する）。
 * 追加した場合は、録画用が最新になり、その間は本物のデプロイの配信ファイルが master.tables に出ない
 * （録画用の配信ファイルだけになる）。
 *
 * 暫定（db.md 11.2。R19 の細部が決まるまで）: sys_deploy_master の mst_database は版の DB 名の形
 * （{基底 DB}-7777777777-{hash 先頭 16 文字}）。このシーダーは実体の DB を作らず、その DB が実在しないときは
 * sys_deploy を何も書かずに理由を表示して中止する（実体の無い版が最新になると全 API が壊れるため。
 * 例外ではなく「何もしない」と同じ扱い）。DB を先に用意した環境でだけ登録される。artifact_dir も名前だけで、配信ファイルの実際のディレクトリは
 * 従来どおりマニフェストハッシュ（RecordingMasterExporter）。
 * 既存のデプロイの is_active は変えない。元に戻すには RecordingResetSeeder。
 * sys_deploy_master の deploy_date は 1900-01-01 で、運営ツールの連番（今日の deploy_date の件数）を増やさない。
 * シートを使える環境では、このシーダーを流さず運営ツールの経路（MASTER_DEPLOY_FIXED_KEY=7777777777 で
 * 画面か `php artisan master:deploy`）で行う。流すと、mst の今の行を別の hash で登録しうる。
 *
 * 使うケース（client_design.md 7節）: 1〜15 すべての前提（version API を通す）。
 * ケース 14（未取得の版の取得）は、このデプロイが配信ファイルを持つので、クライアントを同梱の版のまま
 * 起動すれば撮れる。
 *
 * 冪等性: 登録は (deploy_key, hash) で引く。同じ hash の master があれば再利用して sys_deploy を有効に戻し、無ければ新しい行
 * （新しい sys_deploy.id）。同じキーの古い sys_deploy は is_active=false にし、他のキーの行は触らない。
 * 同じ mst なら何度流しても行は増えない。mst が変われば新しい hash の行が増え、古い行と配信ファイルは
 * 無効なまま残る（RecordingResetSeeder が消す）。
 * 本番: APP_ENV=production なら何もせず例外（予約帯。DeployKeyConst::RESERVED_FLOOR）。
 * 副作用: start_at が未来の sys_deploy があれば何もしない。
 * 追加する行は description が `[REC]` で始まり、RecordingResetSeeder が行とファイルを消す。
 * キャッシュ: 最新デプロイ・deploy_key・sys_deploy.id は Redis に最大 1 時間キャッシュされるため、追加後に消して即時に反映する。
 *
 * @throws \RuntimeException 配信ファイルの出力先が無いとき
 */
class RecordingDeploySeeder extends RecordingScenarioSeeder
{
    /**
     * 予約帯（7000000000 以上。DeployKeyConst::FIXED_KEY_RECORDING）の固定キー。最新の判定は start_at 降順 →
     * deploy_key 降順 → id 降順（SysDeployRepository::selectLatestDownloadableAt）なので、録画用は start_at = 現在で
     * 最新になる（本番では予約帯が除かれる）。列は bigInteger。
     */
    public const DEPLOY_KEY = DeployKeyConst::FIXED_KEY_RECORDING;

    /** 配信に必ず含めるテーブルグループ（親テーブル名）。home-player-info のスタミナ最大値と回復間隔 */
    public const REQUIRED_GROUPS = ['mst_player_level', 'mst_config'];

    /**
     * 本番（APP_ENV=production）では、予約帯の行を作らないよう、環境ガードより先に何もせず例外で止める。
     * 値は DeployKeyConst::RESERVED_FLOOR（予約帯の下限）から読む。
     *
     * @throws \RuntimeException
     */
    protected function assertSafeEnvironment(): void
    {
        if (app()->environment('production') && self::DEPLOY_KEY >= DeployKeyConst::RESERVED_FLOOR) {
            throw new \RuntimeException('録画用のデプロイは予約帯（'.DeployKeyConst::RESERVED_FLOOR.' 以上）の deploy_key を使うため、本番では実行できません');
        }

        parent::assertSafeEnvironment();
    }

    protected function apply(): void
    {
        $now = ClockUtility::nowToString();

        if ($this->hasDeployThatShouldNotBeOverridden($now)) {
            $this->report('start_at が未来のデプロイ（予約済みの版）があるため、録画用のデプロイは追加しません（予約の版が start_at に達すると録画用が無効になるため）。');

            return;
        }

        $this->ensureMasterRows();

        $exported = (new RecordingMasterExporter(self::masterDataDir()))->export($this->masterGroups());

        // 版の DB の実体が無ければ、sys_deploy を何も書かずに中止する。実体の無い版が最新になると、
        // ミドルウェアが存在しない DB に切り替えて全 API が 500 になるため（録画用の版の DB の作り方が未決。db.md 11.2 / R19）。
        // 例外にはせず、従来の「何もしない」（予約済みの版がある場合）と同じ扱いにする。
        $mstDatabase = self::mstDatabaseName($exported['hash']);
        if (! $this->mstDatabaseExists($mstDatabase)) {
            $this->report('録画用の版の mst DB（'.$mstDatabase.'）が実在しないため、録画用のデプロイは追加しません。録画用の版の DB 作成方法（db.md 11.2 / R19）が未決です。決まるまで、実体の無い版は登録しません。');

            return;
        }

        $description = self::DEPLOY_DESCRIPTION_PREFIX.' 動画撮影用のデプロイ（mst の全テーブルを配信）';
        $connection = DB::connection('sys');
        $changedDeployIds = [];

        // 登録は hash で引く（deploy_key では引かない。同じ deploy_key の行が複数ありうる）
        $connection->transaction(function () use ($connection, $exported, $description, $now, $mstDatabase, &$changedDeployIds): void {
            $hash16 = substr($exported['hash'], 0, 16);
            $masterId = $connection->table('sys_deploy_master')
                ->where('deploy_key', self::DEPLOY_KEY)->where('hash', $exported['hash'])->value('id');
            if ($masterId === null) {
                $masterId = $connection->table('sys_deploy_master')->insertGetId([
                    'deploy_key' => self::DEPLOY_KEY,
                    'hash' => $exported['hash'],
                    'deploy_date' => '1900-01-01',
                    'deploy_count' => 0,
                    'status' => 'completed',
                    'deployed_by' => 'recording',
                    'deployed_at' => '1900-01-01 00:00:00',
                    'description' => $description,
                    'mst_database' => $mstDatabase,
                    'artifact_dir' => self::DEPLOY_KEY.'_'.$hash16,
                    'schema_fingerprint' => hash('sha256', 'schema_v1'),
                    'source_kind' => 'build',
                ]);
            }

            // 配信ファイルの一覧は入れ替える（主キーは master_id + table_name。public_url も書き直す）
            $connection->table('sys_deploy_master_table')->where('sys_deploy_master_id', $masterId)->delete();
            foreach ($exported['tables'] as $tableName => $table) {
                $connection->table('sys_deploy_master_table')->insert([
                    'sys_deploy_master_id' => $masterId,
                    'table_name' => $tableName,
                    'hash' => $table['hash'],
                    'file_size' => $table['file_size'],
                    'file_name' => $table['file_name'],
                    'public_url' => $table['public_url'],
                ]);
            }

            $assetHash = hash('sha256', 'recording_asset');
            $assetId = $connection->table('sys_deploy_asset')
                ->where('deploy_key', self::DEPLOY_KEY)->where('hash', $assetHash)->value('id');
            if ($assetId === null) {
                $assetId = $connection->table('sys_deploy_asset')->insertGetId([
                    'deploy_key' => self::DEPLOY_KEY,
                    'hash' => $assetHash,
                    'deploy_date' => '1900-01-01',
                    'deploy_count' => 0,
                    'status' => 'completed',
                    'deployed_by' => 'recording',
                    'deployed_at' => '1900-01-01 00:00:00',
                    'description' => $description,
                ]);
            }

            // 同じ master を指す録画用の sys_deploy があれば（id 最大の行を）有効に戻し、無ければ新しい行にする
            $deployId = $connection->table('sys_deploy')
                ->where('deploy_key', self::DEPLOY_KEY)
                ->where('sys_deploy_master_id', $masterId)
                ->max('id');
            if ($deployId === null) {
                $deployId = $connection->table('sys_deploy')->insertGetId([
                    'deploy_key' => self::DEPLOY_KEY,
                    'start_at' => $now,
                    'sys_deploy_master_id' => $masterId,
                    'sys_deploy_asset_id' => $assetId,
                    'is_active' => true,
                ]);
                $changedDeployIds[] = (int) $deployId;
            } else {
                $connection->table('sys_deploy')->where('id', $deployId)
                    ->update(['is_active' => true, 'start_at' => $now, 'updated_at' => $now]);
                $changedDeployIds[] = (int) $deployId;
            }

            // 同じキーの他の行は無効にする。他のキーの行は触らない
            $others = $connection->table('sys_deploy')
                ->where('deploy_key', self::DEPLOY_KEY)
                ->where('id', '!=', $deployId)
                ->where('is_active', true);
            foreach ($others->pluck('id') as $otherId) {
                $changedDeployIds[] = (int) $otherId;
            }
            $others->update(['is_active' => false, 'updated_at' => $now]);
        });

        // 古い版のディレクトリは消さない（無効にした sys_deploy が指していて、内容アドレスなので残しても害がない。
        // 消すのは RecordingResetSeeder）

        $this->clearApiCaches($changedDeployIds);

        $this->report('録画用のデプロイを用意しました（deploy_key='.self::DEPLOY_KEY.'、配信: '.count($exported['tables']).' ファイル、'.implode('・', self::REQUIRED_GROUPS).' を含む）。');
    }

    /** 録画用の版の mst DB 名（{基底 DB}-7777777777-{hash 先頭 16 文字}。db.md 4.2） */
    public static function mstDatabaseName(string $manifestHash): string
    {
        return config('database.connections.mst.database').'-'.self::DEPLOY_KEY.'-'.substr($manifestHash, 0, 16);
    }

    /**
     * 版の DB が実在し、繋がるか。mst 接続の設定の database 違いの別接続で試す
     * （information_schema を mst 接続で引くと、トランザクションのスナップショットに作ったばかりの DB が見えないことがある）
     */
    private function mstDatabaseExists(string $name): bool
    {
        config(['database.connections.mst_probe' => array_merge((array) config('database.connections.mst'), ['database' => $name])]);
        DB::purge('mst_probe');

        try {
            DB::connection('mst_probe')->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            DB::purge('mst_probe');
        }
    }

    /** 配信ファイルの出力先（RecordingResetSeeder も使う） */
    public static function masterDataDir(): string
    {
        return rtrim((string) config('recording.masterdata_dir'), '/');
    }

    /**
     * 配信するテーブルグループ（mst の親テーブル名。`__l10n` は親と同じファイルに入るので数えない）。
     * 運営ツールの MasterDataExporter と同じく `mst_%` を全部。並びは固定する（再実行で同じマニフェストになる）。
     *
     * @return list<string>
     */
    public function masterGroups(): array
    {
        $groups = [];
        foreach (DB::connection('mst')->select("SHOW TABLES LIKE 'mst_%'") as $row) {
            $name = (string) array_values((array) $row)[0];
            if (! str_ends_with($name, '__l10n')) {
                $groups[] = $name;
            }
        }
        sort($groups);

        return $groups;
    }

    /**
     * 録画環境のマスター DB に両テーブルの行を入れる。どちらかが空のときだけ、既存のシーダーを使う
     * （これらのシーダーは truncate + insert なので、行があるときは触らない）。
     */
    private function ensureMasterRows(): void
    {
        $mst = DB::connection('mst');

        if ($mst->table('mst_player_level')->doesntExist()) {
            $this->call(MstPlayerLevelSeeder::class);
        }
        if ($mst->table('mst_config')->doesntExist()) {
            $this->call(MstConfigSeeder::class);
        }
    }

    /**
     * 録画用を足さない理由になる、start_at が未来の sys_deploy（予約済みの版）があるか。
     * 予約の版が start_at に達すると最新が入れ替わり、録画用が黙って無効になるため。
     * 設計: docs/design/master-distribution/db.md 11.2
     */
    private function hasDeployThatShouldNotBeOverridden(string $now): bool
    {
        return DB::connection('sys')->table('sys_deploy')
            ->where('start_at', '>', $now)
            ->exists();
    }
}

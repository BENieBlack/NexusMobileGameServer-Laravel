<?php

namespace Database\Seeders\Recording\Scenarios;

use Database\Seeders\MstConfigSeeder;
use Database\Seeders\MstPlayerLevelSeeder;
use Database\Seeders\Recording\RecordingMasterExporter;
use Database\Seeders\Recording\RecordingScenarioSeeder;
use Illuminate\Support\Facades\DB;
use Nexus\Core\Utilities\ClockUtility;

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
 * deploy_key は予約キー（DEPLOY_KEY = 7777777777）。version API は有効なデプロイのうち deploy_key が最大のものを
 * 最新とする。運営ツール経由の本物のデプロイは YYYYMMDD×100+連番（例 2026112201）で DEPLOY_KEY より小さいので、
 * 本物があっても録画用が最新になる。開発用 9999999999・レベルデザイン用 8888888888 の有効なデプロイがある
 * 環境だけ、それらが最新になるので何も追加しない。
 * 追加した場合は、録画用が最新になり、その間は本物のデプロイの配信ファイルが master.tables に出ない
 * （録画用の配信ファイルだけになる）。
 * 既存のデプロイの is_active は変えない。元に戻すには RecordingResetSeeder。
 * sys_deploy_master の deploy_date は 1900-01-01 で、運営ツールの連番（今日の deploy_date の件数）を増やさない。
 * 運営ツールと同じ畳み・登録の経路に乗せる作り直しは deploy_key 上乗せの S3-4 で行う（今は mst の現在の行を書き出すつなぎ）。
 *
 * 使うケース（client_design.md 7節）: 1〜15 すべての前提（version API を通す）。
 * ケース 14（未取得の版の取得）は、このデプロイが配信ファイルを持つので、クライアントを同梱の版のまま
 * 起動すれば撮れる。
 *
 * 冪等性: deploy_key 固定の updateOrInsert。何度流しても各 1 行（master_table は入れ替え）。mst の内容が
 * 変わっていればファイルは新しいハッシュで作り直し、古い録画用ディレクトリは消す。
 * 副作用: deploy_key が DEPLOY_KEY より大きい有効なデプロイがあれば何もしない。
 * 追加する行は description が `[REC]` で始まり、RecordingResetSeeder が行とファイルを消す。
 * キャッシュ: 最新デプロイは Redis に最大 1 時間キャッシュされるため、追加後に消して即時に反映する。
 *
 * @throws \RuntimeException 配信ファイルの出力先が無いとき
 */
class RecordingDeploySeeder extends RecordingScenarioSeeder
{
    /**
     * 予約帯（7000000000 以上。DeployKeyConst::FIXED_KEY_RECORDING）の固定キー。本物の 10 桁（例 2026112201）より
     * 大きいので、本物が有効でも録画用が最新になる（本番では予約帯が除かれる）。レベルデザイン用（8888888888）・
     * 開発用（9999999999）より小さい。最新の判定は deploy_key 降順 → id 降順（SysDeployRepository::
     * selectLatestDownloadable）。列は bigInteger。登録の作り直しは S3-4 で行う。
     */
    public const DEPLOY_KEY = 7777777777;

    /** 配信に必ず含めるテーブルグループ（親テーブル名）。home-player-info のスタミナ最大値と回復間隔 */
    public const REQUIRED_GROUPS = ['mst_player_level', 'mst_config'];

    protected function apply(): void
    {
        $now = ClockUtility::nowToString();

        if ($this->hasDeployThatShouldNotBeOverridden($now)) {
            $this->report('deploy_key が録画用より大きい有効なデプロイ（開発用・レベルデザイン用）が既にあるため、デプロイは追加しません。');

            return;
        }

        $this->ensureMasterRows();

        $exported = (new RecordingMasterExporter(self::masterDataDir()))->export($this->masterGroups());

        $description = self::DEPLOY_DESCRIPTION_PREFIX.' 動画撮影用のデプロイ（mst の全テーブルを配信）';
        $connection = DB::connection('sys');

        // 前回と内容が変わったら、古いファイルのディレクトリを消す
        $previousHash = $connection->table('sys_deploy_master')->where('deploy_key', self::DEPLOY_KEY)->value('hash');

        $connection->table('sys_deploy_master')->updateOrInsert(
            ['deploy_key' => self::DEPLOY_KEY],
            [
                'hash' => $exported['hash'],
                'deploy_date' => '1900-01-01',
                'deploy_count' => 0,
                'status' => 'completed',
                'deployed_by' => 'recording',
                'deployed_at' => '1900-01-01 00:00:00',
                'description' => $description,
                'updated_at' => $now,
            ],
        );
        $connection->table('sys_deploy_asset')->updateOrInsert(
            ['deploy_key' => self::DEPLOY_KEY],
            [
                'hash' => hash('sha256', 'recording_asset'),
                'deploy_date' => '1900-01-01',
                'deploy_count' => 0,
                'status' => 'completed',
                'deployed_by' => 'recording',
                'deployed_at' => '1900-01-01 00:00:00',
                'description' => $description,
                'updated_at' => $now,
            ],
        );

        $masterId = $connection->table('sys_deploy_master')->where('deploy_key', self::DEPLOY_KEY)->value('id');
        $assetId = $connection->table('sys_deploy_asset')->where('deploy_key', self::DEPLOY_KEY)->value('id');

        // 配信ファイルの一覧は入れ替える（主キーは master_id + table_name）
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

        $connection->table('sys_deploy')->updateOrInsert(
            ['deploy_key' => self::DEPLOY_KEY],
            [
                'start_at' => '1900-01-01 00:00:00',
                'sys_deploy_master_id' => $masterId,
                'sys_deploy_asset_id' => $assetId,
                'is_active' => true,
                'updated_at' => $now,
            ],
        );

        if (is_string($previousHash) && $previousHash !== $exported['hash']) {
            (new RecordingMasterExporter(self::masterDataDir()))->deleteDeployment($previousHash);
        }

        $this->clearApiCaches();

        $this->report('録画用のデプロイを用意しました（deploy_key='.self::DEPLOY_KEY.'、配信: '.count($exported['tables']).' ファイル、'.implode('・', self::REQUIRED_GROUPS).' を含む）。');
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
    private function masterGroups(): array
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
     * 録画用を足さない理由になる、deploy_key が録画用より大きい有効かつ開始済みのデプロイがあるか
     * （開発用 9999999999・レベルデザイン用 8888888888。最新になれないので足しても無駄）。
     * 配信ファイルを持つ本物の 10 桁のデプロイや基本シードの sys_deploy は、録画用が上に重なるので数えない。
     * 設計: Nexus docs/design/master-distribution/db.md 11.2
     */
    private function hasDeployThatShouldNotBeOverridden(string $now): bool
    {
        return DB::connection('sys')->table('sys_deploy')
            ->where('is_active', true)
            ->where('start_at', '<=', $now)
            ->where('deploy_key', '>', self::DEPLOY_KEY)
            ->exists();
    }
}

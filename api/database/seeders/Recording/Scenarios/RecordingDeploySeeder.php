<?php

namespace Database\Seeders\Recording\Scenarios;

use Database\Seeders\Recording\RecordingScenarioSeeder;
use Illuminate\Support\Facades\DB;
use Nexus\Core\Utilities\ClockUtility;
use NexusVersion\Constants\DeployKeyConst;

/**
 * RecordingDeploySeeder
 *
 * 目的: 運営ツールの build（`MASTER_DEPLOY_FIXED_KEY=7777777777`）が作った録画用の版を、sys に登録し直す。
 * 設計: docs/design/master-distribution/db.md 11.2・3.5、sequence.md 7 節（R19。人の決定。2026-11-24）。
 *
 * このシーダーは版の DB も成果物（配信 SQLite・manifest.json）も作らない。作るのは tool の build
 * （MasterBuildRunner。`php artisan master:build`）で、シートを使える環境だけ。CI・`make seed` では録画用の版を作らない。
 * シーダーの出番は、`make migrate-fresh` などで sys だけが消え、版の DB と成果物が残ったときの登録のやり直し
 * （build も start_at 省略で sys に登録するので、通常は build だけで最新になる）。
 *
 * 流れ（sequence.md 7 節）:
 * 1. 本番（APP_ENV=production）なら何もせず例外（予約帯。DeployKeyConst::RESERVED_FLOOR）。
 * 2. start_at が現在より後の sys_deploy（予約済みの版）があれば何もせず理由を表示する（仮置き。予約の版が start_at に
 *    達すると最新が入れ替わり、録画用が黙って無効になるため）。
 * 3. 成果物 `7777777777_{hash16}`（config('recording.masterdata_dir') の下。manifest.json があるもの）を探す。
 *    複数あれば manifest.json の built_at が最新のものを使う（仮置き）。無ければ中止して理由を表示する。
 * 4. 版の DB（{基底 DB}-7777777777-{hash 先頭 16 文字}）が実在しなければ、sys を何も書かずに中止して理由を表示する
 *    （実体の無い版が最新になると全 API が壊れるため。例外にはしない。CI・make seed を失敗にしないため）。
 * 5. 同じ (deploy_key, hash) の sys_deploy_master があれば再利用し、無ければ build と同じ形で入れる。sys_deploy は
 *    start_at = 現在で有効にする（同じ master を指す行があればそれを有効に戻して start_at を現在にし、無ければ新しい行）。
 *
 * 録画用は start_at = 現在で登録すれば最新になる（deploy_key の大小は効かない。db.md 3.3）。
 * 録画用の後に本物の build（start_at = 現在）が走ると録画用は黙って無効になる（build に予約を見る保護は無い）。
 * 運用は「本物の版の登録の後に、録画用を build する」。元に戻すには RecordingResetSeeder。
 *
 * 冪等性: 何度流しても行は増えない（master・asset は (deploy_key, hash) で引く）。
 * 副作用: 他のキーの行は触らない。キャッシュ（schedule キー・deploy_key・sys_deploy.id）は登録後に消して即時に反映する。
 */
class RecordingDeploySeeder extends RecordingScenarioSeeder
{
    /**
     * 予約帯（7000000000 以上。DeployKeyConst::FIXED_KEY_RECORDING）の固定キー。最新の判定は start_at 降順 →
     * deploy_key 降順 → id 降順（SysDeployRepository::selectLatestDownloadableAt）なので、録画用は start_at = 現在で
     * 最新になる（本番では予約帯が除かれる）。列は bigInteger。
     */
    public const DEPLOY_KEY = DeployKeyConst::FIXED_KEY_RECORDING;

    /** 成果物のディレクトリ名 `{deploy_key}_{hash の先頭 16 文字}`（tool の MasterArtifactStore::DIR_PATTERN と同じ形。録画用のキーに絞る） */
    private const ARTIFACT_DIR_PATTERN = '/^'.self::DEPLOY_KEY.'_[0-9a-f]{16}$/D';

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

        if ($this->hasReservedDeploy($now)) {
            $this->report('start_at が未来のデプロイ（予約済みの版）があるため、録画用のデプロイは登録しません（予約の版が start_at に達すると録画用が無効になるため）。');

            return;
        }

        $artifact = self::findLatestArtifact();
        if ($artifact === null) {
            $this->report('録画用の成果物（'.self::masterDataDir().'/'.self::DEPLOY_KEY.'_*/manifest.json）が無いため、録画用のデプロイは登録しません。'
                .'シートを使える環境で MASTER_DEPLOY_FIXED_KEY='.self::DEPLOY_KEY.' の build（php artisan master:build）を行ってください（本物の版の登録の後）。');

            return;
        }

        $mstDatabase = self::mstDatabaseName($artifact['hash']);
        if (! $this->mstDatabaseExists($mstDatabase)) {
            $this->report('録画用の版の mst DB（'.$mstDatabase.'）が実在しないため、録画用のデプロイは登録しません。'
                .'シートを使える環境で MASTER_DEPLOY_FIXED_KEY='.self::DEPLOY_KEY.' の build（php artisan master:build）を行ってください。');

            return;
        }

        $connection = DB::connection('sys');
        $changedDeployIds = [];

        // 登録は (deploy_key, hash) で引く。build が作った行があれば再利用し、無い（sys だけ消えた）ときだけ build と同じ形で入れる
        $connection->transaction(function () use ($connection, $artifact, $now, $mstDatabase, &$changedDeployIds): void {
            $masterId = $connection->table('sys_deploy_master')
                ->where('deploy_key', self::DEPLOY_KEY)->where('hash', $artifact['hash'])->value('id');
            $existingMaster = $masterId === null ? null : $connection->table('sys_deploy_master')->where('id', $masterId)->first();
            if ($existingMaster !== null && ($existingMaster->mst_dropped_at !== null || $existingMaster->mst_database !== $mstDatabase)) {
                // tool の R1（再利用）と同じく、実在を確認した版の DB の名前に行を揃える（掃除で DROP 済みの印を外す。
                // 実在確認した DB と行が食い違ったまま有効にすると、API が別の DB に切り替えるため）
                $connection->table('sys_deploy_master')->where('id', $masterId)->update([
                    'mst_database' => $mstDatabase,
                    'mst_dropped_at' => null,
                    'schema_fingerprint' => $artifact['schema_fingerprint'],
                    'updated_at' => $now,
                ]);
            }
            if ($masterId === null) {
                $masterId = $connection->table('sys_deploy_master')->insertGetId([
                    'deploy_key' => self::DEPLOY_KEY,
                    'hash' => $artifact['hash'],
                    'deploy_date' => substr($now, 0, 10),
                    'deploy_count' => 0,
                    'status' => 'completed',
                    'deployed_by' => 'master_build',
                    'deployed_at' => $now,
                    'description' => 'マスター build: '.count($artifact['tables']).'テーブル / '.array_sum(array_column($artifact['tables'], 'file_size')).'bytes（録画用の再登録）',
                    'mst_database' => $mstDatabase,
                    'artifact_dir' => $artifact['dir'],
                    'schema_fingerprint' => $artifact['schema_fingerprint'],
                    'source_kind' => 'build',
                ]);
                foreach ($artifact['tables'] as $tableName => $table) {
                    $connection->table('sys_deploy_master_table')->insert([
                        'sys_deploy_master_id' => $masterId,
                        'table_name' => $tableName,
                        'hash' => $table['hash'],
                        'file_size' => $table['file_size'],
                        'file_name' => $table['file_name'],
                        'public_url' => '/masterdata/'.$artifact['dir'].'/'.$table['file_name'],
                    ]);
                }
            }

            // asset（ダミー）は (deploy_key, hash) が UNIQUE。master と同じ hash（build と同じ）
            $assetId = $connection->table('sys_deploy_asset')
                ->where('deploy_key', self::DEPLOY_KEY)->where('hash', $artifact['hash'])->value('id');
            if ($assetId === null) {
                $assetId = $connection->table('sys_deploy_asset')->insertGetId([
                    'deploy_key' => self::DEPLOY_KEY,
                    'hash' => $artifact['hash'],
                    'deploy_date' => substr($now, 0, 10),
                    'deploy_count' => 0,
                    'status' => 'completed',
                    'deployed_by' => 'master_build',
                    'deployed_at' => $now,
                    'description' => 'マスターデータのみ（アセットなし）',
                ]);
            }

            // 同じ master を指す sys_deploy があれば（id 最大の行を）有効に戻して start_at を現在にし、無ければ新しい行にする
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
            } else {
                $connection->table('sys_deploy')->where('id', $deployId)
                    ->update(['is_active' => true, 'start_at' => $now, 'updated_at' => $now]);
            }
            $changedDeployIds[] = (int) $deployId;
        });

        $this->clearApiCaches($changedDeployIds);

        $this->report('録画用のデプロイを登録しました（deploy_key='.self::DEPLOY_KEY.'、成果物: '.$artifact['dir'].'、配信: '.count($artifact['tables']).' ファイル）。');
    }

    /** 録画用の版の mst DB 名（{基底 DB}-7777777777-{hash 先頭 16 文字}。db.md 4.2。tool の MasterVersionDatabase::versionName と同じ） */
    public static function mstDatabaseName(string $manifestHash): string
    {
        return config('database.connections.mst.database').'-'.self::DEPLOY_KEY.'-'.substr($manifestHash, 0, 16);
    }

    /** 成果物の置き場（tool の master_artifact disk を api-php にマウントした先。RecordingResetSeeder も使う） */
    public static function masterDataDir(): string
    {
        return rtrim((string) config('recording.masterdata_dir'), '/');
    }

    /**
     * 録画用の成果物のディレクトリ名（`7777777777_{hash16}`）の一覧。manifest.json の有無は問わない（リセットが消す対象）
     *
     * @return list<string>
     */
    public static function artifactDirNames(): array
    {
        $names = [];
        foreach (@scandir(self::masterDataDir()) ?: [] as $entry) {
            if (preg_match(self::ARTIFACT_DIR_PATTERN, $entry) === 1 && is_dir(self::masterDataDir().'/'.$entry) && ! is_link(self::masterDataDir().'/'.$entry)) {
                $names[] = $entry;
            }
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * 完成した（manifest.json がある）録画用の成果物のうち、built_at が最新のもの（同値は名前の降順。仮置き。sequence.md 7 節）。
     * manifest.json が読めない・形が違う（deploy_key・hash がディレクトリ名と合わない）ものは無視する。
     *
     * @return array{dir: string, hash: string, schema_fingerprint: string, built_at: string, tables: array<string, array{hash: string, file_name: string, file_size: int}>}|null
     */
    public static function findLatestArtifact(): ?array
    {
        $latest = null;
        foreach (self::artifactDirNames() as $dir) {
            $json = @file_get_contents(self::masterDataDir().'/'.$dir.'/manifest.json');
            $manifest = is_string($json) ? json_decode($json, true) : null;
            if (! is_array($manifest)
                || (int) ($manifest['deploy_key'] ?? 0) !== self::DEPLOY_KEY
                || ! is_string($manifest['hash'] ?? null)
                || strlen($manifest['hash']) !== 64
                || self::DEPLOY_KEY.'_'.substr($manifest['hash'], 0, 16) !== $dir
                || ! is_array($manifest['group_list'] ?? null)
                || $manifest['group_list'] === []) {
                continue;
            }

            $tables = [];
            foreach ($manifest['group_list'] as $group) {
                $tables[(string) $group['group']] = [
                    'hash' => (string) $group['sqlite_sha256'],
                    'file_name' => (string) $group['file_name'],
                    'file_size' => (int) $group['file_size'],
                ];
            }
            $builtAt = (string) ($manifest['built_at'] ?? '');
            if ($latest === null || strcmp($builtAt.'|'.$dir, $latest['built_at'].'|'.$latest['dir']) > 0) {
                $latest = [
                    'dir' => $dir,
                    'hash' => $manifest['hash'],
                    'schema_fingerprint' => (string) ($manifest['schema_fingerprint'] ?? ''),
                    'built_at' => $builtAt,
                    'tables' => $tables,
                ];
            }
        }

        return $latest;
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

    /**
     * 録画用を登録しない理由になる、start_at が未来の sys_deploy（予約済みの版）があるか。
     * 予約の版が start_at に達すると最新が入れ替わり、録画用が黙って無効になるため（仮置き。db.md 11.2）。
     */
    private function hasReservedDeploy(string $now): bool
    {
        return DB::connection('sys')->table('sys_deploy')
            ->where('start_at', '>', $now)
            ->exists();
    }
}

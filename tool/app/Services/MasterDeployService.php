<?php

namespace App\Services;

use App\Exceptions\MasterDeployKeyException;
use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * マスターデータの sys への登録（版ごとの mst DB 設計。設計: docs/design/master-distribution/db.md 3.6）
 *
 * registerBuild() が、build・import・rollback の登録を sys の 1 トランザクションで行う（Runner が呼ぶ）。
 * 旧経路の採番・全無効化・補償・register() は S3-16 で削除した。
 */
class MasterDeployService
{
    /**
     * build の登録（db.md 3.6 の R1・R2。他の sys_deploy を無効にしない）を sys の 1 トランザクションで行う
     *
     * - R1: 同じ (deploy_key, hash) の sys_deploy_master があれば再利用し、sys_deploy の行だけ足す。`restored` が true（版の DB を作り直した）なら、
     *   同じトランザクションで master の mst_database・schema_fingerprint を更新し mst_dropped_at を NULL にする
     * - R2: 新しい (deploy_key, hash) は master・sys_deploy_master_table・asset（ダミー）・sys_deploy を足す
     * - 二重登録の防止: start_at が明示で、同じ master・同じ start_at の sys_deploy が既にあれば行を足さず既存を返す（warning `already_registered`）
     * - start_at は Runner が解決済みの値（「すぐ」は登録の直前の現在時刻）。新しい版より後の start_at の行が既にあれば warning `start_at_shadowed`
     *
     * COMMIT より前の失敗は register_failed（ロールバック済み）、COMMIT 処理中の失敗は register_commit_unknown（成否不明。Runner が読み直す）。
     *
     * @param  array{
     *   deploy_key: int,
     *   hash: string,
     *   artifact_dir: string,
     *   schema_fingerprint: string,
     *   mst_database: string,
     *   mode: 'fixed'|'explicit',
     *   restored: bool,
     *   source_kind?: 'build'|'import',
     *   start_at: string,
     *   start_at_explicit: bool,
     *   table_count: int,
     *   file_size: int,
     *   tables: array<string, array{hash: string, file_name: string, file_size: int, public_url: string}>
     * }  $input
     * @return array{sys_deploy_master_id: int, sys_deploy_id: int, deploy_key: int, hash: string, mst_database: string, start_at: string, is_new: bool, warning_list: list<array{code: string, message: string}>}
     *
     * @throws MasterDeployKeyException register_failed・register_commit_unknown
     */
    public function registerBuild(array $input, ?CarbonInterface $now = null): array
    {
        $now = ($now ?? now())->copy()->utc();
        $sys = DB::connection('sys');
        $sys->beginTransaction();
        try {
            $result = $this->registerBuildInTransaction($input, $now);
        } catch (Throwable $e) {
            try {
                $sys->rollBack();
            } catch (Throwable) {
                // ロールバックの失敗は元の例外を優先する（接続が切れていればサーバー側で破棄される）
            }

            throw $e instanceof MasterDeployKeyException ? $e : MasterDeployKeyException::registerFailed($e);
        }
        try {
            $this->commitSys($sys);
        } catch (Throwable $e) {
            throw MasterDeployKeyException::registerCommitUnknown($e);
        }

        return $result;
    }

    /** COMMIT（テストで成否不明を再現するための差し込み口） */
    protected function commitSys(ConnectionInterface $sys): void
    {
        $sys->commit();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{sys_deploy_master_id: int, sys_deploy_id: int, deploy_key: int, hash: string, mst_database: string, start_at: string, is_new: bool, warning_list: list<array{code: string, message: string}>}
     */
    private function registerBuildInTransaction(array $input, CarbonInterface $now): array
    {
        $sys = DB::connection('sys');
        $nowStr = $now->format('Y-m-d H:i:s');
        $deployKey = (int) $input['deploy_key'];
        $hash = (string) $input['hash'];
        $startAt = (string) $input['start_at'];
        $warningList = [];
        $sourceKind = (string) ($input['source_kind'] ?? 'build');

        $existing = $sys->table('sys_deploy_master')->where('deploy_key', $deployKey)->where('hash', $hash)->first();
        if ($existing !== null) {
            // R1
            $masterId = (int) $existing->id;
            $isNew = false;
            if ($input['restored']) {
                $sys->table('sys_deploy_master')->where('id', $masterId)->update([
                    'mst_database' => $input['mst_database'],
                    'schema_fingerprint' => $input['schema_fingerprint'],
                    'mst_dropped_at' => null,
                    'updated_at' => $nowStr,
                ]);
            }
        } else {
            // R2（固定キーは deploy_count = 0。明示キーは NN）
            $isNew = true;
            $deployCount = $input['mode'] === 'fixed' ? 0 : $deployKey % 100;
            $masterId = (int) $sys->table('sys_deploy_master')->insertGetId([
                'deploy_key' => $deployKey,
                'hash' => $hash,
                'deploy_date' => $now->format('Y-m-d'),
                'deploy_count' => $deployCount,
                'status' => 'completed',
                'deployed_by' => $sourceKind === 'import' ? 'master_import' : 'master_build',
                'deployed_at' => $nowStr,
                'description' => "マスター {$sourceKind}: {$input['table_count']}テーブル / {$input['file_size']}bytes",
                'mst_database' => $input['mst_database'],
                'artifact_dir' => $input['artifact_dir'],
                'schema_fingerprint' => $input['schema_fingerprint'],
                'source_kind' => $sourceKind,
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ]);
            foreach ($input['tables'] as $tableName => $tableResult) {
                $sys->table('sys_deploy_master_table')->insert([
                    'sys_deploy_master_id' => $masterId,
                    'table_name' => $tableName,
                    'hash' => $tableResult['hash'],
                    'file_size' => $tableResult['file_size'],
                    'file_name' => $tableResult['file_name'],
                    'public_url' => $tableResult['public_url'],
                ]);
            }
        }

        // asset（ダミー）は (deploy_key, hash) が UNIQUE。あればその id を使う（R1 でも必要）
        $assetId = $sys->table('sys_deploy_asset')->where('deploy_key', $deployKey)->where('hash', $hash)->value('id');
        if ($assetId === null) {
            $assetId = $this->insertAsset($hash, $deployKey, $now, $input['mode'] === 'fixed' ? 0 : $deployKey % 100, $sourceKind === 'import' ? 'master_import' : 'master_build');
        }

        if ($input['start_at_explicit']) {
            $duplicate = $sys->table('sys_deploy')->where('sys_deploy_master_id', $masterId)->where('start_at', $startAt)->orderBy('id')->first();
            if ($duplicate !== null) {
                $warningList[] = ['code' => 'already_registered', 'message' => '同じ版・同じ start_at の切り替え行が既にあるため、新しい行は作りませんでした'];

                return [
                    'sys_deploy_master_id' => $masterId,
                    'sys_deploy_id' => (int) $duplicate->id,
                    'deploy_key' => $deployKey,
                    'hash' => $hash,
                    'mst_database' => (string) ($existing->mst_database ?? $input['mst_database']),
                    'start_at' => $startAt,
                    'is_new' => false,
                    'warning_list' => $warningList,
                ];
            }
        }

        if ($sys->table('sys_deploy')->where('start_at', '>', $startAt)->exists()) {
            $warningList[] = ['code' => 'start_at_shadowed', 'message' => 'この版より後の start_at の切り替え行が既にあるため、その行が到達するとこの版は選ばれません'];
        }

        $deployId = (int) $sys->table('sys_deploy')->insertGetId([
            'deploy_key' => $deployKey,
            'start_at' => $startAt,
            'sys_deploy_master_id' => $masterId,
            'sys_deploy_asset_id' => (int) $assetId,
            'is_active' => true,
            'created_at' => $nowStr,
            'updated_at' => $nowStr,
        ]);

        return [
            'sys_deploy_master_id' => $masterId,
            'sys_deploy_id' => $deployId,
            'deploy_key' => $deployKey,
            'hash' => $hash,
            'mst_database' => $isNew || $input['restored'] ? (string) $input['mst_database'] : (string) ($existing->mst_database ?? $input['mst_database']),
            'start_at' => $startAt,
            'is_new' => $isNew,
            'warning_list' => $warningList,
        ];
    }

    /** sys_deploy が NOT NULL で参照するダミーの asset 行（hash は master と同じ値） */
    private function insertAsset(string $hash, int $deployKey, CarbonInterface $now, int $deployCount, string $deployedBy = 'master_import'): int
    {
        $nowStr = $now->format('Y-m-d H:i:s');

        return (int) DB::connection('sys')->table('sys_deploy_asset')->insertGetId([
            'deploy_key' => $deployKey,
            'hash' => $hash,
            'deploy_date' => $now->format('Y-m-d'),
            'deploy_count' => $deployCount,
            'status' => 'completed',
            'deployed_by' => $deployedBy,
            'deployed_at' => $nowStr,
            'description' => 'マスターデータのみ（アセットなし）',
            'created_at' => $nowStr,
            'updated_at' => $nowStr,
        ]);
    }
}

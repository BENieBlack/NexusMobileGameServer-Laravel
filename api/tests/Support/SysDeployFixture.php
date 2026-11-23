<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * 版（sys_deploy・sys_deploy_master・sys_deploy_asset）を 1 組入れるテスト用のフィクスチャ。
 * 設計: docs/design/master-distribution/db.md 3.1
 */
final class SysDeployFixture
{
    private static int $seq = 0;

    /**
     * sys の版の行をすべて消す（呼ぶテストは sys をトランザクションで包んでいる前提。巻き戻される）
     */
    public static function clear(): void
    {
        foreach (['sys_deploy', 'sys_deploy_master_table', 'sys_deploy_master', 'sys_deploy_asset'] as $table) {
            DB::connection('sys')->table($table)->delete();
        }
    }

    /**
     * 版を 1 つ入れて sys_deploy.id を返す
     *
     * @param  string|null  $mstDatabase  null なら deploy_key と連番から作る一意の名前
     * @param  string|null  $startAt  'Y-m-d H:i:s'。null なら 1 分前
     */
    public static function insert(
        int $deployKey,
        ?string $startAt = null,
        bool $isActive = true,
        ?string $mstDatabase = null,
        bool $dropped = false,
    ): int {
        $name = 'fx'.(++self::$seq);
        $now = now();

        $masterId = DB::connection('sys')->table('sys_deploy_master')->insertGetId([
            'deploy_key' => $deployKey,
            'hash' => hash('sha256', "master-{$name}-{$deployKey}"),
            ...SysDeployMasterColumns::for($deployKey, "master-{$name}-{$deployKey}"),
            ...($mstDatabase !== null ? ['mst_database' => $mstDatabase] : []),
            'mst_dropped_at' => $dropped ? $now : null,
            'deploy_date' => '2026-01-01',
            'deploy_count' => 1,
            'status' => 'completed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $assetId = DB::connection('sys')->table('sys_deploy_asset')->insertGetId([
            'deploy_key' => $deployKey,
            'hash' => hash('sha256', "asset-{$name}-{$deployKey}"),
            'deploy_date' => '2026-01-01',
            'deploy_count' => 1,
            'status' => 'completed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::connection('sys')->table('sys_deploy')->insertGetId([
            'deploy_key' => $deployKey,
            'start_at' => $startAt ?? $now->copy()->subMinute()->format('Y-m-d H:i:s'),
            'sys_deploy_master_id' => $masterId,
            'sys_deploy_asset_id' => $assetId,
            'is_active' => $isActive,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

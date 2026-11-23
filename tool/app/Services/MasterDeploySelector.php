<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * 「最新」（いまの版）の選択（db.md 3.3。api の `SysDeployRepository` と同じ並び・同じ条件。tool は api のクラスを使わないので 2 か所に持つ）
 *
 * `start_at <= 基準時刻` かつ `is_active = 1` の `sys_deploy` のうち、`start_at` が最も新しい行。同じなら `deploy_key` が大きい方、
 * それも同じなら `id` が大きい方。本番（APP_ENV=production）は予約帯（`deploy_key >= reserved_floor`）の行を選ばない（Q7）。
 */
class MasterDeploySelector
{
    /**
     * 選択の候補（最新が先頭）。master の列を `master_*` で添える
     *
     * @param  int|null  $excludeId  この `sys_deploy.id` の行を除く（「止めたあと」の判定）
     * @param  int|null  $forceActiveId  この `sys_deploy.id` の行は `is_active` に関わらず含める（「再開したあと」の判定）
     * @return list<object>
     */
    public function candidates(string $at, ?int $excludeId = null, ?int $forceActiveId = null): array
    {
        $query = DB::connection('sys')->table('sys_deploy as d')
            ->join('sys_deploy_master as m', 'm.id', '=', 'd.sys_deploy_master_id')
            ->where('d.start_at', '<=', $at)
            ->select([
                'd.id', 'd.deploy_key', 'd.start_at', 'd.is_active', 'd.sys_deploy_master_id',
                'm.hash as master_hash', 'm.mst_database as master_mst_database', 'm.mst_dropped_at as master_mst_dropped_at',
                'm.artifact_dir as master_artifact_dir', 'm.schema_fingerprint as master_schema_fingerprint',
            ])
            ->orderByDesc('d.start_at')->orderByDesc('d.deploy_key')->orderByDesc('d.id');
        if ($forceActiveId === null) {
            $query->where('d.is_active', 1);
        } else {
            $query->where(static function ($q) use ($forceActiveId): void {
                $q->where('d.is_active', 1)->orWhere('d.id', $forceActiveId);
            });
        }
        if ($excludeId !== null) {
            $query->where('d.id', '!=', $excludeId);
        }
        if ((string) config('app.env') === 'production') {
            $query->where('d.deploy_key', '<', (int) config('master_deploy.reserved_floor'));
        }

        return array_values($query->get()->all());
    }

    public function latest(string $at, ?int $excludeId = null, ?int $forceActiveId = null): ?object
    {
        return $this->candidates($at, $excludeId, $forceActiveId)[0] ?? null;
    }
}

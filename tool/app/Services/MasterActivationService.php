<?php

namespace App\Services;

use App\Exceptions\MasterDeployRunException;
use Illuminate\Support\Facades\DB;

/**
 * 緊急停止・再開（deactivate / activate。db.md 3.3・3.6 の R39。api.md 9.6。操作の名前・形は仮置き）
 *
 * 運営が明示的に操作したときだけ sys_deploy.is_active を 0（deactivate）／1（activate）にする。build・import・rollback・cancel-schedule は自動では変えない。
 * - 判定の順序: ① 行が無い → deploy_not_found ② すでにその状態 → 更新せず成功（already_in_state。schedule キーの破棄は行う）
 *   ③ deactivate: 止めた後に最新の選択が空、または掃除済み（mst_dropped_at）の版になる → deactivate_no_fallback
 *   （止める行が最新でも予約でもなければ検査なし）③' activate: 再開で最新の選択になる行の master が掃除済みなら、成果物から復元してから有効にする。
 *   復元できなければ activate_target_dropped（更新しない）
 * - 更新の後に schedule キーを破棄する。失敗は成功のまま cache.invalidated = false と警告を返す（already_in_state のときも破棄する）
 * - kind は deactivate（activate も同じ。summary.is_active に新しい値）。phase は lock・check・version_db（activate が復元するときだけ）・register・done
 */
class MasterActivationService extends MasterDeployOperation
{
    /**
     * @param  array{adm_account_id?: int|null}  $options
     * @return array<string, mixed>
     */
    public function deactivate(int $sysDeployId, array $options = []): array
    {
        return $this->toggle($sysDeployId, false, $options);
    }

    /**
     * @param  array{adm_account_id?: int|null}  $options
     * @return array<string, mixed>
     */
    public function activate(int $sysDeployId, array $options = []): array
    {
        return $this->toggle($sysDeployId, true, $options);
    }

    /**
     * @param  array{adm_account_id?: int|null}  $options
     * @return array<string, mixed>
     */
    private function toggle(int $sysDeployId, bool $active, array $options): array
    {
        $admAccountId = isset($options['adm_account_id']) ? (int) $options['adm_account_id'] : null;
        $preRow = DB::connection('sys')->table('sys_deploy')->where('id', $sysDeployId)->first();

        return $this->runLocked('deactivate', $admAccountId, $preRow === null ? null : (int) $preRow->deploy_key, function () use ($sysDeployId, $active): array {
            $this->phase('check');
            $sys = DB::connection('sys');
            $row = $sys->table('sys_deploy')->where('id', $sysDeployId)->first();
            if ($row === null) {
                throw new MasterDeployRunException(MasterDeployRunException::DEPLOY_NOT_FOUND, "切り替え行（sys_deploy.id={$sysDeployId}）がありません。", 404);
            }
            $this->summary['is_active'] = $active;
            $this->updateRun([
                'deploy_key' => (int) $row->deploy_key,
                'sys_deploy_master_id' => (int) $row->sys_deploy_master_id,
                'sys_deploy_id' => (int) $row->id,
                'start_at' => (string) $row->start_at,
            ], true);

            $now = now()->utc()->format('Y-m-d H:i:s');
            $warningList = [];
            $code = null;
            $restored = false;

            if ((bool) $row->is_active === $active) {
                $code = 'already_in_state';
                $this->summary['already_in_state'] = true;
            } else {
                $at = (string) $row->start_at > $now ? (string) $row->start_at : $now;
                if (! $active) {
                    $this->assertDeactivatable($row, $now, $at);
                }
                $restore = $this->prepareActivation($row, $at, $active, $now);

                $this->phase('register');
                $restored = $restore['restored'];
                $updatedAt = $this->nowString();
                if ($restored) {
                    // COMMIT の成否が不明でも復元した DB を DROP しない（登録済みなら消すと Unknown database になるため）
                    $this->keepDatabases = true;
                }
                $sys->transaction(function () use ($sys, $row, $active, $restore, $restored, $updatedAt): void {
                    if ($restored) {
                        $sys->table('sys_deploy_master')->where('id', $row->sys_deploy_master_id)->update([
                            'mst_database' => $restore['mst_database'],
                            'schema_fingerprint' => $restore['schema_fingerprint'],
                            'mst_dropped_at' => null,
                            'updated_at' => $updatedAt,
                        ]);
                    }
                    $sys->table('sys_deploy')->where('id', $row->id)->update(['is_active' => $active ? 1 : 0, 'updated_at' => $updatedAt]);
                });
                if ($restored) {
                    // sys が指す DB になったので、以後の失敗では DROP しない
                    $this->forgetCreated($restore['mst_database']);
                }
            }

            // 更新（または already_in_state）の後に schedule キーを破棄する
            $cache = $this->forgetSchedule();
            if (! $cache['invalidated']) {
                $warningList[] = $this->cacheWarning();
                $warningList[] = ['code' => 'stale_cache_possible', 'message' => '最大 '.(int) config('master_deploy.schedule_ttl').' 秒、止める前の判断（キャッシュ）が API に残りうります。'];
            }
            $this->summary['warning_list'] = $warningList;
            $this->summary['cache_invalidated'] = $cache['invalidated'];
            $this->summary['restored'] = $restored;

            $after = $this->selector->latest($now);
            $current = $sys->table('sys_deploy')->where('id', $sysDeployId)->first();

            return [
                'response' => [
                    'status' => 'success',
                    'code' => $code,
                    'message' => $code === 'already_in_state'
                        ? 'すでにその状態です（キャッシュの破棄だけ行いました）。'
                        : ($active ? '切り替え行を再開しました。' : '切り替え行を止めました。'),
                    'deploy' => [
                        'sys_deploy_id' => (int) $row->id,
                        'sys_deploy_master_id' => (int) $row->sys_deploy_master_id,
                        'deploy_key' => (int) $row->deploy_key,
                        'start_at' => (string) $row->start_at,
                        'is_active' => $current !== null && (bool) $current->is_active,
                    ],
                    'current_version_after' => $after === null ? null : [
                        'sys_deploy_id' => (int) $after->id,
                        'sys_deploy_master_id' => (int) $after->sys_deploy_master_id,
                        'deploy_key' => (int) $after->deploy_key,
                        'start_at' => (string) $after->start_at,
                    ],
                    'restored' => $restored,
                    'cache' => ['invalidated' => $cache['invalidated']],
                    'warning_list' => $warningList,
                ],
            ];
        });
    }

    /**
     * 再開で最新の選択になる行なら、その master の DB を使える状態にする（掃除済みなら成果物から復元。できなければ activate_target_dropped）
     *
     * @return array{restored: bool, mst_database: string, schema_fingerprint: string} 止める操作・再開しても最新の選択にならない行は restored = false（検査なし）
     *
     * @throws MasterDeployRunException activate_target_dropped
     */
    private function prepareActivation(object $row, string $at, bool $active, string $now): array
    {
        $none = ['restored' => false, 'mst_database' => '', 'schema_fingerprint' => ''];
        // 止める操作、および未到達（start_at > 現在）の行は、復元検査をかけない（到達するまで掃除されない。3.6）
        if (! $active || (string) $row->start_at > $now) {
            return $none;
        }
        $selected = $this->selector->latest($at, null, (int) $row->id);
        if ($selected === null || (int) $selected->id !== (int) $row->id) {
            return $none;
        }
        $master = DB::connection('sys')->table('sys_deploy_master')->where('id', $row->sys_deploy_master_id)->first();
        if ($master === null) {
            throw new MasterDeployRunException(MasterDeployRunException::ACTIVATE_TARGET_DROPPED, '再開する行の版（sys_deploy_master）がありません。', 409);
        }

        return $this->ensureMasterUsable($master, MasterDeployRunException::ACTIVATE_TARGET_DROPPED, MasterDeployRunException::ACTIVATE_TARGET_DROPPED, false);
    }

    /**
     * 止めた後に最新の選択が空、または掃除済みの版になるなら拒否する。止める行が最新でも予約でもなければ検査なし
     *
     * @throws MasterDeployRunException deactivate_no_fallback
     */
    private function assertDeactivatable(object $row, string $now, string $at): void
    {
        $current = $this->selector->latest($now);
        $isCurrent = $current !== null && (int) $current->id === (int) $row->id;
        $isScheduled = (string) $row->start_at > $now;
        if (! $isCurrent && ! $isScheduled) {
            return;
        }
        $after = $this->selector->latest($at, (int) $row->id);
        if ($after === null || $after->master_mst_dropped_at !== null) {
            throw new MasterDeployRunException(
                MasterDeployRunException::DEACTIVATE_NO_FALLBACK,
                $after === null
                    ? 'この行を止めると、いまの版が無くなり配信できなくなるため止められません。'
                    : 'この行を止めると、掃除済み（mst_dropped_at）の版に落ちて配信できなくなるため止められません。',
                409,
            );
        }
    }
}

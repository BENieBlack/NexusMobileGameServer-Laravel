<?php

namespace App\Services;

use App\Exceptions\MasterDeployRunException;
use Illuminate\Support\Facades\DB;

/**
 * 予約の取り消し（db.md 3.6。api.md 9.2）
 *
 * start_at が**まだ到達していない** sys_deploy 行を削除する。sys_deploy_master・版の DB・成果物は残す。is_active は変えない。
 * 判定の順序: ① 行が無い → schedule_not_found ② start_at <= 現在 → schedule_already_started ③ 現在 + 2 × schedule_ttl 以内 → schedule_cancel_too_soon
 * ④ DELETE の影響行数が 0（②③ の直後に到達した）→ schedule_already_started。
 * 削除の後に schedule キーを破棄する。破棄に失敗しても成功のまま、cache.invalidated = false と警告 schedule_cache_not_invalidated を返す。
 * phase は lock・check・register（= DELETE）・done。
 */
class MasterCancelScheduleService extends MasterDeployOperation
{
    /**
     * @param  array{adm_account_id?: int|null}  $options
     * @return array<string, mixed>
     *
     * @throws MasterDeployRunException
     */
    public function run(int $sysDeployId, array $options = []): array
    {
        $admAccountId = isset($options['adm_account_id']) ? (int) $options['adm_account_id'] : null;
        $row = DB::connection('sys')->table('sys_deploy')->where('id', $sysDeployId)->first();

        return $this->runLocked('cancel_schedule', $admAccountId, $row === null ? null : (int) $row->deploy_key, function () use ($sysDeployId): array {
            $this->phase('check');
            $sys = DB::connection('sys');
            // ロックの中で読み直す（ロックの前の読み取りは deploy_key の記録にだけ使う）
            $row = $sys->table('sys_deploy')->where('id', $sysDeployId)->first();
            if ($row === null) {
                throw new MasterDeployRunException(MasterDeployRunException::SCHEDULE_NOT_FOUND, "切り替え行（sys_deploy.id={$sysDeployId}）がありません。", 404);
            }
            $now = now()->utc();
            $nowString = $now->format('Y-m-d H:i:s');
            if ((string) $row->start_at <= $nowString) {
                throw new MasterDeployRunException(MasterDeployRunException::SCHEDULE_ALREADY_STARTED, 'この切り替え行はすでに start_at に到達しています。巻き戻しの操作からやり直してください。', 409);
            }
            $tooSoonLimit = $now->copy()->addSeconds(2 * (int) config('master_deploy.schedule_ttl'))->format('Y-m-d H:i:s');
            if ((string) $row->start_at <= $tooSoonLimit) {
                throw new MasterDeployRunException(
                    MasterDeployRunException::SCHEDULE_CANCEL_TOO_SOON,
                    'start_at が近い（現在 + '.(2 * (int) config('master_deploy.schedule_ttl')).' 秒以内）ため取り消せません。到達した後に、巻き戻しで戻してください。緊急に止めるときは deactivate を使います。',
                    409,
                );
            }

            $this->updateRun([
                'deploy_key' => (int) $row->deploy_key,
                'sys_deploy_master_id' => (int) $row->sys_deploy_master_id,
                'sys_deploy_id' => (int) $row->id,
                'start_at' => (string) $row->start_at,
            ], true);

            $this->phase('register');
            $deleted = $sys->table('sys_deploy')->where('id', $sysDeployId)->where('start_at', '>', $nowString)->delete();
            if ($deleted === 0) {
                throw new MasterDeployRunException(MasterDeployRunException::SCHEDULE_ALREADY_STARTED, '取り消しの直前に start_at に到達しました。巻き戻しの操作からやり直してください。', 409);
            }

            $warningList = [];
            $cache = $this->forgetSchedule();
            if (! $cache['invalidated']) {
                $warningList[] = $this->cacheWarning();
            }
            $this->summary['warning_list'] = $warningList;
            $this->summary['cache_invalidated'] = $cache['invalidated'];

            $remaining = [];
            foreach ($sys->table('sys_deploy')->where('start_at', '>', $nowString)->orderBy('start_at')->orderBy('id')->get() as $r) {
                $remaining[] = ['sys_deploy_id' => (int) $r->id, 'sys_deploy_master_id' => (int) $r->sys_deploy_master_id, 'deploy_key' => (int) $r->deploy_key, 'start_at' => (string) $r->start_at];
            }

            return [
                'response' => [
                    'status' => 'success',
                    'message' => '予約（切り替え行）を取り消しました。',
                    'cancelled' => [
                        'sys_deploy_id' => (int) $row->id,
                        'sys_deploy_master_id' => (int) $row->sys_deploy_master_id,
                        'deploy_key' => (int) $row->deploy_key,
                        'start_at' => (string) $row->start_at,
                    ],
                    'remaining_scheduled_list' => $remaining,
                    'cache' => ['invalidated' => $cache['invalidated']],
                    'warning_list' => $warningList,
                ],
            ];
        });
    }
}

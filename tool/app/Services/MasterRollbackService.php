<?php

namespace App\Services;

use App\Exceptions\MasterDeployRunException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 巻き戻し（db.md 3.6。api.md 9.1）
 *
 * 戻したい版（sys_deploy_master）を指す**新しい sys_deploy 行**を、`start_at = max(現在, 直前の最新行の start_at) + 1 秒` で足す。
 * 予約済みの未来の行があるときは rollback_blocked_by_scheduled で拒否する（現在 + 60 秒以内で戻し先と同じ master を指す行は数えない）。
 * 戻し先の DB が使えなければ、成果物から復元してよい（phase version_db。成果物のスキーマ版が基底と一致するときだけ。schema_mismatch）。
 * 順序: 検査（check）→（復元: version_db）→ sys に登録（register。復元のときは同じトランザクションで mst_database・mst_dropped_at も更新）→ schedule キーの破棄 → done
 */
class MasterRollbackService extends MasterDeployOperation
{
    /**
     * @param  array{dry_run?: bool, adm_account_id?: int|null}  $options
     * @return array<string, mixed>
     *
     * @throws MasterDeployRunException
     */
    public function run(int $sysDeployMasterId, array $options = []): array
    {
        $this->databases->assertConfiguration();
        $admAccountId = isset($options['adm_account_id']) ? (int) $options['adm_account_id'] : null;

        if ((bool) ($options['dry_run'] ?? false)) {
            // ロックは取らない・実行記録は書かない・何も変えない（api.md 9.1）
            $this->resetState();
            $plan = $this->plan($sysDeployMasterId, true);

            return [
                'status' => 'success',
                'message' => 'dry_run: 検査だけを行いました。',
                'dry_run' => true,
                'run_id' => null,
                'deploy' => [...$plan['deploy'], 'sys_deploy_id' => null],
                'needs_restore' => $plan['restore']['restored'],
                'warning_list' => $plan['warning_list'],
                'cache' => ['invalidated' => null],
            ];
        }

        $master = DB::connection('sys')->table('sys_deploy_master')->where('id', $sysDeployMasterId)->first();

        return $this->runLocked('rollback', $admAccountId, $master === null ? null : (int) $master->deploy_key, function () use ($sysDeployMasterId): array {
            $this->phase('check');
            $plan = $this->plan($sysDeployMasterId, false);
            $master = $plan['master'];
            $this->updateRun([
                'deploy_key' => (int) $master->deploy_key,
                'master_hash' => (string) $master->hash,
                'artifact_dir' => (string) $master->artifact_dir,
                'sys_deploy_master_id' => (int) $master->id,
            ], true);

            $this->phase('register');
            $registration = $this->registerSys([
                'deploy_key' => (int) $master->deploy_key,
                'hash' => (string) $master->hash,
                'artifact_dir' => (string) $master->artifact_dir,
                'schema_fingerprint' => $plan['restore']['schema_fingerprint'],
                'mst_database' => $plan['restore']['mst_database'],
                'mode' => 'explicit',
                'restored' => $plan['restore']['restored'],
                'start_at' => $plan['start_at'],
                'start_at_explicit' => false,
                'table_count' => 0,
                'file_size' => 0,
                'tables' => [],
            ]);
            $warningList = [...$plan['warning_list'], ...$registration['warning_list']];

            $cache = $this->forgetSchedule();
            if (! $cache['invalidated']) {
                $warningList[] = $this->cacheWarning();
            }
            $this->summary['warning_list'] = $warningList;
            $this->summary['cache_invalidated'] = $cache['invalidated'];
            $this->summary['restored'] = $plan['restore']['restored'];
            $this->summary['previous_sys_deploy_id'] = $plan['previous_sys_deploy_id'];
            if ($plan['restore']['restored']) {
                // 登録（sys の更新）が済んだので、以後は sys が指す DB。失敗でも DROP しない
                $this->forgetCreated($registration['mst_database']);
            }

            return [
                'response' => [
                    'status' => 'success',
                    'message' => "版（deploy_key={$master->deploy_key}）へ巻き戻す切り替え行を足しました。",
                    'dry_run' => false,
                    'deploy' => [
                        'sys_deploy_master_id' => (int) $master->id,
                        'sys_deploy_id' => $registration['sys_deploy_id'],
                        'deploy_key' => (int) $master->deploy_key,
                        'hash' => (string) $master->hash,
                        'mst_database' => $registration['mst_database'],
                        'start_at' => $registration['start_at'],
                        'is_new' => $registration['is_new'],
                        'restored' => $plan['restore']['restored'],
                        'previous_sys_deploy_id' => $plan['previous_sys_deploy_id'],
                    ],
                    'warning_list' => $warningList,
                    'cache' => ['invalidated' => $cache['invalidated']],
                ],
                'run' => [
                    'start_at' => $registration['start_at'],
                    'sys_deploy_master_id' => $registration['sys_deploy_master_id'],
                    'sys_deploy_id' => $registration['sys_deploy_id'],
                    'is_new' => $registration['is_new'],
                    'mst_database' => $registration['mst_database'],
                    'schema_fingerprint' => $plan['restore']['schema_fingerprint'],
                ],
            ];
        });
    }

    /**
     * 検査と、足す行の start_at・復元の要否（dry_run でないときは復元もここで行う）
     *
     * @return array{master: object, start_at: string, previous_sys_deploy_id: int|null, restore: array{restored: bool, mst_database: string, schema_fingerprint: string}, warning_list: list<array{code: string, message: string}>, deploy: array<string, mixed>}
     *
     * @throws MasterDeployRunException
     */
    private function plan(int $sysDeployMasterId, bool $dryRun): array
    {
        $sys = DB::connection('sys');
        $master = $sys->table('sys_deploy_master')->where('id', $sysDeployMasterId)->first();
        if ($master === null) {
            throw new MasterDeployRunException(MasterDeployRunException::ROLLBACK_TARGET_NOT_FOUND, "戻し先の版（sys_deploy_master.id={$sysDeployMasterId}）がありません。", 404);
        }
        // 本番ガード: 予約帯の版へは戻さない
        if ((string) config('app.env') === 'production' && (int) $master->deploy_key >= (int) config('master_deploy.reserved_floor')) {
            throw new MasterDeployRunException(MasterDeployRunException::RESERVED_KEY_FORBIDDEN, "本番では予約帯の版（deploy_key={$master->deploy_key}）へ戻せません。", 403);
        }

        $now = now()->utc();
        $nowString = $now->format('Y-m-d H:i:s');
        $graceLimit = $now->copy()->addSeconds((int) config('master_deploy.start_at_past_grace'))->format('Y-m-d H:i:s');

        // 予約済みの未来の行（現在 + grace 以内で戻し先と同じ master を指す行は数えない）
        $scheduled = [];
        foreach ($sys->table('sys_deploy')->where('start_at', '>', $nowString)->orderBy('start_at')->orderBy('id')->get() as $row) {
            if ((string) $row->start_at <= $graceLimit && (int) $row->sys_deploy_master_id === (int) $master->id) {
                continue;
            }
            $scheduled[] = ['sys_deploy_id' => (int) $row->id, 'deploy_key' => (int) $row->deploy_key, 'start_at' => (string) $row->start_at];
        }
        if ($scheduled !== []) {
            throw new MasterDeployRunException(
                MasterDeployRunException::ROLLBACK_BLOCKED_BY_SCHEDULED,
                '予約済み（start_at が現在より後）の切り替え行があるため、巻き戻せません。先に予約を取り消してください（cancel-schedule）。'
                .'start_at が現在 + '.(2 * (int) config('master_deploy.schedule_ttl')).' 秒以内の予約は取り消せないので、その予約が到達してから巻き戻してください。',
                409,
                $scheduled,
            );
        }

        $latest = $this->selector->latest($nowString);
        $base = $latest !== null && (string) $latest->start_at > $nowString ? (string) $latest->start_at : $nowString;
        $startAt = Carbon::createFromFormat('Y-m-d H:i:s', $base, 'UTC')->addSecond()->format('Y-m-d H:i:s');

        $warningList = [];
        if ($latest !== null && (int) $latest->sys_deploy_master_id === (int) $master->id) {
            $warningList[] = ['code' => 'rollback_target_is_current', 'message' => '戻し先はすでにいまの版です（止めずに新しい切り替え行を足します）'];
        }

        $restore = $this->ensureMasterUsable($master, MasterDeployRunException::ROLLBACK_TARGET_UNAVAILABLE, MasterDeployRunException::SCHEMA_MISMATCH, $dryRun);

        return [
            'master' => $master,
            'start_at' => $startAt,
            'previous_sys_deploy_id' => $latest === null ? null : (int) $latest->id,
            'restore' => $restore,
            'warning_list' => $warningList,
            'deploy' => [
                'sys_deploy_master_id' => (int) $master->id,
                'deploy_key' => (int) $master->deploy_key,
                'hash' => (string) $master->hash,
                'mst_database' => $restore['mst_database'],
                'start_at' => $startAt,
                'is_new' => null,
                'restored' => $restore['restored'],
                'previous_sys_deploy_id' => $latest === null ? null : (int) $latest->id,
            ],
        ];
    }
}

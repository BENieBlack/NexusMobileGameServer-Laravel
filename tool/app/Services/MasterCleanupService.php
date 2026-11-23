<?php

namespace App\Services;

use App\Exceptions\MasterDeployRunException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 掃除（db.md 4.6・4.7。api.md 9.3。手動。既定は dry_run）
 *
 * 対象の種類（drop_list[].kind）:
 * - version: 版の DB。消さないのは ① いまの版とその直前に最新だった有効な版 ② start_at が現在より後の版 ③ 直近 N 版（keep_version_count。
 *   sys_deploy_master を重複排除して数える。is_active = 1 で到達済みの行の最大 start_at の新しい順。止めた行は枠に数えない）
 *   ④ 基底 DB ⑤ 止めた行（is_active = 0）を指す版。消すときは DROP DATABASE IF EXISTS を先、mst_dropped_at の更新を後にする（途中で止まっても再実行で完了する）。成果物は残す
 * - orphan: 孤児の DB。実行記録で failed・aborted の run の created_database_list のうち、sys_deploy_master に無く、猶予（orphan_grace_hours）を過ぎたもの。
 *   register_unknown（summary.resolved が無い run）は除外。DROP の前に sys を再確認する
 * - work: 作業用 DB（接頭辞 `{APP_NAME}-{env}-master-work-`）で、実行記録が参照せず、名前の時刻が猶予を過ぎたもの
 * - tmp: master_artifact の disk の `.tmp/{UTC の時刻}-{run の id}/` で、名前の時刻が猶予を過ぎたもの
 * DROP は自環境の接頭辞の名前だけ（assertOwnName。接頭辞の外の行はその行だけ保持（foreign_database）にして警告 drop_forbidden_skipped を返す）。サーバー全体を SHOW DATABASES で走査して消すことはしない
 * （work の列挙だけは自環境の接頭辞に限る）。dry_run はロックを取らず、実行記録も書かない。実行はロックの後に対象を再計算する。
 * 消せなかったものがあれば cleanup_failed（消せたものは消えたまま。dropped_list・failed_list を返す）。phase は lock・check・drop・done
 */
class MasterCleanupService extends MasterDeployOperation
{
    /**
     * @param  array{dry_run?: bool, adm_account_id?: int|null}  $options  dry_run の既定は true
     * @return array<string, mixed>
     *
     * @throws MasterDeployRunException
     */
    public function run(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? true);
        $admAccountId = isset($options['adm_account_id']) ? (int) $options['adm_account_id'] : null;

        if ($dryRun) {
            $this->resetState();
            $plan = $this->plan();

            return [
                'status' => 'success',
                'message' => 'dry_run: 対象の一覧だけを返しました。',
                'dry_run' => true,
                'run_id' => null,
                'keep_list' => $plan['keep_list'],
                'drop_list' => $plan['drop_list'],
                'dropped_list' => [],
                'failed_list' => [],
                'warning_list' => $plan['warning_list'],
            ];
        }

        return $this->runLocked('cleanup', $admAccountId, null, function (): array {
            $this->phase('check');
            // ロックの後に対象を再計算する（dry_run の後に版が切り替わりうる）
            $plan = $this->plan();
            $this->summary['drop_list'] = $plan['drop_list'];

            $this->phase('drop');
            $dropped = [];
            $failed = [];
            foreach ($plan['drop_list'] as $item) {
                try {
                    $this->dropItem($item);
                    $dropped[] = ['kind' => $item['kind'], 'name' => $item['name']];
                } catch (Throwable $e) {
                    Log::warning('掃除で消せなかった', ['exception' => $e, 'item' => $item]);
                    $failed[] = ['kind' => $item['kind'], 'name' => $item['name'], 'message' => MasterVersionDatabase::safeMessage($e)];
                }
            }
            $this->summary['dropped_list'] = $dropped;
            $this->summary['failed_list'] = $failed;

            if ($failed !== []) {
                throw new MasterDeployRunException(
                    MasterDeployRunException::CLEANUP_FAILED,
                    '消せなかったものがあります（消せたものは消えたままです）。',
                    500,
                    extra: ['keep_list' => $plan['keep_list'], 'drop_list' => $plan['drop_list'], 'dropped_list' => $dropped, 'failed_list' => $failed],
                );
            }

            return [
                'response' => [
                    'status' => 'success',
                    'message' => count($dropped).' 件を掃除しました。',
                    'dry_run' => false,
                    'keep_list' => $plan['keep_list'],
                    'drop_list' => $plan['drop_list'],
                    'dropped_list' => $dropped,
                    'failed_list' => [],
                    'warning_list' => $plan['warning_list'],
                ],
            ];
        });
    }

    /**
     * @return array{keep_list: list<array<string, mixed>>, drop_list: list<array<string, mixed>>, warning_list: list<array{code: string, message: string}>}
     *
     * @throws MasterDeployRunException drop_forbidden
     */
    private function plan(): array
    {
        $sys = DB::connection('sys');
        $now = now()->utc();
        $nowString = $now->format('Y-m-d H:i:s');
        /** @var array<int, string> $keepReason */
        $keepReason = [];

        // ① いまの版と、その直前に最新だった有効な版（並びで次に来る、別の master を指す行）
        $candidates = $this->selector->candidates($nowString);
        if ($candidates !== []) {
            $keepReason[(int) $candidates[0]->sys_deploy_master_id] ??= 'current';
            foreach ($candidates as $candidate) {
                if ((int) $candidate->sys_deploy_master_id !== (int) $candidates[0]->sys_deploy_master_id) {
                    $keepReason[(int) $candidate->sys_deploy_master_id] ??= 'previous';
                    break;
                }
            }
        }
        // ② 予約済み
        foreach ($sys->table('sys_deploy')->where('start_at', '>', $nowString)->pluck('sys_deploy_master_id') as $id) {
            $keepReason[(int) $id] ??= 'scheduled';
        }
        // ⑤ 止めた行を指す版
        foreach ($sys->table('sys_deploy')->where('is_active', 0)->pluck('sys_deploy_master_id') as $id) {
            $keepReason[(int) $id] ??= 'inactive';
        }
        // ③ 直近 N 版（有効で到達済みの行の最大 start_at の新しい順）
        $latestByMaster = [];
        foreach ($sys->table('sys_deploy')->where('is_active', 1)->where('start_at', '<=', $nowString)->get() as $row) {
            $id = (int) $row->sys_deploy_master_id;
            if (! isset($latestByMaster[$id]) || (string) $row->start_at > $latestByMaster[$id]) {
                $latestByMaster[$id] = (string) $row->start_at;
            }
        }
        arsort($latestByMaster);
        foreach (array_slice(array_keys($latestByMaster), 0, max(0, (int) config('master_deploy.keep_version_count'))) as $id) {
            $keepReason[(int) $id] ??= 'recent';
        }

        $keepList = [];
        $dropList = [];
        $warningList = [];
        foreach ($sys->table('sys_deploy_master')->orderBy('id')->get() as $master) {
            if ($master->mst_dropped_at !== null) {
                continue;
            }
            $id = (int) $master->id;
            $name = (string) $master->mst_database;
            if ($name === $this->databases->baseDatabase() || $name === $this->databases->expectedBaseDatabase()) {
                $keepList[] = ['sys_deploy_master_id' => $id, 'mst_database' => $name, 'reason' => 'base'];

                continue;
            }
            if (isset($keepReason[$id])) {
                $keepList[] = ['sys_deploy_master_id' => $id, 'mst_database' => $name, 'reason' => $keepReason[$id]];

                continue;
            }
            try {
                $this->databases->assertOwnName($name);
            } catch (MasterDeployRunException) {
                // 接頭辞の外の名前は DROP しない。その行だけ保持にして警告を返す（plan 全体は止めない）
                $keepList[] = ['sys_deploy_master_id' => $id, 'mst_database' => $name, 'reason' => 'foreign_database'];
                $warningList[] = ['code' => 'drop_forbidden_skipped', 'message' => "自環境の接頭辞の外の名前のため掃除の対象にしません: {$name}"];

                continue;
            }
            $dropList[] = ['kind' => 'version', 'name' => $name, 'sys_deploy_master_id' => $id, 'reason' => 'outside_keep'];
        }

        $knownMasterDatabases = $sys->table('sys_deploy_master')->pluck('mst_database')->all();
        $grace = (int) config('master_deploy.orphan_grace_hours');
        $limit = $now->copy()->subHours($grace);

        // orphan
        $runs = DB::connection('tool')->table('tol_master_deploy_run')->whereIn('status', ['failed', 'aborted'])->orderBy('id')->get();
        $referencedWork = [];
        $seen = [];
        foreach (DB::connection('tool')->table('tol_master_deploy_run')->get() as $run) {
            $summary = $this->decodeSummary($run->summary);
            if (isset($summary['work_database'])) {
                $referencedWork[(string) $summary['work_database']] = true;
            }
        }
        foreach ($runs as $run) {
            $summary = $this->decodeSummary($run->summary);
            if ($run->error_code === MasterDeployRunException::REGISTER_UNKNOWN && ! isset($summary['resolved'])) {
                continue; // 人が確認する（resolve-unknown）
            }
            $finished = (string) ($run->finished_at ?? $run->started_at);
            if (Carbon::createFromFormat('Y-m-d H:i:s', $finished, 'UTC')->greaterThan($limit)) {
                continue;
            }
            foreach ((array) ($summary['created_database_list'] ?? []) as $name) {
                $name = (string) $name;
                if (isset($seen[$name]) || in_array($name, $knownMasterDatabases, true) || ! $this->databases->databaseExists($name)) {
                    continue;
                }
                try {
                    $this->databases->assertOwnName($name);
                } catch (MasterDeployRunException) {
                    $warningList[] = ['code' => 'drop_forbidden_skipped', 'message' => "自環境の接頭辞の外の名前のため孤児として扱いません: {$name}"];

                    continue;
                }
                $seen[$name] = true;
                $dropList[] = ['kind' => 'orphan', 'name' => $name, 'sys_deploy_master_id' => null, 'reason' => "run #{$run->id} が作った DB（{$run->status}）"];
            }
        }

        // work（実行記録が参照しないもの。名前の時刻で判定）
        $prefix = $this->databases->prefix().'work-';
        $like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefix).'%';
        foreach ($this->databases->connection()->select('SELECT SCHEMA_NAME AS name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE ? ORDER BY SCHEMA_NAME', [$like]) as $row) {
            $name = (string) $row->name;
            if (isset($seen[$name]) || isset($referencedWork[$name]) || preg_match('/-work-(\d{14})-[a-z0-9]{4}$/D', $name, $m) !== 1) {
                continue;
            }
            $time = Carbon::createFromFormat('YmdHis', $m[1], 'UTC');
            if ($time === null || $time->greaterThan($limit)) {
                continue;
            }
            $this->databases->assertOwnName($name);
            $seen[$name] = true;
            $dropList[] = ['kind' => 'work', 'name' => $name, 'sys_deploy_master_id' => null, 'reason' => '実行記録の無い作業用 DB'];
        }

        // tmp
        $disk = $this->store->disk();
        foreach ($disk->directories('.tmp') as $dir) {
            if (preg_match('#(?:^|/)\.tmp/(\d{14})-\d+$#D', $dir, $m) !== 1) {
                continue;
            }
            $time = Carbon::createFromFormat('YmdHis', $m[1], 'UTC');
            if ($time !== null && ! $time->greaterThan($limit)) {
                $dropList[] = ['kind' => 'tmp', 'name' => $dir, 'sys_deploy_master_id' => null, 'reason' => '猶予を過ぎた一時の場所'];
            }
        }

        return ['keep_list' => $keepList, 'drop_list' => $dropList, 'warning_list' => $warningList];
    }

    /**
     * @param  array<string, mixed>  $item
     *
     * @throws Throwable
     */
    private function dropItem(array $item): void
    {
        $name = (string) $item['name'];
        switch ($item['kind']) {
            case 'version':
                // DROP を先、mst_dropped_at の更新を後（途中で止まっても再実行で冪等に完了する）
                $this->databases->drop($name);
                DB::connection('sys')->table('sys_deploy_master')->where('id', (int) $item['sys_deploy_master_id'])->update([
                    'mst_dropped_at' => $this->nowString(),
                    'updated_at' => $this->nowString(),
                ]);
                break;
            case 'orphan':
                // DROP の前に sys を再確認する（登録されていたら消さない）
                if (DB::connection('sys')->table('sys_deploy_master')->where('mst_database', $name)->exists()) {
                    throw new MasterDeployRunException(MasterDeployRunException::DROP_FORBIDDEN, "sys に登録されている DB は孤児として消せません: {$name}", 403);
                }
                $this->databases->drop($name);
                break;
            case 'work':
                $this->databases->drop($name);
                break;
            case 'tmp':
                $this->store->disk()->deleteDirectory($name);
                break;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSummary(mixed $summary): array
    {
        if (is_array($summary)) {
            return $summary;
        }
        $decoded = is_string($summary) ? json_decode($summary, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}

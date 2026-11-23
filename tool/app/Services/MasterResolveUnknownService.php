<?php

namespace App\Services;

use App\Exceptions\MasterDeployRunException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * register_unknown の片付け（master:resolve-unknown。db.md 4.7。api.md 9.5）
 *
 * sys の COMMIT の成否が不明で、sys を読めなかった run（status = failed・error_code = register_unknown）の DB を、人が確認して片付ける。
 * **新しい run は作らず、対象の既存の run を更新するだけ**（kind は足さない）。ロックの中で行う。ツールが sys を再確認して決める:
 * 1. sys を読めなければ sys_unreadable（run は register_unknown のまま）
 * 2. (a) 登録済み（sys に同じ mst_database の行がある）: run を succeeded に直す（error_code は消さず summary.resolved = registered）。DB は消さない。schedule キーを破棄する
 * 3. (b) 未登録: 「sys に同じ mst_database の行が無い」かつ「その run の created_database_list にある名前」に限り DROP DATABASE IF EXISTS（保護の規則つき）。
 *    run は failed のまま閉じる（summary.resolved = dropped）。どちらかを満たさなければ drop_forbidden。DROP に失敗したら drop_failed（run は register_unknown のまま）
 * 既に summary.resolved がある run は何もせず already_resolved（冪等）
 */
class MasterResolveUnknownService extends MasterDeployOperation
{
    /**
     * @param  array{adm_account_id?: int|null}  $options
     * @return array<string, mixed>
     *
     * @throws MasterDeployRunException
     */
    public function run(int $runId, array $options = []): array
    {
        $admAccountId = isset($options['adm_account_id']) ? (int) $options['adm_account_id'] : null;

        return $this->withLockNoRecord(function () use ($runId, $admAccountId): array {
            $tool = DB::connection('tool');
            $run = $tool->table('tol_master_deploy_run')->where('id', $runId)->first();
            if ($run === null || $run->error_code !== MasterDeployRunException::REGISTER_UNKNOWN) {
                throw new MasterDeployRunException(MasterDeployRunException::RUN_NOT_FOUND, "register_unknown の実行記録（run_id={$runId}）がありません。", 404);
            }
            $summary = $this->decode($run->summary);
            if (isset($summary['resolved'])) {
                return [
                    'status' => 'success',
                    'code' => 'already_resolved',
                    'message' => 'すでに解決済みです。',
                    'run_id' => $runId,
                    'resolved' => (string) $summary['resolved'],
                    'cache' => ['invalidated' => null],
                    'warning_list' => [],
                ];
            }

            $database = (string) ($run->mst_database ?? '');
            try {
                $registered = $database !== '' && DB::connection('sys')->table('sys_deploy_master')->where('mst_database', $database)->exists();
            } catch (Throwable $e) {
                throw new MasterDeployRunException(MasterDeployRunException::SYS_UNREADABLE, 'sys を読めないため、登録済みかを確認できません。', 503, previous: $e);
            }
            $now = $this->nowString();
            $summary['resolved_at'] = $now;
            $summary['resolved_by'] = $admAccountId;

            if ($registered) {
                $summary['resolved'] = 'registered';
                $this->writeRun($runId, ['status' => 'succeeded', 'summary' => $summary]);
                $warningList = [];
                $cache = $this->forgetSchedule();
                if (! $cache['invalidated']) {
                    $warningList[] = $this->cacheWarning();
                }

                return [
                    'status' => 'success',
                    'code' => 'resolved',
                    'message' => '登録済みと確認し、実行記録を成功に直しました。',
                    'run_id' => $runId,
                    'resolved' => 'registered',
                    'cache' => ['invalidated' => $cache['invalidated']],
                    'warning_list' => $warningList,
                ];
            }

            $created = array_map('strval', (array) ($summary['created_database_list'] ?? []));
            if ($database === '' || ! in_array($database, $created, true)) {
                throw new MasterDeployRunException(
                    MasterDeployRunException::DROP_FORBIDDEN,
                    'この run が新規に作った DB（created_database_list）ではないため DROP できません（採用した DB の可能性があります）。',
                    403,
                );
            }
            $this->databases->assertOwnName($database);
            try {
                $this->databases->drop($database);
            } catch (Throwable $e) {
                Log::error('register_unknown の DB の DROP に失敗', ['exception' => $e, 'run_id' => $runId]);
                throw new MasterDeployRunException(MasterDeployRunException::DROP_FAILED, 'DROP に失敗しました（run は register_unknown のままです。再実行できます）: '.MasterVersionDatabase::safeMessage($e), 500, previous: $e);
            }
            $summary['resolved'] = 'dropped';
            $summary['created_database_list'] = array_values(array_filter($created, static fn (string $n): bool => $n !== $database));
            $this->writeRun($runId, ['summary' => $summary]);

            return [
                'status' => 'success',
                'code' => 'resolved',
                'message' => '未登録と確認し、DB を削除しました。',
                'run_id' => $runId,
                'resolved' => 'dropped',
                'cache' => ['invalidated' => null],
                'warning_list' => [],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $changes  summary は配列で渡す
     */
    private function writeRun(int $runId, array $changes): void
    {
        $changes['summary'] = json_encode($changes['summary'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        DB::connection('tool')->table('tol_master_deploy_run')->where('id', $runId)->update([...$changes, 'updated_at' => $this->nowString()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $summary): array
    {
        if (is_array($summary)) {
            return $summary;
        }
        $decoded = is_string($summary) ? json_decode($summary, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}

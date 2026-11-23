<?php

namespace App\Services;

use App\Exceptions\MasterDeployKeyException;
use Illuminate\Support\Facades\DB;

/**
 * 運営画面に出す「デプロイの対象」（GET /master-import/target と画面の props `target`）を組み立てる
 *
 * 設計: docs/design/master-distribution/api.md 3 節
 * DB と設定を読むだけで、シートは読まない。クラス名は仮置き。
 * running は IS_FREE_LOCK（読み取りだけ）でロックが使われているかを判定する（実行中の他プロセスを邪魔しない）。
 * warning_list の条件は MasterCacheInvalidator::needsFullInvalidation（db.md 8 節 M1）と同じ。
 */
class MasterDeployTargetService
{
    public const WARNING_INCOMPLETE = '前回の実行が完了していません。再実行すれば直ります（古いキャッシュを今すぐ消すなら clear-cache）';

    public const WARNING_CACHE_STALE = 'API のキャッシュが古い可能性があります。次のデプロイが全表を破棄します。今すぐなら clear-cache';

    public function __construct(
        private readonly MasterDeployKeyPolicy $policy,
        private readonly MasterDeployLock $lock,
        private readonly MasterCacheInvalidator $invalidator,
        private readonly MasterDeployService $deployService,
    ) {}

    /**
     * @param  bool  $spreadsheetDirConfigured  GoogleSpreadsheetService::isConfigured() の結果（呼び出し側が渡す）
     * @return array{
     *   app_env: string,
     *   mode: string,
     *   fixed_key: int|null,
     *   next_key: int|null,
     *   blocked: array{code: string, message: string}|null,
     *   spreadsheet_dir_configured: bool,
     *   running: bool,
     *   last_run: array<string, mixed>|null,
     *   warning_list: list<string>,
     * }
     */
    public function build(bool $spreadsheetDirConfigured): array
    {
        $mode = 'real';
        $fixedKey = null;
        $nextKey = null;
        $blocked = null;

        try {
            $fixedKey = $this->policy->resolveFixedKey();
            $mode = $fixedKey === null ? 'real' : 'fixed';
            if ($mode === 'real') {
                $nextKey = $this->deployService->allocateRealKey();
            }
        } catch (MasterDeployKeyException $e) {
            $blocked = ['code' => $e->errorCode, 'message' => $e->getMessage()];
            if ($e->errorCode === MasterDeployKeyException::FIXED_KEY_INVALID) {
                // 設定値が不正（fixed を指定した意図はある）。T は出さない
                $mode = 'fixed';
            } elseif ($e->errorCode === MasterDeployKeyException::FIXED_KEY_FORBIDDEN) {
                $mode = 'fixed';
                $fixedKey = (int) config('master_deploy.fixed_key');
            }
        }

        $running = $this->isRunning();
        $lastRun = $this->selectLastRun();

        return [
            'app_env' => (string) config('app.env'),
            'mode' => $mode,
            'fixed_key' => $mode === 'fixed' ? $fixedKey : null,
            'next_key' => $nextKey,
            'blocked' => $blocked,
            'spreadsheet_dir_configured' => $spreadsheetDirConfigured,
            'running' => $running,
            'last_run' => $lastRun,
            'warning_list' => $this->buildWarningList($lastRun, $running),
        ];
    }

    /** ロックを誰かが持っていれば実行中（読み取りだけ。ロックは取らない） */
    private function isRunning(): bool
    {
        return ! $this->lock->isFree();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function selectLastRun(): ?array
    {
        $row = DB::connection('tool')
            ->table('tol_master_deploy_run')
            ->orderByDesc('id')
            ->first(['id', 'status', 'phase', 'deploy_key', 'started_at', 'finished_at', 'error_code', 'error_message']);
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'status' => (string) $row->status,
            'phase' => (string) $row->phase,
            'deploy_key' => $row->deploy_key === null ? null : (int) $row->deploy_key,
            'started_at' => $row->started_at === null ? null : (string) $row->started_at,
            'finished_at' => $row->finished_at === null ? null : (string) $row->finished_at,
            'error_code' => $row->error_code === null ? null : (string) $row->error_code,
            'error_message' => $row->error_message === null ? null : (string) $row->error_message,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $lastRun
     * @return list<string>
     */
    private function buildWarningList(?array $lastRun, bool $running): array
    {
        $warningList = [];
        if ($lastRun !== null && in_array($lastRun['status'], ['inconsistent', 'aborted'], true)) {
            $warningList[] = self::WARNING_INCOMPLETE;
        }

        $commitReached = $this->invalidator->findLastCommitReachedRun();
        // 他のプロセスが実行中の run（running）は、これから終わるので警告にしない
        $inProgress = $running && $commitReached !== null && $commitReached['status'] === 'running';
        if (! $inProgress && MasterCacheInvalidator::needsFullInvalidation($commitReached)) {
            $warningList[] = self::WARNING_CACHE_STALE;
        }

        return $warningList;
    }
}

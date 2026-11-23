<?php

namespace App\Services;

use App\Exceptions\MasterDeployKeyException;
use App\Exceptions\MasterDeployRunException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 運営画面に出す「デプロイの対象」（GET /master-import/target と画面の props `target`）と、成果物の一覧（artifact-list）を組み立てる
 *
 * 設計: docs/design/master-distribution/api.md 4 節（target）・5 節（artifact-list）、db.md 3.3・3.6
 * DB・設定・保存済みの実行記録を読むだけで、シートは読まない。ロックは取らない（running は IS_FREE_LOCK）。
 * 版の DB が実在するかの確認（information_schema）以外は、sys と tol だけを読む。
 */
class MasterDeployTargetService
{
    /** 基底 DB のスキーマ版のキャッシュ秒数（db.md 4.4。画面を開くたびに全表を読み直さない） */
    public const FINGERPRINT_TTL = 60;

    public function __construct(
        private readonly MasterDeployKeyPolicy $policy,
        private readonly MasterDeployLock $lock,
        private readonly MasterVersionDatabase $databases,
        private readonly MasterDeploySelector $selector,
        private readonly MasterArtifactStore $store,
        private readonly MasterArtifactVerifier $verifier,
    ) {}

    /**
     * @param  bool  $spreadsheetDirConfigured  GoogleSpreadsheetService::checkConfigured() の結果（呼び出し側が渡す）
     * @return array<string, mixed> api.md 4 節の Response
     */
    public function build(bool $spreadsheetDirConfigured): array
    {
        $role = (string) config('master_deploy.role');
        $now = now()->utc();
        $nowString = $now->format('Y-m-d H:i:s');

        [$mode, $deployKey, $blocked] = $this->inspectConfiguration($role);
        $baseName = $this->databases->baseDatabase();
        $baseFingerprint = $this->baseFingerprint();

        $sys = DB::connection('sys');
        $deployRows = $sys->table('sys_deploy as d')
            ->join('sys_deploy_master as m', 'm.id', '=', 'd.sys_deploy_master_id')
            ->select(['d.id', 'd.deploy_key', 'd.start_at', 'd.is_active', 'd.sys_deploy_master_id', 'm.hash as master_hash'])
            ->orderBy('d.start_at')->orderBy('d.id')
            ->get();

        $current = $this->selector->latest($nowString);
        $currentVersion = $current === null ? null : [
            'sys_deploy_id' => (int) $current->id,
            'sys_deploy_master_id' => (int) $current->sys_deploy_master_id,
            'deploy_key' => (int) $current->deploy_key,
            'hash' => (string) $current->master_hash,
            'artifact_dir' => (string) $current->master_artifact_dir,
            'mst_database' => (string) $current->master_mst_database,
            'start_at' => (string) $current->start_at,
            'is_active' => (bool) $current->is_active,
        ];

        $scheduledList = [];
        $inactiveList = [];
        $lastStartAt = [];
        foreach ($deployRows as $row) {
            $start = (string) $row->start_at;
            $masterId = (int) $row->sys_deploy_master_id;
            if ($start > $nowString) {
                $scheduledList[] = [
                    'sys_deploy_id' => (int) $row->id, 'sys_deploy_master_id' => $masterId, 'deploy_key' => (int) $row->deploy_key,
                    'hash' => (string) $row->master_hash, 'start_at' => $start, 'is_active' => (bool) $row->is_active,
                ];
            } elseif (! isset($lastStartAt[$masterId]) || $start > $lastStartAt[$masterId]) {
                $lastStartAt[$masterId] = $start;
            }
            if (! (bool) $row->is_active) {
                $inactiveList[] = [
                    'sys_deploy_id' => (int) $row->id, 'sys_deploy_master_id' => $masterId, 'deploy_key' => (int) $row->deploy_key,
                    'hash' => (string) $row->master_hash, 'start_at' => $start,
                ];
            }
        }

        $versionList = $this->buildVersionList($deployRows->all(), $lastStartAt, $current === null ? null : (int) $current->sys_deploy_master_id, $baseFingerprint, $now);
        $running = ! $this->lock->isFree();
        $lastRun = $this->selectLastRun();
        $followCheck = $role === 'build' ? $this->selectFollowCheck() : null;

        return [
            'app_env' => (string) config('app.env'),
            'role' => $role,
            'mode' => $mode,
            'deploy_key' => $deployKey,
            'blocked' => $blocked,
            'spreadsheet_dir_configured' => $role === 'build' && $spreadsheetDirConfigured,
            'base_database' => ['name' => $baseName, 'schema_fingerprint' => $baseFingerprint],
            'running' => $running,
            'now' => $nowString,
            'current_version' => $currentVersion,
            'scheduled_list' => $scheduledList,
            'inactive_list' => $inactiveList,
            'version_list' => $versionList,
            'last_run' => $lastRun,
            'follow_check' => $followCheck,
            'warning_list' => $this->buildWarningList($currentVersion, $scheduledList, $inactiveList, $versionList, $followCheck, $now),
        ];
    }

    /**
     * 成果物の一覧（`manifest.json` があるものだけ。built_at の新しい順）
     *
     * @param  'upstream'|'own'  $source
     * @return list<array<string, mixed>>
     *
     * @throws MasterDeployRunException upstream_not_configured・pull_failed
     */
    public function artifactList(string $source): array
    {
        if ($source === 'upstream') {
            if (config('filesystems.disks.master_artifact_upstream') === null) {
                throw new MasterDeployRunException(MasterDeployRunException::UPSTREAM_NOT_CONFIGURED, '上流の disk（master_artifact_upstream）が設定されていません。', 500);
            }
            $store = new MasterArtifactStore('master_artifact_upstream');
        } else {
            $store = $this->store;
        }

        $baseFingerprint = $this->baseFingerprint();
        $known = [];
        foreach (DB::connection('sys')->table('sys_deploy_master')->get(['deploy_key', 'hash']) as $master) {
            $known[((int) $master->deploy_key).'_'.(string) $master->hash] = true;
        }

        $list = [];
        try {
            $directories = $store->disk()->directories();
            foreach ($directories as $directory) {
                $dir = basename((string) $directory);
                if (preg_match(MasterArtifactStore::DIR_PATTERN, $dir) !== 1) {
                    continue;
                }
                $manifest = $store->manifest($dir);
                if ($manifest === null || ! isset($manifest['deploy_key'], $manifest['hash'])) {
                    continue;
                }
                $deployKey = (int) $manifest['deploy_key'];
                $hash = (string) $manifest['hash'];
                $list[] = [
                    'artifact_dir' => $dir,
                    'deploy_key' => $deployKey,
                    'hash' => $hash,
                    'schema_fingerprint' => (string) ($manifest['schema_fingerprint'] ?? ''),
                    'built_env' => (string) ($manifest['built_env'] ?? ''),
                    'built_at' => (string) ($manifest['built_at'] ?? ''),
                    'source_kind' => (string) ($manifest['source_kind'] ?? ''),
                    'finalized' => $this->verifier->isFinal($store->disk(), $dir.'/final.json', $deployKey, $hash),
                    'imported' => isset($known[$deployKey.'_'.$hash]),
                    'schema_matches' => $baseFingerprint !== null && (string) ($manifest['schema_fingerprint'] ?? '') === $baseFingerprint,
                ];
            }
        } catch (Throwable $e) {
            report($e);
            throw new MasterDeployRunException(MasterDeployRunException::PULL_FAILED, '成果物の置き場を読めませんでした。ログを確認してください。', 502, previous: $e);
        }
        usort($list, static fn (array $a, array $b): int => [$b['built_at'], $b['artifact_dir']] <=> [$a['built_at'], $a['artifact_dir']]);

        return $list;
    }

    /**
     * 環境設定の検査の結果（いま操作できない理由）。build・import に効く設定の誤りだけを返す
     *
     * @return array{0: string|null, 1: int|null, 2: array{code: string, message: string}|null} [mode, deploy_key, blocked]
     */
    private function inspectConfiguration(string $role): array
    {
        if (! in_array($role, ['build', 'import'], true)) {
            return [null, null, ['code' => 'role_not_configured', 'message' => 'MASTER_DEPLOY_ROLE は build か import のどちらかを設定してください（現在: '.($role === '' ? '未設定' : $role).'）。']];
        }

        $mode = null;
        $deployKey = null;
        $blocked = null;
        if ($role === 'build') {
            try {
                $target = $this->policy->resolveBuildKey();
                $mode = $target['mode'];
                $deployKey = $target['deploy_key'];
            } catch (MasterDeployKeyException $e) {
                $blocked = ['code' => $e->errorCode, 'message' => $e->getMessage()];
                $mode = config('master_deploy.fixed_key') !== null ? 'fixed' : (config('master_deploy.explicit_key') !== null ? 'explicit' : null);
            }
        } elseif (config('filesystems.disks.master_artifact_upstream') === null) {
            $blocked = ['code' => MasterDeployRunException::UPSTREAM_NOT_CONFIGURED, 'message' => '上流の disk（master_artifact_upstream）が設定されていません。'];
        }

        if ($blocked === null) {
            try {
                $this->databases->assertConfiguration();
            } catch (MasterDeployRunException $e) {
                $blocked = ['code' => $e->errorCode, 'message' => $e->getMessage()];
            }
        }

        return [$mode, $deployKey, $blocked];
    }

    /** 基底 DB のスキーマ版（短い TTL でキャッシュ。読めなければ null） */
    private function baseFingerprint(): ?string
    {
        $base = $this->databases->baseDatabase();
        try {
            return Cache::remember('tool:master:schema_fingerprint:'.$base, self::FINGERPRINT_TTL, fn (): string => $this->databases->schemaFingerprint($base));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param  list<object>  $deployRows
     * @param  array<int, string>  $lastStartAt  master id => 到達済みの最大 start_at
     * @return list<array<string, mixed>>
     */
    private function buildVersionList(array $deployRows, array $lastStartAt, ?int $currentMasterId, ?string $baseFingerprint, Carbon $now): array
    {
        $nowString = $now->format('Y-m-d H:i:s');
        $graceLimit = $now->copy()->addSeconds((int) config('master_deploy.start_at_past_grace'))->format('Y-m-d H:i:s');
        $future = array_values(array_filter($deployRows, static fn (object $r): bool => (string) $r->start_at > $nowString));

        $list = [];
        $masters = DB::connection('sys')->table('sys_deploy_master')->orderByDesc('id')->get();
        foreach ($masters as $master) {
            $id = (int) $master->id;
            $schemaMatches = $baseFingerprint !== null && (string) $master->schema_fingerprint === $baseFingerprint;

            // 予約済みの判定（現在 + grace 以内で戻し先と同じ master を指す行は数えない。api.md 9.1）
            $blockedByScheduled = false;
            foreach ($future as $row) {
                if ((string) $row->start_at <= $graceLimit && (int) $row->sys_deploy_master_id === $id) {
                    continue;
                }
                $blockedByScheduled = true;
                break;
            }

            $list[] = [
                'sys_deploy_master_id' => $id,
                'deploy_key' => (int) $master->deploy_key,
                'hash' => (string) $master->hash,
                'artifact_dir' => (string) $master->artifact_dir,
                'mst_database' => (string) $master->mst_database,
                'source_kind' => (string) $master->source_kind,
                'schema_fingerprint' => (string) $master->schema_fingerprint,
                'schema_matches' => $schemaMatches,
                'mst_dropped_at' => $master->mst_dropped_at === null ? null : (string) $master->mst_dropped_at,
                'last_start_at' => $lastStartAt[$id] ?? null,
                'is_current' => $currentMasterId === $id,
                'rollback_state' => $blockedByScheduled ? 'blocked_by_scheduled' : $this->rollbackState($master, $schemaMatches, $baseFingerprint),
            ];
        }

        return $list;
    }

    /**
     * 戻し先としての状態（予約の判定は呼び出し側）。ok・needs_restore・unavailable・schema_mismatch
     */
    private function rollbackState(object $master, bool $schemaMatches, ?string $baseFingerprint): string
    {
        $usable = $master->mst_dropped_at === null;
        if ($usable) {
            try {
                $usable = $this->databases->databaseExists((string) $master->mst_database);
            } catch (Throwable) {
                $usable = false;
            }
        }
        if ($usable) {
            return $schemaMatches ? 'ok' : 'schema_mismatch';
        }

        // 版の DB が無い: 自環境の成果物から復元できるか
        $manifest = $this->store->manifest((string) $master->artifact_dir);
        if ($manifest === null) {
            return 'unavailable';
        }

        return $baseFingerprint !== null && (string) ($manifest['schema_fingerprint'] ?? '') === $baseFingerprint ? 'needs_restore' : 'schema_mismatch';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function selectLastRun(): ?array
    {
        $row = DB::connection('tool')->table('tol_master_deploy_run')->orderByDesc('id')->first();
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'kind' => (string) $row->kind,
            'status' => (string) $row->status,
            'phase' => (string) $row->phase,
            'deploy_key' => $row->deploy_key === null ? null : (int) $row->deploy_key,
            'artifact_dir' => $row->artifact_dir === null ? null : (string) $row->artifact_dir,
            'started_at' => $row->started_at === null ? null : (string) $row->started_at,
            'finished_at' => $row->finished_at === null ? null : (string) $row->finished_at,
            'error_code' => $row->error_code === null ? null : (string) $row->error_code,
            'error_message' => $row->error_message === null ? null : (string) $row->error_message,
        ];
    }

    /**
     * 追従検出の直近の結果（直近の finalize の実行記録の summary.follow_check。シートは読まない）
     *
     * @return array<string, mixed>
     */
    private function selectFollowCheck(): array
    {
        $runs = DB::connection('tool')->table('tol_master_deploy_run')->where('kind', 'finalize')->orderByDesc('id')->limit(20)->get();
        foreach ($runs as $run) {
            $summary = $this->decode($run->summary);
            if (isset($summary['follow_check']) && is_array($summary['follow_check'])) {
                $check = $summary['follow_check'];

                return [
                    'status' => (string) ($check['status'] ?? 'not_checked'),
                    'checked_at' => $check['checked_at'] ?? null,
                    'artifact_dir' => $check['artifact_dir'] ?? null,
                    'changed_deploy_key_list' => (array) ($check['changed_deploy_key_list'] ?? []),
                    'reason' => $check['reason'] ?? null,
                    'allow_unverified' => (bool) ($summary['allow_unverified'] ?? false),
                ];
            }
        }

        return ['status' => 'not_checked', 'checked_at' => null, 'artifact_dir' => null, 'changed_deploy_key_list' => [], 'reason' => null, 'allow_unverified' => false];
    }

    /**
     * @param  array<string, mixed>|null  $currentVersion
     * @param  list<array<string, mixed>>  $scheduledList
     * @param  list<array<string, mixed>>  $inactiveList
     * @param  list<array<string, mixed>>  $versionList
     * @param  array<string, mixed>|null  $followCheck
     * @return list<array{code: string, message: string}>
     */
    private function buildWarningList(?array $currentVersion, array $scheduledList, array $inactiveList, array $versionList, ?array $followCheck, Carbon $now): array
    {
        $warnings = [];
        if ($currentVersion === null) {
            $warnings[] = ['code' => 'no_current_version', 'message' => 'いまの版がありません（API は配信できません）。'];
        }

        $tool = DB::connection('tool');
        $knownDatabases = DB::connection('sys')->table('sys_deploy_master')->pluck('mst_database')->all();

        // 直近の run の中断・失敗で版の DB が残っていないか
        $last = $tool->table('tol_master_deploy_run')->orderByDesc('id')->first();
        if ($last !== null && ($last->status === 'aborted' || ($last->status === 'failed' && $last->error_code !== MasterDeployRunException::REGISTER_UNKNOWN))
            && $last->mst_database !== null && $this->databaseExistsSafe((string) $last->mst_database)) {
            $warnings[] = ['code' => 'incomplete_run', 'message' => "前回の実行（run #{$last->id}）が完了せず、版の DB が残っている可能性があります。cleanup（既定は dry_run）で確認できます。"];
        }

        // register_unknown（未解決）と、孤児の候補
        $unknown = 0;
        $orphans = [];
        foreach ($tool->table('tol_master_deploy_run')->whereIn('status', ['failed', 'aborted'])->orderByDesc('id')->limit(50)->get() as $run) {
            $summary = $this->decode($run->summary);
            if ($run->error_code === MasterDeployRunException::REGISTER_UNKNOWN && ! isset($summary['resolved'])) {
                $unknown++;

                continue;
            }
            foreach ((array) ($summary['created_database_list'] ?? []) as $name) {
                $name = (string) $name;
                if (! in_array($name, $knownDatabases, true) && ! isset($orphans[$name]) && $this->databaseExistsSafe($name)) {
                    $orphans[$name] = true;
                }
            }
        }
        if ($unknown > 0) {
            $warnings[] = ['code' => 'register_unknown', 'message' => "sys の COMMIT の成否が不明な run が {$unknown} 件あります（DB は DROP していません）。master:resolve-unknown {run_id} で片付けてください。放置すると掃除の対象外のまま DB が残ります。"];
        }
        if ($orphans !== []) {
            $warnings[] = ['code' => 'orphan_database', 'message' => '孤児の候補の DB が '.count($orphans).' 件あります（cleanup で消せます）。'];
        }

        $stale = array_filter($versionList, static fn (array $v): bool => $v['mst_dropped_at'] === null && $v['schema_matches'] === false);
        if ($stale !== []) {
            $warnings[] = ['code' => 'schema_stale_version', 'message' => '現行のスキーマと一致しない版が '.count($stale).' 件あります（巻き戻し・復元は schema_mismatch で拒否されます）。'];
        }

        // 直近の成功した run の schedule キーの破棄が失敗していて、TTL 以内か
        $ttl = (int) config('master_deploy.schedule_ttl');
        $recent = $tool->table('tol_master_deploy_run')->where('status', 'succeeded')->orderByDesc('id')->first();
        if ($recent !== null && $recent->finished_at !== null) {
            $summary = $this->decode($recent->summary);
            if (($summary['cache_invalidated'] ?? null) === false
                && Carbon::createFromFormat('Y-m-d H:i:s', (string) $recent->finished_at, 'UTC')->addSeconds($ttl)->greaterThan($now)) {
                $warnings[] = ['code' => 'cache_not_invalidated', 'message' => "直近の操作（run #{$recent->id}）で schedule キーを破棄できていません。最大 {$ttl} 秒は古い判断が残ります（clear-cache で再試行できます）。"];
            }
        }

        if ($followCheck !== null && $followCheck['status'] === 'changed') {
            $warnings[] = ['code' => 'sheet_changed_since_build', 'message' => '直近の追従検出で、シートが成果物の build 後に変わっています。build をやり直して再度 QA してください。'];
        }
        if ($followCheck !== null && $followCheck['status'] === 'unverified') {
            $warnings[] = ['code' => 'follow_check_unverified', 'message' => 'シートを読めないまま --allow-unverified で finalize しました（追従を確かめていません）。'];
        }
        if ($scheduledList !== []) {
            $warnings[] = ['code' => 'scheduled_exists', 'message' => '予約済みの切り替えが '.count($scheduledList).' 件あります（巻き戻しは取り消しが先）。'];
        }
        if ($inactiveList !== []) {
            $warnings[] = ['code' => 'inactive_exists', 'message' => '止めた行（is_active = false）が '.count($inactiveList).' 件あります（再開は activate）。'];
        }

        return $warnings;
    }

    private function databaseExistsSafe(string $name): bool
    {
        try {
            return $this->databases->databaseExists($name);
        } catch (Throwable) {
            return false;
        }
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

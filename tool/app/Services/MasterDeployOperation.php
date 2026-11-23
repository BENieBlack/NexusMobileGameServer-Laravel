<?php

namespace App\Services;

use App\Exceptions\MasterDeployKeyException;
use App\Exceptions\MasterDeployRunException;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 段階 4 の操作（import・finalize・rollback・cancel-schedule・cleanup・deactivate/activate）の共通部分
 *
 * 設計: docs/design/master-distribution/db.md 3.6・4.2〜4.7・5 節・6.6、api.md 11 節・13 節
 *
 * - 排他: MasterDeployLock（sys 接続の GET_LOCK。待たない）。取れなければ deploy_locked（実行記録は作らない）
 * - 実行記録: `tol_master_deploy_run`（tool 接続。各更新は autocommit。sys の COMMIT の前は書けなければ中止、後は握る）
 * - 失敗したときは、この run が新規に作った DB（`created`）だけを `DROP` する（4.7。ベストエフォート）。sys の COMMIT の成否が不明のときは DROP しない
 * - 版の DB の確定（`version_db`）: 既存の使える DB の再利用・孤児の採用・成果物の行データからの新規作成（6.6）
 * - schedule キーの破棄の失敗は成功のまま警告にする（3.6）
 *
 * MasterBuildRunner は別のクラスで、この基底クラスと同じ規則を自分で持つ（統合は未実施。実装の重複は残っている）。
 */
abstract class MasterDeployOperation
{
    protected readonly MasterDeployLock $lock;

    protected readonly MasterVersionDatabase $databases;

    protected readonly MasterCacheInvalidator $invalidator;

    protected readonly MasterArtifactStore $store;

    protected readonly MasterDeployService $deployService;

    protected readonly MasterDeploySelector $selector;

    protected readonly MasterArtifactVerifier $verifier;

    /** @var list<string> この run が新規に作った DB 名（失敗時の DROP の対象。db.md 4.7） */
    protected array $created = [];

    /** @var array<string, mixed> 実行記録の summary */
    protected array $summary = [];

    protected ?int $runId = null;

    /** true なら失敗時に版の DB を DROP しない（sys の COMMIT の成否が不明。db.md 4.7） */
    protected bool $keepDatabases = false;

    /** @var list<string> run の終わりに消す作業ディレクトリ */
    private array $tempDirs = [];

    public function __construct(
        ?MasterDeployLock $lock = null,
        ?MasterVersionDatabase $databases = null,
        ?MasterCacheInvalidator $invalidator = null,
        ?MasterArtifactStore $store = null,
        ?MasterDeployService $deployService = null,
        ?MasterDeploySelector $selector = null,
        ?MasterArtifactVerifier $verifier = null,
    ) {
        $this->lock = $lock ?? new MasterDeployLock;
        $this->databases = $databases ?? new MasterVersionDatabase;
        $this->invalidator = $invalidator ?? new MasterCacheInvalidator;
        $this->store = $store ?? new MasterArtifactStore;
        $this->deployService = $deployService ?? new MasterDeployService;
        $this->selector = $selector ?? new MasterDeploySelector;
        $this->verifier = $verifier ?? new MasterArtifactVerifier;
    }

    // ---- 排他・実行記録 ----

    /**
     * ロックを取り、実行記録（kind）を running で作って $body を実行する。成功したら succeeded・done に直し、失敗したら failed にする
     *
     * $body は `['response' => 応答, 'run' => 実行記録に足す列]` を返す。`run_id` は応答に足す。
     *
     * @param  Closure(): array{response: array<string, mixed>, run?: array<string, mixed>}  $body
     * @return array<string, mixed>
     *
     * @throws MasterDeployRunException
     */
    protected function runLocked(string $kind, ?int $admAccountId, ?int $deployKey, Closure $body): array
    {
        try {
            $acquired = $this->lock->acquire();
        } catch (Throwable $e) {
            Log::error('マスターデプロイのロック取得でエラー', ['exception' => $e, 'kind' => $kind]);
            throw new MasterDeployRunException(MasterDeployRunException::INTERNAL_ERROR, 'ロックの取得でエラーが発生しました。ログの詳細を確認してください。', 500, previous: $e);
        }
        if (! $acquired) {
            // 実行記録は作らない（ロックが無く、古い running を判断できないため）
            throw $this->locked();
        }

        $this->resetState();
        try {
            $this->runId = $this->createRun($kind, $admAccountId, $deployKey);
            $out = $body();
            $this->updateRun([
                ...($out['run'] ?? []),
                'status' => 'succeeded',
                'phase' => 'done',
                'summary' => $this->encodeSummary(),
                'finished_at' => $this->nowString(),
            ], false);
            $out['response']['run_id'] = $this->runId;

            return $out['response'];
        } catch (MasterDeployRunException $e) {
            $this->failRun($e);
            throw $e->withRunId($this->runId);
        } catch (Throwable $e) {
            Log::error("マスターデプロイ（{$kind}）で想定外のエラー", ['exception' => $e, 'run_id' => $this->runId]);
            $error = new MasterDeployRunException(MasterDeployRunException::INTERNAL_ERROR, '想定外のエラーが発生しました。ログの詳細を確認してください。', 500, previous: $e);
            $this->failRun($error);
            throw $error->withRunId($this->runId);
        } finally {
            $this->cleanupTempDirs();
            $this->releaseLock();
        }
    }

    protected function locked(): MasterDeployRunException
    {
        return new MasterDeployRunException(MasterDeployRunException::DEPLOY_LOCKED, '他のデプロイが実行中です。しばらくしてからもう一度実行してください。', 409);
    }

    /**
     * 実行記録を作らずにロックだけ取って $body を実行する（dry_run）。一時の DB・作業ディレクトリは必ず片付ける
     *
     * @template T
     *
     * @param  Closure(): T  $body
     * @return T
     */
    protected function withLockNoRecord(Closure $body): mixed
    {
        try {
            $acquired = $this->lock->acquire();
        } catch (Throwable $e) {
            throw new MasterDeployRunException(MasterDeployRunException::INTERNAL_ERROR, 'ロックの取得でエラーが発生しました。ログの詳細を確認してください。', 500, previous: $e);
        }
        if (! $acquired) {
            throw $this->locked();
        }
        $this->resetState();
        try {
            return $body();
        } finally {
            foreach ($this->created as $name) {
                $this->dropCreated($name);
            }
            $this->cleanupTempDirs();
            $this->releaseLock();
        }
    }

    protected function resetState(): void
    {
        $this->created = [];
        $this->summary = [];
        $this->runId = null;
        $this->keepDatabases = false;
        $this->tempDirs = [];
    }

    private function releaseLock(): void
    {
        try {
            $this->lock->release();
        } catch (Throwable $e) {
            Log::error('マスターデプロイのロックの解放に失敗', ['exception' => $e]);
        }
    }

    protected function phase(string $phase): void
    {
        $this->updateRun(['phase' => $phase], true);
    }

    protected function nowString(): string
    {
        return now()->utc()->format('Y-m-d H:i:s');
    }

    protected function encodeSummary(): ?string
    {
        $summary = $this->summary;
        $summary['created_database_list'] = $this->created;
        try {
            return json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }

    private function createRun(string $kind, ?int $admAccountId, ?int $deployKey): int
    {
        $now = $this->nowString();
        // ロックを取れているので、残っている古い running はプロセスが落ちた跡として aborted に直す
        DB::connection('tool')->table('tol_master_deploy_run')->where('status', 'running')
            ->update(['status' => 'aborted', 'finished_at' => $now, 'updated_at' => $now]);

        return (int) DB::connection('tool')->table('tol_master_deploy_run')->insertGetId([
            'kind' => $kind,
            'deploy_key' => $deployKey,
            'status' => 'running',
            'phase' => 'lock',
            'adm_account_id' => $admAccountId,
            'app_env' => mb_substr((string) config('app.env'), 0, 32),
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * 実行記録を更新する。sys の COMMIT の前（$strict = true）は、書けなければ例外で中止する（db.md 5.2）。後（false）は握ってログに残す
     *
     * @param  array<string, mixed>  $changes
     */
    protected function updateRun(array $changes, bool $strict): void
    {
        if ($this->runId === null) {
            return;
        }
        try {
            DB::connection('tool')->table('tol_master_deploy_run')
                ->where('id', $this->runId)
                ->update([...$changes, 'updated_at' => $this->nowString()]);
        } catch (Throwable $e) {
            if ($strict) {
                throw $e;
            }
            Log::error('マスターデプロイの実行記録の更新に失敗（処理は続ける）', ['exception' => $e, 'run_id' => $this->runId, 'changes' => array_keys($changes)]);
        }
    }

    /**
     * 失敗の後始末: この run が作った DB だけを DROP し（COMMIT の成否が不明なときは残す）、失敗を実行記録に残す
     */
    private function failRun(MasterDeployRunException $e): void
    {
        if (! $this->keepDatabases) {
            foreach ($this->created as $name) {
                $this->dropCreated($name);
            }
        }
        if ($this->runId === null) {
            return;
        }
        foreach (['error_total', 'error_count_by_code', 'changed_deploy_key_list'] as $key) {
            if (isset($e->extra[$key])) {
                $this->summary[$key] = $e->extra[$key];
            }
        }
        $this->updateRun([
            'status' => 'failed',
            'error_code' => $e->errorCode,
            'error_message' => $e->getMessage(),
            'summary' => $this->encodeSummary(),
            'finished_at' => $this->nowString(),
        ], false);
    }

    protected function recordCreated(string $name): void
    {
        $this->created[] = $name;
        $this->updateRun(['summary' => $this->encodeSummary()], true);
    }

    /**
     * この run が作った DB を捨てて一覧から外す（失敗しても握る。残った名前は孤児掃除の対象）
     */
    protected function dropCreated(string $name): void
    {
        try {
            $this->databases->drop($name);
            $this->created = array_values(array_filter($this->created, static fn (string $n): bool => $n !== $name));
        } catch (Throwable $e) {
            Log::warning('この run が作った DB を DROP できませんでした（孤児掃除の対象）', ['exception' => $e, 'database' => $name]);
        }
    }

    /** 一覧から外すだけ（DROP しない。sys が指す DB になったものを、以後の失敗で消さないため。6.6 の手順 4） */
    protected function forgetCreated(string $name): void
    {
        $this->created = array_values(array_filter($this->created, static fn (string $n): bool => $n !== $name));
    }

    protected function fromKeyException(MasterDeployKeyException $e): MasterDeployRunException
    {
        return new MasterDeployRunException($e->errorCode, $e->getMessage(), $e->httpStatus, previous: $e);
    }

    // ---- 作業ディレクトリ ----

    protected function newTempDir(): string
    {
        $dir = sys_get_temp_dir().'/nexus-master-'.bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function cleanupTempDirs(): void
    {
        foreach ($this->tempDirs as $dir) {
            File::deleteDirectory($dir);
        }
        $this->tempDirs = [];
    }

    // ---- start_at（I6。db.md 3.6） ----

    /**
     * 明示された start_at の検査（DB を作る前）。null は「すぐ」（登録の直前の現在時刻）
     *
     * @return array{start_at: string|null, warning_list: list<array{code: string, message: string}>}
     *
     * @throws MasterDeployRunException start_at_past
     */
    protected function resolveStartAt(?string $input): array
    {
        if ($input === null) {
            return ['start_at' => null, 'warning_list' => []];
        }
        try {
            $startAt = Carbon::createFromFormat('Y-m-d H:i:s', $input, 'UTC');
        } catch (Throwable) {
            $startAt = null;
        }
        if ($startAt === null || $startAt->format('Y-m-d H:i:s') !== $input) {
            throw new MasterDeployRunException(MasterDeployRunException::START_AT_PAST, "start_at の形式が不正です（Y-m-d H:i:s の UTC）: {$input}", 422);
        }
        $now = now()->utc();
        $grace = (int) config('master_deploy.start_at_past_grace');
        $pastSeconds = $now->getTimestamp() - $startAt->getTimestamp();
        if ($pastSeconds > 0) {
            if ($pastSeconds > $grace && (bool) config('master_deploy.reject_past_start_at')) {
                throw new MasterDeployRunException(MasterDeployRunException::START_AT_PAST, "start_at が過去です（許容幅 {$grace} 秒を超えています）: {$input}", 422);
            }
            if ($pastSeconds <= $grace) {
                $startAt = $now->copy();
            }
        }
        $warningList = [];
        if ($startAt->getTimestamp() < $now->getTimestamp() + 2 * (int) config('master_deploy.schedule_ttl')) {
            $warningList[] = ['code' => 'start_at_too_soon', 'message' => 'start_at が近いため、他のサーバーへの反映が遅れる可能性があります（API の切り替え予定のキャッシュ TTL）'];
        }

        return ['start_at' => $startAt->format('Y-m-d H:i:s'), 'warning_list' => $warningList];
    }

    // ---- 版の DB（version_db。db.md 6.6） ----

    /**
     * 既存の master の版の DB が「使える」か（6.6 の 2。① mst_dropped_at が NULL ② DB が実在 ③ スキーマ版が基底と一致 ④ 往復検査）。
     * `$manifestTables` が null のときは ④ を省く（成果物が無い巻き戻し。仮置き）
     *
     * @param  list<array{name: string, row_count: int, data_sha256: string}>|null  $manifestTables
     */
    protected function isUsable(object $master, ?array $manifestTables, string $baseFingerprint): bool
    {
        $name = (string) $master->mst_database;

        return $master->mst_dropped_at === null
            && $this->databases->databaseExists($name)
            && $this->databases->schemaFingerprint($name) === $baseFingerprint
            && ($manifestTables === null || $this->databases->roundTripMatches($name, $manifestTables));
    }

    /**
     * 版の DB を新しく用意する（既存の使える DB が無いとき。6.6 の 3）
     *
     * - 同じ名前の DB が既にある → sys の行が無い、またはこの master の行なら、中身（スキーマ版・往復検査）が成果物と一致するときだけ採用
     *   （この run が作ったものではないので created に入れない）。違えば mst_database_collision（触らない）
     * - 無ければ作る（作る直前に実行記録へ名前を書く）。成果物の行データ（NDJSON）を投入して往復検査をかける（合わなければ load_failed）
     *
     * @param  array<string, mixed>  $manifest
     * @param  string  $localDir  成果物のローカルのコピー
     * @param  string|null  $temporaryName  dry run の一時の DB 名（作業用と同じ命名）。指定時は名前の衝突・採用の判定をせず、必ず新しく作る
     * @return array{name: string, adopted: bool}
     *
     * @throws MasterDeployRunException
     */
    protected function createVersionDatabase(array $manifest, string $localDir, ?object $existingMaster, ?string $temporaryName = null): array
    {
        $name = $temporaryName ?? $this->databases->versionName((int) $manifest['deploy_key'], (string) $manifest['hash']);
        /** @var list<array{name: string, row_count: int, data_sha256: string}> $tables */
        $tables = array_map(static fn (array $t): array => ['name' => (string) $t['name'], 'row_count' => (int) $t['row_count'], 'data_sha256' => (string) $t['data_sha256']], (array) $manifest['tables']);

        if ($temporaryName === null && $this->databases->databaseExists($name)) {
            $row = DB::connection('sys')->table('sys_deploy_master')->where('mst_database', $name)->first();
            $adoptable = ($row === null || ($existingMaster !== null && (int) $row->id === (int) $existingMaster->id))
                && $this->databases->schemaFingerprint($name) === (string) $manifest['schema_fingerprint']
                && $this->databases->roundTripMatches($name, $tables);
            if (! $adoptable) {
                throw new MasterDeployRunException(
                    MasterDeployRunException::MST_DATABASE_COLLISION,
                    "同じ名前の版の DB が既にあり、再利用も採用もできません（hash が違う・使えない旧 DB・中身が合わない孤児）: {$name}。手動で確認してください。",
                    500,
                );
            }

            return ['name' => $name, 'adopted' => true];
        }

        // 作る直前に名前を実行記録へ書く（異常終了で残った DB を孤児として追えるように。db.md 4.7）
        $this->updateRun(['mst_database' => $name], true);
        $this->recordCreated($name);
        try {
            $this->databases->createFromBase($name);
            $this->databases->loadNdjson($name, $localDir, $tables);
        } catch (Throwable $e) {
            Log::error('マスターの DB への投入に失敗', ['exception' => $e]);
            throw new MasterDeployRunException(MasterDeployRunException::LOAD_FAILED, 'DB への投入に失敗しました: '.MasterVersionDatabase::safeMessage($e), 500, previous: $e);
        }
        if (! $this->databases->roundTripMatches($name, $tables)) {
            throw new MasterDeployRunException(MasterDeployRunException::LOAD_FAILED, "版の DB に入れた行が成果物と一致しません（往復検査の不一致）: {$name}", 500);
        }

        return ['name' => $name, 'adopted' => false];
    }

    /**
     * 登録済みの master の版の DB を使える状態にする（rollback・activate。成果物が使えるなら復元する）
     *
     * DB が使える → そのまま。使えない → 自環境のストレージの成果物から復元する（先に成果物のスキーマ版が基底と一致するかを見る。I3 と同じ）。
     * 成果物が無い・復元できない → unavailable（`$unavailableCode`）。スキーマ版が合わない → `$schemaMismatchCode`。
     * 復元したときは **sys の更新はしない**（呼び出し側が登録の同じトランザクションで mst_database・mst_dropped_at を更新する）
     *
     * @param  bool  $dryRun  true のときは DB を作らず、復元が要る・できるかだけを調べる
     * @return array{restored: bool, mst_database: string, schema_fingerprint: string}
     *
     * @throws MasterDeployRunException
     */
    protected function ensureMasterUsable(object $master, string $unavailableCode, string $schemaMismatchCode, bool $dryRun): array
    {
        $baseFingerprint = $this->databases->schemaFingerprint($this->databases->baseDatabase());
        $manifest = $this->store->manifest((string) $master->artifact_dir);
        $tables = $manifest === null ? null : $this->manifestTables($manifest);
        if ($this->isUsable($master, $tables, $baseFingerprint)) {
            return ['restored' => false, 'mst_database' => (string) $master->mst_database, 'schema_fingerprint' => $baseFingerprint];
        }

        if ($manifest === null) {
            throw new MasterDeployRunException($unavailableCode, "版の DB が使えず、成果物（{$master->artifact_dir}）も無いため復元できません。", 422);
        }
        try {
            $verified = $this->verifier->verify($this->store->disk(), (string) $master->artifact_dir, (string) $master->artifact_dir, false);
        } catch (MasterDeployRunException $e) {
            throw new MasterDeployRunException($unavailableCode, '版の DB が使えず、成果物も検証に通らないため復元できません: '.$e->getMessage(), 422, previous: $e);
        }
        if ((string) $verified['schema_fingerprint'] !== $baseFingerprint) {
            throw new MasterDeployRunException(
                $schemaMismatchCode,
                'この版の成果物のスキーマ版が、自環境の基底 DB（現行のスキーマ）と一致しないため復元できません（db.md 4.8）。',
                $schemaMismatchCode === MasterDeployRunException::SCHEMA_MISMATCH ? 422 : 409,
            );
        }
        if ($dryRun) {
            return ['restored' => true, 'mst_database' => $this->databases->versionName((int) $verified['deploy_key'], (string) $verified['hash']), 'schema_fingerprint' => $baseFingerprint];
        }

        $this->phase('version_db');
        $localDir = $this->newTempDir();
        try {
            $this->store->pull((string) $master->artifact_dir, $localDir);
        } catch (Throwable $e) {
            throw new MasterDeployRunException($unavailableCode, '成果物の読み込みに失敗したため復元できません: '.MasterVersionDatabase::safeMessage($e), 422, previous: $e);
        }
        $created = $this->createVersionDatabase($verified, $localDir, $master);

        return ['restored' => true, 'mst_database' => $created['name'], 'schema_fingerprint' => $baseFingerprint];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array{name: string, row_count: int, data_sha256: string}>
     */
    protected function manifestTables(array $manifest): array
    {
        return array_map(static fn (array $t): array => ['name' => (string) $t['name'], 'row_count' => (int) $t['row_count'], 'data_sha256' => (string) $t['data_sha256']], (array) ($manifest['tables'] ?? []));
    }

    // ---- 登録（sys の 1 トランザクション。COMMIT の成否が不明のときの扱いは db.md 4.7） ----

    /**
     * 登録する。COMMIT 処理中のエラーは、sys を読み直して登録済みなら成功（警告 register_confirmed_after_error）、行が無ければ register_failed
     * （DB は DROP せず孤児掃除に回す）、sys を読めなければ register_unknown
     *
     * @param  array<string, mixed>  $input  MasterDeployService::registerBuild の入力
     * @return array{sys_deploy_master_id: int, sys_deploy_id: int, deploy_key: int, hash: string, mst_database: string, start_at: string, is_new: bool, warning_list: list<array{code: string, message: string}>}
     *
     * @throws MasterDeployRunException
     */
    protected function registerSys(array $input): array
    {
        try {
            return $this->deployService->registerBuild($input);
        } catch (MasterDeployKeyException $e) {
            if ($e->errorCode !== MasterDeployKeyException::REGISTER_COMMIT_UNKNOWN) {
                throw $this->fromKeyException($e);
            }
            $this->keepDatabases = true;
            $registration = $this->resolveUnknownCommit($e, (string) $input['mst_database'], (string) $input['start_at']);
            $registration['warning_list'][] = ['code' => 'register_confirmed_after_error', 'message' => 'sys の COMMIT 中にエラーがありましたが、読み直したところ登録されていました'];

            return $registration;
        }
    }

    /**
     * @return array{sys_deploy_master_id: int, sys_deploy_id: int, deploy_key: int, hash: string, mst_database: string, start_at: string, is_new: bool, warning_list: list<array{code: string, message: string}>}
     *
     * @throws MasterDeployRunException
     */
    private function resolveUnknownCommit(MasterDeployKeyException $cause, string $database, string $startAt): array
    {
        Log::error('sys の COMMIT 中にエラー（登録されたか確認する）', ['exception' => $cause, 'run_id' => $this->runId]);
        try {
            $sys = DB::connection('sys');
            $master = $sys->table('sys_deploy_master')->where('mst_database', $database)->first();
            $deploy = null;
            if ($master !== null) {
                $deploy = $sys->table('sys_deploy')->where('sys_deploy_master_id', $master->id)->where('start_at', $startAt)->orderByDesc('id')->first();
            }
        } catch (Throwable $e) {
            throw new MasterDeployRunException(
                MasterDeployRunException::REGISTER_UNKNOWN,
                'sys の COMMIT 中にエラーが発生し、sys も読めないため、登録されたか分かりません。DB は削除していません。人が確認してください。',
                500,
                previous: $e,
            );
        }
        if ($master === null || $deploy === null) {
            throw new MasterDeployRunException(
                MasterDeployRunException::REGISTER_FAILED,
                'sys への登録に失敗しました（COMMIT 中のエラー。登録は確認できませんでした）。版の DB は孤児として残り、掃除の対象になります。',
                500,
                previous: $cause,
            );
        }

        return [
            'sys_deploy_master_id' => (int) $master->id,
            'sys_deploy_id' => (int) $deploy->id,
            'deploy_key' => (int) $master->deploy_key,
            'hash' => (string) $master->hash,
            'mst_database' => (string) $master->mst_database,
            'start_at' => $startAt,
            'is_new' => false,
            'warning_list' => [],
        ];
    }

    /**
     * @return array{invalidated: bool}
     */
    protected function forgetSchedule(): array
    {
        try {
            return ['invalidated' => $this->invalidator->forgetSchedule()['invalidated']];
        } catch (Throwable $e) {
            Log::error('schedule キャッシュの破棄に失敗', ['exception' => $e]);

            return ['invalidated' => false];
        }
    }

    /**
     * @return array{code: string, message: string}
     */
    protected function cacheWarning(): array
    {
        return ['code' => 'schedule_cache_not_invalidated', 'message' => 'API の切り替え予定のキャッシュを破棄できませんでした。同じ操作をもう一度実行する（破棄だけ行われる）か、clear-cache を実行してください'];
    }
}

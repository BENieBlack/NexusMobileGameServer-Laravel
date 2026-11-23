<?php

namespace App\Services;

use App\Exceptions\MasterDeployKeyException;
use App\Exceptions\MasterDeployRunException;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * build（develop・LD・QA）の順序・排他・実行記録（版ごとの mst DB 設計。画面・CLI から呼ぶ）
 *
 * 設計: docs/design/master-distribution/db.md 6.6（build）・4.2〜4.7・5 節、api.md 6 節・13 節
 * 旧 MasterDeployRunner（TRUNCATE + 差分反映 + 補償）は S3-15・S3-16 まで残る。この Runner は新しい経路で、画面・CLI の接続は後の段階。
 *
 * 順序: 環境設定の検査（role・T・本番ガード・DB 名。ロックの前）→ GET_LOCK（sys。待たない）→ 実行記録 `kind = build` を running で作る（lock）
 * → start_at の検査（I6。DB を作る前）→ 読む（read）→ 検証（validate）→ 畳み（fold）→ 作業用 DB（work_db）→ 成果物（artifact。hash 確定）
 * → 版の DB を確定（version_db。既存を再利用・孤児を採用・新規に作る）→ 成果物を置く（store。manifest.json を最後に）
 * → sys に 1 トランザクションで登録（register）→ schedule キーの破棄 → succeeded（done）。最後にロックを必ず解放する。
 *
 * 失敗したときは、この run が**新規に作った** DB（作業用 DB・版の DB）だけを `DROP` する（4.7。ベストエフォート）。既存の版の DB・採用した DB は
 * `created_database_list` に入れないので消さない。sys の COMMIT の成否が不明（register の COMMIT 中のエラー）のときは版の DB を `DROP` しない
 * （読み直して登録済みなら成功＝警告 register_confirmed_after_error、行が無ければ register_failed で閉じて孤児掃除に回す、sys を読めなければ register_unknown）。
 *
 * dry_run はロック・実行記録・DB・ストレージ・sys・キャッシュを一切使わない（読み込み・検証・畳み・棚卸し・start_at の検査まで）。
 */
class MasterBuildRunner
{
    private readonly MasterDeployKeyPolicy $policy;

    private readonly MasterDeployLock $lock;

    private readonly MasterSheetValidator $validator;

    private readonly MasterImportService $importService;

    private readonly MasterVersionDatabase $databases;

    private readonly MasterArtifactBuilder $builder;

    private readonly MasterArtifactStore $store;

    private readonly MasterDeployService $deployService;

    private readonly MasterCacheInvalidator $invalidator;

    /** @var Closure(): GoogleSpreadsheetService */
    private readonly Closure $sheetServiceFactory;

    /** @var list<string> この run が新規に作った DB 名（失敗時の DROP の対象。db.md 4.7） */
    private array $created = [];

    /** @var array<string, mixed> 実行記録の summary */
    private array $summary = [];

    /**
     * @param  Closure(): GoogleSpreadsheetService|null  $sheetServiceFactory  Google の認証情報が無いとコンストラクタが例外を投げるので、使うときに作る
     */
    public function __construct(
        ?MasterDeployKeyPolicy $policy = null,
        ?MasterDeployLock $lock = null,
        ?Closure $sheetServiceFactory = null,
        ?MasterSheetValidator $validator = null,
        ?MasterImportService $importService = null,
        ?MasterVersionDatabase $databases = null,
        ?MasterArtifactBuilder $builder = null,
        ?MasterArtifactStore $store = null,
        ?MasterDeployService $deployService = null,
        ?MasterCacheInvalidator $invalidator = null,
    ) {
        $this->policy = $policy ?? MasterDeployKeyPolicy::fromConfig();
        $this->lock = $lock ?? new MasterDeployLock;
        $this->sheetServiceFactory = $sheetServiceFactory ?? fn (): GoogleSpreadsheetService => new GoogleSpreadsheetService;
        $this->validator = $validator ?? new MasterSheetValidator($this->policy);
        $this->importService = $importService ?? new MasterImportService;
        $this->databases = $databases ?? new MasterVersionDatabase($this->importService);
        $this->builder = $builder ?? new MasterArtifactBuilder($this->databases, new MasterDataExporter);
        $this->store = $store ?? new MasterArtifactStore;
        $this->deployService = $deployService ?? new MasterDeployService($this->policy);
        $this->invalidator = $invalidator ?? new MasterCacheInvalidator;
    }

    /**
     * build を 1 回行う
     *
     * @param  array{dry_run?: bool, adm_account_id?: int|null, start_at?: string|null}  $options  start_at は UTC の 'Y-m-d H:i:s'（null は「すぐ」）
     * @return array<string, mixed> api.md 6 節の成功のレスポンス
     *
     * @throws MasterDeployRunException
     */
    public function run(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $admAccountId = isset($options['adm_account_id']) ? (int) $options['adm_account_id'] : null;
        $startAtInput = isset($options['start_at']) && $options['start_at'] !== '' ? (string) $options['start_at'] : null;

        // 環境設定の検査（排他の前。db.md 6.6 の build の 1）
        if (config('master_deploy.role') !== 'build') {
            throw new MasterDeployRunException(
                MasterDeployRunException::ROLE_FORBIDDEN,
                'この環境（role='.(string) config('master_deploy.role').'）では build はできません（build の環境だけ）。',
                403,
            );
        }
        try {
            $target = $this->policy->resolveBuildKey();
        } catch (MasterDeployKeyException $e) {
            throw $this->fromKeyException($e);
        }
        $this->databases->assertConfiguration();

        return $dryRun
            ? $this->runDry($target, $startAtInput)
            : $this->runReal($target, $admAccountId, $startAtInput);
    }

    /**
     * @param  array{mode: 'fixed'|'explicit', deploy_key: int}  $target
     * @return array<string, mixed>
     */
    private function runDry(array $target, ?string $startAtInput): array
    {
        $start = $this->resolveStartAt($startAtInput);
        $prepared = $this->prepare($target['deploy_key'], true, null);

        $warningList = [...$start['warning_list'], ...$prepared['validation']['warning_list']];
        foreach ($prepared['validation']['future_row_count'] as $table => $count) {
            $warningList[] = [
                'sheet' => '', 'table' => $table, 'row' => null, 'column' => 'deploy_key', 'code' => 'future_deploy_key_row',
                'message' => "{$table} に deploy_key が {$target['deploy_key']} より大きい行が {$count} 件あります（今回は効きません）",
            ];
        }

        return [
            'status' => 'success',
            'message' => "dry_run: 検証と畳みだけを行いました（deploy_key={$target['deploy_key']}）。",
            'dry_run' => true,
            'run_id' => null,
            'deploy' => [
                'mode' => $target['mode'],
                'deploy_key' => $target['deploy_key'],
                'hash' => null,
                'artifact_dir' => null,
                'mst_database' => null,
                'sys_deploy_master_id' => null,
                'sys_deploy_id' => null,
                'start_at' => $start['start_at'],
                'is_new' => null,
                'database_reused' => null,
            ],
            'build' => $this->buildResult($prepared, [], $warningList),
            'artifact' => null,
            'cache' => ['invalidated' => null],
            'inventory' => $prepared['inventory'],
        ];
    }

    /**
     * @param  array{mode: 'fixed'|'explicit', deploy_key: int}  $target
     * @return array<string, mixed>
     */
    private function runReal(array $target, ?int $admAccountId, ?string $startAtInput): array
    {
        try {
            $acquired = $this->lock->acquire();
        } catch (Throwable $e) {
            Log::error('マスター build のロック取得でエラー', ['exception' => $e]);
            throw new MasterDeployRunException(
                MasterDeployRunException::INTERNAL_ERROR,
                'ロックの取得でエラーが発生しました。ログの詳細を確認してください。',
                500,
                previous: $e,
            );
        }
        if (! $acquired) {
            // 実行記録は作らない（ロックが無く、古い running を判断できないため）
            throw new MasterDeployRunException(
                MasterDeployRunException::DEPLOY_LOCKED,
                '他のデプロイが実行中です。しばらくしてからもう一度実行してください。',
                409,
            );
        }

        $this->created = [];
        $this->summary = [];
        /** @var int|null $runId run の作成に失敗したときは null のまま catch に入る */
        $runId = null;
        $localDir = null;
        $keepVersionDatabase = false;
        $deployKey = $target['deploy_key'];
        try {
            $runId = $this->createRun($target, $admAccountId);

            // I6: start_at の検査（DB を作る前）
            $start = $this->resolveStartAt($startAtInput);
            $warningList = $start['warning_list'];

            $prepared = $this->prepare($deployKey, false, $runId);

            // work_db: 作業用 DB を作って採用行を入れる。この run が作った DB なので、失敗しても DROP してよい
            $this->updateRun($runId, ['phase' => 'work_db'], true);
            $work = $this->databases->workName();
            $this->recordCreated($runId, $work, 'work_database');
            $this->databases->createFromBase($work);
            try {
                $loaded = $this->databases->loadFolded($work, $prepared['folded']);
            } catch (Throwable $e) {
                throw $this->loadFailed($e);
            }
            $this->summary['loaded'] = $loaded;

            // artifact: 作業用 DB から成果物をローカルの作業ディレクトリに作る（hash の確定。まだストレージに置かない）
            $this->updateRun($runId, ['phase' => 'artifact'], true);
            $localDir = sys_get_temp_dir().'/nexus-artifact-'.bin2hex(random_bytes(6));
            try {
                $built = $this->builder->build($work, $deployKey, $localDir, $this->buildDeployKeyLookup($prepared['folded']), [
                    'built_env' => (string) config('app.env'),
                    'built_at' => now()->utc()->format('Y-m-d H:i:s'),
                    'source_kind' => 'build',
                ]);
            } catch (JsonException $e) {
                throw $this->loadFailed($e);
            } catch (Throwable $e) {
                Log::error('マスターの成果物の書き出しに失敗', ['exception' => $e]);
                throw new MasterDeployRunException(
                    MasterDeployRunException::EXPORT_FAILED,
                    '成果物（行データ・配信 SQLite・schema.json・マニフェスト）の書き出しに失敗しました: '.MasterVersionDatabase::safeMessage($e),
                    500,
                    previous: $e,
                );
            }
            $this->summary['row_digest'] = $built['row_digest_list'];
            $this->updateRun($runId, [
                'master_hash' => $built['hash'],
                'artifact_dir' => $built['artifact_dir'],
                'schema_fingerprint' => $built['schema_fingerprint'],
                'summary' => $this->encodeSummary(),
            ], true);

            // version_db: 版の DB を確定する。作業用 DB はここで捨てる（この run が作った）
            $this->updateRun($runId, ['phase' => 'version_db'], true);
            $version = $this->ensureVersionDatabase($runId, $deployKey, $built, $work);
            $this->dropCreated($work);
            $this->updateRun($runId, ['mst_database' => $version['name'], 'summary' => $this->encodeSummary()], true);

            // store: 成果物をストレージに置く（manifest.json は最後。同じディレクトリに完成した成果物があれば作り直さず既存を使う）
            $this->updateRun($runId, ['phase' => 'store'], true);
            $artifact = $this->storeArtifact($built, $localDir);

            // register: sys に 1 トランザクションで登録する
            $this->updateRun($runId, ['phase' => 'register'], true);
            $startAt = $start['start_at'] ?? now()->utc()->format('Y-m-d H:i:s');
            try {
                $registration = $this->deployService->registerBuild([
                    'deploy_key' => $deployKey,
                    'hash' => $built['hash'],
                    'artifact_dir' => $built['artifact_dir'],
                    'schema_fingerprint' => $built['schema_fingerprint'],
                    'mst_database' => $version['name'],
                    'mode' => $target['mode'],
                    'restored' => $version['restored'],
                    'start_at' => $startAt,
                    'start_at_explicit' => $start['start_at'] !== null,
                    'table_count' => count($built['data_table_list']),
                    'file_size' => array_sum(array_column($artifact['tables'], 'file_size')),
                    'tables' => $artifact['tables'],
                ]);
            } catch (MasterDeployKeyException $e) {
                if ($e->errorCode !== MasterDeployKeyException::REGISTER_COMMIT_UNKNOWN) {
                    throw $this->fromKeyException($e);
                }
                // COMMIT の成否が不明。以後、版の DB は DROP しない（登録済みの DB を消すと Unknown database になるため）
                $keepVersionDatabase = true;
                $registration = $this->resolveUnknownCommit($runId, $e, $version['name'], $built, $startAt);
                $warningList[] = ['code' => 'register_confirmed_after_error', 'message' => 'sys の COMMIT 中にエラーがありましたが、読み直したところ登録されていました'];
            }
            $warningList = [...$warningList, ...$registration['warning_list']];

            // ここから先は sys も確定済み。キャッシュの破棄の失敗は成功のまま警告にする
            $cache = $this->forgetSchedule();
            if (! $cache['invalidated']) {
                $warningList[] = ['code' => 'schedule_cache_not_invalidated', 'message' => 'API の切り替え予定のキャッシュを破棄できませんでした。再試行するか clear-cache を実行してください'];
            }
            $this->summary['warning_list'] = $warningList;
            $this->summary['cache_invalidated'] = $cache['invalidated'];
            $this->summary['database_reused'] = $version['reused'];
            $this->updateRun($runId, [
                'status' => 'succeeded',
                'phase' => 'done',
                'deploy_key' => $deployKey,
                'start_at' => $registration['start_at'],
                'sys_deploy_master_id' => $registration['sys_deploy_master_id'],
                'sys_deploy_id' => $registration['sys_deploy_id'],
                'is_new' => $registration['is_new'],
                'mst_database' => $registration['mst_database'],
                'summary' => $this->encodeSummary(),
                'finished_at' => $this->nowString(),
            ], false);

            return [
                'status' => 'success',
                'message' => $registration['is_new']
                    ? "新しい版を登録しました（deploy_key={$deployKey}）。"
                    : "同じ内容の版が既にあるため、既存の版を再利用して新しい切り替え行を足しました（deploy_key={$deployKey}）。",
                'dry_run' => false,
                'run_id' => $runId,
                'deploy' => [
                    'mode' => $target['mode'],
                    'deploy_key' => $deployKey,
                    'hash' => $built['hash'],
                    'artifact_dir' => $built['artifact_dir'],
                    'mst_database' => $registration['mst_database'],
                    'sys_deploy_master_id' => $registration['sys_deploy_master_id'],
                    'sys_deploy_id' => $registration['sys_deploy_id'],
                    'start_at' => $registration['start_at'],
                    'is_new' => $registration['is_new'],
                    'database_reused' => $version['reused'],
                ],
                'build' => $this->buildResult($prepared, $built['row_digest_list'], $warningList),
                'artifact' => [
                    'hash' => $built['hash'],
                    'artifact_dir' => $built['artifact_dir'],
                    'file_size' => array_sum(array_column($artifact['tables'], 'file_size')),
                    'table_count' => count($built['data_table_list']),
                    'file_count' => count($artifact['tables']),
                    'reused' => $artifact['reused'],
                    'tables' => $artifact['tables'],
                ],
                'cache' => ['invalidated' => $cache['invalidated']],
            ];
        } catch (MasterDeployRunException $e) {
            $this->failRun($runId, $e, $keepVersionDatabase);
            throw $e->withRunId($runId);
        } catch (Throwable $e) {
            Log::error('マスター build で想定外のエラー', ['exception' => $e, 'run_id' => $runId]);
            $error = new MasterDeployRunException(
                MasterDeployRunException::INTERNAL_ERROR,
                '想定外のエラーが発生しました。ログの詳細を確認してください。',
                500,
                previous: $e,
            );
            $this->failRun($runId, $error, $keepVersionDatabase);
            throw $error->withRunId($runId);
        } finally {
            if ($localDir !== null) {
                File::deleteDirectory($localDir);
            }
            try {
                $this->lock->release();
            } catch (Throwable $e) {
                Log::error('マスター build のロックの解放に失敗', ['exception' => $e]);
            }
        }
    }

    // ---- start_at（I6。db.md 3.6） ----

    /**
     * 明示された start_at の検査（DB を作る前）。null は「すぐ」（登録の直前の現在時刻）
     *
     * - 許容幅 `start_at_past_grace` 以内の過去は現在に丸めて通す。それを超える過去は `reject_past_start_at`（production だけ true）なら拒否
     * - 現在 + 2 × `schedule_ttl` より近いときは警告 start_at_too_soon（止めない）
     *
     * @return array{start_at: string|null, warning_list: list<array{code: string, message: string}>}
     *
     * @throws MasterDeployRunException start_at_past
     */
    private function resolveStartAt(?string $input): array
    {
        if ($input === null) {
            return ['start_at' => null, 'warning_list' => []];
        }
        try {
            $startAt = Carbon::createFromFormat('Y-m-d H:i:s', $input, 'UTC');
        } catch (Throwable) {
            $startAt = null;
        }
        if ($startAt === null) {
            throw new MasterDeployRunException(MasterDeployRunException::START_AT_PAST, "start_at の形式が不正です（Y-m-d H:i:s の UTC）: {$input}", 422);
        }
        $now = now()->utc();
        $warningList = [];
        $grace = (int) config('master_deploy.start_at_past_grace');
        $pastSeconds = $now->getTimestamp() - $startAt->getTimestamp();
        if ($pastSeconds > 0) {
            if ($pastSeconds > $grace && (bool) config('master_deploy.reject_past_start_at')) {
                throw new MasterDeployRunException(
                    MasterDeployRunException::START_AT_PAST,
                    "start_at が過去です（許容幅 {$grace} 秒を超えています）: {$input}",
                    422,
                );
            }
            if ($pastSeconds <= $grace) {
                $startAt = $now->copy();
            }
        }
        if ($startAt->getTimestamp() < $now->getTimestamp() + 2 * (int) config('master_deploy.schedule_ttl')) {
            $warningList[] = ['code' => 'start_at_too_soon', 'message' => 'start_at が近いため、他のサーバーへの反映が遅れる可能性があります（API の切り替え予定のキャッシュ TTL）'];
        }

        return ['start_at' => $startAt->format('Y-m-d H:i:s'), 'warning_list' => $warningList];
    }

    // ---- 読む → 検証 → 畳み（版の DB も sys も変えない） ----

    /**
     * @return array{
     *   assembled: array<string, mixed>,
     *   folded: array<string, array{adopted: array<int, array<string, mixed>>, adopted_index_list: array<int, int>, input_rows: int, replaced: bool}>,
     *   validation: array<string, mixed>,
     *   inventory: array<string, mixed>|null
     * }
     */
    private function prepare(int $deployKey, bool $dryRun, ?int $runId): array
    {
        if ($runId !== null) {
            $this->updateRun($runId, ['phase' => 'read'], true);
        }
        $schema = $this->loadMstSchema();
        $assembled = $this->readSheets($schema['columns']);

        if ($runId !== null) {
            $this->updateRun($runId, ['phase' => 'validate'], true);
        }
        /** @var array{tables: array<string, array{entries: array<int, array<string, mixed>>, has_deploy_key_column: bool}>, error_list?: array<int, array{code: string, sheet: string, message: string}>} $assembled */
        $validation = $this->validator->validate($assembled, $deployKey, $schema['columns'], $schema['rarity'], $dryRun);
        $inventory = $dryRun ? MasterDeployInventory::build($assembled['tables'], (int) config('master_deploy.reserved_floor')) : null;
        if ($validation['error_total'] > 0) {
            $extra = ['error_total' => $validation['error_total'], 'error_count_by_code' => $validation['error_count_by_code']];
            if ($inventory !== null) {
                $extra['inventory'] = $inventory;
            }

            throw new MasterDeployRunException(
                MasterDeployRunException::SHEET_INVALID,
                "シートの検証で {$validation['error_total']} 件の問題が見つかりました（先頭 ".count($validation['error_list']).' 件を表示）。',
                422,
                $validation['error_list'],
                null,
                $extra,
            );
        }

        if ($runId !== null) {
            $this->updateRun($runId, ['phase' => 'fold'], true);
        }

        return [
            'assembled' => $assembled,
            'folded' => MasterOverlayFolder::fold($assembled['tables'], $deployKey),
            'validation' => $validation,
            'inventory' => $inventory,
        ];
    }

    /**
     * mst の表ごとの列名と rarity 列の型（検証 V02・V09・V10 用。基底 DB から読む）
     *
     * @return array{columns: array<string, array<int, string>>, rarity: array<string, array<string, array{kind: 'enum', values: array<int, string>}|array{kind: 'int'}>>}
     */
    protected function loadMstSchema(): array
    {
        $columns = [];
        $rows = DB::connection('mst')->select(
            "SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'mst\\_%' ORDER BY TABLE_NAME, ORDINAL_POSITION"
        );
        foreach ($rows as $row) {
            $columns[(string) $row->table_name][] = (string) $row->column_name;
        }

        $rarity = [];
        foreach ($columns as $table => $columnList) {
            if (array_intersect($columnList, ['rarity', 'bonus_rarity']) === []) {
                continue;
            }
            $rarity[$table] = $this->importService->rarityColumnsOf($table, $columnList);
        }

        return ['columns' => $columns, 'rarity' => $rarity];
    }

    /**
     * @param  array<string, array<int, string>>  $knownColumns
     * @return array<string, mixed>
     */
    private function readSheets(array $knownColumns): array
    {
        try {
            $service = ($this->sheetServiceFactory)();
            $configured = $service->isConfigured();
        } catch (Throwable $e) {
            $configured = false;
            $service = null;
            Log::warning('Google スプレッドシートの設定が不正', ['exception' => $e]);
        }
        if ($service === null || ! $configured) {
            throw new MasterDeployRunException(
                MasterDeployRunException::SHEET_NOT_CONFIGURED,
                'Google スプレッドシートのディレクトリ・認証情報が設定されていません（TOL_GOOGLE_SPREADSHEET_DIR と TOL_GOOGLE_SERVICE_ACCOUNT_JSON(_CONTENT)）。',
                422,
            );
        }

        try {
            return $service->readAllMasterTabs($knownColumns);
        } catch (Throwable $e) {
            Log::error('Google スプレッドシートの読み込みに失敗', ['exception' => $e]);
            throw new MasterDeployRunException(
                MasterDeployRunException::SHEET_READ_FAILED,
                'Google スプレッドシートの読み込みに失敗しました（再試行後）。しばらくしてからもう一度実行してください。',
                502,
                previous: $e,
            );
        }
    }

    /**
     * row_digest 用: deploy_key 列を持たない表のために、採用行の id => 元の deploy_key を渡す
     *
     * @param  array<string, array{adopted: array<int, array<string, mixed>>}>  $folded
     * @return array<string, array<string, string>>
     */
    private function buildDeployKeyLookup(array $folded): array
    {
        $lookup = [];
        foreach ($folded as $table => $fold) {
            if (str_ends_with($table, '__l10n')) {
                continue;
            }
            foreach ($fold['adopted'] as $entry) {
                $lookup[$table][(string) $entry['id']] = (string) ($entry['deploy_key'] ?? '');
            }
        }

        return $lookup;
    }

    /**
     * @param  array{assembled: array<string, mixed>, folded: array<string, array{adopted: array<int, array<string, mixed>>, input_rows: int}>}  $prepared
     * @param  list<array{deploy_key: int, digest: string}>  $rowDigestList
     * @param  array<int, array<string, mixed>>  $warningList
     * @return array<string, mixed>
     */
    private function buildResult(array $prepared, array $rowDigestList, array $warningList): array
    {
        $tableList = [];
        foreach ($prepared['folded'] as $table => $fold) {
            $tableList[] = [
                'table' => $table,
                'input_rows' => $fold['input_rows'],
                'ignored_inactive_rows' => (int) ($prepared['assembled']['tables'][$table]['ignored_inactive_rows'] ?? 0),
                'adopted_rows' => count($fold['adopted']),
                'empty' => $fold['adopted'] === [],
            ];
        }

        return [
            'table_list' => $tableList,
            'ignored_tab_list' => $prepared['assembled']['ignored_tab_list'] ?? [],
            'ignored_column_list' => $prepared['assembled']['ignored_column_list'] ?? [],
            'row_digest_list' => $rowDigestList,
            'warning_list' => $warningList,
        ];
    }

    // ---- 版の DB（version_db。db.md 6.6） ----

    /**
     * 登録してよい版の DB がある状態にする
     *
     * 1. 既存の master があり、その DB が使える（dropped でない・実在・スキーマ版が基底と一致・往復検査が合う）→ 新しく作らず使う（R1）
     * 2. 同じ名前の DB が既にある → sys に行があれば mst_database_collision（hash が違う・使えない旧 DB は手動で確認）。行が無い孤児は
     *    中身（スキーマ版・往復検査）が成果物と一致すれば採用（この run が作ったものではないので created に入れない）、違えば collision
     * 3. 上以外 → 新しく作る（作る直前に実行記録へ名前を書く）。作業用 DB から INSERT SELECT し、往復検査をかける
     *
     * @param  array{hash: string, schema_fingerprint: string, data_table_list: list<array{name: string, row_count: int, data_sha256: string}>}  $built
     * @return array{name: string, reused: bool, restored: bool}
     */
    private function ensureVersionDatabase(int $runId, int $deployKey, array $built, string $work): array
    {
        $baseFingerprint = $this->databases->schemaFingerprint($this->databases->baseDatabase());
        $existing = DB::connection('sys')->table('sys_deploy_master')->where('deploy_key', $deployKey)->where('hash', $built['hash'])->first();

        if ($existing !== null) {
            $usable = $existing->mst_dropped_at === null
                && $this->databases->databaseExists((string) $existing->mst_database)
                && $this->databases->schemaFingerprint((string) $existing->mst_database) === $baseFingerprint
                && $this->databases->roundTripMatches((string) $existing->mst_database, $built['data_table_list']);
            if ($usable) {
                return ['name' => (string) $existing->mst_database, 'reused' => true, 'restored' => false];
            }
        }
        $restored = $existing !== null;

        $name = $this->databases->versionName($deployKey, $built['hash']);
        if ($this->databases->databaseExists($name)) {
            $row = DB::connection('sys')->table('sys_deploy_master')->where('mst_database', $name)->first();
            $adoptable = $row === null
                && $this->databases->schemaFingerprint($name) === $built['schema_fingerprint']
                && $this->databases->roundTripMatches($name, $built['data_table_list']);
            if (! $adoptable) {
                throw new MasterDeployRunException(
                    MasterDeployRunException::MST_DATABASE_COLLISION,
                    "同じ名前の版の DB が既にあり、再利用も採用もできません（hash が違う・使えない旧 DB・中身が合わない孤児）: {$name}。手動で確認してください。",
                    500,
                );
            }

            return ['name' => $name, 'reused' => true, 'restored' => $restored];
        }

        // 作る直前に名前を実行記録へ書く（異常終了で残った DB を孤児として追えるように。db.md 4.7）
        $this->updateRun($runId, ['mst_database' => $name], true);
        $this->recordCreated($runId, $name, null);
        try {
            $this->databases->createFromBase($name);
            $this->databases->copyRows($work, $name);
        } catch (Throwable $e) {
            throw $this->loadFailed($e);
        }
        if (! $this->databases->roundTripMatches($name, $built['data_table_list'])) {
            throw new MasterDeployRunException(
                MasterDeployRunException::LOAD_FAILED,
                "版の DB に入れた行が成果物と一致しません（往復検査の不一致）: {$name}",
                500,
            );
        }

        return ['name' => $name, 'reused' => false, 'restored' => $restored];
    }

    /**
     * 成果物をストレージに置く。同じ `{deploy_key}_{hash16}` に完成した成果物（manifest.json）があれば、hash を比べて
     * 同じなら作り直さず既存を使い（配信 SQLite のバイトが違っても既存が正。db.md 6.5.2）、違えば artifact_dir_collision で止める
     *
     * @param  array{artifact_dir: string, hash: string, tables: array<string, array{hash: string, file_name: string, file_size: int, public_url: string}>}  $built
     * @return array{reused: bool, tables: array<string, array{hash: string, file_name: string, file_size: int, public_url: string}>}
     */
    private function storeArtifact(array $built, string $localDir): array
    {
        try {
            $existing = $this->store->manifest($built['artifact_dir']);
            if ($existing === null) {
                $this->store->put($localDir, $built['artifact_dir']);

                return ['reused' => false, 'tables' => $built['tables']];
            }
        } catch (Throwable $e) {
            Log::error('マスターの成果物の配置に失敗', ['exception' => $e]);
            throw new MasterDeployRunException(
                MasterDeployRunException::STORE_FAILED,
                '成果物のストレージへの配置に失敗しました: '.MasterVersionDatabase::safeMessage($e),
                500,
                previous: $e,
            );
        }

        if (($existing['hash'] ?? null) !== $built['hash']) {
            throw new MasterDeployRunException(
                MasterDeployRunException::ARTIFACT_DIR_COLLISION,
                "同じ名前の成果物ディレクトリが既にあり、完全な hash が違います（先頭 16 文字だけが同じ）: {$built['artifact_dir']}",
                500,
            );
        }
        $tables = [];
        foreach ((array) ($existing['group_list'] ?? []) as $group) {
            $tables[(string) $group['group']] = [
                'hash' => (string) $group['sqlite_sha256'],
                'file_name' => (string) $group['file_name'],
                'file_size' => (int) $group['file_size'],
                'public_url' => '/masterdata/'.$built['artifact_dir'].'/'.$group['file_name'],
            ];
        }

        return ['reused' => true, 'tables' => $tables];
    }

    // ---- 登録の COMMIT が成否不明のとき（db.md 4.7） ----

    /**
     * sys を読み直して登録されたかを確かめる
     *
     * - 登録済み（同じ mst_database の master と、同じ master・start_at の sys_deploy がある）→ 登録結果を返す（呼び出し側が成功にする）
     * - 行が無い → register_failed で閉じる（版の DB は DROP せず孤児掃除に回す）
     * - sys を読めない → register_unknown（DROP しない。人が確認する）
     *
     * @param  array{hash: string}  $built
     * @return array{sys_deploy_master_id: int, sys_deploy_id: int, mst_database: string, start_at: string, is_new: bool, warning_list: list<array{code: string, message: string}>}
     *
     * @throws MasterDeployRunException
     */
    private function resolveUnknownCommit(int $runId, MasterDeployKeyException $cause, string $database, array $built, string $startAt): array
    {
        Log::error('sys の COMMIT 中にエラー（登録されたか確認する）', ['exception' => $cause, 'run_id' => $runId]);
        try {
            $master = DB::connection('sys')->table('sys_deploy_master')->where('mst_database', $database)->first();
            $deploy = $master === null ? null : DB::connection('sys')->table('sys_deploy')
                ->where('sys_deploy_master_id', $master->id)->where('start_at', $startAt)->orderByDesc('id')->first();
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
            'mst_database' => (string) $master->mst_database,
            'start_at' => $startAt,
            'is_new' => false,
            'warning_list' => [],
        ];
    }

    /**
     * @return array{invalidated: bool}
     */
    private function forgetSchedule(): array
    {
        try {
            $result = $this->invalidator->forgetSchedule();

            return ['invalidated' => $result['invalidated']];
        } catch (Throwable $e) {
            Log::error('schedule キャッシュの破棄に失敗', ['exception' => $e]);

            return ['invalidated' => false];
        }
    }

    // ---- この run が作った DB（失敗時の DROP の対象。db.md 4.7） ----

    /**
     * 作る直前に名前を記録する（summary.created_database_list。work は summary.work_database にも）
     */
    private function recordCreated(int $runId, string $name, ?string $summaryKey): void
    {
        $this->created[] = $name;
        if ($summaryKey !== null) {
            $this->summary[$summaryKey] = $name;
        }
        $this->updateRun($runId, ['summary' => $this->encodeSummary()], true);
    }

    /**
     * この run が作った DB を捨てて一覧から外す（失敗しても握る。残った名前は孤児掃除の対象）
     */
    private function dropCreated(string $name): void
    {
        try {
            $this->databases->drop($name);
            $this->created = array_values(array_filter($this->created, static fn (string $n): bool => $n !== $name));
        } catch (Throwable $e) {
            Log::warning('この run が作った DB を DROP できませんでした（孤児掃除の対象）', ['exception' => $e, 'database' => $name]);
        }
    }

    /**
     * 失敗の後始末: この run が作った DB だけを DROP する（sys の COMMIT の成否が不明のときは版の DB を残す）。失敗を実行記録に残す
     */
    private function failRun(?int $runId, MasterDeployRunException $e, bool $keepVersionDatabase): void
    {
        $work = $this->summary['work_database'] ?? null;
        foreach ($this->created as $name) {
            if ($keepVersionDatabase && $name !== $work) {
                continue;
            }
            $this->dropCreated($name);
        }
        if ($runId === null) {
            return;
        }
        foreach (['error_total', 'error_count_by_code'] as $key) {
            if (isset($e->extra[$key])) {
                $this->summary[$key] = $e->extra[$key];
            }
        }
        $this->updateRun($runId, [
            'status' => 'failed',
            'error_code' => $e->errorCode,
            'error_message' => $e->getMessage(),
            'summary' => $this->encodeSummary(),
            'finished_at' => $this->nowString(),
        ], false);
    }

    private function loadFailed(Throwable $e): MasterDeployRunException
    {
        Log::error('マスターの DB への投入に失敗', ['exception' => $e]);

        return new MasterDeployRunException(
            MasterDeployRunException::LOAD_FAILED,
            'DB への投入に失敗しました: '.MasterVersionDatabase::safeMessage($e),
            500,
            previous: $e,
        );
    }

    private function fromKeyException(MasterDeployKeyException $e): MasterDeployRunException
    {
        return new MasterDeployRunException($e->errorCode, $e->getMessage(), $e->httpStatus, previous: $e);
    }

    // ---- 実行記録（tol_master_deploy_run。tool 接続。各更新は autocommit。db.md 5 節） ----

    private function encodeSummary(): ?string
    {
        $summary = $this->summary;
        $summary['created_database_list'] = $this->created;
        try {
            return json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }

    private function nowString(): string
    {
        return now()->utc()->format('Y-m-d H:i:s');
    }

    /**
     * kind = build の run を running で作る。ロックを取れているので、残っている古い running はプロセスが落ちた跡として aborted に直す
     *
     * @param  array{mode: 'fixed'|'explicit', deploy_key: int}  $target
     */
    private function createRun(array $target, ?int $admAccountId): int
    {
        $now = $this->nowString();
        DB::connection('tool')->table('tol_master_deploy_run')->where('status', 'running')
            ->update(['status' => 'aborted', 'finished_at' => $now, 'updated_at' => $now]);

        return (int) DB::connection('tool')->table('tol_master_deploy_run')->insertGetId([
            'kind' => 'build',
            'mode' => $target['mode'],
            'deploy_key' => $target['deploy_key'],
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
     * 実行記録を更新する。sys の COMMIT の前（$strict = true）は、書けなければ例外で中止する（記録できない build は避ける。db.md 5.2）。
     * 登録の後（false）は、中止せずログに残して続ける
     *
     * @param  array<string, mixed>  $changes
     */
    private function updateRun(int $runId, array $changes, bool $strict): void
    {
        try {
            DB::connection('tool')->table('tol_master_deploy_run')
                ->where('id', $runId)
                ->update([...$changes, 'updated_at' => $this->nowString()]);
        } catch (Throwable $e) {
            if ($strict) {
                throw $e;
            }
            Log::error('マスター build の実行記録の更新に失敗（処理は続ける）', ['exception' => $e, 'run_id' => $runId, 'changes' => array_keys($changes)]);
        }
    }
}

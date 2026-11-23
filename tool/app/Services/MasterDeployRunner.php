<?php

namespace App\Services;

use App\Exceptions\MasterDeployKeyException;
use App\Exceptions\MasterDeployRunException;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * マスターデプロイ全体の順序・排他・実行記録・トランザクション（画面・CLI から呼ぶ）
 *
 * 設計: docs/design/master-distribution/sequence.md 2〜6 節、api.md 4・11・12 節、db.md 5・8 節
 *
 * 入口は run()。呼び出し側（S3-13 の API・S3-14 の CLI）は、成功の配列か、MasterDeployRunException（toArray() が
 * api.md 2 節の失敗の形、httpStatus が HTTP ステータス）を受け取る。
 *
 * 順序（本物・固定とも）: 環境設定の検査（fixed_key_forbidden。ロックの前）→ GET_LOCK（sys。待たない）→ 実行記録を running で作る
 * → 全破棄の判定（M1）→ 読む（read）→ 採番 → 検証（validate）→ 畳み → mst に差分反映（apply。トランザクションを開いたまま）
 * → 配信 SQLite の書き出し（export。同じ接続で読む）→ sys に登録（register。COMMIT の前に phase = commit）→ mst の COMMIT
 * → キャッシュ破棄（invalidate）→ succeeded（done）。最後にロックを必ず解放する。
 *
 * dry_run はロック・実行記録・書き込み・ファイル・キャッシュを一切使わない（sequence.md 4 節）。
 *
 * ## S3-12a / S3-12b の境界
 * ここ（12a）は正常系と、COMMIT 前の簡単な失敗（sheet_invalid・deploy_locked・apply/export/register_failed など。mst をロールバックして failed）。
 * S3-12b で `handleCommitFailure()`（mst の COMMIT 失敗の補償・sys_deploy のキャッシュ破棄・inconsistent の記録。
 * sys の COMMIT 自体の失敗＝成否不明も、COMMIT の直前に持った登録結果で同じ補償を行い inconsistent にする）を実装した。
 * プロセスが途中で止まった run（5.6）と再実行時の全破棄判定（M1。`shouldInvalidateAll`）は流れの一部で、テストで固定している。
 */
class MasterDeployRunner
{
    private readonly MasterDeployKeyPolicy $policy;

    private readonly MasterDeployLock $lock;

    private readonly MasterSheetValidator $validator;

    private readonly MasterImportService $importService;

    private readonly MasterDataExporter $exporter;

    private readonly MasterDeployService $deployService;

    private readonly MasterCacheInvalidator $invalidator;

    /** @var Closure(): GoogleSpreadsheetService */
    private readonly Closure $sheetServiceFactory;

    /**
     * @param  Closure(): GoogleSpreadsheetService|null  $sheetServiceFactory  Google の認証情報が無いとコンストラクタが例外を投げるので、使うときに作る
     */
    public function __construct(
        ?MasterDeployKeyPolicy $policy = null,
        ?MasterDeployLock $lock = null,
        ?Closure $sheetServiceFactory = null,
        ?MasterSheetValidator $validator = null,
        ?MasterImportService $importService = null,
        ?MasterDataExporter $exporter = null,
        ?MasterDeployService $deployService = null,
        ?MasterCacheInvalidator $invalidator = null,
    ) {
        $this->policy = $policy ?? MasterDeployKeyPolicy::fromConfig();
        $this->lock = $lock ?? new MasterDeployLock;
        $this->sheetServiceFactory = $sheetServiceFactory ?? fn (): GoogleSpreadsheetService => new GoogleSpreadsheetService;
        $this->validator = $validator ?? new MasterSheetValidator($this->policy);
        $this->importService = $importService ?? new MasterImportService;
        $this->exporter = $exporter ?? new MasterDataExporter;
        $this->deployService = $deployService ?? new MasterDeployService($this->policy);
        $this->invalidator = $invalidator ?? new MasterCacheInvalidator;
    }

    /**
     * デプロイを 1 回行う
     *
     * @param  array{dry_run?: bool, adm_account_id?: int|null}  $options  dry_run（既定 false）、実行した管理者（CLI は null）
     * @return array<string, mixed> api.md 4 節の成功のレスポンス
     *
     * @throws MasterDeployRunException
     */
    public function run(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $admAccountId = isset($options['adm_account_id']) ? (int) $options['adm_account_id'] : null;

        // 環境設定の検査（排他の前。db.md 7.3 の層 2）
        try {
            $fixedKey = $this->policy->resolveFixedKey();
        } catch (MasterDeployKeyException $e) {
            throw $this->fromKeyException($e);
        }

        return $dryRun ? $this->runDry($fixedKey) : $this->runReal($fixedKey, $admAccountId);
    }

    /**
     * dry run（sequence.md 4 節）。何も書かない
     *
     * @return array<string, mixed>
     */
    private function runDry(?int $fixedKey): array
    {
        $prepared = $this->prepare($fixedKey, true, null);

        try {
            $reflect = $this->importService->reflect($this->buildReflectTables($prepared['folded']), true);
        } catch (Throwable $e) {
            throw $this->applyFailed($e);
        }
        $tableList = $this->buildTableList($prepared['assembled'], $prepared['folded'], $reflect['tables']);

        $warningList = $prepared['validation']['warning_list'];
        foreach ($prepared['validation']['future_row_count'] as $table => $count) {
            $warningList[] = [
                'sheet' => '', 'table' => $table, 'row' => null, 'column' => 'deploy_key', 'code' => 'future_deploy_key_row',
                'message' => "{$table} に deploy_key が {$prepared['deploy_key']} より大きい行が {$count} 件あります（今回は効きません）",
            ];
        }

        return [
            'status' => 'success',
            'message' => "dry_run: 検証と差分の確認だけを行いました（deploy_key={$prepared['deploy_key']}）。",
            'dry_run' => true,
            'run_id' => null,
            'deploy' => [
                'mode' => $fixedKey === null ? 'real' : 'fixed',
                'deploy_key' => $prepared['deploy_key'],
                'sys_deploy_master_id' => null,
                'sys_deploy_id' => null,
                'is_new' => null,
            ],
            'import' => $this->buildImportResult($prepared['assembled'], $tableList, $warningList),
            'export' => null,
            'cache' => ['invalidated' => null, 'failed_key_list' => null],
            'inventory' => $prepared['inventory'],
        ];
    }

    /**
     * 本番のデプロイ（sequence.md 2・3 節）
     *
     * @return array<string, mixed>
     */
    private function runReal(?int $fixedKey, ?int $admAccountId): array
    {
        try {
            $acquired = $this->lock->acquire();
        } catch (Throwable $e) {
            Log::error('マスターデプロイのロック取得でエラー', ['exception' => $e]);
            throw new MasterDeployRunException(
                MasterDeployRunException::INTERNAL_ERROR,
                'ロックの取得でエラーが発生しました。ログの詳細を確認してください。',
                500,
                previous: $e,
            );
        }
        if (! $acquired) {
            // 実行記録は作らない（ロックが無く、古い running を判断できないため。sequence.md 5.2）
            throw new MasterDeployRunException(
                MasterDeployRunException::DEPLOY_LOCKED,
                '他のデプロイが実行中です。しばらくしてからもう一度実行してください。',
                409,
            );
        }

        /** @var int|null $runId run の作成に失敗したときは null のまま catch に入る */
        $runId = null;
        $mst = null;
        $summary = null;
        try {
            $runId = $this->createRun($fixedKey === null ? 'real' : 'fixed', $admAccountId);
            // M1: 前回が commit 以降へ進んだのに正常に終わっていなければ、今回は全表のキャッシュを破棄する（db.md 8 節）
            $fullInvalidate = $this->invalidator->shouldInvalidateAll($runId);

            $prepared = $this->prepare($fixedKey, false, $runId);
            $deployKey = $prepared['deploy_key'];

            // apply: mst のトランザクションを開いたまま返る（途中で失敗したら reflect が rollBack して例外）
            $this->updateRun($runId, ['phase' => 'apply'], true);
            try {
                $reflect = $this->importService->reflect($this->buildReflectTables($prepared['folded']));
            } catch (Throwable $e) {
                throw $this->applyFailed($e);
            }
            $mst = $reflect['connection'];
            $counts = $reflect['tables'];
            $tableList = $this->buildTableList($prepared['assembled'], $prepared['folded'], $counts);
            $summary = ['tables' => $counts, 'table_list' => $tableList];

            // export: 同じ接続（未コミット）で全 mst_ 表を読む
            $this->updateRun($runId, ['phase' => 'export'], true);
            try {
                $export = $this->exporter->withConnection($mst)->export();
            } catch (Throwable $e) {
                Log::error('マスター配信ファイルの書き出しに失敗', ['exception' => $e]);
                throw new MasterDeployRunException(
                    MasterDeployRunException::EXPORT_FAILED,
                    '配信ファイル（SQLite）の書き出しに失敗しました。ログの詳細を確認してください。',
                    500,
                    previous: $e,
                );
            }
            $summary['hash'] = $export['hash'];

            // register: sys の 1 トランザクション。COMMIT の前に phase を commit にする（M1・sequence.md 2 節の手順 20）
            $this->updateRun($runId, ['phase' => 'register', 'master_hash' => $export['hash']], true);
            /** @var array<string, mixed>|null $pending COMMIT 直前の登録の結果（成否不明の COMMIT の補償に使う） */
            $pending = null;
            $commitStarted = false;
            try {
                $registration = $this->deployService->registerDeploy(
                    $export,
                    $deployKey,
                    $fixedKey !== null,
                    null,
                    function (?array $result = null) use ($runId, &$pending, &$commitStarted): void {
                        $pending = $result;
                        $this->updateRun($runId, ['phase' => 'commit'], true);
                        $commitStarted = true; // 以降の失敗は sys の COMMIT 自体の失敗（成否不明）
                    },
                );
            } catch (MasterDeployKeyException $e) {
                if (! $commitStarted || $pending === null) {
                    // 登録の途中の失敗は sys もロールバック済み（register_failed など）
                    throw $this->fromKeyException($e);
                }
                // sys の COMMIT 自体の失敗は成否不明（5.4 の脚注）。mst は未コミットなので戻し、確定していた場合に備えて補償する
                $this->rollbackMst($mst);
                $mst = null;
                $this->handleCommitFailure($runId, $e->getPrevious() ?? $e, $pending, 'sys');
            }
            // mst の COMMIT（sys は確定済み）。5.4 の窓を広げないよう、この間に実行記録は書かない
            try {
                $mst->commit();
            } catch (Throwable $e) {
                $mst = null; // 成否不明の接続は触らない
                $this->handleCommitFailure($runId, $e, $registration, 'mst');
            }
            $mst = null;

            // ここから先は mst・sys とも確定済み。想定外の例外でも failed にせず、握ってログに残して成功を返す
            // （running のまま残る・cache_invalidated=false なら次の実行が全表のキャッシュを破棄する。db.md 8 節）
            $cache = ['invalidated' => false, 'failed_key_list' => []];
            try {
                $this->updateRun($runId, [
                    'deploy_key' => $registration['deploy_key'],
                    'sys_deploy_master_id' => $registration['sys_deploy_master_id'],
                    'sys_deploy_id' => $registration['sys_deploy_id'],
                    'is_new' => $registration['is_new'],
                    'phase' => 'invalidate', // forget の直前
                ], false);
                $cache = $this->invalidateCache($fullInvalidate, $counts, $registration);
            } catch (Throwable $e) {
                Log::error('マスターデプロイの commit 後の処理で想定外の例外（成功として続ける）', ['exception' => $e, 'run_id' => $runId]);
            }

            $summary['cache_invalidated'] = $cache['invalidated'];
            $summary['cache_failed_key_list'] = $cache['failed_key_list'];
            $summary['full_invalidation'] = $fullInvalidate;
            $this->updateRun($runId, [
                'status' => 'succeeded',
                'phase' => 'done',
                'summary' => $this->encodeSummary($summary) ?? json_encode(['cache_invalidated' => $cache['invalidated']]),
                'finished_at' => $this->nowString(),
            ], false);

            return [
                'status' => 'success',
                'message' => $registration['is_new']
                    ? "新しい版を登録しました（deploy_key={$registration['deploy_key']}）。"
                    : "同じ内容の版が既にあるため、既存の版を有効に戻しました（deploy_key={$registration['deploy_key']}）。",
                'dry_run' => false,
                'run_id' => $runId,
                'deploy' => [
                    'mode' => $fixedKey === null ? 'real' : 'fixed',
                    'deploy_key' => $registration['deploy_key'],
                    'sys_deploy_master_id' => $registration['sys_deploy_master_id'],
                    'sys_deploy_id' => $registration['sys_deploy_id'],
                    'is_new' => $registration['is_new'],
                ],
                'import' => $this->buildImportResult($prepared['assembled'], $tableList, []),
                'export' => $export,
                'cache' => $cache,
            ];
        } catch (MasterDeployRunException $e) {
            $this->rollbackMst($mst);
            // MasterDeployRunException は run の作成より後でしか出ない（runId は確定している）
            if ($e->errorCode !== MasterDeployRunException::INCONSISTENT) {
                $this->failRun($runId, $e, $summary);
            }
            throw $e->withRunId($runId);
        } catch (Throwable $e) {
            $this->rollbackMst($mst);
            Log::error('マスターデプロイで想定外のエラー', ['exception' => $e, 'run_id' => $runId]);
            $error = new MasterDeployRunException(
                MasterDeployRunException::INTERNAL_ERROR,
                '想定外のエラーが発生しました。ログの詳細を確認してください。',
                500,
                previous: $e,
            );
            if ($runId !== null) {
                $this->failRun($runId, $error, $summary);
            }
            throw $error->withRunId($runId);
        } finally {
            try {
                $this->lock->release();
            } catch (Throwable $e) {
                Log::error('マスターデプロイのロックの解放に失敗', ['exception' => $e]);
            }
        }
    }

    /**
     * 読む → 採番 → 検証 → 畳み（mst も sys も変えない。2 節の手順 8〜12）
     *
     * 採番は検証より先に行う（validate が T を要るため。設計の V01〜V09 → 採番 → V10〜V12 の順を 1 回の validate 呼び出しにまとめた。
     * 影響は、シートの不備と採番エラー（deploy_key_exhausted など）が同時にあるとき採番エラーが先に出るだけ）。
     *
     * @return array{
     *   assembled: array<string, mixed>,
     *   deploy_key: int,
     *   folded: array<string, array{adopted: array<int, array<string, mixed>>, adopted_index_list: array<int, int>, input_rows: int, replaced: bool}>,
     *   validation: array<string, mixed>,
     *   inventory: array<string, mixed>|null
     * }
     */
    private function prepare(?int $fixedKey, bool $dryRun, ?int $runId): array
    {
        if ($runId !== null) {
            $this->updateRun($runId, ['phase' => 'read'], true);
        }
        $schema = $this->loadMstSchema();
        $assembled = $this->readSheets($schema['columns']);

        try {
            $deployKey = $fixedKey ?? $this->deployService->allocateRealKey();
        } catch (MasterDeployKeyException $e) {
            throw $this->fromKeyException($e);
        }

        if ($runId !== null) {
            $this->updateRun($runId, ['phase' => 'validate', 'deploy_key' => $deployKey], true);
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

        return [
            'assembled' => $assembled,
            'deploy_key' => $deployKey,
            'folded' => MasterOverlayFolder::fold($assembled['tables'], $deployKey),
            'validation' => $validation,
            'inventory' => $inventory,
        ];
    }

    /**
     * mst の表ごとの列名と rarity 列の型（検証 V02・V09・V10 用）
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
     * 畳み結果を MasterImportService::reflect の入力にする（置き換えの対象の表だけ。全行が削除印の表は replace = true の空）
     *
     * @param  array<string, array{adopted: array<int, array<string, mixed>>, replaced: bool}>  $folded
     * @return array<string, array{headers: array<int, string>, rows: array<int, array<string, mixed>>, replace: bool}>
     */
    private function buildReflectTables(array $folded): array
    {
        $tables = [];
        foreach ($folded as $tableName => $fold) {
            if (! $fold['replaced']) {
                continue;
            }
            $isL10n = str_ends_with($tableName, '__l10n');
            $idColumn = $isL10n ? substr($tableName, 0, -strlen('__l10n')).'_id' : 'id';

            $headers = [];
            $rows = [];
            foreach ($fold['adopted'] as $entry) {
                $row = [$idColumn => $entry['id']];
                if ($isL10n) {
                    $row['language'] = $entry['language'];
                }
                foreach ((array) $entry['columns'] as $column => $value) {
                    $row[$column] = $value;
                }
                $row['deploy_key'] = $entry['deploy_key'];

                foreach (array_keys($row) as $column) {
                    $headers[(string) $column] = true;
                }
                $rows[] = $row;
            }
            $tables[$tableName] = ['headers' => array_keys($headers), 'rows' => $rows, 'replace' => true];
        }

        return $tables;
    }

    /**
     * @param  array<string, mixed>  $assembled
     * @param  array<string, array{adopted: array<int, array<string, mixed>>, input_rows: int, replaced: bool}>  $folded
     * @param  array<string, array{inserted: int, updated: int, deleted: int, unchanged: int}>  $counts
     * @return array<int, array<string, mixed>>
     */
    private function buildTableList(array $assembled, array $folded, array $counts): array
    {
        $list = [];
        foreach ($folded as $tableName => $fold) {
            $count = $counts[$tableName] ?? ['inserted' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0];
            $list[] = [
                'table' => $tableName,
                'input_rows' => $fold['input_rows'],
                'ignored_inactive_rows' => (int) ($assembled['tables'][$tableName]['ignored_inactive_rows'] ?? 0),
                'adopted_rows' => count($fold['adopted']),
                'inserted' => $count['inserted'],
                'updated' => $count['updated'],
                'deleted' => $count['deleted'],
                'unchanged' => $count['unchanged'],
                'replaced' => $fold['replaced'],
            ];
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $assembled
     * @param  array<int, array<string, mixed>>  $tableList
     * @param  array<int, array<string, mixed>>  $warningList
     * @return array<string, mixed>
     */
    private function buildImportResult(array $assembled, array $tableList, array $warningList): array
    {
        return [
            'table_list' => $tableList,
            'ignored_tab_list' => $assembled['ignored_tab_list'] ?? [],
            'ignored_column_list' => $assembled['ignored_column_list'] ?? [],
            'warning_list' => $warningList,
        ];
    }

    /**
     * COMMIT の失敗（成否不明）の後始末（sequence.md 5.4・6 節）
     *
     * - mst の COMMIT 失敗（sys は確定済み）: sys_deploy を元に戻す
     * - sys の COMMIT 失敗（確定したかは不明。mst は呼び出し側がロールバック済み）: 確定していた場合に備えて同じ補償を行う。
     *   補償は is_active の更新だけで冪等なので、sys が確定していなかったときは何も変えない
     * 補償の成否によらず sys_deploy の 3 種のキャッシュ破棄を試み、run を inconsistent にして 500 を返す。
     * run の phase は commit のままなので、次の実行が全表のキャッシュを破棄する（再実行で直る）。
     *
     * 注意: sys の COMMIT が確定していなかったとき、run の sys_deploy_id と summary の sys_deploy_id は実在しない id かもしれない
     * （確定不明の値。採番だけ進んだ id）。
     * 注意: sys 接続が切れると GET_LOCK も外れ、補償は排他なしで動く。補償は冪等な is_active の更新だけなので許容する。
     *
     * @param  array<string, mixed>  $registration  registerDeploy の結果（activated_deploy_id・previous_active_deploy_ids を使う）
     * @param  'mst'|'sys'  $failedAt
     *
     * @throws MasterDeployRunException 常に inconsistent
     */
    protected function handleCommitFailure(int $runId, Throwable $e, array $registration, string $failedAt): never
    {
        Log::error("{$failedAt} の COMMIT に失敗（成否不明。補償を試みる）", ['exception' => $e, 'run_id' => $runId]);

        $activatedId = (int) $registration['activated_deploy_id'];
        /** @var list<int> $previousIds */
        $previousIds = array_values(array_map('intval', (array) $registration['previous_active_deploy_ids']));
        try {
            $this->deployService->compensate($activatedId, $previousIds);
            $compensated = true;
        } catch (Throwable $compensateError) {
            $compensated = false;
            Log::error('sys_deploy の補償に失敗（再実行で直る）', ['exception' => $compensateError, 'run_id' => $runId]);
        }

        $cache = $this->forgetSysDeploy($registration);

        $message = $compensated
            ? "{$failedAt} のコミットに失敗しました。sys_deploy は元に戻しました（補償済み）。再実行すると整合します。"
            : "{$failedAt} のコミットに失敗し、sys_deploy を元に戻すこともできませんでした（補償できず）。再実行すると整合します。";
        $summary = [
            'failed_at' => $failedAt,
            'compensated' => $compensated,
            'sys_deploy_id' => $activatedId,
            'previous_active_deploy_ids' => $previousIds,
            'cache_invalidated' => $cache['invalidated'],
            'cache_failed_key_list' => $cache['failed_key_list'],
        ];
        $this->updateRun($runId, [
            'status' => 'inconsistent',
            'error_code' => MasterDeployRunException::INCONSISTENT,
            'error_message' => $message,
            'sys_deploy_id' => $activatedId,
            'summary' => $this->encodeSummary($summary),
            'finished_at' => $this->nowString(),
        ], false);

        throw new MasterDeployRunException(
            MasterDeployRunException::INCONSISTENT,
            $message,
            500,
            [],
            null,
            ['compensated' => $compensated],
            $e,
        );
    }

    /**
     * sys_deploy の 3 種（latest_downloadable・deploy_key:{n}・id:{n}）だけを forget する（mst の表は触らない。例外も出さない）
     *
     * @param  array<string, mixed>  $registration
     * @return array{invalidated: bool, failed_key_list: list<string>}
     */
    private function forgetSysDeploy(array $registration): array
    {
        // メモリにある登録結果だけで 3 種を試みる（sys が落ちていて補償も失敗しているときも forget は試みる。5.4）
        $idList = [(int) $registration['activated_deploy_id'], ...array_map('intval', (array) $registration['previous_active_deploy_ids'])];
        $deployKeyList = [(int) $registration['deploy_key']];
        try {
            // sys_deploy を読めたときだけ、無効にしていた行の deploy_key を足す（失敗は握る）
            foreach (DB::connection('sys')->table('sys_deploy')->whereIn('id', $idList)->pluck('deploy_key') as $deployKey) {
                $deployKeyList[] = (int) $deployKey;
            }
        } catch (Throwable $e) {
            Log::warning('補償時に sys_deploy の deploy_key を読めない（メモリの値だけで forget する）', ['exception' => $e]);
        }

        try {
            return $this->invalidator->invalidate([], $deployKeyList, $idList);
        } catch (Throwable $e) {
            Log::error('補償時の sys_deploy のキャッシュ破棄に失敗', ['exception' => $e]);

            return ['invalidated' => false, 'failed_key_list' => []];
        }
    }

    /**
     * キャッシュを破棄する。失敗してもデプロイは成功のまま（5.5）。例外も外へ出さない
     *
     * @param  array<string, array{inserted?: int, updated?: int, deleted?: int}>  $counts
     * @param  array{deploy_key: int, activated_deploy_id: int, previous_active_deploy_ids: list<int>}  $registration
     * @return array{invalidated: bool, failed_key_list: list<string>}
     */
    private function invalidateCache(bool $fullInvalidate, array $counts, array $registration): array
    {
        try {
            $idList = [$registration['activated_deploy_id'], ...$registration['previous_active_deploy_ids']];
            $deployKeyList = [$registration['deploy_key']];
            foreach (DB::connection('sys')->table('sys_deploy')->whereIn('id', $idList)->pluck('deploy_key') as $deployKey) {
                $deployKeyList[] = (int) $deployKey;
            }

            if ($fullInvalidate) {
                return $this->invalidator->invalidateAll($deployKeyList, $idList);
            }

            return $this->invalidator->invalidate(MasterCacheInvalidator::changedTables($counts), $deployKeyList, $idList);
        } catch (Throwable $e) {
            Log::error('マスターのキャッシュ破棄に失敗', ['exception' => $e]);

            return ['invalidated' => false, 'failed_key_list' => []];
        }
    }

    private function applyFailed(Throwable $e): MasterDeployRunException
    {
        Log::error('mst への差分反映に失敗', ['exception' => $e]);

        return new MasterDeployRunException(
            MasterDeployRunException::APPLY_FAILED,
            'mst への反映に失敗しました: '.mb_substr($this->safeMessage($e), 0, 500),
            500,
            previous: $e,
        );
    }

    /**
     * 利用者に出してよい例外の文言。QueryException の message は SQL とバインド値（シートの値）を含むので、
     * 中の PDOException（DB の文言だけ）を使う。それ以外は自前の RuntimeException の文言
     */
    private function safeMessage(Throwable $e): string
    {
        if ($e instanceof QueryException) {
            return $e->getPrevious()?->getMessage() ?? 'DB がクエリを拒否しました';
        }

        return $e->getMessage();
    }

    private function fromKeyException(MasterDeployKeyException $e): MasterDeployRunException
    {
        return new MasterDeployRunException($e->errorCode, $e->getMessage(), $e->httpStatus, previous: $e);
    }

    private function rollbackMst(?ConnectionInterface $mst): void
    {
        if ($mst === null) {
            return;
        }
        try {
            $mst->rollBack();
        } catch (Throwable $e) {
            Log::error('mst のロールバックに失敗', ['exception' => $e]);
        }
    }

    // ---- 実行記録（tol_master_deploy_run。tool 接続。各更新は autocommit。db.md 5 節） ----

    /**
     * @param  array<string, mixed>  $summary
     */
    private function encodeSummary(array $summary): ?string
    {
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
     * running の run を作る。ロックを取れているので、残っている古い running はプロセスが落ちた跡として aborted に直す
     */
    private function createRun(string $mode, ?int $admAccountId): int
    {
        $now = $this->nowString();
        $table = DB::connection('tool')->table('tol_master_deploy_run');
        $table->where('status', 'running')->update(['status' => 'aborted', 'finished_at' => $now, 'updated_at' => $now]);

        return (int) DB::connection('tool')->table('tol_master_deploy_run')->insertGetId([
            'mode' => $mode,
            'deploy_key' => null,
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
     * 実行記録を更新する
     *
     * sys の COMMIT の前（$strict = true）は、書けなければ例外で中止する（記録できないデプロイは避ける。db.md 8 節）。
     * COMMIT の後（false）は、中止も補償もせずアプリのログに残して続ける（running・phase commit のまま残れば次の実行が aborted に直し全表破棄する）。
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
            Log::error('マスターデプロイの実行記録の更新に失敗（処理は続ける）', ['exception' => $e, 'run_id' => $runId, 'changes' => array_keys($changes)]);
        }
    }

    /**
     * 失敗を記録する（記録の失敗は握りつぶして元のエラーを優先する）
     *
     * @param  array<string, mixed>|null  $summary
     */
    private function failRun(int $runId, MasterDeployRunException $e, ?array $summary): void
    {
        $summary ??= [];
        foreach (['error_total', 'error_count_by_code'] as $key) {
            if (isset($e->extra[$key])) {
                $summary[$key] = $e->extra[$key];
            }
        }
        $this->updateRun($runId, [
            'status' => 'failed',
            'error_code' => $e->errorCode,
            'error_message' => $e->getMessage(),
            'summary' => $summary === [] ? null : $this->encodeSummary($summary),
            'finished_at' => $this->nowString(),
        ], false);
    }
}

<?php

namespace App\Services;

use App\Exceptions\MasterDeployRunException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * import（review・production・staging）の順序・排他・実行記録（db.md 6.6 の import・6.7。api.md 7 節）
 *
 * 上流の成果物を pull → 検証（I1〜I3）→ DB を要しない検査（I4・I6）→ 版の DB（version_db）→ 行の不変性（I5。production）→ 配置（store）→ sys に登録。
 * シートは読まず、畳まない。環境設定の検査（role・上流の disk・DB 名・基底 DB。ロックの前）→ ロック → 実行記録 kind = import（phase lock）。
 *
 * - I1: manifest・hash の再計算・ディレクトリ名・各ファイルの SHA-256・final.json（require_finalized）。I2: production・staging で予約帯の成果物は reserved_key_forbidden。
 *   I3: 成果物のスキーマ版 == 自環境の基底 DB（schema_mismatch）
 * - I4: 同じ deploy_key の別 hash は全 import 環境で confirmed_key_modified（R3）。参照版 A（deploy_key が最大の master）より小さい新しい master は
 *   production（enforce_confirmed_rows）だけ deploy_key_regression（既存の master を再利用するときは前進を省略）。I6: start_at（resolveStartAt）
 * - I5（production。既存の master を再利用するときは省略）: 新しい版の行のうち deploy_key <= K のものは、A の DB に同じ id・同じ内容で存在すること
 *   （`<=>` の逆結合。created_at・updated_at を除く）。A の DB が使えなければ A の成果物から復元して比べる（実行では A の mst_database・mst_dropped_at も更新し、
 *   dry run では一時の DB に復元して捨てる）。A の成果物も無い・復元できないなら reference_unavailable
 * - 失敗時: この run が新規に作った DB だけを DROP する。A を復元して sys を更新した DB は以後 DROP しない（6.6 の手順 4）
 * - dry_run: ロックは取る・実行記録は書かない・一時の DB（作業用と同じ命名）と作業ディレクトリは finally で片付ける・ストレージ・sys・キャッシュは変えない
 */
class MasterImportRunner extends MasterDeployOperation
{
    private const ARTIFACT_DIR_PATTERN = MasterArtifactStore::DIR_PATTERN;

    private const MISMATCH_SAMPLE_MAX = 20;

    private readonly MasterArtifactStore $upstream;

    private readonly MasterDeployKeyPolicy $policy;

    public function __construct(
        ?MasterArtifactStore $upstream = null,
        ?MasterDeployKeyPolicy $policy = null,
        ?MasterDeployLock $lock = null,
        ?MasterVersionDatabase $databases = null,
        ?MasterCacheInvalidator $invalidator = null,
        ?MasterArtifactStore $store = null,
        ?MasterDeployService $deployService = null,
        ?MasterArtifactVerifier $verifier = null,
    ) {
        parent::__construct($lock, $databases, $invalidator, $store, $deployService, null, $verifier);
        $this->upstream = $upstream ?? new MasterArtifactStore('master_artifact_upstream');
        $this->policy = $policy ?? MasterDeployKeyPolicy::fromConfig();
    }

    /**
     * @param  array{start_at?: string|null, dry_run?: bool, adm_account_id?: int|null}  $options  start_at は UTC の 'Y-m-d H:i:s'（null は「すぐ」）
     * @return array<string, mixed>
     *
     * @throws MasterDeployRunException
     */
    public function run(string $artifactDir, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $admAccountId = isset($options['adm_account_id']) ? (int) $options['adm_account_id'] : null;
        $startAtInput = isset($options['start_at']) && $options['start_at'] !== '' ? (string) $options['start_at'] : null;

        // 環境設定の検査（排他の前）
        if (config('master_deploy.role') !== 'import') {
            throw new MasterDeployRunException(MasterDeployRunException::ROLE_FORBIDDEN, 'この環境（role='.(string) config('master_deploy.role').'）では import はできません（import の環境だけ）。', 403);
        }
        if (config('filesystems.disks.master_artifact_upstream') === null) {
            throw new MasterDeployRunException(MasterDeployRunException::UPSTREAM_NOT_CONFIGURED, '上流の disk（master_artifact_upstream）が設定されていません。', 500);
        }
        $this->databases->assertConfiguration();
        if (preg_match(self::ARTIFACT_DIR_PATTERN, $artifactDir) !== 1) {
            throw new MasterDeployRunException(MasterDeployRunException::ARTIFACT_NOT_FOUND, "成果物のディレクトリ名の形が不正です: {$artifactDir}", 404);
        }
        $deployKey = (int) substr($artifactDir, 0, 10);

        if ($dryRun) {
            return $this->withLockNoRecord(fn (): array => $this->process($artifactDir, $startAtInput, true)['response']);
        }

        return $this->runLocked('import', $admAccountId, $deployKey, fn (): array => $this->process($artifactDir, $startAtInput, false));
    }

    /**
     * @return array{response: array<string, mixed>, run?: array<string, mixed>}
     *
     * @throws MasterDeployRunException
     */
    private function process(string $artifactDir, ?string $startAtInput, bool $dryRun): array
    {
        $checkList = [];

        // pull: 上流の成果物を一時の場所へ写す
        $this->phase('pull');
        $local = $this->newTempDir();
        try {
            $pulled = $this->upstream->pull($artifactDir, $local);
        } catch (Throwable $e) {
            Log::error('上流の成果物の pull に失敗', ['exception' => $e]);
            throw new MasterDeployRunException(MasterDeployRunException::PULL_FAILED, '上流の成果物の取得に失敗しました: '.MasterVersionDatabase::safeMessage($e), 502, previous: $e);
        }
        if (! $pulled) {
            throw new MasterDeployRunException(MasterDeployRunException::ARTIFACT_NOT_FOUND, "上流に成果物がありません（manifest.json が無い・未完成を含む）: {$artifactDir}", 404);
        }

        // verify: I1〜I3
        $this->phase('verify');
        $localDisk = Storage::build(['driver' => 'local', 'root' => $local, 'throw' => false]);
        $manifest = $this->verifier->verify($localDisk, '', $artifactDir, (bool) config('master_deploy.require_finalized'));
        $checkList[] = ['check' => 'I1', 'result' => 'passed', 'detail' => ''];
        $deployKey = (int) $manifest['deploy_key'];
        $hash = (string) $manifest['hash'];
        $tables = $this->manifestTables($manifest);
        $this->updateRun(['deploy_key' => $deployKey, 'master_hash' => $hash, 'artifact_dir' => $artifactDir, 'schema_fingerprint' => (string) $manifest['schema_fingerprint']], true);

        if (in_array((string) config('app.env'), (array) config('master_deploy.fixed_key_forbidden_envs'), true) && $this->policy->isReservedBand($deployKey)) {
            throw new MasterDeployRunException(MasterDeployRunException::RESERVED_KEY_FORBIDDEN, "予約帯の成果物（deploy_key={$deployKey}）はこの環境では import できません。", 403);
        }
        $checkList[] = ['check' => 'I2', 'result' => 'passed', 'detail' => ''];
        $baseFingerprint = $this->databases->schemaFingerprint($this->databases->baseDatabase());
        if ((string) $manifest['schema_fingerprint'] !== $baseFingerprint) {
            throw new MasterDeployRunException(
                MasterDeployRunException::SCHEMA_MISMATCH,
                '成果物のスキーマ版が、自環境の基底 DB（現行のスキーマ）と一致しません。QA 側の build をやり直すか、環境のスキーマ（マイグレーション）を揃えてください。',
                422,
            );
        }
        $checkList[] = ['check' => 'I3', 'result' => 'passed', 'detail' => ''];

        // check: I4・I6（DB を要しない）
        $this->phase('check');
        $sys = DB::connection('sys');
        $conflict = $sys->table('sys_deploy_master')->where('deploy_key', $deployKey)->where('hash', '!=', $hash)->first();
        if ($conflict !== null) {
            throw new MasterDeployRunException(
                MasterDeployRunException::CONFIRMED_KEY_MODIFIED,
                "同じ deploy_key（{$deployKey}）で別の hash の版が既に登録されています。確定済みキーの変更は新しいキーで入れてください。",
                409,
            );
        }
        $existing = $sys->table('sys_deploy_master')->where('deploy_key', $deployKey)->where('hash', $hash)->first();
        $enforce = (bool) config('master_deploy.enforce_confirmed_rows');
        $reference = null;
        if ($existing === null && $enforce) {
            $reference = $sys->table('sys_deploy_master')->orderByDesc('deploy_key')->orderByDesc('id')->first();
            if ($reference !== null && $deployKey < (int) $reference->deploy_key) {
                throw new MasterDeployRunException(
                    MasterDeployRunException::DEPLOY_KEY_REGRESSION,
                    "参照版の deploy_key（{$reference->deploy_key}）より小さい新しい版は import できません。古い版へ戻すときは巻き戻しを使ってください。",
                    409,
                );
            }
        }
        $checkList[] = ['check' => 'I4', 'result' => $existing !== null || ! $enforce ? 'skipped' : 'passed', 'detail' => $existing !== null ? '既存の版を再利用（前進の検査を省略）' : ''];
        $start = $this->resolveStartAt($startAtInput);
        $checkList[] = ['check' => 'I6', 'result' => $start['warning_list'] === [] ? 'passed' : 'warned', 'detail' => ''];
        $warningList = $start['warning_list'];

        // version_db
        $this->phase('version_db');
        $reused = false;
        $restored = $existing !== null;
        $temporaryName = $dryRun ? $this->databases->workName() : null;
        if ($existing !== null && $this->isUsable($existing, $tables, $baseFingerprint)) {
            $reused = true;
            $databaseName = (string) $existing->mst_database;
            $loaded = [];
        } else {
            $created = $this->createVersionDatabase($manifest, $local, $existing, $temporaryName);
            $databaseName = $created['name'];
            $loaded = $created['adopted'] ? [] : array_column($tables, 'row_count', 'name');
        }
        $this->updateRun(['mst_database' => $dryRun ? null : $databaseName], true);

        // check_rows: I5（production。既存の master の再利用では省略）
        $referenceResult = null;
        if ($existing !== null || ! $enforce || $reference === null) {
            $checkList[] = ['check' => 'I5', 'result' => 'skipped', 'detail' => $existing !== null ? '既存の版を再利用' : ($enforce ? '参照版が無い' : 'enforce_confirmed_rows が false')];
        } else {
            $this->phase('check_rows');
            $referenceResult = $this->checkRows($reference, $databaseName, $baseFingerprint, $dryRun);
            $checkList[] = ['check' => 'I5', 'result' => 'passed', 'detail' => ''];
        }

        $tableList = [];
        foreach ($tables as $table) {
            $tableList[] = ['table' => $table['name'], 'row_count' => $table['row_count'], 'loaded' => (int) ($loaded[$table['name']] ?? 0)];
        }
        $deploy = [
            'deploy_key' => $deployKey,
            'hash' => $hash,
            'artifact_dir' => $artifactDir,
            'mst_database' => $dryRun ? null : $databaseName,
            'sys_deploy_master_id' => null,
            'sys_deploy_id' => null,
            'start_at' => $start['start_at'],
            'is_new' => null,
            'database_reused' => $reused,
        ];
        $import = ['table_list' => $tableList, 'check_list' => $checkList, 'reference' => $referenceResult, 'warning_list' => $warningList];

        if ($dryRun) {
            return ['response' => [
                'status' => 'success',
                'message' => "dry_run: 検査まで行いました（deploy_key={$deployKey}）。",
                'dry_run' => true,
                'run_id' => null,
                'deploy' => $deploy,
                'import' => $import,
                'artifact' => null,
                'cache' => ['invalidated' => null],
            ]];
        }

        // store: final.json は manifest.json より前に置かれる（MasterArtifactStore::put は manifest.json を最後に書く）
        $this->phase('store');
        $artifact = $this->storeArtifact($manifest, $local);

        // register
        $this->phase('register');
        $startAt = $start['start_at'] ?? $this->nowString();
        $registration = $this->registerSys([
            'deploy_key' => $deployKey,
            'hash' => $hash,
            'artifact_dir' => $artifactDir,
            'schema_fingerprint' => (string) $manifest['schema_fingerprint'],
            'mst_database' => $databaseName,
            'mode' => $this->policy->isReservedBand($deployKey) ? 'fixed' : 'explicit',
            'restored' => $restored && ! $reused,
            'source_kind' => 'import',
            'start_at' => $startAt,
            'start_at_explicit' => $start['start_at'] !== null,
            'table_count' => count($tables),
            'file_size' => array_sum(array_column($artifact['tables'], 'file_size')),
            'tables' => $artifact['tables'],
        ]);
        $warningList = [...$warningList, ...$registration['warning_list']];

        $cache = $this->forgetSchedule();
        if (! $cache['invalidated']) {
            $warningList[] = $this->cacheWarning();
        }
        $this->summary['warning_list'] = $warningList;
        $this->summary['cache_invalidated'] = $cache['invalidated'];
        $this->summary['database_reused'] = $reused;
        $this->summary['loaded'] = $loaded;
        $import['warning_list'] = $warningList;

        $deploy['mst_database'] = $registration['mst_database'];
        $deploy['sys_deploy_master_id'] = $registration['sys_deploy_master_id'];
        $deploy['sys_deploy_id'] = $registration['sys_deploy_id'];
        $deploy['start_at'] = $registration['start_at'];
        $deploy['is_new'] = $registration['is_new'];

        return [
            'response' => [
                'status' => 'success',
                'message' => $registration['is_new']
                    ? "成果物を取り込みました（deploy_key={$deployKey}）。"
                    : "同じ内容の版が既にあるため、既存の版を再利用して切り替え行を足しました（deploy_key={$deployKey}）。",
                'dry_run' => false,
                'deploy' => $deploy,
                'import' => $import,
                'artifact' => [
                    'hash' => $hash,
                    'artifact_dir' => $artifactDir,
                    'file_count' => count($artifact['tables']),
                    'reused' => $artifact['reused'],
                    'tables' => $artifact['tables'],
                ],
                'cache' => ['invalidated' => $cache['invalidated']],
            ],
            'run' => [
                'start_at' => $registration['start_at'],
                'sys_deploy_master_id' => $registration['sys_deploy_master_id'],
                'sys_deploy_id' => $registration['sys_deploy_id'],
                'is_new' => $registration['is_new'],
                'mst_database' => $registration['mst_database'],
            ],
        ];
    }

    /**
     * I5: 新しい版 B の行のうち deploy_key <= K のものが、参照版 A の DB に同じ id・同じ内容で存在するか
     *
     * @return array{sys_deploy_master_id: int, deploy_key: int, restored: bool}
     *
     * @throws MasterDeployRunException confirmed_key_modified・reference_unavailable
     */
    private function checkRows(object $reference, string $newDatabase, string $baseFingerprint, bool $dryRun): array
    {
        $restored = false;
        $manifestOfA = $this->store->manifest((string) $reference->artifact_dir);
        $aDatabase = (string) $reference->mst_database;
        if (! $this->isUsable($reference, $manifestOfA === null ? null : $this->manifestTables($manifestOfA), $baseFingerprint)) {
            $aDatabase = $this->restoreReference($reference, $manifestOfA, $baseFingerprint, $dryRun);
            $restored = true;
        }

        $k = (int) $reference->deploy_key;
        $connection = $this->databases->connection();
        $newTables = [];
        foreach ($this->databases->schemaDefinition($newDatabase) as $table) {
            $newTables[$table['name']] = $table;
        }
        $aTables = [];
        foreach ($this->databases->schemaDefinition($aDatabase) as $table) {
            $aTables[$table['name']] = $table;
        }

        $errorList = [];
        $total = 0;
        foreach ($newTables as $name => $table) {
            $columnNames = array_column($table['columns'], 'name');
            if (! in_array('deploy_key', $columnNames, true)) {
                continue; // deploy_key 列を持たない表（mst_vip_login_bonus 系）は比べられない
            }
            $dataColumns = array_values(array_filter($columnNames, static fn (string $c): bool => ! in_array($c, MasterVersionDatabase::AUTO_COLUMNS, true)));
            $primary = [];
            foreach ($table['indexes'] as $index) {
                if ($index['name'] === 'PRIMARY') {
                    $primary = $index['columns'];
                }
            }
            if ($primary === []) {
                $primary = [$dataColumns[0]];
            }
            $b = MasterVersionDatabase::quote($newDatabase).'.'.MasterVersionDatabase::quote($name);
            $pkSql = implode(', ', array_map(static fn (string $c): string => 'b.'.MasterVersionDatabase::quote($c), $primary));
            if (! isset($aTables[$name])) {
                $from = "FROM {$b} b WHERE b.`deploy_key` <= ?";
                $bindings = [$k];
            } else {
                $aColumns = array_column($aTables[$name]['columns'], 'name');
                $join = implode(' AND ', array_map(
                    static fn (string $c): string => 'b.'.MasterVersionDatabase::quote($c).' <=> a.'.MasterVersionDatabase::quote($c),
                    array_values(array_intersect($dataColumns, $aColumns)),
                ));
                $a = MasterVersionDatabase::quote($aDatabase).'.'.MasterVersionDatabase::quote($name);
                $from = "FROM {$b} b LEFT JOIN {$a} a ON {$join} WHERE b.`deploy_key` <= ? AND a.".MasterVersionDatabase::quote($primary[0]).' IS NULL';
                $bindings = [$k];
            }
            $count = (int) ($connection->selectOne("SELECT COUNT(*) AS c {$from}", $bindings)->c ?? 0);
            if ($count === 0) {
                continue;
            }
            $total += $count;
            $room = self::MISMATCH_SAMPLE_MAX - count($errorList);
            if ($room > 0) {
                foreach ($connection->select("SELECT {$pkSql} {$from} ORDER BY {$pkSql} LIMIT {$room}", $bindings) as $row) {
                    $values = array_map('strval', array_values((array) $row));
                    $entry = ['table' => $name, 'id' => $values[0]];
                    if (isset($values[1])) {
                        $entry['language'] = $values[1];
                    }
                    $errorList[] = $entry;
                }
            }
        }
        if ($total > 0) {
            throw new MasterDeployRunException(
                MasterDeployRunException::CONFIRMED_KEY_MODIFIED,
                "確定済みキー（deploy_key <= {$k}）の行が、参照版と違います（{$total} 件）。変更は新しい deploy_key の行として入れてください。",
                409,
                $errorList,
                null,
                ['error_total' => $total],
            );
        }

        return ['sys_deploy_master_id' => (int) $reference->id, 'deploy_key' => $k, 'restored' => $restored];
    }

    /**
     * A の DB を A の成果物から復元する。実行では sys の A（mst_database・schema_fingerprint・mst_dropped_at）を更新し、以後 DROP しない。
     * dry run では一時の DB に復元して比べ、捨てる。復元できなければ reference_unavailable
     *
     * @param  array<string, mixed>|null  $manifestOfA
     */
    private function restoreReference(object $reference, ?array $manifestOfA, string $baseFingerprint, bool $dryRun): string
    {
        $unavailable = static fn (string $why, ?Throwable $previous = null): MasterDeployRunException => new MasterDeployRunException(
            MasterDeployRunException::REFERENCE_UNAVAILABLE,
            "参照版（deploy_key={$reference->deploy_key}）の DB が使えず、{$why}ため、行の不変性（I5）を検査できません。",
            409,
            previous: $previous,
        );
        if ($manifestOfA === null) {
            throw $unavailable('成果物も無い');
        }
        try {
            $verified = $this->verifier->verify($this->store->disk(), (string) $reference->artifact_dir, (string) $reference->artifact_dir, false);
        } catch (MasterDeployRunException $e) {
            throw $unavailable('成果物が検証に通らない', $e);
        }
        if ((string) $verified['schema_fingerprint'] !== $baseFingerprint) {
            throw $unavailable('成果物のスキーマ版が現行と一致しない');
        }
        $localDir = $this->newTempDir();
        /** @var string|null $name */
        $name = null;
        try {
            if (! $this->store->pull((string) $reference->artifact_dir, $localDir)) {
                throw $unavailable('成果物を読めない');
            }
            $created = $this->createVersionDatabase($verified, $localDir, $reference, $dryRun ? $this->databases->workName() : null);
            $name = $created['name'];
            if (! $dryRun) {
                DB::connection('sys')->table('sys_deploy_master')->where('id', $reference->id)->update([
                    'mst_database' => $name,
                    'schema_fingerprint' => $baseFingerprint,
                    'mst_dropped_at' => null,
                    'updated_at' => $this->nowString(),
                ]);
                // sys が指す A の DB になったので、以後の失敗では DROP しない（6.6 の手順 4）
                $this->forgetCreated($name);
            }
        } catch (MasterDeployRunException $e) {
            if ($name !== null && in_array($name, $this->created, true)) {
                $this->dropCreated($name); // この run が作った DB だけ。採用した DB は消さない
            }
            throw $e->errorCode === MasterDeployRunException::REFERENCE_UNAVAILABLE ? $e : $unavailable('復元に失敗した', $e);
        } catch (Throwable $e) {
            if ($name !== null && in_array($name, $this->created, true)) {
                $this->dropCreated($name);
            }
            throw $unavailable('参照版の更新に失敗した', $e);
        }

        return $name;
    }

    /**
     * 成果物を自環境のストレージに置く。同じ `{deploy_key}_{hash16}` に完成した成果物があれば、完全な hash が同じなら作り直さず既存を使い、違えば artifact_dir_collision
     *
     * @param  array<string, mixed>  $manifest
     * @return array{reused: bool, tables: array<string, array{hash: string, file_name: string, file_size: int, public_url: string}>}
     *
     * @throws MasterDeployRunException
     */
    private function storeArtifact(array $manifest, string $localDir): array
    {
        $artifactDir = (int) $manifest['deploy_key'].'_'.substr((string) $manifest['hash'], 0, MasterVersionDatabase::HASH_LENGTH);
        try {
            $existing = $this->store->manifest($artifactDir);
            if ($existing === null) {
                $this->store->put($localDir, $artifactDir);

                return ['reused' => false, 'tables' => $this->tableMap($manifest, $artifactDir)];
            }
        } catch (Throwable $e) {
            Log::error('マスターの成果物の配置に失敗', ['exception' => $e]);
            throw new MasterDeployRunException(MasterDeployRunException::STORE_FAILED, '成果物のストレージへの配置に失敗しました: '.MasterVersionDatabase::safeMessage($e), 500, previous: $e);
        }
        if (($existing['hash'] ?? null) !== $manifest['hash']) {
            throw new MasterDeployRunException(
                MasterDeployRunException::ARTIFACT_DIR_COLLISION,
                "同じ名前の成果物ディレクトリが既にあり、完全な hash が違います（先頭 16 文字だけが同じ）: {$artifactDir}",
                500,
            );
        }

        return ['reused' => true, 'tables' => $this->tableMap($existing, $artifactDir)];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, array{hash: string, file_name: string, file_size: int, public_url: string}>
     */
    private function tableMap(array $manifest, string $artifactDir): array
    {
        $tables = [];
        foreach ((array) ($manifest['group_list'] ?? []) as $group) {
            $tables[(string) $group['group']] = [
                'hash' => (string) $group['sqlite_sha256'],
                'file_name' => (string) $group['file_name'],
                'file_size' => (int) $group['file_size'],
                'public_url' => '/masterdata/'.$artifactDir.'/'.$group['file_name'],
            ];
        }

        return $tables;
    }
}

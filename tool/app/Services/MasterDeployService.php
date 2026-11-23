<?php

namespace App\Services;

use App\Exceptions\MasterDeployKeyException;
use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * マスターデータの sys への登録
 *
 * 既存の register() は MasterImportController 経由の旧経路（廃止は S3-16）。
 * 新しい経路は allocateRealKey()（採番。db.md 3.4）・registerDeploy()（登録 R1〜R4。db.md 3.6）・
 * compensate()（mst のコミット失敗時と、sys の COMMIT 失敗＝成否不明のときの補償。sequence.md 5.4）で、MasterDeployRunner（S3-12b）が呼ぶ。
 */
class MasterDeployService
{
    private const NN_MAX = 99;

    public function __construct(private readonly ?MasterDeployKeyPolicy $policy = null) {}

    private function policy(): MasterDeployKeyPolicy
    {
        return $this->policy ?? MasterDeployKeyPolicy::fromConfig();
    }

    /**
     * 本物の採番: その日（UTC）の範囲 D×100+1〜D×100+99 の sys_deploy_master.deploy_key の最大 + 1（件数は使わない）。
     * 排他のロックを取った後に呼ぶ。固定キーのときは呼ばない（採番しない）。
     *
     * @throws MasterDeployKeyException deploy_key_exhausted（99 超）、reserved_key_forbidden（予約帯）
     */
    public function allocateRealKey(?CarbonInterface $now = null): int
    {
        $date = (int) ($now ?? now())->copy()->utc()->format('Ymd');
        $first = $date * 100 + 1;
        $last = $date * 100 + self::NN_MAX;

        $max = DB::connection('sys')
            ->table('sys_deploy_master')
            ->whereBetween('deploy_key', [$first, $last])
            ->max('deploy_key');

        if ($max === null) {
            $key = $first;
        } elseif ((int) $max >= $last) {
            throw MasterDeployKeyException::deployKeyExhausted($date);
        } else {
            $key = (int) $max + 1;
        }
        $this->policy()->assertRealKey($key);

        return $key;
    }

    /**
     * sys への登録（db.md 3.6 の R1〜R4）を 1 つの sys トランザクションで行う。
     *
     * - R1: 同じ hash の master があれば新しい行を作らず、その master を指す sys_deploy のうち id 最大の行を有効に戻す
     *   （指す行が無ければ同じ deploy_key で新しい sys_deploy を作る）。他の sys_deploy は全て無効にする
     * - R2・R3: hash が新しければ master・table・asset・sys_deploy を新しい行として入れる（固定キーは同じキーの古い行を残す）
     * - R4: 本物のモードで、同じ deploy_key の master が別の hash で既にあれば deploy_key_conflict
     *
     * 戻り値の previous_active_deploy_ids（登録前に有効だった sys_deploy.id の全部）と activated_deploy_id
     * （今回有効にした行）を compensate() に渡せば元に戻せる。
     *
     * @param  array{
     *   hash: string,
     *   file_size: int,
     *   table_count: int,
     *   file_count: int,
     *   tables: array<string, array{hash: string, file_name: string, file_size: int, public_url: string}>,
     * }  $exportResult
     * @param  int  $deployKey  本物は allocateRealKey() の結果、固定は MasterDeployKeyPolicy::resolveFixedKey() の結果
     * @param  bool  $isFixed  固定キーのモードか
     * @param  (callable(array<string, mixed>): void)|null  $beforeCommit  sys のトランザクションの中で、登録の後・COMMIT の前に呼ぶ（引数は登録の結果。Runner が実行記録の phase を commit にし、
     *                                                                     COMMIT が成否不明で失敗したときの補償のために結果をメモリに持つ。
     *                                                                     db.md 8 節 M1。例外はトランザクションごとロールバックされ register_failed になる）
     * @return array{
     *   sys_deploy_master_id: int,
     *   sys_deploy_id: int,
     *   deploy_key: int,
     *   hash: string,
     *   tables: array<string, array{hash: string, file_name: string, file_size: int, public_url: string}>,
     *   is_new: bool,
     *   activated_deploy_id: int,
     *   previous_active_deploy_ids: list<int>,
     * }
     *
     * 注意: sys の COMMIT 自体の失敗は成否不明（sequence.md 5.4）。register_failed を「ロールバック済み」と
     * 決め打ちせず、Runner が $beforeCommit を通ったかで区別する（previous に元の例外が入る）。
     *
     * @throws MasterDeployKeyException reserved_key_forbidden・fixed_key_invalid・deploy_key_conflict・register_failed
     */
    public function registerDeploy(array $exportResult, int $deployKey, bool $isFixed, ?CarbonInterface $now = null, ?callable $beforeCommit = null): array
    {
        $now = ($now ?? now())->copy()->utc();
        if ($isFixed) {
            // 固定キーは予約帯の許可リストの値だけ（解決は resolveFixedKey の責務。ここは最後の守り）
            if (! $this->policy()->isReservedBand($deployKey) || $this->policy()->isRejectedReservedKey($deployKey)) {
                throw MasterDeployKeyException::fixedKeyInvalid($deployKey);
            }
        } else {
            $this->policy()->assertRealKey($deployKey);
        }

        try {
            return DB::connection('sys')->transaction(
                function () use ($exportResult, $deployKey, $isFixed, $now, $beforeCommit): array {
                    $result = $this->registerInTransaction($exportResult, $deployKey, $isFixed, $now);
                    if ($beforeCommit !== null) {
                        $beforeCommit($result);
                    }

                    return $result;
                }
            );
        } catch (MasterDeployKeyException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw MasterDeployKeyException::registerFailed($e);
        }
    }

    /**
     * build の登録（db.md 3.6 の R1・R2。他の sys_deploy を無効にしない）を sys の 1 トランザクションで行う
     *
     * - R1: 同じ (deploy_key, hash) の sys_deploy_master があれば再利用し、sys_deploy の行だけ足す。`restored` が true（版の DB を作り直した）なら、
     *   同じトランザクションで master の mst_database・schema_fingerprint を更新し mst_dropped_at を NULL にする
     * - R2: 新しい (deploy_key, hash) は master・sys_deploy_master_table・asset（ダミー）・sys_deploy を足す
     * - 二重登録の防止: start_at が明示で、同じ master・同じ start_at の sys_deploy が既にあれば行を足さず既存を返す（warning `already_registered`）
     * - start_at は Runner が解決済みの値（「すぐ」は登録の直前の現在時刻）。新しい版より後の start_at の行が既にあれば warning `start_at_shadowed`
     *
     * COMMIT より前の失敗は register_failed（ロールバック済み）、COMMIT 処理中の失敗は register_commit_unknown（成否不明。Runner が読み直す）。
     *
     * @param  array{
     *   deploy_key: int,
     *   hash: string,
     *   artifact_dir: string,
     *   schema_fingerprint: string,
     *   mst_database: string,
     *   mode: 'fixed'|'explicit',
     *   restored: bool,
     *   start_at: string,
     *   start_at_explicit: bool,
     *   table_count: int,
     *   file_size: int,
     *   tables: array<string, array{hash: string, file_name: string, file_size: int, public_url: string}>
     * }  $input
     * @return array{sys_deploy_master_id: int, sys_deploy_id: int, deploy_key: int, hash: string, mst_database: string, start_at: string, is_new: bool, warning_list: list<array{code: string, message: string}>}
     *
     * @throws MasterDeployKeyException register_failed・register_commit_unknown
     */
    public function registerBuild(array $input, ?CarbonInterface $now = null): array
    {
        $now = ($now ?? now())->copy()->utc();
        $sys = DB::connection('sys');
        $sys->beginTransaction();
        try {
            $result = $this->registerBuildInTransaction($input, $now);
        } catch (Throwable $e) {
            try {
                $sys->rollBack();
            } catch (Throwable) {
                // ロールバックの失敗は元の例外を優先する（接続が切れていればサーバー側で破棄される）
            }

            throw $e instanceof MasterDeployKeyException ? $e : MasterDeployKeyException::registerFailed($e);
        }
        try {
            $this->commitSys($sys);
        } catch (Throwable $e) {
            throw MasterDeployKeyException::registerCommitUnknown($e);
        }

        return $result;
    }

    /** COMMIT（テストで成否不明を再現するための差し込み口） */
    protected function commitSys(ConnectionInterface $sys): void
    {
        $sys->commit();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{sys_deploy_master_id: int, sys_deploy_id: int, deploy_key: int, hash: string, mst_database: string, start_at: string, is_new: bool, warning_list: list<array{code: string, message: string}>}
     */
    private function registerBuildInTransaction(array $input, CarbonInterface $now): array
    {
        $sys = DB::connection('sys');
        $nowStr = $now->format('Y-m-d H:i:s');
        $deployKey = (int) $input['deploy_key'];
        $hash = (string) $input['hash'];
        $startAt = (string) $input['start_at'];
        $warningList = [];

        $existing = $sys->table('sys_deploy_master')->where('deploy_key', $deployKey)->where('hash', $hash)->first();
        if ($existing !== null) {
            // R1
            $masterId = (int) $existing->id;
            $isNew = false;
            if ($input['restored']) {
                $sys->table('sys_deploy_master')->where('id', $masterId)->update([
                    'mst_database' => $input['mst_database'],
                    'schema_fingerprint' => $input['schema_fingerprint'],
                    'mst_dropped_at' => null,
                    'updated_at' => $nowStr,
                ]);
            }
        } else {
            // R2（固定キーは deploy_count = 0。明示キーは NN）
            $isNew = true;
            $deployCount = $input['mode'] === 'fixed' ? 0 : $deployKey % 100;
            $masterId = (int) $sys->table('sys_deploy_master')->insertGetId([
                'deploy_key' => $deployKey,
                'hash' => $hash,
                'deploy_date' => $now->format('Y-m-d'),
                'deploy_count' => $deployCount,
                'status' => 'completed',
                'deployed_by' => 'master_build',
                'deployed_at' => $nowStr,
                'description' => "マスター build: {$input['table_count']}テーブル / {$input['file_size']}bytes",
                'mst_database' => $input['mst_database'],
                'artifact_dir' => $input['artifact_dir'],
                'schema_fingerprint' => $input['schema_fingerprint'],
                'source_kind' => 'build',
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ]);
            foreach ($input['tables'] as $tableName => $tableResult) {
                $sys->table('sys_deploy_master_table')->insert([
                    'sys_deploy_master_id' => $masterId,
                    'table_name' => $tableName,
                    'hash' => $tableResult['hash'],
                    'file_size' => $tableResult['file_size'],
                    'file_name' => $tableResult['file_name'],
                    'public_url' => $tableResult['public_url'],
                ]);
            }
        }

        // asset（ダミー）は (deploy_key, hash) が UNIQUE。あればその id を使う（R1 でも必要）
        $assetId = $sys->table('sys_deploy_asset')->where('deploy_key', $deployKey)->where('hash', $hash)->value('id');
        if ($assetId === null) {
            $assetId = $this->insertAsset($hash, $deployKey, $now, $input['mode'] === 'fixed' ? 0 : $deployKey % 100, 'master_build');
        }

        if ($input['start_at_explicit']) {
            $duplicate = $sys->table('sys_deploy')->where('sys_deploy_master_id', $masterId)->where('start_at', $startAt)->orderBy('id')->first();
            if ($duplicate !== null) {
                $warningList[] = ['code' => 'already_registered', 'message' => '同じ版・同じ start_at の切り替え行が既にあるため、新しい行は作りませんでした'];

                return [
                    'sys_deploy_master_id' => $masterId,
                    'sys_deploy_id' => (int) $duplicate->id,
                    'deploy_key' => $deployKey,
                    'hash' => $hash,
                    'mst_database' => (string) ($existing->mst_database ?? $input['mst_database']),
                    'start_at' => $startAt,
                    'is_new' => false,
                    'warning_list' => $warningList,
                ];
            }
        }

        if ($sys->table('sys_deploy')->where('start_at', '>', $startAt)->exists()) {
            $warningList[] = ['code' => 'start_at_shadowed', 'message' => 'この版より後の start_at の切り替え行が既にあるため、その行が到達するとこの版は選ばれません'];
        }

        $deployId = (int) $sys->table('sys_deploy')->insertGetId([
            'deploy_key' => $deployKey,
            'start_at' => $startAt,
            'sys_deploy_master_id' => $masterId,
            'sys_deploy_asset_id' => (int) $assetId,
            'is_active' => true,
            'created_at' => $nowStr,
            'updated_at' => $nowStr,
        ]);

        return [
            'sys_deploy_master_id' => $masterId,
            'sys_deploy_id' => $deployId,
            'deploy_key' => $deployKey,
            'hash' => $hash,
            'mst_database' => $isNew || $input['restored'] ? (string) $input['mst_database'] : (string) ($existing->mst_database ?? $input['mst_database']),
            'start_at' => $startAt,
            'is_new' => $isNew,
            'warning_list' => $warningList,
        ];
    }

    /**
     * 補償: 今回有効にした sys_deploy を無効にし、登録前に有効だった行を有効に戻す（is_active の更新だけ。
     * master・asset などの行は残す。同じ hash は次の実行の R1 で再利用される）。
     * mst のコミットが失敗したとき MasterDeployRunner が呼ぶ。失敗は例外のまま投げるので、呼び出し側が
     * 「補償できなかった」を記録する。
     *
     * @param  list<int>  $previousActiveDeployIds  registerDeploy の previous_active_deploy_ids
     */
    public function compensate(int $activatedDeployId, array $previousActiveDeployIds, ?CarbonInterface $now = null): void
    {
        $updatedAt = ($now ?? now())->copy()->utc()->format('Y-m-d H:i:s');
        DB::connection('sys')->transaction(function () use ($activatedDeployId, $previousActiveDeployIds, $updatedAt): void {
            // 先に無効にする（今回の行が元から有効だった場合は、次の更新で有効に戻る）
            DB::connection('sys')->table('sys_deploy')
                ->where('id', $activatedDeployId)
                ->update(['is_active' => false, 'updated_at' => $updatedAt]);
            if ($previousActiveDeployIds !== []) {
                DB::connection('sys')->table('sys_deploy')
                    ->whereIn('id', $previousActiveDeployIds)
                    ->update(['is_active' => true, 'updated_at' => $updatedAt]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $exportResult
     * @return array<string, mixed>
     */
    private function registerInTransaction(array $exportResult, int $deployKey, bool $isFixed, CarbonInterface $now): array
    {
        $sys = DB::connection('sys');
        $nowStr = $now->format('Y-m-d H:i:s');
        $hash = (string) $exportResult['hash'];

        $previousActive = $sys->table('sys_deploy')
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $existing = $sys->table('sys_deploy_master')->where('hash', $hash)->first();

        if ($existing !== null) {
            // R1
            $target = $sys->table('sys_deploy')
                ->where('sys_deploy_master_id', $existing->id)
                ->orderByDesc('id')
                ->first();
            if ($target !== null) {
                $deployId = (int) $target->id;
            } else {
                $assetId = $sys->table('sys_deploy_asset')->where('hash', $hash)->value('id');
                if ($assetId === null) {
                    $assetId = $this->insertAsset($hash, (int) $existing->deploy_key, $now, (int) $existing->deploy_count);
                }
                $deployId = 0;
                $newDeploy = [
                    'deploy_key' => (int) $existing->deploy_key,
                    'start_at' => $nowStr,
                    'sys_deploy_master_id' => $existing->id,
                    'sys_deploy_asset_id' => $assetId,
                ];
            }
            $tableResultArray = [];
            foreach ($sys->table('sys_deploy_master_table')->where('sys_deploy_master_id', $existing->id)->get() as $table) {
                $publicUrl = "/masterdata/{$existing->hash}/{$table->file_name}";
                $sys->table('sys_deploy_master_table')
                    ->where('sys_deploy_master_id', $existing->id)
                    ->where('table_name', (string) $table->table_name)
                    ->update(['public_url' => $publicUrl]);
                $tableResultArray[(string) $table->table_name] = [
                    'hash' => (string) $table->hash,
                    'file_name' => (string) $table->file_name,
                    'file_size' => (int) $table->file_size,
                    'public_url' => $publicUrl,
                ];
            }

            $sys->table('sys_deploy')->update(['is_active' => false, 'updated_at' => $nowStr]);
            if (isset($newDeploy)) {
                $deployId = (int) $sys->table('sys_deploy')->insertGetId($newDeploy + [
                    'is_active' => true, 'created_at' => $nowStr, 'updated_at' => $nowStr,
                ]);
            } else {
                $sys->table('sys_deploy')->where('id', $deployId)->update(['is_active' => true, 'updated_at' => $nowStr]);
            }

            return [
                'sys_deploy_master_id' => (int) $existing->id,
                'sys_deploy_id' => $deployId,
                'deploy_key' => (int) $existing->deploy_key,
                'hash' => $hash,
                'tables' => $tableResultArray,
                'is_new' => false,
                'activated_deploy_id' => $deployId,
                'previous_active_deploy_ids' => $previousActive,
            ];
        }

        // R4: 本物のキーは 1 つの内容に固定（採番は最大 + 1 なので通常は起きない。手で DB を触ったときの守り）
        if (! $isFixed && $sys->table('sys_deploy_master')->where('deploy_key', $deployKey)->exists()) {
            throw MasterDeployKeyException::deployKeyConflict($deployKey);
        }

        // R2・R3（固定キーは deploy_count = 0）
        $deployCount = $isFixed ? 0 : $deployKey % 100;
        $deployMasterId = (int) $sys->table('sys_deploy_master')->insertGetId([
            'deploy_key' => $deployKey,
            'hash' => $hash,
            'deploy_date' => $now->format('Y-m-d'),
            'deploy_count' => $deployCount,
            'status' => 'completed',
            'deployed_by' => 'master_import',
            'deployed_at' => $nowStr,
            'description' => "マスターインポート: {$exportResult['table_count']}テーブル / {$exportResult['file_size']}bytes",
            'created_at' => $nowStr,
            'updated_at' => $nowStr,
        ]);
        foreach ($exportResult['tables'] as $tableName => $tableResult) {
            $sys->table('sys_deploy_master_table')->insert([
                'sys_deploy_master_id' => $deployMasterId,
                'table_name' => $tableName,
                'hash' => $tableResult['hash'],
                'file_size' => $tableResult['file_size'],
                'file_name' => $tableResult['file_name'],
                'public_url' => $tableResult['public_url'],
            ]);
        }
        $assetId = $this->insertAsset($hash, $deployKey, $now, $deployCount);

        $sys->table('sys_deploy')->update(['is_active' => false, 'updated_at' => $nowStr]);
        $deployId = (int) $sys->table('sys_deploy')->insertGetId([
            'deploy_key' => $deployKey,
            'start_at' => $nowStr,
            'sys_deploy_master_id' => $deployMasterId,
            'sys_deploy_asset_id' => $assetId,
            'is_active' => true,
            'created_at' => $nowStr,
            'updated_at' => $nowStr,
        ]);

        return [
            'sys_deploy_master_id' => $deployMasterId,
            'sys_deploy_id' => $deployId,
            'deploy_key' => $deployKey,
            'hash' => $hash,
            'tables' => $exportResult['tables'],
            'is_new' => true,
            'activated_deploy_id' => $deployId,
            'previous_active_deploy_ids' => $previousActive,
        ];
    }

    /** sys_deploy が NOT NULL で参照するダミーの asset 行（hash は master と同じ値） */
    private function insertAsset(string $hash, int $deployKey, CarbonInterface $now, int $deployCount, string $deployedBy = 'master_import'): int
    {
        $nowStr = $now->format('Y-m-d H:i:s');

        return (int) DB::connection('sys')->table('sys_deploy_asset')->insertGetId([
            'deploy_key' => $deployKey,
            'hash' => $hash,
            'deploy_date' => $now->format('Y-m-d'),
            'deploy_count' => $deployCount,
            'status' => 'completed',
            'deployed_by' => $deployedBy,
            'deployed_at' => $nowStr,
            'description' => 'マスターデータのみ（アセットなし）',
            'created_at' => $nowStr,
            'updated_at' => $nowStr,
        ]);
    }

    /**
     * エクスポート結果を sys に登録する
     *
     * sys_deploy_master に新規レコードを作成し、
     * sys_deploy に紐づける（sys_deploy_asset は今回は未使用、NULLではなくダミーIDを使う）
     *
     * @param  array{
     *   hash: string,
     *   file_size: int,
     *   table_count: int,
     *   file_count: int,
     *   tables: array<string, array{hash: string, file_name: string, file_size: int, public_url: string}>,
     * }  $exportResult
     * @return array{
     *   sys_deploy_master_id: int,
     *   sys_deploy_id: int|null,
     *   deploy_key: int,
     *   hash: string,
     *   tables: array<string, array{hash: string, file_name: string, file_size: int, public_url: string}>,
     *   is_new: bool,
     * }
     */
    public function register(array $exportResult): array
    {
        $now = now();
        $today = $now->format('Y-m-d');

        // 同じハッシュが既に登録済みか確認
        $existing = DB::connection('sys')
            ->table('sys_deploy_master')
            ->where('hash', $exportResult['hash'])
            ->first();

        if ($existing) {
            // 既存のデプロイを返す
            $deploy = DB::connection('sys')
                ->table('sys_deploy')
                ->where('sys_deploy_master_id', $existing->id)
                ->orderByDesc('id')
                ->first();
            $tableRows = DB::connection('sys')
                ->table('sys_deploy_master_table')
                ->where('sys_deploy_master_id', $existing->id)
                ->get();

            $tableResultArray = [];
            foreach ($tableRows as $table) {
                $tableName = (string) $table->table_name;
                $publicUrl = "/masterdata/{$existing->hash}/{$table->file_name}";
                DB::connection('sys')
                    ->table('sys_deploy_master_table')
                    ->where('sys_deploy_master_id', $existing->id)
                    ->where('table_name', $tableName)
                    ->update(['public_url' => $publicUrl]);
                $tableResultArray[$tableName] = [
                    'hash' => (string) $table->hash,
                    'file_name' => (string) $table->file_name,
                    'file_size' => (int) $table->file_size,
                    'public_url' => $publicUrl,
                ];
            }

            return [
                'sys_deploy_master_id' => (int) $existing->id,
                'sys_deploy_id' => $deploy !== null ? (int) $deploy->id : null,
                'deploy_key' => (int) $existing->deploy_key,
                'hash' => (string) $existing->hash,
                'tables' => $tableResultArray,
                'is_new' => false,
            ];
        }

        // deploy_key を生成 (YYYYMMDDN形式)
        $deployKey = $this->generateDeployKey($today);

        // deploy_count を取得
        $deployCount = DB::connection('sys')
            ->table('sys_deploy_master')
            ->whereDate('deploy_date', $today)
            ->count() + 1;

        // sys_deploy_master に登録
        $deployMasterId = DB::connection('sys')->table('sys_deploy_master')->insertGetId([
            'deploy_key' => $deployKey,
            'hash' => $exportResult['hash'],
            'deploy_date' => $today,
            'deploy_count' => $deployCount,
            'status' => 'completed',
            'deployed_by' => 'master_import',
            'deployed_at' => $now->format('Y-m-d H:i:s'),
            'description' => "マスターインポート: {$exportResult['table_count']}テーブル / {$exportResult['file_size']}bytes",
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        foreach ($exportResult['tables'] as $tableName => $tableResult) {
            DB::connection('sys')->table('sys_deploy_master_table')->insert([
                'sys_deploy_master_id' => $deployMasterId,
                'table_name' => $tableName,
                'hash' => $tableResult['hash'],
                'file_size' => $tableResult['file_size'],
                'file_name' => $tableResult['file_name'],
                'public_url' => $tableResult['public_url'],
            ]);
        }

        // sys_deploy_asset にもレコードが必要（sys_deploy が NOT NULL で参照するため）
        // 今回はアセットなしなので最小限のレコードを作成
        $deployAssetId = DB::connection('sys')->table('sys_deploy_asset')->insertGetId([
            'deploy_key' => $deployKey,
            'hash' => $exportResult['hash'],
            'deploy_date' => $today,
            'deploy_count' => $deployCount,
            'status' => 'completed',
            'deployed_by' => 'master_import',
            'deployed_at' => $now->format('Y-m-d H:i:s'),
            'description' => 'マスターデータのみ（アセットなし）',
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        // 既存の is_active を全て false にする
        DB::connection('sys')->table('sys_deploy')->update(['is_active' => false]);

        // sys_deploy に登録（即アクティブ化）
        $deployId = DB::connection('sys')->table('sys_deploy')->insertGetId([
            'deploy_key' => $deployKey,
            'start_at' => $now->format('Y-m-d H:i:s'),
            'sys_deploy_master_id' => $deployMasterId,
            'sys_deploy_asset_id' => $deployAssetId,
            'is_active' => true,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        return [
            'sys_deploy_master_id' => $deployMasterId,
            'sys_deploy_id' => $deployId,
            'deploy_key' => $deployKey,
            'hash' => $exportResult['hash'],
            'tables' => $exportResult['tables'],
            'is_new' => true,
        ];
    }

    /**
     * deploy_key を生成する（YYYYMMDDN形式）
     * 例: 2026090401（2026年9月4日の1回目）
     */
    private function generateDeployKey(string $date): int
    {
        $dateInt = (int) str_replace('-', '', $date); // 20260904
        $count = DB::connection('sys')
            ->table('sys_deploy_master')
            ->whereDate('deploy_date', $date)
            ->count() + 1;

        return $dateInt * 100 + $count; // 2026090401
    }
}

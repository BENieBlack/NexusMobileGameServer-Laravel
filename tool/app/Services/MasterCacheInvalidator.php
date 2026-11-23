<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * マスター配信のあとに API 側の Redis キャッシュを破棄する（設計: docs/design/master-distribution/db.md 8 節）
 *
 * キー文字列は api 側のコードが決めており、ここは同じ文字列を持つ。変えるときは両方を直す。
 * 対応: `{cachePrefix}:{テーブル名}:{キー}` を組む packages/nexus-core の `_BaseMstRepository`（cachePrefix = mst、キーは all）と
 * `_BaseSysRepository::buildCacheKey()`（cachePrefix = sys。キーは SysDeployRepository が渡す）。
 * - mst の全件: `mst:{テーブル名}:all`（packages/nexus-core の `_BaseMstRepository`）
 * - sys_deploy: `sys:sys_deploy:latest_downloadable` / `sys:sys_deploy:deploy_key:{キー}` /
 *   `sys:sys_deploy:id:{id}`（api の `SysDeployRepository`）
 *
 * 破棄の失敗（Redis に届かない等の例外）は止めず、失敗したキーを返す。
 * なお Redis の forget は「キーが無かった」ときも false を返すので、false は失敗として扱わない。
 */
class MasterCacheInvalidator
{
    public const SYS_DEPLOY_LATEST_KEY = 'sys:sys_deploy:latest_downloadable';

    /** 実行記録の phase のうち「sys の COMMIT 前後より先へ進んだ」もの（db.md 8 節 M1） */
    public const COMMIT_REACHED_PHASES = ['commit', 'invalidate', 'done'];

    public function __construct(private readonly ?Repository $cache = null) {}

    public static function mstAllKey(string $table): string
    {
        return "mst:{$table}:all";
    }

    public static function sysDeployDeployKeyKey(int $deployKey): string
    {
        return "sys:sys_deploy:deploy_key:{$deployKey}";
    }

    public static function sysDeployIdKey(int $sysDeployId): string
    {
        return "sys:sys_deploy:id:{$sysDeployId}";
    }

    /**
     * 直前の実行の結果から、mst の全表と sys_deploy を破棄すべきかを返す（純粋な判定）
     *
     * 引数は「最後に phase が commit 以降へ進んだ run」（無ければ null）。
     * 無ければ通常（false）。`succeeded` かつ `summary.cache_invalidated = true` なら通常（false）。
     * それ以外（aborted・inconsistent・failed・cache_invalidated が false か無い succeeded）は全破棄（true）。
     *
     * @param  array{status: string, summary?: array<string, mixed>|string|null}|null  $lastCommitReachedRun
     */
    public static function needsFullInvalidation(?array $lastCommitReachedRun): bool
    {
        if ($lastCommitReachedRun === null) {
            return false;
        }
        if ($lastCommitReachedRun['status'] !== 'succeeded') {
            return true;
        }

        $summary = $lastCommitReachedRun['summary'] ?? null;
        if (is_string($summary)) {
            $summary = json_decode($summary, true);
        }

        return ! (is_array($summary) && ($summary['cache_invalidated'] ?? false) === true);
    }

    /**
     * tol_master_deploy_run から「最後に phase が commit 以降へ進んだ run」を探す
     *
     * 直近の run ではない（間に sheet_invalid などの早期失敗の run が挟まっても見落とさない）。
     * 実行中（running）の run は自分自身のことがあるので、呼び出し側が自分の run を作る前に呼ぶか、
     * `$excludeRunId` で除く。
     *
     * @return array{status: string, summary: array<string, mixed>|string|null}|null
     */
    public function findLastCommitReachedRun(?int $excludeRunId = null): ?array
    {
        $query = DB::connection('tool')
            ->table('tol_master_deploy_run')
            ->whereIn('phase', self::COMMIT_REACHED_PHASES)
            ->orderByDesc('id');
        if ($excludeRunId !== null) {
            $query->where('id', '!=', $excludeRunId);
        }

        $row = $query->first(['status', 'summary']);
        if ($row === null) {
            return null;
        }

        return ['status' => (string) $row->status, 'summary' => $row->summary];
    }

    /**
     * 今回、全破棄が要るか（実行記録を読んで判定する）
     */
    public function shouldInvalidateAll(?int $excludeRunId = null): bool
    {
        return self::needsFullInvalidation($this->findLastCommitReachedRun($excludeRunId));
    }

    /**
     * 反映の結果（表ごとの件数）から、キャッシュを破棄する表を選ぶ
     *
     * `inserted`・`updated`・`deleted` のどれかが 0 でない表だけ（db.md 8 節）。
     *
     * @param  array<string, array{inserted?: int, updated?: int, deleted?: int}>  $tableSummary  表名 => 件数
     * @return list<string>
     */
    public static function changedTables(array $tableSummary): array
    {
        $changed = [];
        foreach ($tableSummary as $table => $counts) {
            if (($counts['inserted'] ?? 0) > 0 || ($counts['updated'] ?? 0) > 0 || ($counts['deleted'] ?? 0) > 0) {
                $changed[] = $table;
            }
        }

        return $changed;
    }

    /**
     * mst 接続の `mst_*` テーブル名の一覧（`__l10n` を含む）
     *
     * @return list<string>
     */
    public function listMstTables(): array
    {
        $tables = [];
        foreach (DB::connection('mst')->select("SHOW TABLES LIKE 'mst\\_%'") as $row) {
            $name = (string) array_values((array) $row)[0];
            if (str_starts_with($name, 'mst_')) {
                $tables[] = $name;
            }
        }
        sort($tables);

        return $tables;
    }

    /**
     * 有効な（is_active）sys_deploy 行の deploy_key と id（clear-cache と全破棄で使う）
     *
     * @return array{deploy_key_list: list<int>, id_list: list<int>}
     */
    public function selectActiveSysDeploy(): array
    {
        $rowList = DB::connection('sys')
            ->table('sys_deploy')
            ->where('is_active', true)
            ->get(['id', 'deploy_key']);

        $deployKeyList = [];
        $idList = [];
        foreach ($rowList as $row) {
            $deployKeyList[] = (int) $row->deploy_key;
            $idList[] = (int) $row->id;
        }

        return ['deploy_key_list' => array_values(array_unique($deployKeyList)), 'id_list' => $idList];
    }

    /**
     * 通常の破棄: 変更のあった mst の表と、sys_deploy の 3 種
     *
     * @param  list<string>  $changedMstTableList  `changedTables()` の結果
     * @param  list<int>  $deployKeyList  登録した（または有効に戻した）行と、有効・無効が変わった行の deploy_key
     * @param  list<int>  $sysDeployIdList  同じ行の id
     * @return array{invalidated: bool, failed_key_list: list<string>}
     */
    public function invalidate(array $changedMstTableList, array $deployKeyList, array $sysDeployIdList): array
    {
        return $this->forgetAll($this->buildKeyList($changedMstTableList, $deployKeyList, $sysDeployIdList));
    }

    /**
     * 全破棄: mst の全表（`mst_%`）と、有効な sys_deploy 行の 3 種
     *
     * 全破棄が要るとき（`shouldInvalidateAll()`）と、`POST /master-import/clear-cache` で使う。
     *
     * @param  list<int>  $extraDeployKeyList  今回登録した行など、is_active に頼らず足したい deploy_key
     * @param  list<int>  $extraSysDeployIdList  同じ行の id
     * @return array{invalidated: bool, failed_key_list: list<string>}
     */
    public function invalidateAll(array $extraDeployKeyList = [], array $extraSysDeployIdList = []): array
    {
        $active = $this->selectActiveSysDeploy();

        return $this->forgetAll($this->buildKeyList(
            $this->listMstTables(),
            array_merge($active['deploy_key_list'], $extraDeployKeyList),
            array_merge($active['id_list'], $extraSysDeployIdList),
        ));
    }

    /**
     * 破棄するキーの一覧を作る（重複なし。順序は sys_deploy → mst）
     *
     * @param  list<string>  $mstTableList
     * @param  list<int>  $deployKeyList
     * @param  list<int>  $sysDeployIdList
     * @return list<string>
     */
    public function buildKeyList(array $mstTableList, array $deployKeyList, array $sysDeployIdList): array
    {
        $keyList = [self::SYS_DEPLOY_LATEST_KEY];
        foreach (array_unique($deployKeyList) as $deployKey) {
            $keyList[] = self::sysDeployDeployKeyKey($deployKey);
        }
        foreach (array_unique($sysDeployIdList) as $sysDeployId) {
            $keyList[] = self::sysDeployIdKey($sysDeployId);
        }
        foreach (array_unique($mstTableList) as $table) {
            $keyList[] = self::mstAllKey($table);
        }

        return $keyList;
    }

    /**
     * @param  list<string>  $keyList
     * @return array{invalidated: bool, failed_key_list: list<string>}
     */
    private function forgetAll(array $keyList): array
    {
        $store = $this->cache ?? Cache::store('redis');
        $failed = [];
        foreach ($keyList as $key) {
            try {
                $store->forget($key);
            } catch (Throwable) {
                $failed[] = $key;
            }
        }

        return ['invalidated' => $failed === [], 'failed_key_list' => $failed];
    }
}

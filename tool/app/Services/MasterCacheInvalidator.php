<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * マスター配信のあとに API 側の Redis キャッシュを破棄する（版ごとの mst DB 設計。設計: docs/design/master-distribution/db.md 8.3・api.md 9.4）
 *
 * mst のキャッシュは破棄しない（キーに版の DB 名が入り、版の DB は書き換えないため。db.md 8.4）。破棄するのは sys_deploy の
 * 切り替え予定の一覧（schedule キー）だけ。キー文字列は api 側のコードが決めており、ここは同じ文字列を持つ。変えるときは両方を直す。
 * - `sys:sys_deploy:schedule`（api の `SysDeployRepository::scheduleCacheKey`）
 * - `clear-cache` だけ、緊急停止の運用のために `sys:sys_deploy:id:{id}`・`sys:sys_deploy:deploy_key:{キー}`・`sys:sys_deploy_master:{id}:tables` と
 *   旧キー `sys:sys_deploy:latest_downloadable`（廃止。切り替えの初回に Redis に残るぶんを消す。db.md 8.3）も破棄する
 *
 * 破棄の失敗（Redis に届かない等の例外）は止めず、失敗したキーを返す。
 * なお Redis の forget は「キーが無かった」ときも false を返すので、false は失敗として扱わない。
 */
class MasterCacheInvalidator
{
    /** 切り替え予定の一覧（api の SysDeployRepository::scheduleCacheKey。db.md 8.3） */
    public const SYS_DEPLOY_SCHEDULE_KEY = 'sys:sys_deploy:schedule';

    /** 旧キー（廃止。api は読まない）。clear-cache だけが、切り替えの初回に残りを消す */
    private const LEGACY_LATEST_KEY = 'sys:sys_deploy:latest_downloadable';

    public function __construct(private readonly ?Repository $cache = null) {}

    public static function sysDeployDeployKeyKey(int $deployKey): string
    {
        return "sys:sys_deploy:deploy_key:{$deployKey}";
    }

    public static function sysDeployIdKey(int $sysDeployId): string
    {
        return "sys:sys_deploy:id:{$sysDeployId}";
    }

    public static function sysDeployMasterTablesKey(int $sysDeployMasterId): string
    {
        return "sys:sys_deploy_master:{$sysDeployMasterId}:tables";
    }

    /**
     * 切り替え予定の一覧（`sys:sys_deploy:schedule`）だけを破棄する（登録・巻き戻し・取り消し・停止・再開の後。mst のキャッシュは破棄しない）
     *
     * @return array{invalidated: bool, failed_key_list: list<string>}
     */
    public function forgetSchedule(): array
    {
        return $this->forgetAll([self::SYS_DEPLOY_SCHEDULE_KEY]);
    }

    /**
     * clear-cache（api.md 9.4）: schedule キーと旧キーを先に、続けて id・deploy_key・tables のキーを破棄する（列挙で DB が失敗したら例外。固定のキーは破棄済み）
     *
     * @return array{invalidated: bool, failed_key_list: list<string>}
     */
    public function forgetScheduleAndRelated(): array
    {
        // 固定のキー（schedule・旧キー）を先に破棄する。後の id・deploy_key・tables の列挙で DB が落ちていても、これは消える
        $fixed = $this->forgetAll([self::SYS_DEPLOY_SCHEDULE_KEY, self::LEGACY_LATEST_KEY]);

        $sys = DB::connection('sys');
        $keyList = [];
        foreach ($sys->table('sys_deploy')->get(['id', 'deploy_key']) as $row) {
            $keyList[] = self::sysDeployIdKey((int) $row->id);
            $keyList[] = self::sysDeployDeployKeyKey((int) $row->deploy_key);
        }
        foreach ($sys->table('sys_deploy_master')->pluck('id') as $id) {
            $keyList[] = self::sysDeployMasterTablesKey((int) $id);
        }

        $related = $this->forgetAll(array_values(array_unique($keyList)));

        return [
            'invalidated' => $fixed['invalidated'] && $related['invalidated'],
            'failed_key_list' => [...$fixed['failed_key_list'], ...$related['failed_key_list']],
        ];
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

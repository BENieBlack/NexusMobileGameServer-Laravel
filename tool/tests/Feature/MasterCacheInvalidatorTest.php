<?php

namespace Tests\Feature;

use App\Services\MasterCacheInvalidator;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * MasterCacheInvalidator（schedule キーなどの破棄。api.md 9.4・db.md 8.3）のテスト。mst のキャッシュは破棄しない
 */
class MasterCacheInvalidatorTest extends MasterVersionedTestCase
{
    private function store(): Repository
    {
        return Cache::store('array');
    }

    #[Test]
    public function キーの形がapiと同じ(): void
    {
        $this->assertSame('sys:sys_deploy:schedule', MasterCacheInvalidator::SYS_DEPLOY_SCHEDULE_KEY);
        $this->assertSame('sys:sys_deploy:deploy_key:2026010101', MasterCacheInvalidator::sysDeployDeployKeyKey(2026010101));
        $this->assertSame('sys:sys_deploy:id:7', MasterCacheInvalidator::sysDeployIdKey(7));
        $this->assertSame('sys:sys_deploy_master:3:tables', MasterCacheInvalidator::sysDeployMasterTablesKey(3));
    }

    #[Test]
    public function forget_scheduleはscheduleキーだけを消し_mstのキーは触らない(): void
    {
        $store = $this->store();
        foreach (['sys:sys_deploy:schedule', 'sys:sys_deploy:id:1', 'mst:db:mst_item:all'] as $key) {
            $store->put($key, 'x', 60);
        }

        $result = (new MasterCacheInvalidator($store))->forgetSchedule();

        $this->assertSame(['invalidated' => true, 'failed_key_list' => []], $result);
        $this->assertFalse($store->has('sys:sys_deploy:schedule'));
        $this->assertTrue($store->has('sys:sys_deploy:id:1'));
        $this->assertTrue($store->has('mst:db:mst_item:all'));
    }

    #[Test]
    public function clear_cache用はschedule_id_deploy_key_tables_旧キーを消し_mstのキーは残す(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $store = $this->store();
        $keys = [
            'sys:sys_deploy:schedule',
            'sys:sys_deploy:latest_downloadable',
            MasterCacheInvalidator::sysDeployIdKey((int) $a['sys_deploy_id']),
            MasterCacheInvalidator::sysDeployDeployKeyKey(2026112201),
            MasterCacheInvalidator::sysDeployMasterTablesKey((int) $a['sys_deploy_master_id']),
        ];
        foreach ($keys as $key) {
            $store->put($key, 'x', 60);
        }
        $store->put('mst:db:mst_item:all', 'x', 60);
        $store->put('sys:sys_deploy:id:999', 'x', 60);

        $result = (new MasterCacheInvalidator($store))->forgetScheduleAndRelated();

        $this->assertTrue($result['invalidated']);
        foreach ($keys as $key) {
            $this->assertFalse($store->has($key), $key);
        }
        $this->assertTrue($store->has('mst:db:mst_item:all'));
        $this->assertTrue($store->has('sys:sys_deploy:id:999'));
        $this->assertSame(1, DB::connection('sys')->table('sys_deploy')->count());
    }

    #[Test]
    public function 破棄に失敗したキーを返し_処理は続ける(): void
    {
        $store = Mockery::mock(Repository::class);
        $store->shouldReceive('forget')->with('sys:sys_deploy:schedule')->once()->andThrow(new RuntimeException('redis down'));
        $store->shouldReceive('forget')->with('sys:sys_deploy:latest_downloadable')->once()->andReturn(true);

        $result = (new MasterCacheInvalidator($store))->forgetScheduleAndRelated();

        $this->assertFalse($result['invalidated']);
        $this->assertSame(['sys:sys_deploy:schedule'], $result['failed_key_list']);
    }
}

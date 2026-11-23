<?php

namespace Tests\Feature;

use App\Services\MasterCacheInvalidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Redis;
use Tests\TestCase;

/**
 * MasterCacheInvalidator が消す実キー（schedule・id・deploy_key・tables・旧キー）が、api のリポジトリが書く実キーと一致することの確認（docs/design/master-distribution/db.md の 8 節）
 *
 * api の `api/tests/Feature/Repositories/MasterCacheKeyContractTest.php` が、api のリポジトリで書いたキーを
 * 同じ固定文字列で確かめている。ここは同じ文字列を接頭辞なしの生の接続で Redis に置き、
 * 本物の Redis ストア経由の破棄で消えることを確かめる。2 つのテストが同じ文字列を持つことで契約になる。
 * 変えるときは両方（と MasterCacheInvalidator）を直す。
 * api と tool のテストを並行実行しない（どちらも Redis の DB 15 を flush するため）。
 *
 * 限界: api と tool はコンテナが別なので、1 つのテストで両方のコードを走らせてはいない。
 * 両方の phpunit.xml が同じ Redis・DB 15・APP_NAME=nexus を指す前提（下の assert で tool 側を確認する）。
 */
class MasterCacheKeyContractTest extends TestCase
{
    /** REDIS_PREFIX + CACHE_PREFIX（APP_NAME=nexus の既定） */
    private const PHYSICAL_PREFIX = 'nexus-database-nexus-cache-';

    private const CACHE_DB = 15;

    private Redis $raw;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(self::CACHE_DB, (int) config('database.redis.cache.database'));
        $this->assertSame('nexus-database-', config('database.redis.options.prefix'));
        $this->assertSame('nexus-cache-', config('cache.prefix'));
        $this->assertSame('cache', config('cache.stores.redis.connection'));

        $this->raw = new Redis;
        $this->raw->connect((string) config('database.redis.cache.host'), (int) config('database.redis.cache.port'));
        $this->raw->select(self::CACHE_DB);
        $this->raw->flushDB();
    }

    protected function tearDown(): void
    {
        $this->raw->flushDB();
        $this->raw->close();

        parent::tearDown();
    }

    #[Test]
    public function 破棄がapiの書く実キーを消し_関係のないキーは残す(): void
    {
        $physicalKeyList = [
            'sys:sys_deploy:schedule',
            'sys:sys_deploy:latest_downloadable',
            'sys:sys_deploy:deploy_key:2026010101',
            'sys:sys_deploy:id:7',
            'sys:sys_deploy_master:3:tables',
        ];
        foreach ($physicalKeyList as $key) {
            $this->raw->set(self::PHYSICAL_PREFIX.$key, 'x');
        }
        // mst のキャッシュは破棄しない（キーに版の DB 名が入る。db.md 8.4）
        $this->raw->set(self::PHYSICAL_PREFIX.'mst:nexus-testing-master:mst_item:all', 'x');
        $this->raw->set(self::PHYSICAL_PREFIX.'sys:sys_deploy:id:999', 'x');

        // clear-cache が調べる sys は、最小のテーブルだけをメモリの SQLite に作る
        config(['database.connections.sys' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('sys');
        Schema::connection('sys')->create('sys_deploy', function ($t) {
            $t->unsignedBigInteger('id');
            $t->bigInteger('deploy_key');
        });
        Schema::connection('sys')->create('sys_deploy_master', function ($t) {
            $t->unsignedBigInteger('id');
        });
        DB::connection('sys')->table('sys_deploy')->insert(['id' => 7, 'deploy_key' => 2026010101]);
        DB::connection('sys')->table('sys_deploy_master')->insert(['id' => 3]);

        $result = (new MasterCacheInvalidator)->forgetScheduleAndRelated();

        $this->assertTrue($result['invalidated']);
        foreach ($physicalKeyList as $key) {
            $this->assertSame(0, $this->raw->exists(self::PHYSICAL_PREFIX.$key), $key);
        }
        $this->assertSame(1, $this->raw->exists(self::PHYSICAL_PREFIX.'mst:nexus-testing-master:mst_item:all'));
        $this->assertSame(1, $this->raw->exists(self::PHYSICAL_PREFIX.'sys:sys_deploy:id:999'));
        DB::purge('sys');
    }

    #[Test]
    public function forget_scheduleはscheduleキーだけを消す(): void
    {
        $this->raw->set(self::PHYSICAL_PREFIX.'sys:sys_deploy:schedule', 'x');
        $this->raw->set(self::PHYSICAL_PREFIX.'sys:sys_deploy:id:7', 'x');

        $this->assertTrue((new MasterCacheInvalidator)->forgetSchedule()['invalidated']);

        $this->assertSame(0, $this->raw->exists(self::PHYSICAL_PREFIX.'sys:sys_deploy:schedule'));
        $this->assertSame(1, $this->raw->exists(self::PHYSICAL_PREFIX.'sys:sys_deploy:id:7'));
    }

    #[Test]
    public function 固定文字列が組み立て関数と一致する(): void
    {
        // api の SysDeployRepository::scheduleCacheKey() と同じ文字列（api 側の契約テストが固定）
        $this->assertSame('sys:sys_deploy:schedule', MasterCacheInvalidator::SYS_DEPLOY_SCHEDULE_KEY);
        $this->assertSame('sys:sys_deploy:deploy_key:2026010101', MasterCacheInvalidator::sysDeployDeployKeyKey(2026010101));
        $this->assertSame('sys:sys_deploy:id:7', MasterCacheInvalidator::sysDeployIdKey(7));
        $this->assertSame('sys:sys_deploy_master:3:tables', MasterCacheInvalidator::sysDeployMasterTablesKey(3));
    }
}

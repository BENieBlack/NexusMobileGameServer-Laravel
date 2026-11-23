<?php

namespace Tests\Feature;

use App\Services\MasterCacheInvalidator;
use PHPUnit\Framework\Attributes\Test;
use Redis;
use Tests\TestCase;

/**
 * MasterCacheInvalidator が消す実キーが、api のリポジトリが書く実キーと一致することの確認（docs/design/master-distribution/db.md の 8 節）
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
            'mst:mst_item:all',
            'mst:mst_item__l10n:all',
            'sys:sys_deploy:latest_downloadable',
            'sys:sys_deploy:deploy_key:2026010101',
            'sys:sys_deploy:id:7',
        ];
        foreach ($physicalKeyList as $key) {
            $this->raw->set(self::PHYSICAL_PREFIX.$key, 'x');
        }
        $this->raw->set(self::PHYSICAL_PREFIX.'mst:mst_other:all', 'x');

        $result = (new MasterCacheInvalidator)->invalidate(['mst_item', 'mst_item__l10n'], [2026010101], [7]);

        $this->assertTrue($result['invalidated']);
        foreach ($physicalKeyList as $key) {
            $this->assertSame(0, $this->raw->exists(self::PHYSICAL_PREFIX.$key), $key);
        }
        $this->assertSame(1, $this->raw->exists(self::PHYSICAL_PREFIX.'mst:mst_other:all'));
    }

    #[Test]
    public function 固定文字列が組み立て関数と一致する(): void
    {
        $this->assertSame('mst:mst_item:all', MasterCacheInvalidator::mstAllKey('mst_item'));
        $this->assertSame('sys:sys_deploy:latest_downloadable', MasterCacheInvalidator::SYS_DEPLOY_LATEST_KEY);
        $this->assertSame('sys:sys_deploy:deploy_key:2026010101', MasterCacheInvalidator::sysDeployDeployKeyKey(2026010101));
        $this->assertSame('sys:sys_deploy:id:7', MasterCacheInvalidator::sysDeployIdKey(7));
    }
}

<?php

namespace Tests\Feature\Repositories;

use App\Repositories\Mst\MstItemRepository;
use App\Repositories\Sys\SysDeployRepository;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Redis;
use Tests\RefreshMultipleDatabases;
use Tests\Support\SysDeployMasterColumns;
use Tests\TestCase;

/**
 * api のリポジトリが Redis に書く実キーを固定する（tool の MasterCacheInvalidator との契約。設計: docs/design/master-distribution/db.md の 8 節）
 *
 * tool 側の `tool/tests/Feature/MasterCacheKeyContractTest.php` が同じ文字列を期待値に持つ。
 * 変えるときは両方（と MasterCacheInvalidator）を直す。
 * 接頭辞を足さない生の接続で読むので、接続の DB 番号と、Redis の接頭辞・キャッシュの接頭辞まで含めた実キーを見る。
 */
class MasterCacheKeyContractTest extends TestCase
{
    use RefreshMultipleDatabases;

    /** phpunit.xml の APP_NAME=nexus から決まる接頭辞（REDIS_PREFIX + CACHE_PREFIX） */
    private const PHYSICAL_PREFIX = 'nexus-database-nexus-cache-';

    /** phpunit.xml の REDIS_CACHE_DB */
    private const CACHE_DB = 15;

    private const DEPLOY_KEY = 2026010101;

    private int $sysDeployId = 0;

    protected function tearDown(): void
    {
        foreach (['sys_deploy', 'sys_deploy_master_table', 'sys_deploy_master', 'sys_deploy_asset'] as $table) {
            DB::connection('sys')->table($table)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function mstリポジトリはmst_表名_allをキャッシュ用_d_bへ書く(): void
    {
        (new MstItemRepository)->queryOrMemory();

        $this->assertContains(self::PHYSICAL_PREFIX.'mst:mst_item:all', $this->physicalKeyList());
    }

    #[Test]
    public function sys_deployリポジトリは3種のキーをキャッシュ用_d_bへ書く(): void
    {
        $this->insertDeploy();

        $repository = new SysDeployRepository;
        $repository->selectLatestDownloadable();
        $repository->selectByDeployKey(self::DEPLOY_KEY);
        $repository->selectById($this->sysDeployId);

        $keyList = $this->physicalKeyList();
        $this->assertContains(self::PHYSICAL_PREFIX.'sys:sys_deploy:latest_downloadable', $keyList);
        $this->assertContains(self::PHYSICAL_PREFIX.'sys:sys_deploy:deploy_key:'.self::DEPLOY_KEY, $keyList);
        $this->assertContains(self::PHYSICAL_PREFIX.'sys:sys_deploy:id:'.$this->sysDeployId, $keyList);
    }

    /**
     * @return list<string>
     */
    private function physicalKeyList(): array
    {
        $redis = new Redis;
        $redis->connect((string) config('database.redis.cache.host'), (int) config('database.redis.cache.port'));
        $redis->select((int) config('database.redis.cache.database'));
        // 前提の確認（設定が変わって別の DB を見ていたら、ここで気づく）
        $this->assertSame(self::CACHE_DB, (int) config('database.redis.cache.database'));

        /** @var list<string> $keyList */
        $keyList = $redis->keys('*');
        $redis->close();

        return $keyList;
    }

    private function insertDeploy(): void
    {
        $now = now();
        $masterId = DB::connection('sys')->table('sys_deploy_master')->insertGetId([
            'deploy_key' => self::DEPLOY_KEY,
            'hash' => hash('sha256', 'cache-contract-master'),
            ...SysDeployMasterColumns::for(self::DEPLOY_KEY, 'cache-contract-master'),
            'deploy_date' => '2026-01-01',
            'deploy_count' => 1,
            'status' => 'completed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $assetId = DB::connection('sys')->table('sys_deploy_asset')->insertGetId([
            'deploy_key' => self::DEPLOY_KEY,
            'hash' => hash('sha256', 'cache-contract-asset'),
            'deploy_date' => '2026-01-01',
            'deploy_count' => 1,
            'status' => 'completed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->sysDeployId = DB::connection('sys')->table('sys_deploy')->insertGetId([
            'deploy_key' => self::DEPLOY_KEY,
            'start_at' => now()->subMinute(),
            'sys_deploy_master_id' => $masterId,
            'sys_deploy_asset_id' => $assetId,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

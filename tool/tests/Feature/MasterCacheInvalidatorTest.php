<?php

namespace Tests\Feature;

use App\Services\MasterCacheInvalidator;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * MasterCacheInvalidator のテスト（db.md 8 節）
 *
 * tool スキーマ（tol_*）は CI に無いので、tool 接続だけ SQLite のメモリに差し替えて
 * tol_master_deploy_run を最小の列で作る（開発用の tool DB には触れない）。
 * mst・sys は phpunit.xml のテスト用 DB に、必要なテーブルだけ作って消す。
 */
class MasterCacheInvalidatorTest extends TestCase
{
    private bool $createdSysDeploy = false;

    private bool $createdMstTables = false;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.tool', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('tool');
        Schema::connection('tool')->create('tol_master_deploy_run', function ($table) {
            $table->id();
            $table->string('status');
            $table->string('phase');
            $table->text('summary')->nullable();
        });
    }

    protected function tearDown(): void
    {
        if ($this->createdMstTables) {
            DB::connection('mst')->statement('DROP TABLE IF EXISTS mst_cache_test_a');
            DB::connection('mst')->statement('DROP TABLE IF EXISTS mst_cache_test_a__l10n');
        }
        if ($this->createdSysDeploy) {
            DB::connection('sys')->statement('DROP TABLE IF EXISTS sys_deploy');
        }
        DB::purge('tool');

        parent::tearDown();
    }

    private function newStore(): Repository
    {
        return new Repository(new ArrayStore);
    }

    private function insertRun(string $status, string $phase, ?array $summary = null): void
    {
        DB::connection('tool')->table('tol_master_deploy_run')->insert([
            'status' => $status,
            'phase' => $phase,
            'summary' => $summary === null ? null : json_encode($summary),
        ]);
    }

    /**
     * @return array<string, array{0: array{status: string, summary?: mixed}|null, 1: bool}>
     */
    public static function judgementProvider(): array
    {
        return [
            'run が無ければ通常' => [null, false],
            'succeeded かつ破棄成功なら通常' => [['status' => 'succeeded', 'summary' => ['cache_invalidated' => true]], false],
            'summary が JSON 文字列でも読める' => [['status' => 'succeeded', 'summary' => '{"cache_invalidated":true}'], false],
            'succeeded でも破棄失敗なら全破棄' => [['status' => 'succeeded', 'summary' => ['cache_invalidated' => false]], true],
            'succeeded で summary が無ければ全破棄' => [['status' => 'succeeded', 'summary' => null], true],
            'aborted なら全破棄' => [['status' => 'aborted', 'summary' => null], true],
            'inconsistent なら全破棄' => [['status' => 'inconsistent', 'summary' => null], true],
            'failed なら全破棄' => [['status' => 'failed', 'summary' => ['cache_invalidated' => true]], true],
        ];
    }

    /**
     * @param  array{status: string, summary?: mixed}|null  $run
     */
    #[Test]
    #[DataProvider('judgementProvider')]
    public function 全破棄が要るかの判定(?array $run, bool $expected): void
    {
        $this->assertSame($expected, MasterCacheInvalidator::needsFullInvalidation($run));
    }

    #[Test]
    public function commit以降へ進んだ最後のrunを探し_早期失敗のrunは無視する(): void
    {
        $this->insertRun('aborted', 'commit');
        $this->insertRun('succeeded', 'done', ['cache_invalidated' => true]);
        // 間に挟まった早期失敗（phase が commit に届いていない）
        $this->insertRun('failed', 'validate');

        $invalidator = new MasterCacheInvalidator($this->newStore());
        $run = $invalidator->findLastCommitReachedRun();

        $this->assertSame('succeeded', $run['status'] ?? null);
        $this->assertFalse($invalidator->shouldInvalidateAll());
    }

    #[Test]
    public function 最後のcommit以降のrunがabortedなら全破棄_早期失敗が後ろにあっても変わらない(): void
    {
        $this->insertRun('succeeded', 'done', ['cache_invalidated' => true]);
        $this->insertRun('aborted', 'invalidate');
        $this->insertRun('failed', 'lock');

        $this->assertTrue((new MasterCacheInvalidator($this->newStore()))->shouldInvalidateAll());
    }

    #[Test]
    public function commitに届いたrunが1件も無ければ通常(): void
    {
        $this->insertRun('failed', 'validate');

        $this->assertFalse((new MasterCacheInvalidator($this->newStore()))->shouldInvalidateAll());
    }

    #[Test]
    public function 自分のrunを除いて判定できる(): void
    {
        $this->insertRun('succeeded', 'done', ['cache_invalidated' => true]);
        $this->insertRun('running', 'commit');
        $selfId = (int) DB::connection('tool')->table('tol_master_deploy_run')->max('id');

        $invalidator = new MasterCacheInvalidator($this->newStore());

        $this->assertTrue($invalidator->shouldInvalidateAll());
        $this->assertFalse($invalidator->shouldInvalidateAll($selfId));
    }

    #[Test]
    public function 変更のあった表だけを選ぶ(): void
    {
        $changed = MasterCacheInvalidator::changedTables([
            'mst_a' => ['inserted' => 0, 'updated' => 0, 'deleted' => 0],
            'mst_b' => ['inserted' => 1, 'updated' => 0, 'deleted' => 0],
            'mst_c__l10n' => ['inserted' => 0, 'updated' => 0, 'deleted' => 2],
            'mst_d' => ['updated' => 3],
        ]);

        $this->assertSame(['mst_b', 'mst_c__l10n', 'mst_d'], $changed);
    }

    #[Test]
    public function キーの形がapiと同じ(): void
    {
        $invalidator = new MasterCacheInvalidator($this->newStore());

        $this->assertSame(
            [
                'sys:sys_deploy:schedule',
                'sys:sys_deploy:latest_downloadable',
                'sys:sys_deploy:deploy_key:2026010101',
                'sys:sys_deploy:id:7',
                MasterCacheInvalidator::mstAllKey('mst_item'),
                MasterCacheInvalidator::mstAllKey('mst_item__l10n'),
            ],
            $invalidator->buildKeyList(['mst_item', 'mst_item__l10n', 'mst_item'], [2026010101, 2026010101], [7]),
        );
    }

    #[Test]
    public function 通常の破棄は指定したキーだけを消す(): void
    {
        $store = $this->newStore();
        foreach ([MasterCacheInvalidator::mstAllKey('mst_a'), MasterCacheInvalidator::mstAllKey('mst_b'), 'sys:sys_deploy:latest_downloadable', 'sys:sys_deploy:deploy_key:1', 'sys:sys_deploy:id:9'] as $key) {
            $store->put($key, 'x', 3600);
        }

        $result = (new MasterCacheInvalidator($store))->invalidate(['mst_a'], [1], [9]);

        $this->assertSame(['invalidated' => true, 'failed_key_list' => []], $result);
        $this->assertFalse($store->has(MasterCacheInvalidator::mstAllKey('mst_a')));
        $this->assertFalse($store->has('sys:sys_deploy:latest_downloadable'));
        $this->assertFalse($store->has('sys:sys_deploy:deploy_key:1'));
        $this->assertFalse($store->has('sys:sys_deploy:id:9'));
        $this->assertTrue($store->has(MasterCacheInvalidator::mstAllKey('mst_b')));
    }

    #[Test]
    public function 全破棄はmstの全表と有効なsys_deployの3種を消す(): void
    {
        // 既存の表を壊さないため、sys_deploy が既にある環境（api のマイグレーション済み等）では常にスキップする
        if (Schema::connection('sys')->hasTable('sys_deploy')) {
            $this->markTestSkipped('sys_deploy が既にあるため、テスト用の表を作らない');
        }
        $this->createdSysDeploy = true;
        $this->createdMstTables = true;
        DB::connection('mst')->statement('CREATE TABLE IF NOT EXISTS mst_cache_test_a (id VARCHAR(255) NOT NULL PRIMARY KEY)');
        DB::connection('mst')->statement('CREATE TABLE IF NOT EXISTS mst_cache_test_a__l10n (mst_cache_test_a_id VARCHAR(255) NOT NULL PRIMARY KEY)');
        DB::connection('sys')->statement('CREATE TABLE sys_deploy (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, deploy_key BIGINT NOT NULL, is_active TINYINT(1) NOT NULL)');
        DB::connection('sys')->table('sys_deploy')->insert([
            ['id' => 1, 'deploy_key' => 2026010101, 'is_active' => 0],
            ['id' => 2, 'deploy_key' => 2026010102, 'is_active' => 1],
        ]);

        $store = $this->newStore();
        $keys = [MasterCacheInvalidator::mstAllKey('mst_cache_test_a'), MasterCacheInvalidator::mstAllKey('mst_cache_test_a__l10n'), 'sys:sys_deploy:latest_downloadable', 'sys:sys_deploy:deploy_key:2026010102', 'sys:sys_deploy:id:2', 'sys:sys_deploy:id:1', MasterCacheInvalidator::mstAllKey('other')];
        foreach ($keys as $key) {
            $store->put($key, 'x', 3600);
        }

        $result = (new MasterCacheInvalidator($store))->invalidateAll();

        $this->assertTrue($result['invalidated']);
        foreach (array_slice($keys, 0, 5) as $key) {
            $this->assertFalse($store->has($key), $key);
        }
        // 無効な行の id と、mst に無い表のキーは対象外
        $this->assertTrue($store->has('sys:sys_deploy:id:1'));
        $this->assertTrue($store->has(MasterCacheInvalidator::mstAllKey('other')));
    }

    #[Test]
    public function 破棄に失敗したキーを返し_処理は続ける(): void
    {
        $store = new class(new ArrayStore) extends Repository
        {
            public function forget($key): bool
            {
                if ($key === MasterCacheInvalidator::mstAllKey('mst_a')) {
                    throw new RuntimeException('redis down');
                }

                return parent::forget($key);
            }
        };
        $store->put(MasterCacheInvalidator::mstAllKey('mst_b'), 'x', 3600);

        $result = (new MasterCacheInvalidator($store))->invalidate(['mst_a', 'mst_b'], [], []);

        $this->assertFalse($result['invalidated']);
        $this->assertSame([MasterCacheInvalidator::mstAllKey('mst_a')], $result['failed_key_list']);
        $this->assertFalse($store->has(MasterCacheInvalidator::mstAllKey('mst_b')));
    }
}

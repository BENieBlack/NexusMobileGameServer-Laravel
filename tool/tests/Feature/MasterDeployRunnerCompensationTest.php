<?php

namespace Tests\Feature;

use App\Exceptions\MasterDeployKeyException;
use App\Exceptions\MasterDeployRunException;
use App\Services\GoogleSpreadsheetService;
use App\Services\MasterCacheInvalidator;
use App\Services\MasterDataExporter;
use App\Services\MasterDeployKeyPolicy;
use App\Services\MasterDeployLock;
use App\Services\MasterDeployRunner;
use App\Services\MasterDeployService;
use App\Services\MasterImportService;
use App\Services\MasterSheetValidator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * MasterDeployRunner の補償（S3-12b。sequence.md 5.4・5.5・5.6・6 節）のテスト
 *
 * tool・sys は SQLite のメモリで、MasterDeployService::compensate() は実物（sys_deploy の is_active を実際に確かめる）。
 * Google・mst・Redis・書き出しはモック。registerDeploy だけは sys_deploy を動かす疑似実装に差し替える。
 */
class MasterDeployRunnerCompensationTest extends TestCase
{
    private const KEY = 2026112201;

    private MasterDeployLock&MockInterface $lock;

    private MasterImportService&MockInterface $import;

    private MasterDataExporter&MockInterface $exporter;

    private MasterDeployService&MockInterface $deploy;

    private MasterCacheInvalidator&MockInterface $invalidator;

    private ConnectionInterface&MockInterface $mst;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['tool', 'sys'] as $name) {
            Config::set("database.connections.{$name}", ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
            DB::purge($name);
        }
        Schema::connection('tool')->create('tol_master_deploy_run', function ($t) {
            $t->id();
            $t->string('mode');
            $t->bigInteger('deploy_key')->nullable();
            $t->string('status');
            $t->string('phase');
            $t->unsignedBigInteger('adm_account_id')->nullable();
            $t->string('app_env', 32);
            $t->string('master_hash', 64)->nullable();
            $t->unsignedBigInteger('sys_deploy_master_id')->nullable();
            $t->unsignedBigInteger('sys_deploy_id')->nullable();
            $t->boolean('is_new')->nullable();
            $t->text('summary')->nullable();
            $t->string('error_code', 50)->nullable();
            $t->text('error_message')->nullable();
            $t->dateTime('started_at');
            $t->dateTime('finished_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
        Schema::connection('sys')->create('sys_deploy', function ($t) {
            $t->id();
            $t->bigInteger('deploy_key');
            $t->boolean('is_active')->default(false);
            $t->dateTime('updated_at')->nullable();
        });

        $this->lock = Mockery::mock(MasterDeployLock::class);
        $this->lock->shouldReceive('acquire')->andReturn(true);
        $this->lock->shouldReceive('release');
        $this->import = Mockery::mock(MasterImportService::class);
        $this->import->shouldReceive('rarityColumnsOf')->andReturn([]);
        $this->exporter = Mockery::mock(MasterDataExporter::class);
        $this->deploy = Mockery::mock(MasterDeployService::class)->makePartial();
        $this->invalidator = Mockery::mock(MasterCacheInvalidator::class)->makePartial();
        $this->mst = Mockery::mock(ConnectionInterface::class);

        $this->deploy->shouldReceive('allocateRealKey')->andReturn(self::KEY);
        $this->import->shouldReceive('reflect')->andReturn(['connection' => $this->mst, 'tables' => ['mst_config' => ['inserted' => 1, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0]]]);
        $this->exporter->shouldReceive('withConnection')->andReturnSelf();
        $this->exporter->shouldReceive('export')->andReturn(['hash' => str_repeat('a', 64), 'file_size' => 10, 'table_count' => 1, 'file_count' => 1, 'tables' => []]);
    }

    protected function tearDown(): void
    {
        DB::purge('tool');
        DB::purge('sys');
        parent::tearDown();
    }

    /**
     * @param  list<int>  $activeIds  登録前に有効な sys_deploy の id（id 10〜12 と、あれば新しい行 20 の元の状態）
     */
    private function seedSys(array $activeIds): void
    {
        foreach ([10, 11, 12, 20] as $id) {
            DB::connection('sys')->table('sys_deploy')->insert(['id' => $id, 'deploy_key' => 2026112100 + $id, 'is_active' => in_array($id, $activeIds, true)]);
        }
    }

    /**
     * registerDeploy の疑似実装: 全行を無効にして $activate を有効にする。結果は設計の形
     *
     * @param  list<int>  $activeBefore
     * @param  bool  $sysCommitted  commitError のとき、sys の COMMIT が実際には確定していたか（false なら元の状態に戻る）
     */
    private function fakeRegister(int $activate, array $activeBefore, ?\Throwable $commitError = null, bool $sysCommitted = true): void
    {
        $this->deploy->shouldReceive('registerDeploy')->andReturnUsing(function ($export, $key, $fixed, $now, $beforeCommit) use ($activate, $activeBefore, $commitError, $sysCommitted) {
            $result = [
                'sys_deploy_master_id' => 5, 'sys_deploy_id' => $activate, 'deploy_key' => self::KEY, 'hash' => str_repeat('a', 64),
                'tables' => [], 'is_new' => true, 'activated_deploy_id' => $activate, 'previous_active_deploy_ids' => $activeBefore,
            ];
            DB::connection('sys')->table('sys_deploy')->update(['is_active' => false]);
            DB::connection('sys')->table('sys_deploy')->where('id', $activate)->update(['is_active' => true]);
            $beforeCommit($result);
            if ($commitError !== null) {
                if (! $sysCommitted) {
                    // 実際には確定せず、ロールバックされた（元の状態に戻る）
                    DB::connection('sys')->table('sys_deploy')->update(['is_active' => false]);
                    DB::connection('sys')->table('sys_deploy')->whereIn('id', $activeBefore)->update(['is_active' => true]);
                }
                throw MasterDeployKeyException::registerFailed($commitError);
            }

            return $result;
        });
    }

    /** @return list<int> 有効な sys_deploy の id */
    private function activeIds(): array
    {
        return DB::connection('sys')->table('sys_deploy')->where('is_active', true)->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    private function runner(): MasterDeployRunner
    {
        $policy = new MasterDeployKeyPolicy(null, [7777777777, 8888888888, 9999999999], ['production', 'staging'], 7000000000, 'testing');
        $sheets = ['tables' => ['mst_config' => ['entries' => [
            ['row_no' => 3, 'sheet' => 's/mst_config', 'id' => 'a', 'language' => null, 'deploy_key' => '2026010101', 'deleted' => '', 'columns' => ['name' => 'A']],
        ], 'has_deploy_key_column' => true, 'ignored_inactive_rows' => 0]], 'ignored_tab_list' => [], 'ignored_column_list' => [], 'error_list' => []];
        $factory = function () use ($sheets) {
            $service = Mockery::mock(GoogleSpreadsheetService::class);
            $service->shouldReceive('isConfigured')->andReturn(true);
            $service->shouldReceive('readAllMasterTabs')->andReturn($sheets);

            return $service;
        };

        return new class($policy, $this->lock, $factory, new MasterSheetValidator($policy), $this->import, $this->exporter, $this->deploy, $this->invalidator) extends MasterDeployRunner
        {
            protected function loadMstSchema(): array
            {
                return ['columns' => ['mst_config' => ['id', 'name', 'deploy_key']], 'rarity' => []];
            }
        };
    }

    private function catchError(): MasterDeployRunException
    {
        try {
            $this->runner()->run();
        } catch (MasterDeployRunException $e) {
            return $e;
        }
        $this->fail('MasterDeployRunException が投げられませんでした');
    }

    private function run1(): object
    {
        /** @var object $row */
        $row = DB::connection('tool')->table('tol_master_deploy_run')->orderByDesc('id')->first();

        return $row;
    }

    #[Test]
    public function mstのcommit失敗で_登録で無効にした複数行を全て有効に戻し新しい行を無効にする(): void
    {
        $this->seedSys([10, 11]);
        $this->fakeRegister(20, [10, 11]);
        $this->mst->shouldReceive('commit')->andThrow(new RuntimeException('gone away'));
        $this->mst->shouldNotReceive('rollBack');
        $forgotten = [];
        $this->invalidator->shouldReceive('invalidate')->once()->andReturnUsing(function ($tables, $keys, $ids) use (&$forgotten) {
            $forgotten = [$tables, $keys, $ids];

            return ['invalidated' => true, 'failed_key_list' => []];
        });

        $e = $this->catchError();

        $this->assertSame([10, 11], $this->activeIds());
        $this->assertSame('inconsistent', $e->errorCode);
        $this->assertSame(500, $e->httpStatus);
        $this->assertTrue($e->toArray()['compensated']);
        $this->assertSame([], $forgotten[0]); // mst の表は触らない
        $this->assertContains(self::KEY, $forgotten[1]);
        $this->assertContains(2026112110, $forgotten[1]);
        $this->assertEqualsCanonicalizing([20, 10, 11], $forgotten[2]);
        $run = $this->run1();
        $this->assertSame('inconsistent', $run->status);
        $this->assertSame('commit', $run->phase);
        $summary = json_decode((string) $run->summary, true);
        $this->assertTrue($summary['compensated']);
        $this->assertSame(20, $summary['sys_deploy_id']);
        $this->assertSame('mst', $summary['failed_at']);
        $this->assertTrue($summary['cache_invalidated']);
        // 次の実行は全破棄
        $this->assertTrue((new MasterCacheInvalidator)->shouldInvalidateAll());
    }

    #[Test]
    public function r1で新しい行が元から有効だった場合は補償後も有効のまま(): void
    {
        $this->seedSys([20]);
        $this->fakeRegister(20, [20]);
        $this->mst->shouldReceive('commit')->andThrow(new RuntimeException('gone away'));
        $this->invalidator->shouldReceive('invalidate')->andReturn(['invalidated' => true, 'failed_key_list' => []]);

        $e = $this->catchError();

        $this->assertSame([20], $this->activeIds());
        $this->assertTrue($e->toArray()['compensated']);
    }

    #[Test]
    public function 補償にも失敗したらcompensatedはfalseで_forgetは試み_runはinconsistent(): void
    {
        $this->seedSys([10]);
        $this->fakeRegister(20, [10]);
        $this->deploy->shouldReceive('compensate')->once()->andThrow(new RuntimeException('sys down'));
        $this->mst->shouldReceive('commit')->andThrow(new RuntimeException('gone away'));
        $this->invalidator->shouldReceive('invalidate')->once()->andReturn(['invalidated' => false, 'failed_key_list' => ['sys:sys_deploy:latest_downloadable']]);

        $e = $this->catchError();

        $this->assertSame('inconsistent', $e->errorCode);
        $this->assertFalse($e->toArray()['compensated']);
        $this->assertSame([20], $this->activeIds()); // 戻せていない
        $run = $this->run1();
        $this->assertSame('inconsistent', $run->status);
        $summary = json_decode((string) $run->summary, true);
        $this->assertFalse($summary['compensated']);
        $this->assertFalse($summary['cache_invalidated']);
        $this->assertSame(['sys:sys_deploy:latest_downloadable'], $summary['cache_failed_key_list']);
        $this->assertTrue((new MasterCacheInvalidator)->shouldInvalidateAll());
    }

    #[Test]
    public function run記録の更新に失敗しても_inconsistentのレスポンスは変わらない(): void
    {
        $this->seedSys([10]);
        $this->fakeRegister(20, [10]);
        $this->mst->shouldReceive('commit')->andReturnUsing(function () {
            Schema::connection('tool')->drop('tol_master_deploy_run');
            throw new RuntimeException('gone away');
        });
        $this->invalidator->shouldReceive('invalidate')->andReturn(['invalidated' => true, 'failed_key_list' => []]);

        $e = $this->catchError();

        $this->assertSame('inconsistent', $e->errorCode);
        $this->assertTrue($e->toArray()['compensated']);
        $this->assertSame([10], $this->activeIds());
    }

    #[Test]
    public function sysのcommit自体の失敗は成否不明として_mstを戻し補償してinconsistentにする(): void
    {
        $this->seedSys([10, 11]);
        // sys は実際には確定していた（新しい行が有効のまま）想定
        $this->fakeRegister(20, [10, 11], new RuntimeException('commit gone away'));
        $this->mst->shouldReceive('rollBack')->once();
        $this->mst->shouldNotReceive('commit');
        $this->invalidator->shouldReceive('invalidate')->once()->andReturn(['invalidated' => true, 'failed_key_list' => []]);

        $e = $this->catchError();

        $this->assertSame('inconsistent', $e->errorCode);
        $this->assertTrue($e->toArray()['compensated']);
        $this->assertSame([10, 11], $this->activeIds());
        $run = $this->run1();
        $this->assertSame('inconsistent', $run->status);
        $this->assertSame('sys', json_decode((string) $run->summary, true)['failed_at']);
        $this->assertTrue((new MasterCacheInvalidator)->shouldInvalidateAll());
    }

    #[Test]
    public function 登録の途中の失敗_commit前_はこれまでどおりregister_failedで補償しない(): void
    {
        $this->seedSys([10]);
        $this->deploy->shouldReceive('registerDeploy')->andThrow(MasterDeployKeyException::registerFailed(new RuntimeException('insert failed')));
        $this->deploy->shouldNotReceive('compensate');
        $this->mst->shouldReceive('rollBack')->once();

        $e = $this->catchError();

        $this->assertSame('register_failed', $e->errorCode);
        $this->assertSame('failed', $this->run1()->status);
        $this->assertSame([10], $this->activeIds());
    }

    #[Test]
    public function 前回がabortedのphase_invalidateなら次の実行は全破棄になる(): void
    {
        DB::connection('tool')->table('tol_master_deploy_run')->insert([
            'mode' => 'real', 'status' => 'aborted', 'phase' => 'invalidate', 'app_env' => 'x', 'started_at' => '2026-11-22 00:00:00',
        ]);
        // 間に挟まった早期失敗は無視される
        DB::connection('tool')->table('tol_master_deploy_run')->insert([
            'mode' => 'real', 'status' => 'failed', 'phase' => 'validate', 'app_env' => 'x', 'started_at' => '2026-11-22 01:00:00',
        ]);

        $this->assertTrue((new MasterCacheInvalidator)->shouldInvalidateAll());
    }

    #[Test]
    public function 前回が正常に完了していれば全破棄にならない(): void
    {
        DB::connection('tool')->table('tol_master_deploy_run')->insert([
            'mode' => 'real', 'status' => 'succeeded', 'phase' => 'done', 'app_env' => 'x', 'started_at' => '2026-11-22 00:00:00',
            'summary' => json_encode(['cache_invalidated' => true]),
        ]);

        $this->assertFalse((new MasterCacheInvalidator)->shouldInvalidateAll());
    }

    #[Test]
    public function sysのcommitが実は確定していなければ補償は何も変えずinconsistentになる(): void
    {
        $this->seedSys([10, 11]);
        $this->fakeRegister(20, [10, 11], new RuntimeException('commit gone away'), false);
        $this->mst->shouldReceive('rollBack')->once();
        $this->invalidator->shouldReceive('invalidate')->once()->andReturn(['invalidated' => true, 'failed_key_list' => []]);

        $e = $this->catchError();

        $this->assertSame('inconsistent', $e->errorCode);
        $this->assertTrue($e->toArray()['compensated']);
        $this->assertSame([10, 11], $this->activeIds());
    }

    #[Test]
    public function before_commit内で投げたらcommit_startedにならずregister_failedで補償しない(): void
    {
        $this->seedSys([10]);
        $this->deploy->shouldReceive('registerDeploy')->andReturnUsing(function ($export, $key, $fixed, $now, $beforeCommit) {
            // 実行記録を書けない状態にして、beforeCommit の中で例外を出す（本物と同じく register_failed に包まれる）
            Schema::connection('tool')->drop('tol_master_deploy_run');
            try {
                $beforeCommit(['activated_deploy_id' => 20, 'previous_active_deploy_ids' => [10], 'deploy_key' => self::KEY]);
            } catch (\Throwable $e) {
                throw MasterDeployKeyException::registerFailed($e);
            }
        });
        $this->deploy->shouldNotReceive('compensate');
        $this->invalidator->shouldNotReceive('invalidate');
        $this->mst->shouldReceive('rollBack')->once();

        $e = $this->catchError();

        $this->assertSame('register_failed', $e->errorCode);
        $this->assertSame([10], $this->activeIds());
    }

    #[Test]
    public function sys接続ごと落ちて補償が失敗しても_メモリの登録結果でforgetを試みる(): void
    {
        $this->seedSys([10]);
        $this->fakeRegister(20, [10]);
        $this->mst->shouldReceive('commit')->andReturnUsing(function () {
            // sys を使えなくする（接続設定を壊して再接続させる）
            Config::set('database.connections.sys', ['driver' => 'sqlite', 'database' => '/nonexistent-dir/sys.sqlite', 'prefix' => '']);
            DB::purge('sys');
            throw new RuntimeException('gone away');
        });
        $forgotten = null;
        $this->invalidator->shouldReceive('invalidate')->once()->andReturnUsing(function ($tables, $keys, $ids) use (&$forgotten) {
            $forgotten = [$tables, $keys, $ids];

            return ['invalidated' => true, 'failed_key_list' => []];
        });

        $e = $this->catchError();

        $this->assertFalse($e->toArray()['compensated']);
        $this->assertSame('inconsistent', $e->errorCode);
        $this->assertNotNull($forgotten);
        $this->assertSame([], $forgotten[0]);
        $this->assertSame([self::KEY], $forgotten[1]);
        $this->assertEqualsCanonicalizing([20, 10], $forgotten[2]);
        $this->assertSame('inconsistent', $this->run1()->status);
    }
}

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
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * MasterDeployRunner（S3-12a: 正常系と COMMIT 前の簡単な失敗）のテスト
 *
 * Google・mst・Redis・ファイル書き出しはモックにする。tool・sys 接続は SQLite のメモリへ差し替え、
 * tol_master_deploy_run と sys_deploy を最小の列で作る（共有のテスト DB には触れない）。
 * ロックは MasterDeployLock のモック（GET_LOCK は MySQL の機能なので、実物は MasterDeployLockTest）。
 */
class MasterDeployRunnerTest extends TestCase
{
    private const KEY = 2026112201;

    private int $rowNo = 2;

    private MasterDeployLock&MockInterface $lock;

    private MasterImportService&MockInterface $import;

    private MasterDataExporter&MockInterface $exporter;

    private MasterDeployService&MockInterface $deploy;

    private MasterCacheInvalidator&MockInterface $invalidator;

    private ConnectionInterface&MockInterface $mst;

    /** @var array<string, mixed>|Throwable シートの読み取り結果（Throwable なら投げる） */
    private mixed $sheets;

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
        });
        DB::connection('sys')->table('sys_deploy')->insert([['id' => 30, 'deploy_key' => 2026112101], ['id' => 31, 'deploy_key' => self::KEY]]);

        $this->lock = Mockery::mock(MasterDeployLock::class);
        $this->lock->shouldReceive('acquire')->andReturn(true)->byDefault();
        $this->lock->shouldReceive('release')->byDefault();
        $this->import = Mockery::mock(MasterImportService::class);
        $this->import->shouldReceive('rarityColumnsOf')->andReturn([])->byDefault();
        $this->exporter = Mockery::mock(MasterDataExporter::class);
        $this->deploy = Mockery::mock(MasterDeployService::class);
        $this->invalidator = Mockery::mock(MasterCacheInvalidator::class)->makePartial();
        $this->mst = Mockery::mock(ConnectionInterface::class);
        $this->sheets = $this->assembled([$this->entry('a', '2026010101', ['name' => 'A']), $this->entry('b', '2026112201', ['name' => 'B'])]);
    }

    protected function tearDown(): void
    {
        DB::purge('tool');
        DB::purge('sys');
        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $columns
     * @return array<string, mixed>
     */
    private function entry(string $id, ?string $deployKey, array $columns = [], string $deleted = ''): array
    {
        return ['row_no' => ++$this->rowNo, 'sheet' => 's/mst_config', 'id' => $id, 'language' => null, 'deploy_key' => $deployKey, 'deleted' => $deleted, 'columns' => $columns];
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function assembled(array $entries): array
    {
        return [
            'tables' => ['mst_config' => ['entries' => $entries, 'has_deploy_key_column' => true, 'ignored_inactive_rows' => 1]],
            'ignored_tab_list' => [['sheet' => 's/メモ', 'reason' => 'not_mst_tab']],
            'ignored_column_list' => [],
            'error_list' => [],
        ];
    }

    private function runner(?MasterDeployKeyPolicy $policy = null, ?Closure $sheetFactory = null): MasterDeployRunner
    {
        $policy ??= $this->policy(null);
        $sheets = $this->sheets;
        $factory = $sheetFactory ?? function () use ($sheets) {
            $service = Mockery::mock(GoogleSpreadsheetService::class);
            $service->shouldReceive('isConfigured')->andReturn(true);
            if ($sheets instanceof \Throwable) {
                $service->shouldReceive('readAllMasterTabs')->andThrow($sheets);
            } else {
                $service->shouldReceive('readAllMasterTabs')->andReturn($sheets);
            }

            return $service;
        };

        // mst の DB（SHOW・information_schema は MySQL 専用）には触れないよう、スキーマの取得だけ差し替える
        return new class($policy, $this->lock, $factory, new MasterSheetValidator($policy), $this->import, $this->exporter, $this->deploy, $this->invalidator) extends MasterDeployRunner
        {
            protected function loadMstSchema(): array
            {
                return ['columns' => ['mst_config' => ['id', 'name', 'deploy_key']], 'rarity' => []];
            }
        };
    }

    private function policy(?int $fixedKey, string $env = 'testing'): MasterDeployKeyPolicy
    {
        return new MasterDeployKeyPolicy($fixedKey, [7777777777, 8888888888, 9999999999], ['production', 'staging'], 7000000000, $env);
    }

    /**
     * @return array<string, mixed>
     */
    private function exportResult(): array
    {
        return ['hash' => str_repeat('a', 64), 'file_size' => 10, 'table_count' => 1, 'file_count' => 1, 'tables' => ['mst_config' => ['hash' => 'h', 'file_name' => 'mst_config_h.sqlite', 'file_size' => 10, 'public_url' => '/masterdata/x/mst_config_h.sqlite']]];
    }

    /**
     * @return array<string, mixed>
     */
    private function registration(bool $isNew = true, int $key = self::KEY): array
    {
        return ['sys_deploy_master_id' => 27, 'sys_deploy_id' => 31, 'deploy_key' => $key, 'hash' => str_repeat('a', 64), 'tables' => [], 'is_new' => $isNew, 'activated_deploy_id' => 31, 'previous_active_deploy_ids' => [30]];
    }

    /**
     * 正常系の呼び出し先を一通り用意する
     */
    private function expectHappyPath(int $key = self::KEY, bool $isFixed = false): void
    {
        if (! $isFixed) {
            $this->deploy->shouldReceive('allocateRealKey')->once()->andReturn($key);
        }
        $this->import->shouldReceive('reflect')->once()->andReturn(['connection' => $this->mst, 'tables' => ['mst_config' => ['inserted' => 1, 'updated' => 0, 'deleted' => 0, 'unchanged' => 1]]]);
        $this->exporter->shouldReceive('withConnection')->with($this->mst)->andReturnSelf();
        $this->exporter->shouldReceive('export')->once()->andReturn($this->exportResult());
        $this->deploy->shouldReceive('registerDeploy')->once()->andReturnUsing(function ($export, $deployKey, $fixed, $now, $beforeCommit) use ($key) {
            // phase = commit は sys の COMMIT の前（登録の後）に入る
            $this->assertSame('register', $this->run1()->phase);
            $beforeCommit();
            $this->assertSame('commit', $this->run1()->phase);
            $this->assertSame($key, $deployKey);

            return $this->registration(true, $key);
        });
        $this->mst->shouldReceive('commit')->once();
    }

    private function run1(): object
    {
        /** @var object $row */
        $row = DB::connection('tool')->table('tol_master_deploy_run')->orderByDesc('id')->first();

        return $row;
    }

    private function runCount(): int
    {
        return DB::connection('tool')->table('tol_master_deploy_run')->count();
    }

    private function catch(callable $call): MasterDeployRunException
    {
        try {
            $call();
        } catch (MasterDeployRunException $e) {
            return $e;
        }
        $this->fail('MasterDeployRunException が投げられませんでした');
    }

    #[Test]
    public function 本物の正常系は設計の順に進み_succeededで終わりロックを解放する(): void
    {
        $this->expectHappyPath();
        $this->lock->shouldReceive('release')->once();
        $this->invalidator->shouldReceive('invalidate')->once()->withArgs(function ($tables, $keys, $ids) {
            return $tables === ['mst_config'] && $ids === [31, 30] && in_array(self::KEY, $keys, true) && in_array(2026112101, $keys, true);
        })->andReturn(['invalidated' => true, 'failed_key_list' => []]);

        $result = $this->runner()->run(['adm_account_id' => 5]);

        $this->assertSame('success', $result['status']);
        $this->assertFalse($result['dry_run']);
        $this->assertSame(['mode' => 'real', 'deploy_key' => self::KEY, 'sys_deploy_master_id' => 27, 'sys_deploy_id' => 31, 'is_new' => true], $result['deploy']);
        $this->assertSame(['invalidated' => true, 'failed_key_list' => []], $result['cache']);
        $this->assertSame('mst_config', $result['import']['table_list'][0]['table']);
        $this->assertSame(2, $result['import']['table_list'][0]['input_rows']);
        $this->assertSame(1, $result['import']['table_list'][0]['ignored_inactive_rows']);
        $this->assertSame(1, $result['import']['table_list'][0]['inserted']);
        $this->assertSame([['sheet' => 's/メモ', 'reason' => 'not_mst_tab']], $result['import']['ignored_tab_list']);
        $this->assertSame([], $result['import']['warning_list']);

        $run = $this->run1();
        $this->assertSame($result['run_id'], (int) $run->id);
        $this->assertSame('real', $run->mode);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('done', $run->phase);
        $this->assertSame(5, (int) $run->adm_account_id);
        $this->assertSame(self::KEY, (int) $run->deploy_key);
        $this->assertSame(str_repeat('a', 64), $run->master_hash);
        $this->assertSame(31, (int) $run->sys_deploy_id);
        $this->assertSame(1, (int) $run->is_new);
        $this->assertNotNull($run->finished_at);
        $summary = json_decode((string) $run->summary, true);
        $this->assertTrue($summary['cache_invalidated']);
        $this->assertSame(1, $summary['tables']['mst_config']['inserted']);
    }

    #[Test]
    public function 畳んだ行はidとdeploy_key付きでreflectへ渡る(): void
    {
        $this->sheets = $this->assembled([
            $this->entry('a', '2026010101', ['name' => 'old']),
            $this->entry('a', '2026112201', ['name' => 'new']),
            $this->entry('gone', '2026010101', ['name' => 'x']),
            $this->entry('gone', '2026112201', [], '1'),
            $this->entry('future', '2026112299', ['name' => 'later']),
        ]);
        $this->deploy->shouldReceive('allocateRealKey')->andReturn(self::KEY);
        $this->import->shouldReceive('reflect')->once()->withArgs(function (array $tables, bool $dry = false) {
            return ! $dry && $tables === ['mst_config' => [
                'headers' => ['id', 'name', 'deploy_key'],
                'rows' => [['id' => 'a', 'name' => 'new', 'deploy_key' => '2026112201']],
                'replace' => true,
            ]];
        })->andThrow(new RuntimeException('stop here'));

        $e = $this->catch(fn () => $this->runner()->run());
        $this->assertSame('apply_failed', $e->errorCode);
    }

    #[Test]
    public function 固定キーは採番せずfixedで記録する(): void
    {
        $this->expectHappyPath(8888888888, true);
        $this->invalidator->shouldReceive('invalidate')->once()->andReturn(['invalidated' => true, 'failed_key_list' => []]);
        $this->deploy->shouldNotReceive('allocateRealKey');

        $result = $this->runner($this->policy(8888888888))->run();

        $this->assertSame('fixed', $result['deploy']['mode']);
        $this->assertSame('fixed', $this->run1()->mode);
        $this->assertSame(8888888888, (int) $this->run1()->deploy_key);
    }

    #[Test]
    public function 固定キーが禁止環境ならロックの前にfixed_key_forbiddenで止まる(): void
    {
        $this->lock->shouldNotReceive('acquire');

        $e = $this->catch(fn () => $this->runner($this->policy(8888888888, 'production'))->run());

        $this->assertSame('fixed_key_forbidden', $e->errorCode);
        $this->assertSame(403, $e->httpStatus);
        $this->assertSame(0, $this->runCount());
    }

    #[Test]
    public function 固定キーが許可リスト外ならfixed_key_invalid(): void
    {
        $e = $this->catch(fn () => $this->runner($this->policy(5555555555))->run());

        $this->assertSame('fixed_key_invalid', $e->errorCode);
        $this->assertSame(500, $e->httpStatus);
    }

    #[Test]
    public function ロックが取れなければdeploy_lockedで_runは作らない(): void
    {
        $this->lock->shouldReceive('acquire')->andReturn(false);
        $this->lock->shouldNotReceive('release');
        DB::connection('tool')->table('tol_master_deploy_run')->insert([
            'mode' => 'real', 'status' => 'running', 'phase' => 'apply', 'app_env' => 'x', 'started_at' => '2026-11-22 00:00:00',
        ]);

        $e = $this->catch(fn () => $this->runner()->run());

        $this->assertSame('deploy_locked', $e->errorCode);
        $this->assertSame(409, $e->httpStatus);
        $this->assertSame(1, $this->runCount());
        $this->assertSame('running', $this->run1()->status);
        $this->assertSame(['status' => 'error', 'code' => 'deploy_locked', 'message' => $e->getMessage(), 'error_list' => [], 'run_id' => null], $e->toArray());
    }

    #[Test]
    public function 残っていたrunningはabortedに直し_直前がabortedなら全表を破棄する(): void
    {
        DB::connection('tool')->table('tol_master_deploy_run')->insert([
            'mode' => 'real', 'status' => 'running', 'phase' => 'commit', 'app_env' => 'x', 'started_at' => '2026-11-22 00:00:00',
        ]);
        $this->deploy->shouldReceive('allocateRealKey')->andReturn(self::KEY);
        $this->import->shouldReceive('reflect')->andReturn(['connection' => $this->mst, 'tables' => []]);
        $this->exporter->shouldReceive('withConnection')->andReturnSelf();
        $this->exporter->shouldReceive('export')->andReturn($this->exportResult());
        $this->deploy->shouldReceive('registerDeploy')->andReturn($this->registration());
        $this->mst->shouldReceive('commit');
        $this->invalidator->shouldReceive('invalidateAll')->once()->andReturn(['invalidated' => true, 'failed_key_list' => []]);
        $this->invalidator->shouldNotReceive('invalidate');

        $this->runner()->run();

        $first = DB::connection('tool')->table('tol_master_deploy_run')->orderBy('id')->first();
        $this->assertSame('aborted', $first->status);
        $this->assertNotNull($first->finished_at);
        $summary = json_decode((string) $this->run1()->summary, true);
        $this->assertTrue($summary['full_invalidation']);
    }

    #[Test]
    public function 検証に失敗したらsheet_invalidで_何も変えずfailedにする(): void
    {
        $this->sheets = $this->assembled([$this->entry('a', '202601010'), $this->entry('b', null)]);
        $this->deploy->shouldReceive('allocateRealKey')->andReturn(self::KEY);
        $this->import->shouldNotReceive('reflect');
        $this->exporter->shouldNotReceive('export');
        $this->lock->shouldReceive('release')->once();

        $e = $this->catch(fn () => $this->runner()->run());

        $this->assertSame('sheet_invalid', $e->errorCode);
        $this->assertSame(422, $e->httpStatus);
        $this->assertSame(['deploy_key_legacy_9digits', 'deploy_key_empty'], array_column($e->errorList, 'code'));
        $this->assertSame(2, $e->extra['error_total']);
        $run = $this->run1();
        $this->assertSame($run->id, $e->runId);
        $this->assertSame('failed', $run->status);
        $this->assertSame('validate', $run->phase);
        $this->assertSame('sheet_invalid', $run->error_code);
        $this->assertSame(2, json_decode((string) $run->summary, true)['error_total']);
    }

    #[Test]
    public function 採番の失敗はそのエラーコードでfailedにする(): void
    {
        $this->deploy->shouldReceive('allocateRealKey')->andThrow(MasterDeployKeyException::deployKeyExhausted(20261122));

        $e = $this->catch(fn () => $this->runner()->run());

        $this->assertSame('deploy_key_exhausted', $e->errorCode);
        $this->assertSame(422, $e->httpStatus);
        $this->assertSame('failed', $this->run1()->status);
        $this->assertSame('read', $this->run1()->phase);
    }

    #[Test]
    public function スプレッドシートが未設定ならsheet_not_configured(): void
    {
        $e = $this->catch(fn () => $this->runner(null, fn () => throw new RuntimeException('no credentials'))->run());

        $this->assertSame('sheet_not_configured', $e->errorCode);
        $this->assertSame(422, $e->httpStatus);
        $this->assertSame('failed', $this->run1()->status);
    }

    #[Test]
    public function シートの読み込みが失敗したらsheet_read_failed(): void
    {
        $this->sheets = new RuntimeException('429');

        $e = $this->catch(fn () => $this->runner()->run());

        $this->assertSame('sheet_read_failed', $e->errorCode);
        $this->assertSame(502, $e->httpStatus);
        $this->assertSame('failed', $this->run1()->status);
    }

    #[Test]
    public function 反映の失敗はapply_failedでfailed_mstは未使用(): void
    {
        $this->deploy->shouldReceive('allocateRealKey')->andReturn(self::KEY);
        $this->import->shouldReceive('reflect')->andThrow(new RuntimeException('Duplicate entry for mst_config'));
        $this->exporter->shouldNotReceive('export');

        $e = $this->catch(fn () => $this->runner()->run());

        $this->assertSame('apply_failed', $e->errorCode);
        $this->assertSame(500, $e->httpStatus);
        $this->assertStringContainsString('Duplicate entry', $e->getMessage());
        $this->assertSame('failed', $this->run1()->status);
        $this->assertSame('apply', $this->run1()->phase);
    }

    #[Test]
    public function 書き出しの失敗はexport_failedでmstをロールバックする(): void
    {
        $this->deploy->shouldReceive('allocateRealKey')->andReturn(self::KEY);
        $this->import->shouldReceive('reflect')->andReturn(['connection' => $this->mst, 'tables' => []]);
        $this->exporter->shouldReceive('withConnection')->andReturnSelf();
        $this->exporter->shouldReceive('export')->andThrow(new RuntimeException('disk full'));
        $this->deploy->shouldNotReceive('registerDeploy');
        $this->mst->shouldReceive('rollBack')->once();
        $this->mst->shouldNotReceive('commit');
        $this->lock->shouldReceive('release')->once();

        $e = $this->catch(fn () => $this->runner()->run());

        $this->assertSame('export_failed', $e->errorCode);
        $this->assertStringNotContainsString('disk full', $e->getMessage());
        $this->assertSame('failed', $this->run1()->status);
        $this->assertSame('export', $this->run1()->phase);
    }

    #[Test]
    public function 登録の失敗はregister_failedでmstをロールバックする(): void
    {
        $this->deploy->shouldReceive('allocateRealKey')->andReturn(self::KEY);
        $this->import->shouldReceive('reflect')->andReturn(['connection' => $this->mst, 'tables' => []]);
        $this->exporter->shouldReceive('withConnection')->andReturnSelf();
        $this->exporter->shouldReceive('export')->andReturn($this->exportResult());
        $this->deploy->shouldReceive('registerDeploy')->andThrow(MasterDeployKeyException::registerFailed());
        $this->mst->shouldReceive('rollBack')->once();
        $this->mst->shouldNotReceive('commit');

        $e = $this->catch(fn () => $this->runner()->run());

        $this->assertSame('register_failed', $e->errorCode);
        $this->assertSame(500, $e->httpStatus);
        $this->assertSame('failed', $this->run1()->status);
        $this->assertSame('register', $this->run1()->phase);
        $this->assertSame(str_repeat('a', 64), $this->run1()->master_hash);
    }

    #[Test]
    public function 本物のキーが別のhashで既にあればdeploy_key_conflict_409(): void
    {
        $this->deploy->shouldReceive('allocateRealKey')->andReturn(self::KEY);
        $this->import->shouldReceive('reflect')->andReturn(['connection' => $this->mst, 'tables' => []]);
        $this->exporter->shouldReceive('withConnection')->andReturnSelf();
        $this->exporter->shouldReceive('export')->andReturn($this->exportResult());
        $this->deploy->shouldReceive('registerDeploy')->andThrow(MasterDeployKeyException::deployKeyConflict(self::KEY));
        $this->mst->shouldReceive('rollBack')->once();

        $e = $this->catch(fn () => $this->runner()->run());

        $this->assertSame('deploy_key_conflict', $e->errorCode);
        $this->assertSame(409, $e->httpStatus);
        $this->assertSame('failed', $this->run1()->status);
    }

    #[Test]
    public function 想定外の例外はinternal_errorでmstをロールバックしロックを解放する(): void
    {
        $this->deploy->shouldReceive('allocateRealKey')->andReturn(self::KEY);
        $this->import->shouldReceive('reflect')->andReturn(['connection' => $this->mst, 'tables' => []]);
        $this->exporter->shouldReceive('withConnection')->andReturnSelf();
        $this->exporter->shouldReceive('export')->andReturn($this->exportResult());
        $this->deploy->shouldReceive('registerDeploy')->andThrow(new \LogicException('bug'));
        $this->mst->shouldReceive('rollBack')->once();
        $this->lock->shouldReceive('release')->once();

        $e = $this->catch(fn () => $this->runner()->run());

        $this->assertSame('internal_error', $e->errorCode);
        $this->assertSame(500, $e->httpStatus);
        $this->assertSame($this->run1()->id, $e->runId);
        $this->assertSame('failed', $this->run1()->status);
    }

    #[Test]
    public function 実行記録をcommitの前に書けなければ中止してmstをロールバックする(): void
    {
        $this->deploy->shouldReceive('allocateRealKey')->andReturn(self::KEY);
        $this->import->shouldReceive('reflect')->andReturn(['connection' => $this->mst, 'tables' => []]);
        $this->exporter->shouldReceive('withConnection')->andReturnSelf();
        $this->exporter->shouldReceive('export')->andReturnUsing(function () {
            Schema::connection('tool')->drop('tol_master_deploy_run');

            return $this->exportResult();
        });
        $this->deploy->shouldNotReceive('registerDeploy');
        $this->mst->shouldReceive('rollBack')->once();

        $e = $this->catch(fn () => $this->runner()->run());

        $this->assertSame('internal_error', $e->errorCode);
    }

    #[Test]
    public function mstのcommitが失敗したらinconsistentを記録する_補償は12bで(): void
    {
        $this->deploy->shouldReceive('allocateRealKey')->andReturn(self::KEY);
        $this->import->shouldReceive('reflect')->andReturn(['connection' => $this->mst, 'tables' => []]);
        $this->exporter->shouldReceive('withConnection')->andReturnSelf();
        $this->exporter->shouldReceive('export')->andReturn($this->exportResult());
        $this->deploy->shouldReceive('registerDeploy')->andReturnUsing(function ($e, $k, $f, $n, $beforeCommit) {
            $beforeCommit();

            return $this->registration();
        });
        $this->mst->shouldReceive('commit')->andThrow(new RuntimeException('gone away'));
        $this->mst->shouldNotReceive('rollBack');
        $this->deploy->shouldNotReceive('compensate');
        $this->lock->shouldReceive('release')->once();

        $e = $this->catch(fn () => $this->runner()->run());

        $this->assertSame('inconsistent', $e->errorCode);
        $this->assertSame(500, $e->httpStatus);
        $this->assertFalse($e->toArray()['compensated']);
        $this->assertSame('inconsistent', $this->run1()->status);
        $this->assertSame('commit', $this->run1()->phase);
    }

    #[Test]
    public function キャッシュ破棄に失敗してもsucceededのままcache_invalidatedをfalseで記録する(): void
    {
        $this->deploy->shouldReceive('allocateRealKey')->andReturn(self::KEY);
        $this->import->shouldReceive('reflect')->andReturn(['connection' => $this->mst, 'tables' => ['mst_config' => ['inserted' => 1, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0]]]);
        $this->exporter->shouldReceive('withConnection')->andReturnSelf();
        $this->exporter->shouldReceive('export')->andReturn($this->exportResult());
        $this->deploy->shouldReceive('registerDeploy')->andReturn($this->registration(false));
        $this->mst->shouldReceive('commit');
        $this->invalidator->shouldReceive('invalidate')->andReturn(['invalidated' => false, 'failed_key_list' => ['mst:mst_config:all']]);

        $result = $this->runner()->run();

        $this->assertSame(['invalidated' => false, 'failed_key_list' => ['mst:mst_config:all']], $result['cache']);
        $this->assertStringContainsString('既存の版を有効に戻しました', $result['message']);
        $this->assertSame('succeeded', $this->run1()->status);
        $summary = json_decode((string) $this->run1()->summary, true);
        $this->assertFalse($summary['cache_invalidated']);
        // 次の実行は全破棄になる（M1）
        $this->assertTrue((new MasterCacheInvalidator)->shouldInvalidateAll());
    }

    #[Test]
    public function dry_runはロック_記録_書き込みを使わず件数と棚卸しだけ返す(): void
    {
        $this->sheets = $this->assembled([$this->entry('a', '2026010101', ['name' => 'A']), $this->entry('c', '2026112299', ['name' => 'C'])]);
        $this->lock->shouldNotReceive('acquire');
        $this->deploy->shouldReceive('allocateRealKey')->once()->andReturn(self::KEY);
        $this->import->shouldReceive('reflect')->once()->withArgs(fn (array $tables, bool $dry) => $dry === true)
            ->andReturn(['connection' => $this->mst, 'tables' => ['mst_config' => ['inserted' => 1, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0]]]);
        $this->exporter->shouldNotReceive('export');
        $this->deploy->shouldNotReceive('registerDeploy');
        $this->invalidator->shouldNotReceive('invalidate');
        $this->invalidator->shouldNotReceive('invalidateAll');

        $result = $this->runner()->run(['dry_run' => true]);

        $this->assertTrue($result['dry_run']);
        $this->assertNull($result['run_id']);
        $this->assertSame(['mode' => 'real', 'deploy_key' => self::KEY, 'sys_deploy_master_id' => null, 'sys_deploy_id' => null, 'is_new' => null], $result['deploy']);
        $this->assertNull($result['export']);
        $this->assertSame(1, $result['import']['table_list'][0]['inserted']);
        $this->assertSame('future_deploy_key_row', $result['import']['warning_list'][0]['code']);
        $this->assertSame(2, $result['inventory']['digit_distribution']['mst_config']['ten_digit_real']);
        $this->assertSame(0, $this->runCount());
    }

    #[Test]
    public function dry_runの検証エラーは全件の集計と棚卸し付きでsheet_invalid(): void
    {
        $this->sheets = $this->assembled([$this->entry('a', '202601010'), $this->entry('b', '202601020')]);
        $this->deploy->shouldReceive('allocateRealKey')->andReturn(self::KEY);
        $this->import->shouldNotReceive('reflect');

        $e = $this->catch(fn () => $this->runner()->run(['dry_run' => true]));

        $this->assertSame('sheet_invalid', $e->errorCode);
        $this->assertSame(2, $e->extra['error_total']);
        $this->assertSame(2, $e->extra['inventory']['digit_distribution']['mst_config']['nine_digit']);
        $this->assertSame(0, $this->runCount());
    }

    #[Test]
    public function query_exceptionのapply_failedは_sq_lとバインド値を出さず_d_bの文言だけを出す(): void
    {
        $this->deploy->shouldReceive('allocateRealKey')->andReturn(self::KEY);
        $query = new QueryException(
            'mst',
            'insert into mst_config (id, name) values (?, ?)',
            ['a', 'SECRET_SHEET_VALUE'],
            new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key 'uk_name'"),
        );
        $this->import->shouldReceive('reflect')->andThrow($query);

        $e = $this->catch(fn () => $this->runner()->run());

        $this->assertSame('apply_failed', $e->errorCode);
        $this->assertStringContainsString('Duplicate entry', $e->getMessage());
        $this->assertStringNotContainsString('insert into', $e->getMessage());
        $this->assertStringNotContainsString('SECRET_SHEET_VALUE', $e->getMessage());
        $this->assertStringNotContainsString('SECRET_SHEET_VALUE', (string) $this->run1()->error_message);
    }

    #[Test]
    public function ロック取得で例外ならinternal_error_500(): void
    {
        $this->lock->shouldReceive('acquire')->andThrow(new RuntimeException('GET_LOCK がエラーを返しました'));
        $this->lock->shouldNotReceive('release');

        $e = $this->catch(fn () => $this->runner()->run());

        $this->assertSame('internal_error', $e->errorCode);
        $this->assertSame(500, $e->httpStatus);
        $this->assertSame(0, $this->runCount());
    }

    #[Test]
    public function mstのcommit後に実行記録を書けなくても成功を返す(): void
    {
        $this->deploy->shouldReceive('allocateRealKey')->andReturn(self::KEY);
        $this->import->shouldReceive('reflect')->andReturn(['connection' => $this->mst, 'tables' => []]);
        $this->exporter->shouldReceive('withConnection')->andReturnSelf();
        $this->exporter->shouldReceive('export')->andReturn($this->exportResult());
        $this->deploy->shouldReceive('registerDeploy')->andReturn($this->registration());
        $this->mst->shouldReceive('commit')->andReturnUsing(function () {
            Schema::connection('tool')->drop('tol_master_deploy_run');
        });
        $this->invalidator->shouldReceive('invalidate')->andReturn(['invalidated' => true, 'failed_key_list' => []]);

        $result = $this->runner()->run();

        $this->assertSame('success', $result['status']);
        $this->assertSame(31, $result['deploy']['sys_deploy_id']);
    }

    #[Test]
    public function 実行記録のdeploy_keyなどはmstのcommitの後に書く(): void
    {
        $this->deploy->shouldReceive('allocateRealKey')->andReturn(self::KEY);
        $this->import->shouldReceive('reflect')->andReturn(['connection' => $this->mst, 'tables' => []]);
        $this->exporter->shouldReceive('withConnection')->andReturnSelf();
        $this->exporter->shouldReceive('export')->andReturn($this->exportResult());
        $this->deploy->shouldReceive('registerDeploy')->andReturn($this->registration());
        $this->mst->shouldReceive('commit')->andReturnUsing(function () {
            $this->assertNull($this->run1()->sys_deploy_id);
        });
        $this->invalidator->shouldReceive('invalidate')->andReturn(['invalidated' => true, 'failed_key_list' => []]);

        $this->runner()->run();

        $this->assertSame(31, (int) $this->run1()->sys_deploy_id);
    }
}

<?php

namespace Tests\Feature;

use App\Exceptions\MasterDeployKeyException;
use App\Services\MasterCacheInvalidator;
use App\Services\MasterDeployKeyPolicy;
use App\Services\MasterDeployLock;
use App\Services\MasterDeployService;
use App\Services\MasterDeployTargetService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MasterDeployTargetService のテスト（api.md 3 節）
 *
 * tool 接続は SQLite のメモリに差し替えて tol_master_deploy_run を最小の列で作る。ロックと採番はモック。
 */
class MasterDeployTargetServiceTest extends TestCase
{
    private MasterDeployLock&MockInterface $lock;

    private MasterDeployService&MockInterface $deploy;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.tool', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('tool');
        Schema::connection('tool')->create('tol_master_deploy_run', function ($table) {
            $table->id();
            $table->string('status');
            $table->string('phase');
            $table->unsignedBigInteger('deploy_key')->nullable();
            $table->text('summary')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->string('started_at')->nullable();
            $table->string('finished_at')->nullable();
        });

        $this->lock = Mockery::mock(MasterDeployLock::class);
        $this->deploy = Mockery::mock(MasterDeployService::class);
    }

    protected function tearDown(): void
    {
        DB::purge('tool');
        parent::tearDown();
    }

    private function service(mixed $fixedKey = null, string $appEnv = 'testing'): MasterDeployTargetService
    {
        Config::set('app.env', $appEnv);
        Config::set('master_deploy.fixed_key', $fixedKey);
        $policy = new MasterDeployKeyPolicy($fixedKey, [7777777777, 8888888888, 9999999999], ['production', 'staging'], 7000000000, $appEnv);

        return new MasterDeployTargetService($policy, $this->lock, new MasterCacheInvalidator, $this->deploy);
    }

    private function lockFree(): void
    {
        $this->lock->shouldReceive('isFree')->andReturn(true);
        $this->lock->shouldNotReceive('acquire');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function insertRun(string $status, string $phase, array $extra = []): void
    {
        DB::connection('tool')->table('tol_master_deploy_run')->insert([
            'status' => $status,
            'phase' => $phase,
            'started_at' => '2026-11-22 01:00:00',
        ] + $extra);
    }

    #[Test]
    public function 本物のモードは次のキーを予告する(): void
    {
        $this->lockFree();
        $this->deploy->shouldReceive('allocateRealKey')->once()->andReturn(2026112201);

        $target = $this->service()->build(true);

        $this->assertSame('testing', $target['app_env']);
        $this->assertSame('real', $target['mode']);
        $this->assertNull($target['fixed_key']);
        $this->assertSame(2026112201, $target['next_key']);
        $this->assertNull($target['blocked']);
        $this->assertTrue($target['spreadsheet_dir_configured']);
        $this->assertFalse($target['running']);
        $this->assertNull($target['last_run']);
        $this->assertSame([], $target['warning_list']);
    }

    #[Test]
    public function 固定キーのモードは_tを返し採番しない(): void
    {
        $this->lockFree();
        $this->deploy->shouldNotReceive('allocateRealKey');

        $target = $this->service(9999999999)->build(false);

        $this->assertSame('fixed', $target['mode']);
        $this->assertSame(9999999999, $target['fixed_key']);
        $this->assertNull($target['next_key']);
        $this->assertNull($target['blocked']);
        $this->assertFalse($target['spreadsheet_dir_configured']);
    }

    #[Test]
    public function 禁止環境の固定キーはblockedになる(): void
    {
        $this->lockFree();

        $target = $this->service(9999999999, 'production')->build(true);

        $this->assertSame('fixed_key_forbidden', $target['blocked']['code'] ?? null);
        $this->assertSame('fixed', $target['mode']);
        $this->assertSame(9999999999, $target['fixed_key']);
        $this->assertNull($target['next_key']);
    }

    #[Test]
    public function 許可リスト外の固定キーはblockedで_tを出さない(): void
    {
        $this->lockFree();

        $target = $this->service(7777777778)->build(true);

        $this->assertSame('fixed_key_invalid', $target['blocked']['code'] ?? null);
        $this->assertSame('fixed', $target['mode']);
        $this->assertNull($target['fixed_key']);
        $this->assertNull($target['next_key']);
    }

    #[Test]
    public function 採番が拒否される場合はblockedでnext_keyがnull(): void
    {
        $this->lockFree();
        $this->deploy->shouldReceive('allocateRealKey')->andThrow(MasterDeployKeyException::deployKeyExhausted(20261122));

        $target = $this->service()->build(true);

        $this->assertSame('real', $target['mode']);
        $this->assertSame('deploy_key_exhausted', $target['blocked']['code'] ?? null);
        $this->assertNull($target['next_key']);
    }

    #[Test]
    public function 予約帯の採番はblockedになる(): void
    {
        $this->lockFree();
        $this->deploy->shouldReceive('allocateRealKey')->andThrow(MasterDeployKeyException::reservedKeyForbidden(7000000001));

        $this->assertSame('reserved_key_forbidden', $this->service()->build(true)['blocked']['code'] ?? null);
    }

    #[Test]
    public function ロックが使われていればrunningで取りにいかない(): void
    {
        $this->lock->shouldReceive('isFree')->andReturn(false);
        $this->lock->shouldNotReceive('acquire');
        $this->deploy->shouldReceive('allocateRealKey')->andReturn(2026112201);

        $this->assertTrue($this->service()->build(true)['running']);
    }

    #[Test]
    public function last_runは最新の1件を返す(): void
    {
        $this->lockFree();
        $this->deploy->shouldReceive('allocateRealKey')->andReturn(2026112201);
        $this->insertRun('failed', 'validate', ['error_code' => 'sheet_invalid', 'error_message' => 'x']);
        $this->insertRun('succeeded', 'done', ['deploy_key' => 2026112201, 'finished_at' => '2026-11-22 01:05:00', 'summary' => '{"cache_invalidated":true}']);

        $lastRun = $this->service()->build(true)['last_run'];

        $this->assertSame(2, $lastRun['id'] ?? null);
        $this->assertSame('succeeded', $lastRun['status'] ?? null);
        $this->assertSame('done', $lastRun['phase'] ?? null);
        $this->assertSame(2026112201, $lastRun['deploy_key'] ?? null);
        $this->assertArrayHasKey('error_code', $lastRun);
        $this->assertNull($lastRun['error_code']);
    }

    #[Test]
    public function 警告は_inconsistentとabortedで前回未完了を出す(): void
    {
        foreach (['inconsistent', 'aborted'] as $status) {
            DB::connection('tool')->table('tol_master_deploy_run')->delete();
            $this->lock = Mockery::mock(MasterDeployLock::class);
            $this->lockFree();
            $this->deploy = Mockery::mock(MasterDeployService::class);
            $this->deploy->shouldReceive('allocateRealKey')->andReturn(2026112201);
            $this->insertRun($status, 'register');

            $this->assertContains(MasterDeployTargetService::WARNING_INCOMPLETE, $this->service()->build(true)['warning_list'], $status);
        }
    }

    #[Test]
    public function 警告は_commit以降に進んだrunが失敗か破棄未了ならキャッシュの古さを出す(): void
    {
        $cases = [
            'failed' => ['failed', 'invalidate', null, true],
            'succeeded で破棄失敗' => ['succeeded', 'done', '{"cache_invalidated":false}', true],
            'succeeded で破棄成功' => ['succeeded', 'done', '{"cache_invalidated":true}', false],
        ];
        foreach ($cases as $label => [$status, $phase, $summary, $expectWarning]) {
            DB::connection('tool')->table('tol_master_deploy_run')->delete();
            $this->lock = Mockery::mock(MasterDeployLock::class);
            $this->lockFree();
            $this->deploy = Mockery::mock(MasterDeployService::class);
            $this->deploy->shouldReceive('allocateRealKey')->andReturn(2026112201);
            $this->insertRun($status, $phase, ['summary' => $summary]);
            // 間に挟まる早期失敗の run があっても、commit 以降の run を見る
            $this->insertRun('failed', 'validate');

            $warningList = $this->service()->build(true)['warning_list'];

            $this->assertSame($expectWarning, in_array(MasterDeployTargetService::WARNING_CACHE_STALE, $warningList, true), $label);
        }
    }

    #[Test]
    public function 他のプロセスが実行中のrunは警告にしない(): void
    {
        $this->lock->shouldReceive('isFree')->andReturn(false);
        $this->deploy->shouldReceive('allocateRealKey')->andReturn(2026112201);
        $this->insertRun('running', 'commit');

        $this->assertSame([], $this->service()->build(true)['warning_list']);
    }
}

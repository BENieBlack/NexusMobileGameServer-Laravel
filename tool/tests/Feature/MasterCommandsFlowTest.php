<?php

namespace Tests\Feature;

use App\Services\MasterActivationService;
use App\Services\MasterCancelScheduleService;
use App\Services\MasterCleanupService;
use App\Services\MasterResolveUnknownService;
use App\Services\MasterRollbackService;
use PHPUnit\Framework\Attributes\Test;

/**
 * master:* コマンドと、実物の Service との結合テスト（終了コードと出力）。mst は実 MySQL の使い捨て DB、sys・tool はメモリの SQLite
 */
class MasterCommandsFlowTest extends MasterVersionedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MasterRollbackService::class, new MasterRollbackService(lock: $this->lock, invalidator: $this->invalidator));
        $this->app->instance(MasterCancelScheduleService::class, new MasterCancelScheduleService(lock: $this->lock, invalidator: $this->invalidator));
        $this->app->instance(MasterCleanupService::class, new MasterCleanupService(lock: $this->lock, invalidator: $this->invalidator));
        $this->app->instance(MasterActivationService::class, new MasterActivationService(lock: $this->lock, invalidator: $this->invalidator));
        $this->app->instance(MasterResolveUnknownService::class, new MasterResolveUnknownService(lock: $this->lock, invalidator: $this->invalidator));
    }

    #[Test]
    public function 巻き戻しの成功と対象なしの拒否(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $this->buildVersion(2026112202, 'b', '2026-11-23 11:00:00');

        $this->artisan('master:rollback', ['sys_deploy_master_id' => '999'])
            ->expectsOutputToContain('[失敗] rollback_target_not_found')
            ->assertExitCode(4);
        $this->artisan('master:rollback', ['sys_deploy_master_id' => (string) $a['sys_deploy_master_id'], '--dry-run' => true])
            ->expectsOutputToContain('[成功]')
            ->expectsOutputToContain('2026-11-23 12:00:01 UTC (2026-11-23 21:00:01 JST)')
            ->assertExitCode(0);
        $this->artisan('master:rollback', ['sys_deploy_master_id' => (string) $a['sys_deploy_master_id']])
            ->expectsOutputToContain('run_id:')
            ->assertExitCode(0);
        $this->assertSame('rollback', $this->lastRun()->kind);
    }

    #[Test]
    public function 他の操作が実行中なら終了コード3(): void
    {
        $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $this->lock->shouldReceive('acquire')->andReturn(false);

        $this->artisan('master:cleanup', ['--execute' => true])->expectsOutputToContain('deploy_locked')->assertExitCode(3);
    }

    #[Test]
    public function 予約の取り消しと緊急停止と掃除の終了コード(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $b = $this->buildVersion(2026112202, 'b', '2026-11-23 13:00:00');

        $this->artisan('master:cancel-schedule', ['sys_deploy_id' => (string) $a['sys_deploy_id']])->expectsOutputToContain('schedule_already_started')->assertExitCode(4);
        $this->artisan('master:deactivate', ['sys_deploy_id' => '999'])->expectsOutputToContain('deploy_not_found')->assertExitCode(4);
        $this->artisan('master:deactivate', ['sys_deploy_id' => (string) $b['sys_deploy_id']])->expectsOutputToContain('[成功]')->assertExitCode(0);
        $this->artisan('master:activate', ['sys_deploy_id' => (string) $b['sys_deploy_id']])->assertExitCode(0);
        $this->artisan('master:cancel-schedule', ['sys_deploy_id' => (string) $b['sys_deploy_id']])->expectsOutputToContain('cancelled.sys_deploy_id')->assertExitCode(0);
        $this->artisan('master:resolve-unknown', ['run_id' => '999'])->expectsOutputToContain('run_not_found')->assertExitCode(4);
        $this->artisan('master:cleanup')->expectsOutputToContain('dry_run: 対象の一覧だけを返しました。')->assertExitCode(0);
    }
}

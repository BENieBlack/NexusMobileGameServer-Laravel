<?php

namespace Tests\Feature;

use App\Exceptions\MasterDeployRunException;
use App\Services\MasterCancelScheduleService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * MasterCancelScheduleService（予約の取り消し。db.md 3.6）のテスト
 */
class MasterCancelScheduleServiceTest extends MasterVersionedTestCase
{
    #[Test]
    public function 到達していない予約の行を削除し_masterと_dbと成果物は残す(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $b = $this->buildVersion(2026112202, 'b', '2026-11-23 13:00:00');
        $c = $this->buildVersion(2026112203, 'c', '2026-11-23 14:00:00');

        $result = $this->service()->run((int) $b['sys_deploy_id'], ['adm_account_id' => 3]);

        $this->assertSame('success', $result['status']);
        $this->assertSame((int) $b['sys_deploy_id'], $result['cancelled']['sys_deploy_id']);
        $this->assertSame([(int) $c['sys_deploy_id']], array_column($result['remaining_scheduled_list'], 'sys_deploy_id'));
        $this->assertNull(DB::connection('sys')->table('sys_deploy')->where('id', $b['sys_deploy_id'])->first());
        $this->assertSame(3, DB::connection('sys')->table('sys_deploy_master')->count());
        $this->assertTrue($this->databaseExists($b['mst_database']));
        $this->assertTrue($result['cache']['invalidated']);
        $this->assertSame((int) $a['sys_deploy_id'], (int) DB::connection('sys')->table('sys_deploy')->orderBy('id')->value('id'));

        $run = $this->lastRun();
        $this->assertSame('cancel_schedule', $run->kind);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('done', $run->phase);
        $this->assertSame(2026112202, (int) $run->deploy_key);
        $this->assertSame(3, (int) $run->adm_account_id);
    }

    #[Test]
    public function 取り消しは_is_activeを変えず行を削除するだけ(): void
    {
        $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $b = $this->buildVersion(2026112202, 'b', '2026-11-23 13:00:00');
        $beforeActive = DB::connection('sys')->table('sys_deploy')->where('id', '!=', $b['sys_deploy_id'])->pluck('is_active', 'id')->all();

        $this->service()->run((int) $b['sys_deploy_id']);

        $this->assertSame($beforeActive, DB::connection('sys')->table('sys_deploy')->pluck('is_active', 'id')->all());
    }

    #[Test]
    public function 行が無ければschedule_not_found(): void
    {
        $this->expectError(MasterDeployRunException::SCHEDULE_NOT_FOUND, 999);
    }

    #[Test]
    public function 到達済みの行はschedule_already_started(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');

        $this->expectError(MasterDeployRunException::SCHEDULE_ALREADY_STARTED, (int) $a['sys_deploy_id']);

        $this->assertSame(1, DB::connection('sys')->table('sys_deploy')->count());
    }

    #[Test]
    public function 直前_現在_2_ttl以内_の予約はschedule_cancel_too_soonで削除しない(): void
    {
        $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $b = $this->buildVersion(2026112202, 'b', '2026-11-23 12:02:00');

        $this->expectError(MasterDeployRunException::SCHEDULE_CANCEL_TOO_SOON, (int) $b['sys_deploy_id']);

        $this->assertSame(2, DB::connection('sys')->table('sys_deploy')->count());
        $this->assertSame('schedule_cancel_too_soon', $this->lastRun()->error_code);
    }

    #[Test]
    public function 境界_現在_120秒より先なら取り消せる(): void
    {
        $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $b = $this->buildVersion(2026112202, 'b', '2026-11-23 12:02:01');

        $result = $this->service()->run((int) $b['sys_deploy_id']);

        $this->assertSame('success', $result['status']);
    }

    #[Test]
    public function forgetに失敗しても取り消しは成功のまま警告とrunのsummaryに残す(): void
    {
        $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $b = $this->buildVersion(2026112202, 'b', '2026-11-23 13:00:00');
        $this->invalidator->shouldReceive('forgetSchedule')->andReturn(['invalidated' => false, 'failed_key_list' => ['x']]);

        $result = $this->service()->run((int) $b['sys_deploy_id']);

        $this->assertFalse($result['cache']['invalidated']);
        $this->assertContains('schedule_cache_not_invalidated', array_column($result['warning_list'], 'code'));
        $run = $this->lastRun();
        $this->assertSame('succeeded', $run->status);
        $this->assertFalse($this->summaryOf($run)['cache_invalidated']);
        $this->assertNull(DB::connection('sys')->table('sys_deploy')->where('id', $b['sys_deploy_id'])->first());
    }

    #[Test]
    public function ロックが取れなければdeploy_lockedで何も変えない(): void
    {
        $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $b = $this->buildVersion(2026112202, 'b', '2026-11-23 13:00:00');
        $this->lock->shouldReceive('acquire')->andReturn(false);

        $this->expectError(MasterDeployRunException::DEPLOY_LOCKED, (int) $b['sys_deploy_id']);

        $this->assertSame(2, DB::connection('sys')->table('sys_deploy')->count());
    }

    private function service(): MasterCancelScheduleService
    {
        return new MasterCancelScheduleService(lock: $this->lock, invalidator: $this->invalidator);
    }

    private function expectError(string $code, int $sysDeployId): MasterDeployRunException
    {
        try {
            $this->service()->run($sysDeployId);
        } catch (MasterDeployRunException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());

            return $e;
        }
        $this->fail("{$code} が投げられるはず");
    }
}

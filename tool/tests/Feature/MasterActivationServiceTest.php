<?php

namespace Tests\Feature;

use App\Exceptions\MasterDeployRunException;
use App\Services\MasterActivationService;
use App\Services\MasterArtifactStore;
use App\Services\MasterDeploySelector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * MasterActivationService（緊急停止 deactivate・再開 activate。db.md 3.3・3.6。R39）のテスト
 */
class MasterActivationServiceTest extends MasterVersionedTestCase
{
    #[Test]
    public function 窓の中の予約の行を止められ_最新の選択から外れて前の行が残る(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $b = $this->buildVersion(2026112202, 'b', '2026-11-23 12:01:00'); // 取り消せない窓（120 秒以内）

        $result = $this->service()->deactivate((int) $b['sys_deploy_id']);

        $this->assertNull($result['code']);
        $this->assertFalse($result['deploy']['is_active']);
        $this->assertSame((int) $a['sys_deploy_id'], $result['current_version_after']['sys_deploy_id']);
        $this->assertSame(0, (int) DB::connection('sys')->table('sys_deploy')->where('id', $b['sys_deploy_id'])->value('is_active'));
        // 到達しても止めた行は選ばれない
        $this->assertSame((int) $a['sys_deploy_id'], (new MasterDeploySelector)->latest('2026-11-23 13:00:00')?->id);
        $run = $this->lastRun();
        $this->assertSame('deactivate', $run->kind);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame(2026112202, (int) $run->deploy_key);
        $this->assertFalse($this->summaryOf($run)['is_active']);
        $this->assertTrue($this->summaryOf($run)['cache_invalidated']);
    }

    #[Test]
    public function 他に有効な行が無いときの停止はdeactivate_no_fallbackで更新しない(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');

        $this->expectError(MasterDeployRunException::DEACTIVATE_NO_FALLBACK, 'deactivate', (int) $a['sys_deploy_id']);

        $this->assertSame(1, (int) DB::connection('sys')->table('sys_deploy')->value('is_active'));
    }

    #[Test]
    public function 落ちる先が掃除済みの版ならdeactivate_no_fallback(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $b = $this->buildVersion(2026112202, 'b', '2026-11-23 11:00:00');
        DB::connection('sys')->table('sys_deploy_master')->where('id', $a['sys_deploy_master_id'])->update(['mst_dropped_at' => '2026-11-23 11:30:00']);

        $this->expectError(MasterDeployRunException::DEACTIVATE_NO_FALLBACK, 'deactivate', (int) $b['sys_deploy_id']);
    }

    #[Test]
    public function 最新でも予約でもない行は検査なしで止められる(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $this->buildVersion(2026112202, 'b', '2026-11-23 11:00:00');

        $result = $this->service()->deactivate((int) $a['sys_deploy_id']);

        $this->assertFalse($result['deploy']['is_active']);
    }

    #[Test]
    public function すでにその状態でも更新せず成功し_forgetは行う(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $this->buildVersion(2026112202, 'b', '2026-11-23 11:00:00');
        $this->invalidator->shouldReceive('forgetSchedule')->once()->andReturn(['invalidated' => false, 'failed_key_list' => ['x']]);

        $result = $this->service()->activate((int) $a['sys_deploy_id']);

        $this->assertSame('already_in_state', $result['code']);
        $this->assertFalse($result['cache']['invalidated']);
        $this->assertContains('schedule_cache_not_invalidated', array_column($result['warning_list'], 'code'));
        $this->assertTrue($this->summaryOf($this->lastRun())['already_in_state']);
        $this->assertSame('succeeded', $this->lastRun()->status);
    }

    #[Test]
    public function forgetに失敗しても停止は成功のまま警告を返す(): void
    {
        $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $b = $this->buildVersion(2026112202, 'b', '2026-11-23 12:01:00');
        $this->invalidator->shouldReceive('forgetSchedule')->andReturn(['invalidated' => false, 'failed_key_list' => ['x']]);

        $result = $this->service()->deactivate((int) $b['sys_deploy_id']);

        $codes = array_column($result['warning_list'], 'code');
        $this->assertContains('schedule_cache_not_invalidated', $codes);
        $this->assertSame(0, (int) DB::connection('sys')->table('sys_deploy')->where('id', $b['sys_deploy_id'])->value('is_active'));
    }

    #[Test]
    public function 行が無ければdeploy_not_found(): void
    {
        $this->expectError(MasterDeployRunException::DEPLOY_NOT_FOUND, 'deactivate', 999);
        $this->expectError(MasterDeployRunException::DEPLOY_NOT_FOUND, 'activate', 999);
    }

    #[Test]
    public function 再開で最新の選択になる行の_dbが掃除済みなら成果物から復元して有効にする(): void
    {
        $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $b = $this->buildVersion(2026112202, 'b', '2026-11-23 11:00:00');
        $this->service()->deactivate((int) $b['sys_deploy_id']);
        $this->dropDatabase($b['mst_database']);
        DB::connection('sys')->table('sys_deploy_master')->where('id', $b['sys_deploy_master_id'])->update(['mst_dropped_at' => '2026-11-23 11:40:00']);

        $result = $this->service()->activate((int) $b['sys_deploy_id']);

        $this->assertTrue($result['restored']);
        $this->assertTrue($result['deploy']['is_active']);
        $this->assertTrue($this->databaseExists($b['mst_database']));
        $this->assertNull(DB::connection('sys')->table('sys_deploy_master')->where('id', $b['sys_deploy_master_id'])->value('mst_dropped_at'));
        $this->assertSame((int) $b['sys_deploy_id'], $result['current_version_after']['sys_deploy_id']);
        $run = $this->lastRun();
        $this->assertTrue($this->summaryOf($run)['is_active']);
        $this->assertTrue($this->summaryOf($run)['restored']);
        $this->assertSame([], $this->summaryOf($run)['created_database_list']);
    }

    #[Test]
    public function 復元できなければactivate_target_droppedで更新しない(): void
    {
        $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $b = $this->buildVersion(2026112202, 'b', '2026-11-23 11:00:00');
        $this->service()->deactivate((int) $b['sys_deploy_id']);
        $this->dropDatabase($b['mst_database']);
        DB::connection('sys')->table('sys_deploy_master')->where('id', $b['sys_deploy_master_id'])->update(['mst_dropped_at' => '2026-11-23 11:40:00']);
        Storage::disk(MasterArtifactStore::DISK)->deleteDirectory($b['artifact_dir']);

        $this->expectError(MasterDeployRunException::ACTIVATE_TARGET_DROPPED, 'activate', (int) $b['sys_deploy_id']);

        $this->assertSame(0, (int) DB::connection('sys')->table('sys_deploy')->where('id', $b['sys_deploy_id'])->value('is_active'));
    }

    #[Test]
    public function 再開しても最新の選択にならない行は検査なしで通す(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $this->buildVersion(2026112202, 'b', '2026-11-23 11:00:00');
        $this->service()->deactivate((int) $a['sys_deploy_id']);
        $this->dropDatabase($a['mst_database']);
        DB::connection('sys')->table('sys_deploy_master')->where('id', $a['sys_deploy_master_id'])->update(['mst_dropped_at' => '2026-11-23 11:40:00']);

        $result = $this->service()->activate((int) $a['sys_deploy_id']);

        $this->assertFalse($result['restored']);
        $this->assertTrue($result['deploy']['is_active']);
        $this->assertFalse($this->databaseExists($a['mst_database']));
    }

    #[Test]
    public function 未到達の予約の行の再開は掃除済みでも復元検査をかけない(): void
    {
        $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $b = $this->buildVersion(2026112202, 'b', '2026-11-23 15:00:00');
        $this->service()->deactivate((int) $b['sys_deploy_id']);
        $this->dropDatabase($b['mst_database']);
        DB::connection('sys')->table('sys_deploy_master')->where('id', $b['sys_deploy_master_id'])->update(['mst_dropped_at' => '2026-11-23 11:40:00']);
        Storage::disk(MasterArtifactStore::DISK)->deleteDirectory($b['artifact_dir']);

        $result = $this->service()->activate((int) $b['sys_deploy_id']);

        $this->assertFalse($result['restored']);
        $this->assertTrue($result['deploy']['is_active']);
    }

    #[Test]
    public function ロックが取れなければdeploy_locked(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $this->buildVersion(2026112202, 'b', '2026-11-23 11:00:00');
        $this->lock->shouldReceive('acquire')->andReturn(false);

        $this->expectError(MasterDeployRunException::DEPLOY_LOCKED, 'deactivate', (int) $a['sys_deploy_id']);
    }

    private function service(): MasterActivationService
    {
        return new MasterActivationService(lock: $this->lock, invalidator: $this->invalidator);
    }

    private function expectError(string $code, string $operation, int $sysDeployId): MasterDeployRunException
    {
        try {
            $operation === 'activate' ? $this->service()->activate($sysDeployId) : $this->service()->deactivate($sysDeployId);
        } catch (MasterDeployRunException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());

            return $e;
        }
        $this->fail("{$code} が投げられるはず");
    }
}

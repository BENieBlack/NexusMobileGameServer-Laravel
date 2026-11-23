<?php

namespace Tests\Feature;

use App\Exceptions\MasterDeployRunException;
use App\Services\MasterArtifactStore;
use App\Services\MasterDeployLock;
use App\Services\MasterDeployService;
use App\Services\MasterRollbackService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * MasterRollbackService（巻き戻し。db.md 3.6）のテスト。版は実物の MasterBuildRunner で作る
 */
class MasterRollbackServiceTest extends MasterVersionedTestCase
{
    #[Test]
    public function 戻し先を指す新しい切り替え行を現在_1秒で足し_dbは作り直さない(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $b = $this->buildVersion(2026112202, 'b', '2026-11-23 11:00:00');

        $result = $this->service()->run((int) $a['sys_deploy_master_id'], ['adm_account_id' => 7]);

        $this->assertSame('success', $result['status']);
        $this->assertSame('2026-11-23 12:00:01', $result['deploy']['start_at']);
        $this->assertFalse($result['deploy']['restored']);
        $this->assertSame($a['mst_database'], $result['deploy']['mst_database']);
        $this->assertSame((int) $b['sys_deploy_id'], $result['deploy']['previous_sys_deploy_id']);
        $row = DB::connection('sys')->table('sys_deploy')->where('id', $result['deploy']['sys_deploy_id'])->first();
        $this->assertSame((int) $a['sys_deploy_master_id'], (int) $row->sys_deploy_master_id);
        $this->assertSame(1, (int) $row->is_active);
        $this->assertSame(3, DB::connection('sys')->table('sys_deploy')->count());
        $this->assertSame(2, DB::connection('sys')->table('sys_deploy_master')->count());
        $this->assertCount(2, $this->databaseList());

        $run = $this->lastRun();
        $this->assertSame('rollback', $run->kind);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('done', $run->phase);
        $this->assertSame(7, (int) $run->adm_account_id);
        $this->assertSame((int) $result['deploy']['sys_deploy_id'], (int) $run->sys_deploy_id);
        $this->assertTrue($this->summaryOf($run)['cache_invalidated']);
    }

    #[Test]
    public function 戻し先がいまの版でも警告だけで止めない(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');

        $result = $this->service()->run((int) $a['sys_deploy_master_id']);

        $this->assertContains('rollback_target_is_current', array_column($result['warning_list'], 'code'));
    }

    #[Test]
    public function 予約済みの未来の行があるとrollback_blocked_by_scheduledで拒否する(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $b = $this->buildVersion(2026112202, 'b', '2026-11-23 13:00:00');

        $error = $this->expectError(MasterDeployRunException::ROLLBACK_BLOCKED_BY_SCHEDULED, (int) $a['sys_deploy_master_id']);

        $this->assertSame([['sys_deploy_id' => (int) $b['sys_deploy_id'], 'deploy_key' => 2026112202, 'start_at' => '2026-11-23 13:00:00']], $error->errorList);
        $this->assertSame('failed', $this->lastRun()->status);
        $this->assertSame(2, DB::connection('sys')->table('sys_deploy')->count());
    }

    #[Test]
    public function 戻し先と別の版を指す近い予約は除外せず拒否する(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $this->buildVersion(2026112202, 'b', '2026-11-23 12:00:30');

        $this->expectError(MasterDeployRunException::ROLLBACK_BLOCKED_BY_SCHEDULED, (int) $a['sys_deploy_master_id']);
    }

    #[Test]
    public function 直前の巻き戻しの行_戻し先と同じ版で60秒以内_は予約済みに数えず続けて巻き戻せる(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $this->buildVersion(2026112202, 'b', '2026-11-23 11:00:00');

        $first = $this->service()->run((int) $a['sys_deploy_master_id']);
        $second = $this->service()->run((int) $a['sys_deploy_master_id']);

        $this->assertSame('success', $second['status']);
        $this->assertNotSame($first['deploy']['sys_deploy_id'], $second['deploy']['sys_deploy_id'], '行が 1 つ増えるだけ（二重登録の防止の対象にしない）');
        $this->assertSame(4, DB::connection('sys')->table('sys_deploy')->count());
    }

    #[Test]
    public function 境界_戻し先と同じ版の行は現在_60秒ちょうどまで除外し_61秒なら拒否する(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $this->buildVersion(2026112202, 'b', '2026-11-23 11:00:00');
        $insert = fn (string $startAt) => DB::connection('sys')->table('sys_deploy')->insertGetId([
            'deploy_key' => 2026112201, 'start_at' => $startAt, 'sys_deploy_master_id' => $a['sys_deploy_master_id'], 'sys_deploy_asset_id' => 1, 'is_active' => 1,
        ]);
        $edge = $insert('2026-11-23 12:01:00');
        $this->assertSame('success', $this->service()->run((int) $a['sys_deploy_master_id'])['status']);

        DB::connection('sys')->table('sys_deploy')->where('id', $edge)->delete();
        $insert('2026-11-23 12:01:01');
        $this->expectError(MasterDeployRunException::ROLLBACK_BLOCKED_BY_SCHEDULED, (int) $a['sys_deploy_master_id']);
    }

    #[Test]
    public function 戻し先の版が無ければrollback_target_not_found(): void
    {
        $this->expectError(MasterDeployRunException::ROLLBACK_TARGET_NOT_FOUND, 999);
    }

    #[Test]
    public function 掃除済みの_d_bは成果物から復元して戻す(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $this->buildVersion(2026112202, 'b', '2026-11-23 11:00:00');
        $this->dropDatabase($a['mst_database']);
        DB::connection('sys')->table('sys_deploy_master')->where('id', $a['sys_deploy_master_id'])->update(['mst_dropped_at' => '2026-11-23 11:30:00']);

        $result = $this->service()->run((int) $a['sys_deploy_master_id']);

        $this->assertTrue($result['deploy']['restored']);
        $this->assertTrue($this->databaseExists($result['deploy']['mst_database']));
        $this->assertSame(2, DB::connection('mst')->table($result['deploy']['mst_database'].'.mst_item')->count());
        $master = DB::connection('sys')->table('sys_deploy_master')->where('id', $a['sys_deploy_master_id'])->first();
        $this->assertNull($master->mst_dropped_at);
        $this->assertSame($result['deploy']['mst_database'], $master->mst_database);
        $run = $this->lastRun();
        $this->assertSame('succeeded', $run->status);
        $this->assertSame([], $this->summaryOf($run)['created_database_list'], 'sys が指す DB になったので run の作成物ではない');
        $this->assertTrue($this->summaryOf($run)['restored']);
    }

    #[Test]
    public function dbも成果物も無ければrollback_target_unavailable(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $this->buildVersion(2026112202, 'b', '2026-11-23 11:00:00');
        $this->dropDatabase($a['mst_database']);
        Storage::disk(MasterArtifactStore::DISK)->deleteDirectory($a['artifact_dir']);

        $this->expectError(MasterDeployRunException::ROLLBACK_TARGET_UNAVAILABLE, (int) $a['sys_deploy_master_id']);

        $this->assertSame(2, DB::connection('sys')->table('sys_deploy')->count());
    }

    #[Test]
    public function スキーマ版が現行と違う版にはschema_mismatchで戻せない(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $this->buildVersion(2026112202, 'b', '2026-11-23 11:00:00');
        // 基底 DB のスキーマが変わった（古い版の DB・成果物は古いスキーマ版のまま）
        DB::connection('mst')->statement('ALTER TABLE mst_empty ADD COLUMN note VARCHAR(10) NULL');

        $this->expectError(MasterDeployRunException::SCHEMA_MISMATCH, (int) $a['sys_deploy_master_id']);

        $this->assertSame(2, DB::connection('sys')->table('sys_deploy')->count());
    }

    #[Test]
    public function 復元後のsys登録が失敗してもsysは変わらず復元した_d_bは孤児として残す(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $this->buildVersion(2026112202, 'b', '2026-11-23 11:00:00');
        $this->dropDatabase($a['mst_database']);
        DB::connection('sys')->table('sys_deploy_master')->where('id', $a['sys_deploy_master_id'])->update(['mst_dropped_at' => '2026-11-23 11:30:00']);

        // COMMIT の前にロールバックしてから例外（COMMIT の成否が不明。読み直すと行が無い）
        $error = $this->expectError(MasterDeployRunException::REGISTER_FAILED, (int) $a['sys_deploy_master_id'], $this->flakyService('before'));

        $this->assertSame(2, DB::connection('sys')->table('sys_deploy')->count());
        $this->assertTrue($this->databaseExists($a['mst_database']), 'COMMIT の成否が不明のときは DROP しない（孤児掃除に回す）');
        $run = $this->lastRun();
        $this->assertSame('failed', $run->status);
        $this->assertSame([$a['mst_database']], $this->summaryOf($run)['created_database_list']);
        $this->assertNotNull($error->runId);
    }

    #[Test]
    public function commit処理中のエラーで登録済みなら成功で警告を返す(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $this->buildVersion(2026112202, 'b', '2026-11-23 11:00:00');

        $result = $this->service($this->flakyService('after'))->run((int) $a['sys_deploy_master_id']);

        $this->assertContains('register_confirmed_after_error', array_column($result['warning_list'], 'code'));
        $this->assertSame('succeeded', $this->lastRun()->status);
    }

    #[Test]
    public function ロックが取れなければdeploy_lockedで実行記録も作らない(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $runs = DB::connection('tool')->table('tol_master_deploy_run')->count();
        $this->lock->shouldReceive('acquire')->andReturn(false);

        $this->expectError(MasterDeployRunException::DEPLOY_LOCKED, (int) $a['sys_deploy_master_id']);

        $this->assertSame($runs, DB::connection('tool')->table('tol_master_deploy_run')->count());
        $this->assertSame(1, DB::connection('sys')->table('sys_deploy')->count());
    }

    #[Test]
    public function dry_runはロックも実行記録も使わず何も変えない(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $this->buildVersion(2026112202, 'b', '2026-11-23 11:00:00');
        $this->dropDatabase($a['mst_database']);
        $runs = DB::connection('tool')->table('tol_master_deploy_run')->count();
        $lock = \Mockery::mock(MasterDeployLock::class);
        $lock->shouldNotReceive('acquire');

        $result = (new MasterRollbackService(lock: $lock, invalidator: $this->invalidator))->run((int) $a['sys_deploy_master_id'], ['dry_run' => true]);

        $this->assertTrue($result['dry_run']);
        $this->assertTrue($result['needs_restore']);
        $this->assertSame('2026-11-23 12:00:01', $result['deploy']['start_at']);
        $this->assertNull($result['run_id']);
        $this->assertSame($runs, DB::connection('tool')->table('tol_master_deploy_run')->count());
        $this->assertSame(2, DB::connection('sys')->table('sys_deploy')->count());
        $this->assertFalse($this->databaseExists($a['mst_database']));
    }

    #[Test]
    public function schedule_cacheの破棄に失敗しても成功のまま警告を返す(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $this->invalidator->shouldReceive('forgetSchedule')->andReturn(['invalidated' => false, 'failed_key_list' => ['x']]);

        $result = $this->service()->run((int) $a['sys_deploy_master_id']);

        $this->assertFalse($result['cache']['invalidated']);
        $this->assertContains('schedule_cache_not_invalidated', array_column($result['warning_list'], 'code'));
        $this->assertSame('succeeded', $this->lastRun()->status);
    }

    private function service(?MasterDeployService $deployService = null): MasterRollbackService
    {
        return new MasterRollbackService(lock: $this->lock, invalidator: $this->invalidator, deployService: $deployService);
    }

    private function expectError(string $code, int $masterId, ?MasterDeployService $deployService = null): MasterDeployRunException
    {
        try {
            $this->service($deployService)->run($masterId);
        } catch (MasterDeployRunException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());

            return $e;
        }
        $this->fail("{$code} が投げられるはず");
    }

    private function flakyService(string $mode): MasterDeployService
    {
        return new class($mode) extends MasterDeployService
        {
            public function __construct(private readonly string $mode) {}

            protected function commitSys(ConnectionInterface $sys): void
            {
                if ($this->mode === 'after') {
                    $sys->commit();
                } else {
                    $sys->rollBack();
                }

                throw new RuntimeException('connection lost');
            }
        };
    }
}

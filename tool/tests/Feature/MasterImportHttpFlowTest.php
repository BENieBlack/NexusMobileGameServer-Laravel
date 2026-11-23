<?php

namespace Tests\Feature;

use App\Models\Adm\AdmAccount;
use App\Services\MasterActivationService;
use App\Services\MasterArtifactStore;
use App\Services\MasterBuildRunner;
use App\Services\MasterCancelScheduleService;
use App\Services\MasterCleanupService;
use App\Services\MasterDeployLock;
use App\Services\MasterFinalizeRunner;
use App\Services\MasterImportRunner;
use App\Services\MasterRollbackService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * マスターデプロイの HTTP と、実物の Runner・Service との結合テスト（api.md 3〜9 節）
 *
 * mst は実 MySQL の使い捨て DB、sys・tool はメモリの SQLite（MasterVersionedTestCase）。ロックと schedule キャッシュだけモック。
 * 版は buildVersion（実物の MasterBuildRunner）で作り、各操作は HTTP から呼ぶ。
 */
class MasterImportHttpFlowTest extends MasterVersionedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->lock->shouldReceive('isFree')->andReturn(true)->byDefault();

        $account = new AdmAccount;
        $account->forceFill(['id' => 7, 'name' => 'operator', 'email' => 'flow@example.com']);
        $this->actingAs($account);

        // target はコンテナから作られるので、ロックだけモックにする（IS_FREE_LOCK は MySQL 専用）
        $this->app->instance(MasterDeployLock::class, $this->lock);
        // 実物の操作に、モックのロックと schedule キャッシュを渡して差し込む
        $this->app->instance(MasterRollbackService::class, new MasterRollbackService(lock: $this->lock, invalidator: $this->invalidator));
        $this->app->instance(MasterCancelScheduleService::class, new MasterCancelScheduleService(lock: $this->lock, invalidator: $this->invalidator));
        $this->app->instance(MasterCleanupService::class, new MasterCleanupService(lock: $this->lock, invalidator: $this->invalidator));
        $this->app->instance(MasterActivationService::class, new MasterActivationService(lock: $this->lock, invalidator: $this->invalidator));
        $this->app->instance(MasterImportRunner::class, new MasterImportRunner(lock: $this->lock, invalidator: $this->invalidator));
    }

    private function bindBuild(): void
    {
        $this->app->instance(MasterBuildRunner::class, new MasterBuildRunner(lock: $this->lock, sheetServiceFactory: $this->lastSheetFactory(), invalidator: $this->invalidator));
    }

    #[Test]
    public function buildのdry_runは何も作らず_buildは版を登録して結果の形を返す(): void
    {
        $first = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $this->bindBuild();
        $databases = $this->databaseList();

        $this->postJson('/master-import/build', ['dry_run' => true])
            ->assertOk()->assertJsonPath('dry_run', true)->assertJsonPath('run_id', null)->assertJsonPath('deploy.deploy_key', 2026112201);
        $this->assertSame($databases, $this->databaseList());

        $this->postJson('/master-import/build', ['start_at' => '2026-11-23 11:00:00'])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('deploy.is_new', false)
            ->assertJsonPath('deploy.database_reused', true)
            ->assertJsonPath('deploy.start_at', '2026-11-23 11:00:00')
            ->assertJsonPath('deploy.hash', $first['hash'])
            ->assertJsonStructure(['run_id', 'build' => ['table_list'], 'artifact' => ['artifact_dir', 'tables'], 'cache' => ['invalidated']]);
        $this->assertSame(7, (int) $this->lastRun()->adm_account_id);
    }

    #[Test]
    public function buildがimportの環境では403_role_forbiddenで_codeとrun_idを返す(): void
    {
        $this->bindBuild();
        Config::set('master_deploy.role', 'import');

        $this->postJson('/master-import/build')
            ->assertStatus(403)->assertExactJson(['status' => 'error', 'code' => 'role_forbidden', 'message' => 'この環境（role=import）では build はできません（build の環境だけ）。', 'error_list' => [], 'run_id' => null]);
    }

    #[Test]
    public function 巻き戻しは予約があれば409で_取り消し後に通る_dry_runは何も変えない(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $b = $this->buildVersion(2026112202, 'b', '2026-11-23 11:00:00');
        $c = $this->buildVersion(2026112203, 'c', '2026-11-23 14:00:00');

        $this->postJson('/master-import/rollback', ['sys_deploy_master_id' => $a['sys_deploy_master_id']])
            ->assertStatus(409)->assertJsonPath('code', 'rollback_blocked_by_scheduled')
            ->assertJsonPath('error_list.0.sys_deploy_id', (int) $c['sys_deploy_id']);
        $this->postJson('/master-import/rollback', ['sys_deploy_master_id' => 999])->assertStatus(404)->assertJsonPath('code', 'rollback_target_not_found');

        $this->postJson('/master-import/cancel-schedule', ['sys_deploy_id' => $c['sys_deploy_id']])
            ->assertOk()->assertJsonPath('cancelled.sys_deploy_id', (int) $c['sys_deploy_id'])->assertJsonPath('cache.invalidated', true);
        $this->postJson('/master-import/cancel-schedule', ['sys_deploy_id' => $c['sys_deploy_id']])->assertStatus(404)->assertJsonPath('code', 'schedule_not_found');

        $before = DB::connection('sys')->table('sys_deploy')->count();
        $this->postJson('/master-import/rollback', ['sys_deploy_master_id' => $a['sys_deploy_master_id'], 'dry_run' => true])
            ->assertOk()->assertJsonPath('dry_run', true)->assertJsonPath('deploy.start_at', '2026-11-23 12:00:01');
        $this->assertSame($before, DB::connection('sys')->table('sys_deploy')->count());

        $this->postJson('/master-import/rollback', ['sys_deploy_master_id' => $a['sys_deploy_master_id']])
            ->assertOk()->assertJsonPath('deploy.sys_deploy_master_id', (int) $a['sys_deploy_master_id'])->assertJsonPath('deploy.previous_sys_deploy_id', (int) $b['sys_deploy_id']);
    }

    #[Test]
    public function 近い予約の取り消しは409_schedule_cancel_too_soonで_緊急停止と再開が通る(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $b = $this->buildVersion(2026112202, 'b', '2026-11-23 12:01:00');

        $this->postJson('/master-import/cancel-schedule', ['sys_deploy_id' => $b['sys_deploy_id']])->assertStatus(409)->assertJsonPath('code', 'schedule_cancel_too_soon');
        $this->postJson('/master-import/cancel-schedule', ['sys_deploy_id' => $a['sys_deploy_id']])->assertStatus(409)->assertJsonPath('code', 'schedule_already_started');

        $this->postJson('/master-import/deactivate', ['sys_deploy_id' => $b['sys_deploy_id']])
            ->assertOk()->assertJsonPath('deploy.is_active', false)->assertJsonPath('code', null);
        $this->postJson('/master-import/deactivate', ['sys_deploy_id' => $b['sys_deploy_id']])->assertOk()->assertJsonPath('code', 'already_in_state');
        // いまの版 a を止めると、落ちる先が無い
        $this->postJson('/master-import/deactivate', ['sys_deploy_id' => $a['sys_deploy_id']])->assertStatus(409)->assertJsonPath('code', 'deactivate_no_fallback');
        $this->postJson('/master-import/deactivate', ['sys_deploy_id' => 999])->assertStatus(404)->assertJsonPath('code', 'deploy_not_found');

        $this->postJson('/master-import/activate', ['sys_deploy_id' => $b['sys_deploy_id']])->assertOk()->assertJsonPath('deploy.is_active', true)->assertJsonPath('restored', false);
    }

    #[Test]
    public function targetとartifact_listは実際の状態を返す(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');

        $this->getJson('/master-import/target')
            ->assertOk()
            ->assertJsonPath('role', 'build')
            ->assertJsonPath('current_version.sys_deploy_id', (int) $a['sys_deploy_id'])
            ->assertJsonPath('version_list.0.rollback_state', 'ok')
            ->assertJsonPath('now', '2026-11-23 12:00:00');
        $this->getJson('/master-import/artifact-list?source=own')->assertOk()->assertJsonPath('artifact_list.0.artifact_dir', $a['artifact_dir']);
        $this->getJson('/master-import/artifact-list')->assertOk()->assertJsonPath('artifact_list', []);
    }

    #[Test]
    public function cleanupの既定はdry_runで_clear_cacheはキーを破棄する(): void
    {
        $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');

        $this->postJson('/master-import/cleanup')->assertOk()->assertJsonPath('dry_run', true)->assertJsonPath('run_id', null)->assertJsonPath('dropped_list', []);
        $this->postJson('/master-import/cleanup', ['dry_run' => false])->assertOk()->assertJsonPath('dry_run', false)->assertJsonStructure(['run_id', 'keep_list', 'drop_list']);
        $this->assertSame('cleanup', $this->lastRun()->kind);
    }

    #[Test]
    public function finalizeとimportはartifact_listの成果物でつながる(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $sheets = $this->lastSheetFactory();
        $this->app->instance(MasterFinalizeRunner::class, new MasterFinalizeRunner(
            static fn (int $key): array => (new MasterBuildRunner(sheetServiceFactory: $sheets))->computeRowDigest($key),
            lock: $this->lock,
            invalidator: $this->invalidator,
        ));

        $this->postJson('/master-import/finalize', ['artifact_dir' => $a['artifact_dir'], 'dry_run' => true])->assertOk()->assertJsonPath('follow_check.status', 'match')->assertJsonPath('run_id', null);
        $this->postJson('/master-import/finalize', ['artifact_dir' => $a['artifact_dir']])->assertOk()->assertJsonPath('code', 'finalized');
        $this->postJson('/master-import/finalize', ['artifact_dir' => $a['artifact_dir']])->assertOk()->assertJsonPath('code', 'already_finalized');
        $this->assertTrue(Storage::disk(MasterArtifactStore::DISK)->exists($a['artifact_dir'].'/final.json'));
        $this->postJson('/master-import/finalize', ['artifact_dir' => '2026112299_0123456789abcdef'])->assertStatus(404)->assertJsonPath('code', 'artifact_not_found');

        // review 以降の環境へ: 上流へ写して import する
        $this->publishUpstream($a['artifact_dir']);
        $this->becomeImportEnvironment();
        Config::set('master_deploy.require_finalized', true);

        $this->getJson('/master-import/artifact-list')->assertOk()->assertJsonPath('artifact_list.0.artifact_dir', $a['artifact_dir'])->assertJsonPath('artifact_list.0.finalized', true)->assertJsonPath('artifact_list.0.imported', false);
        $this->postJson('/master-import/import', ['artifact_dir' => $a['artifact_dir'], 'start_at' => null, 'dry_run' => true])->assertOk()->assertJsonPath('dry_run', true)->assertJsonPath('run_id', null);
        $this->assertSame([], $this->databaseList());
        $this->postJson('/master-import/import', ['artifact_dir' => $a['artifact_dir'], 'start_at' => '2026-11-24 00:00:00'])
            ->assertOk()->assertJsonPath('deploy.is_new', true)->assertJsonPath('deploy.start_at', '2026-11-24 00:00:00')->assertJsonPath('import.table_list.0.table', 'mst_empty');
        $this->getJson('/master-import/artifact-list')->assertOk()->assertJsonPath('artifact_list.0.imported', true);
        $this->getJson('/master-import/target')->assertOk()->assertJsonPath('role', 'import')->assertJsonPath('scheduled_list.0.start_at', '2026-11-24 00:00:00');

        // import の環境での build・finalize は拒否される。印の無い成果物は not_finalized
        $this->postJson('/master-import/finalize', ['artifact_dir' => $a['artifact_dir']])->assertStatus(403)->assertJsonPath('code', 'role_forbidden');
        $this->postJson('/master-import/import', ['artifact_dir' => '2026112299_0123456789abcdef', 'start_at' => null])->assertStatus(404)->assertJsonPath('code', 'artifact_not_found');
    }
}

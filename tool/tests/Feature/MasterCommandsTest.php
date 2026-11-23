<?php

namespace Tests\Feature;

use App\Exceptions\MasterDeployRunException;
use App\Services\MasterActivationService;
use App\Services\MasterBuildRunner;
use App\Services\MasterCancelScheduleService;
use App\Services\MasterCleanupService;
use App\Services\MasterFinalizeRunner;
use App\Services\MasterImportRunner;
use App\Services\MasterResolveUnknownService;
use App\Services\MasterRollbackService;
use App\Support\MasterDeployFailure;
use Illuminate\Testing\PendingCommand;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * master:* コマンド（api.md 10 節）のテスト: 引数の検査・Runner への引き渡し・出力・終了コード（13.1）
 *
 * Runner・Service はモックにする（DB は使わない）。実物との結合は MasterCommandsFlowTest。
 */
class MasterCommandsTest extends TestCase
{
    private const DIR = '2026112201_0123456789abcdef';

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T&MockInterface
     */
    private function bindMock(string $class): object
    {
        $mock = Mockery::mock($class);
        $this->app->instance($class, $mock);

        return $mock;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function run_(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    // ---- 終了コードの対応（13.1 の「終了コード」の列） ----

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function exitCodes(): array
    {
        $table = [
            1 => ['load_failed', 'export_failed', 'pull_failed', 'store_failed', 'register_failed', 'register_unknown', 'mst_database_collision', 'artifact_dir_collision',
                'sheet_read_failed', 'cleanup_failed', 'drop_failed', 'sys_unreadable', 'cache_clear_failed', 'internal_error'],
            2 => ['sheet_invalid', 'artifact_invalid', 'not_finalized', 'finalize_conflict', 'schema_mismatch', 'confirmed_key_modified', 'deploy_key_regression',
                'reference_unavailable', 'start_at_past', 'sheet_changed_since_build', 'deploy_key_mismatch', 'artifact_not_found'],
            3 => ['deploy_locked'],
            4 => ['role_forbidden', 'fixed_key_forbidden', 'fixed_key_invalid', 'deploy_key_not_configured', 'reserved_key_forbidden', 'base_database_mismatch',
                'mst_database_name_too_long', 'upstream_not_configured', 'sheet_not_configured', 'follow_check_unavailable', 'rollback_blocked_by_scheduled',
                'rollback_target_not_found', 'rollback_target_unavailable', 'schedule_not_found', 'schedule_already_started', 'schedule_cancel_too_soon',
                'deploy_not_found', 'deactivate_no_fallback', 'activate_target_dropped', 'run_not_found', 'drop_forbidden'],
        ];
        $cases = [];
        foreach ($table as $exit => $codes) {
            foreach ($codes as $code) {
                $cases[$code] = [$code, $exit];
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('exitCodes')]
    public function エラーコードは13_1の終了コードに対応する(string $code, int $exit): void
    {
        $this->assertSame($exit, MasterDeployFailure::exitCode($code));
    }

    #[Test]
    public function 失敗は終了コードと_code_message_run_id_error_listを標準出力に出す(): void
    {
        $this->bindMock(MasterRollbackService::class)->shouldReceive('run')->once()->andThrow(
            new MasterDeployRunException('rollback_blocked_by_scheduled', '予約があります', 409, [['sys_deploy_id' => 3, 'deploy_key' => 2026112202, 'start_at' => '2026-11-23 13:00:00']], 12),
        );

        $this->run_('master:rollback', ['sys_deploy_master_id' => '5'])
            ->expectsOutputToContain('[失敗] rollback_blocked_by_scheduled: 予約があります')
            ->expectsOutputToContain('run_id: 12')
            ->expectsOutputToContain('error_list:')
            ->expectsOutputToContain('2026-11-23 13:00:00 UTC (2026-11-23 22:00:00 JST)')
            ->assertExitCode(4);
    }

    #[Test]
    public function 排他は3_検証の失敗は2_実行中の失敗と想定外は1(): void
    {
        $service = $this->bindMock(MasterCancelScheduleService::class);
        $service->shouldReceive('run')->once()->andThrow(new MasterDeployRunException('deploy_locked', '実行中', 409));
        $service->shouldReceive('run')->once()->andThrow(new MasterDeployRunException('sheet_invalid', '検証失敗', 422));
        $service->shouldReceive('run')->once()->andThrow(new RuntimeException('SQLSTATE secret'));

        $this->run_('master:cancel-schedule', ['sys_deploy_id' => '1'])->expectsOutputToContain('deploy_locked')->assertExitCode(3);
        $this->run_('master:cancel-schedule', ['sys_deploy_id' => '1'])->assertExitCode(2);
        $this->run_('master:cancel-schedule', ['sys_deploy_id' => '1'])->expectsOutputToContain('internal_error')->doesntExpectOutputToContain('SQLSTATE')->assertExitCode(1);
    }

    #[Test]
    public function register_unknownはresolve_unknownを案内して1(): void
    {
        $this->bindMock(MasterBuildRunner::class)->shouldReceive('run')->once()->andThrow(new MasterDeployRunException('register_unknown', '成否不明', 500, [], 41));

        $this->run_('master:build')
            ->expectsOutputToContain('master:resolve-unknown 41')
            ->assertExitCode(1);
    }

    #[Test]
    public function sheet_invalidのerror_listを出す(): void
    {
        $this->bindMock(MasterBuildRunner::class)->shouldReceive('run')->once()->andThrow(
            new MasterDeployRunException('sheet_invalid', '検証に失敗', 422, [['sheet' => 'シート1', 'row' => 5, 'code' => 'id_empty', 'message' => 'id が空です']], null, ['error_total' => 1]),
        );

        $this->run_('master:build', ['--dry-run' => true])
            ->expectsOutputToContain('id_empty')
            ->expectsOutputToContain('error_total: 1')
            ->assertExitCode(2);
    }

    // ---- 成功 ----

    #[Test]
    public function buildは_start_atとdry_runを渡し_成功は0_日時にjstを併記する(): void
    {
        $this->bindMock(MasterBuildRunner::class)->shouldReceive('run')->once()->with(['dry_run' => true, 'start_at' => '2026-12-01 15:00:00'])->andReturn([
            'status' => 'success', 'message' => 'dry_run: 検証しました', 'dry_run' => true, 'run_id' => null,
            'deploy' => ['mode' => 'explicit', 'deploy_key' => 2026120101, 'hash' => null, 'start_at' => '2026-12-01 15:00:00'],
            'build' => ['table_list' => [['table' => 'mst_item', 'input_rows' => 9, 'adopted_rows' => 6]], 'warning_list' => []],
            'artifact' => null,
        ]);

        $this->run_('master:build', ['--dry-run' => true, '--start-at' => '2026-12-01 15:00:00'])
            ->expectsOutputToContain('[成功] dry_run: 検証しました')
            ->expectsOutputToContain('deploy.deploy_key: 2026120101')
            ->expectsOutputToContain('deploy.start_at: 2026-12-01 15:00:00 UTC (2026-12-02 00:00:00 JST)')
            ->expectsOutputToContain('build.table_list:')
            ->expectsOutputToContain('mst_item')
            ->assertExitCode(0);
    }

    #[Test]
    public function buildのstart_at省略は_すぐ_null(): void
    {
        $this->bindMock(MasterBuildRunner::class)->shouldReceive('run')->once()->with(['dry_run' => false, 'start_at' => null])->andReturn(['status' => 'success', 'message' => 'ok', 'run_id' => 3]);

        $this->run_('master:build')->expectsOutputToContain('run_id: 3')->assertExitCode(0);
    }

    #[Test]
    public function 成功に付く成功コードと警告は0(): void
    {
        $this->bindMock(MasterFinalizeRunner::class)->shouldReceive('run')->once()->with(self::DIR, ['dry_run' => false, 'allow_unverified' => true])->andReturn([
            'status' => 'success', 'code' => 'finalized', 'message' => '印を付けました', 'run_id' => 5, 'follow_check' => ['status' => 'unverified'],
            'warning_list' => [['code' => 'follow_check_unverified', 'message' => '確かめていません']],
        ]);

        $this->run_('master:finalize', ['artifact_dir' => self::DIR, '--allow-unverified' => true])
            ->expectsOutputToContain('[成功] (finalized) 印を付けました')
            ->expectsOutputToContain('follow_check_unverified')
            ->assertExitCode(0);
    }

    #[Test]
    public function verify_sheetはfinalizeのdry_runと同じ処理(): void
    {
        $this->bindMock(MasterFinalizeRunner::class)->shouldReceive('run')->once()->with(self::DIR, ['dry_run' => true])->andReturn(['status' => 'success', 'message' => 'match', 'follow_check' => ['status' => 'match']]);

        $this->run_('master:verify-sheet', ['artifact_dir' => self::DIR])->expectsOutputToContain('follow_check.status: match')->assertExitCode(0);
    }

    #[Test]
    public function importはstart_atが必須で_nowは_すぐ(): void
    {
        $runner = $this->bindMock(MasterImportRunner::class);
        $runner->shouldReceive('run')->once()->with(self::DIR, ['dry_run' => false, 'start_at' => null])->andReturn(['status' => 'success', 'message' => '取り込みました']);
        $runner->shouldReceive('run')->once()->with(self::DIR, ['dry_run' => true, 'start_at' => '2026-11-24 00:00:00'])->andReturn(['status' => 'success', 'message' => 'dry']);

        $this->run_('master:import', ['artifact_dir' => self::DIR])->expectsOutputToContain('--start-at は必須')->assertExitCode(2);
        $this->run_('master:import', ['artifact_dir' => self::DIR, '--start-at' => 'now'])->assertExitCode(0);
        $this->run_('master:import', ['artifact_dir' => self::DIR, '--start-at' => '2026-11-24 00:00:00', '--dry-run' => true])->assertExitCode(0);
    }

    #[Test]
    public function rollbackとcancel_scheduleとresolve_unknownとactivate系は_idを渡す(): void
    {
        $this->bindMock(MasterRollbackService::class)->shouldReceive('run')->once()->with(5, ['dry_run' => true])->andReturn(['status' => 'success', 'message' => 'rb']);
        $this->bindMock(MasterCancelScheduleService::class)->shouldReceive('run')->once()->with(9)->andReturn(['status' => 'success', 'message' => 'cs']);
        $this->bindMock(MasterResolveUnknownService::class)->shouldReceive('run')->once()->with(4)->andReturn(['status' => 'success', 'code' => 'already_resolved', 'message' => 'rs']);
        $activation = $this->bindMock(MasterActivationService::class);
        $activation->shouldReceive('deactivate')->once()->with(9)->andReturn(['status' => 'success', 'message' => 'de']);
        $activation->shouldReceive('activate')->once()->with(9)->andReturn(['status' => 'success', 'code' => 'already_in_state', 'message' => 'ac']);

        $this->run_('master:rollback', ['sys_deploy_master_id' => '5', '--dry-run' => true])->expectsOutputToContain('rb')->assertExitCode(0);
        $this->run_('master:cancel-schedule', ['sys_deploy_id' => '9'])->assertExitCode(0);
        $this->run_('master:resolve-unknown', ['run_id' => '4'])->expectsOutputToContain('(already_resolved)')->assertExitCode(0);
        $this->run_('master:deactivate', ['sys_deploy_id' => '9'])->assertExitCode(0);
        $this->run_('master:activate', ['sys_deploy_id' => '9'])->expectsOutputToContain('(already_in_state)')->assertExitCode(0);
    }

    #[Test]
    public function cleanupは_executeを付けないとdry_run(): void
    {
        $service = $this->bindMock(MasterCleanupService::class);
        $service->shouldReceive('run')->once()->with(['dry_run' => true])->andReturn(['status' => 'success', 'message' => 'dry', 'drop_list' => [['kind' => 'version', 'name' => 'db-1', 'reason' => 'outside_keep']]]);
        $service->shouldReceive('run')->once()->with(['dry_run' => false])->andReturn(['status' => 'success', 'message' => 'done']);

        $this->run_('master:cleanup')->expectsOutputToContain('db-1')->assertExitCode(0);
        $this->run_('master:cleanup', ['--execute' => true])->assertExitCode(0);
    }

    #[Test]
    public function cleanup_failedは1で_消せたものと消せなかったものを出す(): void
    {
        $this->bindMock(MasterCleanupService::class)->shouldReceive('run')->once()->andThrow(
            new MasterDeployRunException('cleanup_failed', '消せなかった', 500, [], 8, ['dropped_list' => [['kind' => 'work', 'name' => 'w1']], 'failed_list' => [['kind' => 'version', 'name' => 'v1', 'message' => 'x']]]),
        );

        $this->run_('master:cleanup', ['--execute' => true])->expectsOutputToContain('dropped_list:')->expectsOutputToContain('failed_list:')->assertExitCode(1);
    }

    // ---- 入力の検査（終了コード 2。Runner は呼ばない） ----

    #[Test]
    public function 入力の形式の誤りは2でrunnerを呼ばない(): void
    {
        foreach ([MasterBuildRunner::class, MasterImportRunner::class, MasterFinalizeRunner::class, MasterRollbackService::class, MasterCancelScheduleService::class, MasterActivationService::class, MasterResolveUnknownService::class] as $class) {
            $this->bindMock($class)->shouldNotReceive('run', 'deactivate', 'activate');
        }

        $this->run_('master:build', ['--start-at' => '2026/12/01'])->expectsOutputToContain('--start-at')->assertExitCode(2);
        $this->run_('master:import', ['artifact_dir' => '../x', '--start-at' => 'now'])->expectsOutputToContain('artifact_dir')->assertExitCode(2);
        $this->run_('master:import', ['artifact_dir' => self::DIR, '--start-at' => 'tomorrow'])->assertExitCode(2);
        $this->run_('master:finalize', ['artifact_dir' => 'abc'])->assertExitCode(2);
        $this->run_('master:verify-sheet', ['artifact_dir' => 'abc'])->assertExitCode(2);
        $this->run_('master:rollback', ['sys_deploy_master_id' => '0'])->assertExitCode(2);
        $this->run_('master:cancel-schedule', ['sys_deploy_id' => '-1'])->assertExitCode(2);
        $this->run_('master:deactivate', ['sys_deploy_id' => 'x'])->assertExitCode(2);
        $this->run_('master:activate', ['sys_deploy_id' => '1.5'])->assertExitCode(2);
        $this->run_('master:resolve-unknown', ['run_id' => 'x'])->assertExitCode(2);
    }
}

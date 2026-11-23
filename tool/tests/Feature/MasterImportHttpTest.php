<?php

namespace Tests\Feature;

use App\Exceptions\MasterDeployRunException;
use App\Models\Adm\AdmAccount;
use App\Services\GoogleSpreadsheetService;
use App\Services\MasterActivationService;
use App\Services\MasterBuildRunner;
use App\Services\MasterCacheInvalidator;
use App\Services\MasterCancelScheduleService;
use App\Services\MasterCleanupService;
use App\Services\MasterDeployTargetService;
use App\Services\MasterFinalizeRunner;
use App\Services\MasterImportRunner;
use App\Services\MasterResolveUnknownService;
use App\Services\MasterRollbackService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * マスターデプロイの HTTP（api.md 2〜9・13 節）のテスト: 認可・入力の検査・各操作への引き渡し・エラーコードと HTTP ステータス
 *
 * Runner・Service・target の組み立て・キャッシュ破棄はモックにする（DB は使わない）。実物の操作との結合は MasterImportHttpFlowTest。
 * ログインは DB に触れないメモリ上の管理者で行う。
 */
class MasterImportHttpTest extends TestCase
{
    private const DIR = '2026112201_0123456789abcdef';

    private function login(): void
    {
        $account = new AdmAccount;
        $account->forceFill(['id' => 7, 'name' => 'operator', 'email' => 'master-deploy@example.com']);
        $this->actingAs($account);
    }

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

    // ---- 認可 ----

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function allRoutes(): array
    {
        $routes = [];
        foreach (['target', 'artifact-list', 'all-sheets', 'spreadsheets', 'sheets', 'preview'] as $path) {
            $routes["GET {$path}"] = ['get', "/master-import/{$path}"];
        }
        foreach (['build', 'import', 'finalize', 'rollback', 'cancel-schedule', 'cleanup', 'clear-cache', 'resolve-unknown', 'deactivate', 'activate'] as $path) {
            $routes["POST {$path}"] = ['post', "/master-import/{$path}"];
        }

        return $routes;
    }

    #[Test]
    #[DataProvider('allRoutes')]
    public function 未ログインはログイン画面へリダイレクトする(string $method, string $uri): void
    {
        $this->{$method}($uri)->assertRedirect('/login');
    }

    #[Test]
    #[DataProvider('removedRoutes')]
    public function 廃止した旧ルートは404(string $uri): void
    {
        $this->login();

        $this->postJson($uri)->assertNotFound();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function removedRoutes(): array
    {
        return ['deploy' => ['/master-import/deploy'], 'execute' => ['/master-import/execute'], 'export' => ['/master-import/export']];
    }

    // ---- 入力の検査（Laravel 標準の 422） ----

    #[Test]
    public function 入力の形式の誤りは422(): void
    {
        $this->login();

        $this->postJson('/master-import/build', ['dry_run' => 'maybe', 'start_at' => '2026/11/23'])->assertStatus(422)->assertJsonValidationErrors(['dry_run', 'start_at']);
        $this->postJson('/master-import/import', [])->assertStatus(422)->assertJsonValidationErrors(['artifact_dir', 'start_at']);
        $this->postJson('/master-import/import', ['artifact_dir' => '../x', 'start_at' => null])->assertStatus(422)->assertJsonValidationErrors('artifact_dir');
        $this->postJson('/master-import/import', ['artifact_dir' => self::DIR.'0', 'start_at' => null])->assertStatus(422)->assertJsonValidationErrors('artifact_dir');
        $this->postJson('/master-import/finalize', ['artifact_dir' => 'abc', 'allow_unverified' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['artifact_dir', 'allow_unverified']);
        $this->postJson('/master-import/rollback', ['sys_deploy_master_id' => 0])->assertStatus(422)->assertJsonValidationErrors('sys_deploy_master_id');
        $this->postJson('/master-import/rollback', [])->assertStatus(422)->assertJsonValidationErrors('sys_deploy_master_id');
        foreach (['cancel-schedule', 'deactivate', 'activate'] as $path) {
            $this->postJson("/master-import/{$path}", ['sys_deploy_id' => 'x'])->assertStatus(422)->assertJsonValidationErrors('sys_deploy_id');
        }
        $this->postJson('/master-import/cleanup', ['dry_run' => 'x'])->assertStatus(422)->assertJsonValidationErrors('dry_run');
        $this->postJson('/master-import/resolve-unknown', ['run_id' => -1])->assertStatus(422)->assertJsonValidationErrors('run_id');
        $this->getJson('/master-import/artifact-list?source=other')->assertStatus(422)->assertJsonValidationErrors('source');
    }

    // ---- 各操作への引き渡し ----

    #[Test]
    public function targetは組み立てた対象をそのまま返す(): void
    {
        $this->login();
        Config::set('services.google_spreadsheet.credentials_json', '{}');
        Config::set('services.google_spreadsheet.folder_id', 'folder');
        $this->bindMock(MasterDeployTargetService::class)->shouldReceive('build')->once()->with(true)->andReturn(['app_env' => 'testing', 'role' => 'build', 'version_list' => []]);

        $this->getJson('/master-import/target')->assertOk()->assertExactJson(['app_env' => 'testing', 'role' => 'build', 'version_list' => []]);
    }

    #[Test]
    public function artifact_listの既定はupstream(): void
    {
        $this->login();
        $target = $this->bindMock(MasterDeployTargetService::class);
        $target->shouldReceive('artifactList')->once()->with('upstream')->andReturn([['artifact_dir' => self::DIR]]);
        $target->shouldReceive('artifactList')->once()->with('own')->andReturn([]);

        $this->getJson('/master-import/artifact-list')->assertOk()->assertExactJson(['status' => 'success', 'artifact_list' => [['artifact_dir' => self::DIR]]]);
        $this->getJson('/master-import/artifact-list?source=own')->assertOk()->assertExactJson(['status' => 'success', 'artifact_list' => []]);
    }

    #[Test]
    public function buildは入力と管理者を渡して結果を返す(): void
    {
        $this->login();
        $this->bindMock(MasterBuildRunner::class)->shouldReceive('run')->once()
            ->with(['dry_run' => true, 'start_at' => '2026-12-01 15:00:00', 'adm_account_id' => 7])
            ->andReturn(['status' => 'success', 'dry_run' => true, 'run_id' => null]);

        $this->postJson('/master-import/build', ['dry_run' => true, 'start_at' => '2026-12-01 15:00:00'])
            ->assertOk()->assertExactJson(['status' => 'success', 'dry_run' => true, 'run_id' => null]);
    }

    #[Test]
    public function buildのstart_at省略は_すぐ_null(): void
    {
        $this->login();
        $this->bindMock(MasterBuildRunner::class)->shouldReceive('run')->once()
            ->with(['dry_run' => false, 'start_at' => null, 'adm_account_id' => 7])
            ->andReturn(['status' => 'success']);

        $this->postJson('/master-import/build')->assertOk();
    }

    #[Test]
    public function importはstart_atのキーが必須で_nullは通る(): void
    {
        $this->login();
        $this->bindMock(MasterImportRunner::class)->shouldReceive('run')->once()
            ->with(self::DIR, ['dry_run' => false, 'start_at' => null, 'adm_account_id' => 7])
            ->andReturn(['status' => 'success', 'run_id' => 3]);

        $this->postJson('/master-import/import', ['artifact_dir' => self::DIR, 'start_at' => null])->assertOk()->assertJsonPath('run_id', 3);
    }

    #[Test]
    public function finalizeはallow_unverifiedを渡す(): void
    {
        $this->login();
        $this->bindMock(MasterFinalizeRunner::class)->shouldReceive('run')->once()
            ->with(self::DIR, ['dry_run' => false, 'allow_unverified' => true, 'adm_account_id' => 7])
            ->andReturn(['status' => 'success', 'code' => 'finalized']);

        $this->postJson('/master-import/finalize', ['artifact_dir' => self::DIR, 'allow_unverified' => true])->assertOk()->assertJsonPath('code', 'finalized');
    }

    #[Test]
    public function rollbackとcancel_scheduleとactivate系は_idを渡す(): void
    {
        $this->login();
        $this->bindMock(MasterRollbackService::class)->shouldReceive('run')->once()->with(5, ['dry_run' => true, 'adm_account_id' => 7])->andReturn(['status' => 'success']);
        $this->bindMock(MasterCancelScheduleService::class)->shouldReceive('run')->once()->with(9, ['adm_account_id' => 7])->andReturn(['status' => 'success']);
        $activation = $this->bindMock(MasterActivationService::class);
        $activation->shouldReceive('deactivate')->once()->with(9, ['adm_account_id' => 7])->andReturn(['status' => 'success', 'code' => null]);
        $activation->shouldReceive('activate')->once()->with(9, ['adm_account_id' => 7])->andReturn(['status' => 'success', 'code' => 'already_in_state']);
        $this->bindMock(MasterResolveUnknownService::class)->shouldReceive('run')->once()->with(4, ['adm_account_id' => 7])->andReturn(['status' => 'success', 'code' => 'resolved']);

        $this->postJson('/master-import/rollback', ['sys_deploy_master_id' => 5, 'dry_run' => true])->assertOk();
        $this->postJson('/master-import/cancel-schedule', ['sys_deploy_id' => 9])->assertOk();
        $this->postJson('/master-import/deactivate', ['sys_deploy_id' => 9])->assertOk();
        $this->postJson('/master-import/activate', ['sys_deploy_id' => 9])->assertOk()->assertJsonPath('code', 'already_in_state');
        $this->postJson('/master-import/resolve-unknown', ['run_id' => 4])->assertOk()->assertJsonPath('code', 'resolved');
    }

    #[Test]
    public function cleanupの既定はdry_run_trueで_falseを送ると実行する(): void
    {
        $this->login();
        $service = $this->bindMock(MasterCleanupService::class);
        $service->shouldReceive('run')->once()->with(['dry_run' => true, 'adm_account_id' => 7])->andReturn(['status' => 'success', 'dry_run' => true]);
        $service->shouldReceive('run')->once()->with(['dry_run' => false, 'adm_account_id' => 7])->andReturn(['status' => 'success', 'dry_run' => false]);

        $this->postJson('/master-import/cleanup')->assertOk()->assertJsonPath('dry_run', true);
        $this->postJson('/master-import/cleanup', ['dry_run' => false])->assertOk()->assertJsonPath('dry_run', false);
    }

    // ---- エラー（13.1 の code と HTTP ステータス） ----

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function errorCodes(): array
    {
        return [
            'deploy_locked' => ['deploy_locked', 409],
            'role_forbidden' => ['role_forbidden', 403],
            'fixed_key_forbidden' => ['fixed_key_forbidden', 403],
            'sheet_invalid' => ['sheet_invalid', 422],
            'sheet_read_failed' => ['sheet_read_failed', 502],
            'start_at_past' => ['start_at_past', 422],
            'load_failed' => ['load_failed', 500],
            'artifact_not_found' => ['artifact_not_found', 404],
            'not_finalized' => ['not_finalized', 422],
            'deploy_key_regression' => ['deploy_key_regression', 409],
            'finalize_conflict' => ['finalize_conflict', 409],
            'follow_check_unavailable' => ['follow_check_unavailable', 422],
            'rollback_blocked_by_scheduled' => ['rollback_blocked_by_scheduled', 409],
            'schedule_not_found' => ['schedule_not_found', 404],
            'schedule_cancel_too_soon' => ['schedule_cancel_too_soon', 409],
            'register_unknown' => ['register_unknown', 500],
            'drop_forbidden' => ['drop_forbidden', 403],
            'sys_unreadable' => ['sys_unreadable', 503],
            'cleanup_failed' => ['cleanup_failed', 500],
        ];
    }

    #[Test]
    #[DataProvider('errorCodes')]
    public function 失敗は_code_message_error_list_run_idを例外のhttpステータスで返す(string $code, int $status): void
    {
        $this->login();
        $this->bindMock(MasterRollbackService::class)->shouldReceive('run')->once()
            ->andThrow(new MasterDeployRunException($code, 'メッセージ', $status, [['sys_deploy_id' => 1]], 12, ['error_total' => 1]));

        $this->postJson('/master-import/rollback', ['sys_deploy_master_id' => 1])
            ->assertStatus($status)
            ->assertExactJson(['status' => 'error', 'code' => $code, 'message' => 'メッセージ', 'error_list' => [['sys_deploy_id' => 1]], 'run_id' => 12, 'error_total' => 1]);
    }

    #[Test]
    public function 想定外の例外はinternal_error500で_詳細は出さない(): void
    {
        $this->login();
        $this->bindMock(MasterBuildRunner::class)->shouldReceive('run')->once()->andThrow(new RuntimeException('SQLSTATE[HY000] secret detail'));

        $response = $this->postJson('/master-import/build');

        $response->assertStatus(500)->assertJsonPath('status', 'error')->assertJsonPath('code', 'internal_error')->assertJsonPath('run_id', null);
        $this->assertStringNotContainsString('SQLSTATE', (string) $response->getContent());
    }

    #[Test]
    public function 他の操作の想定外の例外もinternal_error(): void
    {
        $this->login();
        $this->bindMock(MasterCleanupService::class)->shouldReceive('run')->once()->andThrow(new RuntimeException('boom'));
        $this->bindMock(MasterDeployTargetService::class)->shouldReceive('build')->once()->andThrow(new RuntimeException('boom'));

        $this->postJson('/master-import/cleanup')->assertStatus(500)->assertJsonPath('code', 'internal_error');
        $this->getJson('/master-import/target')->assertStatus(500)->assertJsonPath('code', 'internal_error');
    }

    // ---- clear-cache ----

    #[Test]
    public function clear_cacheはschedule系のキーだけを破棄する(): void
    {
        $this->login();
        $this->bindMock(MasterCacheInvalidator::class)->shouldReceive('forgetScheduleAndRelated')->once()->andReturn(['invalidated' => true, 'failed_key_list' => []]);

        $this->postJson('/master-import/clear-cache')->assertOk()->assertExactJson([
            'status' => 'success', 'message' => 'キャッシュを破棄しました。', 'cache' => ['invalidated' => true, 'failed_key_list' => []],
        ]);
    }

    #[Test]
    public function clear_cacheの破棄に失敗したら502_cache_clear_failed(): void
    {
        $this->login();
        $this->bindMock(MasterCacheInvalidator::class)->shouldReceive('forgetScheduleAndRelated')->once()->andReturn(['invalidated' => false, 'failed_key_list' => ['sys:sys_deploy:schedule']]);

        $this->postJson('/master-import/clear-cache')->assertStatus(502)->assertJsonPath('code', 'cache_clear_failed')->assertJsonPath('cache.failed_key_list', ['sys:sys_deploy:schedule']);
    }

    #[Test]
    public function clear_cacheでキーを調べる段階が失敗しても502で詳細は出さない(): void
    {
        $this->login();
        $this->bindMock(MasterCacheInvalidator::class)->shouldReceive('forgetScheduleAndRelated')->once()->andThrow(new RuntimeException('SQLSTATE secret'));

        $response = $this->postJson('/master-import/clear-cache');

        $response->assertStatus(502)->assertJsonPath('code', 'cache_clear_failed');
        $this->assertStringNotContainsString('SQLSTATE', (string) $response->getContent());
    }

    // ---- シートを読む操作は build の環境だけ（role_forbidden） ----

    #[Test]
    public function importの環境ではシート系が403_role_forbidden(): void
    {
        $this->login();
        Config::set('master_deploy.role', 'import');

        foreach (['/master-import/all-sheets', '/master-import/spreadsheets', '/master-import/sheets?spreadsheet_id=a', '/master-import/preview?spreadsheet_id=a&sheet_title=mst_x'] as $uri) {
            $this->getJson($uri)->assertStatus(403)->assertJsonPath('status', 'error')->assertJsonPath('code', 'role_forbidden');
        }
    }

    #[Test]
    public function buildの環境ではシート系は今までどおり(): void
    {
        $this->login();
        Config::set('master_deploy.role', 'build');
        Storage::fake();
        $mock = Mockery::mock(GoogleSpreadsheetService::class);
        $mock->shouldReceive('listSpreadsheets')->once()->andReturn([['id' => 'a', 'name' => 'A']]);
        $this->app->bind(GoogleSpreadsheetService::class, fn () => $mock);

        $this->getJson('/master-import/spreadsheets')->assertOk()->assertExactJson(['status' => 'success', 'spreadsheets' => [['id' => 'a', 'name' => 'A']]]);
    }

    #[Test]
    public function シート系の想定外の例外は文言を出さずログに残す(): void
    {
        $this->login();
        $mock = Mockery::mock(GoogleSpreadsheetService::class);
        $mock->shouldReceive('listSpreadsheets')->once()->andThrow(new RuntimeException('secret-credential-detail'));
        $this->app->bind(GoogleSpreadsheetService::class, fn () => $mock);

        $response = $this->getJson('/master-import/spreadsheets');

        $response->assertStatus(422)->assertJsonPath('status', 'error');
        $this->assertStringNotContainsString('secret-credential-detail', (string) $response->getContent());
    }

    #[Test]
    public function runnerはコンテナから解決でき_schedule破棄はredisストアを使う(): void
    {
        $this->assertInstanceOf(MasterBuildRunner::class, app(MasterBuildRunner::class));
        $this->assertInstanceOf(MasterRollbackService::class, app(MasterRollbackService::class));

        $invalidator = app(MasterCacheInvalidator::class);
        $property = (new \ReflectionClass($invalidator))->getProperty('cache');
        $this->assertNull($property->getValue($invalidator), '既定のキャッシュストアを注入せず、forget のたびに redis を使う');

        // 実際に redis ストアで forget が通る（接続できる環境）
        $this->assertSame(['invalidated' => true, 'failed_key_list' => []], $invalidator->forgetSchedule());
    }
}

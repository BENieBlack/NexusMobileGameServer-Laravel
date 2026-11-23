<?php

namespace Tests\Feature;

use App\Exceptions\MasterDeployRunException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Adm\AdmAccount;
use App\Services\MasterCacheInvalidator;
use App\Services\MasterDeployRunner;
use App\Services\MasterDeployTargetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * マスターデプロイの HTTP（GET /master-import/target・POST deploy・POST clear-cache・GET /master-import の props）のテスト
 *
 * 設計: docs/design/master-distribution/api.md 2〜6 節
 * Runner・target の組み立て・キャッシュ破棄・Google はモックにする（DB は使わない）。
 * ログインは DB に触れないメモリ上の管理者で行う。
 */
class MasterDeployApiTest extends TestCase
{
    private function login(): void
    {
        $account = new AdmAccount;
        $account->forceFill(['id' => 7, 'name' => 'operator', 'email' => 'master-deploy@example.com']);
        $this->actingAs($account);
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeTarget(): array
    {
        return [
            'app_env' => 'testing',
            'mode' => 'real',
            'fixed_key' => null,
            'next_key' => 2026112201,
            'blocked' => null,
            'spreadsheet_dir_configured' => true,
            'running' => false,
            'last_run' => null,
            'warning_list' => [],
        ];
    }

    private function mockTarget(): MasterDeployTargetService&MockInterface
    {
        $mock = Mockery::mock(MasterDeployTargetService::class);
        $mock->shouldReceive('build')->with(true)->andReturn($this->fakeTarget());
        $this->app->instance(MasterDeployTargetService::class, $mock);

        Config::set('services.google_spreadsheet.folder_id', 'folder');
        Config::set('services.google_spreadsheet.credentials_json', '{}');

        return $mock;
    }

    private function mockRunner(): MasterDeployRunner&MockInterface
    {
        $mock = Mockery::mock(MasterDeployRunner::class);
        $this->app->instance(MasterDeployRunner::class, $mock);

        return $mock;
    }

    #[Test]
    public function targetは対象の情報をそのまま返す(): void
    {
        $this->login();
        $this->mockTarget();

        $this->getJson('/master-import/target')->assertOk()->assertExactJson($this->fakeTarget());
    }

    #[Test]
    public function 認証情報が未設定でも_targetは動く(): void
    {
        $this->login();
        Config::set('services.google_spreadsheet.folder_id', null);
        $mock = Mockery::mock(MasterDeployTargetService::class);
        $mock->shouldReceive('build')->once()->with(false)->andReturn(['spreadsheet_dir_configured' => false] + $this->fakeTarget());
        $this->app->instance(MasterDeployTargetService::class, $mock);

        $this->getJson('/master-import/target')->assertOk()->assertJsonPath('spreadsheet_dir_configured', false);
    }

    #[Test]
    public function インデックスの_propsにtargetが入りcached系も残る(): void
    {
        $this->login();
        $this->mockTarget();

        $this->get('/master-import', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/master-import'))])
            ->assertOk()
            ->assertJsonPath('component', 'MasterImport/Index')
            ->assertJsonPath('props.target', $this->fakeTarget())
            ->assertJsonStructure(['props' => ['cached_sheets', 'cached_at', 'is_configured']]);
    }

    #[Test]
    public function デプロイの成功はrunnerの結果を返し_管理者idを渡す(): void
    {
        $this->login();
        $result = ['status' => 'success', 'message' => '新しい版を登録しました。', 'dry_run' => false, 'run_id' => 12];
        $this->mockRunner()->shouldReceive('run')->once()->with(['dry_run' => false, 'adm_account_id' => 7])->andReturn($result);

        $this->postJson('/master-import/deploy')->assertOk()->assertExactJson($result);
    }

    #[Test]
    public function dry_runを渡せる(): void
    {
        $this->login();
        $result = ['status' => 'success', 'message' => 'dry', 'dry_run' => true, 'run_id' => null];
        $this->mockRunner()->shouldReceive('run')->once()->with(['dry_run' => true, 'adm_account_id' => 7])->andReturn($result);

        $this->postJson('/master-import/deploy', ['dry_run' => true])->assertOk()->assertJsonPath('dry_run', true);
    }

    #[Test]
    public function dry_runが真偽値でなければ422でrunnerを呼ばない(): void
    {
        $this->login();
        $this->mockRunner()->shouldNotReceive('run');

        $this->postJson('/master-import/deploy', ['dry_run' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('dry_run');
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function errorProvider(): array
    {
        return [
            'deploy_locked' => [MasterDeployRunException::DEPLOY_LOCKED, 409],
            'fixed_key_forbidden' => ['fixed_key_forbidden', 403],
            'fixed_key_invalid' => ['fixed_key_invalid', 500],
            'reserved_key_forbidden' => ['reserved_key_forbidden', 403],
            'deploy_key_exhausted' => ['deploy_key_exhausted', 422],
            'deploy_key_conflict' => ['deploy_key_conflict', 409],
            'sheet_not_configured' => [MasterDeployRunException::SHEET_NOT_CONFIGURED, 422],
            'sheet_read_failed' => [MasterDeployRunException::SHEET_READ_FAILED, 502],
            'sheet_invalid' => [MasterDeployRunException::SHEET_INVALID, 422],
            'apply_failed' => [MasterDeployRunException::APPLY_FAILED, 500],
            'export_failed' => [MasterDeployRunException::EXPORT_FAILED, 500],
            'register_failed' => ['register_failed', 500],
            'inconsistent' => [MasterDeployRunException::INCONSISTENT, 500],
            'internal_error' => [MasterDeployRunException::INTERNAL_ERROR, 500],
        ];
    }

    #[Test]
    #[DataProvider('errorProvider')]
    public function デプロイの失敗はcodeと_htt_pステータスを返す(string $code, int $http): void
    {
        $this->login();
        $error = new MasterDeployRunException($code, "失敗: {$code}", $http, [['sheet' => 's', 'code' => 'id_empty']], 12, ['error_total' => 1]);
        $this->mockRunner()->shouldReceive('run')->once()->andThrow($error);

        $this->postJson('/master-import/deploy')
            ->assertStatus($http)
            ->assertExactJson([
                'status' => 'error',
                'code' => $code,
                'message' => "失敗: {$code}",
                'error_list' => [['sheet' => 's', 'code' => 'id_empty']],
                'run_id' => 12,
                'error_total' => 1,
            ]);
    }

    #[Test]
    public function キャッシュ破棄の成功(): void
    {
        $this->login();
        $invalidator = Mockery::mock(MasterCacheInvalidator::class);
        $invalidator->shouldReceive('invalidateAll')->once()->andReturn(['invalidated' => true, 'failed_key_list' => []]);
        $this->app->instance(MasterCacheInvalidator::class, $invalidator);

        $this->postJson('/master-import/clear-cache')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('cache', ['invalidated' => true, 'failed_key_list' => []]);
    }

    #[Test]
    public function キャッシュ破棄に失敗したら502(): void
    {
        $this->login();
        $invalidator = Mockery::mock(MasterCacheInvalidator::class);
        $invalidator->shouldReceive('invalidateAll')->once()
            ->andReturn(['invalidated' => false, 'failed_key_list' => ['mst:mst_config:all']]);
        $this->app->instance(MasterCacheInvalidator::class, $invalidator);

        $this->postJson('/master-import/clear-cache')
            ->assertStatus(502)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'cache_clear_failed')
            ->assertJsonPath('cache.failed_key_list', ['mst:mst_config:all']);
    }

    #[Test]
    public function キャッシュ破棄の準備で例外が出ても502(): void
    {
        $this->login();
        $invalidator = Mockery::mock(MasterCacheInvalidator::class);
        $invalidator->shouldReceive('invalidateAll')->once()->andThrow(new RuntimeException('db down'));
        $this->app->instance(MasterCacheInvalidator::class, $invalidator);

        $this->postJson('/master-import/clear-cache')->assertStatus(502)->assertJsonPath('code', 'cache_clear_failed');
    }

    #[Test]
    public function 未認証は全て_ログイン画面へ飛ばす(): void
    {
        $this->get('/master-import/target')->assertRedirect('/login');
        $this->post('/master-import/deploy')->assertRedirect('/login');
        $this->post('/master-import/clear-cache')->assertRedirect('/login');
    }

    #[Test]
    public function 想定外の例外は詳細を出さず500のinternal_errorで返す(): void
    {
        $this->login();
        $this->mockRunner()->shouldReceive('run')->once()->andThrow(new RuntimeException('SQLSTATE[HY000] secret detail'));

        $response = $this->postJson('/master-import/deploy')
            ->assertStatus(500)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'internal_error')
            ->assertJsonPath('error_list', [])
            ->assertJsonPath('run_id', null);
        $this->assertStringNotContainsString('SQLSTATE', (string) $response->getContent());
    }

    #[Test]
    public function キャッシュ破棄の準備の例外は_forgetの失敗と別の文言(): void
    {
        $this->login();
        $invalidator = Mockery::mock(MasterCacheInvalidator::class);
        $invalidator->shouldReceive('invalidateAll')->once()->andThrow(new RuntimeException('db down'));
        $this->app->instance(MasterCacheInvalidator::class, $invalidator);
        $thrown = $this->postJson('/master-import/clear-cache')->json('message');

        $invalidator2 = Mockery::mock(MasterCacheInvalidator::class);
        $invalidator2->shouldReceive('invalidateAll')->once()->andReturn(['invalidated' => false, 'failed_key_list' => ['k']]);
        $this->app->instance(MasterCacheInvalidator::class, $invalidator2);
        $forgetFailed = $this->postJson('/master-import/clear-cache')->json('message');

        $this->assertNotSame($forgetFailed, $thrown);
    }

    #[Test]
    public function targetの組み立てが失敗しても画面は開けてtargetはnull(): void
    {
        $this->login();
        $mock = Mockery::mock(MasterDeployTargetService::class);
        $mock->shouldReceive('build')->once()->andThrow(new RuntimeException('db down'));
        $this->app->instance(MasterDeployTargetService::class, $mock);

        $this->get('/master-import', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/master-import'))])
            ->assertOk()
            ->assertJsonPath('props.target', null);
    }
}

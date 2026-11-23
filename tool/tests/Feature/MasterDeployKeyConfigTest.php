<?php

namespace Tests\Feature;

use App\Services\MasterDeployKeyPolicy;
use Tests\TestCase;

class MasterDeployKeyConfigTest extends TestCase
{
    public function test_既定の設定は設計どおり(): void
    {
        $this->assertSame([7777777777, 8888888888, 9999999999], config('master_deploy.allowed_fixed_keys'));
        $this->assertSame(['production', 'staging'], config('master_deploy.fixed_key_forbidden_envs'));
        $this->assertSame(7000000000, config('master_deploy.reserved_floor'));
    }

    public function test_from_config_は設定の固定キーを返す(): void
    {
        config(['master_deploy.fixed_key' => 8888888888, 'app.env' => 'local']);
        $this->assertSame(8888888888, MasterDeployKeyPolicy::fromConfig()->resolveFixedKey());
    }

    public function test_from_config_は本番で固定キーを拒否する(): void
    {
        config(['master_deploy.fixed_key' => 9999999999, 'app.env' => 'production']);
        $this->expectExceptionMessage('MASTER_DEPLOY_FIXED_KEY');
        MasterDeployKeyPolicy::fromConfig()->resolveFixedKey();
    }

    public function test_config_は環境変数を読んで変換する(): void
    {
        $load = fn () => require base_path('config/master_deploy.php');

        putenv('MASTER_DEPLOY_FIXED_KEY=8888888888');
        $_ENV['MASTER_DEPLOY_FIXED_KEY'] = '8888888888';
        try {
            $this->assertSame(8888888888, $load()['fixed_key']);
            $_ENV['MASTER_DEPLOY_FIXED_KEY'] = '07777777777';
            putenv('MASTER_DEPLOY_FIXED_KEY=07777777777');
            $this->assertSame('07777777777', $load()['fixed_key']);
            $_ENV['MASTER_DEPLOY_FIXED_KEY'] = '';
            putenv('MASTER_DEPLOY_FIXED_KEY=');
            $this->assertNull($load()['fixed_key']);
        } finally {
            unset($_ENV['MASTER_DEPLOY_FIXED_KEY']);
            putenv('MASTER_DEPLOY_FIXED_KEY');
        }
    }
}

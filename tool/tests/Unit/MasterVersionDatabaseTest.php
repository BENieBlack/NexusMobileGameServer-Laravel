<?php

namespace Tests\Unit;

use App\Exceptions\MasterDeployRunException;
use App\Services\MasterVersionDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MasterVersionDatabase の命名・DROP の保護（DB を使わない部分。作成・投入・スキーマ版は MasterBuildRunnerTest が実物の MySQL で確かめる）
 *
 * 設計: docs/design/master-distribution/db.md 4.1・4.2・4.6
 */
class MasterVersionDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.name', 'nexus');
        Config::set('app.env', 'qa');
        Config::set('database.connections.mst.database', 'nexus-qa-master');
    }

    #[Test]
    public function 版の_d_b名は_ap_p_nam_e_env_master_deploy_key_hashの先頭16文字(): void
    {
        $databases = new MasterVersionDatabase;
        $hash = '0f3c1a2b3c4d5e6f'.str_repeat('9', 48);

        $this->assertSame('nexus-qa-master', $databases->expectedBaseDatabase());
        $this->assertSame('nexus-qa-master-2026120101-0f3c1a2b3c4d5e6f', $databases->versionName(2026120101, $hash));
        $this->assertLessThanOrEqual(64, strlen($databases->versionName(2026120101, $hash)));
    }

    #[Test]
    public function 作業用_d_b名は接頭辞_work_ut_c時刻_乱数4文字(): void
    {
        $databases = new MasterVersionDatabase;

        $name = $databases->workName(Carbon::parse('2026-12-01 03:04:05', 'Asia/Tokyo'));

        $this->assertMatchesRegularExpression('/^nexus-qa-master-work-20261130180405-[a-z0-9]{4}$/D', $name);
    }

    #[Test]
    public function dro_pは自環境の接頭辞が付いた名前だけで_基底_d_bと他の環境と接頭辞だけの名前は拒否する(): void
    {
        $databases = new MasterVersionDatabase;
        $databases->assertOwnName('nexus-qa-master-2026120101-0f3c1a2b3c4d5e6f');
        $databases->assertOwnName('nexus-qa-master-work-20261201000000-ab12');

        foreach ([
            'nexus-qa-master',                              // 基底 DB
            'nexus-qa-master-',                             // 接頭辞だけ
            'nexus-local-master-2026120101-0f3c1a2b3c4d5e6f', // 他の環境
            'nexus-testing-tool-master',
            'nexus-qa-system',
            'mysql',
            'nexus-qa-master-x`; DROP DATABASE y; --',      // 不正な文字
        ] as $name) {
            try {
                $databases->drop($name);
                $this->fail("{$name} は拒否されるはず");
            } catch (MasterDeployRunException $e) {
                $this->assertSame(MasterDeployRunException::DROP_FORBIDDEN, $e->errorCode, $name);
                $this->assertSame(403, $e->httpStatus);
            }
        }
    }

    #[Test]
    public function 作成も同じ名前の保護を通る(): void
    {
        $this->expectException(MasterDeployRunException::class);

        (new MasterVersionDatabase)->createFromBase('nexus-qa-master');
    }
}

<?php

namespace Tests\Feature\Repositories\Sys;

use App\Models\Sys\SysDeploy;
use App\Repositories\Sys\SysDeployRepository;
use Illuminate\Support\Facades\DB;
use NexusVersion\Constants\DeployKeyConst;
use PHPUnit\Framework\Attributes\Test;
use Tests\RefreshMultipleDatabases;
use Tests\TestCase;

/**
 * 「最新」の定義（Nexus db.md 3.3）を、実際の DB に行を入れて確かめる。
 *
 * deploy_key は bigInteger で UNIQUE が無いので、固定キー（9999999999 など）と
 * 同じ deploy_key の複数行を入れられる。
 */
class SysDeployRepositoryDbTest extends TestCase
{
    use RefreshMultipleDatabases;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearDeployData();
    }

    protected function tearDown(): void
    {
        $this->app->detectEnvironment(fn () => 'testing');
        $this->clearDeployData();

        parent::tearDown();
    }

    #[Test]
    public function 本番では9999999999が除外され本物のキーが選ばれる(): void
    {
        $real = $this->insertDeploy(2026010101);
        $this->insertDeploy(9999999999);

        $this->app->detectEnvironment(fn () => 'production');

        $this->assertSame($real, (new SysDeployRepository)->buildLatestDownloadableQuery()->first()?->id);
        $this->assertSame($real, SysDeploy::selectLatestDownloadable()?->id);
    }

    #[Test]
    public function 本番以外では9999999999が本物より先に選ばれる(): void
    {
        $this->insertDeploy(2026010101);
        $fixed = $this->insertDeploy(9999999999);

        $this->app->detectEnvironment(fn () => 'testing');

        $latest = (new SysDeployRepository)->buildLatestDownloadableQuery()->first();
        $this->assertSame($fixed, $latest?->id);
        $this->assertSame(9999999999, $latest->deploy_key);
        $this->assertSame($fixed, SysDeploy::selectLatestDownloadable()?->id);
    }

    #[Test]
    public function 同じdeploy_keyではid最大が選ばれる(): void
    {
        $this->insertDeploy(9999999999);
        $newer = $this->insertDeploy(9999999999);

        $this->assertSame($newer, (new SysDeployRepository)->buildLatestDownloadableQuery()->first()?->id);
        $this->assertSame($newer, SysDeploy::selectLatestDownloadable()?->id);
        $this->assertSame($newer, (new SysDeployRepository)->selectByDeployKey(9999999999)?->id);
    }

    #[Test]
    public function 同じdeploy_keyの行は3表に複数入れられる(): void
    {
        $masterIds = [];
        foreach (['a', 'b'] as $name) {
            $masterIds[] = DB::connection('sys')->table('sys_deploy_master')->insertGetId($this->masterRow(9999999999, $name));
        }

        $this->assertCount(2, array_unique($masterIds));
    }

    #[Test]
    public function 本物の上限2999123199が選ばれる(): void
    {
        $this->insertDeploy(2026010101);
        $max = $this->insertDeploy(DeployKeyConst::REAL_KEY_MAX);

        $this->app->detectEnvironment(fn () => 'production');

        $this->assertSame(2999123199, DeployKeyConst::REAL_KEY_MAX);
        $this->assertSame($max, (new SysDeployRepository)->buildLatestDownloadableQuery()->first()?->id);
        $this->assertSame($max, SysDeploy::selectLatestDownloadable()?->id);
    }

    private function clearDeployData(): void
    {
        foreach (['sys_deploy', 'sys_deploy_master_table', 'sys_deploy_master', 'sys_deploy_asset'] as $table) {
            DB::connection('sys')->table($table)->delete();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function masterRow(int $deployKey, string $name): array
    {
        return [
            'deploy_key' => $deployKey,
            'hash' => hash('sha256', "master-{$name}-{$deployKey}"),
            'deploy_date' => '2026-01-01',
            'deploy_count' => 1,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function insertDeploy(int $deployKey): int
    {
        $name = (string) ++$this->seq;
        $masterId = DB::connection('sys')->table('sys_deploy_master')->insertGetId($this->masterRow($deployKey, $name));
        $assetId = DB::connection('sys')->table('sys_deploy_asset')->insertGetId([
            'deploy_key' => $deployKey,
            'hash' => hash('sha256', "asset-{$name}-{$deployKey}"),
            'deploy_date' => '2026-01-01',
            'deploy_count' => 1,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::connection('sys')->table('sys_deploy')->insertGetId([
            'deploy_key' => $deployKey,
            'start_at' => now()->subMinute(),
            'sys_deploy_master_id' => $masterId,
            'sys_deploy_asset_id' => $assetId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

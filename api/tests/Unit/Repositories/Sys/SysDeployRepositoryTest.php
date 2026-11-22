<?php

namespace Tests\Unit\Repositories\Sys;

use App\Models\Sys\SysDeploy;
use App\Repositories\Sys\SysDeployRepository;
use Illuminate\Database\Eloquent\Builder;
use NexusVersion\Constants\DeployKeyConst;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 「最新」の定義（Nexus db.md 3.3）のうち、並びと本番ガードを確かめる。
 *
 * deploy_key は現状 int 型・UNIQUE で、固定キー（9999999999 など）や同じ deploy_key の複数行を
 * 入れられない。スキーマ変更後でも通るよう、DB には入れず、クエリの条件で固定する。
 * 固定キーが本物より先になることは「deploy_key 降順」の並びで保証される。
 */
class SysDeployRepositoryTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->setEnvironment('testing');

        parent::tearDown();
    }

    #[Test]
    public function リポジトリの最新はdeploy_key降順からid降順の並び(): void
    {
        $this->setEnvironment('testing');

        $orders = $this->orders((new SysDeployRepository)->buildLatestDownloadableQuery());

        $this->assertSame([['deploy_key', 'desc'], ['id', 'desc']], $orders);
    }

    #[Test]
    public function モデルの最新もリポジトリと同じ並び(): void
    {
        $this->setEnvironment('testing');

        $orders = $this->orders(SysDeploy::downloadable()->latestOrder());

        $this->assertSame([['deploy_key', 'desc'], ['id', 'desc']], $orders);
    }

    #[Test]
    public function 本番以外は予約帯を除かない(): void
    {
        $this->setEnvironment('testing');

        $this->assertNull($this->reservedGuard((new SysDeployRepository)->buildLatestDownloadableQuery()));
        $this->assertNull($this->reservedGuard(SysDeploy::downloadable()->latestOrder()));
    }

    #[Test]
    public function 本番は予約帯を除く(): void
    {
        $this->setEnvironment('production');

        $guard = $this->reservedGuard((new SysDeployRepository)->buildLatestDownloadableQuery());
        $this->assertSame(DeployKeyConst::RESERVED_FLOOR, $guard);

        $guard = $this->reservedGuard(SysDeploy::downloadable()->latestOrder());
        $this->assertSame(DeployKeyConst::RESERVED_FLOOR, $guard);
    }

    #[Test]
    public function 予約帯の下限と固定キー(): void
    {
        $this->assertSame(7000000000, DeployKeyConst::RESERVED_FLOOR);
        $this->assertGreaterThanOrEqual(DeployKeyConst::RESERVED_FLOOR, DeployKeyConst::FIXED_KEY_RECORDING);
        $this->assertGreaterThan(DeployKeyConst::FIXED_KEY_RECORDING, DeployKeyConst::FIXED_KEY_MID);
        $this->assertGreaterThan(DeployKeyConst::FIXED_KEY_MID, DeployKeyConst::FIXED_KEY_TOP);
        $this->assertLessThan(DeployKeyConst::RESERVED_FLOOR, DeployKeyConst::REAL_KEY_MAX);
    }

    private function setEnvironment(string $env): void
    {
        $this->app->detectEnvironment(fn () => $env);
    }

    /**
     * @param  Builder<SysDeploy>  $query
     * @return list<array{0: string, 1: string}>
     */
    private function orders(Builder $query): array
    {
        return array_map(
            fn (array $o) => [$o['column'], $o['direction']],
            $query->getQuery()->orders ?? []
        );
    }

    /**
     * deploy_key < N の条件の N を返す。無ければ null。
     *
     * @param  Builder<SysDeploy>  $query
     */
    private function reservedGuard(Builder $query): ?int
    {
        foreach ($query->getQuery()->wheres as $where) {
            if (($where['column'] ?? null) === 'deploy_key' && ($where['operator'] ?? null) === '<') {
                return $where['value'];
            }
        }

        return null;
    }
}

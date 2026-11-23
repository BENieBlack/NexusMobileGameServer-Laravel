<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\CheckMasterHash;
use App\Models\Sys\SysDeploy;
use App\Models\Sys\SysDeployMaster;
use App\Models\Sys\SysDeployMasterTable;
use App\Persistence\CurrentMasterVersion;
use Illuminate\Http\Request;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CheckMasterHashミドルウェアのテスト
 *
 * 比較する版は、リクエスト冒頭で固定した CurrentMasterVersion から取る（$next の後で引き直さない。db.md 8.1）
 */
class CheckMasterHashTest extends TestCase
{
    private CurrentMasterVersion $currentMasterVersion;

    private CheckMasterHash $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentMasterVersion = Mockery::mock(CurrentMasterVersion::class);
        $this->middleware = new CheckMasterHash($this->currentMasterVersion);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function ハッシュが一致する場合は更新通知を付けない(): void
    {
        $this->currentMasterVersion
            ->shouldReceive('current')
            ->once()
            ->andReturn($this->makeDeploy('master-hash'));

        $request = Request::create('/test', 'GET');
        $request->headers->set('X-Master-Hash', 'master-hash');

        $response = $this->middleware->handle($request, fn () => response()->json(['success' => true]));

        $this->assertSame(['success' => true], $response->getData(true));
    }

    #[Test]
    public function ハッシュが不一致の場合は更新要求ヘッダーを付ける(): void
    {
        $this->currentMasterVersion
            ->shouldReceive('current')
            ->once()
            ->andReturn($this->makeDeploy('latest-hash'));

        $request = Request::create('/test', 'GET');
        $request->headers->set('X-Master-Hash', 'old-hash');

        $response = $this->middleware->handle($request, fn () => response()->json(['success' => true]));
        $this->assertSame(['success' => true], $response->getData(true));
        $this->assertSame('true', $response->headers->get('X-Master-Update-Required'));
    }

    #[Test]
    public function ヘッダー未指定時はレスポンスを変更しない(): void
    {
        $this->currentMasterVersion->shouldReceive('current')->never();

        $request = Request::create('/test', 'GET');
        $response = $this->middleware->handle($request, fn () => response()->json(['success' => true]));

        $this->assertSame(['success' => true], $response->getData(true));
    }

    #[Test]
    public function jso_n以外のレスポンスにも更新要求ヘッダーを付ける(): void
    {
        $this->currentMasterVersion
            ->shouldReceive('current')
            ->once()
            ->andReturn($this->makeDeploy('latest-hash'));

        $request = Request::create('/test', 'GET');
        $request->headers->set('X-Master-Hash', 'old-hash');
        $response = $this->middleware->handle($request, function () {
            return response('ok', 200);
        });

        $this->assertSame('ok', $response->getContent());
        $this->assertSame('true', $response->headers->get('X-Master-Update-Required'));
    }

    #[Test]
    public function マスター確認に失敗しても元のレスポンスを返す(): void
    {
        $this->currentMasterVersion
            ->shouldReceive('current')
            ->once()
            ->andThrow(new \RuntimeException('database unavailable'));

        $request = Request::create('/test', 'GET');
        $request->headers->set('X-Master-Hash', 'old-hash');

        $response = $this->middleware->handle($request, fn () => response()->json(['success' => true]));

        $this->assertSame(['success' => true], $response->getData(true));
    }

    private function makeDeploy(string $hash): SysDeploy
    {
        $table = new SysDeployMasterTable([
            'table_name' => 'mst_unit',
            'hash' => 'unit-hash',
            'public_url' => '/masterdata/units.sqlite',
            'file_size' => 2048,
        ]);
        $master = new SysDeployMaster(['hash' => $hash]);
        $master->setRelation('tables', collect([$table]));

        $sysDeploy = new SysDeploy(['sys_deploy_master_id' => 7]);
        $sysDeploy->setRelation('deployMaster', $master);

        return $sysDeploy;
    }
}

<?php

namespace Tests\Feature\MasterVersion;

use App\Exceptions\SystemDataException;
use App\Models\Sys\SysDeploy;
use App\Persistence\CurrentMasterVersion;
use App\Repositories\Sys\SysDeployRepository;
use Illuminate\Support\Facades\Cache;
use Nexus\Core\Utilities\ClockUtility;
use PHPUnit\Framework\Attributes\Test;
use Tests\RefreshMultipleDatabases;
use Tests\Support\SysDeployFixture;
use Tests\TestCase;

/**
 * 「いまの版」の決め方（start_at 基準の最新。固定・切り替え予定のキャッシュ）を確かめる。
 * 設計: docs/design/master-distribution/db.md 3.3・8.1・8.3
 *
 * sys は RefreshMultipleDatabases のトランザクションで包まれるので、シードの版を消して入れ直しても巻き戻る。
 */
class CurrentMasterVersionTest extends TestCase
{
    use RefreshMultipleDatabases;

    private const NOW = '2026-06-01 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        ClockUtility::setNow(self::NOW);
        SysDeployFixture::clear();
    }

    protected function tearDown(): void
    {
        $this->app->detectEnvironment(fn () => 'testing');

        parent::tearDown();
    }

    #[Test]
    public function start_atがちょうど現在の版は選ばれ_1秒先の版は選ばれない(): void
    {
        $past = SysDeployFixture::insert(2026010101, '2026-05-01 00:00:00');
        $boundary = SysDeployFixture::insert(2026010201, self::NOW);
        SysDeployFixture::insert(2026010301, '2026-06-01 12:00:01');

        $this->assertSame($boundary, $this->current()?->getKey());
        $this->assertNotSame($past, $this->current()?->getKey());
    }

    #[Test]
    public function 未来のstart_atの版しか無いときはいまの版が無く_299になる(): void
    {
        SysDeployFixture::insert(2026010101, '2026-06-01 12:00:01');

        $this->assertNull($this->current());
        $this->expectException(SystemDataException::class);
        app(CurrentMasterVersion::class)->currentOrFail();
    }

    #[Test]
    public function is_activeが偽の版は選ばれず_その前の版が残る(): void
    {
        $active = SysDeployFixture::insert(2026010101, '2026-05-01 00:00:00');
        SysDeployFixture::insert(2026010201, '2026-05-02 00:00:00', false);

        $this->assertSame($active, $this->current()?->getKey());
    }

    #[Test]
    public function 同じstart_atならdeploy_key_その次にid_が大きい方(): void
    {
        $at = '2026-05-01 00:00:00';
        SysDeployFixture::insert(2026010101, $at);
        $bigKey = SysDeployFixture::insert(2026010201, $at);
        SysDeployFixture::insert(2026010101, $at);

        $this->assertSame($bigKey, $this->current()?->getKey());

        $this->forgetSchedule();
        $newerSameKey = SysDeployFixture::insert(2026010201, $at);
        $this->assertSame($newerSameKey, $this->current()?->getKey());
    }

    #[Test]
    public function start_atが新しい版はdeploy_keyが小さくても選ばれる(): void
    {
        SysDeployFixture::insert(9999999999, '2026-05-01 00:00:00');
        $newer = SysDeployFixture::insert(2026010101, '2026-05-02 00:00:00');

        $this->assertSame($newer, $this->current()?->getKey());
    }

    #[Test]
    public function 本番は予約帯の版を選ばない(): void
    {
        $real = SysDeployFixture::insert(2026010101, '2026-05-01 00:00:00');
        SysDeployFixture::insert(9999999999, '2026-05-02 00:00:00');

        $this->app->detectEnvironment(fn () => 'production');

        $this->assertSame($real, $this->current()?->getKey());
    }

    #[Test]
    public function リクエストの途中で固定した版は切り替わらない(): void
    {
        $first = SysDeployFixture::insert(2026010101, '2026-05-01 00:00:00');
        $version = app(CurrentMasterVersion::class);
        $this->assertSame($first, $version->current()?->getKey());

        // 固定の後に、より新しい版が登録され、時刻も進んでも、このリクエストの版は同じ
        SysDeployFixture::insert(2026010201, '2026-06-01 12:00:00');
        $this->forgetSchedule();
        ClockUtility::setNow('2026-06-01 13:00:00');

        $this->assertSame($first, $version->current()?->getKey());
    }

    #[Test]
    public function 切り替え予定の一覧は_tt_lの途中でstart_atを過ぎてもリクエスト時刻で選び直す(): void
    {
        $now = SysDeployFixture::insert(2026010101, '2026-05-01 00:00:00');
        $reserved = SysDeployFixture::insert(2026010201, '2026-06-01 13:00:00');
        $repository = new SysDeployRepository;

        // 一覧は予約済みの版も含めて 1 回だけ作る（以後はキャッシュ）
        $this->assertSame($now, $repository->selectLatestDownloadableAt(ClockUtility::now())?->getKey());
        $this->assertCount(2, $repository->selectSchedule());

        // 一覧を作り直さずに（TTL の途中）start_at を過ぎた時刻で引く。遅れずに予約の版になる
        ClockUtility::setNow('2026-06-01 13:00:00');
        $this->assertSame($reserved, $repository->selectLatestDownloadableAt(ClockUtility::now())?->getKey());
    }

    #[Test]
    public function 一覧を作り直すと旧キーlatest_downloadableを消す(): void
    {
        SysDeployFixture::insert(2026010101, '2026-05-01 00:00:00');
        $repository = new SysDeployRepository;
        Cache::store('redis')->put($repository->legacyLatestDownloadableCacheKey(), 'old', 3600);

        $repository->selectSchedule();

        $this->assertFalse(Cache::store('redis')->has($repository->legacyLatestDownloadableCacheKey()));
        $this->assertTrue(Cache::store('redis')->has($repository->scheduleCacheKey()));
        $this->assertSame('sys:sys_deploy:schedule', $repository->scheduleCacheKey());
    }

    #[Test]
    public function 掃除済みの版の_d_bへは切り替えず_299にする(): void
    {
        SysDeployFixture::insert(2026010101, '2026-05-01 00:00:00', true, 'nexus-testing-master-dropped', true);

        $before = config('database.connections.mst.database');
        try {
            app(CurrentMasterVersion::class)->applyMstConnection();
            $this->fail('掃除済みの版で例外にならなかった');
        } catch (SystemDataException) {
            $this->assertSame($before, config('database.connections.mst.database'));
        }
    }

    private function current(): ?SysDeploy
    {
        // リクエストごとに新しい CurrentMasterVersion を使う（scoped）。一覧のキャッシュは各テストの中で共有する
        $this->app->forgetScopedInstances();

        return app(CurrentMasterVersion::class)->current();
    }

    private function forgetSchedule(): void
    {
        (new SysDeployRepository)->forgetSchedule();
    }
}

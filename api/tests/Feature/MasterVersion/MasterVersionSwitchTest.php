<?php

namespace Tests\Feature\MasterVersion;

use App\Http\Middleware\CheckMasterHash;
use App\Repositories\Mst\MstConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Nexus\Core\Utilities\ClockUtility;
use PHPUnit\Framework\Attributes\Test;
use Redis;
use Tests\RefreshMultipleDatabases;
use Tests\Support\SysDeployFixture;
use Tests\TestCase;

/**
 * 版が 2 つある状態で、ミドルウェアを通した HTTP リクエストが「いまの版」の mst DB を読むことを確かめる実経路のテスト。
 * 設計: docs/design/master-distribution/db.md 8.1・8.4・8.6
 *
 * 版 A = 基底 DB（nexus-testing-master）、版 B = 2 つ目の mst DB（altMstDatabaseName）。
 * 2 つ目の DB は別接続（mst_alt）でしか書けず、リクエストは mst 接続の database を差し替えて読むので、
 * 差し替え（config + purge）の経路を実際に通る。2 つ目の DB の行はトランザクションで包めないので、
 * テストの終わりに消す。
 */
class MasterVersionSwitchTest extends TestCase
{
    use RefreshMultipleDatabases;

    private const PROBE = '/__master_version_probe';

    private const CONFIG_ID = 'version_switch_probe';

    private const NOW = '2026-06-01 12:00:00';

    private string $baseDatabase = '';

    private string $altDatabase = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseDatabase = (string) config('database.connections.mst.database');
        $this->altDatabase = $this->ensureAltMstMigrated();

        // 2 つ目の DB にだけ別の値を置く（基底 DB の行はこのテストのトランザクションで巻き戻る）
        $this->deleteAltRows();
        $this->insertConfig(DB::connection('mst_alt'), 'alt');
        $this->insertConfig(DB::connection('mst'), 'base');

        ClockUtility::setNow(self::NOW);
        SysDeployFixture::clear();

        // 本物の経路: ミドルウェア master.version を通し、mst を読んで値を返すだけのルート
        Route::middleware(['master.version'])->get(self::PROBE, function () {
            return response()->json([
                'value' => app(MstConfigRepository::class)->selectById(self::CONFIG_ID)?->getValue(),
                'database' => DB::connection('mst')->getDatabaseName(),
            ]);
        });
        Route::middleware([CheckMasterHash::class, 'master.version'])->get(self::PROBE.'-hash', fn () => response()->json(['ok' => true]));
    }

    protected function tearDown(): void
    {
        $this->deleteAltRows();
        DB::purge('mst_alt');

        parent::tearDown();
    }

    #[Test]
    public function start_atをまたぐと応答が基底_d_bから2つ目の_d_bの値に変わり_戻すと基底_d_bに戻る(): void
    {
        SysDeployFixture::insert(2026010101, '2026-05-01 00:00:00', true, $this->baseDatabase);
        SysDeployFixture::insert(2026010201, '2026-06-01 13:00:00', true, $this->altDatabase);

        // 切り替えの前: 版 A（基底 DB）
        $this->getJson(self::PROBE)->assertOk()->assertJson(['value' => 'base', 'database' => $this->baseDatabase]);

        // start_at を過ぎた時刻。一覧は作り直さない（TTL の途中）が、リクエスト時刻で選び直す
        ClockUtility::setNow('2026-06-01 13:00:00');
        $this->getJson(self::PROBE)->assertOk()->assertJson(['value' => 'alt', 'database' => $this->altDatabase]);

        // 時刻が戻った（別サーバーの時計のずれなど）リクエストは、版 A の DB に繋ぎ直す
        ClockUtility::setNow(self::NOW);
        $this->getJson(self::PROBE)->assertOk()->assertJson(['value' => 'base', 'database' => $this->baseDatabase]);
    }

    #[Test]
    public function 全件キャッシュのキーは版の_d_b名ごとに分かれ_互いの値を返さない(): void
    {
        SysDeployFixture::insert(2026010101, '2026-05-01 00:00:00', true, $this->baseDatabase);
        SysDeployFixture::insert(2026010201, '2026-06-01 13:00:00', true, $this->altDatabase);

        $this->getJson(self::PROBE)->assertJson(['value' => 'base']);
        ClockUtility::setNow('2026-06-01 13:00:00');
        $this->getJson(self::PROBE)->assertJson(['value' => 'alt']);

        $keyList = $this->physicalKeyList();
        $this->assertContains("nexus-database-nexus-cache-mst:{$this->baseDatabase}:mst_config:all", $keyList);
        $this->assertContains("nexus-database-nexus-cache-mst:{$this->altDatabase}:mst_config:all", $keyList);

        // 版 A の値が Redis に残っていても、版 B のリクエストは版 B のキーを読む（上の assertJson が示す）。
        // 版 B のキャッシュを残したまま版 A に戻っても、版 A の値を返す
        ClockUtility::setNow(self::NOW);
        $this->getJson(self::PROBE)->assertJson(['value' => 'base']);
    }

    #[Test]
    public function start_atが未来の版は効かない(): void
    {
        SysDeployFixture::insert(2026010101, '2026-05-01 00:00:00', true, $this->baseDatabase);
        SysDeployFixture::insert(2026010201, '2026-06-01 12:00:01', true, $this->altDatabase);

        $this->getJson(self::PROBE)->assertOk()->assertJson(['value' => 'base', 'database' => $this->baseDatabase]);
    }

    #[Test]
    public function is_activeが偽の版は効かない(): void
    {
        SysDeployFixture::insert(2026010101, '2026-05-01 00:00:00', true, $this->baseDatabase);
        SysDeployFixture::insert(2026010201, '2026-05-02 00:00:00', false, $this->altDatabase);

        $this->getJson(self::PROBE)->assertOk()->assertJson(['value' => 'base', 'database' => $this->baseDatabase]);
    }

    #[Test]
    public function いまの版が無いと299でmstを読まない(): void
    {
        SysDeployFixture::insert(2026010101, '2026-06-01 12:00:01', true, $this->baseDatabase);

        $response = $this->getJson(self::PROBE);

        $response->assertStatus(299);
        $response->assertJsonStructure(['error_code', 'message']);
    }

    #[Test]
    public function 掃除済みの版の時は基底_d_bへ落とさず299になる(): void
    {
        SysDeployFixture::insert(2026010101, '2026-05-01 00:00:00', true, $this->baseDatabase);
        SysDeployFixture::insert(2026010201, '2026-05-02 00:00:00', true, $this->altDatabase, true);

        $this->getJson(self::PROBE)->assertStatus(299);
    }

    #[Test]
    public function check_master_hashは冒頭で固定した版のhashと比べ_後から引き直さない(): void
    {
        SysDeployFixture::insert(2026010101, '2026-05-01 00:00:00', true, $this->baseDatabase);
        SysDeployFixture::insert(2026010201, '2026-06-01 12:30:00', true, $this->altDatabase);

        $aHash = (string) DB::connection('sys')->table('sys_deploy_master')->where('mst_database', $this->baseDatabase)->value('hash');
        $bHash = (string) DB::connection('sys')->table('sys_deploy_master')->where('mst_database', $this->altDatabase)->value('hash');

        // リクエストの途中で時計が start_at を過ぎる（$next の中で時刻を進める）。比較対象は固定した版 A のまま
        Route::middleware([CheckMasterHash::class, 'master.version'])->get(self::PROBE.'-late', function () {
            ClockUtility::setNow('2026-06-01 13:00:00');

            return response()->json(['ok' => true]);
        });

        $this->withHeader('X-Master-Hash', $aHash)->getJson(self::PROBE.'-late')
            ->assertOk()->assertHeaderMissing('X-Master-Update-Required');

        // 時刻が進んだ後の新しいリクエストは版 B。A の hash のクライアントには更新要求が付く
        $this->withHeader('X-Master-Hash', $aHash)->getJson(self::PROBE.'-hash')
            ->assertOk()->assertHeader('X-Master-Update-Required', 'true');
        $this->withHeader('X-Master-Hash', $bHash)->getJson(self::PROBE.'-hash')
            ->assertOk()->assertHeaderMissing('X-Master-Update-Required');
    }

    private function insertConfig(Connection $connection, string $value): void
    {
        $connection->table('mst_config')->insert([
            'deploy_key' => 2026010101,
            'id' => self::CONFIG_ID,
            'value' => $value,
            'value_type' => 'string',
            'description' => 'version switch probe',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function deleteAltRows(): void
    {
        DB::connection('mst_alt')->table('mst_config')->where('id', self::CONFIG_ID)->delete();
    }

    /**
     * @return list<string>
     */
    private function physicalKeyList(): array
    {
        $redis = new Redis;
        $redis->connect((string) config('database.redis.cache.host'), (int) config('database.redis.cache.port'));
        $redis->select((int) config('database.redis.cache.database'));
        /** @var list<string> $keyList */
        $keyList = $redis->keys('*');
        $redis->close();

        return $keyList;
    }
}

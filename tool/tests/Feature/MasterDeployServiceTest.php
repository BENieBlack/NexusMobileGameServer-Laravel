<?php

namespace Tests\Feature;

use App\Exceptions\MasterDeployKeyException;
use App\Services\MasterDeployKeyPolicy;
use App\Services\MasterDeployService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MasterDeployService の採番・登録・補償のテスト（db.md 3.4・3.6、sequence.md 5.4）
 *
 * 共有のテスト DB を使わないよう、sys 接続だけ SQLite のメモリに差し替えて必要な列だけのテーブルを作る。
 */
class MasterDeployServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.sys', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('sys');
        $sys = Schema::connection('sys');
        $sys->create('sys_deploy_master', function ($t) {
            $t->id();
            $t->bigInteger('deploy_key');
            $t->string('hash', 64)->unique();
            $t->date('deploy_date');
            $t->unsignedBigInteger('deploy_count');
            $t->string('status');
            $t->string('deployed_by')->nullable();
            $t->dateTime('deployed_at')->nullable();
            $t->text('description')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
        $sys->create('sys_deploy_asset', function ($t) {
            $t->id();
            $t->bigInteger('deploy_key');
            $t->string('hash', 64)->unique();
            $t->date('deploy_date');
            $t->unsignedBigInteger('deploy_count');
            $t->string('status');
            $t->string('deployed_by')->nullable();
            $t->dateTime('deployed_at')->nullable();
            $t->text('description')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
        $sys->create('sys_deploy', function ($t) {
            $t->id();
            $t->bigInteger('deploy_key');
            $t->dateTime('start_at');
            $t->unsignedBigInteger('sys_deploy_master_id');
            $t->unsignedBigInteger('sys_deploy_asset_id');
            $t->boolean('is_active')->default(true);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
        $sys->create('sys_deploy_master_table', function ($t) {
            $t->unsignedBigInteger('sys_deploy_master_id');
            $t->string('table_name');
            $t->string('hash', 64);
            $t->unsignedBigInteger('file_size');
            $t->string('file_name');
            $t->string('public_url');
        });
    }

    protected function tearDown(): void
    {
        DB::purge('sys');

        parent::tearDown();
    }

    private function service(): MasterDeployService
    {
        return new MasterDeployService(new MasterDeployKeyPolicy(null, [7777777777, 8888888888, 9999999999], ['production', 'staging'], 7000000000, 'testing'));
    }

    private function now(string $date = '2026-11-23 10:00:00'): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'UTC');
    }

    /** @return array<string, mixed> */
    private function export(string $hash = 'hash-a'): array
    {
        return [
            'hash' => $hash,
            'file_size' => 100,
            'table_count' => 1,
            'file_count' => 1,
            'tables' => ['mst_config' => ['hash' => 't-'.$hash, 'file_name' => 'mst_config_x.sqlite', 'file_size' => 100, 'public_url' => "/masterdata/{$hash}/mst_config_x.sqlite"]],
        ];
    }

    private function insertMaster(int $key, string $hash): void
    {
        DB::connection('sys')->table('sys_deploy_master')->insert([
            'deploy_key' => $key, 'hash' => $hash, 'deploy_date' => '2026-11-23', 'deploy_count' => $key % 100, 'status' => 'completed',
        ]);
    }

    /** @return list<int> */
    private function activeIds(): array
    {
        return DB::connection('sys')->table('sys_deploy')->where('is_active', true)->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    #[Test]
    public function 採番はその日の最大に1を足し_無ければ01から(): void
    {
        $service = $this->service();
        $this->assertSame(2026112301, $service->allocateRealKey($this->now()));

        // 件数ではなく最大 + 1。別の日・予約キーの行は数えない（件数方式なら 2026112304 以外になる）
        $this->insertMaster(2026112301, 'h1');
        $this->insertMaster(2026112303, 'h3');
        $this->insertMaster(2026112299, 'h-yesterday');
        $this->insertMaster(7777777777, 'h-rec');
        $this->insertMaster(2026112401, 'h-tomorrow');
        $this->assertSame(2026112304, $service->allocateRealKey($this->now()));
    }

    #[Test]
    public function 採番は日付をutcで決める(): void
    {
        $jst = CarbonImmutable::parse('2026-11-24 08:00:00', 'Asia/Tokyo'); // UTC では 11-23
        $this->assertSame(2026112301, $this->service()->allocateRealKey($jst));
    }

    #[Test]
    public function nn99の次はdeploy_key_exhaustedで翌日に回さない(): void
    {
        $this->insertMaster(2026112399, 'h99');
        try {
            $this->service()->allocateRealKey($this->now());
            $this->fail('例外が出ない');
        } catch (MasterDeployKeyException $e) {
            $this->assertSame('deploy_key_exhausted', $e->errorCode);
            $this->assertSame(422, $e->httpStatus);
        }
    }

    #[Test]
    public function 採番が予約帯に入るときはreserved_key_forbidden(): void
    {
        $this->expectException(MasterDeployKeyException::class);
        $this->expectExceptionMessageMatches('/予約帯/');
        $this->service()->allocateRealKey($this->now('7000-01-01 00:00:00'));
    }

    #[Test]
    public function r2_新しいhashは本物のキーで登録し_他を無効にして新しい行を有効にする(): void
    {
        $service = $this->service();
        $a = $service->registerDeploy($this->export('hash-a'), 2026112301, false, $this->now());
        $this->assertTrue($a['is_new']);
        $this->assertSame([], $a['previous_active_deploy_ids']);
        $b = $service->registerDeploy($this->export('hash-b'), 2026112302, false, $this->now());

        $this->assertSame([$a['sys_deploy_id']], $b['previous_active_deploy_ids']);
        $this->assertSame([$b['sys_deploy_id']], $this->activeIds());
        $master = DB::connection('sys')->table('sys_deploy_master')->where('id', $b['sys_deploy_master_id'])->first();
        $this->assertSame(2026112302, (int) $master->deploy_key);
        $this->assertSame(2, (int) $master->deploy_count);
        $this->assertSame(1, DB::connection('sys')->table('sys_deploy_master_table')->where('sys_deploy_master_id', $b['sys_deploy_master_id'])->count());
    }

    #[Test]
    public function r1_同じhashは新しい行を作らず_idが最大の行を有効に戻す(): void
    {
        $service = $this->service();
        $a = $service->registerDeploy($this->export('hash-a'), 2026112301, false, $this->now());
        $b = $service->registerDeploy($this->export('hash-b'), 2026112302, false, $this->now());
        $this->assertSame([$b['sys_deploy_id']], $this->activeIds());

        $again = $service->registerDeploy($this->export('hash-a'), 2026112303, false, $this->now());

        $this->assertFalse($again['is_new']);
        $this->assertSame($a['sys_deploy_id'], $again['sys_deploy_id']);
        $this->assertSame(2026112301, $again['deploy_key']);
        $this->assertSame([$b['sys_deploy_id']], $again['previous_active_deploy_ids']);
        $this->assertSame([$a['sys_deploy_id']], $this->activeIds());
        $this->assertSame(2, DB::connection('sys')->table('sys_deploy_master')->count());
        $this->assertSame(2, DB::connection('sys')->table('sys_deploy')->count());
    }

    #[Test]
    public function r1_masterを指すsys_deployが無ければ同じキーで新しいsys_deployを作る(): void
    {
        $this->insertMaster(2026112301, 'hash-a');
        $result = $this->service()->registerDeploy($this->export('hash-a'), 2026112302, false, $this->now());

        $this->assertFalse($result['is_new']);
        $this->assertSame(2026112301, $result['deploy_key']);
        $this->assertSame([$result['sys_deploy_id']], $this->activeIds());
        $this->assertSame(1, DB::connection('sys')->table('sys_deploy_asset')->count());
    }

    #[Test]
    public function r3_固定キーは新しい行を作り_同じキーの古い行は残り_deploy_countは0(): void
    {
        $service = $this->service();
        $a = $service->registerDeploy($this->export('hash-a'), 9999999999, true, $this->now());
        $b = $service->registerDeploy($this->export('hash-b'), 9999999999, true, $this->now());

        $this->assertTrue($b['is_new']);
        $this->assertNotSame($a['sys_deploy_id'], $b['sys_deploy_id']);
        $this->assertSame([$b['sys_deploy_id']], $this->activeIds());
        $this->assertSame(2, DB::connection('sys')->table('sys_deploy')->where('deploy_key', 9999999999)->count());
        $this->assertSame(0, (int) DB::connection('sys')->table('sys_deploy_master')->where('id', $b['sys_deploy_master_id'])->value('deploy_count'));
    }

    #[Test]
    public function r4_本物のキーが別のhashで既にあればdeploy_key_conflictで何も変えない(): void
    {
        $this->insertMaster(2026112301, 'other-hash');
        try {
            $this->service()->registerDeploy($this->export('hash-a'), 2026112301, false, $this->now());
            $this->fail('例外が出ない');
        } catch (MasterDeployKeyException $e) {
            $this->assertSame('deploy_key_conflict', $e->errorCode);
            $this->assertSame(409, $e->httpStatus);
        }
        $this->assertSame(1, DB::connection('sys')->table('sys_deploy_master')->count());
        $this->assertSame(0, DB::connection('sys')->table('sys_deploy')->count());
    }

    #[Test]
    public function 予約帯や許可リスト外のキーは登録前に拒否する(): void
    {
        $service = $this->service();
        $codes = [];
        foreach ([[7777777777, false], [7777777778, true], [2026112301, true]] as [$key, $fixed]) {
            try {
                $service->registerDeploy($this->export(), $key, $fixed, $this->now());
            } catch (MasterDeployKeyException $e) {
                $codes[] = $e->errorCode;
            }
        }
        $this->assertSame(['reserved_key_forbidden', 'fixed_key_invalid', 'fixed_key_invalid'], $codes);
        $this->assertSame(0, DB::connection('sys')->table('sys_deploy_master')->count());
    }

    #[Test]
    public function 登録中のdbエラーはregister_failedになりsysは元のまま(): void
    {
        $service = $this->service();
        $service->registerDeploy($this->export('hash-a'), 2026112301, false, $this->now());
        // asset を先に同じ hash で塞いでおくと、新しい master の登録の途中で UNIQUE 違反になる
        DB::connection('sys')->table('sys_deploy_asset')->insert([
            'deploy_key' => 1, 'hash' => 'hash-b', 'deploy_date' => '2026-11-23', 'deploy_count' => 1, 'status' => 'completed',
        ]);
        try {
            $service->registerDeploy($this->export('hash-b'), 2026112302, false, $this->now());
            $this->fail('例外が出ない');
        } catch (MasterDeployKeyException $e) {
            $this->assertSame('register_failed', $e->errorCode);
            $this->assertSame(500, $e->httpStatus);
        }
        $this->assertSame(1, DB::connection('sys')->table('sys_deploy_master')->count());
        $this->assertSame(1, DB::connection('sys')->table('sys_deploy')->count());
        $this->assertCount(1, $this->activeIds());
    }

    #[Test]
    public function 補償は新しい行を無効にし_登録前に有効だった全行を戻す(): void
    {
        $service = $this->service();
        $a = $service->registerDeploy($this->export('hash-a'), 2026112301, false, $this->now());
        // シーダーが入れた行などで有効な行が複数ある状態
        DB::connection('sys')->table('sys_deploy')->insert([
            'deploy_key' => 7777777777, 'start_at' => '2026-01-01 00:00:00', 'sys_deploy_master_id' => 99, 'sys_deploy_asset_id' => 99, 'is_active' => true,
        ]);
        $before = $this->activeIds();
        $this->assertCount(2, $before);

        $b = $service->registerDeploy($this->export('hash-b'), 2026112302, false, $this->now());
        $this->assertSame([$b['sys_deploy_id']], $this->activeIds());

        $service->compensate($b['activated_deploy_id'], $b['previous_active_deploy_ids']);

        $this->assertSame($before, $this->activeIds());
        $this->assertSame(3, DB::connection('sys')->table('sys_deploy')->count()); // 行は残る
        $this->assertNotContains($b['sys_deploy_id'], $this->activeIds());
        $this->assertContains($a['sys_deploy_id'], $this->activeIds());
    }

    #[Test]
    public function 補償はr1で元から有効だった行を有効のまま残す(): void
    {
        $service = $this->service();
        $a = $service->registerDeploy($this->export('hash-a'), 2026112301, false, $this->now());
        $again = $service->registerDeploy($this->export('hash-a'), 2026112302, false, $this->now());
        $this->assertSame([$a['sys_deploy_id']], $again['previous_active_deploy_ids']);

        $service->compensate($again['activated_deploy_id'], $again['previous_active_deploy_ids']);

        $this->assertSame([$a['sys_deploy_id']], $this->activeIds());
    }

    #[Test]
    public function 既存のregisterは今までどおり動く(): void
    {
        $result = $this->service()->register($this->export('hash-a'));

        $this->assertTrue($result['is_new']);
        $this->assertSame([$result['sys_deploy_id']], $this->activeIds());
    }

    #[Test]
    public function 登録の日付はutcで入る(): void
    {
        $jst = CarbonImmutable::parse('2026-11-24 08:00:00', 'Asia/Tokyo'); // UTC では 11-23 23:00
        $r = $this->service()->registerDeploy($this->export('hash-a'), 2026112301, false, $jst);

        $master = DB::connection('sys')->table('sys_deploy_master')->where('id', $r['sys_deploy_master_id'])->first();
        $this->assertSame('2026-11-23', substr((string) $master->deploy_date, 0, 10));
        $this->assertSame('2026-11-23 23:00:00', $master->deployed_at);
        $asset = DB::connection('sys')->table('sys_deploy_asset')->first();
        $this->assertSame('2026-11-23', substr((string) $asset->deploy_date, 0, 10));
    }

    #[Test]
    public function register_failedは元の例外をpreviousに持ち_messageにsqlを出さない(): void
    {
        $service = $this->service();
        DB::connection('sys')->table('sys_deploy_asset')->insert([
            'deploy_key' => 1, 'hash' => 'hash-b', 'deploy_date' => '2026-11-23', 'deploy_count' => 1, 'status' => 'completed',
        ]);
        try {
            $service->registerDeploy($this->export('hash-b'), 2026112302, false, $this->now());
            $this->fail('例外が出ない');
        } catch (MasterDeployKeyException $e) {
            $this->assertNotNull($e->getPrevious());
            $this->assertStringNotContainsString('sys_deploy_asset', $e->getMessage());
            $this->assertStringNotContainsString('hash-b', $e->getMessage());
        }
    }

    #[Test]
    public function 補償は時刻を固定できる(): void
    {
        $service = $this->service();
        $r = $service->registerDeploy($this->export('hash-a'), 2026112301, false, $this->now());
        $service->compensate($r['activated_deploy_id'], [], CarbonImmutable::parse('2026-12-01 09:00:00', 'Asia/Tokyo'));

        $this->assertSame('2026-12-01 00:00:00', DB::connection('sys')->table('sys_deploy')->where('id', $r['sys_deploy_id'])->value('updated_at'));
    }

    #[Test]
    public function r1_同じmasterを指すsys_deployが複数あればidの大きい方が選ばれる(): void
    {
        $this->insertMaster(2026112301, 'hash-a');
        foreach ([1, 2] as $_) {
            DB::connection('sys')->table('sys_deploy')->insert([
                'deploy_key' => 2026112301, 'start_at' => '2026-11-23 00:00:00', 'sys_deploy_master_id' => 1, 'sys_deploy_asset_id' => 1, 'is_active' => false,
            ]);
        }
        $result = $this->service()->registerDeploy($this->export('hash-a'), 2026112302, false, $this->now());

        $this->assertFalse($result['is_new']);
        $this->assertSame(2, $result['sys_deploy_id']);
        $this->assertSame([2], $this->activeIds());
    }
}

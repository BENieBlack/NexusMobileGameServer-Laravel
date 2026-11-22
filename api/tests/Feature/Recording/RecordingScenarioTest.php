<?php

namespace Tests\Feature\Recording;

use App\Models\Mst\MstPlayerLevel;
use Database\Seeders\Recording\RecordingScenarioSeeder;
use Database\Seeders\Recording\RecordingSeeder;
use Database\Seeders\Recording\Scenarios\RecordingAgreementSeeder;
use Database\Seeders\Recording\Scenarios\RecordingDeploySeeder;
use Database\Seeders\Recording\Scenarios\RecordingLoginBonusDisabledSeeder;
use Database\Seeders\Recording\Scenarios\RecordingLoginBonusSeeder;
use Database\Seeders\Recording\Scenarios\RecordingMaintenanceOffSeeder;
use Database\Seeders\Recording\Scenarios\RecordingMaintenanceOnSeeder;
use Database\Seeders\Recording\Scenarios\RecordingResetSeeder;
use Illuminate\Support\Facades\DB;
use NexusMaintenance\Contracts\MaintenanceStorageInterface;
use NexusMaintenance\Infrastructure\Database\DatabaseMaintenanceStorage;
use NexusMaintenance\Services\MaintenanceService;
use PHPUnit\Framework\Attributes\Test;

/**
 * 動画撮影用シーダー全般（環境ガード・冪等性・マーカー・メンテナンス・リセット）のテスト。
 * 規約の API 経由の確認は RecordingAgreementPendingTest。
 */
class RecordingScenarioTest extends RecordingTestCase
{
    #[Test]
    public function 許可されていない環境では例外で止まり行は残る(): void
    {
        // 前提: マーカー行を投入済み（local として流す）
        $this->runSeeder(RecordingAgreementSeeder::class);
        $this->runSeeder(RecordingMaintenanceOnSeeder::class);

        // 本番相当の環境に切り替える
        $this->app->instance('env', 'production');

        // 操作 → 期待: どのシナリオも例外になり、行は消えも変わりもしない
        $seeders = [
            RecordingSeeder::class, RecordingAgreementSeeder::class, RecordingLoginBonusSeeder::class,
            RecordingMaintenanceOnSeeder::class, RecordingMaintenanceOffSeeder::class, RecordingResetSeeder::class,
        ];
        foreach ($seeders as $seeder) {
            try {
                $this->app->make($seeder)->run();
                $this->fail("{$seeder} が本番相当の環境で動いた");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('production', $e->getMessage());
            }
        }
        $this->assertSame(3, $this->countAgreements(markedOnly: true));
        $this->assertSame(1, $this->countMaintenance(markedOnly: true));
        $this->assertSame(1, DB::connection('sys')->table('sys_maintenance')->where('title', 'like', '[REC]%')->where('is_active', true)->count());
    }

    #[Test]
    public function メンテナンスはキャッシュが有効でも有効化と解除が即時に反映される(): void
    {
        // 前提: 実際のストレージ（DB）とキャッシュ有効の MaintenanceService
        $this->app->singleton(MaintenanceStorageInterface::class, fn () => new DatabaseMaintenanceStorage);
        $this->app->forgetInstance(MaintenanceService::class);
        config(['maintenance.cache.enabled' => true]);
        $service = $this->app->make(MaintenanceService::class);
        DB::connection('sys')->table('sys_maintenance')->update(['is_active' => false]);
        $this->assertFalse($service->isUnderMaintenance());

        // On → 503 になる（この判定でキャッシュに載る）
        $this->runSeeder(RecordingMaintenanceOnSeeder::class);
        $this->assertTrue($service->isUnderMaintenance());

        // Off の直後に解ける（キャッシュを消していなければ TTL の間 true のまま）
        $this->runSeeder(RecordingMaintenanceOffSeeder::class);
        $this->assertFalse($service->isUnderMaintenance());

        // Reset も同様
        $this->runSeeder(RecordingMaintenanceOnSeeder::class);
        $this->assertTrue($service->isUnderMaintenance());
        $this->runSeeder(RecordingResetSeeder::class);
        $this->assertFalse($service->isUnderMaintenance());
    }

    #[Test]
    public function 規約は3件作られ2回流しても増えない(): void
    {
        $before = $this->countAgreements(markedOnly: false);

        $this->runSeeder(RecordingAgreementSeeder::class);
        $this->runSeeder(RecordingAgreementSeeder::class);

        $this->assertSame(3, $this->countAgreements(markedOnly: true));
        $this->assertSame($before + 3, $this->countAgreements(markedOnly: false));
        $this->assertSame(
            ['rec-future', 'rec-optional', 'rec-required'],
            DB::connection('sys')->table('sys_agreement')->where('version', 'like', 'rec-%')->orderBy('version')->pluck('version')->all(),
        );
    }

    #[Test]
    public function ログインボーナスは作られ無効から有効に戻り行数は変わらない(): void
    {
        $this->runSeeder(RecordingLoginBonusSeeder::class);
        $bonusCount = DB::connection('mst')->table('mst_login_bonus')->count();
        $contentCount = DB::connection('mst')->table('mst_login_bonus_content')->count();

        // 2回目: 行数は変わらない
        $this->runSeeder(RecordingLoginBonusSeeder::class);
        $this->assertSame($bonusCount, DB::connection('mst')->table('mst_login_bonus')->count());
        $this->assertSame($contentCount, DB::connection('mst')->table('mst_login_bonus_content')->count());
        $this->assertSame(7, DB::connection('mst')->table('mst_login_bonus_content')->where('mst_login_bonus_id', 'daily_login')->distinct()->count('day'));

        // 無効化 → 有効に戻す
        $this->runSeeder(RecordingLoginBonusDisabledSeeder::class);
        $this->assertFalse($this->dailyLoginIsActive());
        $this->runSeeder(RecordingLoginBonusSeeder::class);
        $this->assertTrue($this->dailyLoginIsActive());
        $this->assertSame($bonusCount, DB::connection('mst')->table('mst_login_bonus')->count());
    }

    #[Test]
    public function ログインボーナスは他の行を消さない(): void
    {
        // 前提: 別のログインボーナスが1件ある
        DB::connection('mst')->table('mst_login_bonus')->updateOrInsert(['id' => 'other_bonus'], [
            'type' => 'daily', 'loop_days' => 7, 'priority' => 0, 'is_active' => true,
        ]);

        $this->runSeeder(RecordingLoginBonusSeeder::class);
        $this->runSeeder(RecordingLoginBonusDisabledSeeder::class);
        $this->runSeeder(RecordingResetSeeder::class);

        $this->assertTrue(DB::connection('mst')->table('mst_login_bonus')->where('id', 'other_bonus')->exists());
    }

    #[Test]
    public function メンテナンスを有効にして解除できマーカー行だけを触る(): void
    {
        // 前提: 運営が入れた本物の（マーカーなし）メンテナンスが1件ある
        DB::connection('sys')->table('sys_maintenance')->insert([
            'title' => '本物のメンテナンス', 'message' => 'm', 'start_at' => '2020-01-01 00:00:00', 'end_at' => null, 'is_active' => false,
        ]);
        $before = $this->countMaintenance(markedOnly: false);

        // 有効化（2回流しても1行）
        $this->runSeeder(RecordingMaintenanceOnSeeder::class);
        $this->runSeeder(RecordingMaintenanceOnSeeder::class);
        $this->assertSame($before + 1, $this->countMaintenance(markedOnly: false));
        $this->assertSame(1, $this->countMaintenance(markedOnly: true));

        // 実際に使われるストレージが、動画用のメンテナンスを現在のメンテナンスとして返す
        $current = (new DatabaseMaintenanceStorage)->get();
        $this->assertNotNull($current);
        $this->assertTrue($current->isCurrentlyUnderMaintenance());
        $this->assertStringStartsWith('[REC]', $current->getTitle());

        // 解除: マーカー行だけ無効になり、本物の行は変わらない
        $this->runSeeder(RecordingMaintenanceOffSeeder::class);
        $this->assertSame(
            0,
            DB::connection('sys')->table('sys_maintenance')->where('title', 'like', '[REC]%')->where('is_active', true)->count(),
        );
        $this->assertSame($before + 1, $this->countMaintenance(markedOnly: false));
        $this->assertFalse((bool) DB::connection('sys')->table('sys_maintenance')->where('title', '本物のメンテナンス')->value('is_active'));
    }

    #[Test]
    public function デプロイは配信ファイル付きで追加し2回流しても増えず本物の有効なデプロイを残す(): void
    {
        // 前提: 配信ファイルを持たない本物の有効なデプロイ（基本シードと同じ形）がある
        $this->ensureMasterRows();
        $sys = DB::connection('sys');
        $realActive = $sys->table('sys_deploy')->where('is_active', true)->count();
        $beforeFiles = $sys->table('sys_deploy_master_table')->count();

        $this->runSeeder(RecordingDeploySeeder::class);
        $this->runSeeder(RecordingDeploySeeder::class);

        // 期待: 各 1 行、配信ファイルは追加され、既存の is_active は変わらない
        $this->assertSame(1, $sys->table('sys_deploy')->where('deploy_key', RecordingDeploySeeder::DEPLOY_KEY)->count());
        $this->assertSame(1, $sys->table('sys_deploy_master')->where('description', 'like', '[REC]%')->count());
        $this->assertSame(1, $sys->table('sys_deploy_asset')->where('description', 'like', '[REC]%')->count());
        $this->assertGreaterThan($beforeFiles, $sys->table('sys_deploy_master_table')->count());
        $this->assertSame($realActive + 1, $sys->table('sys_deploy')->where('is_active', true)->count());

        // 期待: ファイルが実在し、ハッシュ・サイズが一覧と一致し、mst の行が入っている
        $masterId = $sys->table('sys_deploy_master')->where('deploy_key', RecordingDeploySeeder::DEPLOY_KEY)->value('id');
        $rows = $sys->table('sys_deploy_master_table')->where('sys_deploy_master_id', $masterId)->get()->keyBy('table_name');
        foreach (RecordingDeploySeeder::REQUIRED_GROUPS as $required) {
            $this->assertTrue($rows->has($required));
        }
        // クライアントの必須テーブル（master_database.gd の REQUIRED_TABLES）も配信される
        foreach (['mst_gacha', 'mst_unit', 'mst_equipment'] as $clientRequired) {
            $this->assertTrue($rows->has($clientRequired));
        }
        // 運営ツールと同じく、mst の親テーブル全部（l10n は親のファイルに入る）
        $this->assertCount(
            collect(DB::connection('mst')->select("SHOW TABLES LIKE 'mst_%'"))
                ->map(fn ($r) => (string) array_values((array) $r)[0])
                ->reject(fn ($n) => str_ends_with($n, '__l10n'))->count(),
            $rows,
        );
        $manifest = $sys->table('sys_deploy_master')->where('id', $masterId)->value('hash');
        foreach ($rows as $name => $row) {
            $path = $this->masterDataDir.'/'.$manifest.'/'.$row->file_name;
            $this->assertFileExists($path);
            $this->assertSame($row->hash, hash_file('sha256', $path));
            $this->assertSame((int) $row->file_size, filesize($path));
            $this->assertSame('/masterdata/'.$manifest.'/'.$row->file_name, $row->public_url);

            $sqlite = new \PDO('sqlite:'.$path);
            $count = (int) $sqlite->query("SELECT COUNT(*) FROM `{$name}`")->fetchColumn();
            $this->assertSame(DB::connection('mst')->table($name)->count(), $count);
        }

        // 期待: mst_player_level は string の id を主キーに持つまま配信される（id と level の両方が入る）
        $playerLevel = new \PDO('sqlite:'.$this->masterDataDir.'/'.$manifest.'/'.$rows['mst_player_level']->file_name);
        $idColumn = collect($playerLevel->query('PRAGMA table_info(`mst_player_level`)')->fetchAll(\PDO::FETCH_ASSOC))->firstWhere('name', 'id');
        $this->assertNotNull($idColumn);
        $this->assertSame(1, (int) $idColumn['pk']);
        $firstLevel = $playerLevel->query('SELECT id, level FROM `mst_player_level` ORDER BY level LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(MstPlayerLevel::makeId((int) $firstLevel['level']), $firstLevel['id']);

        // 期待: version API は録画用を最新として、配信ファイルを master.tables に返す
        $this->getJson('/api/auth/version')->assertOk()
            ->assertJsonPath('needs_update', true)
            ->assertJsonPath('latest_deploy_key', RecordingDeploySeeder::DEPLOY_KEY)
            ->assertJsonPath('master.tables.mst_player_level.hash', $rows['mst_player_level']->hash)
            ->assertJsonPath('master.tables.mst_config.url', $rows['mst_config']->public_url);
    }

    #[Test]
    public function デプロイは有効なデプロイが無くても追加でき_version_apiが成功する(): void
    {
        $this->ensureMasterRows();
        DB::connection('sys')->table('sys_deploy')->update(['is_active' => false]);

        $this->runSeeder(RecordingDeploySeeder::class);

        $this->getJson('/api/auth/version')->assertOk()->assertJsonPath('needs_update', true);
    }

    #[Test]
    public function 配信ファイルを持つ有効な本物のデプロイがあればデプロイを追加しない(): void
    {
        $this->ensureMasterRows();
        $sys = DB::connection('sys');
        $sys->table('sys_deploy')->where('deploy_key', RecordingDeploySeeder::DEPLOY_KEY)->delete();
        $sys->table('sys_deploy')->update(['is_active' => false]);
        $sys->table('sys_deploy_master_table')->insert([
            'sys_deploy_master_id' => 9999, 'table_name' => 'mst_item', 'hash' => str_repeat('a', 64),
            'file_size' => 1, 'file_name' => 'mst_item_x.sqlite', 'public_url' => '/masterdata/x/mst_item_x.sqlite',
        ]);
        $sys->table('sys_deploy')->insert([
            'deploy_key' => 190001011, 'start_at' => '2020-01-01 00:00:00',
            'sys_deploy_master_id' => 9999, 'sys_deploy_asset_id' => 1, 'is_active' => true,
        ]);
        $before = $sys->table('sys_deploy')->count();

        $this->runSeeder(RecordingDeploySeeder::class);

        $this->assertSame($before, $sys->table('sys_deploy')->count());
        $this->assertSame([], glob($this->masterDataDir.'/*') ?: []);
    }

    #[Test]
    public function 録画用より大きいdeploy_keyの有効なデプロイがあれば追加せずリセットでも消さない(): void
    {
        $this->ensureMasterRows();
        $sys = DB::connection('sys');
        $sys->table('sys_deploy')->where('deploy_key', RecordingDeploySeeder::DEPLOY_KEY)->delete();
        foreach ([999999999, 888888888] as $key) {
            $sys->table('sys_deploy')->insert([
                'deploy_key' => $key, 'start_at' => '2020-01-01 00:00:00',
                'sys_deploy_master_id' => 1, 'sys_deploy_asset_id' => 1, 'is_active' => true,
            ]);
        }

        $this->runSeeder(RecordingDeploySeeder::class);
        $this->assertSame(0, $sys->table('sys_deploy')->where('deploy_key', RecordingDeploySeeder::DEPLOY_KEY)->count());

        $this->runSeeder(RecordingResetSeeder::class);
        $this->assertSame(2, $sys->table('sys_deploy')->whereIn('deploy_key', [999999999, 888888888])->where('is_active', true)->count());
    }

    #[Test]
    public function 運営ツールの10桁のdeploy_keyの有効なデプロイがあれば追加せずリセットでも消さない(): void
    {
        $this->ensureMasterRows();
        $sys = DB::connection('sys');
        $sys->table('sys_deploy')->where('deploy_key', RecordingDeploySeeder::DEPLOY_KEY)->delete();
        // MasterDeployService::generateDeployKey が作る形（YYYYMMDD×100+連番）。配信ファイルは持たない
        $realKey = 2026112201;
        $this->assertGreaterThan(RecordingDeploySeeder::DEPLOY_KEY, $realKey);
        $sys->table('sys_deploy')->insert([
            'deploy_key' => $realKey, 'start_at' => '2020-01-01 00:00:00',
            'sys_deploy_master_id' => 1, 'sys_deploy_asset_id' => 1, 'is_active' => true,
        ]);
        $before = $sys->table('sys_deploy')->count();

        $this->runSeeder(RecordingDeploySeeder::class);

        $this->assertSame($before, $sys->table('sys_deploy')->count());
        $this->assertSame([], glob($this->masterDataDir.'/*') ?: []);

        $this->runSeeder(RecordingResetSeeder::class);
        $this->assertTrue((bool) $sys->table('sys_deploy')->where('deploy_key', $realKey)->value('is_active'));
    }

    #[Test]
    public function リセットは本物のsys_deployが録画用のmasterを共有していても本物の行を消さない(): void
    {
        $this->ensureMasterRows();
        $sys = DB::connection('sys');
        $this->runSeeder(RecordingDeploySeeder::class);
        $recMasterId = $sys->table('sys_deploy_master')->where('deploy_key', RecordingDeploySeeder::DEPLOY_KEY)->value('id');
        $this->assertNotNull($recMasterId);

        // 本物（マーカー無し）の sys_deploy が、録画用の master を指している（外部キーが無いので起こりうる）
        $sys->table('sys_deploy')->insert([
            'deploy_key' => 2026112201, 'start_at' => '2020-01-01 00:00:00',
            'sys_deploy_master_id' => $recMasterId, 'sys_deploy_asset_id' => 1, 'is_active' => true,
        ]);

        $this->runSeeder(RecordingResetSeeder::class);

        $this->assertSame(1, $sys->table('sys_deploy')->where('deploy_key', 2026112201)->count());
        $this->assertSame(0, $sys->table('sys_deploy')->where('deploy_key', RecordingDeploySeeder::DEPLOY_KEY)->count());
        // 本物が指す master は、マーカー付きでも残る
        $this->assertSame(1, $sys->table('sys_deploy_master')->where('id', $recMasterId)->count());
    }

    #[Test]
    public function 配信ファイルの出力先が無ければ失敗する(): void
    {
        $this->ensureMasterRows();
        config(['recording.masterdata_dir' => $this->masterDataDir.'/missing']);

        $this->expectException(\RuntimeException::class);
        $this->app->make(RecordingDeploySeeder::class)->run();
    }

    #[Test]
    public function リセットはマーカー行だけを消し非マーカー行を残す(): void
    {
        // 前提: 非マーカーの規約・メンテナンス・デプロイと、全シナリオの投入
        DB::connection('sys')->table('sys_agreement')->insert([
            'version' => '1.0.0', 'title' => '本物の規約', 'url' => 'https://example.com', 'is_required' => true, 'published_at' => '2020-01-01 00:00:00',
        ]);
        DB::connection('sys')->table('sys_maintenance')->insert([
            'title' => '本物のメンテナンス', 'message' => 'm', 'start_at' => '2020-01-01 00:00:00', 'end_at' => null, 'is_active' => false,
        ]);
        $this->ensureMasterRows();
        DB::connection('sys')->table('sys_deploy')->update(['is_active' => false]);
        // 本物のデプロイの配信ファイル（別ディレクトリ）が、リセットで残ることも確かめる
        mkdir($this->masterDataDir.'/'.str_repeat('b', 64), 0755, true);
        file_put_contents($this->masterDataDir.'/'.str_repeat('b', 64).'/real.sqlite', 'x');
        $this->runSeeder(RecordingSeeder::class);
        $this->runSeeder(RecordingMaintenanceOnSeeder::class);
        $this->runSeeder(RecordingLoginBonusDisabledSeeder::class);
        $realAgreements = DB::connection('sys')->table('sys_agreement')->where('version', 'not like', 'rec-%')->count();
        $realMaintenance = DB::connection('sys')->table('sys_maintenance')->where('title', 'not like', '[REC]%')->count();
        $realDeploys = DB::connection('sys')->table('sys_deploy')->where('deploy_key', '!=', RecordingDeploySeeder::DEPLOY_KEY)->count();

        $recMasterId = DB::connection('sys')->table('sys_deploy_master')->where('deploy_key', RecordingDeploySeeder::DEPLOY_KEY)->value('id');
        $this->assertGreaterThan(0, DB::connection('sys')->table('sys_deploy_master_table')->where('sys_deploy_master_id', $recMasterId)->count());

        // 操作（2回流しても同じ）
        $this->runSeeder(RecordingResetSeeder::class);
        $this->runSeeder(RecordingResetSeeder::class);

        // 期待: マーカー行は無くなり、非マーカー行は残り、daily_login は有効
        $this->assertSame(0, $this->countAgreements(markedOnly: true));
        $this->assertSame(0, $this->countMaintenance(markedOnly: true));
        $this->assertSame(0, DB::connection('sys')->table('sys_deploy')->where('deploy_key', RecordingDeploySeeder::DEPLOY_KEY)->count());
        $this->assertSame(0, DB::connection('sys')->table('sys_deploy_master')->where('description', 'like', '[REC]%')->count());
        $this->assertSame(0, DB::connection('sys')->table('sys_deploy_asset')->where('description', 'like', '[REC]%')->count());
        $this->assertSame(0, DB::connection('sys')->table('sys_deploy_master_table')->where('sys_deploy_master_id', $recMasterId)->count());
        $this->assertSame([$this->masterDataDir.'/'.str_repeat('b', 64)], glob($this->masterDataDir.'/*') ?: []);
        $this->assertSame($realAgreements, DB::connection('sys')->table('sys_agreement')->count());
        $this->assertSame($realMaintenance, $this->countMaintenance(markedOnly: false));
        $this->assertSame($realDeploys, DB::connection('sys')->table('sys_deploy')->count());
        $this->assertTrue($this->dailyLoginIsActive());
    }

    #[Test]
    public function 既定の一覧は破壊的なシナリオを含まない(): void
    {
        $default = RecordingSeeder::defaultScenarios();

        foreach ($default as $class) {
            $this->assertTrue(is_subclass_of($class, RecordingScenarioSeeder::class));
        }
        foreach ([RecordingMaintenanceOnSeeder::class, RecordingMaintenanceOffSeeder::class, RecordingLoginBonusDisabledSeeder::class, RecordingResetSeeder::class] as $excluded) {
            $this->assertNotContains($excluded, $default);
        }
    }

    private function dailyLoginIsActive(): bool
    {
        return (bool) DB::connection('mst')->table('mst_login_bonus')->where('id', 'daily_login')->value('is_active');
    }
}

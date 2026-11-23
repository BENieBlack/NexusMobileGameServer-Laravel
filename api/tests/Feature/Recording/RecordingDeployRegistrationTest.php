<?php

namespace Tests\Feature\Recording;

use App\Repositories\Sys\SysDeployRepository;
use Database\Seeders\Recording\Scenarios\RecordingDeploySeeder;
use Database\Seeders\Recording\Scenarios\RecordingResetSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use NexusVersion\Constants\DeployKeyConst;
use PHPUnit\Framework\Attributes\Test;

/**
 * RecordingDeploySeeder が、build で作られた録画用の版（成果物 7777777777_{hash16} と版の mst DB）を sys に登録する挙動。
 * 設計: db.md 11.2・sequence.md 7 節（R19。録画用の版は tool の build で作る。シーダーは作らない）。
 * 版の mst DB は使い捨ての名前（ランダムな hash）で作り、テストの終わりに消す。
 */
class RecordingDeployRegistrationTest extends RecordingTestCase
{
    private const KEY = RecordingDeploySeeder::DEPLOY_KEY;

    /** 版の DB・成果物を用意せず db:seed を直接流す（runSeeder は版を自動で用意するので使わない） */
    private function seedDeployWithoutPreparing(): void
    {
        $this->artisan('db:seed', ['--class' => RecordingDeploySeeder::class, '--force' => true])->assertExitCode(0);
    }

    #[Test]
    public function deploy_keyは予約帯の録画用の固定キーである(): void
    {
        $this->assertSame(DeployKeyConst::FIXED_KEY_RECORDING, RecordingDeploySeeder::DEPLOY_KEY);
        $this->assertGreaterThanOrEqual(DeployKeyConst::RESERVED_FLOOR, RecordingDeploySeeder::DEPLOY_KEY);
    }

    #[Test]
    public function 本番では何もせず例外で止まる(): void
    {
        $this->prepareRecordingVersion();
        $sys = DB::connection('sys');
        $before = $sys->table('sys_deploy')->count();
        $this->app['env'] = 'production';

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('本番では実行できません');
            (new RecordingDeploySeeder)->run();
        } finally {
            $this->app['env'] = 'testing';
            $this->assertSame($before, $sys->table('sys_deploy')->count());
            $this->assertSame(0, $sys->table('sys_deploy_master')->where('deploy_key', self::KEY)->count());
        }
    }

    #[Test]
    public function 成果物が無いときは何も書かずに中止する(): void
    {
        $sys = DB::connection('sys');
        $deployBefore = $sys->table('sys_deploy')->count();
        $masterBefore = $sys->table('sys_deploy_master')->count();

        $this->seedDeployWithoutPreparing();

        $this->assertSame($deployBefore, $sys->table('sys_deploy')->count());
        $this->assertSame($masterBefore, $sys->table('sys_deploy_master')->count());
    }

    #[Test]
    public function 成果物の出力先のディレクトリ自体が無くても例外にせず中止する(): void
    {
        config(['recording.masterdata_dir' => $this->masterDataDir.'/missing']);
        $before = DB::connection('sys')->table('sys_deploy')->count();

        $this->seedDeployWithoutPreparing();

        $this->assertSame($before, DB::connection('sys')->table('sys_deploy')->count());
    }

    #[Test]
    public function 版の_d_bが実在しないときはsysを何も書かずに中止する(): void
    {
        $this->prepareRecordingVersion(withDatabase: false);
        $sys = DB::connection('sys');
        $deployBefore = $sys->table('sys_deploy')->count();
        $masterBefore = $sys->table('sys_deploy_master')->count();

        $this->seedDeployWithoutPreparing();

        $this->assertSame($deployBefore, $sys->table('sys_deploy')->count());
        $this->assertSame($masterBefore, $sys->table('sys_deploy_master')->count());
        $this->assertSame(0, $sys->table('sys_deploy_asset')->where('deploy_key', self::KEY)->count());
    }

    #[Test]
    public function manifestが壊れている成果物は無視して中止する(): void
    {
        $version = $this->prepareRecordingVersion();
        file_put_contents($this->masterDataDir.'/'.$version['dir'].'/manifest.json', '{broken');

        $this->assertNull(RecordingDeploySeeder::findLatestArtifact());
        $this->seedDeployWithoutPreparing();
        $this->assertSame(0, DB::connection('sys')->table('sys_deploy')->where('deploy_key', self::KEY)->count());
    }

    #[Test]
    public function 版の_d_bがあれば登録し_sysだけ消えた後の再実行でも同じ版を登録し直す(): void
    {
        $version = $this->prepareRecordingVersion();
        $sys = DB::connection('sys');

        $this->runSeeder(RecordingDeploySeeder::class);

        $master = $sys->table('sys_deploy_master')->where('deploy_key', self::KEY)->first();
        $this->assertNotNull($master);
        $this->assertSame($version['hash'], $master->hash);
        $this->assertSame($version['database'], $master->mst_database);
        $this->assertSame($version['dir'], $master->artifact_dir);
        $this->assertSame('master_build', $master->deployed_by);
        $this->assertSame('build', $master->source_kind);
        $this->assertStringNotContainsString('[REC]', (string) $master->description);
        $tables = $sys->table('sys_deploy_master_table')->where('sys_deploy_master_id', $master->id)->pluck('public_url', 'table_name');
        $this->assertSame(['mst_config', 'mst_player_level'], $tables->keys()->sort()->values()->all());
        $this->assertStringStartsWith('/masterdata/'.$version['dir'].'/', $tables['mst_config']);
        $deploy = $sys->table('sys_deploy')->where('deploy_key', self::KEY)->first();
        $this->assertTrue((bool) $deploy->is_active);
        $this->assertSame($master->id, $deploy->sys_deploy_master_id);

        // sys だけが消えた状態（版の DB と成果物は残る）→ 流し直すと同じ版が登録される
        (new RecordingResetSeeder)->run();
        $sys->table('sys_deploy')->where('deploy_key', self::KEY)->delete();
        $this->prepareRecordingVersion($version['hash']);
        $this->runSeeder(RecordingDeploySeeder::class);
        $again = $sys->table('sys_deploy_master')->where('deploy_key', self::KEY)->first();
        $this->assertSame($version['hash'], $again->hash);
    }

    #[Test]
    public function build_が作った行があれば_masterを再利用し_行を増やさず_無効に戻したdeployを有効に戻す(): void
    {
        $this->prepareRecordingVersion();
        $sys = DB::connection('sys');

        $this->runSeeder(RecordingDeploySeeder::class);
        $first = $sys->table('sys_deploy')->where('deploy_key', self::KEY)->first();
        $this->assertNotNull($first);

        $sys->table('sys_deploy')->where('id', $first->id)->update(['is_active' => false]);
        $this->runSeeder(RecordingDeploySeeder::class);

        $rows = $sys->table('sys_deploy')->where('deploy_key', self::KEY)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($first->id, $rows[0]->id);
        $this->assertSame(1, (int) $rows[0]->is_active);
        $this->assertSame(1, $sys->table('sys_deploy_master')->where('deploy_key', self::KEY)->count());
        $this->assertSame(1, $sys->table('sys_deploy_asset')->where('deploy_key', self::KEY)->count());
    }

    #[Test]
    public function 成果物が複数あるときはbuilt_atが最新のものを使う(): void
    {
        $old = $this->prepareRecordingVersion(builtAt: '2026-11-20 00:00:00');
        $new = $this->prepareRecordingVersion(builtAt: '2026-11-24 12:00:00');
        $older = $this->prepareRecordingVersion(builtAt: '2026-11-01 00:00:00');

        $this->assertSame($new['dir'], RecordingDeploySeeder::findLatestArtifact()['dir'] ?? null);

        $this->seedDeployWithoutPreparing();

        $this->assertSame([$new['hash']], DB::connection('sys')->table('sys_deploy_master')->where('deploy_key', self::KEY)->pluck('hash')->all());
        $this->assertNotSame($old['hash'], $new['hash']);
        $this->assertNotSame($older['hash'], $new['hash']);
    }

    #[Test]
    public function 最新の成果物の版のdbが無ければ古い版にはフォールバックせず中止する(): void
    {
        $this->prepareRecordingVersion(builtAt: '2026-11-20 00:00:00');
        $this->prepareRecordingVersion(builtAt: '2026-11-24 12:00:00', withDatabase: false);

        $this->seedDeployWithoutPreparing();

        $this->assertSame(0, DB::connection('sys')->table('sys_deploy_master')->where('deploy_key', self::KEY)->count());
    }

    #[Test]
    public function start_atが未来の予約済みデプロイがあれば何もしない(): void
    {
        $this->prepareRecordingVersion();
        $sys = DB::connection('sys');
        $sys->table('sys_deploy')->insert([
            'deploy_key' => 2026112201, 'start_at' => '2999-01-01 00:00:00',
            'sys_deploy_master_id' => 1, 'sys_deploy_asset_id' => 1, 'is_active' => true,
        ]);

        $this->seedDeployWithoutPreparing();

        $this->assertSame(0, $sys->table('sys_deploy')->where('deploy_key', self::KEY)->count());
        $this->assertSame(0, $sys->table('sys_deploy_master')->where('deploy_key', self::KEY)->count());

        $this->runSeeder(RecordingResetSeeder::class);
        $this->assertSame(1, $sys->table('sys_deploy')->where('deploy_key', 2026112201)->where('is_active', true)->count());
    }

    #[Test]
    public function リセットは録画用のキーの行と成果物を消し_版の_d_bと他の成果物は残す(): void
    {
        $version = $this->prepareRecordingVersion();
        $sys = DB::connection('sys');
        $other = $this->masterDataDir.'/'.str_repeat('b', 64);
        mkdir($other, 0755, true);
        $sys->table('sys_deploy')->insert([
            'deploy_key' => 2026112201, 'start_at' => '2020-01-01 00:00:00',
            'sys_deploy_master_id' => 1, 'sys_deploy_asset_id' => 1, 'is_active' => true,
        ]);
        $this->runSeeder(RecordingDeploySeeder::class);
        $masterId = $sys->table('sys_deploy_master')->where('deploy_key', self::KEY)->value('id');

        $this->runSeeder(RecordingResetSeeder::class);
        $this->runSeeder(RecordingResetSeeder::class);

        $this->assertSame(0, $sys->table('sys_deploy')->where('deploy_key', self::KEY)->count());
        $this->assertSame(0, $sys->table('sys_deploy_master')->where('deploy_key', self::KEY)->count());
        $this->assertSame(0, $sys->table('sys_deploy_asset')->where('deploy_key', self::KEY)->count());
        $this->assertSame(0, $sys->table('sys_deploy_master_table')->where('sys_deploy_master_id', $masterId)->count());
        $this->assertDirectoryDoesNotExist($this->masterDataDir.'/'.$version['dir']);
        $this->assertDirectoryExists($other);
        $this->assertSame(1, $sys->table('sys_deploy')->where('deploy_key', 2026112201)->count());
        // 版の DB は消さない（db.md 4.6 の掃除に任せる）
        $exists = $this->ddlConnection()->select('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$version['database']]);
        $this->assertCount(1, $exists);
    }

    #[Test]
    public function キャッシュはscheduleと旧キーとdeploy_keyとidを破棄する(): void
    {
        $this->prepareRecordingVersion();
        $redis = Cache::store('redis');
        $this->runSeeder(RecordingDeploySeeder::class);
        $id = (int) DB::connection('sys')->table('sys_deploy')->where('deploy_key', self::KEY)->value('id');

        $repository = app(SysDeployRepository::class);
        $keys = [$repository->scheduleCacheKey(), $repository->legacyLatestDownloadableCacheKey(), 'sys:sys_deploy:deploy_key:'.self::KEY, 'sys:sys_deploy:id:'.$id];
        foreach ($keys as $key) {
            $redis->put($key, 'stale', 600);
        }

        $this->runSeeder(RecordingDeploySeeder::class);

        foreach ($keys as $key) {
            $this->assertNull($redis->get($key), $key);
        }
    }

    #[Test]
    public function 登録した配信ファイルの一覧とassetはbuildと同じ形になる(): void
    {
        $version = $this->prepareRecordingVersion();
        $sys = DB::connection('sys');

        $this->runSeeder(RecordingDeploySeeder::class);

        $masterId = $sys->table('sys_deploy_master')->where('deploy_key', self::KEY)->value('id');
        $rows = $sys->table('sys_deploy_master_table')->where('sys_deploy_master_id', $masterId)->get()->keyBy('table_name');
        $this->assertSame(['mst_config', 'mst_player_level'], $rows->keys()->sort()->values()->all());
        foreach ($rows as $name => $row) {
            $this->assertSame('/masterdata/'.$version['dir'].'/'.$row->file_name, $row->public_url);
            $this->assertStringStartsWith($name.'_', $row->file_name);
            $this->assertSame(1, (int) $row->file_size);
        }
        $asset = $sys->table('sys_deploy_asset')->where('deploy_key', self::KEY)->first();
        $this->assertSame('master_build', $asset->deployed_by);
        $this->assertSame($version['hash'], $asset->hash);
    }

    #[Test]
    public function 掃除済みの印や別の_d_b名の行を再利用するときは実在確認した版の_d_bに揃える(): void
    {
        $version = $this->prepareRecordingVersion();
        $sys = DB::connection('sys');
        $this->runSeeder(RecordingDeploySeeder::class);
        $sys->table('sys_deploy_master')->where('deploy_key', self::KEY)->update(['mst_dropped_at' => '2026-11-01 00:00:00', 'mst_database' => 'other-db']);

        $this->runSeeder(RecordingDeploySeeder::class);

        $master = $sys->table('sys_deploy_master')->where('deploy_key', self::KEY)->first();
        $this->assertNull($master->mst_dropped_at);
        $this->assertSame($version['database'], $master->mst_database);
    }

    #[Test]
    public function シンボリックリンクの成果物は無視し_リセットは中身を辿らない(): void
    {
        $target = sys_get_temp_dir().'/recording_link_target_'.uniqid('', true);
        mkdir($target, 0755, true);
        file_put_contents($target.'/keep.txt', 'x');
        $link = $this->masterDataDir.'/'.self::KEY.'_'.str_repeat('a', 16);
        symlink($target, $link);

        try {
            $this->assertSame([], RecordingDeploySeeder::artifactDirNames());
            $this->runSeeder(RecordingResetSeeder::class);
            $this->assertFileExists($target.'/keep.txt');
        } finally {
            @unlink($link);
            @unlink($target.'/keep.txt');
            @rmdir($target);
        }
    }

    #[Test]
    public function リセットで残したmasterの成果物は消さない(): void
    {
        $version = $this->prepareRecordingVersion();
        $sys = DB::connection('sys');
        $this->runSeeder(RecordingDeploySeeder::class);
        $masterId = $sys->table('sys_deploy_master')->where('deploy_key', self::KEY)->value('id');
        $sys->table('sys_deploy')->insert([
            'deploy_key' => 2026112201, 'start_at' => '2020-01-01 00:00:00',
            'sys_deploy_master_id' => $masterId, 'sys_deploy_asset_id' => 1, 'is_active' => true,
        ]);

        $this->runSeeder(RecordingResetSeeder::class);

        $this->assertSame(1, $sys->table('sys_deploy_master')->where('id', $masterId)->count());
        $this->assertDirectoryExists($this->masterDataDir.'/'.$version['dir']);
    }
}

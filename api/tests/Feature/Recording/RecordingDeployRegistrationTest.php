<?php

namespace Tests\Feature\Recording;

use Database\Seeders\Recording\RecordingMasterExporter;
use Database\Seeders\Recording\Scenarios\RecordingDeploySeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use NexusVersion\Constants\DeployKeyConst;
use PHPUnit\Framework\Attributes\Test;

/**
 * RecordingDeploySeeder の登録（hash 引き）と RecordingMasterExporter の書き出し規則。
 * 設計: db.md 11.2・6.6。tool の MasterDataExporter と同じ規則（主キー昇順・整数・同じ mst なら同じ hash）。
 */
class RecordingDeployRegistrationTest extends RecordingTestCase
{
    private const KEY = RecordingDeploySeeder::DEPLOY_KEY;

    #[Test]
    public function deploy_keyは予約帯の録画用の固定キーである(): void
    {
        $this->assertSame(DeployKeyConst::FIXED_KEY_RECORDING, RecordingDeploySeeder::DEPLOY_KEY);
        $this->assertGreaterThanOrEqual(DeployKeyConst::RESERVED_FLOOR, RecordingDeploySeeder::DEPLOY_KEY);
    }

    #[Test]
    public function 本番では何もせず例外で止まる(): void
    {
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
        }
    }

    #[Test]
    public function 同じmstなら同じmasterを再利用し_行を増やさず_無効に戻したdeployを有効に戻す(): void
    {
        $this->ensureMasterRows();
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
        $this->assertSame(1, $sys->table('sys_deploy_master')->where('description', 'like', '[REC]%')->count());
    }

    #[Test]
    public function mstが変わると新しい行で入り_古い同じキーのdeployは無効になり_他のキーは触らない(): void
    {
        $this->ensureMasterRows();
        $sys = DB::connection('sys');
        $sys->table('sys_deploy')->insert([
            'deploy_key' => 2026112201, 'start_at' => '2020-01-01 00:00:00',
            'sys_deploy_master_id' => 1, 'sys_deploy_asset_id' => 1, 'is_active' => true,
        ]);

        $this->runSeeder(RecordingDeploySeeder::class);
        $oldId = (int) $sys->table('sys_deploy')->where('deploy_key', self::KEY)->value('id');

        DB::connection('mst')->table('mst_config')->where('id', 'test')->update(['value' => '2']);
        $this->runSeeder(RecordingDeploySeeder::class);

        $rows = $sys->table('sys_deploy')->where('deploy_key', self::KEY)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame($oldId, (int) $rows[0]->id);
        $this->assertSame(0, (int) $rows[0]->is_active);
        $this->assertSame(1, (int) $rows[1]->is_active);
        $this->assertNotSame($rows[0]->sys_deploy_master_id, $rows[1]->sys_deploy_master_id);
        $this->assertSame(1, (int) $sys->table('sys_deploy')->where('deploy_key', 2026112201)->value('is_active'));

        // 元の内容に戻すと、古い行を有効に戻す（新しい行を作らない）
        DB::connection('mst')->table('mst_config')->where('id', 'test')->update(['value' => '1']);
        $this->runSeeder(RecordingDeploySeeder::class);
        $rows = $sys->table('sys_deploy')->where('deploy_key', self::KEY)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([1, 0], [(int) $rows[0]->is_active, (int) $rows[1]->is_active]);
    }

    #[Test]
    public function キャッシュはlatestとdeploy_keyとidを破棄する(): void
    {
        $this->ensureMasterRows();
        $redis = Cache::store('redis');
        $this->runSeeder(RecordingDeploySeeder::class);
        $id = (int) DB::connection('sys')->table('sys_deploy')->where('deploy_key', self::KEY)->value('id');

        $keys = ['sys:sys_deploy:latest_downloadable', 'sys:sys_deploy:deploy_key:'.self::KEY, 'sys:sys_deploy:id:'.$id];
        foreach ($keys as $key) {
            $redis->put($key, 'stale', 600);
        }

        $this->runSeeder(RecordingDeploySeeder::class);

        foreach ($keys as $key) {
            $this->assertNull($redis->get($key), $key);
        }
    }

    #[Test]
    public function 書き出しは主キー昇順で_bigintは整数で_同じmstなら同じhashになる(): void
    {
        $mst = DB::connection('mst');
        $mst->table('mst_config')->delete();
        $row = static fn (string $id, int $key): array => [
            'deploy_key' => $key, 'id' => $id, 'value' => 'v', 'value_type' => 'string', 'description' => 'd',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ];
        // 挿入順をばらす。BINARY 昇順は B < Z < a < c（照合順序に依ると a < B < c < Z）
        $mst->table('mst_config')->insert([$row('c', 9999999999), $row('a', 2026010101), $row('Z', 1), $row('B', 2)]);

        $first = (new RecordingMasterExporter($this->masterDataDir))->export(['mst_config']);
        $second = (new RecordingMasterExporter($this->masterDataDir))->export(['mst_config']);

        $this->assertSame($first['hash'], $second['hash']);
        $this->assertSame($first['tables']['mst_config']['hash'], $second['tables']['mst_config']['hash']);

        $table = $first['tables']['mst_config'];
        $this->assertSame('mst_config_'.$table['hash'].'.sqlite', $table['file_name']);
        $this->assertSame('/masterdata/'.$first['hash'].'/'.$table['file_name'], $table['public_url']);
        $this->assertSame(hash('sha256', json_encode(['mst_config' => $table['hash']])), $first['hash']);

        $sqlite = new \PDO('sqlite:'.$this->masterDataDir.'/'.$first['hash'].'/'.$table['file_name']);
        $this->assertSame(['B', 'Z', 'a', 'c'], $sqlite->query('SELECT id FROM mst_config ORDER BY rowid')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame(
            ['deploy_key', 'id', 'value', 'value_type', 'description', 'created_at', 'updated_at'],
            array_column($sqlite->query('PRAGMA table_info(mst_config)')->fetchAll(\PDO::FETCH_ASSOC), 'name'),
        );
        $this->assertSame('integer', $sqlite->query("SELECT typeof(deploy_key) FROM mst_config WHERE id = 'c'")->fetchColumn());
        $this->assertEquals(9999999999, $sqlite->query("SELECT deploy_key FROM mst_config WHERE id = 'c'")->fetchColumn());

        // 内容が変わればハッシュが変わる
        $mst->table('mst_config')->where('id', 'a')->update(['value' => 'changed']);
        $third = (new RecordingMasterExporter($this->masterDataDir))->export(['mst_config']);
        $this->assertNotSame($first['hash'], $third['hash']);
    }
}

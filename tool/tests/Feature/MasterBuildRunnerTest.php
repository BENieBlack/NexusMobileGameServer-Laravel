<?php

namespace Tests\Feature;

use App\Exceptions\MasterDeployKeyException;
use App\Exceptions\MasterDeployRunException;
use App\Services\GoogleSpreadsheetService;
use App\Services\MasterArtifactStore;
use App\Services\MasterBuildRunner;
use App\Services\MasterCacheInvalidator;
use App\Services\MasterDeployLock;
use App\Services\MasterDeployService;
use App\Services\MasterVersionDatabase;
use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * MasterBuildRunner（版ごとの mst DB 設計の build。db.md 6.6）のテスト
 *
 * mst は実物の MySQL（db-master）で動かす。名前が衝突しないよう、このテストだけの使い捨ての `APP_NAME`（nxt + 乱数）で
 * 基底 DB `{app}-testing-master` を作り、版の DB・作業用 DB もその接頭辞で作る。終了時に接頭辞の DB を全部消す。
 * tool・sys 接続は SQLite のメモリに差し替え（tol_master_deploy_run と sys_* は最小の列で作る）、シートの API・ロック・
 * schedule キャッシュはモック、成果物の置き場は Storage::fake。
 */
class MasterBuildRunnerTest extends TestCase
{
    private const KEY = 2026112201;

    private string $appName;

    private string $originalMstDatabase;

    private int $rowNo = 2;

    private MasterDeployLock&MockInterface $lock;

    private MasterCacheInvalidator&MockInterface $invalidator;

    /** @var array<string, mixed>|\Throwable */
    private mixed $sheets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalMstDatabase = (string) config('database.connections.mst.database');
        $this->appName = 'nxt'.bin2hex(random_bytes(3));
        Config::set('app.name', $this->appName);
        Config::set('app.env', 'testing');
        Config::set('master_deploy.role', 'build');
        Config::set('master_deploy.fixed_key', null);
        Config::set('master_deploy.explicit_key', self::KEY);
        Config::set('master_deploy.reject_past_start_at', false);
        Config::set('master_deploy.start_at_past_grace', 60);
        Config::set('master_deploy.schedule_ttl', 60);

        // 後始末用の接続（基底 DB を消した後でも使えるよう、元のテスト用 DB を指す）
        Config::set('database.connections.build_test_admin', [...(array) config('database.connections.mst'), 'database' => $this->originalMstDatabase]);

        $this->createBaseDatabase();

        foreach (['tool', 'sys'] as $name) {
            Config::set("database.connections.{$name}", ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
            DB::purge($name);
        }
        $this->createToolAndSysTables();
        Storage::fake(MasterArtifactStore::DISK);
        Carbon::setTestNow('2026-11-23 12:00:00');

        $this->lock = Mockery::mock(MasterDeployLock::class);
        $this->lock->shouldReceive('acquire')->andReturn(true)->byDefault();
        $this->lock->shouldReceive('release')->byDefault();
        $this->invalidator = Mockery::mock(MasterCacheInvalidator::class);
        $this->invalidator->shouldReceive('forgetSchedule')->andReturn(['invalidated' => true, 'failed_key_list' => []])->byDefault();

        $this->sheets = $this->assembled([
            'mst_item' => [
                $this->entry('mst_item', 'a', '2026010101', ['name' => 'A1', 'price' => '100', 'rarity' => 'C']),
                $this->entry('mst_item', 'a', '2026060101', ['name' => 'A2', 'price' => '100', 'rarity' => 'R']),
                $this->entry('mst_item', 'b', '2026112201', ['name' => 'B', 'price' => '']),
                $this->entry('mst_item', 'c', '2026112301', ['name' => 'C（T より新しい行は入らない）', 'price' => '1', 'rarity' => 'C']),
            ],
            'mst_item__l10n' => [
                $this->entry('mst_item__l10n', 'a', '2026010101', ['name' => 'エー'], 'ja'),
                $this->entry('mst_item__l10n', 'a', '2026010101', ['name' => 'A'], 'en'),
            ],
            'mst_vip_login_bonus' => [
                $this->entry('mst_vip_login_bonus', 'v1', '2026010101', ['name' => 'V']),
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        try {
            $admin = DB::connection('build_test_admin');
            $rows = $admin->select('SELECT SCHEMA_NAME AS name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE ?', [$this->appName.'-%']);
            foreach ($rows as $row) {
                $admin->statement('DROP DATABASE IF EXISTS `'.str_replace('`', '``', (string) $row->name).'`');
            }
        } finally {
            DB::purge('build_test_admin');
            DB::purge('mst');
            DB::purge('mst_artifact_read');
            DB::purge('tool');
            DB::purge('sys');
            Config::set('database.connections.mst.database', $this->originalMstDatabase);
        }
        parent::tearDown();
    }

    // ---- 正常系 ----

    #[Test]
    public function buildがシートから版の_d_bと成果物とsysの登録を作る(): void
    {
        $result = $this->runner()->run();

        $this->assertSame('success', $result['status']);
        $this->assertFalse($result['dry_run']);
        $deploy = $result['deploy'];
        $this->assertSame('explicit', $deploy['mode']);
        $this->assertSame(self::KEY, $deploy['deploy_key']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $deploy['hash']);
        $this->assertSame(self::KEY.'_'.substr($deploy['hash'], 0, 16), $deploy['artifact_dir']);
        $this->assertSame($this->appName.'-testing-master-'.self::KEY.'-'.substr($deploy['hash'], 0, 16), $deploy['mst_database']);
        $this->assertTrue($deploy['is_new']);
        $this->assertFalse($deploy['database_reused']);
        $this->assertSame('2026-11-23 12:00:00', $deploy['start_at']);

        // 版の DB: 畳み後の行だけ（a は新しい deploy_key の行が勝つ。c は T より大きいので入らない）。行の無い表は空の表（R33）
        $db = $deploy['mst_database'];
        $this->assertSame(['a', 'b'], DB::connection('mst')->table("{$db}.mst_item")->orderBy('id')->pluck('id')->all());
        $a = DB::connection('mst')->table("{$db}.mst_item")->where('id', 'a')->first();
        $this->assertSame('A2', $a->name);
        $this->assertSame('R', $a->rarity);
        $this->assertSame('2026060101', (string) $a->deploy_key);
        $b = DB::connection('mst')->table("{$db}.mst_item")->where('id', 'b')->first();
        $this->assertNull($b->price, 'NULL 可の列の空文字は NULL');
        $this->assertSame('1970-01-01 00:00:00', $a->created_at);
        $this->assertSame(2, DB::connection('mst')->table("{$db}.mst_item__l10n")->count());
        $this->assertSame(0, DB::connection('mst')->table("{$db}.mst_empty")->count());
        // 基底 DB は変えない
        $this->assertSame(0, DB::connection('mst')->table('mst_item')->count());

        // 作業用 DB は捨てられ、版の DB だけが残る
        $this->assertSame([$db], $this->databaseList());

        // 成果物
        $disk = Storage::disk(MasterArtifactStore::DISK);
        $dir = $deploy['artifact_dir'];
        foreach (['manifest.json', 'schema.json', 'data/mst_item.ndjson', 'data/mst_empty.ndjson', 'data/mst_item__l10n.ndjson'] as $file) {
            $this->assertTrue($disk->exists("{$dir}/{$file}"), $file);
        }
        $this->assertSame(
            '{"id":"a","name":"A2","price":"100","rarity":"R","deploy_key":"2026060101"}'."\n"
            .'{"id":"b","name":"B","price":null,"rarity":null,"deploy_key":"2026112201"}'."\n",
            $disk->get("{$dir}/data/mst_item.ndjson"),
        );
        $this->assertSame('', $disk->get("{$dir}/data/mst_empty.ndjson"));
        $manifest = json_decode($disk->get("{$dir}/manifest.json"), true);
        $this->assertSame($deploy['hash'], $manifest['hash']);
        $this->assertSame(self::KEY, $manifest['deploy_key']);
        $this->assertSame('build', $manifest['source_kind']);
        $this->assertSame(
            hash('sha256', $disk->get("{$dir}/data/mst_item.ndjson")),
            collect($manifest['tables'])->firstWhere('name', 'mst_item')['data_sha256'],
        );
        $this->assertSame(64, strlen($manifest['schema_fingerprint']));
        // 配信 SQLite は版の DB から書かれる（グループごとに 1 ファイル。l10n は親と同じファイル）
        $groups = collect($manifest['group_list'])->pluck('file_name', 'group')->all();
        $this->assertSame(['mst_empty', 'mst_item', 'mst_vip_login_bonus'], array_keys($groups));
        $this->assertSame(
            hash('sha256', $disk->get("{$dir}/".$groups['mst_item'])),
            collect($manifest['group_list'])->firstWhere('group', 'mst_item')['sqlite_sha256'],
        );
        $this->assertSame('/masterdata/'.$dir.'/'.$groups['mst_item'], $result['artifact']['tables']['mst_item']['public_url']);
        // deploy_key 列を持たない表の行も row_digest に入る（畳みで採った行の元の deploy_key）
        $digestKeys = array_column($manifest['row_digest_list'], 'deploy_key');
        $this->assertSame([2026010101, 2026060101, 2026112201], $digestKeys);

        // sys
        $master = DB::connection('sys')->table('sys_deploy_master')->first();
        $this->assertSame($deploy['hash'], $master->hash);
        $this->assertSame($db, $master->mst_database);
        $this->assertSame($dir, $master->artifact_dir);
        $this->assertSame('build', $master->source_kind);
        $this->assertSame('master_build', $master->deployed_by);
        $this->assertNull($master->mst_dropped_at);
        $this->assertSame(3, DB::connection('sys')->table('sys_deploy_master_table')->count());
        $this->assertSame(1, DB::connection('sys')->table('sys_deploy_asset')->count());
        $sysDeploy = DB::connection('sys')->table('sys_deploy')->first();
        $this->assertSame(self::KEY, (int) $sysDeploy->deploy_key);
        $this->assertSame('2026-11-23 12:00:00', $sysDeploy->start_at);
        $this->assertSame(1, (int) $sysDeploy->is_active);

        // 実行記録
        $run = $this->run1();
        $this->assertSame('build', $run->kind);
        $this->assertSame('explicit', $run->mode);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('done', $run->phase);
        $this->assertSame($deploy['hash'], $run->master_hash);
        $this->assertSame($dir, $run->artifact_dir);
        $this->assertSame($db, $run->mst_database);
        $this->assertSame($master->schema_fingerprint, $run->schema_fingerprint);
        $this->assertSame((int) $master->id, (int) $run->sys_deploy_master_id);
        $summary = json_decode($run->summary, true);
        $this->assertSame([$db], $summary['created_database_list'], '作業用 DB は捨てたので、この run が作って残っているのは版の DB だけ');
        $this->assertStringContainsString('-master-work-', $summary['work_database']);
        $this->assertSame(2, $summary['loaded']['mst_item']);
        $this->assertTrue($summary['cache_invalidated']);
    }

    #[Test]
    public function 版の_d_bのスキーマ版は基底_d_bと一致する(): void
    {
        $result = $this->runner()->run();

        $databases = new MasterVersionDatabase;
        $this->assertSame(
            $databases->schemaFingerprint($this->appName.'-testing-master'),
            $databases->schemaFingerprint($result['deploy']['mst_database']),
        );
        $this->assertSame($databases->schemaFingerprint($this->appName.'-testing-master'), $result['artifact'] ? DB::connection('sys')->table('sys_deploy_master')->value('schema_fingerprint') : null);
    }

    #[Test]
    public function 同じ入力で同じhashになり二回目は版の_d_bと成果物を再利用して切り替え行だけ足す(): void
    {
        $first = $this->runner()->run();
        $second = $this->runner()->run();

        $this->assertSame($first['deploy']['hash'], $second['deploy']['hash']);
        $this->assertSame($first['deploy']['mst_database'], $second['deploy']['mst_database']);
        $this->assertFalse($second['deploy']['is_new']);
        $this->assertTrue($second['deploy']['database_reused']);
        $this->assertTrue($second['artifact']['reused']);
        $this->assertSame(1, DB::connection('sys')->table('sys_deploy_master')->count());
        $this->assertSame(2, DB::connection('sys')->table('sys_deploy')->count());
        $this->assertNotSame($first['deploy']['sys_deploy_id'], $second['deploy']['sys_deploy_id']);
        $this->assertSame([$first['deploy']['mst_database']], $this->databaseList());
        // 再利用した DB は、この run が作ったものではない
        $this->assertSame([], json_decode((string) DB::connection('tool')->table('tol_master_deploy_run')->orderByDesc('id')->value('summary'), true)['created_database_list']);
    }

    #[Test]
    public function 別の入力では別のhashと別の版の_d_bになる(): void
    {
        $first = $this->runner()->run();
        $this->sheets['tables']['mst_item']['entries'][] = $this->entry('mst_item', 'd', '2026112201', ['name' => 'D', 'price' => '5', 'rarity' => 'SR']);
        $second = $this->runner()->run();

        $this->assertNotSame($first['deploy']['hash'], $second['deploy']['hash']);
        $this->assertNotSame($first['deploy']['mst_database'], $second['deploy']['mst_database']);
        $this->assertTrue($second['deploy']['is_new']);
        $this->assertSame(2, DB::connection('sys')->table('sys_deploy_master')->count());
        $this->assertCount(2, $this->databaseList());
        // 他の sys_deploy を無効にしない
        $this->assertSame([1, 1], DB::connection('sys')->table('sys_deploy')->orderBy('id')->pluck('is_active')->map(fn ($v) => (int) $v)->all());
    }

    #[Test]
    public function 同じ内容ならシートの行の順序が違ってもhashは同じ(): void
    {
        $first = $this->runner()->run();
        $this->sheets['tables']['mst_item']['entries'] = array_reverse($this->sheets['tables']['mst_item']['entries']);
        $second = $this->runner()->run();

        $this->assertSame($first['deploy']['hash'], $second['deploy']['hash']);
    }

    #[Test]
    public function tより新しい行だけを変えてもhashは変わらない(): void
    {
        $first = $this->runner()->run();
        $this->sheets['tables']['mst_item']['entries'][3] = $this->entry('mst_item', 'c', '2026112301', ['name' => '別の未来の行', 'price' => '9', 'rarity' => 'R']);
        $second = $this->runner()->run();

        $this->assertSame($first['deploy']['hash'], $second['deploy']['hash']);
    }

    #[Test]
    public function 固定キーのbuildはmodeがfixedでdeploy_countが0(): void
    {
        Config::set('master_deploy.fixed_key', 9999999999);
        Config::set('master_deploy.explicit_key', null);

        $result = $this->runner()->run();

        $this->assertSame('fixed', $result['deploy']['mode']);
        $this->assertSame(9999999999, $result['deploy']['deploy_key']);
        $this->assertSame('fixed', $this->run1()->mode);
        $this->assertSame(0, (int) DB::connection('sys')->table('sys_deploy_master')->value('deploy_count'));
        // 9999999999 は予約帯。T 以下の行は全部入る
        $this->assertSame(3, DB::connection('mst')->table($result['deploy']['mst_database'].'.mst_item')->count());
    }

    // ---- 失敗時の途中の DB の孤児管理 ----

    #[Test]
    public function 投入の失敗ではload_failedで作業用_d_bを捨てる(): void
    {
        $this->sheets['tables']['mst_item']['entries'][] = $this->entry('mst_item', 'bad', '2026010101', ['name' => 'X', 'price' => 'abc', 'rarity' => 'C']);

        $error = $this->expectRunError(MasterDeployRunException::LOAD_FAILED);

        $this->assertStringContainsString('mst_item', $error->getMessage());
        $this->assertSame([], $this->databaseList());
        $run = $this->run1();
        $this->assertSame('failed', $run->status);
        $this->assertSame('work_db', $run->phase);
        $this->assertSame('load_failed', $run->error_code);
        $summary = json_decode($run->summary, true);
        $this->assertSame([], $summary['created_database_list'], '捨てたものは一覧から外れる');
        $this->assertSame(0, DB::connection('sys')->table('sys_deploy_master')->count());
        $this->assertSame([], Storage::disk(MasterArtifactStore::DISK)->allFiles());
    }

    #[Test]
    public function 成果物の配置に失敗したらこのrunが作った版の_d_bを捨てる(): void
    {
        $store = new class extends MasterArtifactStore
        {
            public function put(string $localDir, string $artifactDir): void
            {
                throw new RuntimeException('disk full');
            }
        };

        $this->expectRunError(MasterDeployRunException::STORE_FAILED, $this->runner(store: $store));

        $this->assertSame([], $this->databaseList(), '作業用 DB も版の DB も残らない');
        $run = $this->run1();
        $this->assertSame('failed', $run->status);
        $this->assertSame('store', $run->phase);
        $this->assertNotNull($run->mst_database, '作る直前に書いた名前が残る');
        $this->assertSame([], json_decode($run->summary, true)['created_database_list']);
        $this->assertSame(0, DB::connection('sys')->table('sys_deploy_master')->count());
    }

    #[Test]
    public function 採用した孤児の_d_bは後続の失敗でも消さない(): void
    {
        // 1 回目の build の DB を孤児（sys に行が無い DB）にする
        $first = $this->runner()->run();
        DB::connection('sys')->table('sys_deploy_master')->delete();
        DB::connection('sys')->table('sys_deploy')->delete();
        Storage::disk(MasterArtifactStore::DISK)->deleteDirectory($first['deploy']['artifact_dir']);

        $store = new class extends MasterArtifactStore
        {
            public function put(string $localDir, string $artifactDir): void
            {
                throw new RuntimeException('disk full');
            }
        };
        $this->expectRunError(MasterDeployRunException::STORE_FAILED, $this->runner(store: $store));

        $this->assertSame([$first['deploy']['mst_database']], $this->databaseList(), '採用した DB はこの run が作ったものではないので DROP しない');
        $this->assertSame([], json_decode((string) $this->run1()->summary, true)['created_database_list']);

        // 作り直さずに採用して、登録まで通る
        $third = $this->runner()->run();
        $this->assertSame($first['deploy']['mst_database'], $third['deploy']['mst_database']);
        $this->assertTrue($third['deploy']['database_reused']);
        $this->assertTrue($third['deploy']['is_new']);
    }

    #[Test]
    public function 中身が合わない孤児の_d_bは採用せずmst_database_collisionで止めて触らない(): void
    {
        $first = $this->runner()->run();
        $name = $first['deploy']['mst_database'];
        DB::connection('sys')->table('sys_deploy_master')->delete();
        DB::connection('sys')->table('sys_deploy')->delete();
        DB::connection('mst')->table("{$name}.mst_item")->where('id', 'a')->update(['name' => '改ざん']);

        $this->expectRunError(MasterDeployRunException::MST_DATABASE_COLLISION);

        $this->assertSame([$name], $this->databaseList());
        $this->assertSame('改ざん', DB::connection('mst')->table("{$name}.mst_item")->where('id', 'a')->value('name'));
    }

    #[Test]
    public function 使えない旧_d_bが同じ名前で残っているときはmst_database_collisionで止める(): void
    {
        $first = $this->runner()->run();
        $name = $first['deploy']['mst_database'];
        // sys には行があるが、DB の中身が成果物と合わない（往復検査の不一致）
        DB::connection('mst')->table("{$name}.mst_item")->where('id', 'b')->delete();

        $this->expectRunError(MasterDeployRunException::MST_DATABASE_COLLISION);

        $this->assertSame([$name], $this->databaseList(), 'この run が作った名前ではないので DROP しない');
        $this->assertSame(1, DB::connection('sys')->table('sys_deploy_master')->count());
    }

    #[Test]
    public function 掃除済みの版は同じ内容のbuildで版の_d_bを作り直してsysのmst_databaseを更新する(): void
    {
        $first = $this->runner()->run();
        $name = $first['deploy']['mst_database'];
        // 掃除（DB を DROP して mst_dropped_at を入れる）の再現
        DB::connection('mst')->statement('DROP DATABASE `'.$name.'`');
        DB::connection('sys')->table('sys_deploy_master')->update(['mst_dropped_at' => '2026-11-23 11:00:00']);

        $second = $this->runner()->run();

        $this->assertFalse($second['deploy']['is_new']);
        $this->assertFalse($second['deploy']['database_reused']);
        $this->assertSame($name, $second['deploy']['mst_database']);
        $this->assertSame([$name], $this->databaseList());
        $this->assertNull(DB::connection('sys')->table('sys_deploy_master')->value('mst_dropped_at'));
        $this->assertSame(1, DB::connection('sys')->table('sys_deploy_master')->count());
        $this->assertSame([$name], json_decode((string) $this->run1()->summary, true)['created_database_list']);
    }

    #[Test]
    public function 成果物のディレクトリ名が衝突し完全なhashが違うならartifact_dir_collisionで止める(): void
    {
        // 1 回目の build で成果物を作り、完全な hash だけ違う（先頭 16 文字は同じ）manifest に差し替える
        $first = $this->runner()->run();
        $dir = $first['deploy']['artifact_dir'];
        DB::connection('sys')->table('sys_deploy_master')->delete();
        DB::connection('sys')->table('sys_deploy')->delete();
        $disk = Storage::disk(MasterArtifactStore::DISK);
        $manifest = json_decode($disk->get("{$dir}/manifest.json"), true);
        $manifest['hash'] = substr($manifest['hash'], 0, 16).str_repeat('0', 48);
        $disk->put("{$dir}/manifest.json", json_encode($manifest));

        $this->expectRunError(MasterDeployRunException::ARTIFACT_DIR_COLLISION);

        $this->assertSame($manifest['hash'], json_decode($disk->get("{$dir}/manifest.json"), true)['hash'], '既存のディレクトリは触らない');
        $this->assertSame('store', $this->run1()->phase);
        $this->assertSame([$first['deploy']['mst_database']], $this->databaseList(), '孤児として採用した DB は消さない');
    }

    // ---- sys の COMMIT の成否が不明 ----

    #[Test]
    public function commi_t中のエラーでも読み直して登録済みなら成功にして警告を付ける(): void
    {
        $service = $this->flakyService('after');

        $result = $this->runner(deployService: $service)->run();

        $this->assertSame('success', $result['status']);
        $this->assertContains('register_confirmed_after_error', array_column($result['build']['warning_list'], 'code'));
        $this->assertSame('succeeded', $this->run1()->status);
        $this->assertCount(1, $this->databaseList(), '登録済みの版の DB は消さない');
    }

    #[Test]
    public function commi_t中のエラーで行が無ければregister_failedで閉じ版の_d_bは消さず孤児掃除に回す(): void
    {
        $service = $this->flakyService('before');

        $this->expectRunError(MasterDeployRunException::REGISTER_FAILED, $this->runner(deployService: $service));

        $this->assertCount(1, $this->databaseList(), 'その場では DROP しない（孤児掃除の猶予後に sys を再確認して DROP）');
        $run = $this->run1();
        $this->assertSame('failed', $run->status);
        $this->assertSame('register_failed', $run->error_code);
        $this->assertCount(1, json_decode($run->summary, true)['created_database_list']);
    }

    #[Test]
    public function commi_tより前の登録の失敗ではロールバックしこのrunが作った_d_bを捨てる(): void
    {
        $service = new class extends MasterDeployService
        {
            public function registerBuild(array $input, ?CarbonInterface $now = null): array
            {
                throw MasterDeployKeyException::registerFailed(new RuntimeException('boom'));
            }
        };

        $this->expectRunError(MasterDeployRunException::REGISTER_FAILED, $this->runner(deployService: $service));

        $this->assertSame([], $this->databaseList());
        $this->assertSame('register', $this->run1()->phase);
    }

    // ---- 検査（ロックの前・start_at・シート） ----

    #[Test]
    public function ロックが取れなければdeploy_lockedで実行記録も作らない(): void
    {
        $this->lock->shouldReceive('acquire')->andReturn(false);

        $this->expectRunError(MasterDeployRunException::DEPLOY_LOCKED);

        $this->assertSame(0, DB::connection('tool')->table('tol_master_deploy_run')->count());
        $this->assertSame([], $this->databaseList());
    }

    #[Test]
    public function 他のrunのrunningが残っていればロックを取れた時点でabortedに直す(): void
    {
        DB::connection('tool')->table('tol_master_deploy_run')->insert([
            'kind' => 'build', 'status' => 'running', 'phase' => 'work_db', 'app_env' => 'testing', 'started_at' => '2026-11-23 10:00:00',
        ]);

        $this->runner()->run();

        $this->assertSame('aborted', DB::connection('tool')->table('tol_master_deploy_run')->orderBy('id')->first()->status);
    }

    #[Test]
    public function import環境ではrole_forbiddenでロックも取らない(): void
    {
        Config::set('master_deploy.role', 'import');
        $this->lock->shouldNotReceive('acquire');

        $this->expectRunError(MasterDeployRunException::ROLE_FORBIDDEN);

        $this->assertSame(0, DB::connection('tool')->table('tol_master_deploy_run')->count());
    }

    #[Test]
    public function 本番ガード_本番で固定キーが設定されていればfixed_key_forbidden(): void
    {
        Config::set('app.env', 'production');
        Config::set('master_deploy.fixed_key', 9999999999);
        Config::set('master_deploy.explicit_key', null);
        $this->lock->shouldNotReceive('acquire');

        $this->expectRunError('fixed_key_forbidden');

        $this->assertSame(0, DB::connection('tool')->table('tol_master_deploy_run')->count());
    }

    #[Test]
    public function 固定キーが許可リストに無ければfixed_key_invalid(): void
    {
        Config::set('master_deploy.fixed_key', 7777777778);
        Config::set('master_deploy.explicit_key', null);

        $this->expectRunError('fixed_key_invalid');
    }

    #[Test]
    public function 固定キーも明示キーも無ければdeploy_key_not_configured(): void
    {
        Config::set('master_deploy.explicit_key', null);
        $this->expectRunError('deploy_key_not_configured');
    }

    #[Test]
    public function 固定キーと明示キーが両方あればdeploy_key_not_configured(): void
    {
        Config::set('master_deploy.fixed_key', 9999999999);
        $this->expectRunError('deploy_key_not_configured');
    }

    #[Test]
    public function 明示キーが予約帯ならreserved_key_forbidden(): void
    {
        Config::set('master_deploy.explicit_key', 7777777777);
        $this->expectRunError('reserved_key_forbidden');
    }

    #[Test]
    public function 明示キーが_yyyymmddn_nの形でなければdeploy_key_not_configured(): void
    {
        foreach ([2026023001, 2026112200, 2025123101, 12345] as $key) {
            Config::set('master_deploy.explicit_key', $key);
            try {
                $this->runner()->run();
                $this->fail("{$key} は拒否されるはず");
            } catch (MasterDeployRunException $e) {
                $this->assertSame('deploy_key_not_configured', $e->errorCode, (string) $key);
            }
        }
    }

    #[Test]
    public function ap_p_nam_eとenvの合計が28文字を超えればmst_database_name_too_long(): void
    {
        Config::set('app.name', str_repeat('n', 22));
        $this->lock->shouldNotReceive('acquire');

        $this->expectRunError(MasterDeployRunException::MST_DATABASE_NAME_TOO_LONG);
    }

    #[Test]
    public function mst接続のdatabaseが_ap_p_nam_e_ap_p_en_v_masterでなければbase_database_mismatch(): void
    {
        Config::set('database.connections.mst.database', $this->originalMstDatabase);
        DB::purge('mst');
        $this->lock->shouldNotReceive('acquire');

        $this->expectRunError(MasterDeployRunException::BASE_DATABASE_MISMATCH);
    }

    #[Test]
    public function 過去のstart_atは本番相当の設定なら許容幅を超えるとstart_at_pastで拒否し_d_bを作らない(): void
    {
        Config::set('master_deploy.reject_past_start_at', true);

        $this->expectRunError(MasterDeployRunException::START_AT_PAST, $this->runner(), ['start_at' => '2026-11-23 11:00:00']);

        $this->assertSame([], $this->databaseList());
        $run = $this->run1();
        $this->assertSame('failed', $run->status);
        $this->assertSame('lock', $run->phase, 'DB を作る前（読む前）に拒否する');
    }

    #[Test]
    public function 許容幅以内の過去のstart_atは現在に丸めて通す(): void
    {
        Config::set('master_deploy.reject_past_start_at', true);

        $result = $this->runner()->run(['start_at' => '2026-11-23 11:59:30']);

        $this->assertSame('2026-11-23 12:00:00', $result['deploy']['start_at']);
    }

    #[Test]
    public function 本番相当でなければ過去のstart_atもそのまま通す(): void
    {
        $result = $this->runner()->run(['start_at' => '2026-11-23 11:00:00']);

        $this->assertSame('2026-11-23 11:00:00', $result['deploy']['start_at']);
        $this->assertSame('2026-11-23 11:00:00', DB::connection('sys')->table('sys_deploy')->value('start_at'));
    }

    #[Test]
    public function 近いstart_atは警告し_新しい版より後の行があればshadowedを警告し_同じstart_atの二重登録は行を足さない(): void
    {
        $first = $this->runner()->run(['start_at' => '2026-11-23 12:01:00']);
        $this->assertContains('start_at_too_soon', array_column($first['build']['warning_list'], 'code'));

        $second = $this->runner()->run(['start_at' => '2026-11-23 12:01:00']);
        $this->assertContains('already_registered', array_column($second['build']['warning_list'], 'code'));
        $this->assertFalse($second['deploy']['is_new']);
        $this->assertSame($first['deploy']['sys_deploy_id'], $second['deploy']['sys_deploy_id']);
        $this->assertSame(1, DB::connection('sys')->table('sys_deploy')->count());

        // 新しい版（別の内容）を、より前の start_at で登録すると shadowed
        $this->sheets['tables']['mst_item']['entries'][] = $this->entry('mst_item', 'e', '2026112201', ['name' => 'E', 'price' => '1', 'rarity' => 'C']);
        $third = $this->runner()->run(['start_at' => '2026-11-23 12:00:30']);
        $this->assertContains('start_at_shadowed', array_column($third['build']['warning_list'], 'code'));
    }

    #[Test]
    public function シートの検証に失敗したらsheet_invalidで版の_d_bも成果物もsysも作らない(): void
    {
        $this->sheets['tables']['mst_item']['entries'][] = $this->entry('mst_item', 'x', '123456789', ['name' => 'X', 'price' => '1', 'rarity' => 'C']);

        $error = $this->expectRunError(MasterDeployRunException::SHEET_INVALID);

        $this->assertSame(1, $error->extra['error_total']);
        $this->assertSame([], $this->databaseList());
        $this->assertSame([], Storage::disk(MasterArtifactStore::DISK)->allFiles());
        $this->assertSame(0, DB::connection('sys')->table('sys_deploy_master')->count());
        $run = $this->run1();
        $this->assertSame('failed', $run->status);
        $this->assertSame('validate', $run->phase);
    }

    #[Test]
    public function シートを読めなければsheet_read_failedで何も作らない(): void
    {
        $this->sheets = new RuntimeException('google down');

        $this->expectRunError(MasterDeployRunException::SHEET_READ_FAILED);

        $this->assertSame([], $this->databaseList());
        $this->assertSame('read', $this->run1()->phase);
    }

    #[Test]
    public function dry_runはロックも実行記録も_d_bもストレージもsysも使わない(): void
    {
        $this->lock->shouldNotReceive('acquire');

        $result = $this->runner()->run(['dry_run' => true]);

        $this->assertTrue($result['dry_run']);
        $this->assertNull($result['run_id']);
        $this->assertNull($result['deploy']['hash']);
        $this->assertSame(self::KEY, $result['deploy']['deploy_key']);
        $this->assertNull($result['artifact']);
        $this->assertNotNull($result['inventory']);
        $item = collect($result['build']['table_list'])->firstWhere('table', 'mst_item');
        $this->assertSame(['input_rows' => 4, 'adopted_rows' => 2, 'empty' => false], [
            'input_rows' => $item['input_rows'], 'adopted_rows' => $item['adopted_rows'], 'empty' => $item['empty'],
        ]);
        $this->assertSame(0, DB::connection('tool')->table('tol_master_deploy_run')->count());
        $this->assertSame([], $this->databaseList());
        $this->assertSame([], Storage::disk(MasterArtifactStore::DISK)->allFiles());
        $this->assertSame(0, DB::connection('sys')->table('sys_deploy_master')->count());
    }

    #[Test]
    public function schedule_cacheの破棄に失敗しても登録は成功のまま警告を返す(): void
    {
        $this->invalidator->shouldReceive('forgetSchedule')->andReturn(['invalidated' => false, 'failed_key_list' => ['sys:sys_deploy:schedule']]);

        $result = $this->runner()->run();

        $this->assertFalse($result['cache']['invalidated']);
        $this->assertContains('schedule_cache_not_invalidated', array_column($result['build']['warning_list'], 'code'));
        $this->assertSame('succeeded', $this->run1()->status);
    }

    // ---- 補助 ----

    private function expectRunError(string $code, ?MasterBuildRunner $runner = null, array $options = []): MasterDeployRunException
    {
        try {
            ($runner ?? $this->runner())->run($options);
        } catch (MasterDeployRunException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage().' '.json_encode($e->errorList, JSON_UNESCAPED_UNICODE));

            return $e;
        }
        $this->fail("{$code} が投げられるはず");
    }

    private function runner(?MasterArtifactStore $store = null, ?MasterDeployService $deployService = null): MasterBuildRunner
    {
        $sheets = $this->sheets;
        $factory = function () use ($sheets) {
            $service = Mockery::mock(GoogleSpreadsheetService::class);
            $service->shouldReceive('isConfigured')->andReturn(true);
            if ($sheets instanceof \Throwable) {
                $service->shouldReceive('readAllMasterTabs')->andThrow($sheets);
            } else {
                $service->shouldReceive('readAllMasterTabs')->andReturn($sheets);
            }

            return $service;
        };

        return new MasterBuildRunner(
            lock: $this->lock,
            sheetServiceFactory: $factory,
            store: $store,
            deployService: $deployService,
            invalidator: $this->invalidator,
        );
    }

    /**
     * COMMIT の成否が不明になる sys への登録を再現する（after: COMMIT は済んでから例外 / before: ロールバックしてから例外）
     */
    private function flakyService(string $mode): MasterDeployService
    {
        return new class($mode) extends MasterDeployService
        {
            public function __construct(private readonly string $mode) {}

            protected function commitSys(ConnectionInterface $sys): void
            {
                if ($this->mode === 'after') {
                    $sys->commit();
                } else {
                    $sys->rollBack();
                }

                throw new RuntimeException('connection lost');
            }
        };
    }

    private function run1(): object
    {
        $run = DB::connection('tool')->table('tol_master_deploy_run')->orderByDesc('id')->first();
        $this->assertNotNull($run);

        return $run;
    }

    /**
     * このテストの接頭辞の DB（版の DB・作業用 DB。基底 DB を除く）
     *
     * @return list<string>
     */
    private function databaseList(): array
    {
        $rows = DB::connection('build_test_admin')->select('SELECT SCHEMA_NAME AS name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE ? ORDER BY SCHEMA_NAME', [$this->appName.'-testing-master-%']);

        return array_map(static fn ($row): string => (string) $row->name, $rows);
    }

    private function createBaseDatabase(): void
    {
        $base = $this->appName.'-testing-master';
        $admin = DB::connection('build_test_admin');
        $admin->statement("CREATE DATABASE `{$base}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $timestamps = 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP';
        $admin->statement("CREATE TABLE `{$base}`.mst_item (id VARCHAR(255) NOT NULL PRIMARY KEY, name VARCHAR(255) NOT NULL, price INT UNSIGNED NULL, rarity ENUM('C','R','SR') NULL, deploy_key BIGINT NOT NULL DEFAULT 2026010101, {$timestamps}, KEY idx_item_deploy_key (deploy_key))");
        $admin->statement("CREATE TABLE `{$base}`.mst_item__l10n (mst_item_id VARCHAR(255) NOT NULL, language VARCHAR(10) NOT NULL, name VARCHAR(255) NOT NULL, deploy_key BIGINT NOT NULL DEFAULT 2026010101, {$timestamps}, PRIMARY KEY (mst_item_id, language))");
        $admin->statement("CREATE TABLE `{$base}`.mst_empty (id VARCHAR(64) NOT NULL PRIMARY KEY, deploy_key BIGINT NOT NULL DEFAULT 2026010101, {$timestamps})");
        // deploy_key 列を持たない表（mst_vip_login_bonus。db.md 4.9）
        $admin->statement("CREATE TABLE `{$base}`.mst_vip_login_bonus (id VARCHAR(255) NOT NULL PRIMARY KEY, name VARCHAR(255) NOT NULL, {$timestamps})");

        Config::set('database.connections.mst.database', $base);
        DB::purge('mst');
        DB::purge('mst_artifact_read');
    }

    private function createToolAndSysTables(): void
    {
        Schema::connection('tool')->create('tol_master_deploy_run', function ($t) {
            $t->id();
            $t->string('kind');
            $t->string('mode')->nullable();
            $t->bigInteger('deploy_key')->nullable();
            $t->string('status');
            $t->string('phase');
            $t->unsignedBigInteger('adm_account_id')->nullable();
            $t->string('app_env', 32);
            $t->string('master_hash', 64)->nullable();
            $t->string('artifact_dir', 128)->nullable();
            $t->string('mst_database', 64)->nullable();
            $t->string('schema_fingerprint', 64)->nullable();
            $t->dateTime('start_at')->nullable();
            $t->unsignedBigInteger('sys_deploy_master_id')->nullable();
            $t->unsignedBigInteger('sys_deploy_id')->nullable();
            $t->boolean('is_new')->nullable();
            $t->text('summary')->nullable();
            $t->string('error_code', 50)->nullable();
            $t->text('error_message')->nullable();
            $t->dateTime('started_at');
            $t->dateTime('finished_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
        Schema::connection('sys')->create('sys_deploy_master', function ($t) {
            $t->id();
            $t->bigInteger('deploy_key');
            $t->string('hash', 64);
            $t->date('deploy_date');
            $t->unsignedBigInteger('deploy_count');
            $t->string('status');
            $t->string('deployed_by')->nullable();
            $t->dateTime('deployed_at')->nullable();
            $t->text('description')->nullable();
            $t->string('mst_database', 64);
            $t->string('artifact_dir', 128);
            $t->string('schema_fingerprint', 64);
            $t->string('source_kind', 16);
            $t->dateTime('mst_dropped_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique(['deploy_key', 'hash']);
            $t->unique('mst_database');
        });
        Schema::connection('sys')->create('sys_deploy_asset', function ($t) {
            $t->id();
            $t->bigInteger('deploy_key');
            $t->string('hash', 64);
            $t->date('deploy_date');
            $t->unsignedBigInteger('deploy_count');
            $t->string('status');
            $t->string('deployed_by')->nullable();
            $t->dateTime('deployed_at')->nullable();
            $t->text('description')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique(['deploy_key', 'hash']);
        });
        Schema::connection('sys')->create('sys_deploy', function ($t) {
            $t->id();
            $t->bigInteger('deploy_key');
            $t->dateTime('start_at');
            $t->unsignedBigInteger('sys_deploy_master_id');
            $t->unsignedBigInteger('sys_deploy_asset_id');
            $t->boolean('is_active')->default(true);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
        Schema::connection('sys')->create('sys_deploy_master_table', function ($t) {
            $t->unsignedBigInteger('sys_deploy_master_id');
            $t->string('table_name', 128);
            $t->string('hash', 64);
            $t->unsignedBigInteger('file_size');
            $t->string('file_name');
            $t->string('public_url', 512);
            $t->primary(['sys_deploy_master_id', 'table_name']);
        });
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $tables  表名 => 行
     * @return array<string, mixed>
     */
    private function assembled(array $tables): array
    {
        $result = [];
        foreach ($tables as $name => $entries) {
            $result[$name] = ['entries' => $entries, 'has_deploy_key_column' => true, 'ignored_inactive_rows' => 0];
        }

        return ['tables' => $result, 'ignored_tab_list' => [], 'ignored_column_list' => [], 'error_list' => []];
    }

    /**
     * @param  array<string, string>  $columns
     * @return array<string, mixed>
     */
    private function entry(string $table, string $id, string $deployKey, array $columns, ?string $language = null): array
    {
        return ['row_no' => ++$this->rowNo, 'sheet' => "s/{$table}", 'id' => $id, 'language' => $language, 'deploy_key' => $deployKey, 'deleted' => '', 'columns' => $columns];
    }
}

<?php

namespace Tests\Feature;

use App\Services\GoogleSpreadsheetService;
use App\Services\MasterArtifactStore;
use App\Services\MasterBuildRunner;
use App\Services\MasterCacheInvalidator;
use App\Services\MasterDeployLock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * 段階 4 の操作（import・finalize・rollback・cancel-schedule・cleanup・deactivate・resolve-unknown）のテストの土台
 *
 * mst は実物の MySQL（db-master）で動かす。名前が衝突しないよう、テストごとに使い捨ての `APP_NAME`（nxt + 乱数）で基底 DB
 * `{app}-{env}-master` を作り、版の DB・作業用 DB もその接頭辞で作る。終了時に接頭辞の DB を全部消す。
 * tool・sys 接続は SQLite のメモリに差し替え、ロック・schedule キャッシュはモック、成果物の置き場は Storage::fake。
 * 版の成果物は、実物の MasterBuildRunner（シートはモック）で作る。
 */
abstract class MasterVersionedTestCase extends TestCase
{
    protected string $appName;

    protected string $originalMstDatabase;

    protected int $rowNo = 2;

    /** @var array<string, mixed> */
    protected array $lastSheets = [];

    protected MasterDeployLock&MockInterface $lock;

    protected MasterCacheInvalidator&MockInterface $invalidator;

    protected function envName(): string
    {
        return 'testing';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalMstDatabase = (string) config('database.connections.mst.database');
        $this->appName = 'nxt'.bin2hex(random_bytes(3));
        Config::set('app.name', $this->appName);
        Config::set('app.env', $this->envName());
        Config::set('master_deploy.role', 'build');
        Config::set('master_deploy.fixed_key', null);
        Config::set('master_deploy.explicit_key', 2026112201);
        Config::set('master_deploy.reject_past_start_at', false);
        Config::set('master_deploy.enforce_confirmed_rows', false);
        Config::set('master_deploy.require_finalized', false);
        Config::set('master_deploy.start_at_past_grace', 60);
        Config::set('master_deploy.schedule_ttl', 60);
        Config::set('master_deploy.keep_version_count', 3);
        Config::set('master_deploy.orphan_grace_hours', 24);

        Config::set('database.connections.build_test_admin', [...(array) config('database.connections.mst'), 'database' => $this->originalMstDatabase]);
        $this->createBaseDatabase();

        foreach (['tool', 'sys'] as $name) {
            Config::set("database.connections.{$name}", ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
            DB::purge($name);
        }
        $this->createToolAndSysTables();
        Storage::fake(MasterArtifactStore::DISK);
        Storage::fake('master_artifact_upstream');
        Carbon::setTestNow('2026-11-23 12:00:00');

        $this->lock = Mockery::mock(MasterDeployLock::class);
        $this->lock->shouldReceive('acquire')->andReturn(true)->byDefault();
        $this->lock->shouldReceive('release')->byDefault();
        $this->invalidator = Mockery::mock(MasterCacheInvalidator::class);
        $this->invalidator->shouldReceive('forgetSchedule')->andReturn(['invalidated' => true, 'failed_key_list' => []])->byDefault();
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

    // ---- 版を作る（実物の build） ----

    /**
     * build で版を 1 つ作る（T = $key。$variant が違えば内容・hash が違う）
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $extraItems  mst_item に足す行（id・deploy_key・name）
     * @param  bool  $fixed  true なら $key を固定キー（予約キー）として build する
     * @return array<string, mixed> build の応答の deploy
     */
    protected function buildVersion(int $key, string $variant = 'a', ?string $startAt = null, array $extraItems = [], bool $fixed = false): array
    {
        Config::set('master_deploy.role', 'build');
        Config::set('master_deploy.fixed_key', $fixed ? $key : null);
        Config::set('master_deploy.explicit_key', $fixed ? null : $key);
        $items = [
            $this->entry('mst_item', 'a', '2026010101', ['name' => 'A-'.$variant, 'price' => '100', 'rarity' => 'C']),
            $this->entry('mst_item', 'b', '2026010101', ['name' => 'B', 'price' => '']),
        ];
        foreach ($extraItems as [$id, $deployKey, $name]) {
            $items[] = $this->entry('mst_item', $id, $deployKey, ['name' => $name, 'price' => '1', 'rarity' => 'R']);
        }
        $sheets = $this->assembled([
            'mst_item' => $items,
            'mst_item__l10n' => [
                $this->entry('mst_item__l10n', 'a', '2026010101', ['name' => 'エー'], 'ja'),
            ],
        ]);
        $this->lastSheets = $sheets;
        $runner = new MasterBuildRunner(lock: $this->lock, sheetServiceFactory: $this->lastSheetFactory(), invalidator: $this->invalidator);

        return $runner->run($startAt === null ? [] : ['start_at' => $startAt])['deploy'];
    }

    /**
     * 直近の buildVersion が読んだシートを返すシートサービスのファクトリ（追従検出に使う）
     *
     * @return \Closure(): GoogleSpreadsheetService
     */
    protected function lastSheetFactory(): \Closure
    {
        $sheets = $this->lastSheets;

        return function () use ($sheets) {
            $service = Mockery::mock(GoogleSpreadsheetService::class);
            $service->shouldReceive('isConfigured')->andReturn(true);
            $service->shouldReceive('readAllMasterTabs')->andReturn($sheets);

            return $service;
        };
    }

    /**
     * 自環境の成果物を上流の置き場へ写す（import の元）
     */
    protected function publishUpstream(string $artifactDir): void
    {
        $own = Storage::disk(MasterArtifactStore::DISK);
        $upstream = Storage::disk('master_artifact_upstream');
        foreach ($own->allFiles($artifactDir) as $path) {
            $upstream->put($path, (string) $own->get($path));
        }
    }

    /**
     * 別の環境（import 側）になったことにする: 版の DB・sys・実行記録・自環境の成果物を空にし、role を import にする
     */
    protected function becomeImportEnvironment(): void
    {
        $admin = DB::connection('build_test_admin');
        foreach ($admin->select('SELECT SCHEMA_NAME AS name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE ?', [$this->appName.'-'.$this->envName().'-master-%']) as $row) {
            $admin->statement('DROP DATABASE IF EXISTS `'.str_replace('`', '``', (string) $row->name).'`');
        }
        foreach (['sys_deploy', 'sys_deploy_master', 'sys_deploy_asset', 'sys_deploy_master_table'] as $table) {
            DB::connection('sys')->table($table)->delete();
        }
        DB::connection('tool')->table('tol_master_deploy_run')->delete();
        foreach (Storage::disk(MasterArtifactStore::DISK)->directories() as $directory) {
            Storage::disk(MasterArtifactStore::DISK)->deleteDirectory($directory);
        }
        Config::set('master_deploy.role', 'import');
    }

    // ---- 補助 ----

    /**
     * @return list<string>
     */
    protected function databaseList(): array
    {
        $rows = DB::connection('build_test_admin')->select('SELECT SCHEMA_NAME AS name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE ? ORDER BY SCHEMA_NAME', [$this->appName.'-'.$this->envName().'-master-%']);

        return array_map(static fn ($row): string => (string) $row->name, $rows);
    }

    protected function databaseExists(string $name): bool
    {
        return DB::connection('build_test_admin')->selectOne('SELECT 1 AS f FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$name]) !== null;
    }

    protected function dropDatabase(string $name): void
    {
        DB::connection('build_test_admin')->statement('DROP DATABASE IF EXISTS `'.str_replace('`', '``', $name).'`');
    }

    protected function lastRun(): object
    {
        $run = DB::connection('tool')->table('tol_master_deploy_run')->orderByDesc('id')->first();
        $this->assertNotNull($run);

        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    protected function summaryOf(object $run): array
    {
        return json_decode((string) $run->summary, true) ?? [];
    }

    protected function baseDatabaseName(): string
    {
        return $this->appName.'-'.$this->envName().'-master';
    }

    protected function createBaseDatabase(): void
    {
        $base = $this->baseDatabaseName();
        $admin = DB::connection('build_test_admin');
        $admin->statement("CREATE DATABASE `{$base}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $timestamps = 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP';
        $admin->statement("CREATE TABLE `{$base}`.mst_item (id VARCHAR(255) NOT NULL PRIMARY KEY, name VARCHAR(255) NOT NULL, price INT UNSIGNED NULL, rarity ENUM('C','R','SR') NULL, deploy_key BIGINT NOT NULL DEFAULT 2026010101, {$timestamps}, KEY idx_item_deploy_key (deploy_key))");
        $admin->statement("CREATE TABLE `{$base}`.mst_item__l10n (mst_item_id VARCHAR(255) NOT NULL, language VARCHAR(10) NOT NULL, name VARCHAR(255) NOT NULL, deploy_key BIGINT NOT NULL DEFAULT 2026010101, {$timestamps}, PRIMARY KEY (mst_item_id, language))");
        $admin->statement("CREATE TABLE `{$base}`.mst_empty (id VARCHAR(64) NOT NULL PRIMARY KEY, deploy_key BIGINT NOT NULL DEFAULT 2026010101, {$timestamps})");
        $admin->statement("CREATE TABLE `{$base}`.mst_vip_login_bonus (id VARCHAR(255) NOT NULL PRIMARY KEY, name VARCHAR(255) NOT NULL, {$timestamps})");

        Config::set('database.connections.mst.database', $base);
        DB::purge('mst');
        DB::purge('mst_artifact_read');
    }

    protected function createToolAndSysTables(): void
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
     * @param  array<string, array<int, array<string, mixed>>>  $tables
     * @return array<string, mixed>
     */
    protected function assembled(array $tables): array
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
    protected function entry(string $table, string $id, string $deployKey, array $columns, ?string $language = null): array
    {
        return ['row_no' => ++$this->rowNo, 'sheet' => "s/{$table}", 'id' => $id, 'language' => $language, 'deploy_key' => $deployKey, 'deleted' => '', 'columns' => $columns];
    }
}

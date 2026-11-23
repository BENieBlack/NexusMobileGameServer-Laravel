<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;

trait RefreshMultipleDatabases
{
    /**
     * Define hooks to migrate the database before and after each test.
     * This method is automatically called by Laravel's TestCase::setUpTraits()
     */
    public function setUpRefreshMultipleDatabases(): void
    {
        $this->refreshDatabase();
    }

    /**
     * Define hooks to migrate the database before and after each test.
     */
    public function refreshDatabase(): void
    {
        $this->beforeRefreshingDatabase();

        $this->refreshTestDatabase();

        $this->afterRefreshingDatabase();
    }

    /**
     * Perform any work that should take place before the database has started refreshing.
     */
    protected function beforeRefreshingDatabase(): void
    {
        // ...
    }

    /**
     * Perform any work that should take place once the database has finished refreshing.
     */
    protected function afterRefreshingDatabase(): void
    {
        // ...
    }

    /**
     * Define a set of database connections to migrate.
     *
     * @return array<string, null>
     */
    protected function connectionsToMigrate(): array
    {
        $connections = [
            'sys' => null,
            'mst' => null,
        ];

        // Add dynamic sharded connections
        $shardCount = (int) env('DB_SHARD_COUNT', 2);

        for ($i = 1; $i <= $shardCount; $i++) {
            $connections["trx{$i}"] = null;
        }

        return $connections;
    }

    /**
     * Refresh the in-memory database.
     */
    protected function refreshInMemoryDatabase(): void
    {
        $this->artisan('migrate', $this->migrateUsing());

        // Migrate additional database connections
        foreach ($this->connectionsToMigrate() as $connection => $paths) {
            $this->artisan('migrate', [
                '--database' => $connection,
            ]);
        }

        $this->app[Kernel::class]->setArtisan(null);
    }

    /**
     * Tracks whether migrations already ran in this PHP process.
     */
    protected static bool $migrationsRunForProcess = false;

    /**
     * Refresh a conventional test database.
     */
    protected function refreshTestDatabase(): void
    {
        $this->ensureDatabasesMigrated();

        $this->beginDatabaseTransaction();
    }

    /**
     * Make sure every test database is migrated and seeded, at most once per process.
     *
     * Feature tests that cannot be wrapped in a transaction (because the code under
     * test manages its own transactions) call this directly instead of
     * refreshTestDatabase(), and clean up their own rows in tearDown().
     */
    protected function ensureDatabasesMigrated(): void
    {
        if (! static::$migrationsRunForProcess) {
            // Use absolute path to ensure same location across all test instances
            $baseDir = base_path('storage/framework/testing');
            $flagFile = $baseDir.'/.migrated';
            $lockFile = $baseDir.'/.migrate.lock';

            // Ensure directory exists
            if (! is_dir($baseDir)) {
                mkdir($baseDir, 0755, true);
            }

            // Acquire exclusive lock
            $lock = fopen($lockFile, 'c+');

            if (flock($lock, LOCK_EX)) {
                try {
                    // The fingerprint changes whenever a migration file is added or edited,
                    // so schema changes are picked up instead of being masked by a stale flag.
                    $fingerprint = $this->migrationFingerprint();

                    clearstatcache(true, $flagFile);
                    $upToDate = file_exists($flagFile)
                        && file_get_contents($flagFile) === $fingerprint;

                    if (! $upToDate) {
                        $this->runMigrations();

                        file_put_contents($flagFile, $fingerprint);
                    }
                } finally {
                    // Release lock
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
            }

            // Seed separately from the migration guard: some feature tests run without a
            // transaction and delete sys_deploy rows in tearDown, so a process that skips
            // migrations still needs the baseline data restored.
            $this->seedSystemData();

            static::$migrationsRunForProcess = true;
        }

        $this->restoreBaselineDeploy();
    }

    /**
     * 基底 DB を指す「いまの版」が無ければ、基本シードの版を入れ直す
     *
     * HTTP のリクエストは頭で「いまの版」を決めて mst を切り替え、版が無いと 299 になる（db.md 8.1）。
     * トランザクションで包めないテストが tearDown で sys_deploy を空にすると、同じプロセスの後続のテストが
     * 全部 299 になるので、毎回ここで確かめる（SysDeploySeeder は truncate なので、トランザクションの外＝テストの開始前に流す）。
     */
    protected function restoreBaselineDeploy(): void
    {
        $sys = DB::connection('sys');

        // 基底 DB を指す版が有効（is_active・掃除済みでない）かで判定する。別の版だけ残っている状態も復元の対象
        $hasBaseline = $sys->table('sys_deploy as d')
            ->join('sys_deploy_master as m', 'm.id', '=', 'd.sys_deploy_master_id')
            ->where('d.is_active', true)
            ->whereNull('m.mst_dropped_at')
            ->where('m.mst_database', (string) config('database.connections.mst.database'))
            ->exists();

        if (! $hasBaseline) {
            $this->seedSystemData();
        }
    }

    /**
     * Seed the baseline sys data every test relies on (deploy versions, sharding).
     */
    protected function seedSystemData(): void
    {
        $this->artisan('db:seed', [
            '--database' => 'sys',
            '--class' => 'Database\\Seeders\\SysDeploySeeder',
            '--force' => true,
        ]);

        $this->app[Kernel::class]->setArtisan(null);
    }

    /**
     * Run every migration group against the connections it belongs to.
     *
     * sys/mst migrations pin their target with Schema::connection(), but trx/log
     * migrations use the default connection. They must therefore be run once per
     * shard with --database, and with --path limited to their own directory —
     * otherwise every trx/log table lands in whichever database happens to be
     * the default for the run.
     *
     * Uses migrate:fresh, not migrate. This project edits existing migration
     * files in place until release, and `migrate` would consider an edited file
     * already applied and skip it — leaving the test databases on the old schema
     * while the fingerprint says they are current. Rebuilding from scratch is the
     * only way an edit reaches the schema. This runs only when the fingerprint
     * changed, so the common case pays nothing.
     *
     * Each database holds exactly one migration group, so dropping all of its
     * tables never takes out another group's schema.
     */
    protected function runMigrations(): void
    {
        foreach ($this->migrationTargets() as [$connection, $paths]) {
            $this->artisan('migrate:fresh', [
                '--database' => $connection,
                '--path' => $paths,
                '--force' => true,
            ]);
        }

        $this->app[Kernel::class]->setArtisan(null);
    }

    /**
     * Build the [connection, migration paths] pairs to run, in dependency order.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    protected function migrationTargets(): array
    {
        $targets = [
            ['sys', $this->migrationPaths('sys')],
            ['mst', $this->migrationPaths('mst')],
        ];

        $trxPaths = $this->migrationPaths('trx');
        $logPaths = $this->migrationPaths('log');
        $shardCount = (int) env('DB_SHARD_COUNT', 2);

        // trx と log は同じシャードDBに同居している。migrate:fresh はDB内の全テーブルを
        // 落とすため、別々に流すと後の方が先に作ったテーブルを消す。1回にまとめて流す
        for ($i = 1; $i <= $shardCount; $i++) {
            $targets[] = ["trx{$i}", array_merge($trxPaths, $logPaths)];
        }

        return $targets;
    }

    /**
     * Collect every migration directory for a database group, relative to base_path().
     *
     * Discovered by globbing so a new package does not need to be registered here.
     *
     * @return list<string>
     */
    protected function migrationPaths(string $group): array
    {
        $absolute = array_merge(
            glob(base_path("database/migrations/{$group}"), GLOB_ONLYDIR) ?: [],
            glob(base_path("../packages/*/database/migrations/{$group}"), GLOB_ONLYDIR) ?: [],
        );

        $basePath = base_path().'/';

        return array_values(array_map(
            fn (string $path): string => str_starts_with($path, $basePath)
                ? substr($path, strlen($basePath))
                : $path,
            $absolute
        ));
    }

    /**
     * 版の切り替えのテスト用の 2 つ目の mst DB の名前
     *
     * docker-compose.yml の db-master の DB_NAMES と同じ固定の名前（版の DB の命名規則
     * `{APP_NAME}-{env}-master-{deploy_key}-{HASH 16 文字}` に合わせた例。設計: docs/design/master-distribution/db.md 8.6）。
     */
    protected function altMstDatabaseName(): string
    {
        return config('app.name').'-testing-master-2026010101-0000000000000000';
    }

    /**
     * 2 つ目の mst DB を指す接続 mst_alt を登録し、スキーマを最新にして返す
     *
     * mst_alt は mst 接続の設定の database 違い。全テストのマイグレーション（runMigrations）には入れず、
     * 使うテストだけがここで流す（DB が無い既存の開発者環境で、他のテストを巻き込んで落とさないため）。
     * スキーマはマイグレーションの指紋が変わったときと、表が無いときだけ作り直す。
     * DB が無いときは、作り方を添えて失敗させる。
     */
    protected function ensureAltMstMigrated(): string
    {
        $database = $this->altMstDatabaseName();

        config(['database.connections.mst_alt' => array_merge(
            (array) config('database.connections.mst'),
            ['database' => $database],
        )]);
        DB::purge('mst_alt');

        try {
            DB::connection('mst_alt')->getPdo();
        } catch (\Throwable $e) {
            $this->fail(
                "2 つ目の mst DB（{$database}）がありません。docker compose down -v で DB を作り直すか、"
                .'手で CREATE DATABASE してください（.claude/development.md の「テスト用の 2 つ目の mst DB」）。'
                .$e->getMessage()
            );
        }

        $baseDir = base_path('storage/framework/testing');
        if (! is_dir($baseDir)) {
            mkdir($baseDir, 0755, true);
        }
        $flagFile = $baseDir.'/.migrated-alt';
        $fingerprint = $this->migrationFingerprint();
        clearstatcache(true, $flagFile);

        $upToDate = file_exists($flagFile)
            && file_get_contents($flagFile) === $fingerprint
            && DB::connection('mst_alt')->getSchemaBuilder()->hasTable('mst_config');

        if (! $upToDate) {
            $this->artisan('migrate:fresh', [
                '--database' => 'mst_alt',
                '--path' => $this->migrationPaths('mst'),
                '--force' => true,
            ]);
            $this->app[Kernel::class]->setArtisan(null);
            file_put_contents($flagFile, $fingerprint);
        }

        return $database;
    }

    /**
     * Hash the migration files so an added or edited migration invalidates the flag file.
     */
    protected function migrationFingerprint(): string
    {
        $entries = [];

        foreach (['sys', 'mst', 'trx', 'log'] as $group) {
            foreach ($this->migrationPaths($group) as $path) {
                foreach (glob(base_path($path).'/*.php') ?: [] as $file) {
                    $entries[] = $file.':'.filemtime($file).':'.filesize($file);
                }
            }
        }

        sort($entries);

        return md5(implode('|', $entries).'|shards='.env('DB_SHARD_COUNT', 2));
    }

    /**
     * Begin a database transaction on all connections.
     */
    public function beginDatabaseTransaction(): void
    {
        $database = $this->app->make('db');
        $connectionNames = array_keys($this->connectionsToMigrate());

        // テストを包むトランザクションを数えない管理クラスに差し替える。
        // 差し替えないと、コード側のトランザクションをコミットしても外側が残るため、
        // afterCommit のジョブ（通知など）がいつまでも動かない（RefreshDatabase と同じ処理）
        $transactionsManager = new DatabaseTransactionsManager($connectionNames);
        $this->app->instance('db.transactions', $transactionsManager);

        // Start transaction for all connections
        foreach ($connectionNames as $connectionName) {
            $connection = $database->connection($connectionName);
            $connection->setTransactionManager($transactionsManager);
            $dispatcher = $connection->getEventDispatcher();
            $connection->unsetEventDispatcher();
            $connection->beginTransaction();
            $connection->setEventDispatcher($dispatcher);
        }

        $this->beforeApplicationDestroyed(function () use ($database) {
            // Rollback all connections
            foreach (array_keys($this->connectionsToMigrate()) as $connectionName) {
                $connection = $database->connection($connectionName);
                $dispatcher = $connection->getEventDispatcher();
                $connection->unsetEventDispatcher();
                $connection->rollback();
                $connection->setEventDispatcher($dispatcher);
                $connection->disconnect();
            }
        });
    }
}

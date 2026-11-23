<?php

namespace Tests\Feature\Seeders;

use App\Repositories\Sys\SysDeployRepository;
use Database\Seeders\SysDeploySeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\RefreshMultipleDatabases;
use Tests\TestCase;

/**
 * SysDeploySeeder は開発の基底 DB（mst 接続）を 1 つの版として登録する（設計: db.md 3.1・11.1）。
 * シーダーは truncate する（暗黙コミット）ので、トランザクションで包まない。
 */
class SysDeploySeederTest extends TestCase
{
    use RefreshMultipleDatabases;

    public function beginDatabaseTransaction(): void {}

    #[Test]
    public function 最新の版は基底_d_bを指し他の版は_d_b名が重ならない(): void
    {
        $this->seed(SysDeploySeeder::class);

        $sys = DB::connection('sys');
        $baseDatabase = (string) config('database.connections.mst.database');

        $latest = (new SysDeployRepository)->buildLatestDownloadableQuery()->first();
        $this->assertNotNull($latest);
        $this->assertSame(2026022201, $latest->deploy_key);
        $this->assertSame($baseDatabase, $latest->deployMaster?->getMstDatabase());
        $this->assertNull($latest->deployMaster?->getMstDroppedAt());
        $this->assertSame('build', $latest->deployMaster->getSourceKind());

        $names = $sys->table('sys_deploy_master')->pluck('mst_database')->all();
        $this->assertCount(3, array_unique($names));

        foreach ($sys->table('sys_deploy_master')->get() as $master) {
            $this->assertSame($master->deploy_key.'_'.substr((string) $master->hash, 0, 16), $master->artifact_dir);
            $this->assertSame(64, strlen((string) $master->schema_fingerprint));
        }
    }
}

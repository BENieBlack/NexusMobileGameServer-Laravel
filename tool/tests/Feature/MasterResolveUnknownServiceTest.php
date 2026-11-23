<?php

namespace Tests\Feature;

use App\Exceptions\MasterDeployRunException;
use App\Services\MasterResolveUnknownService;
use App\Services\MasterVersionDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * MasterResolveUnknownService（master:resolve-unknown。db.md 4.7）のテスト
 */
class MasterResolveUnknownServiceTest extends MasterVersionedTestCase
{
    #[Test]
    public function 登録済みなら_runをsucceededに直し_dbは消さずforgetする(): void
    {
        $v = $this->buildVersion(2026112201, 'a');
        $runId = $this->insertUnknownRun($v['mst_database'], [$v['mst_database']]);

        $result = $this->service()->run($runId, ['adm_account_id' => 9]);

        $this->assertSame('registered', $result['resolved']);
        $this->assertTrue($result['cache']['invalidated']);
        $run = DB::connection('tool')->table('tol_master_deploy_run')->where('id', $runId)->first();
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('register_unknown', $run->error_code, 'error_code は消さない');
        $summary = $this->summaryOf($run);
        $this->assertSame('registered', $summary['resolved']);
        $this->assertSame(9, $summary['resolved_by']);
        $this->assertArrayHasKey('resolved_at', $summary);
        $this->assertTrue($this->databaseExists($v['mst_database']));
    }

    #[Test]
    public function 未登録でrunが作った_dbならdropしrunはfailedのまま閉じる(): void
    {
        $name = $this->createDatabase('unknown-1');
        $runId = $this->insertUnknownRun($name, [$name]);
        $runsBefore = DB::connection('tool')->table('tol_master_deploy_run')->count();

        $result = $this->service()->run($runId, ['adm_account_id' => 9]);

        $this->assertSame('dropped', $result['resolved']);
        $this->assertFalse($this->databaseExists($name));
        $run = DB::connection('tool')->table('tol_master_deploy_run')->where('id', $runId)->first();
        $this->assertSame('failed', $run->status);
        $this->assertSame('register_unknown', $run->error_code);
        $this->assertSame('dropped', $this->summaryOf($run)['resolved']);
        $this->assertSame($runsBefore, DB::connection('tool')->table('tol_master_deploy_run')->count(), '新しい run は作らない');
    }

    #[Test]
    public function created_database_listに無い名前_採用した_db_はdrop_forbidden(): void
    {
        $name = $this->createDatabase('unknown-2');
        $runId = $this->insertUnknownRun($name, []);

        $this->expectError(MasterDeployRunException::DROP_FORBIDDEN, $runId);

        $this->assertTrue($this->databaseExists($name));
    }

    #[Test]
    public function 接頭辞の外の名前はdrop_forbidden(): void
    {
        $runId = $this->insertUnknownRun('other-env-master-1', ['other-env-master-1']);

        $this->expectError(MasterDeployRunException::DROP_FORBIDDEN, $runId);
    }

    #[Test]
    public function register_unknownでないrunやrunが無いときはrun_not_found(): void
    {
        $id = DB::connection('tool')->table('tol_master_deploy_run')->insertGetId([
            'kind' => 'build', 'status' => 'failed', 'phase' => 'register', 'app_env' => 'testing', 'error_code' => 'register_failed', 'started_at' => '2026-11-23 10:00:00',
        ]);

        $this->expectError(MasterDeployRunException::RUN_NOT_FOUND, $id);
        $this->expectError(MasterDeployRunException::RUN_NOT_FOUND, 999);
    }

    #[Test]
    public function 解決済みのrunは何もせずalready_resolved(): void
    {
        $name = $this->createDatabase('unknown-3');
        $runId = $this->insertUnknownRun($name, [$name]);
        $this->service()->run($runId);

        $result = $this->service()->run($runId);

        $this->assertSame('already_resolved', $result['code']);
    }

    #[Test]
    public function sysを読めなければsys_unreadableでrunはregister_unknownのまま(): void
    {
        $name = $this->createDatabase('unknown-4');
        $runId = $this->insertUnknownRun($name, [$name]);
        Schema::connection('sys')->drop('sys_deploy_master');

        $this->expectError(MasterDeployRunException::SYS_UNREADABLE, $runId);

        $run = DB::connection('tool')->table('tol_master_deploy_run')->where('id', $runId)->first();
        $this->assertSame('failed', $run->status);
        $this->assertArrayNotHasKey('resolved', $this->summaryOf($run));
        $this->assertTrue($this->databaseExists($name));
    }

    #[Test]
    public function dropに失敗したらdrop_failedでrunはregister_unknownのまま(): void
    {
        $name = $this->createDatabase('unknown-5');
        $runId = $this->insertUnknownRun($name, [$name]);
        $databases = new class extends MasterVersionDatabase
        {
            public function drop(string $database): void
            {
                throw new \RuntimeException('denied');
            }
        };

        try {
            (new MasterResolveUnknownService(lock: $this->lock, databases: $databases, invalidator: $this->invalidator))->run($runId);
            $this->fail('drop_failed が投げられるはず');
        } catch (MasterDeployRunException $e) {
            $this->assertSame(MasterDeployRunException::DROP_FAILED, $e->errorCode);
        }
        $this->assertArrayNotHasKey('resolved', $this->summaryOf(DB::connection('tool')->table('tol_master_deploy_run')->where('id', $runId)->first()));
    }

    #[Test]
    public function ロックが取れなければdeploy_locked(): void
    {
        $name = $this->createDatabase('unknown-6');
        $runId = $this->insertUnknownRun($name, [$name]);
        $this->lock->shouldReceive('acquire')->andReturn(false);

        $this->expectError(MasterDeployRunException::DEPLOY_LOCKED, $runId);

        $this->assertTrue($this->databaseExists($name));
    }

    /**
     * @param  list<string>  $created
     */
    private function insertUnknownRun(string $database, array $created): int
    {
        return (int) DB::connection('tool')->table('tol_master_deploy_run')->insertGetId([
            'kind' => 'build', 'status' => 'failed', 'phase' => 'register', 'app_env' => 'testing', 'error_code' => 'register_unknown',
            'mst_database' => $database, 'summary' => json_encode(['created_database_list' => $created]), 'started_at' => '2026-11-23 10:00:00', 'finished_at' => '2026-11-23 10:00:05',
        ]);
    }

    private function createDatabase(string $suffix): string
    {
        $name = $this->appName.'-'.$this->envName().'-master-'.$suffix;
        DB::connection('build_test_admin')->statement("CREATE DATABASE `{$name}`");

        return $name;
    }

    private function service(): MasterResolveUnknownService
    {
        return new MasterResolveUnknownService(lock: $this->lock, invalidator: $this->invalidator);
    }

    private function expectError(string $code, int $runId): MasterDeployRunException
    {
        try {
            $this->service()->run($runId);
        } catch (MasterDeployRunException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());

            return $e;
        }
        $this->fail("{$code} が投げられるはず");
    }
}

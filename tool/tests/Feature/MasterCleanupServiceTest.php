<?php

namespace Tests\Feature;

use App\Exceptions\MasterDeployRunException;
use App\Services\MasterActivationService;
use App\Services\MasterArtifactStore;
use App\Services\MasterCleanupService;
use App\Services\MasterVersionDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * MasterCleanupService（掃除。db.md 4.6・4.7）のテスト
 */
class MasterCleanupServiceTest extends MasterVersionedTestCase
{
    #[Test]
    public function 保持の規則で版の_dbを消し_dbだけ消して成果物とmasterの行は残す(): void
    {
        Config::set('master_deploy.keep_version_count', 2);
        $v = $this->buildFive();
        $future = $this->buildVersion(2026112206, 'f', '2026-11-23 15:00:00');

        $dry = $this->service()->run(['dry_run' => true]);

        $this->assertTrue($dry['dry_run']);
        $this->assertNull($dry['run_id']);
        $this->assertSame([$v[1]['mst_database'], $v[2]['mst_database'], $v[3]['mst_database']], array_column($dry['drop_list'], 'name'));
        $reasons = array_column($dry['keep_list'], 'reason', 'mst_database');
        $this->assertSame('current', $reasons[$v[5]['mst_database']]);
        $this->assertSame('previous', $reasons[$v[4]['mst_database']]);
        $this->assertSame('scheduled', $reasons[$future['mst_database']]);
        $this->assertTrue($this->databaseExists($v[1]['mst_database']), 'dry_run は消さない');
        $this->assertSame(0, DB::connection('tool')->table('tol_master_deploy_run')->count() - 6, 'dry_run は実行記録を書かない（build の 6 件だけ）');

        $result = $this->service()->run(['dry_run' => false, 'adm_account_id' => 2]);

        $this->assertSame([$v[1]['mst_database'], $v[2]['mst_database'], $v[3]['mst_database']], array_column($result['dropped_list'], 'name'));
        foreach ([1, 2, 3] as $i) {
            $this->assertFalse($this->databaseExists($v[$i]['mst_database']));
            $this->assertNotNull(DB::connection('sys')->table('sys_deploy_master')->where('id', $v[$i]['sys_deploy_master_id'])->value('mst_dropped_at'));
            $this->assertTrue(Storage::disk(MasterArtifactStore::DISK)->exists($v[$i]['artifact_dir'].'/manifest.json'));
        }
        foreach ([4, 5] as $i) {
            $this->assertTrue($this->databaseExists($v[$i]['mst_database']));
        }
        $this->assertTrue($this->databaseExists($future['mst_database']));
        $run = $this->lastRun();
        $this->assertSame('cleanup', $run->kind);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('done', $run->phase);
        $this->assertNull($run->deploy_key);
        $this->assertCount(3, $this->summaryOf($run)['dropped_list']);

        // 冪等: 2 回目は何も消さない
        $again = $this->service()->run(['dry_run' => false]);
        $this->assertSame([], $again['dropped_list']);
    }

    #[Test]
    public function 止めた行を指す版は残し_n_の枠に数えない(): void
    {
        Config::set('master_deploy.keep_version_count', 1);
        $v = $this->buildFive();
        // v5 を止める → 最新は v4、直前は v3。v5 は止めた行なので保持（枠に数えない）
        $this->deactivate((int) $v[5]['sys_deploy_id']);

        $plan = $this->service()->run(['dry_run' => true]);

        $reasons = array_column($plan['keep_list'], 'reason', 'mst_database');
        $this->assertSame('current', $reasons[$v[4]['mst_database']]);
        $this->assertSame('previous', $reasons[$v[3]['mst_database']]);
        $this->assertSame('inactive', $reasons[$v[5]['mst_database']]);
        $this->assertSame([$v[1]['mst_database'], $v[2]['mst_database']], array_column($plan['drop_list'], 'name'));
    }

    #[Test]
    public function 基底_dbを版として登録していても消さない(): void
    {
        Config::set('master_deploy.keep_version_count', 0);
        $v = $this->buildFive();
        DB::connection('sys')->table('sys_deploy_master')->where('id', $v[1]['sys_deploy_master_id'])->update(['mst_database' => $this->baseDatabaseName()]);

        $plan = $this->service()->run(['dry_run' => true]);

        $this->assertSame('base', array_column($plan['keep_list'], 'reason', 'mst_database')[$this->baseDatabaseName()]);
        $this->assertNotContains($this->baseDatabaseName(), array_column($plan['drop_list'], 'name'));
    }

    #[Test]
    public function 自環境の接頭辞の外の名前の行はその行だけ保持にして警告を返し_他は掃除する(): void
    {
        Config::set('master_deploy.keep_version_count', 0);
        $v = $this->buildFive();
        DB::connection('sys')->table('sys_deploy_master')->where('id', $v[1]['sys_deploy_master_id'])->update(['mst_database' => 'other-env-master-1']);

        $result = $this->service()->run(['dry_run' => false]);

        $this->assertSame('foreign_database', array_column($result['keep_list'], 'reason', 'mst_database')['other-env-master-1']);
        $this->assertContains('drop_forbidden_skipped', array_column($result['warning_list'], 'code'));
        $this->assertNotContains('other-env-master-1', array_column($result['dropped_list'], 'name'));
        $this->assertFalse($this->databaseExists($v[2]['mst_database']));
        $this->assertSame('succeeded', $this->lastRun()->status);
    }

    #[Test]
    public function 孤児の_dbは猶予を過ぎた失敗runのcreated_database_listにあるものだけ消す(): void
    {
        $old = $this->createDatabase('orphan-old');
        $recent = $this->createDatabase('orphan-recent');
        $unknown = $this->createDatabase('orphan-unknown');
        $registered = $this->createDatabase('orphan-registered');
        $notListed = $this->createDatabase('not-listed');
        $masterId = DB::connection('sys')->table('sys_deploy_master')->insertGetId([
            'deploy_key' => 2026112299, 'hash' => str_repeat('a', 64), 'deploy_date' => '2026-11-23', 'deploy_count' => 99, 'status' => 'completed',
            'mst_database' => $registered, 'artifact_dir' => 'x', 'schema_fingerprint' => 'x', 'source_kind' => 'build',
        ]);
        // 予約済みの版として保持される（版の掃除の対象にならない）
        DB::connection('sys')->table('sys_deploy')->insert(['deploy_key' => 2026112299, 'start_at' => '2026-11-24 00:00:00', 'sys_deploy_master_id' => $masterId, 'sys_deploy_asset_id' => 1, 'is_active' => 1]);
        $this->insertRun('failed', '2026-11-22 10:00:00', [$old, $registered], null);   // 26 時間前
        $this->insertRun('failed', '2026-11-23 06:00:00', [$recent], null);             // 6 時間前（猶予内）
        $this->insertRun('failed', '2026-11-22 10:00:00', [$unknown], 'register_unknown');

        $result = $this->service()->run(['dry_run' => false]);

        $this->assertSame([$old], array_column(array_filter($result['dropped_list'], fn ($i) => $i['kind'] === 'orphan'), 'name'));
        $this->assertFalse($this->databaseExists($old));
        foreach ([$recent, $unknown, $registered, $notListed] as $name) {
            $this->assertTrue($this->databaseExists($name), $name);
        }

        // 解決した register_unknown の run は除外から外れる
        DB::connection('tool')->table('tol_master_deploy_run')->where('error_code', 'register_unknown')
            ->update(['summary' => json_encode(['created_database_list' => [$unknown], 'resolved' => 'dropped'])]);
        $result = $this->service()->run(['dry_run' => false]);
        $this->assertContains($unknown, array_column($result['dropped_list'], 'name'));
    }

    #[Test]
    public function 実行記録の無い古い作業用_dbと古い一時の場所を消し_新しいものは残す(): void
    {
        $oldWork = $this->createDatabase('work-20261120000000-ab12');
        $newWork = $this->createDatabase('work-20261123110000-cd34');
        $disk = Storage::disk(MasterArtifactStore::DISK);
        $disk->put('.tmp/20261120000000-5/data/x', 'x');
        $disk->put('.tmp/20261123110000-6/data/x', 'x');

        $result = $this->service()->run(['dry_run' => false]);

        $this->assertEqualsCanonicalizing([$oldWork, '.tmp/20261120000000-5'], array_column($result['dropped_list'], 'name'));
        $this->assertFalse($this->databaseExists($oldWork));
        $this->assertTrue($this->databaseExists($newWork));
        $this->assertFalse($disk->exists('.tmp/20261120000000-5/data/x'));
        $this->assertTrue($disk->exists('.tmp/20261123110000-6/data/x'));
    }

    #[Test]
    public function 消せないものがあればcleanup_failedで消せたものは消えたまま返す(): void
    {
        Config::set('master_deploy.keep_version_count', 0);
        $v = $this->buildFive();
        $failName = $v[1]['mst_database'];
        $databases = new class($failName) extends MasterVersionDatabase
        {
            public function __construct(private readonly string $failName)
            {
                parent::__construct();
            }

            public function drop(string $database): void
            {
                if ($database === $this->failName) {
                    throw new \RuntimeException('drop denied');
                }
                parent::drop($database);
            }
        };

        try {
            (new MasterCleanupService(lock: $this->lock, databases: $databases, invalidator: $this->invalidator))->run(['dry_run' => false]);
            $this->fail('cleanup_failed が投げられるはず');
        } catch (MasterDeployRunException $e) {
            $this->assertSame(MasterDeployRunException::CLEANUP_FAILED, $e->errorCode);
            $this->assertSame([$failName], array_column($e->extra['failed_list'], 'name'));
            $this->assertNotContains($failName, array_column($e->extra['dropped_list'], 'name'));
        }

        // DROP が先・mst_dropped_at が後: 失敗した版は更新されず、再実行できる
        $this->assertNull(DB::connection('sys')->table('sys_deploy_master')->where('id', $v[1]['sys_deploy_master_id'])->value('mst_dropped_at'));
        $this->assertTrue($this->databaseExists($failName));
        $this->assertFalse($this->databaseExists($v[2]['mst_database']));
        $this->assertSame('failed', $this->lastRun()->status);
        $this->assertSame('cleanup_failed', $this->lastRun()->error_code);
    }

    #[Test]
    public function ロックが取れなければdeploy_lockedで消さない(): void
    {
        Config::set('master_deploy.keep_version_count', 0);
        $v = $this->buildFive();
        $this->lock->shouldReceive('acquire')->andReturn(false);

        try {
            $this->service()->run(['dry_run' => false]);
            $this->fail('deploy_locked が投げられるはず');
        } catch (MasterDeployRunException $e) {
            $this->assertSame(MasterDeployRunException::DEPLOY_LOCKED, $e->errorCode);
        }
        $this->assertTrue($this->databaseExists($v[1]['mst_database']));
    }

    #[Test]
    public function 既定はdry_run(): void
    {
        Config::set('master_deploy.keep_version_count', 0);
        $v = $this->buildFive();

        $result = $this->service()->run();

        $this->assertTrue($result['dry_run']);
        $this->assertTrue($this->databaseExists($v[1]['mst_database']));
    }

    /**
     * @return array<int, array<string, mixed>> 1〜5 の版（start_at は 06:00〜10:00。すべて到達済み）
     */
    private function buildFive(): array
    {
        $v = [];
        foreach ([1, 2, 3, 4, 5] as $i) {
            $v[$i] = $this->buildVersion(2026112200 + $i, 'v'.$i, sprintf('2026-11-23 %02d:00:00', 5 + $i));
        }

        return $v;
    }

    private function deactivate(int $sysDeployId): void
    {
        (new MasterActivationService(lock: $this->lock, invalidator: $this->invalidator))->deactivate($sysDeployId);
    }

    private function createDatabase(string $suffix): string
    {
        $name = $this->appName.'-'.$this->envName().'-master-'.$suffix;
        DB::connection('build_test_admin')->statement("CREATE DATABASE `{$name}`");

        return $name;
    }

    /**
     * @param  list<string>  $created
     */
    private function insertRun(string $status, string $finishedAt, array $created, ?string $errorCode): void
    {
        DB::connection('tool')->table('tol_master_deploy_run')->insert([
            'kind' => 'build', 'status' => $status, 'phase' => 'register', 'app_env' => 'testing',
            'error_code' => $errorCode, 'started_at' => $finishedAt, 'finished_at' => $finishedAt,
            'mst_database' => $created[0], 'summary' => json_encode(['created_database_list' => $created]),
        ]);
    }

    private function service(): MasterCleanupService
    {
        return new MasterCleanupService(lock: $this->lock, invalidator: $this->invalidator);
    }
}

<?php

namespace Tests\Feature;

use App\Exceptions\MasterDeployKeyException;
use App\Exceptions\MasterDeployRunException;
use App\Services\MasterArtifactStore;
use App\Services\MasterDeployLock;
use App\Services\MasterDeployService;
use App\Services\MasterImportRunner;
use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * MasterImportRunner（import。db.md 6.6 の import・6.7 の I1〜I6）のテスト。成果物は実物の build で作って上流へ写す
 */
class MasterImportRunnerTest extends MasterVersionedTestCase
{
    #[Test]
    public function 上流の成果物から版の_dbを作り_自環境のストレージに置いてsysに登録する(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $this->becomeImportEnvironment();

        $result = $this->runner()->run($a['artifact_dir'], ['start_at' => '2026-11-23 15:00:00', 'adm_account_id' => 4]);

        $this->assertSame('success', $result['status']);
        $deploy = $result['deploy'];
        $this->assertSame($a['hash'], $deploy['hash']);
        $this->assertSame($a['mst_database'], $deploy['mst_database']);
        $this->assertTrue($deploy['is_new']);
        $this->assertFalse($deploy['database_reused']);
        $this->assertSame('2026-11-23 15:00:00', $deploy['start_at']);
        // 行は成果物のとおり
        $this->assertSame(['a', 'b'], DB::connection('mst')->table($a['mst_database'].'.mst_item')->orderBy('id')->pluck('id')->all());
        $this->assertNull(DB::connection('mst')->table($a['mst_database'].'.mst_item')->where('id', 'b')->value('price'));
        $this->assertSame('1970-01-01 00:00:00', DB::connection('mst')->table($a['mst_database'].'.mst_item')->where('id', 'a')->value('created_at'));
        $this->assertSame(1, DB::connection('mst')->table($a['mst_database'].'.mst_item__l10n')->count());
        $this->assertSame(0, DB::connection('mst')->table($a['mst_database'].'.mst_empty')->count());
        // 自環境のストレージ
        $this->assertTrue(Storage::disk(MasterArtifactStore::DISK)->exists($a['artifact_dir'].'/manifest.json'));
        $this->assertTrue(Storage::disk(MasterArtifactStore::DISK)->exists($a['artifact_dir'].'/data/mst_item.ndjson'));
        // sys
        $master = DB::connection('sys')->table('sys_deploy_master')->first();
        $this->assertSame('import', $master->source_kind);
        $this->assertSame('master_import', $master->deployed_by);
        $this->assertSame($a['artifact_dir'], $master->artifact_dir);
        $this->assertNull($master->mst_dropped_at);
        $sysDeploy = DB::connection('sys')->table('sys_deploy')->first();
        $this->assertSame('2026-11-23 15:00:00', $sysDeploy->start_at);
        $this->assertSame(3, DB::connection('sys')->table('sys_deploy_master_table')->count());
        // 実行記録
        $run = $this->lastRun();
        $this->assertSame('import', $run->kind);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('done', $run->phase);
        $this->assertSame(4, (int) $run->adm_account_id);
        $this->assertSame([$a['mst_database']], $this->summaryOf($run)['created_database_list']);
        $this->assertSame(['I1', 'I2', 'I3', 'I4', 'I6', 'I5'], array_column($result['import']['check_list'], 'check'));
        $this->assertSame([$a['mst_database']], $this->databaseList());
    }

    #[Test]
    public function start_atがnullなら登録の直前の現在時刻(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $this->becomeImportEnvironment();

        $result = $this->runner()->run($a['artifact_dir'], ['start_at' => null]);

        $this->assertSame('2026-11-23 12:00:00', $result['deploy']['start_at']);
    }

    #[Test]
    public function 同じ成果物の再importは版の_dbと成果物を再利用して切り替え行だけ足す(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $this->becomeImportEnvironment();
        $first = $this->runner()->run($a['artifact_dir'], ['start_at' => '2026-11-23 15:00:00']);

        $second = $this->runner()->run($a['artifact_dir'], ['start_at' => '2026-11-23 16:00:00']);

        $this->assertFalse($second['deploy']['is_new']);
        $this->assertTrue($second['deploy']['database_reused']);
        $this->assertTrue($second['artifact']['reused']);
        $this->assertSame($first['deploy']['sys_deploy_master_id'], $second['deploy']['sys_deploy_master_id']);
        $this->assertSame(2, DB::connection('sys')->table('sys_deploy')->count());
        $this->assertSame(1, DB::connection('sys')->table('sys_deploy_master')->count());
        $this->assertSame([], $this->summaryOf($this->lastRun())['created_database_list']);
        $this->assertSame('skipped', collect($second['import']['check_list'])->firstWhere('check', 'I5')['result']);
    }

    #[Test]
    public function 同じ_start_atの再実行は新しい行を作らず既存を返す(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $this->becomeImportEnvironment();
        $first = $this->runner()->run($a['artifact_dir'], ['start_at' => '2026-11-23 15:00:00']);

        $second = $this->runner()->run($a['artifact_dir'], ['start_at' => '2026-11-23 15:00:00']);

        $this->assertSame($first['deploy']['sys_deploy_id'], $second['deploy']['sys_deploy_id']);
        $this->assertSame(1, DB::connection('sys')->table('sys_deploy')->count());
        $this->assertContains('already_registered', array_column($second['import']['warning_list'], 'code'));
    }

    // ---- 拒否（環境・取得・検証） ----

    #[Test]
    public function role_がbuildの環境ではrole_forbidden(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $this->becomeImportEnvironment();
        Config::set('master_deploy.role', 'build');

        $this->expectError(MasterDeployRunException::ROLE_FORBIDDEN, $a['artifact_dir']);
    }

    #[Test]
    public function 上流が未設定ならupstream_not_configured(): void
    {
        Config::set('master_deploy.role', 'import');
        Config::set('filesystems.disks.master_artifact_upstream', null);

        $this->expectError(MasterDeployRunException::UPSTREAM_NOT_CONFIGURED, '2026112201_'.str_repeat('a', 16));
    }

    #[Test]
    public function 上流に無い成果物や形の不正な名前はartifact_not_found(): void
    {
        Config::set('master_deploy.role', 'import');

        $this->expectError(MasterDeployRunException::ARTIFACT_NOT_FOUND, '2026112201_'.str_repeat('a', 16));
        $this->expectError(MasterDeployRunException::ARTIFACT_NOT_FOUND, '../etc');
        $this->assertSame('failed', $this->lastRun()->status);
        $this->assertSame('pull', $this->lastRun()->phase);
    }

    #[Test]
    public function manifestの無い未完成の成果物はartifact_not_found(): void
    {
        $a = $this->buildAndPublish(2026112201);
        Storage::disk('master_artifact_upstream')->delete($a['artifact_dir'].'/manifest.json');
        $this->becomeImportEnvironment();

        $this->expectError(MasterDeployRunException::ARTIFACT_NOT_FOUND, $a['artifact_dir']);
    }

    #[Test]
    public function ファイルが改ざんされていればartifact_invalidでdbを作らない(): void
    {
        $a = $this->buildAndPublish(2026112201);
        Storage::disk('master_artifact_upstream')->put($a['artifact_dir'].'/data/mst_item.ndjson', "{\"id\":\"x\"}\n");
        $this->becomeImportEnvironment();

        $this->expectError(MasterDeployRunException::ARTIFACT_INVALID, $a['artifact_dir']);

        $this->assertSame([], $this->databaseList());
        $this->assertSame('verify', $this->lastRun()->phase);
        $this->assertSame(0, DB::connection('sys')->table('sys_deploy_master')->count());
    }

    #[Test]
    public function マニフェストのhashが書き換えられていればartifact_invalid(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $upstream = Storage::disk('master_artifact_upstream');
        $manifest = json_decode($upstream->get($a['artifact_dir'].'/manifest.json'), true);
        $manifest['tables'][0]['row_count'] = 99;
        $upstream->put($a['artifact_dir'].'/manifest.json', json_encode($manifest));
        $this->becomeImportEnvironment();

        $this->expectError(MasterDeployRunException::ARTIFACT_INVALID, $a['artifact_dir']);
    }

    #[Test]
    public function require_finalizedの環境ではfinal_jsonが無い成果物をnot_finalizedで拒否し_あれば通して先にコピーする(): void
    {
        Config::set('master_deploy.require_finalized', true);
        $a = $this->buildAndPublish(2026112201);
        $this->becomeImportEnvironment();
        Config::set('master_deploy.require_finalized', true);

        $this->expectError(MasterDeployRunException::NOT_FINALIZED, $a['artifact_dir']);
        $this->assertSame([], $this->databaseList());

        // 別の hash の印は拒否
        Storage::disk('master_artifact_upstream')->put($a['artifact_dir'].'/final.json', json_encode(['deploy_key' => 2026112201, 'hash' => str_repeat('0', 64)]));
        $this->expectError(MasterDeployRunException::NOT_FINALIZED, $a['artifact_dir']);

        Storage::disk('master_artifact_upstream')->put($a['artifact_dir'].'/final.json', json_encode(['deploy_key' => 2026112201, 'hash' => $a['hash']]));
        $result = $this->runner()->run($a['artifact_dir'], ['start_at' => null]);

        $this->assertSame('success', $result['status']);
        $this->assertTrue(Storage::disk(MasterArtifactStore::DISK)->exists($a['artifact_dir'].'/final.json'));
    }

    #[Test]
    public function 予約帯の成果物はproduction_stagingではreserved_key_forbiddenで_reviewなどでは通す(): void
    {
        $a = $this->buildVersion(9999999999, 'a', null, [], true);
        $this->publishUpstream($a['artifact_dir']);
        $this->becomeImportEnvironment();
        Config::set('master_deploy.fixed_key', null);

        $ok = $this->runner()->run($a['artifact_dir'], ['start_at' => null]);
        $this->assertSame('success', $ok['status']);
        $this->assertSame(0, (int) DB::connection('sys')->table('sys_deploy_master')->value('deploy_count'));

        $this->becomeImportEnvironment();
        Config::set('master_deploy.fixed_key_forbidden_envs', [$this->envName()]);
        $this->expectError(MasterDeployRunException::RESERVED_KEY_FORBIDDEN, $a['artifact_dir']);
        $this->assertSame([], $this->databaseList());
    }

    #[Test]
    public function スキーマ版が自環境の基底_dbと違えばschema_mismatchでdbを作らない(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $this->becomeImportEnvironment();
        DB::connection('mst')->statement('ALTER TABLE mst_empty ADD COLUMN note VARCHAR(10) NULL');

        $this->expectError(MasterDeployRunException::SCHEMA_MISMATCH, $a['artifact_dir']);

        $this->assertSame([], $this->databaseList());
    }

    // ---- I4・I6（DB を作る前） ----

    #[Test]
    public function 同じdeploy_keyで別hashの版が既にあればconfirmed_key_modified_全環境(): void
    {
        $a1 = $this->buildVersion(2026112201, 'a');
        $a2 = $this->buildVersion(2026112201, 'b');
        $this->publishUpstream($a1['artifact_dir']);
        $this->publishUpstream($a2['artifact_dir']);
        $this->becomeImportEnvironment();
        $this->runner()->run($a1['artifact_dir'], ['start_at' => null]);
        $this->assertFalse((bool) config('master_deploy.enforce_confirmed_rows'));

        $this->expectError(MasterDeployRunException::CONFIRMED_KEY_MODIFIED, $a2['artifact_dir']);

        $this->assertSame([$a1['mst_database']], $this->databaseList());
        $this->assertSame('check', $this->lastRun()->phase);
    }

    #[Test]
    public function productionでは参照版より小さいキーの新しい版をdeploy_key_regressionで拒否する(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $b = $this->buildAndPublish(2026112202, 'b');
        $this->becomeImportEnvironment();
        Config::set('master_deploy.enforce_confirmed_rows', true);
        $this->runner()->run($b['artifact_dir'], ['start_at' => null]);

        $this->expectError(MasterDeployRunException::DEPLOY_KEY_REGRESSION, $a['artifact_dir']);

        $this->assertSame([$b['mst_database']], $this->databaseList());
        // 前進の検査は production だけ
        Config::set('master_deploy.enforce_confirmed_rows', false);
        $this->assertSame('success', $this->runner()->run($a['artifact_dir'], ['start_at' => null])['status']);
    }

    #[Test]
    public function 過去のstart_atはproductionの設定で拒否し_許容幅以内は現在に丸める(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $this->becomeImportEnvironment();
        Config::set('master_deploy.reject_past_start_at', true);

        $this->expectError(MasterDeployRunException::START_AT_PAST, $a['artifact_dir'], ['start_at' => '2026-11-23 11:00:00']);
        $this->assertSame([], $this->databaseList());
        $this->assertSame('check', $this->lastRun()->phase);

        $ok = $this->runner()->run($a['artifact_dir'], ['start_at' => '2026-11-23 11:59:30']);
        $this->assertSame('2026-11-23 12:00:00', $ok['deploy']['start_at']);
        $this->assertContains('start_at_too_soon', array_column($ok['import']['warning_list'], 'code'));
    }

    // ---- I5 ----

    #[Test]
    public function 確定済みキーの行が参照版と同じで新しい行だけ足した版はproductionで通る(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $b = $this->buildVersion(2026112202, 'a', null, [['c', '2026112202', 'C']]);
        $this->publishUpstream($b['artifact_dir']);
        $this->becomeImportEnvironment();
        Config::set('master_deploy.enforce_confirmed_rows', true);
        $first = $this->runner()->run($a['artifact_dir'], ['start_at' => null]);

        $result = $this->runner()->run($b['artifact_dir'], ['start_at' => null]);

        $this->assertSame('passed', collect($result['import']['check_list'])->firstWhere('check', 'I5')['result']);
        $this->assertSame($first['deploy']['sys_deploy_master_id'], $result['import']['reference']['sys_deploy_master_id']);
        $this->assertFalse($result['import']['reference']['restored']);
        $this->assertCount(2, $this->databaseList());
    }

    #[Test]
    public function 確定済みキーの行が参照版と違えばconfirmed_key_modifiedで表とidを返し_この_runの_dbを捨てる(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $b = $this->buildAndPublish(2026112202, 'changed'); // 行 a（deploy_key 2026010101 <= K）の内容が違う
        $this->becomeImportEnvironment();
        Config::set('master_deploy.enforce_confirmed_rows', true);
        $this->runner()->run($a['artifact_dir'], ['start_at' => null]);

        $error = $this->expectError(MasterDeployRunException::CONFIRMED_KEY_MODIFIED, $b['artifact_dir']);

        $this->assertSame([['table' => 'mst_item', 'id' => 'a']], $error->errorList);
        $this->assertSame(1, $error->extra['error_total']);
        $this->assertSame([$a['mst_database']], $this->databaseList(), '参照版の DB は消さず、この run が作った DB だけ捨てる');
        $this->assertSame('check_rows', $this->lastRun()->phase);
        $this->assertSame(1, DB::connection('sys')->table('sys_deploy_master')->count());
    }

    #[Test]
    public function 参照版の_dbが掃除済みなら成果物から復元して比べ_sysのaも更新する(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $b = $this->buildVersion(2026112202, 'a', null, [['c', '2026112202', 'C']]);
        $this->publishUpstream($b['artifact_dir']);
        $this->becomeImportEnvironment();
        Config::set('master_deploy.enforce_confirmed_rows', true);
        $first = $this->runner()->run($a['artifact_dir'], ['start_at' => null]);
        $this->dropDatabase($a['mst_database']);
        DB::connection('sys')->table('sys_deploy_master')->where('id', $first['deploy']['sys_deploy_master_id'])->update(['mst_dropped_at' => '2026-11-23 11:00:00']);

        $result = $this->runner()->run($b['artifact_dir'], ['start_at' => null]);

        $this->assertTrue($result['import']['reference']['restored']);
        $this->assertTrue($this->databaseExists($a['mst_database']));
        $this->assertNull(DB::connection('sys')->table('sys_deploy_master')->where('id', $first['deploy']['sys_deploy_master_id'])->value('mst_dropped_at'));
        $this->assertSame([$b['mst_database']], $this->summaryOf($this->lastRun())['created_database_list'], '復元した A の DB はこの run の作成物から外れる');
    }

    #[Test]
    public function 参照版の成果物も無ければreference_unavailableでこの_runの_dbを捨てる(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $b = $this->buildVersion(2026112202, 'a', null, [['c', '2026112202', 'C']]);
        $this->publishUpstream($b['artifact_dir']);
        $this->becomeImportEnvironment();
        Config::set('master_deploy.enforce_confirmed_rows', true);
        $first = $this->runner()->run($a['artifact_dir'], ['start_at' => null]);
        $this->dropDatabase($a['mst_database']);
        DB::connection('sys')->table('sys_deploy_master')->where('id', $first['deploy']['sys_deploy_master_id'])->update(['mst_dropped_at' => '2026-11-23 11:00:00']);
        Storage::disk(MasterArtifactStore::DISK)->deleteDirectory($a['artifact_dir']);

        $this->expectError(MasterDeployRunException::REFERENCE_UNAVAILABLE, $b['artifact_dir']);

        $this->assertSame([], $this->databaseList());
    }

    #[Test]
    public function 参照版を復元した後に配置で失敗しても_aの_dbは消さずbの_dbだけ捨てる(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $b = $this->buildVersion(2026112202, 'a', null, [['c', '2026112202', 'C']]);
        $this->publishUpstream($b['artifact_dir']);
        $this->becomeImportEnvironment();
        Config::set('master_deploy.enforce_confirmed_rows', true);
        $first = $this->runner()->run($a['artifact_dir'], ['start_at' => null]);
        $this->dropDatabase($a['mst_database']);
        DB::connection('sys')->table('sys_deploy_master')->where('id', $first['deploy']['sys_deploy_master_id'])->update(['mst_dropped_at' => '2026-11-23 11:00:00']);
        $failingStore = new class extends MasterArtifactStore
        {
            public function put(string $localDir, string $artifactDir): void
            {
                throw new RuntimeException('disk full');
            }
        };

        $this->expectError(MasterDeployRunException::STORE_FAILED, $b['artifact_dir'], ['start_at' => null], $failingStore);

        $this->assertSame([$a['mst_database']], $this->databaseList());
        $this->assertNull(DB::connection('sys')->table('sys_deploy_master')->where('id', $first['deploy']['sys_deploy_master_id'])->value('mst_dropped_at'));
    }

    #[Test]
    public function 参照版の採用した_dbでsys更新が失敗してもその_dbは消さずreference_unavailable(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $b = $this->buildVersion(2026112202, 'a', null, [['c', '2026112202', 'C']]);
        $this->publishUpstream($b['artifact_dir']);
        $this->becomeImportEnvironment();
        Config::set('master_deploy.enforce_confirmed_rows', true);
        $first = $this->runner()->run($a['artifact_dir'], ['start_at' => null]);
        // A は「掃除済み」扱いだが DB 本体は（同じ名前・同じ中身で）残っている → 採用される
        DB::connection('sys')->table('sys_deploy_master')->where('id', $first['deploy']['sys_deploy_master_id'])->update(['mst_database' => 'old-name', 'mst_dropped_at' => '2026-11-23 11:00:00']);
        DB::connection('sys')->statement("CREATE TRIGGER fail_master_update BEFORE UPDATE ON sys_deploy_master BEGIN SELECT RAISE(ABORT, 'denied'); END");

        $this->expectError(MasterDeployRunException::REFERENCE_UNAVAILABLE, $b['artifact_dir']);

        $this->assertTrue($this->databaseExists($a['mst_database']), '採用した DB（この run が作ったものではない）は消さない');
        $this->assertFalse($this->databaseExists($b['mst_database']), 'この run が作った B の DB だけ捨てる');
    }

    // ---- 配置・登録の失敗と孤児管理 ----

    #[Test]
    public function 同じ名前の成果物ディレクトリで完全なhashが違えばartifact_dir_collisionでdbを捨てる(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $this->becomeImportEnvironment();
        Storage::disk(MasterArtifactStore::DISK)->put($a['artifact_dir'].'/manifest.json', json_encode(['hash' => substr($a['hash'], 0, 16).str_repeat('0', 48)]));

        $this->expectError(MasterDeployRunException::ARTIFACT_DIR_COLLISION, $a['artifact_dir']);

        $this->assertSame([], $this->databaseList());
        $this->assertSame('store', $this->lastRun()->phase);
    }

    #[Test]
    public function 同じ名前の_dbが使えない中身で実在すればmst_database_collisionで触らない(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $this->becomeImportEnvironment();
        DB::connection('build_test_admin')->statement('CREATE DATABASE `'.$a['mst_database'].'`');

        $this->expectError(MasterDeployRunException::MST_DATABASE_COLLISION, $a['artifact_dir']);

        $this->assertTrue($this->databaseExists($a['mst_database']));
        $this->assertSame([], $this->summaryOf($this->lastRun())['created_database_list']);
    }

    #[Test]
    public function sysの登録がcommit前に失敗すればこの_runの_dbを捨てregister_failedで閉じる(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $this->becomeImportEnvironment();
        $service = new class extends MasterDeployService
        {
            public function registerBuild(array $input, ?CarbonInterface $now = null): array
            {
                throw MasterDeployKeyException::registerFailed();
            }
        };

        $this->expectError(MasterDeployRunException::REGISTER_FAILED, $a['artifact_dir'], ['start_at' => null], null, $service);

        $this->assertSame([], $this->databaseList());
        $this->assertSame('register', $this->lastRun()->phase);
        $this->assertSame(0, DB::connection('sys')->table('sys_deploy')->count());
    }

    #[Test]
    public function commitの成否が不明で行が無ければdbを捨てずregister_failed_孤児掃除に回す_次の_importは孤児を採用する(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $this->becomeImportEnvironment();

        $this->expectError(MasterDeployRunException::REGISTER_FAILED, $a['artifact_dir'], ['start_at' => null], null, $this->flakyService('before'));

        $this->assertTrue($this->databaseExists($a['mst_database']));
        $this->assertSame([$a['mst_database']], $this->summaryOf($this->lastRun())['created_database_list']);
        $this->assertSame(0, DB::connection('sys')->table('sys_deploy_master')->count());

        // 次の import は、sys に行が無い孤児を（中身が一致するので）採用する。この run が作ったものではない
        $result = $this->runner()->run($a['artifact_dir'], ['start_at' => null]);

        $this->assertSame('success', $result['status']);
        $this->assertSame([], $this->summaryOf($this->lastRun())['created_database_list']);
        $this->assertSame([$a['mst_database']], $this->databaseList());
    }

    #[Test]
    public function commit処理中のエラーで登録済みなら成功と警告(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $this->becomeImportEnvironment();

        $result = $this->runner(null, null, $this->flakyService('after'))->run($a['artifact_dir'], ['start_at' => null]);

        $this->assertContains('register_confirmed_after_error', array_column($result['import']['warning_list'], 'code'));
        $this->assertSame('succeeded', $this->lastRun()->status);
    }

    #[Test]
    public function schedule_cacheの破棄に失敗しても成功のまま警告(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $this->becomeImportEnvironment();
        $this->invalidator->shouldReceive('forgetSchedule')->andReturn(['invalidated' => false, 'failed_key_list' => ['x']]);

        $result = $this->runner()->run($a['artifact_dir'], ['start_at' => null]);

        $this->assertFalse($result['cache']['invalidated']);
        $this->assertContains('schedule_cache_not_invalidated', array_column($result['import']['warning_list'], 'code'));
    }

    // ---- ロック・dry_run ----

    #[Test]
    public function ロックが取れなければdeploy_lockedで実行記録も作らない(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $this->becomeImportEnvironment();
        $this->lock->shouldReceive('acquire')->andReturn(false);

        $this->expectError(MasterDeployRunException::DEPLOY_LOCKED, $a['artifact_dir']);

        $this->assertSame(0, DB::connection('tool')->table('tol_master_deploy_run')->count());
        $this->assertSame([], $this->databaseList());
    }

    #[Test]
    public function dry_runはロックを取り実行記録とストレージと_sysを変えず一時の_dbを片付ける(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $this->becomeImportEnvironment();
        $lock = \Mockery::mock(MasterDeployLock::class);
        $lock->shouldReceive('acquire')->once()->andReturn(true);
        $lock->shouldReceive('release')->once();

        $result = (new MasterImportRunner(lock: $lock, invalidator: $this->invalidator))->run($a['artifact_dir'], ['start_at' => '2026-11-23 15:00:00', 'dry_run' => true]);

        $this->assertTrue($result['dry_run']);
        $this->assertNull($result['run_id']);
        $this->assertNull($result['deploy']['mst_database']);
        $this->assertSame(['I1', 'I2', 'I3', 'I4', 'I6', 'I5'], array_column($result['import']['check_list'], 'check'));
        $this->assertSame(0, DB::connection('tool')->table('tol_master_deploy_run')->count());
        $this->assertSame(0, DB::connection('sys')->table('sys_deploy_master')->count());
        $this->assertSame([], $this->databaseList(), '一時の DB は片付ける');
        $this->assertSame([], Storage::disk(MasterArtifactStore::DISK)->allFiles());
    }

    #[Test]
    public function dry_runでも検査で拒否され一時の_dbを残さない(): void
    {
        $a = $this->buildAndPublish(2026112201);
        $b = $this->buildAndPublish(2026112202, 'changed');
        $this->becomeImportEnvironment();
        Config::set('master_deploy.enforce_confirmed_rows', true);
        $this->runner()->run($a['artifact_dir'], ['start_at' => null]);

        $this->expectError(MasterDeployRunException::CONFIRMED_KEY_MODIFIED, $b['artifact_dir'], ['start_at' => null, 'dry_run' => true]);

        $this->assertSame([$a['mst_database']], $this->databaseList());
    }

    // ---- 補助 ----

    /**
     * @return array<string, mixed>
     */
    private function buildAndPublish(int $key, string $variant = 'a'): array
    {
        $deploy = $this->buildVersion($key, $variant);
        $this->publishUpstream($deploy['artifact_dir']);

        return $deploy;
    }

    private function runner(?MasterArtifactStore $store = null, ?MasterArtifactStore $upstream = null, ?MasterDeployService $deployService = null): MasterImportRunner
    {
        return new MasterImportRunner(upstream: $upstream, lock: $this->lock, invalidator: $this->invalidator, store: $store, deployService: $deployService);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function expectError(string $code, string $artifactDir, array $options = ['start_at' => null], ?MasterArtifactStore $store = null, ?MasterDeployService $deployService = null): MasterDeployRunException
    {
        try {
            $this->runner($store, null, $deployService)->run($artifactDir, $options);
        } catch (MasterDeployRunException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());

            return $e;
        }
        $this->fail("{$code} が投げられるはず");
    }

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
}

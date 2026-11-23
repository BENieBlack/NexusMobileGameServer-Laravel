<?php

namespace Tests\Feature;

use App\Exceptions\MasterDeployRunException;
use App\Services\MasterArtifactStore;
use App\Services\MasterArtifactVerifier;
use App\Services\MasterDeployKeyPolicy;
use App\Services\MasterDeployLock;
use App\Services\MasterDeploySelector;
use App\Services\MasterDeployTargetService;
use App\Services\MasterVersionDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * MasterDeployTargetService（target・artifact-list。api.md 4・5 節）のテスト。版は実物の build で作る（mst は実 MySQL の使い捨て DB）
 */
class MasterDeployTargetServiceTest extends MasterVersionedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->lock->shouldReceive('isFree')->andReturn(true)->byDefault();
        Cache::flush();
    }

    private function service(): MasterDeployTargetService
    {
        return new MasterDeployTargetService(
            MasterDeployKeyPolicy::fromConfig(),
            $this->lock,
            new MasterVersionDatabase,
            new MasterDeploySelector,
            new MasterArtifactStore,
            new MasterArtifactVerifier,
        );
    }

    /**
     * @param  array<string, mixed>  $list
     * @return list<string>
     */
    private function codes(array $list): array
    {
        return array_column($list, 'code');
    }

    #[Test]
    public function 版が無いときはno_current_versionで_buildの設定を返す(): void
    {
        $target = $this->service()->build(true);

        $this->assertSame('testing', $target['app_env']);
        $this->assertSame('build', $target['role']);
        $this->assertSame('explicit', $target['mode']);
        $this->assertSame(2026112201, $target['deploy_key']);
        $this->assertNull($target['blocked']);
        $this->assertTrue($target['spreadsheet_dir_configured']);
        $this->assertSame($this->baseDatabaseName(), $target['base_database']['name']);
        $this->assertSame(64, strlen((string) $target['base_database']['schema_fingerprint']));
        $this->assertFalse($target['running']);
        $this->assertSame('2026-11-23 12:00:00', $target['now']);
        $this->assertNull($target['current_version']);
        $this->assertSame([], $target['scheduled_list']);
        $this->assertSame([], $target['version_list']);
        $this->assertNull($target['last_run']);
        $this->assertSame('not_checked', $target['follow_check']['status']);
        $this->assertContains('no_current_version', $this->codes($target['warning_list']));
    }

    #[Test]
    public function いまの版_予約_版の一覧_直近のrunを返す(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $b = $this->buildVersion(2026112202, 'b', '2026-11-23 13:00:00');

        $target = $this->service()->build(true);

        $this->assertSame((int) $a['sys_deploy_id'], $target['current_version']['sys_deploy_id']);
        $this->assertSame($a['mst_database'], $target['current_version']['mst_database']);
        $this->assertTrue($target['current_version']['is_active']);
        $this->assertCount(1, $target['scheduled_list']);
        $this->assertSame((int) $b['sys_deploy_id'], $target['scheduled_list'][0]['sys_deploy_id']);
        $this->assertTrue($target['scheduled_list'][0]['is_active']);
        $this->assertSame([], $target['inactive_list']);
        $this->assertContains('scheduled_exists', $this->codes($target['warning_list']));

        // 新しい順。予約済みがあるので、どちらを戻し先にしても blocked_by_scheduled（a は予約 b が別の版を指す。b は近くない予約を自分が指す）
        $this->assertSame([(int) $b['sys_deploy_master_id'], (int) $a['sys_deploy_master_id']], array_column($target['version_list'], 'sys_deploy_master_id'));
        $versionB = $target['version_list'][0];
        $versionA = $target['version_list'][1];
        $this->assertTrue($versionA['is_current']);
        $this->assertFalse($versionB['is_current']);
        $this->assertSame('2026-11-23 10:00:00', $versionA['last_start_at']);
        $this->assertNull($versionB['last_start_at']);
        $this->assertTrue($versionA['schema_matches']);
        $this->assertSame('blocked_by_scheduled', $versionA['rollback_state']);
        $this->assertSame('blocked_by_scheduled', $versionB['rollback_state']);

        $this->assertSame('build', $target['last_run']['kind']);
        $this->assertSame('succeeded', $target['last_run']['status']);
    }

    #[Test]
    public function 予約が無ければ戻し先はokで_dbが無く成果物があればneeds_restore_成果物も無ければunavailable(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $b = $this->buildVersion(2026112202, 'b', '2026-11-23 11:00:00');
        $this->assertSame('ok', $this->service()->build(true)['version_list'][1]['rollback_state']);

        // a の DB を消して掃除済みにする: 成果物が残っていれば復元できる
        $this->dropDatabase($a['mst_database']);
        DB::connection('sys')->table('sys_deploy_master')->where('id', $a['sys_deploy_master_id'])->update(['mst_dropped_at' => '2026-11-23 11:30:00']);
        $this->assertSame('needs_restore', $this->service()->build(true)['version_list'][1]['rollback_state']);

        // 成果物も消すと復元できない
        Storage::disk(MasterArtifactStore::DISK)->deleteDirectory($a['artifact_dir']);
        $this->assertSame('unavailable', $this->service()->build(true)['version_list'][1]['rollback_state']);
        $this->assertSame('ok', $this->service()->build(true)['version_list'][0]['rollback_state']);
        $this->assertTrue($this->service()->build(true)['version_list'][0]['is_current']);
        $this->assertSame((int) $b['sys_deploy_master_id'], $this->service()->build(true)['version_list'][0]['sys_deploy_master_id']);
    }

    #[Test]
    public function 現在から60秒以内で戻し先と同じ版を指す予約は_blocked_by_scheduledに数えない(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $this->buildVersion(2026112202, 'b', '2026-11-23 11:00:00');
        // 巻き戻しの直後を再現: a を指す行が現在 + 1 秒にある
        DB::connection('sys')->table('sys_deploy')->insert([
            'deploy_key' => 2026112201, 'start_at' => '2026-11-23 12:00:01', 'sys_deploy_master_id' => $a['sys_deploy_master_id'],
            'sys_deploy_asset_id' => 1, 'is_active' => 1,
        ]);

        $list = $this->service()->build(true)['version_list'];

        $byMaster = array_column($list, null, 'sys_deploy_master_id');
        $this->assertSame('ok', $byMaster[(int) $a['sys_deploy_master_id']]['rollback_state']);
        // 別の版（b）を戻し先にする場合は、a を指す近い予約が数えられる
        $this->assertSame('blocked_by_scheduled', $byMaster[(int) DB::connection('sys')->table('sys_deploy_master')->where('deploy_key', 2026112202)->value('id')]['rollback_state']);
    }

    #[Test]
    public function 止めた行はinactive_listと警告に出て_いまの版から外れる(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $b = $this->buildVersion(2026112202, 'b', '2026-11-23 11:00:00');
        DB::connection('sys')->table('sys_deploy')->where('id', $b['sys_deploy_id'])->update(['is_active' => 0]);

        $target = $this->service()->build(true);

        $this->assertSame((int) $a['sys_deploy_id'], $target['current_version']['sys_deploy_id']);
        $this->assertSame([(int) $b['sys_deploy_id']], array_column($target['inactive_list'], 'sys_deploy_id'));
        $this->assertContains('inactive_exists', $this->codes($target['warning_list']));
    }

    #[Test]
    public function 設定の誤りはblockedに出る(): void
    {
        Config::set('master_deploy.explicit_key', null);
        $this->assertSame('deploy_key_not_configured', $this->service()->build(true)['blocked']['code']);

        Config::set('master_deploy.explicit_key', 7000000001);
        $this->assertSame('reserved_key_forbidden', $this->service()->build(true)['blocked']['code']);

        Config::set('master_deploy.explicit_key', 2026112201);
        Config::set('master_deploy.role', 'unknown');
        $target = $this->service()->build(true);
        $this->assertSame('role_not_configured', $target['blocked']['code']);
        $this->assertNull($target['follow_check']);

        Config::set('master_deploy.role', 'build');
        Config::set('app.name', 'otherapp');
        $this->assertSame('base_database_mismatch', $this->service()->build(true)['blocked']['code']);
        Config::set('app.name', str_repeat('n', 40));
        $this->assertSame('mst_database_name_too_long', $this->service()->build(true)['blocked']['code']);
    }

    #[Test]
    public function importの環境ではシートも_t_も出さず_上流の無い設定をblockedにする(): void
    {
        Config::set('master_deploy.role', 'import');
        $target = $this->service()->build(true);

        $this->assertNull($target['mode']);
        $this->assertNull($target['deploy_key']);
        $this->assertFalse($target['spreadsheet_dir_configured']);
        $this->assertNull($target['follow_check']);
        $this->assertNull($target['blocked']);

        Config::set('filesystems.disks.master_artifact_upstream', null);
        $this->assertSame('upstream_not_configured', $this->service()->build(true)['blocked']['code']);
    }

    #[Test]
    public function 実行中はrunningになる(): void
    {
        $lock = \Mockery::mock(MasterDeployLock::class);
        $lock->shouldReceive('isFree')->andReturn(false);
        $service = new MasterDeployTargetService(MasterDeployKeyPolicy::fromConfig(), $lock, new MasterVersionDatabase, new MasterDeploySelector, new MasterArtifactStore, new MasterArtifactVerifier);

        $this->assertTrue($service->build(true)['running']);
    }

    #[Test]
    public function 追従検出は直近のfinalizeの記録から出し_changedとunverifiedは警告になる(): void
    {
        $now = '2026-11-23 11:00:00';
        $insert = fn (array $summary) => DB::connection('tool')->table('tol_master_deploy_run')->insert([
            'kind' => 'finalize', 'status' => 'succeeded', 'phase' => 'done', 'app_env' => 'testing', 'started_at' => $now,
            'summary' => json_encode($summary),
        ]);
        $insert(['follow_check' => ['status' => 'match', 'checked_at' => $now, 'artifact_dir' => 'x', 'changed_deploy_key_list' => [], 'reason' => null]]);
        $this->assertSame('match', $this->service()->build(true)['follow_check']['status']);

        $insert(['follow_check' => ['status' => 'unverified', 'checked_at' => $now, 'artifact_dir' => 'x', 'changed_deploy_key_list' => [], 'reason' => 'sheet_read_failed'], 'allow_unverified' => true]);
        $target = $this->service()->build(true);
        $this->assertSame('unverified', $target['follow_check']['status']);
        $this->assertTrue($target['follow_check']['allow_unverified']);
        $this->assertSame('sheet_read_failed', $target['follow_check']['reason']);
        $this->assertContains('follow_check_unverified', $this->codes($target['warning_list']));

        $insert(['follow_check' => ['status' => 'changed', 'checked_at' => $now, 'artifact_dir' => 'x', 'changed_deploy_key_list' => [2026010101], 'reason' => null]]);
        $target = $this->service()->build(true);
        $this->assertSame([2026010101], $target['follow_check']['changed_deploy_key_list']);
        $this->assertContains('sheet_changed_since_build', $this->codes($target['warning_list']));
    }

    #[Test]
    public function 実行記録から_register_unknown_孤児_未完了_キャッシュ未破棄の警告を出す(): void
    {
        $run = fn (array $row) => DB::connection('tool')->table('tol_master_deploy_run')->insert([...['kind' => 'build', 'phase' => 'register', 'app_env' => 'testing', 'started_at' => '2026-11-23 11:59:00'], ...$row]);

        $orphan = $this->baseDatabaseName().'-2026112299-aaaaaaaaaaaaaaaa';
        DB::connection('build_test_admin')->statement('CREATE DATABASE `'.$orphan.'`');
        $run(['status' => 'failed', 'error_code' => 'register_failed', 'mst_database' => $orphan, 'summary' => json_encode(['created_database_list' => [$orphan]])]);
        $run(['status' => 'failed', 'error_code' => 'register_unknown', 'mst_database' => $this->baseDatabaseName().'-x', 'summary' => json_encode(['created_database_list' => []])]);
        $run(['status' => 'succeeded', 'phase' => 'done', 'finished_at' => '2026-11-23 11:59:30', 'summary' => json_encode(['cache_invalidated' => false])]);

        $codes = $this->codes($this->service()->build(true)['warning_list']);

        $this->assertContains('register_unknown', $codes);
        $this->assertContains('orphan_database', $codes);
        $this->assertContains('cache_not_invalidated', $codes);

        // 解決済みの register_unknown は警告から外れる。TTL を過ぎたキャッシュ未破棄も外れる
        DB::connection('tool')->table('tol_master_deploy_run')->where('error_code', 'register_unknown')->update(['summary' => json_encode(['resolved' => 'dropped'])]);
        DB::connection('tool')->table('tol_master_deploy_run')->where('status', 'succeeded')->update(['finished_at' => '2026-11-23 11:50:00']);
        $codes = $this->codes($this->service()->build(true)['warning_list']);
        $this->assertNotContains('register_unknown', $codes);
        $this->assertNotContains('cache_not_invalidated', $codes);
    }

    #[Test]
    public function 直近のrunがabortedで_dbが残っていればincomplete_run(): void
    {
        $left = $this->baseDatabaseName().'-2026112298-bbbbbbbbbbbbbbbb';
        DB::connection('build_test_admin')->statement('CREATE DATABASE `'.$left.'`');
        DB::connection('tool')->table('tol_master_deploy_run')->insert([
            'kind' => 'build', 'status' => 'aborted', 'phase' => 'version_db', 'app_env' => 'testing', 'started_at' => '2026-11-23 11:00:00', 'mst_database' => $left,
        ]);

        $this->assertContains('incomplete_run', $this->codes($this->service()->build(true)['warning_list']));
    }

    #[Test]
    public function 現行のスキーマと違う版はschema_stale_versionとschema_mismatch(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        DB::connection('sys')->table('sys_deploy_master')->where('id', $a['sys_deploy_master_id'])->update(['schema_fingerprint' => str_repeat('0', 64)]);

        $target = $this->service()->build(true);

        $this->assertFalse($target['version_list'][0]['schema_matches']);
        $this->assertSame('schema_mismatch', $target['version_list'][0]['rollback_state']);
        $this->assertContains('schema_stale_version', $this->codes($target['warning_list']));
    }

    #[Test]
    public function 基底dbのスキーマ版はキャッシュされる(): void
    {
        $first = $this->service()->build(true)['base_database']['schema_fingerprint'];
        $this->assertSame($first, Cache::get('tool:master:schema_fingerprint:'.$this->baseDatabaseName()));
        Cache::put('tool:master:schema_fingerprint:'.$this->baseDatabaseName(), 'cached-value', 60);

        $this->assertSame('cached-value', $this->service()->build(true)['base_database']['schema_fingerprint']);
    }

    // ---- artifact-list ----

    #[Test]
    public function artifact_listは自環境の成果物を新しい順に返し_finalized_imported_schema_matchesを付ける(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $b = $this->buildVersion(2026112202, 'b', '2026-11-23 11:00:00');
        $disk = Storage::disk(MasterArtifactStore::DISK);
        $manifestB = json_decode((string) $disk->get($b['artifact_dir'].'/manifest.json'), true);
        $disk->put($b['artifact_dir'].'/final.json', json_encode(['deploy_key' => 2026112202, 'hash' => $manifestB['hash']]));
        // 未完成（manifest.json が無い）は載せない
        $disk->put('2026112203_0123456789abcdef/data/x.ndjson', '');
        $disk->put('.tmp/x/y', '');

        $list = $this->service()->artifactList('own');

        $this->assertCount(2, $list);
        $byDir = array_column($list, null, 'artifact_dir');
        $this->assertTrue($byDir[$b['artifact_dir']]['finalized']);
        $this->assertFalse($byDir[$a['artifact_dir']]['finalized']);
        $this->assertTrue($byDir[$a['artifact_dir']]['imported']);
        $this->assertTrue($byDir[$a['artifact_dir']]['schema_matches']);
        $this->assertSame(2026112202, $byDir[$b['artifact_dir']]['deploy_key']);
        $this->assertSame('build', $byDir[$a['artifact_dir']]['source_kind']);
        $this->assertSame(['artifact_dir', 'deploy_key', 'hash', 'schema_fingerprint', 'built_env', 'built_at', 'source_kind', 'finalized', 'imported', 'schema_matches'], array_keys($list[0]));
    }

    #[Test]
    public function artifact_listの上流は上流のdiskを読み_imported_はfalse(): void
    {
        $a = $this->buildVersion(2026112201, 'a', '2026-11-23 10:00:00');
        $this->publishUpstream($a['artifact_dir']);
        $this->becomeImportEnvironment();

        $list = $this->service()->artifactList('upstream');

        $this->assertSame([$a['artifact_dir']], array_column($list, 'artifact_dir'));
        $this->assertFalse($list[0]['imported']);
        $this->assertSame([], $this->service()->artifactList('own'));
    }

    #[Test]
    public function 上流のdiskが無ければupstream_not_configured(): void
    {
        Config::set('filesystems.disks.master_artifact_upstream', null);

        try {
            $this->service()->artifactList('upstream');
            $this->fail('例外が投げられていない');
        } catch (MasterDeployRunException $e) {
            $this->assertSame('upstream_not_configured', $e->errorCode);
            $this->assertSame(500, $e->httpStatus);
        }
    }
}

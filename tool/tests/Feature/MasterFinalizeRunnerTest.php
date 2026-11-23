<?php

namespace Tests\Feature;

use App\Exceptions\MasterDeployRunException;
use App\Services\MasterArtifactStore;
use App\Services\MasterBuildRunner;
use App\Services\MasterDeployLock;
use App\Services\MasterFinalizeRunner;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * MasterFinalizeRunner（QA 完了の印 final.json。db.md 6.5.1・6.7 の R28）のテスト
 */
class MasterFinalizeRunnerTest extends MasterVersionedTestCase
{
    #[Test]
    public function 追従検出が一致すればfinal_jsonを書き_manifestとhashは変わらない(): void
    {
        $a = $this->buildVersion(2026112201);
        $disk = Storage::disk(MasterArtifactStore::DISK);
        $manifestBefore = $disk->get($a['artifact_dir'].'/manifest.json');

        $result = $this->runner($this->digestOf($a['artifact_dir']))->run($a['artifact_dir'], ['adm_account_id' => 5]);

        $this->assertSame('finalized', $result['code']);
        $this->assertSame('match', $result['follow_check']['status']);
        $final = json_decode($disk->get($a['artifact_dir'].'/final.json'), true);
        $this->assertSame(2026112201, $final['deploy_key']);
        $this->assertSame($a['hash'], $final['hash']);
        $this->assertSame('2026-11-23 12:00:00', $final['finalized_at']);
        $this->assertSame($this->envName(), $final['finalized_by_env']);
        $this->assertSame($manifestBefore, $disk->get($a['artifact_dir'].'/manifest.json'));
        $run = $this->lastRun();
        $this->assertSame('finalize', $run->kind);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('done', $run->phase);
        $this->assertSame(5, (int) $run->adm_account_id);
        $this->assertSame('match', $this->summaryOf($run)['follow_check']['status']);
    }

    #[Test]
    public function 実物のbuildの畳み直しでrow_digestが一致する(): void
    {
        $a = $this->buildVersion(2026112201);
        $sheets = $this->lastSheetFactory();
        $provider = static fn (int $key): array => (new MasterBuildRunner(sheetServiceFactory: $sheets))->computeRowDigest($key);

        $result = $this->runner($provider)->run($a['artifact_dir']);

        $this->assertSame('match', $result['follow_check']['status']);
        $this->assertSame([], $this->databaseList() === [$a['mst_database']] ? [] : ['作業用 DB が残っている']);
    }

    #[Test]
    public function すでに一致するfinal_jsonがあれば何も書かずalready_finalizedで追従検出もしない(): void
    {
        $a = $this->buildVersion(2026112201);
        $this->runner($this->digestOf($a['artifact_dir']))->run($a['artifact_dir']);
        $final = Storage::disk(MasterArtifactStore::DISK)->get($a['artifact_dir'].'/final.json');
        $neverCalled = static function (): array {
            throw new \LogicException('シートを読んではいけない');
        };

        $result = $this->runner($neverCalled)->run($a['artifact_dir']);

        $this->assertSame('already_finalized', $result['code']);
        $this->assertNull($result['follow_check']);
        $this->assertSame($final, Storage::disk(MasterArtifactStore::DISK)->get($a['artifact_dir'].'/final.json'));
        $this->assertSame('succeeded', $this->lastRun()->status);
    }

    #[Test]
    public function 別の成果物の印や壊れた印があればfinalize_conflictで上書きしない(): void
    {
        $a = $this->buildVersion(2026112201);
        Storage::disk(MasterArtifactStore::DISK)->put($a['artifact_dir'].'/final.json', json_encode(['deploy_key' => 2026112201, 'hash' => str_repeat('0', 64)]));

        $this->expectError(MasterDeployRunException::FINALIZE_CONFLICT, $a['artifact_dir'], $this->digestOf($a['artifact_dir']));

        $this->assertStringContainsString(str_repeat('0', 64), Storage::disk(MasterArtifactStore::DISK)->get($a['artifact_dir'].'/final.json'));

        Storage::disk(MasterArtifactStore::DISK)->put($a['artifact_dir'].'/final.json', 'broken');
        $this->expectError(MasterDeployRunException::FINALIZE_CONFLICT, $a['artifact_dir'], $this->digestOf($a['artifact_dir']));
    }

    #[Test]
    public function シートが変わっていればsheet_changed_since_buildでallow_unverifiedでも通さず印も付けない(): void
    {
        $a = $this->buildVersion(2026112201);
        $changed = static fn (): array => [['deploy_key' => 2026010101, 'digest' => str_repeat('f', 64)]];

        $error = $this->expectError(MasterDeployRunException::SHEET_CHANGED_SINCE_BUILD, $a['artifact_dir'], $changed, ['allow_unverified' => true]);

        $this->assertSame([2026010101], $error->extra['changed_deploy_key_list']);
        $this->assertFalse(Storage::disk(MasterArtifactStore::DISK)->exists($a['artifact_dir'].'/final.json'));
        $run = $this->lastRun();
        $this->assertSame('failed', $run->status);
        $this->assertSame('check', $run->phase);
        $this->assertSame('changed', $this->summaryOf($run)['follow_check']['status']);
    }

    #[Test]
    public function シートを読めなければfollow_check_unavailableで_allow_unverifiedなら警告で通し実行記録に残す(): void
    {
        $a = $this->buildVersion(2026112201);
        $unreadable = static function (): array {
            throw new MasterDeployRunException(MasterDeployRunException::SHEET_NOT_CONFIGURED, 'no sheet', 422);
        };

        $this->expectError(MasterDeployRunException::FOLLOW_CHECK_UNAVAILABLE, $a['artifact_dir'], $unreadable);
        $this->assertFalse(Storage::disk(MasterArtifactStore::DISK)->exists($a['artifact_dir'].'/final.json'));

        $result = $this->runner($unreadable)->run($a['artifact_dir'], ['allow_unverified' => true, 'adm_account_id' => 8]);

        $this->assertSame('finalized', $result['code']);
        $this->assertSame('unverified', $result['follow_check']['status']);
        $this->assertContains('follow_check_unverified', array_column($result['warning_list'], 'code'));
        $run = $this->lastRun();
        $summary = $this->summaryOf($run);
        $this->assertSame('unverified', $summary['follow_check']['status']);
        $this->assertSame('sheet_not_configured', $summary['follow_check']['reason']);
        $this->assertTrue($summary['allow_unverified']);
        $this->assertSame(8, (int) $run->adm_account_id);
        $final = json_decode(Storage::disk(MasterArtifactStore::DISK)->get($a['artifact_dir'].'/final.json'), true);
        $this->assertArrayNotHasKey('unverified', $final, '印の中身は変えない');
    }

    #[Test]
    public function sheet_invalidは読み替えずそのまま返す(): void
    {
        $a = $this->buildVersion(2026112201);
        $invalid = static function (): array {
            throw new MasterDeployRunException(MasterDeployRunException::SHEET_INVALID, 'bad', 422);
        };

        $this->expectError(MasterDeployRunException::SHEET_INVALID, $a['artifact_dir'], $invalid, ['allow_unverified' => true]);
    }

    #[Test]
    public function 予約帯の成果物やtと違う成果物は拒否する(): void
    {
        $fixed = $this->buildVersion(9999999999, 'a', null, [], true);
        Config::set('master_deploy.fixed_key', null);
        Config::set('master_deploy.explicit_key', 2026112201);
        $this->expectError(MasterDeployRunException::RESERVED_KEY_FORBIDDEN, $fixed['artifact_dir'], $this->digestOf($fixed['artifact_dir']));

        $b = $this->buildVersion(2026112202);
        Config::set('master_deploy.explicit_key', 2026112201);
        $this->expectError(MasterDeployRunException::DEPLOY_KEY_MISMATCH, $b['artifact_dir'], $this->digestOf($b['artifact_dir']));
    }

    #[Test]
    public function importの環境や成果物の無いときや改ざんされた成果物は拒否する(): void
    {
        $a = $this->buildVersion(2026112201);
        $this->expectError(MasterDeployRunException::ARTIFACT_NOT_FOUND, '2026112201_'.str_repeat('a', 16), $this->digestOf($a['artifact_dir']));

        Storage::disk(MasterArtifactStore::DISK)->put($a['artifact_dir'].'/data/mst_item.ndjson', "{}\n");
        $this->expectError(MasterDeployRunException::ARTIFACT_INVALID, $a['artifact_dir'], $this->digestOf($a['artifact_dir']));

        Config::set('master_deploy.role', 'import');
        try {
            $this->runner($this->digestOf($a['artifact_dir']))->run($a['artifact_dir']);
            $this->fail('role_forbidden が投げられるはず');
        } catch (MasterDeployRunException $e) {
            $this->assertSame(MasterDeployRunException::ROLE_FORBIDDEN, $e->errorCode);
        }
    }

    #[Test]
    public function final_jsonを書けなければstore_failed(): void
    {
        $a = $this->buildVersion(2026112201);
        $failing = new class extends MasterArtifactStore
        {
            public function writeFile(string $artifactDir, string $name, string $contents): void
            {
                throw new \RuntimeException('disk full');
            }
        };

        $this->expectError(MasterDeployRunException::STORE_FAILED, $a['artifact_dir'], $this->digestOf($a['artifact_dir']), [], $failing);

        $this->assertFalse(Storage::disk(MasterArtifactStore::DISK)->exists($a['artifact_dir'].'/final.json'));
        $this->assertSame('register', $this->lastRun()->phase);
    }

    #[Test]
    public function dry_runはロックも実行記録も使わず印を付けない(): void
    {
        $a = $this->buildVersion(2026112201);
        $runs = DB::connection('tool')->table('tol_master_deploy_run')->count();
        $lock = \Mockery::mock(MasterDeployLock::class);
        $lock->shouldNotReceive('acquire');

        $result = (new MasterFinalizeRunner($this->digestOf($a['artifact_dir']), lock: $lock, invalidator: $this->invalidator))->run($a['artifact_dir'], ['dry_run' => true]);

        $this->assertTrue($result['dry_run']);
        $this->assertSame('match', $result['follow_check']['status']);
        $this->assertNull($result['run_id']);
        $this->assertSame($runs, DB::connection('tool')->table('tol_master_deploy_run')->count());
        $this->assertFalse(Storage::disk(MasterArtifactStore::DISK)->exists($a['artifact_dir'].'/final.json'));
    }

    #[Test]
    public function ロックが取れなければdeploy_locked(): void
    {
        $a = $this->buildVersion(2026112201);
        $this->lock->shouldReceive('acquire')->andReturn(false);

        $this->expectError(MasterDeployRunException::DEPLOY_LOCKED, $a['artifact_dir'], $this->digestOf($a['artifact_dir']));

        $this->assertFalse(Storage::disk(MasterArtifactStore::DISK)->exists($a['artifact_dir'].'/final.json'));
    }

    // ---- 補助 ----

    /**
     * マニフェストの row_digest_list をそのまま返す（追従検出が一致する）
     *
     * @return Closure(int): list<array{deploy_key: int, digest: string}>
     */
    private function digestOf(string $artifactDir): Closure
    {
        return static function (int $deployKey) use ($artifactDir): array {
            $manifest = json_decode((string) Storage::disk(MasterArtifactStore::DISK)->get($artifactDir.'/manifest.json'), true);

            return array_map(static fn (array $i): array => ['deploy_key' => (int) $i['deploy_key'], 'digest' => (string) $i['digest']], (array) ($manifest['row_digest_list'] ?? []));
        };
    }

    private function runner(Closure $provider, ?MasterArtifactStore $store = null): MasterFinalizeRunner
    {
        return new MasterFinalizeRunner($provider, lock: $this->lock, invalidator: $this->invalidator, store: $store);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function expectError(string $code, string $artifactDir, Closure $provider, array $options = [], ?MasterArtifactStore $store = null): MasterDeployRunException
    {
        try {
            $this->runner($provider, $store)->run($artifactDir, $options);
        } catch (MasterDeployRunException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());

            return $e;
        }
        $this->fail("{$code} が投げられるはず");
    }
}

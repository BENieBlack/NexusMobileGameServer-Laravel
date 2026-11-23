<?php

namespace Tests\Feature;

use App\Exceptions\MasterDeployRunException;
use App\Services\MasterDeploySelector;
use App\Services\MasterRollbackService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * 本番ガード（APP_ENV=production。db.md 3.3・3.6・7.3）: 予約帯の版は最新に選ばず、巻き戻しでも戻さない
 */
class MasterProductionGuardTest extends MasterVersionedTestCase
{
    protected function envName(): string
    {
        return 'production';
    }

    #[Test]
    public function 本番では予約帯の行を最新に選ばない(): void
    {
        $real = $this->insertVersion(2026112201, '2026-11-23 10:00:00');
        $this->insertVersion(9999999999, '2026-11-23 11:00:00');

        $this->assertSame($real, (int) (new MasterDeploySelector)->latest('2026-11-23 12:00:00')?->id);
    }

    #[Test]
    public function 本番では予約帯の版へ巻き戻せずreserved_key_forbidden(): void
    {
        $this->insertVersion(2026112201, '2026-11-23 10:00:00');
        $reservedMaster = $this->insertMaster(9999999999);

        try {
            (new MasterRollbackService(lock: $this->lock, invalidator: $this->invalidator))->run($reservedMaster);
            $this->fail('reserved_key_forbidden が投げられるはず');
        } catch (MasterDeployRunException $e) {
            $this->assertSame(MasterDeployRunException::RESERVED_KEY_FORBIDDEN, $e->errorCode);
        }
        $this->assertSame(1, DB::connection('sys')->table('sys_deploy')->count());
    }

    private function insertMaster(int $deployKey): int
    {
        return (int) DB::connection('sys')->table('sys_deploy_master')->insertGetId([
            'deploy_key' => $deployKey, 'hash' => str_pad((string) $deployKey, 64, '0'), 'deploy_date' => '2026-11-23', 'deploy_count' => 0, 'status' => 'completed',
            'mst_database' => $this->appName.'-production-master-'.$deployKey.'-x', 'artifact_dir' => $deployKey.'_x', 'schema_fingerprint' => 'x', 'source_kind' => 'build',
        ]);
    }

    private function insertVersion(int $deployKey, string $startAt): int
    {
        return (int) DB::connection('sys')->table('sys_deploy')->insertGetId([
            'deploy_key' => $deployKey, 'start_at' => $startAt, 'sys_deploy_master_id' => $this->insertMaster($deployKey), 'sys_deploy_asset_id' => 1, 'is_active' => 1,
        ]);
    }
}

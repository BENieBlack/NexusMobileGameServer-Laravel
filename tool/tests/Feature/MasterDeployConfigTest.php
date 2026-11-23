<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * tool/config/master_deploy.php の版ごとの mst DB 設計の設定キー（db.md 7.2）が揃っていることの確認。
 */
class MasterDeployConfigTest extends TestCase
{
    public function test_設定キーが揃い既定値が設計どおり(): void
    {
        $config = config('master_deploy');

        foreach ([
            'explicit_key', 'role', 'reject_past_start_at', 'start_at_past_grace', 'enforce_confirmed_rows',
            'require_finalized', 'keep_version_count', 'orphan_grace_hours', 'schedule_ttl',
        ] as $key) {
            $this->assertArrayHasKey($key, $config, $key);
        }

        $this->assertSame(3, $config['keep_version_count']);
        $this->assertSame(24, $config['orphan_grace_hours']);
        $this->assertSame(60, $config['schedule_ttl']);
        $this->assertSame(60, $config['start_at_past_grace']);
        $this->assertContains($config['role'], ['build', 'import']);
        // testing 環境（production・import 環境ではない）
        $this->assertFalse($config['reject_past_start_at']);
        $this->assertFalse($config['enforce_confirmed_rows']);
        $this->assertFalse($config['require_finalized']);
    }
}

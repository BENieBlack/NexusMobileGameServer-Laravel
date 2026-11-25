<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Nexus\Core\Pitr\Support\ShardMigrationPaths;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * trx:rollback が shard ディレクトリのマイグレーションを実際に見つけられることのテスト
 *
 * --pretend で SQL を出すだけにするので、テスト DB のテーブルは落とさない。
 * パスを渡さないと、記録済みのマイグレーションが見つからず
 * "Migration not found" で何も戻さずに成功終了してしまう。
 */
class TrxRollbackResolvesShardMigrationsTest extends TestCase
{
    #[Test]
    public function shardのパスを渡すと記録済みのマイグレーションを見つけて戻す_sq_lを出す(): void
    {
        $exitCode = Artisan::call('migrate:rollback', [
            '--database' => 'trx1',
            '--path' => ShardMigrationPaths::find(),
            '--step' => 1000,
            '--pretend' => true,
            '--force' => true,
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('Migration not found', $output);
        // trx 側と log 側の両方のマイグレーションが対象になる
        $this->assertStringContainsString('create_core_tables', $output);
        $this->assertStringContainsString('create_core_log_tables', $output);
    }
}

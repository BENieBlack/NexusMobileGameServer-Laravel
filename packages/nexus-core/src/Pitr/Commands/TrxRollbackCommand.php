<?php

namespace Nexus\Core\Pitr\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Nexus\Core\Pitr\Logger\ShardMapper;
use Nexus\Core\Pitr\Support\ShardMigrationPaths;

/**
 * TrxRollbackCommand
 *
 * すべてのシャード（trx1, trx2, ...）に対してマイグレーションをロールバック
 * trx と log は同じシャードDBに同居しているので、両方のテーブルがこの1回で戻る
 * 動的シャーディング対応（DB_SHARD_COUNTに応じてtrx1, trx2, ...に実行）
 */
class TrxRollbackCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trx:rollback 
                            {--force : Force the operation to run when in production}
                            {--step=1 : Number of migrations to rollback}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rollback shard (trx and log tables) migrations on all shards dynamically';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $trxConnections = ShardMapper::allTrxConnections();

        // パスはpackages/を走査して集める（trx と log のテーブルを含む shard ディレクトリ）
        $migrationPaths = ShardMigrationPaths::find();

        $this->info('Rolling back shard migrations on all shards...');
        $this->info('Migration paths: '.implode(', ', $migrationPaths));
        $this->newLine();

        foreach ($trxConnections as $trxConnection) {
            $this->info("📦 Rolling back shard: {$trxConnection}");

            $options = [
                '--database' => $trxConnection,
                '--path' => $migrationPaths,
                '--step' => $this->option('step'),
            ];

            if ($this->option('force')) {
                $options['--force'] = true;
            }

            $exitCode = Artisan::call('migrate:rollback', $options, $this->getOutput());

            if ($exitCode !== 0) {
                $this->error("❌ Rollback failed for {$trxConnection}");

                return self::FAILURE;
            }

            $this->info("✅ Rollback completed for {$trxConnection}");
            $this->newLine();
        }

        $this->info('🎉 All shard rollbacks completed successfully!');

        return self::SUCCESS;
    }
}

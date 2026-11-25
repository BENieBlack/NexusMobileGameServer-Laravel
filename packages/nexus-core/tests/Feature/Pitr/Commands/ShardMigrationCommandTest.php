<?php

namespace Nexus\Core\Tests\Feature\Pitr\Commands;

use Illuminate\Support\Facades\Artisan;
use Nexus\Core\Pitr\Commands\TrxMigrateCommand;
use Nexus\Core\Pitr\Commands\TrxRollbackCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * trx:migrate / trx:rollback のテスト（trx と log は同じシャードDBなので、どちらもこの2つで流す）
 *
 * 実際にマイグレーションを流すとテスト用DBを壊すため、
 * 内部で呼ぶ Artisan::call を差し替えて、どのシャードへ
 * どのオプションを渡しているかを検証する。
 *
 * シャード数は DB_SHARD_COUNT で決まる。テストは2シャード前提
 * （phpunit.xml と CI の両方で 2 に揃えてある）。
 */
class ShardMigrationCommandTest extends TestCase
{
    /** @var list<array{0: string, 1: array<string, mixed>}> Artisan::call の呼び出し記録 */
    private array $calls = [];

    private int $exitCodeToReturn = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calls = [];
        $this->exitCodeToReturn = 0;

        Artisan::shouldReceive('call')->andReturnUsing(function (string $command, array $options = []) {
            $this->calls[] = [$command, $options];

            return $this->exitCodeToReturn;
        });
    }

    #[Test]
    public function trx_migrateは全trxシャードにマイグレーションを流す(): void
    {
        $output = new BufferedOutput;

        $this->assertSame(TrxMigrateCommand::SUCCESS, $this->runCommand(TrxMigrateCommand::class, [], $output));

        $this->assertSame(['trx1', 'trx2'], array_column(array_column($this->calls, 1), '--database'));
        $this->assertSame(['migrate', 'migrate'], array_column($this->calls, 0));

        // shardサブディレクトリだけを対象にする（trx と log のテーブルを両方作る）
        foreach ($this->calls[0][1]['--path'] as $path) {
            $this->assertStringEndsWith('database/migrations/shard', $path);
        }

        $this->assertStringContainsString('Target shards: trx1, trx2', $output->fetch());
    }

    #[Test]
    public function trx_migrateはオプションを転送する(): void
    {
        $this->runCommand(TrxMigrateCommand::class, ['--force' => true, '--seed' => true, '--step' => true]);

        $options = $this->calls[0][1];
        $this->assertTrue($options['--force']);
        $this->assertTrue($options['--seed']);
        $this->assertTrue($options['--step']);
    }

    #[Test]
    public function trx_migrateは失敗したシャードで打ち切る(): void
    {
        $this->exitCodeToReturn = 1;
        $output = new BufferedOutput;

        $this->assertSame(TrxMigrateCommand::FAILURE, $this->runCommand(TrxMigrateCommand::class, [], $output));

        // 1つ目で失敗したら後続のシャードには進まない
        $this->assertCount(1, $this->calls);
        $this->assertStringContainsString('Migration failed for trx1', $output->fetch());
    }

    #[Test]
    public function trx_rollbackは段数を指定して全シャードを戻す(): void
    {
        $this->assertSame(
            TrxRollbackCommand::SUCCESS,
            $this->runCommand(TrxRollbackCommand::class, ['--step' => 2, '--force' => true])
        );

        $this->assertSame(['migrate:rollback', 'migrate:rollback'], array_column($this->calls, 0));
        $this->assertSame(['trx1', 'trx2'], array_column(array_column($this->calls, 1), '--database'));
        $this->assertSame(2, $this->calls[0][1]['--step']);

        // shard の全パスを渡す（渡さないと ServiceProvider の読むパス次第で何も戻らない）
        $this->assertNotEmpty($this->calls[0][1]['--path']);

        foreach ($this->calls[0][1]['--path'] as $path) {
            $this->assertStringEndsWith('database/migrations/shard', $path);
        }
        $this->assertTrue($this->calls[0][1]['--force']);
    }

    #[Test]
    public function trx_migrateのfreshはmigrate_freshで全テーブルを作り直す(): void
    {
        $this->assertSame(TrxMigrateCommand::SUCCESS, $this->runCommand(TrxMigrateCommand::class, ['--fresh' => true]));

        // 1回の migrate:fresh に shard の全パス（log のテーブルを含む）を渡す。後から log を足す必要はない
        $this->assertSame(['migrate:fresh', 'migrate:fresh'], array_column($this->calls, 0));

        foreach ($this->calls as [, $options]) {
            $this->assertContains('database/migrations/shard', $options['--path']);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function runCommand(string $commandClass, array $options = [], ?BufferedOutput $output = null): int
    {
        $command = $this->app->make($commandClass);
        $command->setLaravel($this->app);

        $input = new ArrayInput($options);
        $input->setInteractive(false);

        return $command->run($input, $output ?? new BufferedOutput);
    }
}

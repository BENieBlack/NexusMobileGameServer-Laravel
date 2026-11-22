<?php

namespace Tests\Unit\Repositories;

use App\Models\Sys\SysGuildApply;
use App\Repositories\Sys\SysGuildApplyRepository;
use App\Repositories\Sys\SysPlayerRepository;
use App\Repositories\Trx\TrxMailboxRepository;
use Illuminate\Support\Facades\DB;
use NexusGuild\Constants\GuildApplyStatus;
use Tests\RefreshMultipleDatabases;
use Tests\TestCase;

/**
 * 行ロック（selectForUpdate）の共通の振る舞いのテスト
 *
 * Sys / Trx の基底で例外メッセージと検査が揃っていること、
 * ロック読みがキャッシュの同じ行を差し替えることを固定する。
 */
class RowLockTransactionGuardTest extends TestCase
{
    use RefreshMultipleDatabases;

    public function test_sys_row_lock_outside_transaction_throws_unified_message(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('行ロックはトランザクションの中で呼ぶこと（接続: sys）');

        $this->outsideTransaction('sys', fn () => (new SysPlayerRepository)->selectByIdForUpdate(1));
    }

    public function test_trx_row_lock_outside_transaction_throws_unified_message(): void
    {
        $repository = new TrxMailboxRepository;
        $repository->setConnection('trx1');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('行ロックはトランザクションの中で呼ぶこと（接続: trx1）');

        $this->outsideTransaction('trx1', fn () => $repository->selectByIdForUpdate(1));
    }

    public function test_row_lock_inside_transaction_replaces_cached_model(): void
    {
        $apply = SysGuildApply::create([
            'sys_guild_id' => 8201,
            'sys_player_id' => 9201,
            'status' => GuildApplyStatus::APPLIED,
        ]);
        $repository = new SysGuildApplyRepository;
        $repository->setModel($apply);

        DB::connection('sys')->table('sys_guild_apply')->where('id', $apply->id)->update(['status' => GuildApplyStatus::ACCEPTED]);

        DB::connection('sys')->transaction(function () use ($repository, $apply): void {
            $locked = $repository->selectByIdForUpdate($apply->id);

            $this->assertSame(GuildApplyStatus::ACCEPTED, $locked?->status);
        });

        // ロック読みが DB の最新値を返すだけでは差し替えの検査にならないので、
        // キャッシュ側のモデルが最新値になっていることを直接見る
        $cached = (new \ReflectionProperty($repository, 'models'))->getValue($repository)->get((string) $apply->id);
        $this->assertSame(GuildApplyStatus::ACCEPTED, $cached->status);
    }

    /**
     * テスト基盤が全接続を包んでいるトランザクションを一時的に外して実行する
     *
     * @param  callable(): mixed  $callback
     */
    private function outsideTransaction(string $connection, callable $callback): void
    {
        $db = DB::connection($connection);
        $level = $db->transactionLevel();

        for ($i = 0; $i < $level; $i++) {
            $db->rollBack();
        }

        try {
            $callback();
        } finally {
            for ($i = 0; $i < $level; $i++) {
                $db->beginTransaction();
            }
        }
    }
}

<?php

namespace App\Repositories\Sys;

use App\Models\Sys\SysGuildApply;
use App\Models\Sys\SysGuildMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use NexusGuild\Constants\GuildApplyStatus;

/**
 * SysGuildApplyRepository
 *
 * ギルド加入申請のRepository実装
 *
 * @extends _BaseSysRepository<SysGuildApply>
 */
class SysGuildApplyRepository extends _BaseSysRepository
{
    protected string $modelClass = SysGuildApply::class;

    // ========================================
    // Application層専用メソッド（Model返却）
    // ========================================

    /**
     * 自分が出した申請と、自分が所属するギルド宛の申請を読む
     *
     * 未所属なら前者だけ、マスター/サブマスターなら後者も要る。
     * 承認・却下は他人が出した申請を書き換えるが、
     * 自分のギルド宛である限り「自分に関係する情報」として扱う。
     *
     * @param  Builder<SysGuildApply>  $query
     */
    protected function applySelectScope(Builder $query, int $sysPlayerId): void
    {
        $query->where(function (Builder $builder) use ($sysPlayerId) {
            $builder->where('sys_player_id', $sysPlayerId)
                ->orWhereIn('sys_guild_id', SysGuildMember::query()
                    ->where('sys_player_id', $sysPlayerId)
                    ->select('sys_guild_id'));
        });
    }

    /**
     * 申請を FOR UPDATE で読み直す（承認・却下の排他）
     *
     * 申請の status は申請者ではなく承認者・却下者（他人）が書く。
     * 承認と却下が同時に来ても、先にこの行を押さえた側だけが進める。
     * 後から来た側はロックが外れた後の最新の status を読むので、
     * 「承認済みなのに却下した」ができない。
     *
     * 既にキャッシュに載っている同じ行は、最新の値で差し替える
     * （古い値のまま setModel() して他人の更新を巻き戻さないため）。
     * キャッシュが無い場合は、後から読み込まれてよい（書き込みは dirty の status だけで、
     * 判定に使うのはこのロック読みの値だから）。
     *
     * @param  int  $applyId  申請ID
     */
    public function selectByIdForUpdate(int $applyId): ?SysGuildApply
    {
        /** @var SysGuildApply|null $apply */
        $apply = $this->selectForUpdate()->find($applyId);

        $this->replaceCachedModelIfPresent($applyId, $apply);

        return $apply;
    }

    /**
     * プレイヤーの全申請を、ID昇順で FOR UPDATE して読む（脱退の排他）
     *
     * 申請行は承認者が書く（他人が書く行）ので、脱退が削除する前に押さえる。
     * 複数行なので主キー昇順に取る。該当行が無いときはギャップロックになるが、
     * 申請を挿入するのは本人（player.lock で直列）だけなので、他人とは競合しない。
     *
     * @param  int  $playerId  プレイヤーID
     * @return Collection<array-key, SysGuildApply>
     */
    public function selectAllByPlayerIdForUpdate(int $playerId): Collection
    {
        return $this->selectForUpdate()
            ->where('sys_player_id', $playerId)
            ->orderBy('id')
            ->get();
    }

    /**
     * ギルドIDとプレイヤーIDで既存の申請を検索（Model返却）
     *
     * @param  int  $guildId  ギルドID
     * @param  int  $playerId  プレイヤーID
     */
    public function selectByGuildAndPlayer(int $guildId, int $playerId): ?SysGuildApply
    {
        if ($this->isSessionPlayer($playerId)) {
            /** @var SysGuildApply|null */
            return $this->queryOrMemory()->first(
                fn (SysGuildApply $apply) => $apply->getSysGuildId() === $guildId
                    && $apply->getSysPlayerId() === $playerId
                    && in_array($apply->getStatus(), [GuildApplyStatus::APPLIED, GuildApplyStatus::ACCEPTED], true)
            );
        }

        /** @var SysGuildApply|null */
        return $this->selectWithoutCache()
            ->where('sys_guild_id', $guildId)
            ->where('sys_player_id', $playerId)
            ->whereIn('status', [GuildApplyStatus::APPLIED, GuildApplyStatus::ACCEPTED])
            ->first();
    }

    /**
     * ギルドIDに関連する申請一覧を取得（Model返却）
     *
     * @param  int  $guildId  ギルドID
     * @return Collection<array-key, SysGuildApply>
     */
    public function selectAppliesByGuildId(int $guildId): Collection
    {
        if ($this->isOwnGuild($guildId)) {
            /** @var Collection<array-key, SysGuildApply> $applies */
            $applies = new Collection(
                $this->queryOrMemory()
                    ->filter(fn (SysGuildApply $apply) => $apply->getSysGuildId() === $guildId
                        && $apply->getStatus() === GuildApplyStatus::APPLIED)
                    ->values()
                    ->all()
            );

            return $applies;
        }

        return $this->selectWithoutCache()
            ->where('sys_guild_id', $guildId)
            ->where('status', GuildApplyStatus::APPLIED)
            ->get();
    }

    /**
     * プレイヤーIDに関連する申請一覧を取得（Model返却）
     *
     * @param  int  $playerId  プレイヤーID
     * @return Collection<array-key, SysGuildApply>
     */
    public function selectAppliesByPlayerId(int $playerId): Collection
    {
        if ($this->isSessionPlayer($playerId)) {
            /** @var Collection<array-key, SysGuildApply> $applies */
            $applies = new Collection(
                $this->queryOrMemory()
                    ->filter(fn (SysGuildApply $apply) => $apply->getSysPlayerId() === $playerId
                        && $apply->getStatus() === GuildApplyStatus::APPLIED)
                    ->values()
                    ->all()
            );

            return $applies;
        }

        return $this->selectWithoutCache()
            ->where('sys_player_id', $playerId)
            ->where('status', GuildApplyStatus::APPLIED)
            ->get();
    }

    /**
     * 申請を作成（Model返却）
     *
     * @param  int  $guildId  ギルドID
     * @param  int  $playerId  プレイヤーID
     */
    public function insertApply(int $guildId, int $playerId): SysGuildApply
    {
        $apply = new SysGuildApply;
        $apply->setSysGuildId($guildId);
        $apply->setSysPlayerId($playerId);
        $apply->setStatus(GuildApplyStatus::APPLIED);
        $apply->exists = false;
        $this->setModel($apply);

        // 呼び出し元が採番済みの申請IDを参照するため、ここでフラッシュする
        $this->flushQueue();

        return $apply;
    }

    /**
     * ログイン中プレイヤーが所属するギルドかどうか
     */
    private function isOwnGuild(int $guildId): bool
    {
        if (! $this->hasSelfScope()) {
            return false;
        }

        return SysGuildMember::query()
            ->where('sys_player_id', static::getSysPlayerId())
            ->where('sys_guild_id', $guildId)
            ->exists();
    }
}

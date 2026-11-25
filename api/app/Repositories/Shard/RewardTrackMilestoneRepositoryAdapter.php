<?php

namespace App\Repositories\Shard;

use App\Adapters\RewardTrack\RewardTrackMilestoneAdapter;
use Illuminate\Support\Facades\DB;
use Nexus\Core\Utilities\ClockUtility;
use NexusRewardTrack\DataTransferObjects\RewardTrackMilestone;
use NexusRewardTrack\Repositories\RewardTrackMilestoneRepositoryInterface;

/**
 * RewardTrackMilestoneRepositoryAdapter
 *
 * trx_reward_track_milestone（マイルストーン受取状態）の永続化。
 * パッケージ契約（RewardTrackMilestoneRepositoryInterface）の実装。契約単位のため Model と 1 対 1 の規約の対象外（*RepositoryAdapter）。
 * 読み書きは接続を指定した DB::table で行い、行を DTO にして返す（UnitOfWork のキューは通さない）。書き込みは UseCase のトランザクション終了を待たず、呼んだ時点で実行される。
 */
class RewardTrackMilestoneRepositoryAdapter implements RewardTrackMilestoneRepositoryInterface
{
    public function findByPlayerAndTrack(int $sysPlayerId, string $mstRewardTrackId, string $connectionName): array
    {
        // trx_reward_track_milestone には mst_reward_track_id がない。mst と trx は別の DB サーバーに載っていて
        // 跨いだ JOIN はできず、版ごとの mst DB は DB 名も毎回違うので、
        // mst で対象のマイルストーン ID を引いてから trx を whereIn で絞る二段引きにする（findReceivedKeySet と同じ形。db.md 8.5）
        $milestoneIds = $this->selectMilestoneIdList($mstRewardTrackId);

        if (empty($milestoneIds)) {
            return [];
        }

        return DB::connection($connectionName)
            ->table('trx_reward_track_milestone')
            ->where('sys_player_id', $sysPlayerId)
            ->whereIn('mst_reward_track_milestone_id', $milestoneIds)
            ->where('is_delete', false)
            ->get()
            ->map(fn ($r) => RewardTrackMilestoneAdapter::toDto((array) $r))
            ->all();
    }

    /**
     * トラックに属するマイルストーン ID を mst で引く（トラックごとに少数）
     *
     * @return list<string>
     */
    private function selectMilestoneIdList(string $mstRewardTrackId): array
    {
        /** @var list<string> $ids */
        $ids = DB::connection('mst')
            ->table('mst_reward_track_milestone')
            ->where('mst_reward_track_id', $mstRewardTrackId)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        return $ids;
    }

    public function hasReceived(int $sysPlayerId, string $mstRewardTrackMilestoneId, string $mstRewardTrackLineId, string $connectionName): bool
    {
        return DB::connection($connectionName)
            ->table('trx_reward_track_milestone')
            ->where('sys_player_id', $sysPlayerId)
            ->where('mst_reward_track_milestone_id', $mstRewardTrackMilestoneId)
            ->where('mst_reward_track_line_id', $mstRewardTrackLineId)
            ->where('is_delete', false)
            ->exists();
    }

    public function insertReceipt(int $sysPlayerId, string $mstRewardTrackMilestoneId, string $mstRewardTrackLineId, string $receivedAt, string $connectionName): RewardTrackMilestone
    {
        $now = ClockUtility::nowToString();

        $id = DB::connection($connectionName)->table('trx_reward_track_milestone')->insertGetId([
            'sys_player_id' => $sysPlayerId,
            'mst_reward_track_milestone_id' => $mstRewardTrackMilestoneId,
            'mst_reward_track_line_id' => $mstRewardTrackLineId,
            'received_at' => $receivedAt,
            'is_delete' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $row = DB::connection($connectionName)
            ->table('trx_reward_track_milestone')
            ->where('id', $id)
            ->first();

        return RewardTrackMilestoneAdapter::toDto((array) $row);
    }

    public function findReceivedKeySet(int $sysPlayerId, string $mstRewardTrackId, string $connectionName): array
    {
        // 二段引き: マスター側で対象マイルストーンを引いてから、そのIDでシャード側を絞る（findByPlayerAndTrack と同じ形）
        $milestoneIds = $this->selectMilestoneIdList($mstRewardTrackId);

        if (empty($milestoneIds)) {
            return [];
        }

        $rows = DB::connection($connectionName)
            ->table('trx_reward_track_milestone')
            ->where('sys_player_id', $sysPlayerId)
            ->whereIn('mst_reward_track_milestone_id', $milestoneIds)
            ->where('is_delete', false)
            ->select('mst_reward_track_milestone_id', 'mst_reward_track_line_id')
            ->get();

        $keySet = [];
        foreach ($rows as $row) {
            $key = $row->mst_reward_track_milestone_id.':'.$row->mst_reward_track_line_id;
            $keySet[$key] = true;
        }

        return $keySet;
    }
}

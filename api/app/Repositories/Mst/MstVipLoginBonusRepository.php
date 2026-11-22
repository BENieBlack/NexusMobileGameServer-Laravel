<?php

namespace App\Repositories\Mst;

use App\Models\Mst\MstVipLoginBonus;
use App\Models\Mst\MstVipLoginBonusContent;
use Nexus\Core\Support\CustomCollection;

/**
 * VIPログインボーナスマスターのRepository
 *
 * @extends _BaseMstRepository<MstVipLoginBonus>
 */
class MstVipLoginBonusRepository extends _BaseMstRepository implements VipLoginBonusRepositoryInterface
{
    protected string $modelClass = MstVipLoginBonus::class;

    /**
     * {@inheritDoc}
     *
     * @return array<string, mixed>|null
     */
    public function selectActiveByVipLevel(int $vipLevel): ?array
    {
        $model = MstVipLoginBonus::active()
            ->forVipLevel($vipLevel)
            // 同じレベルに有効な行が 2 件以上あるマスターの誤りがあっても、
            // 配布・カレンダー・再受け取りが同じ 1 件を選ぶように並びを固定する
            ->orderBy('id')
            ->first();

        return $model?->toArray();
    }

    /**
     * {@inheritDoc}
     *
     * @return CustomCollection<array-key, array<string, mixed>> 中身はtoArray()した連想配列
     */
    public function selectContentsByBonusIdAndDay(string $vipLoginBonusId, int $day): CustomCollection
    {
        $contents = MstVipLoginBonusContent::where('mst_vip_login_bonus_id', $vipLoginBonusId)
            ->where('day', $day)
            // 並びは sort_desc 降順（大きい値が先。配る順 = クライアントの表示順）。同値は id 昇順で固定する。
            // id は string（{親ID}:{day}:{content_type}:{content_mst_id}）で、同値の並びは MySQL の文字列順になり、
            // クライアントの SQLite（BINARY）の並びと食い違いうる。そのため同値は取り込みで拒否する
            // （tool の MasterImportService。設計 docs/design/mst-id-introduction/db.md 12 節 6）
            ->orderByDesc('sort_desc')
            ->orderBy('id')
            ->get();

        return new CustomCollection($contents->toArray());
    }
}

<?php

namespace App\Models\Mst;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nexus\Core\Models\Mst\_BaseMst;

/**
 * MstVipLoginBonusContent
 *
 * VIPログインボーナスコンテンツマスターデータ
 *
 * @property string $id
 * @property string $mst_vip_login_bonus_id VIPログインボーナスID
 * @property int $day ログイン日数
 * @property string $content_type 報酬タイプ
 * @property string $content_mst_id 報酬ID
 * @property array<string, mixed>|null $content_option 報酬オプション
 * @property int $content_quantity 報酬の基本個数
 * @property int $amount 報酬の倍率
 * @property int $sort_desc 同じ日の中の並び順（降順。大きい値が先）
 * @property string $created_at
 * @property string $updated_at
 */
class MstVipLoginBonusContent extends _BaseMst
{
    protected $connection = 'mst';

    protected $table = 'mst_vip_login_bonus_content';

    /** 主キーは string の id（{mst_vip_login_bonus_id}:{day}:{content_type}:{content_mst_id}）。自然キーは unique */
    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'mst_vip_login_bonus_id',
        'day',
        'content_type',
        'content_mst_id',
        'content_option',
        'content_quantity',
        'amount',
        'sort_desc',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'day' => 'integer',
        'content_option' => 'array',
        'content_quantity' => 'integer',
        'amount' => 'integer',
        'sort_desc' => 'integer',
    ];

    /**
     * VIPログインボーナスとのリレーション
     *
     * @return BelongsTo<MstVipLoginBonus, $this>
     */
    public function vipLoginBonus(): BelongsTo
    {
        return $this->belongsTo(MstVipLoginBonus::class, 'mst_vip_login_bonus_id', 'id');
    }

    /**
     * id を作る（シーダー・テスト・取り込みの検証が同じ規則を使う）
     *
     * 規則は {mst_vip_login_bonus_id}:{day}:{content_type}:{content_mst_id}。mst_vip_login_bonus_id と content_type に ':' を含めない（取り込みで拒否する）
     * tool の MasterImportService::ID_RULES も同じ規則を持つ。変えるときは両方を直す
     */
    public static function makeId(string $parentId, int $day, string $contentType, string $contentMstId): string
    {
        return $parentId.':'.$day.':'.$contentType.':'.$contentMstId;
    }
}

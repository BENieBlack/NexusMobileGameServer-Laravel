<?php

namespace App\Models\Mst;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MstLoginBonusContent Model
 *
 * ログインボーナスの報酬内容
 *
 * @property string $id
 * @property int $deploy_key
 * @property string $mst_login_bonus_id
 * @property int $day
 * @property string $content_type
 * @property string $content_mst_id
 * @property int $amount
 * @property bool $is_paid
 * @property int $sort_desc 並び順（降順。大きい値が先）
 */
class MstLoginBonusContent extends _BaseMst
{
    public $table = 'mst_login_bonus_content';

    /** 主キーは string の id（{mst_login_bonus_id}:{day}:{content_type}:{content_mst_id}）。自然キーは unique */
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'deploy_key',
        'id',
        'mst_login_bonus_id',
        'day',
        'content_type',
        'content_mst_id',
        'content_option',
        'content_quantity',
        'amount',
        'is_paid',
        'sort_desc',
    ];

    /**
     * @var array<string, string>
     */
    /** @var array<string, string> */
    protected $casts = [
        'deploy_key' => 'integer',
        'content_option' => 'array',
        'day' => 'integer',
        'content_quantity' => 'integer',
        'amount' => 'integer',
        'is_paid' => 'boolean',
        'sort_desc' => 'integer',
    ];

    public $timestamps = true;

    /**
     * ログインボーナス設定とのリレーション
     */
    /**
     * @return BelongsTo<MstLoginBonus, $this>
     */
    public function loginBonus(): BelongsTo
    {
        return $this->belongsTo(MstLoginBonus::class, 'mst_login_bonus_id', 'id');
    }

    /**
     * コンテンツタイプを取得
     */
    public function getContentType(): string
    {
        return $this->getAttribute('content_type');
    }

    /**
     * コンテンツIDを取得
     */
    public function getContentMstId(): string
    {
        return $this->getAttribute('content_mst_id');
    }

    /**
     * コンテンツオプションを取得
     *
     * @return array<string, mixed>|null
     */
    public function getContentOption(): ?array
    {
        return $this->getAttribute('content_option');
    }

    /**
     * コンテンツ数量を取得（1配布あたり）
     */
    public function getContentQuantity(): int
    {
        return $this->getAttribute('content_quantity');
    }

    /**
     * 配布回数を取得
     */
    public function getAmount(): int
    {
        return $this->getAttribute('amount');
    }

    /**
     * 実際の配布総量を取得（content_quantity × amount）
     */
    public function getTotalQuantity(): int
    {
        return $this->getContentQuantity() * $this->getAmount();
    }

    /**
     * 有償フラグを取得
     */
    public function getIsPaid(): bool
    {
        return $this->getAttribute('is_paid');
    }

    /**
     * id を作る（シーダー・テスト・取り込みの検証が同じ規則を使う）
     *
     * 規則は {mst_login_bonus_id}:{day}:{content_type}:{content_mst_id}。mst_login_bonus_id と content_type に ':' を含めない（取り込みで拒否する）
     * tool の MasterImportService::ID_RULES も同じ規則を持つ。変えるときは両方を直す
     */
    public static function makeId(string $parentId, int $day, string $contentType, string $contentMstId): string
    {
        return $parentId.':'.$day.':'.$contentType.':'.$contentMstId;
    }
}

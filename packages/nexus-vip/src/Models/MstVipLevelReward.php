<?php

namespace NexusVip\Models;

use Nexus\Core\Models\Mst\_BaseMst;

/**
 * VIPレベルアップ報酬マスターモデル
 *
 * @property int $amount
 * @property string $content_mst_id
 * @property array<string, mixed>|null $content_option
 * @property int $content_quantity
 * @property string $content_type
 * @property bool $is_active
 * @property bool $is_paid
 * @property int $vip_level
 */
class MstVipLevelReward extends _BaseMst
{
    protected $table = 'mst_vip_level_reward';

    /** 主キーは string の id（{vip_level}:{content_type}:{content_mst_id}）。自然キーは unique */
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'deploy_key',
        'id',
        'vip_level',
        'content_type',
        'content_mst_id',
        'content_option',
        'content_quantity',
        'amount',
        'is_paid',
        'sort_order',
        'is_active',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'deploy_key' => 'integer',
        'vip_level' => 'integer',
        'content_option' => 'array',
        'content_quantity' => 'integer',
        'amount' => 'integer',
        'is_paid' => 'boolean',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * VIPレベルを取得
     */
    public function getVipLevel(): int
    {
        return $this->vip_level;
    }

    /**
     * コンテンツタイプを取得
     */
    public function getContentType(): string
    {
        return $this->content_type;
    }

    /**
     * コンテンツIDを取得
     */
    public function getContentMstId(): string
    {
        return $this->content_mst_id;
    }

    /**
     * コンテンツオプションを取得
     *
     * @return array<string, mixed>|null
     */
    public function getContentOption(): ?array
    {
        return $this->content_option;
    }

    /**
     * コンテンツ数量を取得（1配布あたり）
     */
    public function getContentQuantity(): int
    {
        return $this->content_quantity;
    }

    /**
     * 配布回数を取得
     */
    public function getAmount(): int
    {
        return $this->amount;
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
        return $this->is_paid;
    }

    /**
     * 有効フラグを取得
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * id を作る（シーダー・テスト・取り込みの検証が同じ規則を使う）
     *
     * 規則は {vip_level}:{content_type}:{content_mst_id}。content_type に ':' を含めない（取り込みで拒否する）
     * tool の MasterImportService::ID_RULES も同じ規則を持つ。変えるときは両方を直す
     */
    public static function makeId(int $vipLevel, string $contentType, string $contentMstId): string
    {
        return $vipLevel.':'.$contentType.':'.$contentMstId;
    }
}

<?php

namespace App\Models\Mst;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MstTradeProductContent Model
 *
 * 交換所の商品を1回交換すると手に入るもの
 *
 * @property string $id
 * @property int $deploy_key
 * @property string $mst_trade_product_id
 * @property string $content_type
 * @property string $content_mst_id
 * @property int $content_quantity
 * @property int $sort_order
 * @property string $created_at
 * @property string $updated_at
 */
class MstTradeProductContent extends _BaseMst
{
    public $table = 'mst_trade_product_content';

    /** 主キーは string の id（{mst_trade_product_id}:{content_type}:{content_mst_id}）。自然キーは unique */
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'deploy_key',
        'id',
        'mst_trade_product_id',
        'content_type',
        'content_mst_id',
        'content_quantity',
        'sort_order',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'deploy_key' => 'integer',
        'content_quantity' => 'integer',
        'sort_order' => 'integer',
    ];

    public $timestamps = true;

    /**
     * @return BelongsTo<MstTradeProduct, $this>
     */
    public function tradeProduct(): BelongsTo
    {
        return $this->belongsTo(MstTradeProduct::class, 'mst_trade_product_id', 'id');
    }

    public function getMstTradeProductId(): string
    {
        return (string) $this->getAttribute('mst_trade_product_id');
    }

    public function getContentType(): string
    {
        return (string) $this->getAttribute('content_type');
    }

    public function getContentMstId(): string
    {
        return (string) $this->getAttribute('content_mst_id');
    }

    public function getContentQuantity(): int
    {
        return (int) $this->getAttribute('content_quantity');
    }

    public function getSortOrder(): int
    {
        return (int) $this->getAttribute('sort_order');
    }

    /**
     * id を作る（シーダー・テスト・取り込みの検証が同じ規則を使う）
     *
     * 規則は {mst_trade_product_id}:{content_type}:{content_mst_id}。mst_trade_product_id と content_type に ':' を含めない（取り込みで拒否する）
     * tool の MasterImportService::ID_RULES も同じ規則を持つ。変えるときは両方を直す
     */
    public static function makeId(string $parentId, string $contentType, string $contentMstId): string
    {
        return $parentId.':'.$contentType.':'.$contentMstId;
    }
}

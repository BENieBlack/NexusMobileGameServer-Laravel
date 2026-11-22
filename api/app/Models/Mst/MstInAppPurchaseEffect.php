<?php

namespace App\Models\Mst;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MstInAppPurchaseEffect extends _BaseMst
{
    public $table = 'mst_in_app_purchase_effect';

    /** 主キーは string の id（{mst_in_app_purchase_id}:{effect_type}）。自然キーは unique */
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'deploy_key',
        'id',
        'mst_in_app_purchase_id',
        'effect_type',
        'value',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'deploy_key' => 'integer',
        'mst_in_app_purchase_id' => 'string',
        // effect_type は enum の文字列、value は decimal(10,2)。
        // integerにすると 'ExpBoost' → '0'、1.50 → 1 に潰れる
        'effect_type' => 'string',
        'value' => 'decimal:2',
    ];

    public $timestamps = true;

    /**
     * id を作る（シーダー・テスト・取り込みの検証が同じ規則を使う）
     *
     * 規則は {mst_in_app_purchase_id}:{effect_type}。mst_in_app_purchase_id に ':' を含めない（取り込みで拒否する）
     * tool の MasterImportService::ID_RULES も同じ規則を持つ。変えるときは両方を直す
     */
    public static function makeId(string $mstInAppPurchaseId, string $effectType): string
    {
        return $mstInAppPurchaseId.':'.$effectType;
    }

    /**
     * 親のアプリ内課金商品
     */
    /**
     * @return BelongsTo<MstInAppPurchase, $this>
     */
    public function inAppPurchase(): BelongsTo
    {
        return $this->belongsTo(MstInAppPurchase::class, 'mst_in_app_purchase_id');
    }

    /**
     * 効果タイプを取得
     */
    public function getEffectType(): string
    {
        return $this->getAttribute('effect_type');
    }

    /**
     * 効果値を取得
     */
    public function getValue(): float
    {
        return (float) $this->getAttribute('value');
    }

    /**
     * レスポンス用配列に変換
     *
     * Note: id（{mst_in_app_purchase_id}:{effect_type}）はそのまま含まれる
     */
    public function toResponseArray(): array
    {
        return parent::toResponseArray();
    }
}

<?php

namespace App\Models\Mst;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property ?int $price_amount_micros
 * @property ?string $price_currency_code
 */
class MstBillingPlatformProduct extends _BaseMst
{
    public $table = 'mst_billing_platform_product';

    /** 主キーは string の id（{billing_platform}:{platform_product_id}）。自然キーは unique */
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'deploy_key',
        'id',
        'platform_product_id',
        'billing_platform',
        'product_type',
        'price_amount_micros',
        'price_currency_code',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    /** @var array<string, string> */
    protected $casts = [
        'deploy_key' => 'integer',
        'price_amount_micros' => 'integer',
        'is_active' => 'boolean',
    ];

    public $timestamps = true;

    /**
     * このプラットフォーム商品を使用しているアプリ内課金商品（AppStore側）
     */
    /**
     * @return HasMany<MstInAppPurchase, $this>
     */
    public function inAppPurchasesAsAppStore(): HasMany
    {
        return $this->hasMany(MstInAppPurchase::class, 'app_store_product_id');
    }

    /**
     * このプラットフォーム商品を使用しているアプリ内課金商品（GooglePlay側）
     */
    /**
     * @return HasMany<MstInAppPurchase, $this>
     */
    public function inAppPurchasesAsGooglePlay(): HasMany
    {
        return $this->hasMany(MstInAppPurchase::class, 'google_play_product_id');
    }

    /**
     * レスポンス用配列に変換
     *
     * データベース層の'id'をAPI層の'mst_billing_platform_product_id'に変換
     */
    public function toResponseArray(): array
    {
        $array = parent::toResponseArray();

        if (isset($array['id'])) {
            $array['mst_billing_platform_product_id'] = $array['id'];
            unset($array['id']);
        }

        return $array;
    }

    /**
     * price_amount_micros を取得
     */
    public function getPriceAmountMicros(): ?int
    {
        return $this->getAttribute('price_amount_micros');
    }

    /**
     * price_currency_code を取得
     */
    public function getPriceCurrencyCode(): ?string
    {
        return $this->getAttribute('price_currency_code');
    }

    /**
     * id を作る（シーダー・テスト・取り込みの検証が同じ規則を使う）
     *
     * 規則は {billing_platform}:{platform_product_id}。billing_platform が先頭固定なので、
     * platform_product_id が ':' を含んでも別の組と同じ id にならない
     * tool の MasterImportService::ID_RULES も同じ規則を持つ。変えるときは両方を直す
     */
    public static function makeId(string $billingPlatform, string $platformProductId): string
    {
        return $billingPlatform.':'.$platformProductId;
    }
}

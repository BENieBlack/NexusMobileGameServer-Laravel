<?php

namespace App\Models\Mst;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MstMailboxContent Model
 *
 * @property string $id
 * @property int $deploy_key
 * @property string $mst_mailbox_id
 * @property string $content_type
 * @property string $content_mst_id
 * @property int $amount
 * @property ?string $rarity
 * @property bool $is_highlight
 * @property int $sort_desc
 * @property string $created_at
 * @property string $updated_at
 */
class MstMailboxContent extends _BaseMst
{
    public $table = 'mst_mailbox_content';

    /** 主キーは string の id（{mst_mailbox_id}:{content_type}:{content_mst_id}）。自然キーは unique */
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'deploy_key',
        'id',
        'mst_mailbox_id',
        'content_type',
        'content_mst_id',
        'content_option',
        'content_quantity',
        'amount',
        'rarity',
        'is_highlight',
        'sort_desc',
    ];

    /**
     * @var array<string, string>
     */
    /** @var array<string, string> */
    protected $casts = [
        'deploy_key' => 'integer',
        'content_option' => 'array',
        'content_quantity' => 'integer',
        'amount' => 'integer',
        'is_highlight' => 'boolean',
        'sort_desc' => 'integer',
    ];

    public $timestamps = true;

    /**
     * メールボックスとのリレーション
     */
    /**
     * @return BelongsTo<MstMailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(MstMailbox::class, 'mst_mailbox_id', 'id');
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
     */
    /**
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
     * 数量を取得（配布回数）
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
     * レスポンス用配列に変換
     */
    public function toResponseArray(): array
    {
        return parent::toResponseArray();
    }

    /**
     * rarity を取得
     */
    public function getRarity(): ?string
    {
        return $this->getAttribute('rarity');
    }

    /**
     * is_highlight を取得
     */
    public function getIsHighlight(): bool
    {
        return (bool) $this->getAttribute('is_highlight');
    }

    /**
     * sort_desc を取得
     */
    public function getSortDesc(): int
    {
        return $this->getAttribute('sort_desc');
    }

    /**
     * id を作る（シーダー・テスト・取り込みの検証が同じ規則を使う）
     *
     * 規則は {mst_mailbox_id}:{content_type}:{content_mst_id}。mst_mailbox_id と content_type に ':' を含めない（取り込みで拒否する）
     * tool の MasterImportService::ID_RULES も同じ規則を持つ。変えるときは両方を直す
     */
    public static function makeId(string $parentId, string $contentType, string $contentMstId): string
    {
        return $parentId.':'.$contentType.':'.$contentMstId;
    }
}

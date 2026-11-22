<?php

namespace App\Models\Mst;

/**
 * MstEventContent Model
 *
 * イベントに束ねるコンテンツ（ガチャ・ミッション・交換所など）
 *
 * @property string $id
 * @property int $deploy_key
 * @property string $mst_event_id
 * @property string $content_type
 * @property string $content_mst_id
 * @property int $sort_order
 * @property string $created_at
 * @property string $updated_at
 */
class MstEventContent extends _BaseMst
{
    public $table = 'mst_event_content';

    /** 主キーは string の id（{mst_event_id}:{content_type}:{content_mst_id}）。自然キーは unique */
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'deploy_key',
        'id',
        'mst_event_id',
        'content_type',
        'content_mst_id',
        'sort_order',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'deploy_key' => 'integer',
        'sort_order' => 'integer',
    ];

    public $timestamps = true;

    public function getMstEventId(): string
    {
        return (string) $this->getAttribute('mst_event_id');
    }

    public function getContentType(): string
    {
        return (string) $this->getAttribute('content_type');
    }

    public function getContentMstId(): string
    {
        return (string) $this->getAttribute('content_mst_id');
    }

    public function getSortOrder(): int
    {
        return (int) $this->getAttribute('sort_order');
    }

    /**
     * id を作る（シーダー・テスト・取り込みの検証が同じ規則を使う）
     *
     * 規則は {mst_event_id}:{content_type}:{content_mst_id}。mst_event_id と content_type に ':' を含めない（取り込みで拒否する）
     * tool の MasterImportService::ID_RULES も同じ規則を持つ。変えるときは両方を直す
     */
    public static function makeId(string $parentId, string $contentType, string $contentMstId): string
    {
        return $parentId.':'.$contentType.':'.$contentMstId;
    }
}

<?php

namespace App\Models\Mst;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MstTagContent Model
 *
 * タグとマスター（ユニット・アイテム等）の紐付け（多対多）
 *
 * @property string $id
 * @property int $deploy_key
 * @property string $mst_tag_id
 * @property string $content_type
 * @property string $content_mst_id
 * @property string $created_at
 * @property string $updated_at
 */
class MstTagContent extends _BaseMst
{
    public $table = 'mst_tag_content';

    /** 主キーは string の id（{mst_tag_id}:{content_type}:{content_mst_id}）。自然キーは unique */
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'deploy_key',
        'id',
        'mst_tag_id',
        'content_type',
        'content_mst_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'deploy_key' => 'integer',
    ];

    public $timestamps = true;

    /**
     * @return BelongsTo<MstTag, $this>
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(MstTag::class, 'mst_tag_id', 'id');
    }

    public function getMstTagId(): string
    {
        return (string) $this->getAttribute('mst_tag_id');
    }

    public function getContentType(): string
    {
        return (string) $this->getAttribute('content_type');
    }

    public function getContentMstId(): string
    {
        return (string) $this->getAttribute('content_mst_id');
    }

    /**
     * id を作る（シーダー・テスト・取り込みの検証が同じ規則を使う）
     *
     * 規則は {mst_tag_id}:{content_type}:{content_mst_id}。mst_tag_id と content_type に ':' を含めない（取り込みで拒否する）
     * tool の MasterImportService::ID_RULES も同じ規則を持つ。変えるときは両方を直す
     */
    public static function makeId(string $parentId, string $contentType, string $contentMstId): string
    {
        return $parentId.':'.$contentType.':'.$contentMstId;
    }
}

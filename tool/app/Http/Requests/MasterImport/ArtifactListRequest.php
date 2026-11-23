<?php

namespace App\Http\Requests\MasterImport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /master-import/artifact-list の入力
 *
 * 設計: docs/design/master-distribution/api.md 5 節
 * 認可: ログイン済み（auth ミドルウェア）なら誰でも。role と操作の食い違いは Runner が role_forbidden で返す（api.md 2 節）。
 */
class ArtifactListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'source' => ['sometimes', 'string', 'in:upstream,own'],
        ];
    }

    public function source(): string
    {
        return (string) $this->input('source', 'upstream');
    }
}

<?php

namespace App\Http\Requests\MasterImport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /master-import/cleanup の入力
 *
 * 設計: docs/design/master-distribution/api.md 9.3 節
 * 認可: ログイン済み（auth ミドルウェア）なら誰でも。role と操作の食い違いは Runner が role_forbidden で返す（api.md 2 節）。
 */
class CleanupRequest extends FormRequest
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
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }

    /** 既定は dry_run（付けないと対象の一覧だけ） */
    public function dryRun(): bool
    {
        return $this->boolean('dry_run', true);
    }
}

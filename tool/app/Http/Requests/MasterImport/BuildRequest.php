<?php

namespace App\Http\Requests\MasterImport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /master-import/build の入力
 *
 * 設計: docs/design/master-distribution/api.md 6 節
 * 認可: ログイン済み（auth ミドルウェア）なら誰でも。role と操作の食い違いは Runner が role_forbidden で返す（api.md 2 節）。
 */
class BuildRequest extends FormRequest
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
            'start_at' => ['sometimes', 'nullable', 'date_format:Y-m-d H:i:s'],
        ];
    }

    public function dryRun(): bool
    {
        return $this->boolean('dry_run');
    }

    public function startAt(): ?string
    {
        $value = $this->input('start_at');

        return is_string($value) && $value !== '' ? $value : null;
    }
}

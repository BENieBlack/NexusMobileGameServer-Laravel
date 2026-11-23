<?php

namespace App\Http\Requests\MasterImport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /master-import/resolve-unknown の入力
 *
 * 設計: docs/design/master-distribution/api.md 9.5 節
 * 認可: ログイン済み（auth ミドルウェア）なら誰でも。role と操作の食い違いは Runner が role_forbidden で返す（api.md 2 節）。
 */
class ResolveUnknownRequest extends FormRequest
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
            'run_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function runId(): int
    {
        return (int) $this->input('run_id');
    }
}

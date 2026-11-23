<?php

namespace App\Http\Requests\MasterImport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /master-import/cancel-schedule・deactivate・activate の入力（sys_deploy.id）
 *
 * 設計: docs/design/master-distribution/api.md 9.2・9.6 節
 * 認可: ログイン済み（auth ミドルウェア）なら誰でも。role と操作の食い違いは Runner が role_forbidden で返す（api.md 2 節）。
 */
class SysDeployIdRequest extends FormRequest
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
            'sys_deploy_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function sysDeployId(): int
    {
        return (int) $this->input('sys_deploy_id');
    }
}

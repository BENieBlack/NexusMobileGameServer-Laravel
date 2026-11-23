<?php

namespace App\Http\Requests\MasterImport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /master-import/rollback の入力
 *
 * 設計: docs/design/master-distribution/api.md 9.1 節
 * 認可: ログイン済み（auth ミドルウェア）なら誰でも。role と操作の食い違いは Runner が role_forbidden で返す（api.md 2 節）。
 */
class RollbackRequest extends FormRequest
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
            'sys_deploy_master_id' => ['required', 'integer', 'min:1'],
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }

    public function sysDeployMasterId(): int
    {
        return (int) $this->input('sys_deploy_master_id');
    }

    public function dryRun(): bool
    {
        return $this->boolean('dry_run');
    }
}

<?php

namespace App\Http\Requests\MasterImport;

use App\Services\MasterArtifactStore;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /master-import/finalize の入力
 *
 * 設計: docs/design/master-distribution/api.md 8 節
 * 認可: ログイン済み（auth ミドルウェア）なら誰でも。role と操作の食い違いは Runner が role_forbidden で返す（api.md 2 節）。
 */
class FinalizeRequest extends FormRequest
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
            'artifact_dir' => ['required', 'string', 'regex:'.MasterArtifactStore::DIR_PATTERN],
            'dry_run' => ['sometimes', 'boolean'],
            'allow_unverified' => ['sometimes', 'boolean'],
        ];
    }

    public function artifactDir(): string
    {
        return (string) $this->input('artifact_dir');
    }

    public function dryRun(): bool
    {
        return $this->boolean('dry_run');
    }

    public function allowUnverified(): bool
    {
        return $this->boolean('allow_unverified');
    }
}

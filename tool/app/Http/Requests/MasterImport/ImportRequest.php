<?php

namespace App\Http\Requests\MasterImport;

use App\Services\MasterArtifactStore;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /master-import/import の入力
 *
 * 設計: docs/design/master-distribution/api.md 7 節
 * 認可: ログイン済み（auth ミドルウェア）なら誰でも。role と操作の食い違いは Runner が role_forbidden で返す（api.md 2 節）。
 */
class ImportRequest extends FormRequest
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
            // artifact_dir の hash は先頭 16 文字（db.md 4.2・6.5.2。api.md 7 節の 64 は旧記述）。キーは必ず送る（値は null 可。null = 登録の直前の現在時刻）。誤って即時にしないため
            'start_at' => ['present', 'nullable', 'date_format:Y-m-d H:i:s'],
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }

    public function artifactDir(): string
    {
        return (string) $this->input('artifact_dir');
    }

    public function startAt(): ?string
    {
        $value = $this->input('start_at');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function dryRun(): bool
    {
        return $this->boolean('dry_run');
    }
}

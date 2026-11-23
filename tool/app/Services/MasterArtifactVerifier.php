<?php

namespace App\Services;

use App\Exceptions\MasterDeployRunException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Throwable;

/**
 * 成果物の検証（db.md 6.7 の I1。import の verify・finalize の前提）
 *
 * - `manifest.json` がある（無ければ未完成として artifact_not_found）
 * - `hash` が、マニフェストに記録された `schema_fingerprint` と表の一覧から再計算した値と一致する（自環境で指紋を計算し直さない。4.4）
 * - ディレクトリ名 `{deploy_key}_{hash の先頭 16 文字}` とマニフェストの deploy_key・hash が一致する
 * - 各ファイル（data・配信 SQLite・schema.json）の SHA-256 がマニフェストと一致する（行数も）
 * - `final.json`（require_finalized が true のとき）がマニフェストの deploy_key・hash と一致する（not_finalized）
 */
class MasterArtifactVerifier
{
    /**
     * @param  string  $prefix  disk の中のディレクトリ（成果物のコピー自体がルートなら ''）
     * @return array<string, mixed> マニフェスト
     *
     * @throws MasterDeployRunException artifact_not_found・artifact_invalid・not_finalized
     */
    public function verify(Filesystem $disk, string $prefix, string $artifactDir, bool $requireFinalized): array
    {
        $path = static fn (string $relative): string => $prefix === '' ? $relative : rtrim($prefix, '/').'/'.$relative;

        if (preg_match(MasterArtifactStore::DIR_PATTERN, $artifactDir) !== 1) {
            throw $this->invalid("成果物のディレクトリ名の形が不正です: {$artifactDir}");
        }
        if (! $disk->exists($path('manifest.json'))) {
            throw new MasterDeployRunException(MasterDeployRunException::ARTIFACT_NOT_FOUND, "成果物が見つかりません（manifest.json が無い）: {$artifactDir}", 404);
        }
        $manifest = json_decode((string) $disk->get($path('manifest.json')), true);
        if (! is_array($manifest) || ! isset($manifest['hash'], $manifest['deploy_key'], $manifest['schema_fingerprint'], $manifest['tables'], $manifest['format_version'], $manifest['schema_sha256'], $manifest['group_list'])
            || ! is_string($manifest['schema_sha256']) || ! is_array($manifest['group_list'])
            || ! is_string($manifest['hash']) || preg_match('/^[0-9a-f]{64}$/D', $manifest['hash']) !== 1 || ! is_array($manifest['tables'])) {
            throw $this->invalid('manifest.json の形が不正です');
        }
        if ($artifactDir !== ((int) $manifest['deploy_key']).'_'.substr($manifest['hash'], 0, MasterVersionDatabase::HASH_LENGTH)) {
            throw $this->invalid('ディレクトリ名とマニフェストの deploy_key・hash が一致しません');
        }

        $tableList = [];
        foreach ($manifest['tables'] as $table) {
            if (! is_array($table) || ! isset($table['name'], $table['row_count'], $table['data_sha256']) || preg_match('/^mst_[a-z0-9_]+$/D', (string) $table['name']) !== 1) {
                throw $this->invalid('manifest.json の tables の形が不正です');
            }
            $tableList[] = ['name' => $table['name'], 'row_count' => $table['row_count'], 'data_sha256' => $table['data_sha256']];
        }
        $recomputed = hash('sha256', json_encode([
            'format_version' => $manifest['format_version'],
            'schema_fingerprint' => $manifest['schema_fingerprint'],
            'tables' => $tableList,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($recomputed !== $manifest['hash']) {
            throw $this->invalid('hash の再計算がマニフェストと一致しません（改ざん・欠損）');
        }

        foreach ($tableList as $table) {
            $file = $path('data/'.$table['name'].'.ndjson');
            [$sha, , $lines] = $this->digest($disk, $file);
            if ($sha !== $table['data_sha256'] || $lines !== (int) $table['row_count']) {
                throw $this->invalid("行データが一致しません: {$table['name']}");
            }
        }
        foreach ($manifest['group_list'] as $group) {
            $fileName = (string) ($group['file_name'] ?? '');
            if ($fileName === '' || $fileName !== basename($fileName) || str_contains($fileName, '..')) {
                throw $this->invalid('配信 SQLite のファイル名が不正です');
            }
            [$sha, $size] = $this->digest($disk, $path($fileName));
            if ($sha !== ($group['sqlite_sha256'] ?? null) || $size !== (int) ($group['file_size'] ?? -1)) {
                throw $this->invalid("配信 SQLite が一致しません: {$fileName}");
            }
        }
        [$sha] = $this->digest($disk, $path('schema.json'));
        if ($sha !== $manifest['schema_sha256']) {
            throw $this->invalid('schema.json が一致しません');
        }

        if ($requireFinalized && ! $this->isFinal($disk, $path('final.json'), (int) $manifest['deploy_key'], $manifest['hash'])) {
            throw new MasterDeployRunException(
                MasterDeployRunException::NOT_FINALIZED,
                '成果物に QA 完了の印（final.json）が無い、またはマニフェストと一致しません。QA の環境で master:finalize を行ってください。',
                422,
            );
        }

        return $manifest;
    }

    /**
     * final.json があり、deploy_key・hash がマニフェストと一致するか
     */
    public function isFinal(Filesystem $disk, string $finalPath, int $deployKey, string $hash): bool
    {
        if (! $disk->exists($finalPath)) {
            return false;
        }
        $final = json_decode((string) $disk->get($finalPath), true);

        return is_array($final) && (int) ($final['deploy_key'] ?? -1) === $deployKey && ($final['hash'] ?? null) === $hash;
    }

    /**
     * @return array{0: string, 1: int, 2: int} SHA-256・バイト数・LF の数（行数）
     */
    private function digest(Filesystem $disk, string $file): array
    {
        try {
            $stream = $disk->readStream($file);
        } catch (Throwable) {
            $stream = null;
        }
        if (! is_resource($stream)) {
            throw $this->invalid("ファイルを読めません: {$file}");
        }
        $context = hash_init('sha256');
        $size = 0;
        $lines = 0;
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1 << 20);
                if ($chunk === false) {
                    throw $this->invalid("ファイルを読めません: {$file}");
                }
                hash_update($context, $chunk);
                $size += strlen($chunk);
                $lines += substr_count($chunk, "\n");
            }
        } finally {
            fclose($stream);
        }

        return [hash_final($context), $size, $lines];
    }

    private function invalid(string $message): MasterDeployRunException
    {
        return new MasterDeployRunException(MasterDeployRunException::ARTIFACT_INVALID, '成果物の検証に失敗しました（I1）: '.$message, 422);
    }
}

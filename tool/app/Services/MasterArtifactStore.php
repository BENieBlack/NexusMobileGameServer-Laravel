<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * マスター配信の成果物の置き場（Laravel の disk の薄い包み。db.md 6.5.3）
 *
 * disk 名は `master_artifact`（config/filesystems.php。ローカルは public/masterdata、本番側はバケット）。
 * 完成の印は `manifest.json`。S3 などのバケットにはディレクトリの原子的な rename が無いので、全ファイルを置いてから
 * 最後に `manifest.json` を置く。`manifest.json` が無いディレクトリは未完成として無視する。
 */
class MasterArtifactStore
{
    public const DISK = 'master_artifact';

    public const MANIFEST = 'manifest.json';

    public function __construct(private readonly string $diskName = self::DISK) {}

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName);
    }

    /**
     * 完成した成果物（manifest.json がある）か
     */
    public function isComplete(string $artifactDir): bool
    {
        return $this->disk()->exists($artifactDir.'/'.self::MANIFEST);
    }

    /**
     * @return array<string, mixed>|null manifest.json が無い（未完成・存在しない）ときは null
     */
    public function manifest(string $artifactDir): ?array
    {
        $path = $artifactDir.'/'.self::MANIFEST;
        if (! $this->disk()->exists($path)) {
            return null;
        }
        $json = $this->disk()->get($path);
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * ローカルの作業ディレクトリの中身を `{artifactDir}/` に置く。manifest.json は最後に置く
     *
     * @throws RuntimeException 書き込みに失敗したとき
     */
    public function put(string $localDir, string $artifactDir): void
    {
        $localDir = rtrim($localDir, '/');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($localDir, \FilesystemIterator::SKIP_DOTS));
        $relativeList = [];
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $relativeList[] = substr($file->getPathname(), strlen($localDir) + 1);
        }
        sort($relativeList, SORT_STRING);

        foreach ($relativeList as $relative) {
            if ($relative === self::MANIFEST) {
                continue;
            }
            $this->putFile($localDir.'/'.$relative, $artifactDir.'/'.$relative);
        }
        if (! in_array(self::MANIFEST, $relativeList, true)) {
            throw new RuntimeException('manifest.json がありません');
        }
        $this->putFile($localDir.'/'.self::MANIFEST, $artifactDir.'/'.self::MANIFEST);
    }

    private function putFile(string $localPath, string $remotePath): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("ファイルを開けません: {$localPath}");
        }
        try {
            if ($this->disk()->writeStream($remotePath, $stream) === false) {
                throw new RuntimeException("成果物の書き込みに失敗しました: {$remotePath}");
            }
        } finally {
            fclose($stream);
        }
    }
}

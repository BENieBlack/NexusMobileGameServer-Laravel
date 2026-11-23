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

    /** 成果物のディレクトリ名 `{deploy_key}_{hash の先頭 16 文字}`（db.md 4.2・6.5.2）。検証・入力の検査はすべてこの 1 か所 */
    public const DIR_PATTERN = '/^[0-9]{10}_[0-9a-f]{16}$/D';

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

    /**
     * 成果物のディレクトリの中に 1 ファイルを書く（QA 完了の印 final.json。manifest.json の後に別に書く）
     *
     * @throws RuntimeException 書き込みに失敗したとき
     */
    public function writeFile(string $artifactDir, string $name, string $contents): void
    {
        if ($this->disk()->put($artifactDir.'/'.$name, $contents) === false) {
            throw new RuntimeException("成果物の書き込みに失敗しました: {$artifactDir}/{$name}");
        }
    }

    /**
     * `{artifactDir}/` の中身（`.tmp` を含まない）をローカルの作業ディレクトリへ写す（import の pull・復元の元の取得）。
     * 完成の印（manifest.json）が無いディレクトリは写さずに false を返す
     *
     * @throws RuntimeException 読み取り・書き込みに失敗したとき
     */
    public function pull(string $artifactDir, string $localDir): bool
    {
        if (! $this->isComplete($artifactDir)) {
            return false;
        }
        $localDir = rtrim($localDir, '/');
        $prefix = $artifactDir.'/';
        foreach ($this->disk()->allFiles($artifactDir) as $path) {
            $relative = substr($path, strlen($prefix));
            $target = $localDir.'/'.$relative;
            $parent = dirname($target);
            if (! is_dir($parent) && ! mkdir($parent, 0755, true) && ! is_dir($parent)) {
                throw new RuntimeException("作業ディレクトリを作れません: {$parent}");
            }
            $in = $this->disk()->readStream($path);
            $out = fopen($target, 'wb');
            if (! is_resource($in) || $out === false) {
                throw new RuntimeException("成果物を読めません: {$path}");
            }
            try {
                if (stream_copy_to_stream($in, $out) === false) {
                    throw new RuntimeException("成果物のコピーに失敗しました: {$path}");
                }
            } finally {
                fclose($in);
                fclose($out);
            }
        }

        return true;
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

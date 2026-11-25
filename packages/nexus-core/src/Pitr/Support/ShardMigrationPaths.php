<?php

namespace Nexus\Core\Pitr\Support;

/**
 * ShardMigrationPaths
 *
 * シャードへ流すマイグレーションのパスを集める
 *
 * trx と log は同じシャードDB（trx1..N）に同居しているので、
 * マイグレーションは各パッケージの database/migrations/shard/ にまとめて置く。
 * 一覧を手で持つとパッケージを増やすたびに更新が要るため、
 * packages/ を走査して見つける。
 */
final class ShardMigrationPaths
{
    /** シャード用マイグレーションのディレクトリ（base_path() からの相対） */
    private const DIRECTORY = 'database/migrations/shard';

    /**
     * 最後に流すパッケージ
     *
     * TiDB向けの変換は対象テーブルが揃ったあとに流す必要がある。
     * ファイル名の日付順では保証できないため、パスの並びで最後に回す。
     */
    private const LAST_PACKAGES = ['nexus-tidb'];

    /**
     * シャード用（trx / log）のマイグレーションパスを返す
     *
     * artisan migrate の --path は base_path() からの相対パスで解釈される。
     *
     * @return list<string>
     */
    public static function find(): array
    {
        $paths = [];

        // アプリ自身が持つマイグレーション
        if (is_dir(base_path(self::DIRECTORY))) {
            $paths[] = self::DIRECTORY;
        }

        $packagesDir = base_path('../packages');

        if (! is_dir($packagesDir)) {
            return $paths;
        }

        $lastPaths = [];

        foreach (self::findPackageNames($packagesDir) as $packageName) {
            $relativePath = "../packages/{$packageName}/".self::DIRECTORY;

            if (! is_dir(base_path($relativePath))) {
                continue;
            }

            if (in_array($packageName, self::LAST_PACKAGES, true)) {
                $lastPaths[] = $relativePath;

                continue;
            }

            $paths[] = $relativePath;
        }

        return array_merge($paths, $lastPaths);
    }

    /**
     * パッケージ名を名前順に返す
     *
     * @return list<string>
     */
    private static function findPackageNames(string $packagesDir): array
    {
        $names = [];

        foreach ((array) scandir($packagesDir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (! is_dir($packagesDir.'/'.$entry)) {
                continue;
            }

            $names[] = (string) $entry;
        }

        sort($names);

        return $names;
    }
}

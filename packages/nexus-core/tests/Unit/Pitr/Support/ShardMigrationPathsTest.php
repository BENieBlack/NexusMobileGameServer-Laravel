<?php

namespace Nexus\Core\Tests\Unit\Pitr\Support;

use Nexus\Core\Pitr\Support\ShardMigrationPaths;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ShardMigrationPaths のテスト
 *
 * trx / log のマイグレーションは各パッケージの database/migrations/shard/ にある。
 * 一覧を手で持つとパッケージを増やすたびに更新が要るため、
 * packages/ を走査して集める。
 */
class ShardMigrationPathsTest extends TestCase
{
    #[Test]
    public function パッケージのshardマイグレーションを集める(): void
    {
        $paths = ShardMigrationPaths::find();

        $this->assertContains('../packages/nexus-core/database/migrations/shard', $paths);
        $this->assertContains('../packages/nexus-album/database/migrations/shard', $paths);
        $this->assertContains('database/migrations/shard', $paths, 'アプリ自身のshardも含む');
    }

    #[Test]
    public function tidbの変換は最後に流す(): void
    {
        // 対象テーブルが揃ってからでないと変換できない。
        // ファイル名の日付順では保証できないのでパスの並びで最後に回している
        $paths = ShardMigrationPaths::find();
        $tidbPath = '../packages/nexus-tidb/database/migrations/shard';

        $this->assertContains($tidbPath, $paths);
        $this->assertSame($tidbPath, end($paths), 'nexus-tidb が最後になっていない');
    }

    #[Test]
    public function trxとlogの旧ディレクトリは含まない(): void
    {
        foreach (ShardMigrationPaths::find() as $path) {
            $this->assertStringEndsWith('database/migrations/shard', $path);
        }
    }

    #[Test]
    public function 同じパスを重複して返さない(): void
    {
        $paths = ShardMigrationPaths::find();

        $this->assertSame(array_values(array_unique($paths)), $paths);
    }
}

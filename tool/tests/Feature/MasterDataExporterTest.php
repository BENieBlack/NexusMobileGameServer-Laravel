<?php

namespace Tests\Feature;

use App\Services\MasterDataExporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MasterDataExporter の並び順・決定性・整数型のテスト
 *
 * 本来のスキーマは api / packages のマイグレーションが持つが、CI の tool ジョブはそれを入れない
 * ため、テストに必要な列だけでテーブルを作る（tool 専用のテスト用 mst データベースにだけ作る）。
 * 書き出し先は public/masterdata なので、テストが作ったディレクトリだけ後で消す。
 */
class MasterDataExporterTest extends TestCase
{
    private const TABLES = [
        'mst_export_str' => 'id VARCHAR(255) NOT NULL PRIMARY KEY, created_at DATETIME NULL, updated_at DATETIME NULL',
        'mst_export_composite' => 'a VARCHAR(255) NOT NULL, b INT NOT NULL, v VARCHAR(255) NULL, created_at DATETIME NULL, updated_at DATETIME NULL, PRIMARY KEY (a, b)',
        'mst_export_big' => 'id VARCHAR(255) NOT NULL PRIMARY KEY, deploy_key BIGINT NOT NULL DEFAULT 2026010101',
        'mst_export_num' => 'id INT NOT NULL PRIMARY KEY',
    ];

    /** @var array<int, string> */
    private array $createdDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::connection('mst')->getDatabaseName();
        $this->assertSame('nexus-testing-tool-master', $database, 'tool 専用以外の mst データベースにはテーブルを作らない');

        // 他のテストが残した mst_* があると出力に混ざるので、tool 専用 DB の mst_* を一旦すべて落とす
        foreach (DB::connection('mst')->select("SHOW TABLES LIKE 'mst_%'") as $row) {
            DB::connection('mst')->statement('DROP TABLE `'.array_values((array) $row)[0].'`');
        }
        foreach (self::TABLES as $table => $columns) {
            DB::connection('mst')->statement("CREATE TABLE `{$table}` ({$columns})");
        }
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            DB::connection('mst')->statement("DROP TABLE IF EXISTS `{$table}`");
        }
        foreach ($this->createdDirs as $dir) {
            File::deleteDirectory($dir);
        }

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function export(?MasterDataExporter $exporter = null): array
    {
        $result = ($exporter ?? new MasterDataExporter)->export();
        $this->createdDirs[] = public_path('masterdata/'.$result['hash']);

        return $result;
    }

    private function sqlitePath(array $result, string $group): string
    {
        return public_path('masterdata/'.$result['hash'].'/'.$result['tables'][$group]['file_name']);
    }

    /** @return array<int, array<string, mixed>> */
    private function readSqlite(string $path, string $sql): array
    {
        $pdo = new \PDO('sqlite:'.$path);

        return $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    #[Test]
    public function 行は主キーの昇順で書き出される_文字列は大小どおり_複合キーは列の順(): void
    {
        $mst = DB::connection('mst');
        // 挿入順をわざと崩す。大文字小文字・長さの違う文字列 id を含める
        foreach (['b', 'a_10', 'C', 'a_2', 'a', 'ab', 'A0'] as $id) {
            $mst->table('mst_export_str')->insert(['id' => $id]);
        }
        foreach ([['y', 1], ['x', 2], ['x', 10], ['y', 0], ['x', 1]] as [$a, $b]) {
            $mst->table('mst_export_composite')->insert(['a' => $a, 'b' => $b]);
        }
        foreach ([10, 9, 100, 2] as $id) {
            $mst->table('mst_export_num')->insert(['id' => $id]);
        }

        $result = $this->export();

        $ids = array_column($this->readSqlite($this->sqlitePath($result, 'mst_export_str'), 'SELECT id FROM mst_export_str'), 'id');
        // BINARY 昇順（バイト順）。大文字は小文字より前（照合順序 _ci なら a, A0, ... の並びになる）
        $this->assertSame(['A0', 'C', 'a', 'a_10', 'a_2', 'ab', 'b'], $ids);

        $keys = array_map(
            static fn ($r) => $r['a'].':'.$r['b'],
            $this->readSqlite($this->sqlitePath($result, 'mst_export_composite'), 'SELECT a, b FROM mst_export_composite')
        );
        $this->assertSame(['x:1', 'x:2', 'x:10', 'y:0', 'y:1'], $keys);

        $nums = array_column($this->readSqlite($this->sqlitePath($result, 'mst_export_num'), 'SELECT id FROM mst_export_num'), 'id');
        $this->assertSame([2, 9, 10, 100], array_map('intval', $nums));
    }

    #[Test]
    public function 同じmstを2回書き出すとバイト列とハッシュが一致する(): void
    {
        $mst = DB::connection('mst');
        foreach (['c', 'a', 'b'] as $id) {
            $mst->table('mst_export_str')->insert(['id' => $id, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00']);
        }

        $first = $this->export();
        $firstBytes = file_get_contents($this->sqlitePath($first, 'mst_export_str'));

        // 同じ内容でも挿入順・物理順が変わる状況を作る（削除して逆順に入れ直す）
        $mst->table('mst_export_str')->delete();
        foreach (['b', 'a', 'c'] as $id) {
            $mst->table('mst_export_str')->insert(['id' => $id, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00']);
        }
        $second = $this->export();
        $secondBytes = file_get_contents($this->sqlitePath($second, 'mst_export_str'));

        $this->assertSame($firstBytes, $secondBytes);
        $this->assertSame($first['hash'], $second['hash']);
        $this->assertSame($first['tables']['mst_export_str']['hash'], $second['tables']['mst_export_str']['hash']);
        $this->assertSame(hash('sha256', $firstBytes), $first['tables']['mst_export_str']['hash']);
    }

    #[Test]
    public function bigintの値はintegerのまま保存される(): void
    {
        DB::connection('mst')->table('mst_export_big')->insert(['id' => 'x', 'deploy_key' => 9999999999]);

        $result = $this->export();

        $rows = $this->readSqlite($this->sqlitePath($result, 'mst_export_big'), 'SELECT typeof(deploy_key) AS t, deploy_key AS v FROM mst_export_big');
        $this->assertSame('integer', $rows[0]['t']);
        $this->assertSame(9999999999, (int) $rows[0]['v']);
    }

    #[Test]
    public function 渡した接続で読む(): void
    {
        DB::connection('mst')->table('mst_export_str')->insert(['id' => 'only']);

        $result = $this->export((new MasterDataExporter)->withConnection(DB::connection('mst')));

        $ids = array_column($this->readSqlite($this->sqlitePath($result, 'mst_export_str'), 'SELECT id FROM mst_export_str'), 'id');
        $this->assertSame(['only'], $ids);
    }

    #[Test]
    public function sho_w_table_sの順が変わってもマニフェストハッシュは同じ(): void
    {
        DB::connection('mst')->table('mst_export_str')->insert(['id' => 'a']);
        DB::connection('mst')->table('mst_export_num')->insert(['id' => 1]);

        $normal = $this->export();
        $reversed = $this->export(new class extends MasterDataExporter
        {
            protected function listTableNames(): array
            {
                // SHOW TABLES が逆順で返しても groupTables が並びを固定することを見る
                return ['mst_export_str', 'mst_export_num', 'mst_export_composite', 'mst_export_big'];
            }
        });

        $this->assertSame($normal['hash'], $reversed['hash']);
        $this->assertSame(array_keys($normal['tables']), array_keys($reversed['tables']));
        $keys = array_keys($normal['tables']);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys);
    }

    #[Test]
    public function コンテナから解決したexporterは既定でmstを読む(): void
    {
        DB::connection('mst')->table('mst_export_str')->insert(['id' => 'from_mst']);
        // 既定接続(sqlite)側にだけ別の mst_* を置く。取り違えるとこちらが出る
        DB::statement('CREATE TABLE mst_export_default_only (id VARCHAR(255) NOT NULL PRIMARY KEY)');

        $result = $this->export(app(MasterDataExporter::class));

        $this->assertSame(
            ['mst_export_big', 'mst_export_composite', 'mst_export_num', 'mst_export_str'],
            array_keys($result['tables'])
        );
        $ids = array_column($this->readSqlite($this->sqlitePath($result, 'mst_export_str'), 'SELECT id FROM mst_export_str'), 'id');
        $this->assertSame(['from_mst'], $ids);
    }
}

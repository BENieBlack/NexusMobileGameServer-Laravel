<?php

namespace Tests\Feature;

use App\Services\MasterImportService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * MasterImportService のレアリティ変換のテスト
 *
 * mst_* を TRUNCATE するため、テスト用の mst データベース（phpunit.xml の DB_MASTER_DATABASE）にだけ
 * 流す。本来のスキーマは api / packages のマイグレーションが持つが、CI の tool ジョブはそれを
 * 入れないため、テストに必要な列だけでテーブルを作る（rarity の型は本物に揃える）。
 */
class MasterImportServiceTest extends TestCase
{
    private const TABLES = [
        'mst_unit_level' => 'rarity ENUM(\'UR\',\'SSR\',\'SR\',\'R\',\'UC\',\'C\') NOT NULL, level INT UNSIGNED NOT NULL, PRIMARY KEY (rarity, level)',
        'mst_unit' => 'id VARCHAR(255) NOT NULL PRIMARY KEY, rarity ENUM(\'UR\',\'SSR\',\'SR\',\'R\',\'UC\',\'C\') NOT NULL',
        'mst_mailbox_content' => 'id INT UNSIGNED NOT NULL PRIMARY KEY, rarity ENUM(\'C\',\'UC\',\'R\',\'SR\',\'SSR\',\'UR\') NULL',
        'mst_gacha_prize' => 'id VARCHAR(255) NOT NULL PRIMARY KEY, rarity TINYINT UNSIGNED NOT NULL',
        'mst_equipment' => 'id VARCHAR(255) NOT NULL PRIMARY KEY, rarity ENUM(\'UR\',\'SSR\',\'SR\',\'R\',\'UC\',\'C\') NOT NULL',
        'mst_item' => 'id VARCHAR(255) NOT NULL PRIMARY KEY, name VARCHAR(255) NOT NULL',
        'mst_gacha_step_bonus' => 'id VARCHAR(255) NOT NULL PRIMARY KEY, bonus_rarity TINYINT UNSIGNED NULL',
    ];

    private const ALL_RARITIES = ['UR', 'SSR', 'SR', 'R', 'UC', 'C'];

    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::connection('mst')->getDatabaseName();
        // api のテスト用（nexus-testing-master）を DROP しないよう、tool 専用の DB であることまで確認する
        $this->assertStringContainsString('testing', $database, 'テスト用以外の mst データベースには TRUNCATE を流さない');
        $this->assertStringContainsString('tool-master', $database, 'tool 専用以外の mst データベースにはテーブルを作らない');

        foreach (self::TABLES as $table => $columns) {
            DB::connection('mst')->statement("DROP TABLE IF EXISTS `{$table}`");
            DB::connection('mst')->statement("CREATE TABLE `{$table}` ({$columns})");
        }
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            DB::connection('mst')->statement("DROP TABLE IF EXISTS `{$table}`");
        }

        parent::tearDown();
    }

    #[Test]
    public function 列挙型の列のテーブルにはレアリティが文字列のまま保存される(): void
    {
        $rows = [];
        foreach (self::ALL_RARITIES as $i => $rarity) {
            $rows[] = ['rarity' => $rarity, 'level' => (string) ($i + 1)];
        }

        $result = (new MasterImportService)->import('mst_unit_level', ['rarity', 'level'], $rows);

        $this->assertSame(6, $result['inserted']);
        $saved = DB::connection('mst')->table('mst_unit_level')->pluck('rarity', 'level')->all();
        foreach (self::ALL_RARITIES as $i => $rarity) {
            $this->assertSame($rarity, $saved[$i + 1]);
        }
    }

    #[Test]
    public function mst_unitでもレアリティが入れ替わらない(): void
    {
        $rows = array_map(fn (string $r) => ['id' => "unit_{$r}", 'rarity' => $r], self::ALL_RARITIES);

        (new MasterImportService)->import('mst_unit', ['id', 'rarity'], $rows);

        foreach (self::ALL_RARITIES as $rarity) {
            $this->assertSame(
                $rarity,
                DB::connection('mst')->table('mst_unit')->where('id', "unit_{$rarity}")->value('rarity'),
            );
        }
    }

    #[Test]
    public function 先頭がコモンの列挙型でも文字列のまま正しく保存され空はnullになる(): void
    {
        $rows = [];
        foreach (self::ALL_RARITIES as $i => $rarity) {
            $rows[] = ['id' => (string) ($i + 1), 'rarity' => $rarity];
        }
        $rows[] = ['id' => '7', 'rarity' => ''];

        (new MasterImportService)->import('mst_mailbox_content', ['id', 'rarity'], $rows);

        $saved = DB::connection('mst')->table('mst_mailbox_content')->pluck('rarity', 'id')->all();
        foreach (self::ALL_RARITIES as $i => $rarity) {
            $this->assertSame($rarity, $saved[$i + 1]);
        }
        $this->assertNull($saved[7]);
    }

    #[Test]
    public function tinyint列は文字列のレアリティを6から1の数値で保存する(): void
    {
        $rows = [];
        foreach (self::ALL_RARITIES as $rarity) {
            $rows[] = ['id' => $rarity, 'rarity' => $rarity];
        }

        (new MasterImportService)->import('mst_gacha_prize', ['id', 'rarity'], $rows);

        $expected = ['UR' => 6, 'SSR' => 5, 'SR' => 4, 'R' => 3, 'UC' => 2, 'C' => 1];
        foreach ($expected as $id => $number) {
            $this->assertSame($number, (int) DB::connection('mst')->table('mst_gacha_prize')->where('id', $id)->value('rarity'));
        }
    }

    #[Test]
    public function tinyint列は数値で書かれたシートもそのまま取り込める(): void
    {
        $rows = [['id' => 'a', 'rarity' => '6'], ['id' => 'b', 'rarity' => 1]];

        (new MasterImportService)->import('mst_gacha_prize', ['id', 'rarity'], $rows);

        $this->assertSame(6, (int) DB::connection('mst')->table('mst_gacha_prize')->where('id', 'a')->value('rarity'));
        $this->assertSame(1, (int) DB::connection('mst')->table('mst_gacha_prize')->where('id', 'b')->value('rarity'));
    }

    #[Test]
    public function bonus_rarityも文字列は数値に変換され空はnullになる(): void
    {
        $rows = [['id' => 'a', 'bonus_rarity' => 'SSR'], ['id' => 'b', 'bonus_rarity' => '']];

        (new MasterImportService)->import('mst_gacha_step_bonus', ['id', 'bonus_rarity'], $rows);

        $this->assertSame(5, (int) DB::connection('mst')->table('mst_gacha_step_bonus')->where('id', 'a')->value('bonus_rarity'));
        $this->assertNull(DB::connection('mst')->table('mst_gacha_step_bonus')->where('id', 'b')->value('bonus_rarity'));
    }

    /**
     * @return array<string, array{string, string, mixed}>
     */
    public static function invalidRarities(): array
    {
        return [
            'ENUM列の未知の文字列' => ['mst_unit', 'rarity', 'XX'],
            'ENUM列の数値' => ['mst_unit', 'rarity', 6],
            'ENUM列の数字文字列' => ['mst_unit', 'rarity', '6'],
            'ENUM列の小文字' => ['mst_unit', 'rarity', 'ur'],
            'tinyint列の未知の文字列' => ['mst_gacha_prize', 'rarity', 'XX'],
            'tinyint列の範囲外の数値' => ['mst_gacha_prize', 'rarity', 7],
            'tinyint列の0' => ['mst_gacha_prize', 'rarity', 0],
            'bonus_rarityの未知の文字列' => ['mst_gacha_step_bonus', 'bonus_rarity', 'XX'],
            'bonus_rarityの範囲外の数値' => ['mst_gacha_step_bonus', 'bonus_rarity', 7],
            'tinyint列の空（NOT NULL）' => ['mst_gacha_prize', 'rarity', ''],
        ];
    }

    #[Test]
    #[DataProvider('invalidRarities')]
    public function 未知のレアリティは取り込み前にエラーで弾かれ既存の行が残る(string $table, string $column, mixed $value): void
    {
        $isEnum = $table === 'mst_unit';
        $existing = match ($table) {
            'mst_unit' => ['id' => 'keep', 'rarity' => 'SR'],
            'mst_gacha_step_bonus' => ['id' => 'keep', 'bonus_rarity' => 3],
            default => ['id' => 'keep', 'rarity' => 3],
        };
        DB::connection('mst')->table($table)->insert($existing);

        try {
            (new MasterImportService)->import($table, ['id', $column], [
                ['id' => 'ok', $column => $isEnum ? 'UR' : 6],
                ['id' => 'bad', $column => $value],
            ]);
            $this->fail('RuntimeException が投げられるはず');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($column, $e->getMessage());
            $this->assertStringContainsString('行 3', $e->getMessage());
        }

        $this->assertSame(['keep'], DB::connection('mst')->table($table)->pluck('id')->all());
    }

    #[Test]
    public function 不正値が21件以上あるときは先頭20件だけ表示してほか件数を出す(): void
    {
        DB::connection('mst')->table('mst_unit')->insert(['id' => 'keep', 'rarity' => 'SR']);
        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = ['id' => "bad_{$i}", 'rarity' => 'XX'];
        }

        try {
            (new MasterImportService)->import('mst_unit', ['id', 'rarity'], $rows);
            $this->fail('RuntimeException が投げられるはず');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('行 21:', $e->getMessage());
            $this->assertStringNotContainsString('行 22:', $e->getMessage());
            $this->assertStringContainsString('ほか 5 件', $e->getMessage());
        }

        $this->assertSame(['keep'], DB::connection('mst')->table('mst_unit')->pluck('id')->all());
    }

    #[Test]
    public function mst_equipmentもmst_unitと同じ列挙型でレアリティが入れ替わらない(): void
    {
        $rows = array_map(fn (string $r) => ['id' => "eq_{$r}", 'rarity' => $r], self::ALL_RARITIES);

        (new MasterImportService)->import('mst_equipment', ['id', 'rarity'], $rows);

        foreach (self::ALL_RARITIES as $rarity) {
            $this->assertSame(
                $rarity,
                DB::connection('mst')->table('mst_equipment')->where('id', "eq_{$rarity}")->value('rarity'),
            );
        }
    }

    #[Test]
    public function rarity列を持たないテーブルはレアリティの検証を受けない(): void
    {
        // rarity というヘッダがあっても、テーブルに列が無ければ無視される。名前の列も素通りする
        $result = (new MasterImportService)->import('mst_item', ['id', 'name', 'rarity'], [
            ['id' => 'a', 'name' => 'XX', 'rarity' => 'XX'],
        ]);

        $this->assertSame(1, $result['inserted']);
        $this->assertSame('XX', DB::connection('mst')->table('mst_item')->where('id', 'a')->value('name'));
    }
}

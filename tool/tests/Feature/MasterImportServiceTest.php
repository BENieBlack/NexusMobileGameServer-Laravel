<?php

namespace Tests\Feature;

use App\Services\MasterImportService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MasterImportService の行の変換（convertValue・rarityColumnsOf）のテスト
 *
 * 版の DB への投入（MasterVersionDatabase）が使う部品。rarity の型は本物の mst スキーマに揃えたテーブルを、
 * テスト用の mst データベース（phpunit.xml の DB_MASTER_DATABASE）に作って判定させる。
 * CI の tool ジョブは api / packages のマイグレーションを入れないため、必要な列だけでテーブルを作る。
 * ID の規則・重複の検査は MasterSheetValidatorTest が持つ。
 */
class MasterImportServiceTest extends TestCase
{
    private const TABLES = [
        'mst_unit' => 'id VARCHAR(255) NOT NULL PRIMARY KEY, rarity ENUM(\'UR\',\'SSR\',\'SR\',\'R\',\'UC\',\'C\') NOT NULL',
        'mst_mailbox_content' => 'id VARCHAR(255) NOT NULL PRIMARY KEY, mst_mailbox_id VARCHAR(255) NOT NULL, content_type VARCHAR(255) NOT NULL, content_mst_id VARCHAR(255) NOT NULL, rarity ENUM(\'C\',\'UC\',\'R\',\'SR\',\'SSR\',\'UR\') NULL, UNIQUE KEY uk_mailbox_content (mst_mailbox_id, content_type, content_mst_id)',
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
        $this->assertStringContainsString('testing', $database, 'テスト用以外の mst データベースにはテーブルを作らない');
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

    /**
     * 表・列の型を判定して convertValue に通す
     */
    private function convert(string $table, string $column, mixed $value): mixed
    {
        $service = new MasterImportService;
        $types = $service->rarityColumnsOf($table, ['id', $column]);
        $nullable = DB::connection('mst')->select("SHOW COLUMNS FROM `{$table}` WHERE Field = ?", [$column])[0]->Null === 'YES';

        return $service->convertValue($column, $value, $nullable, $types[$column] ?? null);
    }

    #[Test]
    public function 列の型はenumと整数だけが判定される(): void
    {
        $service = new MasterImportService;

        $this->assertSame(['kind' => 'enum', 'values' => self::ALL_RARITIES], $service->rarityColumnsOf('mst_unit', ['id', 'rarity'])['rarity']);
        $this->assertSame(['kind' => 'enum', 'values' => array_reverse(self::ALL_RARITIES)], $service->rarityColumnsOf('mst_mailbox_content', ['rarity'])['rarity']);
        $this->assertSame(['kind' => 'int'], $service->rarityColumnsOf('mst_gacha_prize', ['rarity'])['rarity']);
        $this->assertSame(['kind' => 'int'], $service->rarityColumnsOf('mst_gacha_step_bonus', ['bonus_rarity'])['bonus_rarity']);
        $this->assertSame([], $service->rarityColumnsOf('mst_item', ['id', 'name', 'rarity']));
    }

    #[Test]
    public function 列挙型の列はレアリティを文字列のまま返す(): void
    {
        foreach (['mst_unit', 'mst_equipment', 'mst_mailbox_content'] as $table) {
            foreach (self::ALL_RARITIES as $rarity) {
                $this->assertSame($rarity, $this->convert($table, 'rarity', $rarity));
            }
        }
    }

    #[Test]
    public function nul_l可の列だけ空文字がnullになる(): void
    {
        $this->assertNull($this->convert('mst_mailbox_content', 'rarity', ''));
        $this->assertNull($this->convert('mst_gacha_step_bonus', 'bonus_rarity', ''));
        $this->assertSame('', $this->convert('mst_item', 'name', ''));
    }

    #[Test]
    public function tinyint列は文字列のレアリティを6から1の数値へ変換する(): void
    {
        $expected = ['UR' => 6, 'SSR' => 5, 'SR' => 4, 'R' => 3, 'UC' => 2, 'C' => 1];
        foreach ($expected as $rarity => $number) {
            $this->assertSame($number, $this->convert('mst_gacha_prize', 'rarity', $rarity));
            $this->assertSame($number, $this->convert('mst_gacha_step_bonus', 'bonus_rarity', $rarity));
        }
    }

    #[Test]
    public function tinyint列は数値で書かれたシートもそのまま受け付ける(): void
    {
        $this->assertSame(6, $this->convert('mst_gacha_prize', 'rarity', '6'));
        $this->assertSame(1, $this->convert('mst_gacha_prize', 'rarity', 1));
    }

    #[Test]
    public function rarity列を持たない表の値は変換されない(): void
    {
        $this->assertSame('XX', $this->convert('mst_item', 'name', 'XX'));
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
    public function 未知のレアリティは例外になる(string $table, string $column, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($column);

        $this->convert($table, $column, $value);
    }
}

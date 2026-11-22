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
        'mst_unit_level' => 'id VARCHAR(191) NOT NULL PRIMARY KEY, rarity ENUM(\'UR\',\'SSR\',\'SR\',\'R\',\'UC\',\'C\') NOT NULL, level INT UNSIGNED NOT NULL, UNIQUE KEY uk_unit_level (rarity, level)',
        'mst_equipment_level' => 'id VARCHAR(191) NOT NULL PRIMARY KEY, rarity ENUM(\'UR\',\'SSR\',\'SR\',\'R\',\'UC\',\'C\') NOT NULL, level INT UNSIGNED NOT NULL, UNIQUE KEY uk_equipment_level (rarity, level)',
        'mst_player_level' => 'id VARCHAR(191) NOT NULL PRIMARY KEY, level INT UNSIGNED NOT NULL, UNIQUE KEY uk_player_level (level)',
        'mst_unit' => 'id VARCHAR(191) NOT NULL PRIMARY KEY, rarity ENUM(\'UR\',\'SSR\',\'SR\',\'R\',\'UC\',\'C\') NOT NULL',
        'mst_mailbox_content' => 'id VARCHAR(191) NOT NULL PRIMARY KEY, mst_mailbox_id VARCHAR(191) NOT NULL, content_type VARCHAR(191) NOT NULL, content_mst_id VARCHAR(191) NOT NULL, rarity ENUM(\'C\',\'UC\',\'R\',\'SR\',\'SSR\',\'UR\') NULL, UNIQUE KEY uk_mailbox_content (mst_mailbox_id, content_type, content_mst_id)',
        'mst_event_content' => 'id VARCHAR(191) NOT NULL PRIMARY KEY, mst_event_id VARCHAR(191) NOT NULL, content_type VARCHAR(191) NOT NULL, content_mst_id VARCHAR(191) NOT NULL, UNIQUE KEY uk_event_content (mst_event_id, content_type, content_mst_id)',
        'mst_mission_content' => 'id VARCHAR(191) NOT NULL PRIMARY KEY, mst_mission_id VARCHAR(191) NOT NULL, content_type VARCHAR(191) NOT NULL, content_mst_id VARCHAR(191) NOT NULL, UNIQUE KEY uk_mission_content (mst_mission_id, content_type, content_mst_id)',
        'mst_reward_track_content' => 'id VARCHAR(191) NOT NULL PRIMARY KEY, mst_reward_track_milestone_id VARCHAR(191) NOT NULL, mst_reward_track_line_id VARCHAR(191) NOT NULL, content_type VARCHAR(191) NOT NULL, content_mst_id VARCHAR(191) NOT NULL, UNIQUE KEY uk_reward_track_content (mst_reward_track_milestone_id, mst_reward_track_line_id, content_type, content_mst_id)',
        'mst_tag_content' => 'id VARCHAR(191) NOT NULL PRIMARY KEY, mst_tag_id VARCHAR(191) NOT NULL, content_type VARCHAR(191) NOT NULL, content_mst_id VARCHAR(191) NOT NULL, UNIQUE KEY uk_tag_content (mst_tag_id, content_type, content_mst_id)',
        'mst_trade_product_content' => 'id VARCHAR(191) NOT NULL PRIMARY KEY, mst_trade_product_id VARCHAR(191) NOT NULL, content_type VARCHAR(191) NOT NULL, content_mst_id VARCHAR(191) NOT NULL, UNIQUE KEY uk_trade_product_content (mst_trade_product_id, content_type, content_mst_id)',
        'mst_login_bonus_content' => 'id VARCHAR(191) NOT NULL PRIMARY KEY, mst_login_bonus_id VARCHAR(191) NOT NULL, day INT UNSIGNED NOT NULL DEFAULT 0, content_type VARCHAR(191) NOT NULL, content_mst_id VARCHAR(191) NOT NULL, sort_desc INT UNSIGNED NOT NULL DEFAULT 0, UNIQUE KEY uk_login_bonus_content (mst_login_bonus_id, day, content_type, content_mst_id)',
        'mst_vip_login_bonus_content' => 'id VARCHAR(191) NOT NULL PRIMARY KEY, mst_vip_login_bonus_id VARCHAR(191) NOT NULL, day INT UNSIGNED NOT NULL, content_type VARCHAR(191) NOT NULL, content_mst_id VARCHAR(191) NOT NULL, sort_desc INT UNSIGNED NOT NULL DEFAULT 0, UNIQUE KEY uk_vip_login_bonus_content (mst_vip_login_bonus_id, day, content_type, content_mst_id)',
        'mst_vip_level_reward' => 'id VARCHAR(191) NOT NULL PRIMARY KEY, vip_level SMALLINT NOT NULL, content_type VARCHAR(191) NOT NULL, content_mst_id VARCHAR(191) NOT NULL, UNIQUE KEY uk_vip_level_reward (vip_level, content_type, content_mst_id)',
        'mst_billing_platform_product' => 'id VARCHAR(191) NOT NULL PRIMARY KEY, billing_platform ENUM(\'app_store\',\'google_play\',\'pay_pal\',\'stripe\') NOT NULL, platform_product_id VARCHAR(255) NOT NULL, UNIQUE KEY uk_billing_platform_product (billing_platform, platform_product_id)',
        'mst_in_app_purchase' => 'id VARCHAR(191) NOT NULL PRIMARY KEY, type ENUM(\'diamond\',\'pack\',\'pass\') NOT NULL',
        'mst_in_app_purchase__l10n' => 'mst_in_app_purchase_id VARCHAR(191) NOT NULL, language VARCHAR(10) NOT NULL, name VARCHAR(191) NOT NULL, PRIMARY KEY (mst_in_app_purchase_id, language)',
        'mst_in_app_purchase_content' => 'id VARCHAR(191) NOT NULL PRIMARY KEY, mst_in_app_purchase_id VARCHAR(191) NOT NULL, content_type VARCHAR(191) NOT NULL, content_mst_id VARCHAR(191) NOT NULL, UNIQUE KEY uk_purchase_content (mst_in_app_purchase_id, content_type, content_mst_id)',
        'mst_in_app_purchase_effect' => 'id VARCHAR(191) NOT NULL PRIMARY KEY, mst_in_app_purchase_id VARCHAR(191) NOT NULL, effect_type VARCHAR(191) NOT NULL, UNIQUE KEY uk_purchase_effect (mst_in_app_purchase_id, effect_type)',
        'mst_gacha_prize' => 'id VARCHAR(191) NOT NULL PRIMARY KEY, rarity TINYINT UNSIGNED NOT NULL',
        'mst_equipment' => 'id VARCHAR(191) NOT NULL PRIMARY KEY, rarity ENUM(\'UR\',\'SSR\',\'SR\',\'R\',\'UC\',\'C\') NOT NULL',
        'mst_item' => 'id VARCHAR(191) NOT NULL PRIMARY KEY, name VARCHAR(191) NOT NULL',
        'mst_gacha_step_bonus' => 'id VARCHAR(191) NOT NULL PRIMARY KEY, bonus_rarity TINYINT UNSIGNED NULL',
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
            $rows[] = ['id' => $rarity.':'.($i + 1), 'rarity' => $rarity, 'level' => (string) ($i + 1)];
        }

        $result = (new MasterImportService)->import('mst_unit_level', ['id', 'rarity', 'level'], $rows);

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
            $rows[] = $this->mailboxRow('item_'.($i + 1), $rarity);
        }
        $rows[] = $this->mailboxRow('item_7', '');

        (new MasterImportService)->import('mst_mailbox_content', ['id', 'mst_mailbox_id', 'content_type', 'content_mst_id', 'rarity'], $rows);

        $saved = DB::connection('mst')->table('mst_mailbox_content')->pluck('rarity', 'content_mst_id')->all();
        foreach (self::ALL_RARITIES as $i => $rarity) {
            $this->assertSame($rarity, $saved['item_'.($i + 1)]);
        }
        $this->assertNull($saved['item_7']);
    }

    /**
     * @return array<string, string>
     */
    private function mailboxRow(string $contentMstId, string $rarity): array
    {
        return [
            'id' => 'mail_001:item:'.$contentMstId,
            'mst_mailbox_id' => 'mail_001',
            'content_type' => 'item',
            'content_mst_id' => $contentMstId,
            'rarity' => $rarity,
        ];
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

    // ========================================
    // id 列の事前チェック（TRUNCATE の前に中止して既存行を残す）
    // ========================================

    /**
     * @return array<string, array{array<int, string>, array<int, array<string, mixed>>, string}>
     */
    public static function invalidIdSheets(): array
    {
        $ok = ['id' => 'UR:1', 'rarity' => 'UR', 'level' => '1'];

        return [
            'シートに id 列が無い' => [['rarity', 'level'], [['rarity' => 'UR', 'level' => '1']], 'id 列がありません'],
            'id が空' => [['id', 'rarity', 'level'], [$ok, ['id' => '', 'rarity' => 'SR', 'level' => '2']], 'id が空'],
            'id が重複' => [['id', 'rarity', 'level'], [$ok, $ok], '重複'],
            'id が大文字小文字違いで重複' => [['id', 'rarity', 'level'], [$ok, ['id' => 'ur:1', 'rarity' => 'SR', 'level' => '1']], '重複'],
            'id が 191 文字超' => [['id', 'rarity', 'level'], [['id' => str_repeat('a', 192), 'rarity' => 'UR', 'level' => '1']], '191 文字'],
            'id が規則と違う' => [['id', 'rarity', 'level'], [['id' => 'UR:2', 'rarity' => 'UR', 'level' => '1']], '規則'],
        ];
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<string, mixed>>  $rows
     */
    #[Test]
    #[DataProvider('invalidIdSheets')]
    public function idが不正なシートは取り込み前に中止され既存の行が残る(array $headers, array $rows, string $message): void
    {
        DB::connection('mst')->table('mst_unit_level')->insert(['id' => 'C:1', 'rarity' => 'C', 'level' => 1]);

        try {
            (new MasterImportService)->import('mst_unit_level', $headers, $rows);
            $this->fail('RuntimeException が投げられるはず');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertStringContainsString('既存のデータは変更していません', $e->getMessage());
        }

        $this->assertSame(['C:1'], DB::connection('mst')->table('mst_unit_level')->pluck('id')->all());
    }

    #[Test]
    public function idがちょうど191文字なら取り込める(): void
    {
        $id = str_repeat('a', 191);
        $result = (new MasterImportService)->import('mst_item', ['id', 'name'], [['id' => $id, 'name' => 'x']]);

        $this->assertSame(1, $result['inserted']);
    }

    #[Test]
    public function 装備レベルはidが規則と違えば中止され既存の行が残る(): void
    {
        DB::connection('mst')->table('mst_equipment_level')->insert(['id' => 'C:1', 'rarity' => 'C', 'level' => 1]);

        try {
            (new MasterImportService)->import('mst_equipment_level', ['id', 'rarity', 'level'], [
                ['id' => 'SR:1', 'rarity' => 'SR', 'level' => '1'],
                ['id' => 'SR-2', 'rarity' => 'SR', 'level' => '2'],
            ]);
            $this->fail('RuntimeException が投げられるはず');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('行 3', $e->getMessage());
        }

        $this->assertSame(['C:1'], DB::connection('mst')->table('mst_equipment_level')->pluck('id')->all());
    }

    #[Test]
    public function プレイヤーレベルはidがレベルの文字列でなければ中止され既存の行が残る(): void
    {
        DB::connection('mst')->table('mst_player_level')->insert(['id' => '1', 'level' => 1]);

        try {
            (new MasterImportService)->import('mst_player_level', ['id', 'level'], [
                ['id' => '1', 'level' => '1'],
                ['id' => 'lv2', 'level' => '2'],
            ]);
            $this->fail('RuntimeException が投げられるはず');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('行 3', $e->getMessage());
        }

        $this->assertSame(['1'], DB::connection('mst')->table('mst_player_level')->pluck('id')->all());
    }

    #[Test]
    public function プレイヤーレベルはidが規則どおりなら取り込める(): void
    {
        $result = (new MasterImportService)->import('mst_player_level', ['id', 'level'], [
            ['id' => '1', 'level' => '1'],
            ['id' => '2', 'level' => 2],
        ]);

        $this->assertSame(2, $result['inserted']);
        $this->assertSame(['1' => 1, '2' => 2], DB::connection('mst')->table('mst_player_level')->pluck('level', 'id')->all());
    }

    #[Test]
    public function id列を持たないテーブルはid検証の対象外(): void
    {
        DB::connection('mst')->statement('DROP TABLE IF EXISTS `mst_no_id_table`');
        DB::connection('mst')->statement('CREATE TABLE `mst_no_id_table` (code INT UNSIGNED NOT NULL PRIMARY KEY)');

        try {
            $result = (new MasterImportService)->import('mst_no_id_table', ['code'], [['code' => '1']]);
            $this->assertSame(1, $result['inserted']);
        } finally {
            DB::connection('mst')->statement('DROP TABLE IF EXISTS `mst_no_id_table`');
        }
    }

    // ========================================
    // *_content 系の id（親 ID・content_type・content_mst_id を ':' でつなぐ）
    // ========================================

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function contentTables(): array
    {
        return [
            'mst_event_content' => ['mst_event_content', ['mst_event_id']],
            'mst_mailbox_content' => ['mst_mailbox_content', ['mst_mailbox_id']],
            'mst_mission_content' => ['mst_mission_content', ['mst_mission_id']],
            'mst_reward_track_content' => ['mst_reward_track_content', ['mst_reward_track_milestone_id', 'mst_reward_track_line_id']],
            'mst_tag_content' => ['mst_tag_content', ['mst_tag_id']],
            'mst_trade_product_content' => ['mst_trade_product_content', ['mst_trade_product_id']],
            'mst_in_app_purchase_content' => ['mst_in_app_purchase_content', ['mst_in_app_purchase_id']],
        ];
    }

    /**
     * @param  list<string>  $parentColumns
     * @return array<string, string>
     */
    private function contentRow(array $parentColumns, string $parentValue, string $contentType, string $contentMstId): array
    {
        $row = [];
        $parts = [];
        foreach ($parentColumns as $column) {
            $row[$column] = $parentValue;
            $parts[] = $parentValue;
        }
        $row['content_type'] = $contentType;
        $row['content_mst_id'] = $contentMstId;
        $parts[] = $contentType;
        $parts[] = $contentMstId;

        return ['id' => implode(':', $parts)] + $row;
    }

    /**
     * @param  list<string>  $parentColumns
     */
    #[Test]
    #[DataProvider('contentTables')]
    public function content系は規則どおりのidなら取り込める(string $table, array $parentColumns): void
    {
        $rows = [
            $this->contentRow($parentColumns, 'parent_a', 'item', 'item_potion'),
            // 最後の列（content_mst_id）は ':' を含んでよい
            $this->contentRow($parentColumns, 'parent_a', 'item', 'item:with:colon'),
        ];

        $result = (new MasterImportService)->import($table, array_keys($rows[0]), $rows);

        $this->assertSame(2, $result['inserted']);
        $this->assertSame(2, DB::connection('mst')->table($table)->count());
    }

    /**
     * @param  list<string>  $parentColumns
     */
    #[Test]
    #[DataProvider('contentTables')]
    public function content系はidが規則と違えば中止され既存の行が残る(string $table, array $parentColumns): void
    {
        $seed = $this->contentRow($parentColumns, 'parent_a', 'item', 'old');
        DB::connection('mst')->table($table)->insert($seed);

        $bad = $this->contentRow($parentColumns, 'parent_a', 'item', 'new');
        $bad['id'] = 'something_else';

        try {
            (new MasterImportService)->import($table, array_keys($bad), [$bad]);
            $this->fail('RuntimeException が投げられるはず');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('規則', $e->getMessage());
            $this->assertStringContainsString('既存のデータは変更していません', $e->getMessage());
        }

        $this->assertSame([$seed['id']], DB::connection('mst')->table($table)->pluck('id')->all());
    }

    /**
     * @param  list<string>  $parentColumns
     */
    #[Test]
    #[DataProvider('contentTables')]
    public function content系は親_i_dにコロンを含む行を拒否し既存の行が残る(string $table, array $parentColumns): void
    {
        $seed = $this->contentRow($parentColumns, 'parent_a', 'item', 'old');
        DB::connection('mst')->table($table)->insert($seed);

        // 規則どおりの id でも、親 ID に ':' があれば拒否する（a:b + c と a + b:c が同じ id になりうる）
        $bad = $this->contentRow($parentColumns, 'parent:a', 'item', 'new');

        try {
            (new MasterImportService)->import($table, array_keys($bad), [$bad]);
            $this->fail('RuntimeException が投げられるはず');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($parentColumns[0], $e->getMessage());
            $this->assertStringContainsString('行 2', $e->getMessage());
            $this->assertStringContainsString('既存のデータは変更していません', $e->getMessage());
        }

        $this->assertSame([$seed['id']], DB::connection('mst')->table($table)->pluck('id')->all());
    }

    /**
     * @param  list<string>  $parentColumns
     */
    #[Test]
    #[DataProvider('contentTables')]
    public function content系はcontent_typeにコロンを含む行を拒否する(string $table, array $parentColumns): void
    {
        $bad = $this->contentRow($parentColumns, 'parent_a', 'item:x', 'new');

        try {
            (new MasterImportService)->import($table, array_keys($bad), [$bad]);
            $this->fail('RuntimeException が投げられるはず');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('content_type', $e->getMessage());
        }

        $this->assertSame(0, DB::connection('mst')->table($table)->count());
    }

    /**
     * @param  list<string>  $parentColumns
     */
    #[Test]
    #[DataProvider('contentTables')]
    public function content系はid列が無いシートを中止する(string $table, array $parentColumns): void
    {
        $row = $this->contentRow($parentColumns, 'parent_a', 'item', 'x');
        unset($row['id']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('id 列がありません');
        (new MasterImportService)->import($table, array_keys($row), [$row]);
    }

    // ========================================
    // login・vip の id（login / vip の content は day を含む 4 列、vip_level_reward は vip_level 始まりの 3 列）
    // ========================================

    /**
     * @return array<string, string>
     */
    private function loginRow(string $table, string $parentColumn, string $parent, int $day, string $type, string $mstId, int $sortDesc = 0): array
    {
        return [
            'id' => implode(':', [$parent, $day, $type, $mstId]),
            $parentColumn => $parent,
            'day' => (string) $day,
            'content_type' => $type,
            'content_mst_id' => $mstId,
            'sort_desc' => (string) $sortDesc,
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function loginTables(): array
    {
        return [
            'mst_login_bonus_content' => ['mst_login_bonus_content', 'mst_login_bonus_id'],
            'mst_vip_login_bonus_content' => ['mst_vip_login_bonus_content', 'mst_vip_login_bonus_id'],
        ];
    }

    #[Test]
    #[DataProvider('loginTables')]
    public function loginとvipのcontentは規則どおりのidなら取り込める(string $table, string $parentColumn): void
    {
        $rows = [
            $this->loginRow($table, $parentColumn, 'bonus_a', 1, 'item', 'item_potion', 2),
            $this->loginRow($table, $parentColumn, 'bonus_a', 2, 'item', 'item:with:colon', 1),
        ];

        $result = (new MasterImportService)->import($table, array_keys($rows[0]), $rows);

        $this->assertSame(2, $result['inserted']);
    }

    #[Test]
    #[DataProvider('loginTables')]
    public function loginとvipのcontentはidが規則と違えば中止され既存の行が残る(string $table, string $parentColumn): void
    {
        $seed = $this->loginRow($table, $parentColumn, 'bonus_a', 1, 'item', 'old');
        DB::connection('mst')->table($table)->insert($seed);

        $bad = $this->loginRow($table, $parentColumn, 'bonus_a', 1, 'item', 'new');
        $bad['id'] = 'bonus_a:item:new';

        try {
            (new MasterImportService)->import($table, array_keys($bad), [$bad]);
            $this->fail('RuntimeException が投げられるはず');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('規則', $e->getMessage());
        }

        $this->assertSame([$seed['id']], DB::connection('mst')->table($table)->pluck('id')->all());
    }

    #[Test]
    #[DataProvider('loginTables')]
    public function loginとvipのcontentは親_i_dやcontent_typeにコロンを含む行を拒否する(string $table, string $parentColumn): void
    {
        foreach ([['parent:a', 'item', $parentColumn], ['parent_a', 'item:x', 'content_type']] as [$parent, $type, $column]) {
            $bad = $this->loginRow($table, $parentColumn, $parent, 1, $type, 'new');
            try {
                (new MasterImportService)->import($table, array_keys($bad), [$bad]);
                $this->fail('RuntimeException が投げられるはず');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($column, $e->getMessage());
            }
        }
        $this->assertSame(0, DB::connection('mst')->table($table)->count());
    }

    #[Test]
    public function vipレベル報酬はidがvip_levelから始まる規則で検証される(): void
    {
        $ok = ['id' => '3:item:free_diamond', 'vip_level' => '3', 'content_type' => 'item', 'content_mst_id' => 'free_diamond'];
        $result = (new MasterImportService)->import('mst_vip_level_reward', array_keys($ok), [$ok]);
        $this->assertSame(1, $result['inserted']);

        $bad = ['id' => 'vip_3:item:free_diamond'] + $ok;
        try {
            (new MasterImportService)->import('mst_vip_level_reward', array_keys($bad), [$bad]);
            $this->fail('RuntimeException が投げられるはず');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('規則', $e->getMessage());
        }
        $this->assertSame(['3:item:free_diamond'], DB::connection('mst')->table('mst_vip_level_reward')->pluck('id')->all());
    }

    #[Test]
    public function vipログインボーナスはsort_descが同じ日の同値を拒否し既存の行が残る(): void
    {
        $table = 'mst_vip_login_bonus_content';
        $seed = $this->loginRow($table, 'mst_vip_login_bonus_id', 'vip_a', 1, 'item', 'old', 1);
        DB::connection('mst')->table($table)->insert($seed);

        // 同じ親・同じ日・同じ sort_desc（中身は違う）
        $rows = [
            $this->loginRow($table, 'mst_vip_login_bonus_id', 'vip_a', 1, 'item', 'item_a', 5),
            $this->loginRow($table, 'mst_vip_login_bonus_id', 'vip_a', 1, 'item', 'item_b', 5),
        ];

        try {
            (new MasterImportService)->import($table, array_keys($rows[0]), $rows);
            $this->fail('RuntimeException が投げられるはず');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sort_desc', $e->getMessage());
            $this->assertStringContainsString('行 3', $e->getMessage());
            $this->assertStringContainsString('既存のデータは変更していません', $e->getMessage());
        }

        $this->assertSame([$seed['id']], DB::connection('mst')->table($table)->pluck('id')->all());
    }

    #[Test]
    public function vipログインボーナスはsort_descが違うか日や親が違えば同値でも取り込める(): void
    {
        $table = 'mst_vip_login_bonus_content';
        $rows = [
            $this->loginRow($table, 'mst_vip_login_bonus_id', 'vip_a', 1, 'item', 'item_a', 5),
            $this->loginRow($table, 'mst_vip_login_bonus_id', 'vip_a', 1, 'item', 'item_b', 4),
            $this->loginRow($table, 'mst_vip_login_bonus_id', 'vip_a', 2, 'item', 'item_a', 5),
            $this->loginRow($table, 'mst_vip_login_bonus_id', 'vip_b', 1, 'item', 'item_a', 5),
        ];

        $result = (new MasterImportService)->import($table, array_keys($rows[0]), $rows);

        $this->assertSame(4, $result['inserted']);
    }

    #[Test]
    public function vipログインボーナスはsort_desc列が無いと全行が0の同値として拒否される(): void
    {
        $table = 'mst_vip_login_bonus_content';
        $rows = [
            $this->loginRow($table, 'mst_vip_login_bonus_id', 'vip_a', 1, 'item', 'item_a'),
            $this->loginRow($table, 'mst_vip_login_bonus_id', 'vip_a', 1, 'item', 'item_b'),
        ];
        foreach ($rows as &$row) {
            unset($row['sort_desc']);
        }
        unset($row);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sort_desc');
        (new MasterImportService)->import($table, array_keys($rows[0]), $rows);
    }

    #[Test]
    public function vipログインボーナスの親idは数値として比べず文字列で比べる(): void
    {
        $table = 'mst_vip_login_bonus_content';
        // '1' と '01' は数値なら同じだが、親 ID は別の ID（取り込める）
        $rows = [
            $this->loginRow($table, 'mst_vip_login_bonus_id', '1', 1, 'item', 'item_a', 5),
            $this->loginRow($table, 'mst_vip_login_bonus_id', '01', 1, 'item', 'item_b', 5),
        ];

        $result = (new MasterImportService)->import($table, array_keys($rows[0]), $rows);

        $this->assertSame(2, $result['inserted']);
    }

    #[Test]
    public function vipログインボーナスのdayとsort_descは数値として比べる(): void
    {
        $table = 'mst_vip_login_bonus_content';
        // day '1' と '01'、sort_desc '5' と '05' は同じ値
        $a = $this->loginRow($table, 'mst_vip_login_bonus_id', 'vip_a', 1, 'item', 'item_a', 5);
        $b = $this->loginRow($table, 'mst_vip_login_bonus_id', 'vip_a', 1, 'item', 'item_b', 5);
        $b['day'] = '01';
        $b['id'] = 'vip_a:01:item:item_b';
        $b['sort_desc'] = '05';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sort_desc');
        (new MasterImportService)->import($table, array_keys($a), [$a, $b]);
    }

    #[Test]
    public function 通常のログインボーナスはsort_descが同値でも取り込める(): void
    {
        // 通常側は同値の並びを content_type・content_mst_id の昇順で決めるので、拒否の対象外
        $table = 'mst_login_bonus_content';
        $rows = [
            $this->loginRow($table, 'mst_login_bonus_id', 'bonus_a', 1, 'item', 'item_a', 1),
            $this->loginRow($table, 'mst_login_bonus_id', 'bonus_a', 1, 'item', 'item_b', 1),
        ];

        $result = (new MasterImportService)->import($table, array_keys($rows[0]), $rows);

        $this->assertSame(2, $result['inserted']);
    }

    // ========================================
    // billing の id（platform_product は {billing_platform}:{platform_product_id}、effect は {親}:{effect_type}、
    // mst_in_app_purchase は人が決める値で規則なし）
    // ========================================

    #[Test]
    public function プラットフォーム商品はidが規則どおりなら取り込め_platform_product_idのコロンも許す(): void
    {
        $rows = [
            ['id' => 'app_store:com.example.game.diamond100', 'billing_platform' => 'app_store', 'platform_product_id' => 'com.example.game.diamond100'],
            ['id' => 'stripe:price:abc', 'billing_platform' => 'stripe', 'platform_product_id' => 'price:abc'],
        ];

        $result = (new MasterImportService)->import('mst_billing_platform_product', array_keys($rows[0]), $rows);

        $this->assertSame(2, $result['inserted']);
    }

    #[Test]
    public function プラットフォーム商品はidが規則と違えば中止され既存の行が残る(): void
    {
        $seed = ['id' => 'app_store:old', 'billing_platform' => 'app_store', 'platform_product_id' => 'old'];
        DB::connection('mst')->table('mst_billing_platform_product')->insert($seed);

        $bad = ['id' => 'AppStore:new', 'billing_platform' => 'app_store', 'platform_product_id' => 'new'];

        try {
            (new MasterImportService)->import('mst_billing_platform_product', array_keys($bad), [$bad]);
            $this->fail('RuntimeException が投げられるはず');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('規則', $e->getMessage());
        }

        $this->assertSame(['app_store:old'], DB::connection('mst')->table('mst_billing_platform_product')->pluck('id')->all());
    }

    #[Test]
    public function アプリ内課金効果は規則どおりのidなら取り込め_違えば拒否する(): void
    {
        $ok = ['id' => 'iap_monthly_pass:ad_skip', 'mst_in_app_purchase_id' => 'iap_monthly_pass', 'effect_type' => 'ad_skip'];
        $this->assertSame(1, (new MasterImportService)->import('mst_in_app_purchase_effect', array_keys($ok), [$ok])['inserted']);

        $bad = ['id' => 'ad_skip'] + $ok;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('規則');
        (new MasterImportService)->import('mst_in_app_purchase_effect', array_keys($bad), [$bad]);
    }

    #[Test]
    public function アプリ内課金商品は規則を持たず_idの空と重複だけ拒否する(): void
    {
        $ok = [['id' => 'iap_diamond_100', 'type' => 'diamond'], ['id' => 'iap_starter_pack', 'type' => 'pack']];
        $this->assertSame(2, (new MasterImportService)->import('mst_in_app_purchase', ['id', 'type'], $ok)['inserted']);

        $dup = [['id' => 'iap_diamond_100', 'type' => 'diamond'], ['id' => 'IAP_DIAMOND_100', 'type' => 'diamond']];
        try {
            (new MasterImportService)->import('mst_in_app_purchase', ['id', 'type'], $dup);
            $this->fail('RuntimeException が投げられるはず');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('重複', $e->getMessage());
        }
        $this->assertSame(2, DB::connection('mst')->table('mst_in_app_purchase')->count());
    }

    #[Test]
    public function アプリ内課金のl10nはid列を持たないのでid検証の対象外(): void
    {
        $rows = [['mst_in_app_purchase_id' => 'iap_diamond_100', 'language' => 'ja', 'name' => 'ダイヤ100']];

        $result = (new MasterImportService)->import('mst_in_app_purchase__l10n', array_keys($rows[0]), $rows);

        $this->assertSame(1, $result['inserted']);
        $this->assertSame(['iap_diamond_100'], DB::connection('mst')->table('mst_in_app_purchase__l10n')->pluck('mst_in_app_purchase_id')->all());
    }
}

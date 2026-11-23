<?php

namespace Tests\Unit;

use App\Services\GoogleSpreadsheetService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * シートの値の読み取り・正規化（db.md 6.1・6.2）。Google API は呼ばず、値の配列だけで確かめる。
 */
class GoogleSpreadsheetServiceTest extends TestCase
{
    /** @return array<int, array<int, string>> */
    private function configValues(): array
    {
        return [
            ['', 'mst_config', '', '', ''],
            ['is_active', 'id', 'value', 'deploy_key', '__deleted'],
            ['1', 'a', '300', '2026010101', ''],
            ['0', 'a', '1', '2026020101', ''],
            ['', '', '', '', ''],
            ['TRUE', 'a', '', '2026060101', 'TRUE'],
        ];
    }

    #[Test]
    public function 既存の形の_rows_は変わらず内部キーも混ざらない(): void
    {
        $result = GoogleSpreadsheetService::parseSheetValues($this->configValues(), 'mst_config');

        $this->assertSame(['id', 'value', 'deploy_key', '__deleted'], $result['mst_config']['headers']);
        $this->assertSame(
            ['id' => 'a', 'value' => '300', 'deploy_key' => '2026010101', '__deleted' => ''],
            $result['mst_config']['rows'][0],
        );
        $this->assertSame(4, $result['mst_config']['raw_count']);
        $this->assertSame(2, $result['mst_config']['skipped_count']);
    }

    #[Test]
    public function 行番号はシート上の行を指す(): void
    {
        $result = GoogleSpreadsheetService::parseSheetValues($this->configValues(), 'mst_config');

        // ヘッダ 2 行の次が 3 行目。is_active 無効の 4 行目・空の 5 行目は出ない。
        $this->assertSame([3, 6], $result['mst_config']['row_nos']);
        $entries = $result['mst_config']['entries'];
        $this->assertSame('a', $entries[0]['id']);
        $this->assertSame('2026010101', $entries[0]['deploy_key']);
        $this->assertSame('', $entries[0]['deleted']);
        $this->assertSame(['value' => '300'], $entries[0]['columns']);
        $this->assertSame('TRUE', $entries[1]['deleted']);
        $this->assertTrue($result['mst_config']['has_deploy_key_column']);
    }

    #[Test]
    public function deploy_key_は検証せず書かれたまま持つ(): void
    {
        $values = $this->configValues();
        $values[2][3] = '202601010';
        $values[5][3] = '';

        $entries = GoogleSpreadsheetService::parseSheetValues($values, 't')['mst_config']['entries'];

        $this->assertSame('202601010', $entries[0]['deploy_key']);
        $this->assertSame('', $entries[1]['deploy_key']);
    }

    #[Test]
    public function deploy_key_列が無いテーブルは_null_で、フラグが偽になる(): void
    {
        $values = [
            ['', 'mst_x', ''],
            ['is_active', 'id', 'v'],
            ['1', 'x1', '5'],
        ];

        $table = GoogleSpreadsheetService::parseSheetValues($values, 't')['mst_x'];

        $this->assertFalse($table['has_deploy_key_column']);
        $this->assertNull($table['entries'][0]['deploy_key']);
        $this->assertNull($table['entries'][0]['deleted']);
    }

    #[Test]
    public function l10n_は元の行番号と親の_deploy_key・deleted_を持つ(): void
    {
        $values = [
            ['', 'mst_unit', '', '', 'mst_unit__l10n', ''],
            ['is_active', 'id', 'deploy_key', '__deleted', 'name.ja', 'name.en'],
            ['1', 'u1', '2026010101', '', '剣', 'Sword'],
            ['1', 'u2', '2026020101', 'TRUE', '', 'Bow'],
        ];

        $result = GoogleSpreadsheetService::parseSheetValues($values, 't');
        $l10n = $result['mst_unit__l10n'];

        $this->assertTrue($l10n['has_deploy_key_column']);
        $this->assertSame([3, 3, 4], $l10n['row_nos']);
        $this->assertSame(
            ['mst_unit_id' => 'u1', 'language' => 'ja', 'name' => '剣'],
            $l10n['rows'][0],
            'rows に内部キーや親の deploy_key が混ざらない',
        );
        $this->assertSame('ja', $l10n['entries'][0]['language']);
        $this->assertSame('u1', $l10n['entries'][0]['id']);
        $this->assertSame('2026010101', $l10n['entries'][0]['deploy_key']);
        $this->assertSame(['name' => '剣'], $l10n['entries'][0]['columns']);
        $this->assertSame('TRUE', $l10n['entries'][2]['deleted']);
        $this->assertSame('en', $l10n['entries'][2]['language']);
        // 親の行は自分の列をそのまま持つ
        $this->assertSame('TRUE', $result['mst_unit']['entries'][1]['deleted']);
    }

    #[Test]
    public function l10n_自身の_deploy_key_列があればそちらを使う(): void
    {
        $values = [
            ['', 'mst_unit', '', 'mst_unit__l10n', '', ''],
            ['is_active', 'id', 'deploy_key', 'deploy_key', 'name.ja', 'x'],
            ['1', 'u1', '2026010101', '2026030101', '剣', ''],
        ];

        $l10n = GoogleSpreadsheetService::parseSheetValues($values, 't')['mst_unit__l10n'];

        $this->assertSame('2026030101', $l10n['entries'][0]['deploy_key']);
    }

    #[Test]
    public function 複数タブの同じテーブルは合わせ、mst_以外のタブの除外とエラーを集める(): void
    {
        $other = [
            ['', 'mst_config', ''],
            ['is_active', 'id', 'deploy_key'],
            ['1', 'b', '2026010101'],
        ];
        $broken = [
            ['', 'mst_config'],
            ['id', 'deploy_key'],
        ];

        $result = GoogleSpreadsheetService::assembleMasterTabs([
            ['sheet' => 'A/mst_config', 'values' => $this->configValues()],
            ['sheet' => 'B/mst_config2', 'values' => $other],
            ['sheet' => 'B/mst_broken', 'values' => $broken],
            ['sheet' => 'B/mst_empty', 'values' => [['x']]],
            ['sheet' => 'B/memo', 'title' => 'memo', 'values' => $broken],
        ]);

        $this->assertCount(3, $result['tables']['mst_config']['entries']);
        $this->assertSame('A/mst_config', $result['tables']['mst_config']['entries'][0]['sheet']);
        $this->assertSame('B/mst_config2', $result['tables']['mst_config']['entries'][2]['sheet']);
        $this->assertSame(2, $result['tables']['mst_config']['ignored_inactive_rows']);
        $this->assertSame([
            ['sheet' => 'B/mst_empty', 'reason' => 'empty_tab'],
            ['sheet' => 'B/memo', 'reason' => 'not_mst_tab'],
        ], $result['ignored_tab_list']);
        $this->assertCount(1, $result['error_list'], 'mst_ 以外のタブは is_active の検査に入らない');
        $this->assertSame('is_active_column_missing', $result['error_list'][0]['code']);
        $this->assertSame('B/mst_broken', $result['error_list'][0]['sheet']);
    }

    #[Test]
    public function mst_の列に無いヘッダは無視して一覧に出す(): void
    {
        $result = GoogleSpreadsheetService::assembleMasterTabs(
            [['sheet' => 'A/mst_config', 'values' => $this->configValues()]],
            ['mst_config' => ['id', 'value', 'deploy_key']],
        );

        $this->assertSame(['value'], array_keys($result['tables']['mst_config']['entries'][0]['columns']));
        $this->assertSame([], $result['ignored_column_list']);

        $result = GoogleSpreadsheetService::assembleMasterTabs(
            [['sheet' => 'A/mst_config', 'values' => $this->configValues()]],
            ['mst_config' => ['id', 'deploy_key']],
        );

        $this->assertSame([], $result['tables']['mst_config']['entries'][0]['columns']);
        $this->assertSame([['table' => 'mst_config', 'column' => 'value']], $result['ignored_column_list']);
    }

    #[Test]
    public function 空のシートは空を返し、is_active_列が無ければ従来どおり例外になる(): void
    {
        $this->assertSame([], GoogleSpreadsheetService::parseSheetValues([], 't'));

        $this->expectException(\RuntimeException::class);
        GoogleSpreadsheetService::parseSheetValues([['', 'mst_x'], ['id', 'v']], 't');
    }

    #[Test]
    public function l10n_の親に_deploy_key_列が無ければ_null(): void
    {
        $values = [
            ['', 'mst_unit', '', 'mst_unit__l10n'],
            ['is_active', 'id', 'v', 'name.ja'],
            ['1', 'u1', '1', '剣'],
        ];

        $l10n = GoogleSpreadsheetService::parseSheetValues($values, 't')['mst_unit__l10n'];

        $this->assertFalse($l10n['has_deploy_key_column']);
        $this->assertNull($l10n['entries'][0]['deploy_key']);
    }

    #[Test]
    public function 全言語が空の行は作られず_row_nos_は元の行番号のまま(): void
    {
        $values = [
            ['', 'mst_unit', '', 'mst_unit__l10n'],
            ['is_active', 'id', 'deploy_key', 'name.ja'],
            ['1', 'u1', '2026010101', ''],
            ['1', 'u2', '2026010101', '弓'],
        ];

        $l10n = GoogleSpreadsheetService::parseSheetValues($values, 't')['mst_unit__l10n'];

        $this->assertSame([4], $l10n['row_nos']);
        $this->assertSame('u2', $l10n['entries'][0]['id']);
    }

    #[Test]
    public function known_columns_に無い表は列を絞らない(): void
    {
        $result = GoogleSpreadsheetService::assembleMasterTabs(
            [['sheet' => 'A/mst_config', 'values' => $this->configValues()]],
            ['mst_other' => ['id']],
        );

        $this->assertSame(['value'], array_keys($result['tables']['mst_config']['entries'][0]['columns']));
        $this->assertSame([], $result['ignored_column_list']);
    }

    #[Test]
    public function ヘッダだけでデータ行が無い表は_entries_が空(): void
    {
        $values = [
            ['', 'mst_x', ''],
            ['is_active', 'id', 'deploy_key'],
        ];

        $result = GoogleSpreadsheetService::assembleMasterTabs([['sheet' => 'A/mst_x', 'values' => $values]]);

        $this->assertSame([], $result['tables']['mst_x']['entries']);
        $this->assertTrue($result['tables']['mst_x']['has_deploy_key_column']);
    }
}

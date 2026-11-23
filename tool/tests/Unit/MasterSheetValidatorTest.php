<?php

namespace Tests\Unit;

use App\Services\MasterDeployKeyPolicy;
use App\Services\MasterSheetValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * db.md 6.3 の検証規則 V01〜V12 を、各 code の最小例で確かめる（DB を使わない）
 */
class MasterSheetValidatorTest extends TestCase
{
    private const T = 9999999999;

    private function validator(): MasterSheetValidator
    {
        return new MasterSheetValidator(new MasterDeployKeyPolicy(null, [7777777777, 8888888888, 9999999999], ['production', 'staging'], 7000000000, 'local'));
    }

    /**
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    private function e(string $id = 'a', ?string $key = '2026010101', array $o = []): array
    {
        return array_merge([
            'row_no' => 3, 'sheet' => 's/mst_config', 'id' => $id, 'language' => null,
            'deploy_key' => $key, 'deleted' => '', 'columns' => [],
        ], $o);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function assembled(array $entries, string $table = 'mst_config', bool $hasKey = true): array
    {
        return ['tables' => [$table => ['entries' => $entries, 'has_deploy_key_column' => $hasKey, 'ignored_inactive_rows' => 0]], 'error_list' => []];
    }

    /**
     * @param  array<string, mixed>  $assembled
     * @return list<string>
     */
    private function codes(array $assembled, int $t = self::T, ?array $known = null, array $rarity = []): array
    {
        $r = $this->validator()->validate($assembled, $t, $known, $rarity);

        return array_map(fn ($e) => $e['code'], $r['error_list']);
    }

    public function test_正常なら0件(): void
    {
        $r = $this->validator()->validate($this->assembled([$this->e('a'), $this->e('b', '9999999999')]), self::T);
        $this->assertSame(0, $r['error_total']);
        $this->assertSame([], $r['error_list']);
        $this->assertSame([], $r['error_count_by_code']);
    }

    public function test_v01_タブの不備を引き継ぐ(): void
    {
        $a = $this->assembled([]);
        $a['error_list'] = [['code' => 'is_active_column_missing', 'sheet' => 's/mst_x', 'message' => 'm']];
        $this->assertSame(['is_active_column_missing'], $this->codes($a));
    }

    public function test_v02_unknown_table(): void
    {
        $this->assertSame(['unknown_table'], $this->codes($this->assembled([$this->e()], 'Mst-Bad'), self::T, ['mst_config' => ['id']]));
        $this->assertSame(['unknown_table'], $this->codes($this->assembled([$this->e()], 'mst_nothing'), self::T, ['mst_config' => ['id']]));
        $this->assertSame([], $this->codes($this->assembled([$this->e()]), self::T, ['mst_config' => ['id']]));
    }

    public function test_v03_deploy_key_column_missing(): void
    {
        $this->assertSame(['deploy_key_column_missing'], $this->codes($this->assembled([$this->e('a', null)], 'mst_config', false)));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function deployKeyProvider(): array
    {
        return [
            '空' => ['', 'deploy_key_empty'],
            '数字でない' => ['20260101a1', 'deploy_key_not_number'],
            '空白付き' => [' 2026010101', 'deploy_key_not_number'],
            '9桁' => ['202601010', 'deploy_key_legacy_9digits'],
            '旧予約 777' => ['777777777', 'deploy_key_legacy_reserved'],
            '旧予約 888' => ['888888888', 'deploy_key_legacy_reserved'],
            '旧予約 999' => ['999999999', 'deploy_key_legacy_reserved'],
            '8桁' => ['20260101', 'deploy_key_out_of_range'],
            '11桁' => ['20260101011', 'deploy_key_out_of_range'],
            'NN=00' => ['2026010100', 'deploy_key_out_of_range'],
            '2026年より前' => ['2025123101', 'deploy_key_out_of_range'],
            '月13' => ['2026130101', 'deploy_key_out_of_range'],
            '月00' => ['2026000101', 'deploy_key_out_of_range'],
            '日32' => ['2026013201', 'deploy_key_out_of_range'],
            '日00' => ['2026010001', 'deploy_key_out_of_range'],
            '予約帯の誤記' => ['7777777778', 'deploy_key_out_of_range'],
            '予約帯の下限' => ['7000000000', 'deploy_key_out_of_range'],
            '年3000' => ['3000010101', 'deploy_key_out_of_range'],
            '予約帯の手前' => ['6999999999', 'deploy_key_out_of_range'],
            '本物の下限' => ['2026010101', null],
            '本物の上限' => ['2999123199', null],
            '予約キー 7' => ['7777777777', null],
            '予約キー 8' => ['8888888888', null],
            '予約キー 9' => ['9999999999', null],
        ];
    }

    #[DataProvider('deployKeyProvider')]
    public function test_v04_deploy_keyの値(string $key, ?string $code): void
    {
        $this->assertSame($code === null ? [] : [$code], $this->codes($this->assembled([$this->e('a', $key)])));
    }

    public function test_v04_9桁のメッセージは変換を案内する(): void
    {
        $r = $this->validator()->validate($this->assembled([$this->e('a', '202601010')]), self::T);
        $this->assertStringContainsString('2026010101', $r['error_list'][0]['message']);
        $this->assertSame(3, $r['error_list'][0]['row']);
        $this->assertSame('deploy_key', $r['error_list'][0]['column']);
        $this->assertSame('mst_config', $r['error_list'][0]['table']);
        $this->assertSame('s/mst_config', $r['error_list'][0]['sheet']);
    }

    public function test_v05_idの空と空白(): void
    {
        $this->assertSame(['id_empty'], $this->codes($this->assembled([$this->e('')])));
        $this->assertSame(['id_whitespace'], $this->codes($this->assembled([$this->e('a ')])));
        $this->assertSame(['id_whitespace'], $this->codes($this->assembled([$this->e("\u{3000}a")])));
    }

    public function test_v05_l10nの親idとlanguageの空(): void
    {
        $a = $this->assembled([$this->e('', '2026010101', ['language' => 'ja']), $this->e('p', '2026010101', ['language' => '', 'row_no' => 4])], 'mst_unit__l10n');
        $this->assertSame(['l10n_parent_empty', 'l10n_language_empty'], $this->codes($a));
    }

    public function test_v06_deleted_flag_invalid(): void
    {
        $this->assertSame(['deleted_flag_invalid'], $this->codes($this->assembled([$this->e('a', '2026010101', ['deleted' => 'yes'])])));
        foreach (['', '0', 'false', 'FALSE', 'False', '1', 'true', 'TRUE', 'True', null] as $ok) {
            $this->assertSame([], $this->codes($this->assembled([$this->e('a', '2026010101', ['deleted' => $ok])])), (string) $ok);
        }
    }

    public function test_v07_同じ鍵と同じdeploy_keyの行はシートをまたいで重複(): void
    {
        $a = $this->assembled([$this->e('a'), $this->e('a', '2026010101', ['sheet' => 'other/mst_config', 'row_no' => 9]), $this->e('a', '2026020101', ['row_no' => 10])]);
        $r = $this->validator()->validate($a, self::T);
        $this->assertSame(['duplicate_row'], array_column($r['error_list'], 'code'));
        $this->assertSame(9, $r['error_list'][0]['row']);
    }

    public function test_v07_l10nは言語が違えば重複でない(): void
    {
        $a = $this->assembled([$this->e('p', '2026010101', ['language' => 'ja']), $this->e('p', '2026010101', ['language' => 'en', 'row_no' => 4])], 'mst_unit__l10n');
        $this->assertSame([], $this->codes($a));
        $a = $this->assembled([$this->e('p', '2026010101', ['language' => 'ja']), $this->e('p', '2026010101', ['language' => 'ja', 'row_no' => 4])], 'mst_unit__l10n');
        $this->assertSame(['duplicate_row'], $this->codes($a));
    }

    public function test_v08_大文字小文字だけ違う綴り(): void
    {
        $a = $this->assembled([$this->e('UR:1', '2026010101'), $this->e('ur:1', '2026020101', ['row_no' => 4])]);
        $this->assertSame(['id_case_variant'], $this->codes($a));
        $this->assertSame([], $this->codes($this->assembled([$this->e('UR:1', '2026010101'), $this->e('UR:1', '2026020101', ['row_no' => 4])])));
    }

    public function test_v09_no_fold_key(): void
    {
        $this->assertSame(['no_fold_key'], $this->codes($this->assembled([$this->e('')], 'mst_config'), self::T, ['mst_config' => ['value']]));
        // l10n は id 列が無くても鍵を持つ
        $this->assertSame([], $this->codes($this->assembled([$this->e('p', '2026010101', ['language' => 'ja'])], 'mst_unit__l10n'), self::T, ['mst_unit__l10n' => ['name']]));
    }

    public function test_v10_rarityの検査は採用行だけ(): void
    {
        $enum = ['mst_config' => ['rarity' => ['kind' => 'enum', 'values' => ['UR', 'SR']]]];
        $bad = $this->e('a', '2026020101', ['columns' => ['rarity' => 'X']]);
        $this->assertSame(['rarity_invalid'], $this->codes($this->assembled([$bad]), self::T, null, $enum));
        // 畳みで負ける・T より大きい・削除印の行は検査しない
        $this->assertSame([], $this->codes($this->assembled([$bad, $this->e('a', '2026030101', ['columns' => ['rarity' => 'UR']])]), self::T, null, $enum));
        $this->assertSame([], $this->codes($this->assembled([$bad]), 2026010101, null, $enum));
        $this->assertSame([], $this->codes($this->assembled([$this->e('a', '2026020101', ['deleted' => '1', 'columns' => ['rarity' => 'X']])]), self::T, null, $enum));
        $this->assertSame([], $this->codes($this->assembled([$this->e('a', '2026020101', ['columns' => ['rarity' => 'SR']])]), self::T, null, $enum));
    }

    public function test_v10_整数列は_u_r等か1から6(): void
    {
        $int = ['mst_config' => ['rarity' => ['kind' => 'int'], 'bonus_rarity' => ['kind' => 'int']]];
        foreach (['UR', 'C', '1', '6'] as $ok) {
            $this->assertSame([], $this->codes($this->assembled([$this->e('a', '2026010101', ['columns' => ['rarity' => $ok]])]), self::T, null, $int), $ok);
        }
        foreach (['0', '7', 'X', ''] as $ng) {
            $this->assertSame(['rarity_invalid'], $this->codes($this->assembled([$this->e('a', '2026010101', ['columns' => ['bonus_rarity' => $ng]])]), self::T, null, $int), $ng);
        }
    }

    public function test_v11_idの長さと規則とコロン(): void
    {
        $long = str_repeat('a', 256);
        $this->assertSame(['id_too_long'], $this->codes($this->assembled([$this->e($long)])));
        $this->assertSame([], $this->codes($this->assembled([$this->e(str_repeat('a', 255))])));

        $level = fn (string $id, string $rarity, string $level) => $this->e($id, '2026010101', ['columns' => ['rarity' => $rarity, 'level' => $level]]);
        $this->assertSame([], $this->codes($this->assembled([$level('UR:1', 'UR', '1')], 'mst_unit_level')));
        $this->assertSame(['id_rule_mismatch'], $this->codes($this->assembled([$level('UR:2', 'UR', '1')], 'mst_unit_level')));

        $c = fn (string $id, string $p) => $this->e($id, '2026010101', ['columns' => ['mst_event_id' => $p, 'content_type' => 't', 'content_mst_id' => 'x']]);
        $this->assertSame([], $this->codes($this->assembled([$c('e1:t:x', 'e1')], 'mst_event_content')));
        $this->assertSame(['id_colon'], $this->codes($this->assembled([$c('e:1:t:x', 'e:1')], 'mst_event_content')));
    }

    public function test_v11_l10nは検査しない_削除印と負ける行も検査しない(): void
    {
        $this->assertSame([], $this->codes($this->assembled([$this->e(str_repeat('a', 300), '2026010101', ['language' => 'ja'])], 'mst_unit__l10n')));
        $this->assertSame([], $this->codes($this->assembled([$this->e(str_repeat('a', 300), '2026010101', ['deleted' => 'TRUE'])])));
        $this->assertSame([], $this->codes($this->assembled([$this->e(str_repeat('a', 300), '2026020101')]), 2026010101));
    }

    public function test_v12_unique_group_duplicate_は採用行だけ数える(): void
    {
        $row = fn (string $id, string $key, string $sort, int $n, array $o = []) => $this->e($id, $key, $o + ['row_no' => $n, 'columns' => ['mst_vip_login_bonus_id' => 'V1', 'day' => '1', 'sort_desc' => $sort, 'content_type' => 't', 'content_mst_id' => substr($id, -1)]]);
        $table = 'mst_vip_login_bonus_content';
        // id 規則（V11）を満たす id にする
        $mk = fn (string $cid, string $key, string $sort, int $n, array $o = []) => $row("V1:1:t:{$cid}", $key, $sort, $n, $o);
        $a = $this->assembled([$mk('x', '2026010101', '5', 3), $mk('y', '2026010101', '05', 4)], $table);
        $this->assertSame(['unique_group_duplicate'], $this->codes($a));
        // 親 ID は大文字小文字を区別しない
        $upper = $mk('y', '2026010101', '5', 4);
        $upper['columns']['mst_vip_login_bonus_id'] = 'v1';
        $this->assertContains('unique_group_duplicate', $this->codes($this->assembled([$mk('x', '2026010101', '5', 3), $upper], $table)));
        // 削除印の行・畳みで負ける行は数えない
        $b = $this->assembled([$mk('x', '2026010101', '5', 3), $mk('y', '2026010101', '5', 4, ['deleted' => '1'])], $table);
        $this->assertSame([], $this->codes($b));
        $c = $this->assembled([$mk('x', '2026010101', '5', 3), $mk('y', '2026010101', '5', 4)], $table);
        $this->assertSame([], $this->codes($c, 2026010100));
    }

    public function test_構造のエラーがある表は内容を検査しない(): void
    {
        $enum = ['mst_config' => ['rarity' => ['kind' => 'enum', 'values' => ['UR']]]];
        $a = $this->assembled([$this->e('a', '2026010101', ['columns' => ['rarity' => 'X']]), $this->e('b', '202601010')]);
        $this->assertSame(['deploy_key_legacy_9digits'], $this->codes($a, self::T, null, $enum));
    }

    public function test_構造の規則は_tより大きい行にも適用する(): void
    {
        $this->assertSame(['deploy_key_legacy_9digits'], $this->codes($this->assembled([$this->e('a', '202601010')]), 2026010101));
    }

    public function test_20件で切り詰め_総数とcode別件数は全件(): void
    {
        $entries = [];
        for ($i = 0; $i < 25; $i++) {
            $entries[] = $this->e("id{$i}", '202601010', ['row_no' => 3 + $i]);
        }
        $entries[] = $this->e('z', '', ['row_no' => 99]);
        $r = $this->validator()->validate($this->assembled($entries), self::T);
        $this->assertCount(20, $r['error_list']);
        $this->assertSame(26, $r['error_total']);
        $this->assertSame(['deploy_key_legacy_9digits' => 25, 'deploy_key_empty' => 1], $r['error_count_by_code']);
    }

    public function test_dry_runの警告_負ける行と_tより大きい行の内容違反と未来行数(): void
    {
        $enum = ['mst_config' => ['rarity' => ['kind' => 'enum', 'values' => ['UR']]]];
        $entries = [
            $this->e('a', '2026010101', ['columns' => ['rarity' => 'X']]),
            $this->e('a', '2026020101', ['columns' => ['rarity' => 'UR'], 'row_no' => 4]),
            $this->e('b', '2026050101', ['columns' => ['rarity' => 'Y'], 'row_no' => 5]),
            $this->e('c', '2026050101', ['deleted' => '1', 'columns' => ['rarity' => 'Y'], 'row_no' => 6]),
        ];
        $r = $this->validator()->validate($this->assembled($entries), 2026020101, null, $enum, true);
        $this->assertSame(0, $r['error_total']);
        $this->assertSame(2, $r['warning_total']);
        $this->assertSame([3, 5], array_column($r['warning_list'], 'row'));
        $this->assertSame(['rarity_invalid', 'rarity_invalid'], array_column($r['warning_list'], 'code'));
        $this->assertSame(['mst_config' => 2], $r['future_row_count']);

        $quiet = $this->validator()->validate($this->assembled($entries), 2026020101, null, $enum, false);
        $this->assertSame(0, $quiet['warning_total']);
        $this->assertSame(['mst_config' => 2], $quiet['future_row_count']);
    }

    public function test_構造のエラーがある表は_v12も行わない(): void
    {
        $mk = fn (string $cid, int $n, string $key) => $this->e("V1:1:t:{$cid}", $key, ['row_no' => $n, 'columns' => ['mst_vip_login_bonus_id' => 'V1', 'day' => '1', 'sort_desc' => '5', 'content_type' => 't', 'content_mst_id' => $cid]]);
        $table = 'mst_vip_login_bonus_content';
        $this->assertSame(['unique_group_duplicate'], $this->codes($this->assembled([$mk('x', 3, '2026010101'), $mk('y', 4, '2026010101')], $table)));
        $this->assertSame(['deploy_key_legacy_9digits'], $this->codes($this->assembled([$mk('x', 3, '2026010101'), $mk('y', 4, '2026010101'), $mk('z', 5, '202601010')], $table)));
    }

    public function test_v04に当たった行はduplicate_rowにしない(): void
    {
        $this->assertSame(['deploy_key_legacy_9digits', 'deploy_key_legacy_9digits'], $this->codes($this->assembled([$this->e('a', '202601010'), $this->e('a', '202601010', ['row_no' => 4])])));
    }

    public function test_v08のメッセージにlanguageを含む(): void
    {
        $a = $this->assembled([$this->e('P', '2026010101', ['language' => 'ja']), $this->e('p', '2026010101', ['language' => 'ja', 'row_no' => 4])], 'mst_unit__l10n');
        $r = $this->validator()->validate($a, self::T);
        $this->assertSame('id_case_variant', $r['error_list'][0]['code']);
        $this->assertStringContainsString('language', $r['error_list'][0]['message']);
    }
}

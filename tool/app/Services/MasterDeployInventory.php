<?php

namespace App\Services;

/**
 * dry_run が返す 9 桁の棚卸し（純粋関数。DB・設定を読まない）
 *
 * 設計: docs/design/master-distribution/db.md 9.3
 * 入力は GoogleSpreadsheetService::assembleMasterTabs() の戻り値の `tables`（検証の前の全行）。
 * - digit_distribution: 表ごとに deploy_key の形別の行数（nine_digit・ten_digit_real・ten_digit_reserved・other・empty）
 * - legacy_row_list: 9 桁の行（category: legacy_reserved=旧予約キー・n_nonzero=N≠0・n_zero=N=0）。LEGACY_ROW_LIST_MAX 件で切る（総数は legacy_count_by_category）
 * - conversion_conflict_list: N=0 の 9 桁を ×10+1 にすると、同じ鍵に同じ deploy_key の行が既にある組
 * - order_change_key_count: 9 桁と 10 桁にまたがる鍵のうち、変換の前後で畳みの勝者が変わる鍵の数
 * 変換の規則（N=0 を ×10+1。旧予約キーと N≠0 は変換しない）は db.md 9.2。サーバーはシートを書き換えず、数えるだけ。
 */
class MasterDeployInventory
{
    public const LEGACY_ROW_LIST_MAX = 200;

    private const LEGACY_RESERVED_KEYS = ['777777777', '888888888', '999999999'];

    /**
     * @param  array<string, array{entries: array<int, array<string, mixed>>}>  $tables
     * @return array{
     *   digit_distribution: array<string, array{nine_digit: int, ten_digit_real: int, ten_digit_reserved: int, other: int, empty: int}>,
     *   legacy_row_list: array<int, array{table: string, sheet: string, row: int, deploy_key: string, category: string}>,
     *   legacy_count_by_category: array<string, int>,
     *   conversion_conflict_list: array<int, array{table: string, id: string, language: string|null, deploy_key: string, converted_deploy_key: string}>,
     *   order_change_key_count: int
     * }
     */
    public static function build(array $tables, int $reservedFloor): array
    {
        $distribution = [];
        $legacyRows = [];
        $legacyCount = [];
        $conflicts = [];
        $orderChange = 0;

        foreach ($tables as $tableName => $table) {
            $dist = ['nine_digit' => 0, 'ten_digit_real' => 0, 'ten_digit_reserved' => 0, 'other' => 0, 'empty' => 0];
            /** @var array<string, list<array{raw: int, converted: int, is_nine: bool, is_ten: bool}>> $byKey */
            $byKey = [];
            /** @var array<string, array<int, true>> $tenKeys 鍵ごとの 10 桁の値 */
            $tenKeys = [];

            foreach ($table['entries'] as $entry) {
                $value = $entry['deploy_key'] ?? null;
                $value = is_string($value) ? trim($value) : '';
                $isNine = false;
                $isTen = false;
                if ($value === '') {
                    $dist['empty']++;
                } elseif (preg_match('/^\d{9}$/D', $value) === 1) {
                    $dist['nine_digit']++;
                    $isNine = true;
                    $category = in_array($value, self::LEGACY_RESERVED_KEYS, true) ? 'legacy_reserved' : (str_ends_with($value, '0') ? 'n_zero' : 'n_nonzero');
                    $legacyCount[$category] = ($legacyCount[$category] ?? 0) + 1;
                    if (count($legacyRows) < self::LEGACY_ROW_LIST_MAX) {
                        $legacyRows[] = ['table' => $tableName, 'sheet' => (string) ($entry['sheet'] ?? ''), 'row' => (int) ($entry['row_no'] ?? 0), 'deploy_key' => $value, 'category' => $category];
                    }
                } elseif (preg_match('/^\d{10}$/D', $value) === 1) {
                    $isTen = true;
                    if ((int) $value >= $reservedFloor) {
                        $dist['ten_digit_reserved']++;
                    } else {
                        $dist['ten_digit_real']++;
                    }
                } else {
                    $dist['other']++;
                }

                if (! $isNine && ! $isTen) {
                    continue;
                }
                $foldKey = MasterOverlayFolder::foldKey($entry);
                $raw = (int) $value;
                $converted = ($isNine && ! in_array($value, self::LEGACY_RESERVED_KEYS, true) && str_ends_with($value, '0')) ? $raw * 10 + 1 : $raw;
                $byKey[$foldKey][] = ['raw' => $raw, 'converted' => $converted, 'is_nine' => $isNine, 'is_ten' => $isTen];
                if ($isTen) {
                    $tenKeys[$foldKey][$raw] = true;
                }
            }
            // 変換の衝突（10 桁の値が全行そろってから判定する）
            foreach ($table['entries'] as $entry) {
                $value = is_string($entry['deploy_key'] ?? null) ? trim($entry['deploy_key']) : '';
                if (preg_match('/^\d{9}$/D', $value) !== 1 || in_array($value, self::LEGACY_RESERVED_KEYS, true) || ! str_ends_with($value, '0')) {
                    continue;
                }
                $converted = (int) $value * 10 + 1;
                $foldKey = MasterOverlayFolder::foldKey($entry);
                if (isset($tenKeys[$foldKey][$converted])) {
                    $conflicts[] = self::conflict($tableName, $entry, $value, $converted);
                }
            }

            foreach ($byKey as $rows) {
                $hasNine = false;
                $hasTen = false;
                foreach ($rows as $r) {
                    $hasNine = $hasNine || $r['is_nine'];
                    $hasTen = $hasTen || $r['is_ten'];
                }
                if (! $hasNine || ! $hasTen) {
                    continue;
                }
                $winnerBefore = null;
                $winnerAfter = null;
                foreach ($rows as $i => $r) {
                    if ($winnerBefore === null || $r['raw'] > $rows[$winnerBefore]['raw']) {
                        $winnerBefore = $i;
                    }
                    if ($winnerAfter === null || $r['converted'] > $rows[$winnerAfter]['converted']) {
                        $winnerAfter = $i;
                    }
                }
                if ($winnerBefore !== $winnerAfter) {
                    $orderChange++;
                }
            }

            $distribution[$tableName] = $dist;
        }
        ksort($legacyCount);

        return [
            'digit_distribution' => $distribution,
            'legacy_row_list' => $legacyRows,
            'legacy_count_by_category' => $legacyCount,
            'conversion_conflict_list' => $conflicts,
            'order_change_key_count' => $orderChange,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{table: string, id: string, language: string|null, deploy_key: string, converted_deploy_key: string}
     */
    private static function conflict(string $table, array $entry, string $value, int $converted): array
    {
        $language = $entry['language'] ?? null;

        return [
            'table' => $table,
            'id' => (string) ($entry['id'] ?? ''),
            'language' => is_string($language) ? $language : null,
            'deploy_key' => $value,
            'converted_deploy_key' => (string) $converted,
        ];
    }
}

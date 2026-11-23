<?php

namespace App\Services;

/**
 * 全タブの行を、デプロイ時点のキー T 以下で畳む（純粋関数。DB・設定を読まない）
 *
 * 設計: docs/design/master-distribution/db.md 6.4（D3・D4）
 * - 畳みの鍵: 通常のテーブルは id、__l10n は「親の id + language」。mb_strtolower で正規化して比べる（DB の照合順序に合わせる）
 * - 候補は deploy_key <= T の行。鍵ごとに deploy_key が最大の行を 1 つ採る（行単位の置き換え）。
 *   同点は V07（duplicate_row）が検証で拒否するので起きないが、呼ばれたときは入力順で先の行を採る
 * - 採った行が削除印（`__deleted`）なら、その鍵は出さない。削除印より大きい deploy_key の通常行があれば復活する
 * - 親テーブルが置き換えの対象（行が 1 行以上）のとき、親の採用行に無い親 ID の l10n 行は出さない
 *
 * deploy_key が数字だけでない行は候補にしない（検証 V04 で拒否済みの前提。ここでは落とすだけで報告しない）。
 * 入力は GoogleSpreadsheetService::assembleMasterTabs() の戻り値の `tables`。
 */
class MasterOverlayFolder
{
    private const L10N_SUFFIX = '__l10n';

    /**
     * @param  array<string, array{entries: array<int, array<string, mixed>>}>  $tables
     * @return array<string, array{
     *   adopted: array<int, array<string, mixed>>,
     *   adopted_index_list: array<int, int>,
     *   input_rows: int,
     *   replaced: bool
     * }>  adopted は mst に入れる行（入力の entries と同じ形）、adopted_index_list はその行の入力 entries 内の添字（同じ順）
     */
    public static function fold(array $tables, int $deployKeyLimit): array
    {
        $result = [];
        foreach ($tables as $tableName => $table) {
            $entries = array_values($table['entries']);
            $best = [];
            foreach ($entries as $index => $entry) {
                $key = self::deployKeyOf($entry);
                if ($key === null || $key > $deployKeyLimit) {
                    continue;
                }
                $foldKey = self::foldKey($entry);
                if (! isset($best[$foldKey]) || $key > $best[$foldKey]['key']) {
                    $best[$foldKey] = ['key' => $key, 'index' => $index];
                }
            }

            $indexList = [];
            foreach ($best as $picked) {
                if (! self::isDeleted($entries[$picked['index']]['deleted'] ?? null)) {
                    $indexList[] = $picked['index'];
                }
            }
            sort($indexList);

            $result[$tableName] = [
                'adopted' => array_map(fn (int $i) => $entries[$i], $indexList),
                'adopted_index_list' => $indexList,
                'input_rows' => count($entries),
                'replaced' => $entries !== [],
            ];
        }

        // l10n: 親が置き換えの対象のとき、親の採用行に無い親 ID の行を出さない
        foreach ($tables as $tableName => $table) {
            if (! str_ends_with($tableName, self::L10N_SUFFIX)) {
                continue;
            }
            $parent = substr($tableName, 0, -strlen(self::L10N_SUFFIX));
            if (! isset($result[$parent]) || ! $result[$parent]['replaced']) {
                continue;
            }
            $parentIds = [];
            foreach ($result[$parent]['adopted'] as $entry) {
                $parentIds[mb_strtolower((string) ($entry['id'] ?? ''))] = true;
            }
            $entries = array_values($table['entries']);
            $keptIndex = [];
            foreach ($result[$tableName]['adopted_index_list'] as $index) {
                if (isset($parentIds[mb_strtolower((string) ($entries[$index]['id'] ?? ''))])) {
                    $keptIndex[] = $index;
                }
            }
            $result[$tableName]['adopted_index_list'] = $keptIndex;
            $result[$tableName]['adopted'] = array_map(fn (int $i) => $entries[$i], $keptIndex);
        }

        return $result;
    }

    /**
     * 畳みの鍵（db.md 6.4）。大文字小文字だけ違う綴りは同じ鍵になる（綴りの違いは V08 で拒否する）
     *
     * @param  array<string, mixed>  $entry
     */
    public static function foldKey(array $entry): string
    {
        return mb_strtolower((string) ($entry['id'] ?? ''))."\0".mb_strtolower((string) ($entry['language'] ?? ''));
    }

    /**
     * `__deleted` の値が削除印か（`1`・`true`・`TRUE`・`True`。V06）
     */
    public static function isDeleted(mixed $value): bool
    {
        return in_array($value, ['1', 'true', 'TRUE', 'True'], true);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function deployKeyOf(array $entry): ?int
    {
        $value = $entry['deploy_key'] ?? null;
        if (! is_string($value) || preg_match('/^[0-9]{1,18}$/D', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}

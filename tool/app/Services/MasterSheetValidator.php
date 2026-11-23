<?php

namespace App\Services;

use LogicException;
use ReflectionClass;

/**
 * シートの行の検証（V01〜V12）。エラーを集めて返す（純粋関数。DB・Google API を読まない）
 *
 * 設計: docs/design/master-distribution/db.md 6.3、api.md 4 節（`sheet_invalid` の error_list）
 * - 構造の規則（V01〜V09）はすべての行に適用する（deploy_key が T より大きい行も含む）
 * - 内容の規則（V10・V11）は、T 以下の畳みで採られた削除印でない行だけに適用する（P4 の仮置き）
 * - 畳み結果の規則（V12）は採った行に適用する
 * - 構造のエラーがあるテーブルは、畳みの結果が当てにならないので内容（V10・V11）・畳み結果（V12）の規則をすべて行わない
 *   （仮置き。構造を直してから出る。警告も出さない）
 * - V04 に当たった行は V07（duplicate_row）の対象から外す（二重報告を避ける。V08 は対象のまま）
 * - `$collectWarnings` が true のとき、採られなかった行（T より大きい・畳みで負ける）の V10・V11 を警告に集める（止めない）
 *
 * 入力は GoogleSpreadsheetService::assembleMasterTabs() の戻り値。表の列（V02・V09）と rarity 列の型（V10）は DB を読む側が渡す。
 * ID_RULES・UNIQUE_COLUMN_GROUPS などの定義は MasterImportService の private 定数を読み取る（複製しない。
 * 定義の正本は MasterImportService。テストが同じ定義を通る）。
 */
class MasterSheetValidator
{
    /** error_list に出す最大件数（MasterImportService::MAX_REPORTED_ERRORS と同じ） */
    public const MAX_REPORTED = 20;

    /** 9 桁相当の旧予約キー（変換せず拒否する。db.md 9.2） */
    private const LEGACY_RESERVED_KEYS = ['777777777', '888888888', '999999999'];

    private const NOT_DELETED_VALUES = ['', '0', 'false', 'FALSE', 'False'];

    private const L10N_SUFFIX = '__l10n';

    /** @var ReflectionClass<MasterImportService>|null */
    private ?ReflectionClass $importClass = null;

    public function __construct(private readonly MasterDeployKeyPolicy $policy) {}

    /**
     * @param  array{tables: array<string, array{entries: array<int, array<string, mixed>>, has_deploy_key_column: bool}>, error_list?: array<int, array{code: string, sheet: string, message: string}>}  $assembled  assembleMasterTabs の戻り値
     * @param  int  $deployKeyLimit  T（畳みの上限）
     * @param  array<string, array<int, string>>|null  $knownColumns  mst DB の表ごとの列名（V02・V09。null なら DB の検査をしない）
     * @param  array<string, array<string, array{kind: 'enum', values: array<int, string>}|array{kind: 'int'}>>  $rarityColumns  表ごとの rarity・bonus_rarity 列の型（V10。MasterImportService::getRarityColumns と同じ形。無い表は検査しない）
     * @return array{
     *   error_list: array<int, array{sheet: string, table: string|null, row: int|null, column: string|null, code: string, message: string}>,
     *   error_total: int,
     *   error_count_by_code: array<string, int>,
     *   warning_list: array<int, array{sheet: string, table: string|null, row: int|null, column: string|null, code: string, message: string}>,
     *   warning_total: int,
     *   future_row_count: array<string, int>
     * }  error_list・warning_list は最大 MAX_REPORTED 件。error_total・error_count_by_code は全件。future_row_count は表ごとの T より大きい行数（0 の表は出さない）
     */
    public function validate(array $assembled, int $deployKeyLimit, ?array $knownColumns = null, array $rarityColumns = [], bool $collectWarnings = false): array
    {
        /** @var array<string, array<string, mixed>> $errors 重複（同じシート行の l10n 展開など）は 1 件にまとめる */
        $errors = [];
        $add = function (array &$bag, string $sheet, ?string $table, ?int $row, ?string $column, string $code, string $message): void {
            $bag[$sheet."\0".$table."\0".$row."\0".$column."\0".$code] ??= [
                'sheet' => $sheet, 'table' => $table, 'row' => $row, 'column' => $column, 'code' => $code, 'message' => $message,
            ];
        };

        // V01（読み取り側が集めた、タブのエラー）
        foreach ($assembled['error_list'] ?? [] as $error) {
            $add($errors, $error['sheet'], null, null, null, $error['code'], $error['message']);
        }

        $tables = $assembled['tables'];
        $structureBad = [];
        foreach ($tables as $tableName => $table) {
            $entries = array_values($table['entries']);
            $firstSheet = (string) ($entries[0]['sheet'] ?? '');
            $isL10n = str_ends_with($tableName, self::L10N_SUFFIX);

            // V02
            if (preg_match('/^mst_[a-z0-9_]+$/D', $tableName) !== 1 || ($knownColumns !== null && ! array_key_exists($tableName, $knownColumns))) {
                $add($errors, $firstSheet, $tableName, null, null, 'unknown_table', "テーブル「{$tableName}」は mst_ で始まる、DB に存在するテーブルではありません");
                $structureBad[$tableName] = true;

                continue;
            }
            // V09
            if (! $isL10n && $knownColumns !== null && ! in_array('id', $knownColumns[$tableName], true)) {
                $add($errors, $firstSheet, $tableName, null, null, 'no_fold_key', "テーブル {$tableName} は id 列が無く、畳みの鍵を決められません");
                $structureBad[$tableName] = true;

                continue;
            }
            // V03
            $hasKeyColumn = $table['has_deploy_key_column'];
            if (! $hasKeyColumn) {
                $add($errors, $firstSheet, $tableName, null, 'deploy_key', 'deploy_key_column_missing', "テーブル {$tableName} に deploy_key 列がありません");
                $structureBad[$tableName] = true;
            }

            $seen = [];
            $spellings = [];
            foreach ($entries as $entry) {
                $sheet = (string) ($entry['sheet'] ?? '');
                $row = (int) $entry['row_no'];

                // V04
                $keyBad = false;
                if ($hasKeyColumn) {
                    $keyError = $this->checkDeployKey($entry['deploy_key'] ?? null);
                    if ($keyError !== null) {
                        $add($errors, $sheet, $tableName, $row, 'deploy_key', $keyError[0], $keyError[1]);
                        $keyBad = true;
                    }
                }

                // V05
                $id = (string) ($entry['id'] ?? '');
                $language = $isL10n ? (string) ($entry['language'] ?? '') : null;
                if ($id === '') {
                    $add($errors, $sheet, $tableName, $row, $isL10n ? substr($tableName, 0, -strlen(self::L10N_SUFFIX)).'.id' : 'id',
                        $isL10n ? 'l10n_parent_empty' : 'id_empty', $isL10n ? '親の id が空です' : 'id が空です');
                } elseif (preg_match('/^[\s\x{3000}]|[\s\x{3000}]$/u', $id) === 1) {
                    $add($errors, $sheet, $tableName, $row, 'id', 'id_whitespace', "id「{$id}」の前後に空白があります");
                }
                if ($isL10n && $language === '') {
                    $add($errors, $sheet, $tableName, $row, 'language', 'l10n_language_empty', 'language が空です');
                }

                // V06
                $deleted = $entry['deleted'] ?? null;
                if ($deleted !== null && ! MasterOverlayFolder::isDeleted($deleted) && ! in_array($deleted, self::NOT_DELETED_VALUES, true)) {
                    $add($errors, $sheet, $tableName, $row, '__deleted', 'deleted_flag_invalid', "__deleted の値「{$deleted}」は使えません（削除は 1・true・TRUE・True、非削除は空・0・false・FALSE・False）");
                }

                if ($id === '' || ($isL10n && $language === '')) {
                    continue;
                }

                // V07・V08（id・deploy_key が書かれた行を対象にする）
                $foldKey = MasterOverlayFolder::foldKey($entry);
                $spelling = $id."\0".($language ?? '');
                if (! isset($spellings[$foldKey])) {
                    $spellings[$foldKey] = ['spelling' => $spelling, 'row' => $row, 'sheet' => $sheet];
                } elseif ($spellings[$foldKey]['spelling'] !== $spelling) {
                    $add($errors, $sheet, $tableName, $row, $isL10n ? 'id・language' : 'id', 'id_case_variant',
                        "id「{$id}」".($isL10n ? "（language「{$language}」）" : '')."は、{$spellings[$foldKey]['sheet']} の行 {$spellings[$foldKey]['row']} と大文字小文字だけ違う綴りです");
                }
                $deployKey = (string) ($entry['deploy_key'] ?? '');
                if ($hasKeyColumn && ! $keyBad) {
                    $dupKey = $foldKey."\0".$deployKey;
                    if (isset($seen[$dupKey])) {
                        $add($errors, $sheet, $tableName, $row, 'deploy_key', 'duplicate_row',
                            "id「{$id}」の deploy_key {$deployKey} の行が、{$seen[$dupKey]['sheet']} の行 {$seen[$dupKey]['row']} と重複しています");
                    } else {
                        $seen[$dupKey] = ['sheet' => $sheet, 'row' => $row];
                    }
                }
            }
        }

        // 構造のエラーがあるテーブルは内容の検査をしない
        foreach ($errors as $error) {
            if ($error['table'] !== null) {
                $structureBad[$error['table']] = true;
            }
        }

        $folded = MasterOverlayFolder::fold($tables, $deployKeyLimit);
        $warnings = [];
        $futureRowCount = [];
        foreach ($tables as $tableName => $table) {
            if (isset($structureBad[$tableName])) {
                continue;
            }
            $entries = array_values($table['entries']);
            $adoptedFlag = array_fill_keys($folded[$tableName]['adopted_index_list'], true);
            $isL10n = str_ends_with($tableName, self::L10N_SUFFIX);

            foreach ($entries as $index => $entry) {
                $sheet = (string) ($entry['sheet'] ?? '');
                $row = (int) $entry['row_no'];
                $deployKey = (int) ($entry['deploy_key'] ?? 0);
                if ($deployKey > $deployKeyLimit) {
                    $futureRowCount[$tableName] = ($futureRowCount[$tableName] ?? 0) + 1;
                }
                $isAdopted = isset($adoptedFlag[$index]);
                if (! $isAdopted && (! $collectWarnings || MasterOverlayFolder::isDeleted($entry['deleted'] ?? null))) {
                    continue;
                }
                $bag = [];
                foreach ($this->contentViolations($tableName, $entry, $isL10n, $rarityColumns[$tableName] ?? []) as [$column, $code, $message]) {
                    $add($bag, $sheet, $tableName, $row, $column, $code, $message);
                }
                if ($isAdopted) {
                    $errors += $bag;
                } else {
                    $warnings += $bag;
                }
            }

            // V12（採った行）
            foreach ($this->uniqueGroupViolations($tableName, $folded[$tableName]['adopted']) as [$entry, $message]) {
                $add($errors, (string) ($entry['sheet'] ?? ''), $tableName, (int) $entry['row_no'], null, 'unique_group_duplicate', $message);
            }
        }

        $errorList = array_values($errors);
        $countByCode = [];
        foreach ($errorList as $error) {
            $countByCode[$error['code']] = ($countByCode[$error['code']] ?? 0) + 1;
        }
        ksort($futureRowCount);

        return [
            'error_list' => array_slice($errorList, 0, self::MAX_REPORTED),
            'error_total' => count($errorList),
            'error_count_by_code' => $countByCode,
            'warning_list' => array_slice(array_values($warnings), 0, self::MAX_REPORTED),
            'warning_total' => count($warnings),
            'future_row_count' => $futureRowCount,
        ];
    }

    /**
     * V04。違反なら [code, message]
     *
     * @return array{0: string, 1: string}|null
     */
    private function checkDeployKey(mixed $value): ?array
    {
        $text = is_string($value) ? $value : '';
        if ($text === '') {
            return ['deploy_key_empty', 'deploy_key が空です'];
        }
        if (preg_match('/^[0-9]+$/D', $text) !== 1) {
            return ['deploy_key_not_number', "deploy_key「{$text}」は数字だけではありません"];
        }
        if (in_array($text, self::LEGACY_RESERVED_KEYS, true)) {
            return ['deploy_key_legacy_reserved', "deploy_key {$text} は旧予約キー（9 桁）です。変換せず、予約キー（7777777777・8888888888・9999999999）に書き直してください"];
        }
        if (strlen($text) === 9) {
            return ['deploy_key_legacy_9digits', "deploy_key {$text} は 9 桁です。10 桁（YYYYMMDDNN）に変換してください（×10 + 1。例 202601010 → 2026010101）"];
        }
        if (strlen($text) !== 10) {
            return ['deploy_key_out_of_range', "deploy_key {$text} は 10 桁ではありません"];
        }
        $key = (int) $text;
        if ($this->policy->isReservedBand($key)) {
            return $this->policy->isRejectedReservedKey($key)
                ? ['deploy_key_out_of_range', "deploy_key {$text} は予約帯の値ですが、予約キー（7777777777・8888888888・9999999999）ではありません"]
                : null;
        }
        $year = (int) substr($text, 0, 4);
        $month = (int) substr($text, 4, 2);
        $day = (int) substr($text, 6, 2);
        $count = (int) substr($text, 8, 2);
        if ($year < 2026 || $year > 2999 || $month < 1 || $month > 12 || $day < 1 || $day > 31 || $count < 1) {
            return ['deploy_key_out_of_range', "deploy_key {$text} は YYYYMMDDNN（2026010101〜2999123199。NN は 01〜99）の形ではありません"];
        }

        return null;
    }

    /**
     * V10・V11。違反の [列, code, メッセージ]
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, array{kind: 'enum', values: array<int, string>}|array{kind: 'int'}>  $rarityColumns
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function contentViolations(string $tableName, array $entry, bool $isL10n, array $rarityColumns): array
    {
        $violations = [];
        $columns = (array) ($entry['columns'] ?? []);
        $rarityMap = $this->constArray('RARITY_MAP');

        // V10
        foreach ($rarityColumns as $column => $spec) {
            if (! array_key_exists($column, $columns)) {
                continue;
            }
            $text = is_scalar($columns[$column]) ? (string) $columns[$column] : '';
            if ($spec['kind'] === 'enum') {
                $ok = in_array($text, $spec['values'], true);
                $hint = implode('・', $spec['values']).' のいずれか';
            } else {
                $ok = isset($rarityMap[$text]) || preg_match('/^[1-6]$/D', $text) === 1;
                $hint = implode('・', array_keys($rarityMap)).' または 1〜6 の数値';
            }
            if (! $ok) {
                $violations[] = [$column, 'rarity_invalid', "{$column} の値「{$text}」は使えません（{$hint}）"];
            }
        }

        // V11（親テーブルの id だけ。l10n は親の行で検査する）
        if ($isL10n) {
            return $violations;
        }
        $id = (string) ($entry['id'] ?? '');
        if (mb_strlen($id) > $this->constInt('MAX_ID_LENGTH')) {
            $violations[] = ['id', 'id_too_long', 'id が '.$this->constInt('MAX_ID_LENGTH').' 文字を超えています'];

            return $violations;
        }
        $ruleColumns = $this->constArray('ID_RULES')[$tableName] ?? null;
        if ($ruleColumns !== null) {
            $parts = [];
            foreach ($ruleColumns as $column) {
                $value = $columns[$column] ?? '';
                $parts[] = is_scalar($value) ? (string) $value : '';
            }
            // 最後の列以外（親 ID・content_type）に ':' があると、別の組が同じ id になりうる
            if (count($ruleColumns) >= 3) {
                $colon = [];
                foreach (array_slice($ruleColumns, 0, -1) as $position => $column) {
                    if (str_contains($parts[$position], ':')) {
                        $colon[] = $column;
                    }
                }
                if ($colon !== []) {
                    $violations[] = [$colon[0], 'id_colon', implode('・', $colon).' に \':\' を含められません（id の区切りと衝突します）'];

                    return $violations;
                }
            }
            $expected = implode(':', $parts);
            if ($id !== $expected) {
                $violations[] = ['id', 'id_rule_mismatch', "id「{$id}」が規則（".implode(':', array_map(fn (string $c) => '{'.$c.'}', $ruleColumns))."）の「{$expected}」と一致しません"];
            }
        }

        return $violations;
    }

    /**
     * V12。採った行の中で UNIQUE_COLUMN_GROUPS の組が重なる行（2 つ目以降）と、メッセージ
     *
     * @param  array<int, array<string, mixed>>  $adopted
     * @return list<array{0: array<string, mixed>, 1: string}>
     */
    private function uniqueGroupViolations(string $tableName, array $adopted): array
    {
        $groupColumns = $this->constArray('UNIQUE_COLUMN_GROUPS')[$tableName] ?? null;
        if ($groupColumns === null) {
            return [];
        }
        $numeric = $this->constArray('UNIQUE_NUMERIC_COLUMNS');
        $violations = [];
        $seen = [];
        foreach ($adopted as $entry) {
            $columns = (array) ($entry['columns'] ?? []);
            $parts = [];
            foreach ($groupColumns as $column) {
                $value = $columns[$column] ?? '';
                $text = is_scalar($value) ? trim((string) $value) : '';
                // 数値は '1' と '01' を同じ値とみなす。空は既定値 0（MasterImportService::validateUniqueColumnGroups と同じ）
                if (in_array($column, $numeric, true)) {
                    $parts[] = $text === '' ? '0' : (is_numeric($text) ? (string) (int) $text : $text);
                } else {
                    $parts[] = mb_strtolower($text);
                }
            }
            $key = implode("\0", $parts);
            if (isset($seen[$key])) {
                $violations[] = [$entry, '('.implode(', ', $groupColumns).") が行 {$seen[$key]} と同じ値です（並びが決まらなくなります）"];

                continue;
            }
            $seen[$key] = (int) $entry['row_no'];
        }

        return $violations;
    }

    /**
     * MasterImportService の private 定数を読む（定義の複製を避ける）
     *
     * @return array<int|string, mixed>
     */
    private function constArray(string $name): array
    {
        $value = $this->constant($name);

        return is_array($value) ? $value : [];
    }

    private function constInt(string $name): int
    {
        $value = $this->constant($name);

        return is_int($value) ? $value : 0;
    }

    /**
     * @throws LogicException 定数が無いとき（名前の変更で黙って空にならないようにする）
     */
    private function constant(string $name): mixed
    {
        $this->importClass ??= new ReflectionClass(MasterImportService::class);
        if (! $this->importClass->hasConstant($name)) {
            throw new LogicException('MasterImportService::'.$name.' が見つかりません（定数名が変わった場合は MasterSheetValidator も直す）');
        }

        return $this->importClass->getConstant($name);
    }
}

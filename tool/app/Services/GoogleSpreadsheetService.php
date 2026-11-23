<?php

namespace App\Services;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Exception;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use RuntimeException;

/**
 * GoogleSpreadsheetService
 *
 * Google Drive フォルダ内のスプレッドシートを操作するサービス。
 *
 * 設定:
 * - TOL_GOOGLE_SPREADSHEET_DIR: Google Drive のフォルダID
 * - TOL_GOOGLE_SERVICE_ACCOUNT_JSON: サービスアカウント認証JSONファイルのパス
 *
 * 認証方法:
 * Google Cloud Console でサービスアカウントを作成し、
 * Drive API / Sheets API を有効化した上で認証JSONを取得してください。
 * 対象フォルダをサービスアカウントのメールアドレスに共有する必要があります。
 */
class GoogleSpreadsheetService
{
    private Client $client;

    private Drive $driveService;

    private Sheets $sheetsService;

    public function __construct()
    {
        $this->client = $this->buildClient();
        $this->driveService = new Drive($this->client);
        $this->sheetsService = new Sheets($this->client);
    }

    /**
     * 設定が有効か確認する
     */
    public function isConfigured(): bool
    {
        $folderId = config('services.google_spreadsheet.folder_id');
        if (empty($folderId)) {
            return false;
        }

        // JSON直書き または ファイルパスのどちらかが設定されていればOK
        $jsonContent = config('services.google_spreadsheet.credentials_json');
        if (! empty($jsonContent)) {
            return true;
        }

        $credPath = config('services.google_spreadsheet.credentials_path');

        return ! empty($credPath) && file_exists($credPath);
    }

    /**
     * フォルダ内のスプレッドシート一覧を取得する
     *
     * @return array<int, array{id: string, name: string, modified_at: string}>
     */
    public function listSpreadsheets(): array
    {
        $folderId = $this->getFolderId();

        $response = $this->withRetry(fn () => $this->driveService->files->listFiles([
            'q' => "'{$folderId}' in parents and mimeType='application/vnd.google-apps.spreadsheet' and trashed=false",
            'fields' => 'files(id, name, modifiedTime)',
            'orderBy' => 'name',
        ]));

        return array_map(fn (DriveFile $file) => [
            'id' => $file->getId(),
            'name' => $file->getName(),
            'modified_at' => $file->getModifiedTime(),
        ], $response->getFiles());
    }

    /**
     * スプレッドシートのシート一覧を取得する
     *
     * @return array<int, array{sheet_id: int, title: string}>
     */
    public function listSheets(string $spreadsheetId): array
    {
        $spreadsheet = $this->withRetry(fn () => $this->sheetsService->spreadsheets->get($spreadsheetId));

        return array_map(fn ($sheet) => [
            'sheet_id' => $sheet->getProperties()->getSheetId(),
            'title' => $sheet->getProperties()->getTitle(),
        ], $spreadsheet->getSheets());
    }

    /**
     * is_active の列インデックス（固定: 常に1列目 = インデックス0）
     *
     * スプレッドシートの1列目は is_active 列として予約する。
     * 1行目（テーブル名行）は空にし、2行目（カラム名行）に "is_active" と記載する。
     * 空・"0"・"false" の場合はその行を取り込み対象外とする。
     */
    private const IS_ACTIVE_COLUMN_INDEX = 0;

    /**
     * is_active が有効とみなす値
     */
    private const ACTIVE_VALUES = ['1', 'true', 'TRUE', 'True'];

    /**
     * 読み取りの途中だけで使う内部キー（出力の rows には残さない）。`__deleted` はシートの列なので含めない
     */
    private const INTERNAL_KEYS = ['_parent_id', '_row_no', '_parent_deploy_key', '_parent_deleted'];

    /**
     * シートの全データを取得する
     *
     * スプレッドシートのヘッダ形式（2行ヘッダ）:
     *   1列目: is_active（1行目は空、2行目は "is_active"）← 固定
     *   1行目: テーブル名（空セルは直前のテーブル名を引き継ぐ）
     *   2行目: カラム名
     *   3行目以降: データ
     *
     * is_active が空・0・false の行は全テーブルで取り込み対象外。
     *
     * l10nテーブルの列は「カラム名.言語コード」形式（例: name.ja, name.en）で表現する。
     * これをサービス側で言語ごとの行に展開する。
     * 例: { mst_unit_id: "unit_001", "name.ja": "炎の剣士", "name.en": "Flame Swordsman" }
     *   → mst_unit__l10n の ja 行: { mst_unit_id: "unit_001", language: "ja", name: "炎の剣士" }
     *   → mst_unit__l10n の en 行: { mst_unit_id: "unit_001", language: "en", name: "Flame Swordsman" }
     *
     * @return array<string, array{
     *   table: string,
     *   headers: array<int, string>,
     *   rows: array<int, array<string, string>>,
     *   raw_count: int,
     *   skipped_count: int
     * }>
     */
    public function getSheetData(string $spreadsheetId, string $sheetTitle): array
    {
        /** @var ValueRange $response */
        $response = $this->withRetry(fn () => $this->sheetsService->spreadsheets_values->get(
            $spreadsheetId,
            $sheetTitle,
        ));

        // ライブラリの宣言は array だが、空シートでは null が返る
        /** @var array<int, array<int, string>>|null $rawValues */
        $rawValues = $response->getValues();

        return self::parseSheetValues($rawValues ?? [], $sheetTitle);
    }

    /**
     * シートの値（行の配列）をテーブルごとのデータに変換する（Google API を呼ばない純粋関数）
     *
     * 戻り値の形は getSheetData と同じ。加えて各テーブルに次を持つ（既存の呼び出し元は読まない）:
     * - row_nos: rows と同じ順の、シート上の行番号（1 始まり。ヘッダ 2 行を含む。l10n の展開後も元の行番号）
     * - entries: 行の正規化（db.md 6.2）。{@see self::normalizeEntries()}
     * - has_deploy_key_column: テーブルの列グループ（l10n は親の列も可）に deploy_key 列があるか
     *
     * @param  array<int, array<int, mixed>>  $values
     * @return array<string, array{
     *   table: string,
     *   headers: array<int, string>,
     *   rows: array<int, array<string, string>>,
     *   raw_count: int,
     *   skipped_count: int,
     *   row_nos: array<int, int>,
     *   entries: array<int, array<string, mixed>>,
     *   has_deploy_key_column: bool
     * }>
     */
    public static function parseSheetValues(array $values, string $sheetTitle): array
    {
        if (count($values) < 2) {
            return [];
        }

        $tableRow = $values[0]; // 1行目: テーブル名
        $headerRow = $values[1]; // 2行目: カラム名

        // is_active 列の存在チェック（必須）
        $isActiveHeader = strval($headerRow[self::IS_ACTIVE_COLUMN_INDEX] ?? '');
        if ($isActiveHeader !== 'is_active') {
            throw new RuntimeException(
                "シート「{$sheetTitle}」の1列目は \"is_active\" である必要があります。"
                ."現在の値: \"{$isActiveHeader}\"\n"
                .'スプレッドシートの1列目（A列）に is_active 列を追加してください。'
            );
        }
        $dataRows = array_slice($values, 2);

        // 列ごとに「テーブル名」と「カラム名」を対応付ける
        // 1列目は is_active 予約列なので除外し、インデックス1以降を処理する
        // 1行目が空の場合は直前のテーブル名を引き継ぐ
        $columnMeta = [];
        $lastTable = '';
        for ($i = 0; $i < count($headerRow); $i++) {
            $tableName = strval($tableRow[$i] ?? '');
            $columnName = strval($headerRow[$i] ?? '');

            // 1列目（is_active列）はテーブルデータとして扱わない
            if ($i === self::IS_ACTIVE_COLUMN_INDEX) {
                continue;
            }

            if ($tableName !== '') {
                $lastTable = $tableName;
            }
            if ($columnName === '' || $lastTable === '') {
                continue;
            }
            $columnMeta[] = ['table' => $lastTable, 'column' => $columnName, 'index' => $i];
        }

        // テーブルごとにグループ化
        $tables = [];
        foreach ($columnMeta as $meta) {
            $tables[$meta['table']][] = $meta;
        }

        // 親テーブルの id 列インデックスを事前に取得しておく
        // l10nテーブル行に親IDを自動注入するために使用
        // 例: mst_unit__l10n → mst_unit の id 列インデックス
        $parentIdIndexMap = []; // ['mst_unit__l10n' => 3] のような形
        // 親テーブルの deploy_key・__deleted の列インデックス（l10n の行へ注入する。db.md 6.2）
        $parentMetaIndexMap = []; // ['mst_unit__l10n' => ['deploy_key' => 4, '__deleted' => 5]]
        foreach ($tables as $tableName => $columns) {
            if (! str_ends_with($tableName, '__l10n')) {
                continue;
            }
            $parentTableName = substr($tableName, 0, -strlen('__l10n'));
            if (! isset($tables[$parentTableName])) {
                continue;
            }
            foreach ($tables[$parentTableName] as $col) {
                // 同名の列が複数あるときは、id・deploy_key・__deleted のどれも最初の列を採る
                if ($col['column'] === 'id' && ! isset($parentIdIndexMap[$tableName])) {
                    $parentIdIndexMap[$tableName] = $col['index'];
                }
                if (in_array($col['column'], ['deploy_key', '__deleted'], true)
                    && ! isset($parentMetaIndexMap[$tableName][$col['column']])) {
                    $parentMetaIndexMap[$tableName][$col['column']] = $col['index'];
                }
            }
        }

        // テーブルごとにデータ行を連想配列化
        $result = [];
        foreach ($tables as $tableName => $columns) {
            $rows = [];
            $rowNos = [];
            $skippedCount = 0;

            foreach ($dataRows as $dataIndex => $row) {
                // is_active チェック（1列目）: 空・0・false は取り込み対象外
                $isActive = strval($row[self::IS_ACTIVE_COLUMN_INDEX] ?? '');
                if (! in_array($isActive, self::ACTIVE_VALUES, true)) {
                    $skippedCount++;

                    continue;
                }

                $assoc = [];
                foreach ($columns as $col) {
                    $assoc[$col['column']] = isset($row[$col['index']]) ? strval($row[$col['index']]) : '';
                }

                // l10nテーブルの場合、同行の親テーブルの id を _parent_id として注入
                if (str_ends_with($tableName, '__l10n') && isset($parentIdIndexMap[$tableName])) {
                    $assoc['_parent_id'] = strval($row[$parentIdIndexMap[$tableName]] ?? '');
                }

                // 全列が空の行はスキップ（is_active以外）
                if (empty(array_filter($assoc, fn ($v) => $v !== ''))) {
                    continue;
                }

                // 内部キー: シート上の行番号（ヘッダ 2 行の次が 3 行目）と、l10n 行へ渡す親の値
                $assoc['_row_no'] = (string) ($dataIndex + 3);
                if (str_ends_with($tableName, '__l10n')) {
                    foreach (['deploy_key' => '_parent_deploy_key', '__deleted' => '_parent_deleted'] as $metaColumn => $internalKey) {
                        if (isset($parentMetaIndexMap[$tableName][$metaColumn])) {
                            $assoc[$internalKey] = strval($row[$parentMetaIndexMap[$tableName][$metaColumn]] ?? '');
                        }
                    }
                }
                $rows[] = $assoc;
            }

            // l10nテーブル: 「カラム名.言語コード」列を言語ごとの行に展開する
            // 親テーブル名を導出（例: mst_unit__l10n → mst_unit）
            if (str_ends_with($tableName, '__l10n')) {
                $parentTableName = substr($tableName, 0, -strlen('__l10n'));
                $rows = self::expandL10nRows($rows, $parentTableName);
                $headers = self::buildL10nHeaders($columns, $parentTableName);
            } else {
                $headers = array_column($columns, 'column');
            }

            // 内部キー（_row_no など）を正規化の entries と行番号へ移し、rows からは外す（既存の形を保つ）
            $isL10n = str_ends_with($tableName, '__l10n');
            $entries = self::normalizeEntries($rows, $tableName, $isL10n ? substr($tableName, 0, -strlen('__l10n')) : null);
            $rowNos = array_column($entries, 'row_no');
            $rows = array_map(
                fn (array $r) => array_filter($r, fn ($k) => ! in_array($k, self::INTERNAL_KEYS, true), ARRAY_FILTER_USE_KEY),
                $rows,
            );

            $hasDeployKey = in_array('deploy_key', array_column($columns, 'column'), true)
                || isset($parentMetaIndexMap[$tableName]['deploy_key']);

            $result[$tableName] = [
                'table' => $tableName,
                'headers' => $headers,
                'rows' => $rows,
                'raw_count' => count($dataRows),
                'skipped_count' => $skippedCount,
                'row_nos' => $rowNos,
                'entries' => $entries,
                'has_deploy_key_column' => $hasDeployKey,
            ];
        }

        return $result;
    }

    /**
     * 行の正規化（db.md 6.2）。値は検証せず、シートに書かれたままの文字列で持つ（検証は MasterSheetValidator）
     *
     * - 親テーブル: id は `id` 列。deploy_key・deleted は自分の列（無ければ null）
     * - __l10n: id は注入済みの親 id、language は `language`。deploy_key は l10n 自身の列、
     *   無ければ同じシート行の親テーブルの列（仮置き）。deleted は常に親の `__deleted`
     * - columns: 上記以外の列（deploy_key・__deleted・id・language・内部キーを除く）
     *
     * @param  array<int, array<string, string>>  $rows  内部キー（_row_no 等）付きの行
     * @return array<int, array{row_no: int, id: string, language: string|null, deploy_key: string|null, deleted: string|null, columns: array<string, string>}>
     */
    private static function normalizeEntries(array $rows, string $tableName, ?string $parentTableName): array
    {
        $entries = [];
        foreach ($rows as $row) {
            if ($parentTableName !== null) {
                $id = $row[$parentTableName.'_id'] ?? '';
                $language = $row['language'] ?? '';
                $deployKey = array_key_exists('deploy_key', $row) ? $row['deploy_key'] : ($row['_parent_deploy_key'] ?? null);
                $deleted = $row['_parent_deleted'] ?? null;
                $exclude = [$parentTableName.'_id', 'language'];
            } else {
                $id = $row['id'] ?? '';
                $language = null;
                $deployKey = $row['deploy_key'] ?? null;
                $deleted = $row['__deleted'] ?? null;
                $exclude = ['id'];
            }
            $exclude = [...$exclude, 'deploy_key', '__deleted'];

            $columns = [];
            foreach ($row as $key => $value) {
                if (in_array($key, self::INTERNAL_KEYS, true) || in_array($key, $exclude, true)) {
                    continue;
                }
                $columns[$key] = $value;
            }

            $entries[] = [
                'row_no' => (int) ($row['_row_no'] ?? 0),
                'id' => $id,
                'language' => $language,
                'deploy_key' => $deployKey,
                'deleted' => $deleted,
                'columns' => $columns,
            ];
        }

        return $entries;
    }

    /**
     * ディレクトリ内の全スプレッドシートの、タイトルが `mst_` で始まる全タブを読む（db.md 6.1）
     *
     * Google API の部分（一覧・値の取得）だけをここに置き、整形は {@see self::assembleMasterTabs()} に任せる。
     *
     * @param  array<string, array<int, string>>|null  $knownColumns  mst DB の表ごとの列名（無ければ列の絞り込みをしない）
     * @return array<string, mixed> assembleMasterTabs の戻り値
     */
    public function readAllMasterTabs(?array $knownColumns = null): array
    {
        $tabs = [];
        $ignored = [];
        foreach ($this->listSpreadsheets() as $spreadsheet) {
            foreach ($this->listSheets($spreadsheet['id']) as $sheet) {
                $label = $spreadsheet['name'].'/'.$sheet['title'];
                if (! self::isMasterTabTitle($sheet['title'])) {
                    // 値を取る前に外す（API 呼び出しを省く）。assembleMasterTabs も同じ判定を持つ
                    $ignored[] = ['sheet' => $label, 'reason' => 'not_mst_tab'];

                    continue;
                }

                /** @var ValueRange $response */
                $response = $this->withRetry(fn () => $this->sheetsService->spreadsheets_values->get(
                    $spreadsheet['id'],
                    $sheet['title'],
                ));
                /** @var array<int, array<int, string>>|null $rawValues */
                $rawValues = $response->getValues();
                $tabs[] = ['sheet' => $label, 'title' => $sheet['title'], 'values' => $rawValues ?? []];
            }
        }

        $result = self::assembleMasterTabs($tabs, $knownColumns);
        $result['ignored_tab_list'] = [...$ignored, ...$result['ignored_tab_list']];

        return $result;
    }

    /**
     * 読む対象のタブか（タイトルが `mst_` で始まる。db.md 6.1）
     */
    public static function isMasterTabTitle(string $title): bool
    {
        return str_starts_with($title, 'mst_');
    }

    /**
     * 読んだタブの値を、テーブルごとの正規化済みの行にまとめる（Google API を呼ばない純粋関数）
     *
     * 同じテーブルの行は全タブを合わせる（順序に意味は無い）。タブの不備は例外にせず error_list に集める
     * （全体拒否の判断は呼び出し側。db.md 6.3）。
     *
     * @param  array<int, array{sheet: string, title?: string, values: array<int, array<int, mixed>>}>  $tabs  sheet は「{スプレッドシート名}/{タブ名}」。title（タブ名）があり `mst_` で始まらなければ not_mst_tab として読み飛ばす
     * @param  array<string, array<int, string>>|null  $knownColumns
     * @return array{
     *   tables: array<string, array{entries: array<int, array<string, mixed>>, has_deploy_key_column: bool, ignored_inactive_rows: int}>,
     *   ignored_tab_list: array<int, array{sheet: string, reason: string}>,
     *   ignored_column_list: array<int, array{table: string, column: string}>,
     *   error_list: array<int, array{code: string, sheet: string, message: string}>
     * }
     */
    public static function assembleMasterTabs(array $tabs, ?array $knownColumns = null): array
    {
        $tables = [];
        $ignoredTabs = [];
        $ignoredColumns = [];
        $errors = [];

        foreach ($tabs as $tab) {
            if (isset($tab['title']) && ! self::isMasterTabTitle($tab['title'])) {
                $ignoredTabs[] = ['sheet' => $tab['sheet'], 'reason' => 'not_mst_tab'];

                continue;
            }
            $values = $tab['values'];
            if (count($values) < 2) {
                $ignoredTabs[] = ['sheet' => $tab['sheet'], 'reason' => 'empty_tab'];

                continue;
            }
            $isActiveHeader = strval($values[1][self::IS_ACTIVE_COLUMN_INDEX] ?? '');
            if ($isActiveHeader !== 'is_active') {
                $errors[] = [
                    'code' => 'is_active_column_missing',
                    'sheet' => $tab['sheet'],
                    'message' => "2 行目の A 列が is_active ではありません（現在の値: \"{$isActiveHeader}\"）",
                ];

                continue;
            }

            foreach (self::parseSheetValues($values, $tab['sheet']) as $tableName => $data) {
                $tables[$tableName] ??= ['entries' => [], 'has_deploy_key_column' => false, 'ignored_inactive_rows' => 0];
                $tables[$tableName]['has_deploy_key_column'] = $tables[$tableName]['has_deploy_key_column'] || $data['has_deploy_key_column'];
                $tables[$tableName]['ignored_inactive_rows'] += $data['skipped_count'];

                $known = $knownColumns[$tableName] ?? null;
                foreach ($data['entries'] as $entry) {
                    $entry['sheet'] = $tab['sheet'];
                    if ($known !== null) {
                        foreach (array_keys($entry['columns']) as $column) {
                            if (! in_array($column, $known, true)) {
                                $ignoredColumns[$tableName.'.'.$column] = ['table' => $tableName, 'column' => $column];
                                unset($entry['columns'][$column]);
                            }
                        }
                    }
                    $tables[$tableName]['entries'][] = $entry;
                }
            }
        }

        return [
            'tables' => $tables,
            'ignored_tab_list' => $ignoredTabs,
            'ignored_column_list' => array_values($ignoredColumns),
            'error_list' => $errors,
        ];
    }

    /**
     * l10n行を言語ごとの行に展開する
     *
     * スプレッドシートでは親IDを持たず、事前に注入された `_parent_id` の値を
     * `{parentTable}_id` として自動セットする。
     *
     * 入力 (mst_unit__l10n): [{ "_parent_id": "unit_001", "name.ja": "炎の剣士", "name.en": "Flame Swordsman" }]
     *
     * 出力:
     *   { mst_unit_id: "unit_001", language: "ja", name: "炎の剣士" }
     *   { mst_unit_id: "unit_001", language: "en", name: "Flame Swordsman" }
     *
     * @param  array<int, array<string, string>>  $rows  l10n列のデータ（_parent_id付き）
     * @param  string  $parentTableName  親テーブル名（例: mst_unit）
     * @return array<int, array<string, string>>
     */
    private static function expandL10nRows(array $rows, string $parentTableName): array
    {
        $expanded = [];
        $parentIdKey = $parentTableName.'_id'; // 例: mst_unit_id

        foreach ($rows as $row) {
            // _parent_id を取り出して親ID列として使用
            $parentId = $row['_parent_id'] ?? '';

            // カラム名に「.言語コード」を含むものを抽出して言語コードを収集
            $languages = [];
            $langColumns = []; // ['ja' => ['name' => '炎の剣士', ...], ...]
            $commonFields = []; // 言語に依存しないフィールド（deploy_key 等）
            $internal = []; // 内部キー（_row_no 等）。共通列には入れず、各言語の行へそのまま渡す

            foreach ($row as $key => $value) {
                if ($key === '_parent_id') {
                    continue; // 内部キーはスキップ
                }
                if (in_array($key, self::INTERNAL_KEYS, true)) {
                    $internal[$key] = $value;

                    continue;
                }
                if (str_contains($key, '.')) {
                    [$colName, $lang] = explode('.', $key, 2);
                    $languages[$lang] = true;
                    $langColumns[$lang][$colName] = $value;
                } else {
                    $commonFields[$key] = $value;
                }
            }

            // 言語ごとに1行生成
            foreach ($languages as $lang => $_) {
                $langRow = [$parentIdKey => $parentId] + $commonFields;
                $langRow['language'] = $lang;
                foreach ($langColumns[$lang] as $col => $val) {
                    $langRow[$col] = $val;
                }
                // 言語列の値が全て空なら行をスキップ
                $langValues = array_diff_key($langRow, [$parentIdKey => ''], $commonFields, ['language' => '']);
                if (empty(array_filter($langValues, fn ($v) => $v !== ''))) {
                    continue;
                }
                $expanded[] = $langRow + $internal;
            }
        }

        return $expanded;
    }

    /**
     * l10nテーブル用のヘッダ列を構築する
     *
     * スプレッドシートの l10n 列には親IDは含まれず、
     * `{parentTable}_id`、`language`、言語依存カラムの順でヘッダを返す。
     *
     * @param  array<int, array{table: string, column: string, index: int}>  $columns
     * @param  string  $parentTableName
     * @return array<int, string>
     */
    private static function buildL10nHeaders(array $columns, string $parentTableName): array
    {
        $parentIdKey = $parentTableName.'_id'; // 例: mst_unit_id
        $headers = [];
        $langCols = [];

        foreach ($columns as $col) {
            if (str_contains($col['column'], '.')) {
                [$colName] = explode('.', $col['column'], 2);
                $langCols[$colName] = true;
            } elseif ($col['column'] !== 'id') {
                // id 列は親ID列として変換するため除外
                $headers[] = $col['column'];
            }
        }

        // 先頭に {parentTable}_id を追加
        array_unshift($headers, $parentIdKey);

        // language 列を追加
        $headers[] = 'language';

        // 言語依存列を追加
        foreach (array_keys($langCols) as $colName) {
            $headers[] = $colName;
        }

        return $headers;
    }

    /**
     * プレビュー用データを取得する（テーブルごとに先頭N行）
     *
     * @return array<string, array{
     *   table: string,
     *   headers: array<int, string>,
     *   rows: array<int, array<string, string>>,
     *   total_rows: int,
     *   preview_rows: int
     * }>
     */
    public function getPreviewData(string $spreadsheetId, string $sheetTitle, int $limit = 5): array
    {
        $tableData = $this->getSheetData($spreadsheetId, $sheetTitle);

        $result = [];
        foreach ($tableData as $tableName => $data) {
            $result[$tableName] = [
                'table' => $tableName,
                'headers' => $data['headers'],
                'rows' => array_slice($data['rows'], 0, $limit),
                'total_rows' => count($data['rows']),
                'preview_rows' => min($limit, count($data['rows'])),
            ];
        }

        return $result;
    }

    /**
     * Google API クライアントを構築する
     *
     * 認証方法の優先順位:
     * 1. TOL_GOOGLE_SERVICE_ACCOUNT_JSON_CONTENT（JSON直書き）
     * 2. TOL_GOOGLE_SERVICE_ACCOUNT_JSON（ファイルパス）
     */
    private function buildClient(): Client
    {
        $client = new Client;
        $client->setApplicationName('Nexus Master Importer');
        $client->setScopes([
            Drive::DRIVE_READONLY,
            Sheets::SPREADSHEETS,          // 読み書き両方（サンプルデータ書き込み用）
        ]);

        // JSON直書きを優先
        $jsonContent = config('services.google_spreadsheet.credentials_json');
        if (! empty($jsonContent)) {
            // private_key の \n をPHPの改行コードに変換
            $decoded = json_decode($jsonContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException(
                    'TOL_GOOGLE_SERVICE_ACCOUNT_JSON_CONTENT のJSONが不正です: '.json_last_error_msg()
                );
            }
            // private_key 内の \\n を実際の改行に変換（.envでエスケープされる場合）
            if (isset($decoded['private_key'])) {
                $decoded['private_key'] = str_replace('\\n', "\n", $decoded['private_key']);
            }
            $client->setAuthConfig($decoded);

            return $client;
        }

        // ファイルパスにフォールバック
        $credentialsPath = config('services.google_spreadsheet.credentials_path');
        if (empty($credentialsPath)) {
            throw new RuntimeException(
                'TOL_GOOGLE_SERVICE_ACCOUNT_JSON_CONTENT または TOL_GOOGLE_SERVICE_ACCOUNT_JSON を設定してください。'
            );
        }
        if (! file_exists($credentialsPath)) {
            throw new RuntimeException(
                "サービスアカウント認証ファイルが見つかりません: {$credentialsPath}"
            );
        }
        $client->setAuthConfig($credentialsPath);

        return $client;
    }

    /**
     * 設定からフォルダIDを取得する
     */
    private function getFolderId(): string
    {
        $folderId = config('services.google_spreadsheet.folder_id');

        if (empty($folderId)) {
            throw new RuntimeException(
                'TOL_GOOGLE_SPREADSHEET_DIR が設定されていません。'
            );
        }

        return $folderId;
    }

    /**
     * Google API コールをリトライ付きで実行する
     *
     * 503 (Service Unavailable) や 429 (Rate Limit) の場合に
     * 指数バックオフでリトライする。
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @param  int  $maxRetries
     * @return T
     */
    private function withRetry(callable $call, int $maxRetries = 3): mixed
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt <= $maxRetries) {
            try {
                return $call();
            } catch (Exception $e) {
                $code = $e->getCode();
                $isRetryable = in_array($code, [429, 500, 503], true);

                if (! $isRetryable || $attempt >= $maxRetries) {
                    throw $e;
                }

                // 指数バックオフ: 1秒, 2秒, 4秒
                $waitSeconds = pow(2, $attempt);
                sleep((int) $waitSeconds);

                $attempt++;
                $lastException = $e;
            }
        }

        throw $lastException;
    }
}

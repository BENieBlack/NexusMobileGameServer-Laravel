<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * MasterImportService
 *
 * スプレッドシートから取得したデータをmstデータベースに
 * インポートするサービス。
 *
 * インポート仕様:
 * - シート名 = mstテーブル名 として対応付ける
 *   例: シート名「mst_item」→ テーブル「mst_item」
 * - 1行目はヘッダ（カラム名）として使用
 * - 既存レコードは全て削除してから再INSERT（truncate & insert）
 * - deploy_key は現在のUnixタイムスタンプを使用
 * - rarity / bonus_rarity は列の実際の型で扱いを変える（ENUM は文字列のまま、整数列は数値へ変換）。
 *   不正な値は TRUNCATE の前に検出してエラーにする
 * - id 列（string）を持つテーブルは、TRUNCATE の前に id 列を検証する。
 *   シートに id 列が無い・空・重複（大文字小文字を区別しない照合順序に合わせる）・191 文字超は取り込みを中止する。
 *   level 系の3表と *_content 系の9表（login・vip 含む）は、id が自然キーの規則どおりかも検証する（ID_RULES。api の Model の makeId と同じ規則）。
 *   *_content 系は、親 ID と content_type に ':' を含む行も拒否する（別の組が同じ id になりうるため。設計 3.1）
 * - mst_vip_login_bonus_content は (mst_vip_login_bonus_id, day, sort_desc) の重複も拒否する（UNIQUE_COLUMN_GROUPS）
 * - トランザクション内で実行（失敗時はロールバック）
 */
class MasterImportService
{
    /**
     * インポート対象として許可するテーブル名のプレフィックス
     */
    private const ALLOWED_TABLE_PREFIX = 'mst_';

    /**
     * インポート時に自動設定するカラム
     * スプレッドシートに含まれていても無視し、サーバー側で上書きする
     */
    private const AUTO_SET_COLUMNS = ['created_at', 'updated_at'];

    /**
     * id 列の最大長（実 DB の string は Schema::defaultStringLength(191) で varchar(191)）
     */
    private const MAX_ID_LENGTH = 191;

    /**
     * id が自然キーの規則どおりかを検証するテーブルと、id を作る列（':' でつなぐ）
     *
     * api の Model の makeId（MstUnitLevel・MstEquipmentLevel・MstPlayerLevel と、
     * MstEventContent・MstMailboxContent・MstMissionContent・MstTagContent・MstTradeProductContent・
     * MstLoginBonusContent・MstVipLoginBonusContent・NexusVip の MstVipLevelReward）と同じ規則を持つ。
     * mst_reward_track_content は api に Model が無く（アダプターが DB::table で読む）、この規則が唯一の定義になる。
     * tool は api の Model を読み込めず、api の docker コンテナも tool のコードを読めないため、
     * 突き合わせるテストが書けない。規則を変えるときは必ず両方を同時に直す。
     * 残りの表（billing 系）は、対応する PR で足す（設計: mst-id-introduction/db.md 8.2）
     *
     * 列が 3 つ以上の規則は、最後の列（content_mst_id）以外が ':' を含んではならない（validateIds）
     */
    private const ID_RULES = [
        'mst_unit_level' => ['rarity', 'level'],
        'mst_equipment_level' => ['rarity', 'level'],
        'mst_player_level' => ['level'],
        'mst_event_content' => ['mst_event_id', 'content_type', 'content_mst_id'],
        'mst_mailbox_content' => ['mst_mailbox_id', 'content_type', 'content_mst_id'],
        'mst_mission_content' => ['mst_mission_id', 'content_type', 'content_mst_id'],
        'mst_reward_track_content' => ['mst_reward_track_milestone_id', 'mst_reward_track_line_id', 'content_type', 'content_mst_id'],
        'mst_tag_content' => ['mst_tag_id', 'content_type', 'content_mst_id'],
        'mst_trade_product_content' => ['mst_trade_product_id', 'content_type', 'content_mst_id'],
        'mst_login_bonus_content' => ['mst_login_bonus_id', 'day', 'content_type', 'content_mst_id'],
        'mst_vip_login_bonus_content' => ['mst_vip_login_bonus_id', 'day', 'content_type', 'content_mst_id'],
        'mst_vip_level_reward' => ['vip_level', 'content_type', 'content_mst_id'],
    ];

    /**
     * 同じ組み合わせで値が重なってはならない列（取り込みで拒否する）
     *
     * mst_vip_login_bonus_content は、同じ日の中の並びを sort_desc 降順で決める。同値の並びは
     * サーバー（MySQL の文字列順の id）とクライアント（SQLite の id ASC）で食い違いうるので、
     * (mst_vip_login_bonus_id, day, sort_desc) の重複を作らない（設計: mst-id-introduction/db.md 12 節 6）
     */
    private const UNIQUE_COLUMN_GROUPS = [
        'mst_vip_login_bonus_content' => ['mst_vip_login_bonus_id', 'day', 'sort_desc'],
    ];

    /**
     * UNIQUE_COLUMN_GROUPS のうち、数値として比べる列（'1' と '01' を同じ値とみなし、空は 0）。
     * それ以外の列（親 ID など）は文字列のまま、大文字小文字を区別せずに比べる（DB の照合順序に合わせる）
     */
    private const UNIQUE_NUMERIC_COLUMNS = ['day', 'sort_desc'];

    /**
     * エラーとして表示する不正行の最大件数
     */
    private const MAX_REPORTED_ERRORS = 20;

    /**
     * シートデータをmstテーブルへインポートする
     *
     * @param  string  $tableName  インポート先テーブル名（例: mst_item）
     * @param  array<int, string>  $headers  ヘッダ（カラム名）配列
     * @param  array<int, array<string, mixed>>  $rows  データ行配列（連想配列）
     * @return array{
     *   table: string,
     *   inserted: int,
     *   skipped: int,
     *   errors: array<int, string>
     * }
     */
    public function import(string $tableName, array $headers, array $rows): array
    {
        $this->validateTableName($tableName);

        $existingColumns = $this->getTableColumns($tableName);
        $now = now()->format('Y-m-d H:i:s');

        // テーブルに存在するヘッダのみ絞り込む（自動設定カラムは除外）
        $validHeaders = array_filter(
            $headers,
            fn (string $h) => in_array($h, $existingColumns, true)
                && ! in_array($h, self::AUTO_SET_COLUMNS, true),
        );

        if (empty($validHeaders)) {
            throw new RuntimeException(
                "テーブル {$tableName} に対応するカラムがスプレッドシートに見つかりません。"
            );
        }

        // id 列の検証は TRUNCATE の前に済ませる（失敗しても既存の行を残す）
        $this->validateIds($tableName, $headers, $rows);
        $this->validateUniqueColumnGroups($tableName, $rows);

        $insertData = [];
        $skipped = 0;
        $errors = [];

        // NULLableなカラムを取得（空文字をNULLに変換する対象）
        $nullableColumns = $this->getNullableColumns($tableName);

        // rarity 系カラムの型（ENUM / 整数）を実際の定義から判定する
        $rarityColumns = $this->getRarityColumns($tableName, array_values($validHeaders));
        $rarityErrors = [];

        foreach ($rows as $index => $row) {
            try {
                $record = [];
                foreach ($validHeaders as $header) {
                    $value = $row[$header] ?? '';
                    // NULLableカラムのみ空文字をNULLに変換。NOT NULLカラムは空文字のまま
                    $converted = ($value === '' && in_array($header, $nullableColumns, true))
                        ? null
                        : $value;

                    // rarity 系カラムは型に合わせて検証・変換する（不正な値は InvalidArgumentException）
                    if (isset($rarityColumns[$header]) && $converted !== null) {
                        $converted = $this->normalizeRarity($header, $converted, $rarityColumns[$header]);
                    }

                    $record[$header] = $converted;
                }

                // created_at / updated_at を自動設定
                if (in_array('created_at', $existingColumns, true)) {
                    $record['created_at'] = $now;
                }
                if (in_array('updated_at', $existingColumns, true)) {
                    $record['updated_at'] = $now;
                }

                $insertData[] = $record;
            } catch (InvalidArgumentException $e) {
                $rarityErrors[] = '行 '.($index + 2).': '.$e->getMessage();
            } catch (\Throwable $e) {
                $errors[] = '行 '.($index + 2).': '.$e->getMessage();
                $skipped++;
            }
        }

        // レアリティが不正なまま進むと、TRUNCATE の後の INSERT で失敗して既存の行が全て消える。
        // TRUNCATE より前に、全行を検証した結果をまとめてエラーにする
        if ($rarityErrors !== []) {
            $shown = array_slice($rarityErrors, 0, self::MAX_REPORTED_ERRORS);
            $more = count($rarityErrors) - count($shown);

            throw new RuntimeException(
                "テーブル {$tableName} のレアリティが不正なため、取り込みを中止しました（既存のデータは変更していません）。\n"
                .implode("\n", $shown)
                .($more > 0 ? "\n…ほか {$more} 件" : '')
            );
        }

        if (empty($insertData)) {
            return [
                'table' => $tableName,
                'inserted' => 0,
                'skipped' => $skipped,
                'errors' => $errors,
            ];
        }

        // 外部キー制約を無効化したまま TRUNCATE → INSERT を実行する
        // MySQLでは FOREIGN_KEY_CHECKS はセッションスコープのため
        // 同一接続内で有効。TRUNCATE は DDL なのでトランザクション外で実行する。
        DB::connection('mst')->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            DB::connection('mst')->table($tableName)->truncate();

            // INSERT はトランザクション内でチャンク単位に実行（大量データ対応）
            DB::connection('mst')->transaction(function () use ($tableName, $insertData) {
                foreach (array_chunk($insertData, 500) as $chunk) {
                    DB::connection('mst')->table($tableName)->insert($chunk);
                }
            });
        } finally {
            // 例外が起きても必ず外部キーチェックを元に戻す
            DB::connection('mst')->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        Log::info("マスターインポート完了: {$tableName}", [
            'inserted' => count($insertData),
            'skipped' => $skipped,
        ]);

        return [
            'table' => $tableName,
            'inserted' => count($insertData),
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * id 列を検証する。問題があれば TRUNCATE の前に例外で中止する
     *
     * 対象は id 列が文字列型のテーブルだけ（整数の id のテーブルは何もしない）。
     * - シートに id 列が無い
     * - id が空・重複・191 文字超の行
     * - ID_RULES のテーブルは、id が自然キーの列を ':' でつないだ値と一致しない行
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<string, mixed>>  $rows
     *
     * @throws RuntimeException
     */
    private function validateIds(string $tableName, array $headers, array $rows): void
    {
        if (! $this->hasStringIdColumn($tableName)) {
            return;
        }

        if (! in_array('id', $headers, true)) {
            throw new RuntimeException(
                "テーブル {$tableName} は id 列が必要ですが、スプレッドシートに id 列がありません。"
                .'取り込みを中止しました（既存のデータは変更していません）。'
            );
        }

        $errors = [];
        $seen = [];
        $ruleColumns = self::ID_RULES[$tableName] ?? null;

        foreach ($rows as $index => $row) {
            $line = '行 '.($index + 2).': ';
            $raw = $row['id'] ?? '';
            $id = is_scalar($raw) ? (string) $raw : '';

            if ($id === '') {
                $errors[] = $line.'id が空です';

                continue;
            }
            if (mb_strlen($id) > self::MAX_ID_LENGTH) {
                $errors[] = $line.'id が '.self::MAX_ID_LENGTH.' 文字を超えています';

                continue;
            }

            $key = mb_strtolower($id);
            if (isset($seen[$key])) {
                $errors[] = $line."id「{$id}」が行 {$seen[$key]} と重複しています";

                continue;
            }
            $seen[$key] = $index + 2;

            if ($ruleColumns !== null) {
                $parts = [];
                foreach ($ruleColumns as $column) {
                    $value = $row[$column] ?? '';
                    $parts[] = is_scalar($value) ? (string) $value : '';
                }
                // 最後の列以外（親 ID・content_type）に ':' があると、別の組が同じ id になりうる（設計 3.1）
                if (count($ruleColumns) >= 3) {
                    $colonErrors = [];
                    foreach (array_slice($ruleColumns, 0, -1) as $position => $column) {
                        if (str_contains($parts[$position], ':')) {
                            $colonErrors[] = $column;
                        }
                    }
                    if ($colonErrors !== []) {
                        $errors[] = $line.implode('・', $colonErrors).' に \':\' を含められません（id の区切りと衝突します）';

                        continue;
                    }
                }
                $expected = implode(':', $parts);
                if ($id !== $expected) {
                    $errors[] = $line."id「{$id}」が規則（".implode(':', array_map(fn (string $c) => '{'.$c.'}', $ruleColumns))."）の「{$expected}」と一致しません";
                }
            }
        }

        if ($errors !== []) {
            $shown = array_slice($errors, 0, self::MAX_REPORTED_ERRORS);
            $more = count($errors) - count($shown);

            throw new RuntimeException(
                "テーブル {$tableName} の id が不正なため、取り込みを中止しました（既存のデータは変更していません）。\n"
                .implode("\n", $shown)
                .($more > 0 ? "\n…ほか {$more} 件" : '')
            );
        }
    }

    /**
     * UNIQUE_COLUMN_GROUPS のテーブルについて、列の組み合わせの重複を検証する。問題があれば TRUNCATE の前に例外で中止する
     *
     * 数値の列（UNIQUE_NUMERIC_COLUMNS）が空・無いときは、DB の既定値（0）と同じ扱いで比べる。親 ID は文字列で比べる。
     *
     * @param  array<int, array<string, mixed>>  $rows
     *
     * @throws RuntimeException
     */
    private function validateUniqueColumnGroups(string $tableName, array $rows): void
    {
        $columns = self::UNIQUE_COLUMN_GROUPS[$tableName] ?? null;
        if ($columns === null) {
            return;
        }

        $errors = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            $parts = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? '';
                $text = is_scalar($value) ? trim((string) $value) : '';
                // 数値は '1' と '01' を同じ値とみなす。空は既定値 0
                if (in_array($column, self::UNIQUE_NUMERIC_COLUMNS, true)) {
                    $parts[] = $text === '' ? '0' : (is_numeric($text) ? (string) (int) $text : $text);
                } else {
                    $parts[] = mb_strtolower($text);
                }
            }
            $key = implode("\0", $parts);
            if (isset($seen[$key])) {
                $errors[] = '行 '.($index + 2).': ('.implode(', ', $columns).") が行 {$seen[$key]} と同じ値です（並びが決まらなくなります）";

                continue;
            }
            $seen[$key] = $index + 2;
        }

        if ($errors !== []) {
            $shown = array_slice($errors, 0, self::MAX_REPORTED_ERRORS);
            $more = count($errors) - count($shown);

            throw new RuntimeException(
                "テーブル {$tableName} の ".implode('・', $columns)." が重複しているため、取り込みを中止しました（既存のデータは変更していません）。\n"
                .implode("\n", $shown)
                .($more > 0 ? "\n…ほか {$more} 件" : '')
            );
        }
    }

    /**
     * テーブルが文字列型の id 列を持つか
     */
    private function hasStringIdColumn(string $tableName): bool
    {
        foreach (DB::connection('mst')->select("SHOW COLUMNS FROM `{$tableName}`") as $row) {
            if ($row->Field === 'id') {
                return preg_match('/^(var)?char\b/', strtolower((string) $row->Type)) === 1;
            }
        }

        return false;
    }

    /**
     * レアリティ文字列 → 数値マッピング
     * 整数型（tinyint 等）の rarity 列にだけ使う。api 側の RarityType::findRank() と同じ値
     */
    private const RARITY_MAP = [
        'UR' => 6,
        'SSR' => 5,
        'SR' => 4,
        'R' => 3,
        'UC' => 2,
        'C' => 1,
    ];

    /**
     * レアリティを持つカラム名。変換するかどうかは名前ではなく、その列の型で決める
     */
    private const RARITY_COLUMNS = ['rarity', 'bonus_rarity'];

    /**
     * rarity 系カラムの型を判定する
     *
     * - ENUM 列: 文字列のまま入れる。値は列の定義（enum(...)）に含まれるものだけ許可する。
     *   並び順に依存しないので、C 先頭（mst_mailbox_content）でも UR 先頭（mst_unit 等）でも同じ
     * - 整数型の列（tinyint 等）: UR=6 … C=1 の数値へ変換する
     * - それ以外の型（文字列列など）: 何もしない
     *
     * @param  array<int, string>  $headers  取り込み対象のカラム名
     * @return array<string, array{kind: 'enum', values: array<int, string>}|array{kind: 'int'}>
     */
    private function getRarityColumns(string $tableName, array $headers): array
    {
        $targets = array_intersect($headers, self::RARITY_COLUMNS);
        if ($targets === []) {
            return [];
        }

        $result = [];
        foreach (DB::connection('mst')->select("SHOW COLUMNS FROM `{$tableName}`") as $row) {
            if (! in_array($row->Field, $targets, true)) {
                continue;
            }

            $type = strtolower((string) $row->Type);
            if (str_starts_with($type, 'enum(')) {
                preg_match_all("/'((?:[^']|'')*)'/", (string) $row->Type, $matches);
                $result[$row->Field] = [
                    'kind' => 'enum',
                    'values' => array_map(fn (string $v) => str_replace("''", "'", $v), $matches[1]),
                ];
            } elseif (preg_match('/^(tiny|small|medium|big)?int\b/', $type) === 1) {
                $result[$row->Field] = ['kind' => 'int'];
            }
        }

        return $result;
    }

    /**
     * rarity 系カラムの値を、列の型に合わせて検証・変換する
     *
     * @param  array{kind: 'enum', values: array<int, string>}|array{kind: 'int'}  $column
     *
     * @throws InvalidArgumentException 列に入れられない値のとき
     */
    private function normalizeRarity(string $header, mixed $value, array $column): mixed
    {
        $text = is_scalar($value) ? (string) $value : '';

        if ($column['kind'] === 'enum') {
            if (! in_array($text, $column['values'], true)) {
                throw new InvalidArgumentException(
                    "{$header} の値「{$text}」は使えません（".implode('・', $column['values']).' のいずれか）'
                );
            }

            return $text;
        }

        // 整数列: UR/SSR 等は数値へ変換。数値で書かれたシートは 1〜6 の範囲で受け付ける
        if (isset(self::RARITY_MAP[$text])) {
            return self::RARITY_MAP[$text];
        }
        if (preg_match('/^[1-6]$/', $text) === 1) {
            return (int) $text;
        }

        throw new InvalidArgumentException(
            "{$header} の値「{$text}」は使えません（".implode('・', array_keys(self::RARITY_MAP)).' または 1〜6 の数値）'
        );
    }

    /**
     * テーブル名のバリデーション（mst_プレフィックスのみ許可）
     */
    private function validateTableName(string $tableName): void
    {
        if (! str_starts_with($tableName, self::ALLOWED_TABLE_PREFIX)) {
            throw new RuntimeException(
                "インポート対象外のテーブルです: {$tableName}。"
                .self::ALLOWED_TABLE_PREFIX.' で始まるテーブルのみ許可されています。'
            );
        }

        // SQLインジェクション対策: テーブル名に英数字とアンダースコア以外を含まないことを確認
        if (! preg_match('/^[a-z0-9_]+$/', $tableName)) {
            throw new RuntimeException(
                "テーブル名に不正な文字が含まれています: {$tableName}"
            );
        }
    }

    /**
     * テーブルの実際のカラム名一覧を取得する
     *
     * @return array<int, string>
     */
    private function getTableColumns(string $tableName): array
    {
        try {
            // getColumnListing() はキャッシュ問題があるため SHOW COLUMNS を直接使用
            $rows = DB::connection('mst')->select("SHOW COLUMNS FROM `{$tableName}`");
            $columns = array_map(fn ($r) => $r->Field, $rows);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "テーブル {$tableName} が mst データベースに存在しません。"
            );
        }

        if (empty($columns)) {
            throw new RuntimeException(
                "テーブル {$tableName} のカラム情報を取得できませんでした。"
            );
        }

        return $columns;
    }

    /**
     * NULLableなカラム名一覧を取得する
     *
     * SHOW COLUMNS の Null カラムが 'YES' のもの。
     * 空文字をNULLに変換するか否かの判定に使用する。
     *
     * @return array<int, string>
     */
    private function getNullableColumns(string $tableName): array
    {
        try {
            $rows = DB::connection('mst')->select("SHOW COLUMNS FROM `{$tableName}`");

            return array_map(
                fn ($r) => $r->Field,
                array_filter($rows, fn ($r) => $r->Null === 'YES')
            );
        } catch (\Throwable) {
            return [];
        }
    }
}

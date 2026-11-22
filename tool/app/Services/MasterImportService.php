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

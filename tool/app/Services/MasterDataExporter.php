<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * mstデータを親テーブル単位のSQLiteファイルへ出力する。
 * __l10nテーブルは親テーブルと同じファイルに含める。
 */
class MasterDataExporter
{
    private string $outputDir;

    private ?ConnectionInterface $connection = null;

    /** 読み取り元の DB 名。null は接続の既定（基底 DB。旧経路）。版ごとの mst DB 設計では作業用 DB・版の DB を指す（db.md 10.4） */
    private ?string $database = null;

    public function __construct()
    {
        $this->outputDir = public_path('masterdata');
    }

    /**
     * mst の読み取りに使う接続を差し替えた複製を返す(トランザクション内の接続で読むときに使う)。
     *
     * コンストラクタ引数にしないのは、コンテナが ConnectionInterface(= 既定接続)を自動注入し、
     * 既定で mst ではなく admin を読んでしまうため。省略時は mst 接続。
     */
    public function withConnection(ConnectionInterface $connection): static
    {
        $clone = clone $this;
        $clone->connection = $connection;

        return $clone;
    }

    /**
     * 読み取り元を、同じ MySQL サーバーの別の DB（作業用 DB・版の DB）にした複製を返す（db.md 6.5.1・10.4）。
     */
    public function withDatabase(string $database): static
    {
        $clone = clone $this;
        $clone->database = $database;

        return $clone;
    }

    /** クエリビルダ・SHOW に渡す表名（DB を指定したときは `db.table`） */
    private function tableRef(string $tableName): string
    {
        return $this->database === null ? $tableName : $this->database.'.'.$tableName;
    }

    /** 生の SQL に書く表名（バッククォート付き） */
    private function quotedTableRef(string $tableName): string
    {
        $quote = static fn (string $name): string => '`'.str_replace('`', '``', $name).'`';

        return $this->database === null ? $quote($tableName) : $quote($this->database).'.'.$quote($tableName);
    }

    /**
     * 配信 SQLite をテーブルグループごとに `$outputDir` へ書く（公開・hash の確定はしない。ディレクトリ名は呼び出し側が決める。db.md 6.5.1）
     *
     * 読み取り元は withDatabase() の DB（無指定なら接続の既定）。ファイル名は `{グループ}_{sqlite の sha256}.sqlite`。
     *
     * @return array<string, array{hash: string, file_name: string, file_size: int, public_url: string}> グループ名 => 結果（public_url は空。呼び出し側が埋める）
     */
    public function exportSqliteGroups(string $outputDir): array
    {
        if (! is_dir($outputDir) && ! mkdir($outputDir, 0755, true) && ! is_dir($outputDir)) {
            throw new RuntimeException('SQLite の出力先ディレクトリを作れません');
        }
        $result = [];
        foreach ($this->groupTables($this->listTableNames()) as $groupName => $groupTableArray) {
            $result[$groupName] = $this->exportGroup($groupName, $groupTableArray, $outputDir);
        }

        return $result;
    }

    private function connection(): ConnectionInterface
    {
        return $this->connection ?? DB::connection('mst');
    }

    /**
     * @return array{
     *   hash: string,
     *   file_size: int,
     *   table_count: int,
     *   file_count: int,
     *   tables: array<string, array{hash: string, file_name: string, file_size: int, public_url: string}>
     * }
     */
    public function export(): array
    {
        if (! is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }

        // 全ファイルを一時ディレクトリへ出力し、マニフェストハッシュ確定後に公開する。
        $stagingDir = $this->outputDir.'/.tmp_'.uniqid('', true);
        mkdir($stagingDir, 0755, true);

        $tableNames = $this->listTableNames();
        $tableGroupArray = $this->groupTables($tableNames);
        $tableResultArray = [];

        try {
            foreach ($tableGroupArray as $groupName => $groupTableArray) {
                $tableResultArray[$groupName] = $this->exportGroup($groupName, $groupTableArray, $stagingDir);
            }

            $manifestHash = hash('sha256', json_encode(
                array_map(static fn ($result) => $result['hash'], $tableResultArray),
                JSON_THROW_ON_ERROR
            ));
            $deploymentDir = $this->outputDir.'/'.$manifestHash;

            if (is_dir($deploymentDir)) {
                File::deleteDirectory($stagingDir);
            } else {
                if (! rename($stagingDir, $deploymentDir)) {
                    throw new RuntimeException('SQLite公開ディレクトリの作成に失敗しました');
                }
            }

            foreach ($tableResultArray as &$tableResult) {
                $tableResult['public_url'] = '/masterdata/'.$manifestHash.'/'.$tableResult['file_name'];
            }
            unset($tableResult);
        } catch (\Throwable $exception) {
            if (is_dir($stagingDir)) {
                File::deleteDirectory($stagingDir);
            }
            throw $exception;
        }

        return [
            'hash' => $manifestHash,
            'file_size' => array_sum(array_column($tableResultArray, 'file_size')),
            'table_count' => count($tableNames),
            'file_count' => count($tableResultArray),
            'tables' => $tableResultArray,
        ];
    }

    /**
     * mst_% のテーブル名をバイト順（照合順序に依らない）で返す。
     * SHOW TABLES の返す順は環境で変わりうるため、ここで固定する。
     *
     * @return array<int, string>
     */
    protected function listTableNames(): array
    {
        $tableNames = array_map(
            static fn ($row) => array_values((array) $row)[0],
            $this->database === null
                ? $this->connection()->select("SHOW TABLES LIKE 'mst_%'")
                : $this->connection()->select('SHOW TABLES FROM `'.str_replace('`', '``', $this->database)."` LIKE 'mst_%'")
        );
        sort($tableNames, SORT_STRING);

        return $tableNames;
    }

    /** @param array<int, string> $tableNameArray
     * @return array<string, array<int, string>>
     */
    private function groupTables(array $tableNameArray): array
    {
        $groupTableArray = [];
        foreach ($tableNameArray as $tableName) {
            $groupName = str_ends_with($tableName, '__l10n')
                ? substr($tableName, 0, -6)
                : $tableName;
            $groupTableArray[$groupName][] = $tableName;
        }

        // マニフェストハッシュはグループの並びに依るので、グループ名のバイト順に固定する
        ksort($groupTableArray, SORT_STRING);
        foreach ($groupTableArray as &$tableArray) {
            sort($tableArray, SORT_STRING);
        }
        unset($tableArray);

        return $groupTableArray;
    }

    /** @param array<int, string> $tableNameArray
     * @return array{hash: string, file_name: string, file_size: int, public_url: string}
     */
    private function exportGroup(string $groupName, array $tableNameArray, string $outputDir): array
    {
        $tmpPath = $outputDir.'/'.$groupName.'.sqlite';

        try {
            $sqlite = new \PDO('sqlite:'.$tmpPath);
            $sqlite->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $sqlite->beginTransaction();

            foreach ($tableNameArray as $tableName) {
                $columns = $this->connection()->select('SHOW COLUMNS FROM '.$this->quotedTableRef($tableName));
                $sqlite->exec($this->buildCreateTableSql($tableName, $columns));
                $rowCollection = $this->orderByPrimaryKey(
                    $this->connection()->table($this->tableRef($tableName)),
                    $columns
                )->get();

                if ($rowCollection->isEmpty()) {
                    continue;
                }

                $columnArray = array_keys((array) $rowCollection->first());
                $columnSql = implode(', ', array_map(static fn ($column) => "`{$column}`", $columnArray));
                $placeholderSql = implode(', ', array_fill(0, count($columnArray), '?'));
                $statement = $sqlite->prepare("INSERT INTO `{$tableName}` ({$columnSql}) VALUES ({$placeholderSql})");

                // bigint は列を INTEGER 親和性で作るので、PDO が int で渡した値は整数のまま保存される(キャストは不要)
                foreach ($rowCollection as $row) {
                    $statement->execute(array_values((array) $row));
                }
            }

            $sqlite->commit();
            unset($sqlite);

            $hash = hash_file('sha256', $tmpPath);
            $fileName = "{$groupName}_{$hash}.sqlite";
            $finalPath = $outputDir.'/'.$fileName;
            rename($tmpPath, $finalPath);

            return [
                'hash' => $hash,
                'file_name' => $fileName,
                'file_size' => filesize($finalPath),
                'public_url' => '',
            ];
        } catch (\Throwable $exception) {
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
            throw new RuntimeException('SQLiteエクスポートに失敗しました: '.$exception->getMessage(), 0, $exception);
        }
    }

    /**
     * 行を主キー列(複合も)の昇順で読む。文字列列は BINARY で照合順序に依らない並びにする。
     * 複合キーは SHOW COLUMNS のテーブル列順(buildCreateTableSql の PRIMARY KEY と同じ)。
     * 数値列は BINARY にすると文字列順("10" < "9")になるため、そのまま数値順にする。
     * 主キーが無いテーブルは全列で並べる。
     *
     * @param  array<int, object>  $columns
     */
    private function orderByPrimaryKey(Builder $query, array $columns): Builder
    {
        $keyColumnArray = [];
        $allColumnArray = [];
        foreach ($columns as $column) {
            $column = (array) $column;
            $allColumnArray[] = $column;
            if ($column['Key'] === 'PRI') {
                $keyColumnArray[] = $column;
            }
        }

        foreach ($keyColumnArray ?: $allColumnArray as $column) {
            $name = str_replace('`', '``', $column['Field']);
            $isNumeric = (bool) preg_match('/^(int|bigint|tinyint|smallint|mediumint|decimal|float|double|numeric)/', strtolower($column['Type']));
            $query->orderByRaw($isNumeric ? "`{$name}` ASC" : "BINARY `{$name}` ASC");
        }

        return $query;
    }

    /** @param array<int, object> $columns */
    private function buildCreateTableSql(string $tableName, array $columns): string
    {
        $primaryKeyArray = [];
        $autoIncrementColumn = null;
        foreach ($columns as $column) {
            $column = (array) $column;
            if ($column['Key'] === 'PRI') {
                $primaryKeyArray[] = $column['Field'];
                if (str_contains($column['Extra'] ?? '', 'auto_increment')) {
                    $autoIncrementColumn = $column['Field'];
                }
            }
        }

        $compositePrimaryKey = count($primaryKeyArray) > 1;
        $columnDefinitionArray = [];
        foreach ($columns as $column) {
            $column = (array) $column;
            $name = $column['Field'];
            $type = strtolower($column['Type']);
            $null = $column['Null'] === 'YES' ? '' : ' NOT NULL';
            $sqliteType = preg_match('/^(int|bigint|tinyint|smallint|mediumint)/', $type)
                ? 'INTEGER'
                : (preg_match('/^(decimal|float|double|numeric)/', $type) ? 'REAL' : 'TEXT');

            if (! $compositePrimaryKey && $column['Key'] === 'PRI' && $autoIncrementColumn === $name) {
                $columnDefinitionArray[] = "`{$name}` INTEGER PRIMARY KEY AUTOINCREMENT";
            } elseif (! $compositePrimaryKey && $column['Key'] === 'PRI') {
                $columnDefinitionArray[] = "`{$name}` {$sqliteType}{$null} PRIMARY KEY";
            } else {
                $columnDefinitionArray[] = "`{$name}` {$sqliteType}{$null}";
            }
        }

        if ($compositePrimaryKey) {
            $primaryKeySql = implode(', ', array_map(static fn ($column) => "`{$column}`", $primaryKeyArray));
            $columnDefinitionArray[] = "PRIMARY KEY ({$primaryKeySql})";
        }

        return "CREATE TABLE IF NOT EXISTS `{$tableName}` (\n  "
            .implode(",\n  ", $columnDefinitionArray)
            ."\n)";
    }
}

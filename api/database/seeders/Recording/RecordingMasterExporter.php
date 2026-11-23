<?php

namespace Database\Seeders\Recording;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RecordingMasterExporter
 *
 * 動画撮影用に、mst の指定テーブルグループだけを SQLite に書き出して配信ディレクトリに置く。
 * 運営ツールの MasterDataExporter（tool/app/Services/MasterDataExporter.php）と同じ規則
 * （行は主キー昇順、bigint は整数で書く、ファイル名 `{グループ}_{ハッシュ}.sqlite`、置き場 `{出力先}/{マニフェストハッシュ}/`、
 * マニフェストハッシュ = グループ名 => ファイルのハッシュ の JSON の sha256）に揃える。
 * tool は別アプリで api から呼べないため、必要な最小限だけをここに持つ。
 * `mst_xxx__l10n` があれば親と同じファイルに入れる。
 *
 * mst の表は string の `id` を主キーに持つ（単一の主キーなので SQLite でも PRIMARY KEY になる）。
 */
class RecordingMasterExporter
{
    public function __construct(private readonly string $outputDir) {}

    /**
     * @param  list<string>  $groupNames  親テーブル名（例: mst_config）
     * @return array{hash: string, tables: array<string, array{hash: string, file_name: string, file_size: int, public_url: string}>}
     *
     * @throws \RuntimeException 出力先が無い、または書き出しに失敗したとき
     */
    public function export(array $groupNames): array
    {
        if (! is_dir($this->outputDir) || ! is_writable($this->outputDir)) {
            throw new \RuntimeException('配信ファイルの出力先がありません（docker-compose.yml の api-php のマウントを確認）: '.$this->outputDir);
        }

        // 全ファイルを一時ディレクトリに作り、マニフェストハッシュが決まってから公開する
        $stagingDir = $this->outputDir.'/.tmp_'.uniqid('', true);
        mkdir($stagingDir, 0755, true);

        try {
            $results = [];
            foreach ($groupNames as $group) {
                $results[$group] = $this->exportGroup($group, $stagingDir);
            }

            $manifestHash = hash('sha256', json_encode(
                array_map(static fn (array $r): string => $r['hash'], $results),
                JSON_THROW_ON_ERROR,
            ));
            $deploymentDir = $this->outputDir.'/'.$manifestHash;

            if (is_dir($deploymentDir)) {
                $this->deleteDirectory($stagingDir);
            } elseif (! rename($stagingDir, $deploymentDir)) {
                throw new \RuntimeException('配信ファイルの公開ディレクトリを作れませんでした: '.$deploymentDir);
            }
        } catch (\Throwable $e) {
            $this->deleteDirectory($stagingDir);
            throw $e;
        }

        foreach ($results as $group => $result) {
            $results[$group]['public_url'] = '/masterdata/'.$manifestHash.'/'.$result['file_name'];
        }

        return ['hash' => $manifestHash, 'tables' => $results];
    }

    /**
     * 配信ディレクトリ直下の `{マニフェストハッシュ}` ディレクトリを消す。
     * 64 桁の 16 進以外は消さない（他のファイルや親ディレクトリを巻き込まないため）。
     */
    public function deleteDeployment(string $manifestHash): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $manifestHash) !== 1) {
            return;
        }

        $this->deleteDirectory($this->outputDir.'/'.$manifestHash);
    }

    /**
     * @return array{hash: string, file_name: string, file_size: int, public_url: string}
     */
    private function exportGroup(string $group, string $dir): array
    {
        $tmpPath = $dir.'/'.$group.'.sqlite';
        $tables = [$group];
        if (Schema::connection('mst')->hasTable($group.'__l10n')) {
            $tables[] = $group.'__l10n';
        }

        $sqlite = new \PDO('sqlite:'.$tmpPath);
        $sqlite->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $sqlite->beginTransaction();

        foreach ($tables as $table) {
            $columns = DB::connection('mst')->select("SHOW COLUMNS FROM `{$table}`");
            $sqlite->exec($this->buildCreateTableSql($table, $columns));

            // 行は主キー昇順（文字列は BINARY、数値は数値順。tool の MasterDataExporter と同じ規則）に読む。
            // 並びが固定なので、同じ mst なら同じファイル（同じ hash）になる
            $rows = $this->orderByPrimaryKey(DB::connection('mst')->table($table), $columns)->get();
            if ($rows->isEmpty()) {
                continue;
            }

            $names = array_keys((array) $rows->first());
            $columnSql = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $names));
            $placeholders = implode(', ', array_fill(0, count($names), '?'));
            $statement = $sqlite->prepare("INSERT INTO `{$table}` ({$columnSql}) VALUES ({$placeholders})");
            $integerColumns = $this->integerColumnFlags($columns, $names);
            foreach ($rows as $row) {
                $values = array_values((array) $row);
                // bigint も整数で書く（文字列のまま束縛しない）
                foreach ($values as $i => $value) {
                    if ($integerColumns[$i] && $value !== null && is_numeric($value)) {
                        $values[$i] = (int) $value;
                    }
                }
                $statement->execute($values);
            }
        }

        $sqlite->commit();
        unset($statement, $sqlite);

        $hash = (string) hash_file('sha256', $tmpPath);
        $fileName = "{$group}_{$hash}.sqlite";
        rename($tmpPath, $dir.'/'.$fileName);

        return [
            'hash' => $hash,
            'file_name' => $fileName,
            'file_size' => (int) filesize($dir.'/'.$fileName),
            'public_url' => '',
        ];
    }

    /**
     * 行を主キー列（複合も）の昇順で読む。文字列列は BINARY で照合順序に依らない並びにし、
     * 数値列は BINARY にせず数値順にする（"10" が "9" より前にならないように）。主キーが無い表は全列で並べる。
     * tool/app/Services/MasterDataExporter.php の orderByPrimaryKey と同じ規則（変えるときは両方）。
     *
     * @param  array<int, object>  $columns  SHOW COLUMNS の結果
     */
    private function orderByPrimaryKey(Builder $query, array $columns): Builder
    {
        $columns = array_map(static fn (object $c): array => (array) $c, $columns);
        $keys = array_values(array_filter($columns, static fn (array $c): bool => $c['Key'] === 'PRI'));

        foreach ($keys !== [] ? $keys : $columns as $column) {
            $name = str_replace('`', '``', (string) $column['Field']);
            $query->orderByRaw($this->isIntegerType((string) $column['Type']) || $this->isDecimalType((string) $column['Type'])
                ? "`{$name}` ASC"
                : "BINARY `{$name}` ASC");
        }

        return $query;
    }

    /**
     * @param  array<int, object>  $columns  SHOW COLUMNS の結果
     * @param  list<string>  $names  行の列名（並び順）
     * @return list<bool>
     */
    private function integerColumnFlags(array $columns, array $names): array
    {
        $types = [];
        foreach ($columns as $column) {
            $column = (array) $column;
            $types[(string) $column['Field']] = (string) $column['Type'];
        }

        return array_map(fn (string $n): bool => isset($types[$n]) && $this->isIntegerType($types[$n]), $names);
    }

    private function isIntegerType(string $type): bool
    {
        return preg_match('/^(int|bigint|tinyint|smallint|mediumint)/', strtolower($type)) === 1;
    }

    private function isDecimalType(string $type): bool
    {
        return preg_match('/^(decimal|float|double|numeric)/', strtolower($type)) === 1;
    }

    /**
     * MasterDataExporter::buildCreateTableSql と同じ型の対応（整数 → INTEGER、小数 → REAL、他 → TEXT）。
     *
     * @param  array<int, object>  $columns
     */
    private function buildCreateTableSql(string $table, array $columns): string
    {
        $columns = array_map(static fn (object $c): array => (array) $c, $columns);
        $primaryKeys = [];
        $autoIncrement = null;
        foreach ($columns as $column) {
            if ($column['Key'] === 'PRI') {
                $primaryKeys[] = $column['Field'];
                if (str_contains((string) ($column['Extra'] ?? ''), 'auto_increment')) {
                    $autoIncrement = $column['Field'];
                }
            }
        }

        $composite = count($primaryKeys) > 1;
        $definitions = [];
        foreach ($columns as $column) {
            $type = strtolower((string) $column['Type']);
            $sqliteType = preg_match('/^(int|bigint|tinyint|smallint|mediumint)/', $type) === 1
                ? 'INTEGER'
                : (preg_match('/^(decimal|float|double|numeric)/', $type) === 1 ? 'REAL' : 'TEXT');
            $null = $column['Null'] === 'YES' ? '' : ' NOT NULL';
            if (! $composite && $column['Key'] === 'PRI' && $autoIncrement === $column['Field']) {
                $definitions[] = "`{$column['Field']}` INTEGER PRIMARY KEY AUTOINCREMENT";

                continue;
            }
            $primary = ! $composite && $column['Key'] === 'PRI' ? ' PRIMARY KEY' : '';
            $definitions[] = "`{$column['Field']}` {$sqliteType}{$null}{$primary}";
        }
        if ($composite) {
            $definitions[] = 'PRIMARY KEY ('.implode(', ', array_map(static fn (string $c): string => "`{$c}`", $primaryKeys)).')';
        }

        return "CREATE TABLE IF NOT EXISTS `{$table}` (\n  ".implode(",\n  ", $definitions)."\n)";
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}

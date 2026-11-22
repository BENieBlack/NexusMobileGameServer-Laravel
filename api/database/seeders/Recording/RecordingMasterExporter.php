<?php

namespace Database\Seeders\Recording;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RecordingMasterExporter
 *
 * 動画撮影用に、mst の指定テーブルグループだけを SQLite に書き出して配信ディレクトリに置く。
 * 運営ツールの MasterDataExporter（tool/app/Services/MasterDataExporter.php）と同じ規則
 * （ファイル名 `{グループ}_{ハッシュ}.sqlite`、置き場 `{出力先}/{マニフェストハッシュ}/`、
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

            // 行の並びが変わるとハッシュが変わるので、並びを固定する（再実行で同じファイルになる）
            $rows = DB::connection('mst')->table($table)->get()
                ->sortBy(fn ($row) => json_encode(array_values((array) $row)))
                ->values();
            if ($rows->isEmpty()) {
                continue;
            }

            $names = array_keys((array) $rows->first());
            $columnSql = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $names));
            $placeholders = implode(', ', array_fill(0, count($names), '?'));
            $statement = $sqlite->prepare("INSERT INTO `{$table}` ({$columnSql}) VALUES ({$placeholders})");
            foreach ($rows as $row) {
                $statement->execute(array_values((array) $row));
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
     * MasterDataExporter::buildCreateTableSql と同じ型の対応（整数 → INTEGER、小数 → REAL、他 → TEXT）。
     *
     * @param  array<int, object>  $columns
     */
    private function buildCreateTableSql(string $table, array $columns): string
    {
        $columns = array_map(static fn (object $c): array => (array) $c, $columns);
        $primaryKeys = [];
        foreach ($columns as $column) {
            if ($column['Key'] === 'PRI') {
                $primaryKeys[] = $column['Field'];
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

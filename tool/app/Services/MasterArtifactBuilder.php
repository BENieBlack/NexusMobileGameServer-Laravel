<?php

namespace App\Services;

use RuntimeException;

/**
 * 成果物（artifact）をローカルの作業ディレクトリに作る（db.md 6.5.1・6.5.2）
 *
 * 読み取り元は作業用 DB（または版の DB）。作るもの:
 * - `data/{表}.ndjson`（畳み後の行。主キー昇順・値はすべて文字列か null）
 * - `{グループ}_{sqlite の sha256}.sqlite`（配信 SQLite。MasterDataExporter）
 * - `schema.json`（表定義とスキーマ版）
 * - `manifest.json`（**最後に**書く。完成の印）
 *
 * hash（完全な 64 文字）= SHA-256(正規化 JSON{format_version, schema_fingerprint, tables[{name,row_count,data_sha256}]（表名順）})。
 * deploy_key・時刻・配信 SQLite のバイト列は含めない。ディレクトリ名は `{deploy_key}_{hash の先頭 16 文字}`。
 * ストレージへ置く（`manifest.json` を最後に）のは MasterArtifactStore。
 */
class MasterArtifactBuilder
{
    public const FORMAT_VERSION = 1;

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly MasterVersionDatabase $databases,
        private readonly MasterDataExporter $exporter,
    ) {}

    /**
     * @param  string  $database  読み取り元（作業用 DB）
     * @param  int  $deployKey  T
     * @param  array<string, array<string, string>>  $deployKeyLookup  deploy_key 列を持たない表のための、表名 => (id => 行の元の deploy_key)。row_digest 用
     * @param  array{built_env: string, built_at: string, source_kind: string}  $origin  マニフェストの出所（hash に含めない）
     * @return array{
     *   hash: string,
     *   artifact_dir: string,
     *   schema_fingerprint: string,
     *   manifest: array<string, mixed>,
     *   tables: array<string, array{hash: string, file_name: string, file_size: int, public_url: string}>,
     *   data_table_list: list<array{name: string, row_count: int, data_sha256: string}>,
     *   row_digest_list: list<array{deploy_key: int, digest: string}>,
     *   file_size: int
     * }
     *
     * @throws \JsonException 不正な UTF-8 など（呼び出し側が load_failed にする）
     * @throws RuntimeException ファイルを書けないとき
     */
    public function build(string $database, int $deployKey, string $localDir, array $deployKeyLookup, array $origin): array
    {
        if (! is_dir($localDir.'/data') && ! mkdir($localDir.'/data', 0755, true) && ! is_dir($localDir.'/data')) {
            throw new RuntimeException('成果物の作業ディレクトリを作れません');
        }

        $definition = $this->databases->schemaDefinition($database);
        $fingerprint = $this->databases->schemaFingerprint($database, $definition);

        $dataTableList = [];
        /** @var array<int, \HashContext> $digestContexts */
        $digestContexts = [];
        foreach ($definition as $table) {
            $name = $table['name'];
            $hasDeployKey = in_array('deploy_key', array_column($table['columns'], 'name'), true);
            $result = $this->databases->exportRows(
                $database,
                $table,
                $localDir.'/data/'.$name.'.ndjson',
                function (array $row) use ($name, $hasDeployKey, $deployKeyLookup, &$digestContexts): void {
                    $key = $hasDeployKey ? ($row['deploy_key'] ?? null) : ($deployKeyLookup[$name][(string) ($row['id'] ?? '')] ?? null);
                    if ($key === null || ! ctype_digit((string) $key)) {
                        return;
                    }
                    $digestContexts[(int) $key] ??= hash_init('sha256');
                    hash_update($digestContexts[(int) $key], json_encode($row, self::JSON_FLAGS)."\n");
                },
            );
            $dataTableList[] = ['name' => $name, 'row_count' => $result['row_count'], 'data_sha256' => $result['sha256']];
        }
        ksort($digestContexts);
        $rowDigestList = [];
        foreach ($digestContexts as $key => $context) {
            $rowDigestList[] = ['deploy_key' => $key, 'digest' => hash_final($context)];
        }

        $hash = hash('sha256', json_encode([
            'format_version' => self::FORMAT_VERSION,
            'schema_fingerprint' => $fingerprint,
            'tables' => $dataTableList,
        ], self::JSON_FLAGS));
        $artifactDir = $deployKey.'_'.substr($hash, 0, MasterVersionDatabase::HASH_LENGTH);

        $sqlite = $this->exporter->withDatabase($database)->exportSqliteGroups($localDir);
        $groupList = [];
        $tables = [];
        foreach ($sqlite as $group => $result) {
            $groupList[] = ['group' => $group, 'file_name' => $result['file_name'], 'sqlite_sha256' => $result['hash'], 'file_size' => $result['file_size']];
            $tables[$group] = [
                'hash' => $result['hash'],
                'file_name' => $result['file_name'],
                'file_size' => $result['file_size'],
                'public_url' => '/masterdata/'.$artifactDir.'/'.$result['file_name'],
            ];
        }

        // data_columns: NDJSON に入る列（created_at・updated_at を除く。投入時は固定値）
        $schemaTables = [];
        foreach ($definition as $table) {
            $table['data_columns'] = array_values(array_filter(
                array_column($table['columns'], 'name'),
                static fn (string $column): bool => ! in_array($column, MasterVersionDatabase::AUTO_COLUMNS, true),
            ));
            $schemaTables[] = $table;
        }
        $schemaJson = json_encode(
            ['format_version' => self::FORMAT_VERSION, 'schema_fingerprint' => $fingerprint, 'tables' => $schemaTables],
            self::JSON_FLAGS | JSON_PRETTY_PRINT,
        );
        $this->write($localDir.'/schema.json', $schemaJson);

        $manifest = [
            'format_version' => self::FORMAT_VERSION,
            'deploy_key' => $deployKey,
            'hash' => $hash,
            'schema_fingerprint' => $fingerprint,
            'schema_sha256' => hash('sha256', $schemaJson."\n"), // write() が末尾に LF を足すので、書いたファイルの SHA-256 にそろえる（I1 が再計算する）
            'tables' => $dataTableList,
            'group_list' => $groupList,
            'built_env' => $origin['built_env'],
            'built_at' => $origin['built_at'],
            'source_kind' => $origin['source_kind'],
            'fold_limit' => $deployKey,
            'row_digest_list' => $rowDigestList,
        ];
        // manifest.json は最後に書く（完成の印）
        $this->write($localDir.'/manifest.json', json_encode($manifest, self::JSON_FLAGS | JSON_PRETTY_PRINT));

        return [
            'hash' => $hash,
            'artifact_dir' => $artifactDir,
            'schema_fingerprint' => $fingerprint,
            'manifest' => $manifest,
            'tables' => $tables,
            'data_table_list' => $dataTableList,
            'row_digest_list' => $rowDigestList,
            'file_size' => array_sum(array_column($groupList, 'file_size')),
        ];
    }

    private function write(string $path, string $content): void
    {
        if (file_put_contents($path, $content."\n") === false) {
            throw new RuntimeException("ファイルを書けません: {$path}");
        }
    }
}

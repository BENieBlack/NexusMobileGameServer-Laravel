<?php

namespace App\Services;

use App\Exceptions\MasterDeployRunException;
use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

/**
 * 版ごとの mst DB（版の DB・作業用 DB）の命名・作成・削除・スキーマ版・投入・行データの書き出し
 *
 * 設計: docs/design/master-distribution/db.md 4.1〜4.7（命名・作り方・スキーマ版・権限・孤児）、6.5.1（行データ）、6.6（version_db）
 *
 * - DB 名: 版の DB は `{APP_NAME}-{env}-master-{deploy_key}-{hash の先頭 16 文字}`、作業用 DB は
 *   `{APP_NAME}-{env}-master-work-{UTC の YYYYMMDDHHMMSS}-{ランダム 4 文字}`。基底 DB は `{APP_NAME}-{env}-master`
 * - 作り方: 基底 DB の `mst_%` の表定義を `CREATE TABLE ... LIKE` で複製する（マイグレーションは流さない。tool は packages を持たない）
 * - `DROP` の保護: 自環境の接頭辞 `{APP_NAME}-{env}-master-` が付き、基底 DB と一致しない名前だけ（`drop_forbidden`）
 * - tool の `mst` 接続は基底 DB を指す。別の DB は `db`.`table` と修飾して同じ接続から読み書きする（同じ MySQL サーバーであること。4.3）
 */
class MasterVersionDatabase
{
    /** 行データの読み取り専用の接続名（`mst` と同じ設定に、値を MySQL の表記のまま文字列で返す PDO 属性を足したもの。db.md 6.5.1 の書き出しの正規化） */
    public const READ_CONNECTION = 'mst_artifact_read';

    /** 版の DB・配信 SQLite に入れる created_at・updated_at の固定値（build のたびに時刻が変わって hash が変わるのを避ける。db.md 6.5.1。仮置き） */
    public const FIXED_TIMESTAMP = '1970-01-01 00:00:00';

    /** 行データ（NDJSON）に含めず、投入時に固定値を入れる列 */
    public const AUTO_COLUMNS = ['created_at', 'updated_at'];

    /** DB 名に使う hash の長さ（先頭 16 文字。決定） */
    public const HASH_LENGTH = 16;

    /** `len(APP_NAME) + len(env)` の上限（hash 16 文字が 64 文字の名前に収まる条件。db.md 4.2） */
    public const NAME_BASE_MAX = 28;

    private const INSERT_CHUNK = 500;

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public function __construct(private readonly ?MasterImportService $importService = null) {}

    // ---- 命名（4.1・4.2） ----

    public function appName(): string
    {
        return (string) config('app.name');
    }

    public function env(): string
    {
        return (string) config('app.env');
    }

    /** 基底 DB の期待する名前 `{APP_NAME}-{env}-master` */
    public function expectedBaseDatabase(): string
    {
        return $this->appName().'-'.$this->env().'-master';
    }

    /** tool の `mst` 接続が指す DB（基底 DB） */
    public function baseDatabase(): string
    {
        return (string) config('database.connections.mst.database');
    }

    /** 自環境の版の DB・作業用 DB の接頭辞（末尾のハイフンを含む） */
    public function prefix(): string
    {
        return $this->expectedBaseDatabase().'-';
    }

    public function versionName(int $deployKey, string $hash): string
    {
        return $this->prefix().$deployKey.'-'.substr($hash, 0, self::HASH_LENGTH);
    }

    public function workName(?CarbonInterface $now = null): string
    {
        $time = ($now ?? now())->copy()->utc()->format('YmdHis');

        return $this->prefix().'work-'.$time.'-'.Str::lower(Str::random(4));
    }

    /**
     * build・import の最初（排他の前）の設定の検査
     *
     * @throws MasterDeployRunException mst_database_name_too_long・base_database_mismatch
     */
    public function assertConfiguration(): void
    {
        $length = strlen($this->appName()) + strlen($this->env());
        if ($length > self::NAME_BASE_MAX) {
            throw new MasterDeployRunException(
                MasterDeployRunException::MST_DATABASE_NAME_TOO_LONG,
                'APP_NAME と APP_ENV の長さの合計が '.self::NAME_BASE_MAX.' 文字を超えています（版の DB の名前が 64 文字に収まりません）: '.$length,
                500,
            );
        }
        if ($this->baseDatabase() !== $this->expectedBaseDatabase()) {
            throw new MasterDeployRunException(
                MasterDeployRunException::BASE_DATABASE_MISMATCH,
                "tool の mst 接続の database（{$this->baseDatabase()}）が {APP_NAME}-{APP_ENV}-master（{$this->expectedBaseDatabase()}）と一致しません。api と tool の APP_NAME・APP_ENV・DB_MASTER_DATABASE を揃えてください。",
                500,
            );
        }
    }

    // ---- DB の存在・作成・削除（4.3・4.6） ----

    public function connection(): ConnectionInterface
    {
        return DB::connection('mst');
    }

    /** 行データの読み取り専用の接続（STRINGIFY_FETCHES・EMULATE_PREPARES。値は MySQL が返す表記のままの文字列になる） */
    public function readConnection(): ConnectionInterface
    {
        $config = (array) config('database.connections.mst');
        $options = (array) ($config['options'] ?? []);
        $options[PDO::ATTR_STRINGIFY_FETCHES] = true;
        $options[PDO::ATTR_EMULATE_PREPARES] = true;
        $config['options'] = $options;
        if (config('database.connections.'.self::READ_CONNECTION) !== $config) {
            config(['database.connections.'.self::READ_CONNECTION => $config]);
            DB::purge(self::READ_CONNECTION);
        }

        return DB::connection(self::READ_CONNECTION);
    }

    public function databaseExists(string $database): bool
    {
        return $this->connection()->selectOne('SELECT 1 AS found FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$database]) !== null;
    }

    /**
     * 自環境の版の DB・作業用 DB の名前か（DROP・CREATE の保護。db.md 4.6）
     *
     * @throws MasterDeployRunException drop_forbidden
     */
    public function assertOwnName(string $database): void
    {
        $prefix = $this->prefix();
        if (! str_starts_with($database, $prefix) || strlen($database) <= strlen($prefix)
            || $database === $this->baseDatabase() || $database === $this->expectedBaseDatabase()
            || preg_match('/^[A-Za-z0-9_-]+$/D', $database) !== 1) {
            throw new MasterDeployRunException(
                MasterDeployRunException::DROP_FORBIDDEN,
                "この名前の DB は作成・削除できません（自環境の接頭辞 {$prefix} が付いた版の DB・作業用 DB だけ）: {$database}",
                403,
            );
        }
    }

    /**
     * 基底 DB の `mst_%` の表定義を複製して新しい DB を作る（行は入れない）。名前が既にあれば失敗する
     *
     * @throws MasterDeployRunException drop_forbidden（名前の保護）
     */
    public function createFromBase(string $database): void
    {
        $this->assertOwnName($database);
        $connection = $this->connection();
        $quoted = self::quote($database);
        $connection->statement("CREATE DATABASE {$quoted} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        foreach ($this->listTables($this->baseDatabase()) as $table) {
            $connection->statement('CREATE TABLE '.$quoted.'.'.self::quote($table).' LIKE '.self::quote($this->baseDatabase()).'.'.self::quote($table));
        }
    }

    /**
     * `DROP DATABASE IF EXISTS`。名前の保護を通ったものだけ
     *
     * @throws MasterDeployRunException drop_forbidden
     */
    public function drop(string $database): void
    {
        $this->assertOwnName($database);
        $this->connection()->statement('DROP DATABASE IF EXISTS '.self::quote($database));
    }

    /**
     * @return list<string> `mst_%` の表名（バイト順）
     */
    public function listTables(string $database): array
    {
        $rows = $this->connection()->select(
            "SELECT TABLE_NAME AS table_name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE 'mst\\_%'",
            [$database],
        );
        $list = array_map(static fn ($row): string => (string) $row->table_name, $rows);
        sort($list, SORT_STRING);

        return $list;
    }

    // ---- スキーマ版（4.4） ----

    /**
     * `mst_%` の全表の構造（列・索引）。スキーマ版と schema.json の元
     *
     * 型は表示幅を落とす（`int(11)` → `int`）。既定値は文字列か null。コメント・AUTO_INCREMENT の現在値は含めない。
     *
     * @return list<array{name: string, columns: list<array{name: string, type: string, nullable: bool, default: ?string, key: string}>, indexes: list<array{name: string, unique: bool, columns: list<string>}>}>
     */
    public function schemaDefinition(string $database): array
    {
        $connection = $this->connection();
        $columnRows = $connection->select(
            "SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, ORDINAL_POSITION AS ordinal, COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default, COLUMN_KEY AS column_key FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE 'mst\\_%'",
            [$database],
        );
        $indexRows = $connection->select(
            "SELECT TABLE_NAME AS table_name, INDEX_NAME AS index_name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS seq, COLUMN_NAME AS column_name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE 'mst\\_%'",
            [$database],
        );

        /** @var array<string, array<int, array<string, mixed>>> $columns */
        $columns = [];
        foreach ($columnRows as $row) {
            $columns[(string) $row->table_name][(int) $row->ordinal] = [
                'name' => (string) $row->column_name,
                'type' => preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', (string) $row->column_type),
                'nullable' => (string) $row->is_nullable === 'YES',
                'default' => $row->column_default === null ? null : (string) $row->column_default,
                'key' => (string) $row->column_key,
            ];
        }
        /** @var array<string, array<string, array{unique: bool, seq: array<int, string>}>> $indexes */
        $indexes = [];
        foreach ($indexRows as $row) {
            $index = &$indexes[(string) $row->table_name][(string) $row->index_name];
            $index['unique'] = (int) $row->non_unique === 0;
            $index['seq'][(int) $row->seq] = (string) $row->column_name;
            unset($index);
        }

        $tableNames = array_keys($columns);
        sort($tableNames, SORT_STRING);
        $result = [];
        foreach ($tableNames as $table) {
            ksort($columns[$table]);
            $indexList = [];
            $indexNames = array_keys($indexes[$table] ?? []);
            sort($indexNames, SORT_STRING);
            foreach ($indexNames as $name) {
                ksort($indexes[$table][$name]['seq']);
                $indexList[] = ['name' => $name, 'unique' => $indexes[$table][$name]['unique'], 'columns' => array_values($indexes[$table][$name]['seq'])];
            }
            $result[] = ['name' => $table, 'columns' => array_values($columns[$table]), 'indexes' => $indexList];
        }

        return $result;
    }

    /**
     * スキーマ版（schema_fingerprint）= `mst_%` の全表の列・索引の構造の SHA-256
     *
     * @param  list<array<string, mixed>>|null  $definition  schemaDefinition() の結果（省略時はここで読む）
     */
    public function schemaFingerprint(string $database, ?array $definition = null): string
    {
        return hash('sha256', json_encode($definition ?? $this->schemaDefinition($database), self::JSON_FLAGS));
    }

    // ---- 投入（6.6 の work_db・version_db） ----

    /**
     * 畳み後の採用行を DB に入れる。DDL を終えた後に、全表を 1 つのトランザクションで入れる（500 行ずつ。db.md 6.6 の h）
     *
     * 行の変換は旧 import と同じ（NULL 可の列の空文字は NULL、rarity は型に合わせる）。シートの行に無い列は空文字から同じ規則で変換する。
     * created_at・updated_at は固定値。失敗したらロールバックして、表名と DB の文言（SQL・バインド値は含めない）を持つ RuntimeException を投げる。
     *
     * @param  array<string, array{adopted: array<int, array<string, mixed>>}>  $folded  MasterOverlayFolder::fold() の結果
     * @return array<string, int> 表名 => 入れた行数（行が 1 行以上の表だけ）
     *
     * @throws RuntimeException
     */
    public function loadFolded(string $database, array $folded): array
    {
        $definition = [];
        foreach ($this->schemaDefinition($database) as $table) {
            $definition[$table['name']] = $table;
        }
        $import = $this->importService ?? new MasterImportService;
        $connection = $this->connection();
        $loaded = [];

        $connection->beginTransaction();
        $current = '';
        try {
            foreach ($folded as $tableName => $fold) {
                if ($fold['adopted'] === []) {
                    continue;
                }
                $current = $tableName;
                if (! isset($definition[$tableName])) {
                    throw new RuntimeException('基底 DB に表がありません');
                }
                $loaded[$tableName] = $this->loadTable($connection, $import, $database, $definition[$tableName], $tableName, $fold['adopted']);
            }
            $connection->commit();
        } catch (Throwable $e) {
            try {
                $connection->rollBack();
            } catch (Throwable) {
                // ロールバックの失敗は元の例外を優先する（DB ごと DROP される）
            }

            throw new RuntimeException(($current !== '' ? "{$current}: " : '').self::safeMessage($e), 0, $e);
        }

        return $loaded;
    }

    /**
     * @param  array{name: string, columns: list<array{name: string, nullable: bool}>}  $tableDefinition
     * @param  array<int, array<string, mixed>>  $adopted
     */
    private function loadTable(ConnectionInterface $connection, MasterImportService $import, string $database, array $tableDefinition, string $tableName, array $adopted): int
    {
        $isL10n = str_ends_with($tableName, '__l10n');
        $idColumn = $isL10n ? substr($tableName, 0, -strlen('__l10n')).'_id' : 'id';

        $rows = [];
        $headerSet = [];
        foreach ($adopted as $entry) {
            $row = [$idColumn => $entry['id']];
            if ($isL10n) {
                $row['language'] = $entry['language'];
            }
            foreach ((array) $entry['columns'] as $column => $value) {
                $row[$column] = $value;
            }
            $row['deploy_key'] = $entry['deploy_key'];
            foreach (array_keys($row) as $column) {
                $headerSet[(string) $column] = true;
            }
            $rows[] = $row;
        }

        $nullable = [];
        $existing = [];
        foreach ($tableDefinition['columns'] as $column) {
            $existing[] = $column['name'];
            if ($column['nullable']) {
                $nullable[$column['name']] = true;
            }
        }
        $headers = array_values(array_filter(
            array_keys($headerSet),
            static fn (string $header): bool => in_array($header, $existing, true) && ! in_array($header, self::AUTO_COLUMNS, true),
        ));
        if ($headers === []) {
            throw new RuntimeException('シートの列が表の列に 1 つも対応しません');
        }
        $auto = array_values(array_intersect(self::AUTO_COLUMNS, $existing));
        $rarity = $import->rarityColumnsOf($tableName, $headers);

        $columnSql = implode(', ', array_map([self::class, 'quote'], [...$headers, ...$auto]));
        $rowPlaceholder = '('.implode(', ', array_fill(0, count($headers) + count($auto), '?')).')';
        $target = self::quote($database).'.'.self::quote($tableName);

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $bindings = [];
            foreach ($chunk as $row) {
                foreach ($headers as $header) {
                    $bindings[] = $import->convertValue($header, $row[$header] ?? '', isset($nullable[$header]), $rarity[$header] ?? null);
                }
                foreach ($auto as $_) {
                    $bindings[] = self::FIXED_TIMESTAMP;
                }
            }
            $connection->insert(
                "INSERT INTO {$target} ({$columnSql}) VALUES ".implode(', ', array_fill(0, count($chunk), $rowPlaceholder)),
                $bindings,
            );
        }

        return count($rows);
    }

    /**
     * 別の DB の全表の行を、同じ名前の表へ写す（`INSERT ... SELECT`。作業用 DB → 版の DB）。表定義が同じ（LIKE で作った）前提。
     * 1 つのトランザクションで行う。失敗したらロールバックして例外
     *
     * @throws RuntimeException
     */
    public function copyRows(string $fromDatabase, string $toDatabase): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        $current = '';
        try {
            foreach ($this->listTables($fromDatabase) as $table) {
                $current = $table;
                $connection->statement(
                    'INSERT INTO '.self::quote($toDatabase).'.'.self::quote($table).' SELECT * FROM '.self::quote($fromDatabase).'.'.self::quote($table)
                );
            }
            $connection->commit();
        } catch (Throwable $e) {
            try {
                $connection->rollBack();
            } catch (Throwable) {
            }

            throw new RuntimeException(($current !== '' ? "{$current}: " : '').self::safeMessage($e), 0, $e);
        }
    }

    // ---- 行データ（6.5.1 の NDJSON・往復検査） ----

    /**
     * 1 つの表の行を、主キー昇順（BINARY）・列は表の列順（created_at・updated_at を除く）の NDJSON にして、行数と SHA-256 を返す。
     * `$path` を渡すとファイルにも書く（親ディレクトリは呼び出し側が作る）。`$onRow` には各行（列名 => 文字列か null）を渡す
     *
     * 値はすべて JSON の文字列か null（型は schema.json が決める）。区切りは LF で、最後の行の後にも LF。空の表は 0 バイト。
     *
     * @param  array{name: string, columns: list<array{name: string, type: string, key: string}>, indexes: list<array{name: string, columns: list<string>}>}  $tableDefinition
     * @param  (callable(array<string, string|null>): void)|null  $onRow
     * @return array{row_count: int, sha256: string}
     *
     * @throws \JsonException 不正な UTF-8 など
     */
    public function exportRows(string $database, array $tableDefinition, ?string $path = null, ?callable $onRow = null): array
    {
        $columns = array_values(array_filter(
            array_column($tableDefinition['columns'], 'name'),
            static fn (string $name): bool => ! in_array($name, self::AUTO_COLUMNS, true),
        ));
        $select = implode(', ', array_map([self::class, 'quote'], $columns));
        $sql = "SELECT {$select} FROM ".self::quote($database).'.'.self::quote($tableDefinition['name']).' ORDER BY '.$this->orderBy($tableDefinition);

        $context = hash_init('sha256');
        $handle = $path === null ? null : fopen($path, 'wb');
        if ($path !== null && $handle === false) {
            throw new RuntimeException("行データを書けません: {$path}");
        }
        $count = 0;
        try {
            foreach ($this->readConnection()->cursor($sql) as $row) {
                $values = [];
                foreach ((array) $row as $name => $value) {
                    $values[(string) $name] = $value === null ? null : (string) $value;
                }
                $line = json_encode($values, self::JSON_FLAGS)."\n";
                hash_update($context, $line);
                if ($handle !== null) {
                    fwrite($handle, $line);
                }
                if ($onRow !== null) {
                    $onRow($values);
                }
                $count++;
            }
        } finally {
            if ($handle !== null) {
                fclose($handle);
            }
        }

        return ['row_count' => $count, 'sha256' => hash_final($context)];
    }

    /**
     * 往復検査: DB から行データを書き出した SHA-256 が、成果物の表ごとの `data_sha256` と一致するか（db.md 6.6）
     *
     * 比べる列は DB の現在の列から created_at・updated_at を除いたもの（マイグレーションで足した列があれば結果が変わる。
     * 成果物の schema.json の列だけに絞る処理は import の段階で足す。**仮置き**）。成果物に無い表・DB に無い表も不一致。
     *
     * @param  list<array{name: string, row_count: int, data_sha256: string}>  $manifestTables
     */
    public function roundTripMatches(string $database, array $manifestTables): bool
    {
        $definition = [];
        foreach ($this->schemaDefinition($database) as $table) {
            $definition[$table['name']] = $table;
        }
        if (count($definition) !== count($manifestTables)) {
            return false;
        }
        try {
            foreach ($manifestTables as $expected) {
                if (! isset($definition[$expected['name']])) {
                    return false;
                }
                $actual = $this->exportRows($database, $definition[$expected['name']]);
                if ($actual['sha256'] !== $expected['data_sha256'] || $actual['row_count'] !== (int) $expected['row_count']) {
                    return false;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * 主キー（複合は構成列の順）の昇順。文字列列は BINARY（照合順序に依らない）、数値列は数値順。主キーが無い表は全列
     *
     * @param  array{columns: list<array{name: string, type: string}>, indexes: list<array{name: string, columns: list<string>}>}  $tableDefinition
     */
    private function orderBy(array $tableDefinition): string
    {
        $types = [];
        foreach ($tableDefinition['columns'] as $column) {
            $types[$column['name']] = strtolower($column['type']);
        }
        $keyColumns = [];
        foreach ($tableDefinition['indexes'] as $index) {
            if ($index['name'] === 'PRIMARY') {
                $keyColumns = $index['columns'];
            }
        }
        if ($keyColumns === []) {
            $keyColumns = array_keys($types);
        }
        $parts = [];
        foreach ($keyColumns as $name) {
            $quoted = self::quote($name);
            $numeric = preg_match('/^(int|bigint|tinyint|smallint|mediumint|decimal|float|double|numeric)/', $types[$name] ?? '') === 1;
            $parts[] = $numeric ? "{$quoted} ASC" : "BINARY {$quoted} ASC";
        }

        return implode(', ', $parts);
    }

    public static function quote(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    /**
     * 利用者に出してよい例外の文言。QueryException の message は SQL とバインド値（シートの値）を含むので、中の PDOException（DB の文言だけ）を使う
     */
    public static function safeMessage(Throwable $e): string
    {
        if ($e instanceof QueryException) {
            return mb_substr($e->getPrevious()?->getMessage() ?? 'DB がクエリを拒否しました', 0, 500);
        }

        return mb_substr($e->getMessage(), 0, 500);
    }
}

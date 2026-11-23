<?php

namespace Tests\Feature;

use App\Services\MasterImportService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * MasterImportService::reflect()（差分反映。db.md 6.5）のテスト
 *
 * テスト用の mst データベース（tool 専用）に、decimal・日時・複合主キーを持つ表を作って確かめる。
 */
class MasterImportServiceReflectTest extends TestCase
{
    private const TABLES = [
        'mst_reflect_item' => 'id VARCHAR(255) NOT NULL PRIMARY KEY, name VARCHAR(255) NOT NULL DEFAULT \'\', rate DECIMAL(10,2) NOT NULL DEFAULT 0, big BIGINT NOT NULL DEFAULT 0, start_at DATETIME NULL, memo VARCHAR(255) NULL, deploy_key BIGINT NOT NULL DEFAULT 0, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, UNIQUE KEY uk_reflect_name (name)',
        'mst_reflect_item__l10n' => 'mst_reflect_item_id VARCHAR(255) NOT NULL, language VARCHAR(10) NOT NULL, label VARCHAR(255) NOT NULL DEFAULT \'\', created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, PRIMARY KEY (mst_reflect_item_id, language)',
        'mst_reflect_untouched' => 'id VARCHAR(255) NOT NULL PRIMARY KEY, name VARCHAR(255) NOT NULL DEFAULT \'\'',
    ];

    private const OLD = '2020-01-01 00:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::connection('mst')->getDatabaseName();
        $this->assertStringContainsString('testing', $database);
        $this->assertStringContainsString('tool-master', $database, 'tool 専用以外の mst データベースにはテーブルを作らない');

        foreach (self::TABLES as $table => $columns) {
            DB::connection('mst')->statement("DROP TABLE IF EXISTS `{$table}`");
            DB::connection('mst')->statement("CREATE TABLE `{$table}` ({$columns})");
        }
    }

    protected function tearDown(): void
    {
        // 開いたままのトランザクションが残っていれば戻す
        while (DB::connection('mst')->transactionLevel() > 0) {
            DB::connection('mst')->rollBack();
        }
        foreach (array_keys(self::TABLES) as $table) {
            DB::connection('mst')->statement("DROP TABLE IF EXISTS `{$table}`");
        }

        parent::tearDown();
    }

    private const ITEM_HEADERS = ['id', 'name', 'rate', 'big', 'start_at', 'memo', 'deploy_key'];

    /**
     * @return array<string, mixed>
     */
    private function item(string $id, string $name, string $rate = '1.50', string $big = '9999999999'): array
    {
        return ['id' => $id, 'name' => $name, 'rate' => $rate, 'big' => $big, 'start_at' => '2026-01-02 03:04:05', 'memo' => '', 'deploy_key' => '2026112201'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{connection: ConnectionInterface, tables: array<string, array<string, int>>}
     */
    private function reflectItems(array $rows, bool $dryRun = false): array
    {
        return (new MasterImportService)->reflect(
            ['mst_reflect_item' => ['headers' => self::ITEM_HEADERS, 'rows' => $rows]],
            $dryRun,
        );
    }

    private function commit(): void
    {
        DB::connection('mst')->commit();
    }

    /**
     * 既存行の created_at・updated_at を過去へ固定して、書き換わったかを見分けられるようにする
     */
    private function ageTimestamps(string $table): void
    {
        DB::connection('mst')->table($table)->update(['created_at' => self::OLD, 'updated_at' => self::OLD]);
    }

    /**
     * @return array<string, object>
     */
    private function items(): array
    {
        return DB::connection('mst')->table('mst_reflect_item')->orderBy('id')->get()->keyBy('id')->all();
    }

    private function tableHash(string $table): string
    {
        return md5(json_encode(DB::connection('mst')->table($table)->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function 空の表に入れると全行がinsertされトランザクションは開いたまま返る(): void
    {
        $result = $this->reflectItems([$this->item('a', 'A'), $this->item('b', 'B')]);

        $this->assertSame(['inserted' => 2, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0], $result['tables']['mst_reflect_item']);
        $this->assertSame(1, $result['connection']->transactionLevel());
        // 同じ接続からは未コミットの行が見える
        $this->assertSame(2, $result['connection']->table('mst_reflect_item')->count());

        $result['connection']->rollBack();
        $this->assertSame(0, DB::connection('mst')->table('mst_reflect_item')->count());
    }

    #[Test]
    public function 同じシートを2回反映すると2回目は全行が変更なしでハッシュも時刻も変わらない(): void
    {
        $rows = [$this->item('a', 'A', '1.5'), $this->item('b', 'B', '2.00', '123')];
        $this->reflectItems($rows);
        $this->commit();
        $this->ageTimestamps('mst_reflect_item');
        $before = $this->tableHash('mst_reflect_item');

        $result = $this->reflectItems($rows);
        $this->commit();

        $this->assertSame(['inserted' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 2], $result['tables']['mst_reflect_item']);
        $this->assertSame($before, $this->tableHash('mst_reflect_item'));
        $this->assertSame(self::OLD, $this->items()['a']->updated_at);
    }

    #[Test]
    public function 数値の書式差やnullと空文字は変更とみなさない(): void
    {
        $this->reflectItems([$this->item('a', 'A', '1.50', '9999999999')]);
        $this->commit();
        $this->ageTimestamps('mst_reflect_item');

        // '1.5' と DECIMAL の '1.50'、'+9999999999' と 9999999999、memo の '' と NULL
        $row = $this->item('a', 'A', '1.5', '09999999999');
        $result = $this->reflectItems([$row]);
        $this->commit();

        $this->assertSame(1, $result['tables']['mst_reflect_item']['unchanged']);
        $this->assertSame(self::OLD, $this->items()['a']->updated_at);
    }

    #[Test]
    public function 数値型でない列は文字列の厳密比較で007と7や1_0と1は違う(): void
    {
        $this->reflectItems([$this->item('a', '7'), $this->item('b', '1.0')]);
        $this->commit();
        $this->ageTimestamps('mst_reflect_item');

        $result = $this->reflectItems([$this->item('a', '007'), $this->item('b', '1')]);
        $this->commit();

        $this->assertSame(['inserted' => 0, 'updated' => 2, 'deleted' => 0, 'unchanged' => 0], $result['tables']['mst_reflect_item']);
        $this->assertSame('007', $this->items()['a']->name);
        $this->assertSame('1', $this->items()['b']->name);
    }

    #[Test]
    public function 変更のある行だけが更新され他の行の時刻は変わらない(): void
    {
        $this->reflectItems([$this->item('a', 'A'), $this->item('b', 'B'), $this->item('c', 'C')]);
        $this->commit();
        $this->ageTimestamps('mst_reflect_item');

        $result = $this->reflectItems([$this->item('a', 'A'), $this->item('b', 'B2', '3.25'), $this->item('d', 'D')]);
        $this->commit();

        $this->assertSame(['inserted' => 1, 'updated' => 1, 'deleted' => 1, 'unchanged' => 1], $result['tables']['mst_reflect_item']);
        $items = $this->items();
        $this->assertSame(['a', 'b', 'd'], array_keys($items));
        $this->assertSame(self::OLD, $items['a']->updated_at);
        $this->assertNotSame(self::OLD, $items['b']->updated_at);
        $this->assertSame('B2', $items['b']->name);
        $this->assertNotSame(self::OLD, $items['d']->created_at);
    }

    #[Test]
    public function 変更した行は主キー以外のシートに無い列が既定値へ戻る(): void
    {
        DB::connection('mst')->table('mst_reflect_item')->insert(['id' => 'a', 'name' => 'A', 'memo' => 'old memo']);

        $result = (new MasterImportService)->reflect(
            ['mst_reflect_item' => ['headers' => ['id', 'name'], 'rows' => [['id' => 'a', 'name' => 'A2']]]],
        );
        $this->commit();

        $this->assertSame(1, $result['tables']['mst_reflect_item']['updated']);
        $this->assertNull($this->items()['a']->memo);
    }

    #[Test]
    public function uniqueの入れ替えもdeleteを先に行うので衝突しない(): void
    {
        $this->reflectItems([$this->item('a', 'X'), $this->item('b', 'Y')]);
        $this->commit();

        $result = $this->reflectItems([$this->item('a', 'Y'), $this->item('b', 'X')]);
        $this->commit();

        $this->assertSame(2, $result['tables']['mst_reflect_item']['updated']);
        $this->assertSame('Y', $this->items()['a']->name);
    }

    #[Test]
    public function 主キーは大文字小文字を区別せず比べる(): void
    {
        $this->reflectItems([$this->item('Abc', 'A')]);
        $this->commit();

        $result = $this->reflectItems([$this->item('abc', 'A')]);
        $this->commit();

        // 同じ行とみなす（id の綴りの違いは別途の検証で拒否される）。id 列の文字列は文字列比較で違うので更新になる
        $this->assertSame(['inserted' => 0, 'updated' => 1, 'deleted' => 0, 'unchanged' => 0], $result['tables']['mst_reflect_item']);
        $this->assertSame(['abc'], array_keys($this->items()));
    }

    #[Test]
    public function l10nは複合主キーで差分反映する(): void
    {
        $headers = ['mst_reflect_item_id', 'language', 'label'];
        $service = new MasterImportService;
        $service->reflect(['mst_reflect_item__l10n' => ['headers' => $headers, 'rows' => [
            ['mst_reflect_item_id' => 'a', 'language' => 'ja', 'label' => 'あ'],
            ['mst_reflect_item_id' => 'a', 'language' => 'en', 'label' => 'A'],
            ['mst_reflect_item_id' => 'b', 'language' => 'ja', 'label' => 'び'],
        ]]]);
        $this->commit();

        $result = $service->reflect(['mst_reflect_item__l10n' => ['headers' => $headers, 'rows' => [
            ['mst_reflect_item_id' => 'a', 'language' => 'ja', 'label' => 'あ'],
            ['mst_reflect_item_id' => 'a', 'language' => 'en', 'label' => 'A2'],
        ]]]);
        $this->commit();

        $this->assertSame(['inserted' => 0, 'updated' => 1, 'deleted' => 1, 'unchanged' => 1], $result['tables']['mst_reflect_item__l10n']);
        $this->assertSame(2, DB::connection('mst')->table('mst_reflect_item__l10n')->count());
    }

    #[Test]
    public function dry_runは書かずに件数だけ返しトランザクションも開かない(): void
    {
        $this->reflectItems([$this->item('a', 'A'), $this->item('b', 'B')]);
        $this->commit();

        $result = $this->reflectItems([$this->item('a', 'A9'), $this->item('c', 'C')], true);

        $this->assertSame(['inserted' => 1, 'updated' => 1, 'deleted' => 1, 'unchanged' => 0], $result['tables']['mst_reflect_item']);
        $this->assertSame(0, $result['connection']->transactionLevel());
        $this->assertSame(['a', 'b'], array_keys($this->items()));
        $this->assertSame('A', $this->items()['a']->name);
    }

    #[Test]
    public function 行が0行の表とシートに無い表は触らない(): void
    {
        DB::connection('mst')->table('mst_reflect_untouched')->insert(['id' => 'x', 'name' => 'keep']);
        DB::connection('mst')->table('mst_reflect_item')->insert(['id' => 'a', 'name' => 'A']);

        $result = (new MasterImportService)->reflect([
            'mst_reflect_item' => ['headers' => self::ITEM_HEADERS, 'rows' => []],
        ]);
        $this->commit();

        $this->assertSame([], $result['tables']);
        $this->assertSame(1, DB::connection('mst')->table('mst_reflect_item')->count());
        $this->assertSame(1, DB::connection('mst')->table('mst_reflect_untouched')->count());
    }

    #[Test]
    public function 後の表の検証に失敗したら何も書かず既存の行が残る(): void
    {
        DB::connection('mst')->table('mst_reflect_item')->insert(['id' => 'a', 'name' => 'A']);

        try {
            (new MasterImportService)->reflect([
                'mst_reflect_item' => ['headers' => self::ITEM_HEADERS, 'rows' => [$this->item('z', 'Z')]],
                'mst_reflect_untouched' => ['headers' => ['name'], 'rows' => [['name' => 'n']]],
            ]);
            $this->fail('例外が出るはず');
        } catch (RuntimeException) {
            // 期待どおり
        }

        $this->assertSame(0, DB::connection('mst')->transactionLevel());
        $this->assertSame(['a'], array_keys($this->items()));
    }

    #[Test]
    public function 書き込みの途中で失敗したらロールバックされ前の表の変更も戻る(): void
    {
        DB::connection('mst')->table('mst_reflect_item')->insert(['id' => 'a', 'name' => 'A']);

        try {
            // 2 表目は name が UNIQUE の衝突で INSERT に失敗する
            (new MasterImportService)->reflect([
                'mst_reflect_untouched' => ['headers' => ['id', 'name'], 'rows' => [['id' => 'x', 'name' => 'n']]],
                'mst_reflect_item' => ['headers' => self::ITEM_HEADERS, 'rows' => [$this->item('b', 'dup'), $this->item('c', 'dup')]],
            ]);
            $this->fail('例外が出るはず');
        } catch (\Throwable) {
            // 期待どおり
        }

        $this->assertSame(0, DB::connection('mst')->transactionLevel());
        $this->assertSame(0, DB::connection('mst')->table('mst_reflect_untouched')->count());
        $this->assertSame(['a'], array_keys($this->items()));
    }

    #[Test]
    public function 主キー列がシートに無い表は中止しトランザクションも開かない(): void
    {
        try {
            (new MasterImportService)->reflect(
                ['mst_reflect_item__l10n' => ['headers' => ['label'], 'rows' => [['label' => 'x']]]],
            );
            $this->fail('例外が出るはず');
        } catch (RuntimeException) {
            $this->assertSame(0, DB::connection('mst')->transactionLevel());
        }
    }

    #[Test]
    public function シート内の主キー重複は書き込み前に中止する(): void
    {
        DB::connection('mst')->table('mst_reflect_item')->insert(['id' => 'a', 'name' => 'A']);

        try {
            (new MasterImportService)->reflect(['mst_reflect_item' => ['headers' => self::ITEM_HEADERS, 'rows' => [$this->item('x', 'X'), $this->item('X', 'Y')]]]);
            $this->fail('例外が出るはず');
        } catch (RuntimeException) {
            $this->assertSame(0, DB::connection('mst')->transactionLevel());
            $this->assertSame(['a'], array_keys($this->items()));
        }
    }

    #[Test]
    public function 行数が多くても分割して入る(): void
    {
        $rows = [];
        for ($i = 0; $i < 1203; $i++) {
            $rows[] = $this->item('id'.$i, 'n'.$i);
        }
        $result = $this->reflectItems($rows);
        $this->commit();

        $this->assertSame(1203, $result['tables']['mst_reflect_item']['inserted']);
        $this->assertSame(1203, DB::connection('mst')->table('mst_reflect_item')->count());

        $again = $this->reflectItems($rows);
        $this->commit();
        $this->assertSame(1203, $again['tables']['mst_reflect_item']['unchanged']);
    }

    #[Test]
    public function replaceがtrueの空の表は全行を削除し_replaceなしの空の表は触らない(): void
    {
        DB::connection('mst')->table('mst_reflect_item')->insert([['id' => 'a', 'name' => 'A'], ['id' => 'b', 'name' => 'B']]);
        DB::connection('mst')->table('mst_reflect_untouched')->insert([['id' => 'u', 'name' => 'U']]);

        $result = (new MasterImportService)->reflect([
            'mst_reflect_item' => ['headers' => [], 'rows' => [], 'replace' => true],
            'mst_reflect_untouched' => ['headers' => ['id', 'name'], 'rows' => []],
        ]);
        $this->commit();

        $this->assertSame(['inserted' => 0, 'updated' => 0, 'deleted' => 2, 'unchanged' => 0], $result['tables']['mst_reflect_item']);
        $this->assertArrayNotHasKey('mst_reflect_untouched', $result['tables']);
        $this->assertSame([], $this->items());
        $this->assertSame(1, DB::connection('mst')->table('mst_reflect_untouched')->count());
    }
}

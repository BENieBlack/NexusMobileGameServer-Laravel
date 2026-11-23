<?php

namespace Tests\Unit;

use App\Services\MasterOverlayFolder;
use PHPUnit\Framework\TestCase;

class MasterOverlayFolderTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function e(string $id, string $key, ?string $deleted = '', ?string $language = null, int $row = 3): array
    {
        return ['row_no' => $row, 'sheet' => 's/mst_a', 'id' => $id, 'language' => $language, 'deploy_key' => $key, 'deleted' => $deleted, 'columns' => []];
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, array{entries: array<int, array<string, mixed>>}>
     */
    private function tables(array $entries, string $name = 'mst_a'): array
    {
        return [$name => ['entries' => $entries]];
    }

    /**
     * @param  array<int, array<string, mixed>>  $adopted
     * @return list<string>
     */
    private function keys(array $adopted): array
    {
        return array_map(fn ($e) => $e['id'].'@'.$e['deploy_key'], $adopted);
    }

    public function test_最大のdeploy_keyの行を採る_行の順序に依存しない(): void
    {
        $entries = [$this->e('a', '2026020101'), $this->e('a', '2026010101'), $this->e('b', '2026010101')];
        $r = MasterOverlayFolder::fold($this->tables($entries), 9999999999);
        $this->assertSame(['a@2026020101', 'b@2026010101'], $this->keys($r['mst_a']['adopted']));
        $this->assertSame([0, 2], $r['mst_a']['adopted_index_list']);
        $this->assertTrue($r['mst_a']['replaced']);
        $this->assertSame(3, $r['mst_a']['input_rows']);
    }

    public function test_tより大きい行は採らない_tちょうどは採る(): void
    {
        $entries = [$this->e('a', '2026010101'), $this->e('a', '2026010102'), $this->e('a', '2026010103')];
        $r = MasterOverlayFolder::fold($this->tables($entries), 2026010102);
        $this->assertSame(['a@2026010102'], $this->keys($r['mst_a']['adopted']));
    }

    public function test_予約キーの行は本物の_tでは畳みに入らない(): void
    {
        $entries = [$this->e('a', '2026010101'), $this->e('a', '9999999999')];
        $this->assertSame(['a@2026010101'], $this->keys(MasterOverlayFolder::fold($this->tables($entries), 2026010101)['mst_a']['adopted']));
        $this->assertSame(['a@9999999999'], $this->keys(MasterOverlayFolder::fold($this->tables($entries), 9999999999)['mst_a']['adopted']));
    }

    public function test_削除印が最大ならその鍵は出さない_より大きい通常行で復活する(): void
    {
        $entries = [$this->e('a', '2026010101'), $this->e('a', '2026020101', 'TRUE'), $this->e('b', '2026010101')];
        $this->assertSame(['b@2026010101'], $this->keys(MasterOverlayFolder::fold($this->tables($entries), 9999999999)['mst_a']['adopted']));

        $entries[] = $this->e('a', '2026030101');
        $this->assertSame(['b@2026010101', 'a@2026030101'], $this->keys(MasterOverlayFolder::fold($this->tables($entries), 9999999999)['mst_a']['adopted']));
    }

    public function test_削除印の対象が無くてもよい_全部削除印なら採用0行で置き換え対象のまま(): void
    {
        $r = MasterOverlayFolder::fold($this->tables([$this->e('x', '2026010101', '1')]), 9999999999);
        $this->assertSame([], $r['mst_a']['adopted']);
        $this->assertTrue($r['mst_a']['replaced']);
    }

    public function test_行が無い表は置き換え対象でない(): void
    {
        $r = MasterOverlayFolder::fold($this->tables([]), 9999999999);
        $this->assertFalse($r['mst_a']['replaced']);
    }

    public function test_鍵は大文字小文字を区別しない(): void
    {
        $entries = [$this->e('UR:1', '2026010101'), $this->e('ur:1', '2026020101')];
        $this->assertSame(['ur:1@2026020101'], $this->keys(MasterOverlayFolder::fold($this->tables($entries), 9999999999)['mst_a']['adopted']));
    }

    public function test_deploy_keyが数字でない行は候補にしない(): void
    {
        $entries = [$this->e('a', '2026010101'), $this->e('a', 'abc'), $this->e('b', '')];
        $this->assertSame(['a@2026010101'], $this->keys(MasterOverlayFolder::fold($this->tables($entries), 9999999999)['mst_a']['adopted']));
    }

    public function test_同点は入力順で先の行を採る(): void
    {
        $entries = [$this->e('a', '2026010101', '', null, 3), $this->e('a', '2026010101', '', null, 4)];
        $this->assertSame([0], MasterOverlayFolder::fold($this->tables($entries), 9999999999)['mst_a']['adopted_index_list']);
    }

    public function test_l10nは言語ごとに畳み_親が消えたら消える(): void
    {
        $tables = [
            'mst_a' => ['entries' => [$this->e('p1', '2026010101'), $this->e('p2', '2026010101'), $this->e('p2', '2026020101', 'TRUE')]],
            'mst_a__l10n' => ['entries' => [
                $this->e('p1', '2026010101', '', 'ja'),
                $this->e('p1', '2026020101', '', 'ja'),
                $this->e('p1', '2026010101', '', 'en'),
                $this->e('p2', '2026010101', '', 'ja'),
                $this->e('p3', '2026010101', '', 'ja'),
            ]],
        ];
        $r = MasterOverlayFolder::fold($tables, 9999999999);
        $this->assertSame(['p1@2026020101', 'p1@2026010101'], $this->keys($r['mst_a__l10n']['adopted']));
        $this->assertSame(['ja', 'en'], array_column($r['mst_a__l10n']['adopted'], 'language'));
    }

    public function test_親が置き換え対象でないときはl10nの親の存在を見ない(): void
    {
        $tables = [
            'mst_a' => ['entries' => []],
            'mst_a__l10n' => ['entries' => [$this->e('p9', '2026010101', '', 'ja')]],
        ];
        $this->assertSame(['p9@2026010101'], $this->keys(MasterOverlayFolder::fold($tables, 9999999999)['mst_a__l10n']['adopted']));
        $this->assertSame(['p9@2026010101'], $this->keys(MasterOverlayFolder::fold(['mst_a__l10n' => $tables['mst_a__l10n']], 9999999999)['mst_a__l10n']['adopted']));
    }

    public function test_削除印の値(): void
    {
        foreach (['1', 'true', 'TRUE', 'True'] as $v) {
            $this->assertTrue(MasterOverlayFolder::isDeleted($v));
        }
        foreach (['', '0', 'false', null, 'yes'] as $v) {
            $this->assertFalse(MasterOverlayFolder::isDeleted($v));
        }
    }
}

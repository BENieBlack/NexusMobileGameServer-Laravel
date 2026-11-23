<?php

namespace Tests\Unit;

use App\Services\MasterDeployInventory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * dry_run の 9 桁の棚卸し（db.md 9.3）。DB を使わない
 */
class MasterDeployInventoryTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function e(string $id, ?string $key, int $row = 3, ?string $language = null): array
    {
        return ['row_no' => $row, 'sheet' => 's/mst_a', 'id' => $id, 'language' => $language, 'deploy_key' => $key, 'deleted' => '', 'columns' => []];
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function build(array $entries): array
    {
        return MasterDeployInventory::build(['mst_a' => ['entries' => $entries]], 7000000000);
    }

    #[Test]
    public function 桁数の分布を表ごとに数える(): void
    {
        $r = $this->build([
            $this->e('a', '202601010'), $this->e('b', '2026010101'), $this->e('c', '7777777777'),
            $this->e('d', 'abc'), $this->e('e', ''), $this->e('f', null), $this->e('g', '12345678901'),
        ]);

        $this->assertSame(['nine_digit' => 1, 'ten_digit_real' => 1, 'ten_digit_reserved' => 1, 'other' => 2, 'empty' => 2], $r['digit_distribution']['mst_a']);
    }

    #[Test]
    public function 九桁の行を旧予約キー_n非ゼロ_n0に分け場所を出す(): void
    {
        $r = $this->build([$this->e('a', '777777777', 3), $this->e('b', '202601021', 4), $this->e('c', '202601010', 5)]);

        $this->assertSame(['legacy_reserved' => 1, 'n_nonzero' => 1, 'n_zero' => 1], $r['legacy_count_by_category']);
        $this->assertSame(
            [['table' => 'mst_a', 'sheet' => 's/mst_a', 'row' => 5, 'deploy_key' => '202601010', 'category' => 'n_zero']],
            array_values(array_filter($r['legacy_row_list'], fn ($row) => $row['category'] === 'n_zero')),
        );
    }

    #[Test]
    public function 変換すると同じ鍵に同じdeploy_keyの行ができる組を出す(): void
    {
        // 202601010 -> 2026010101 が、同じ id の既存の 10 桁行と衝突する（9 桁が先でも 10 桁が先でも拾う）
        $r = $this->build([$this->e('a', '202601010', 3), $this->e('a', '2026010101', 4), $this->e('b', '2026010101', 5), $this->e('b', '202601010', 6), $this->e('c', '202601010', 7)]);

        $this->assertSame(['a', 'b'], array_column($r['conversion_conflict_list'], 'id'));
        $this->assertSame('2026010101', $r['conversion_conflict_list'][0]['converted_deploy_key']);
    }

    #[Test]
    public function 変換で畳みの勝者が変わる鍵を数える(): void
    {
        $r = $this->build([
            // a: 変換前は 10 桁の 2026010101 が勝ち（9 桁 202602220 は数値が小さい）、変換後は 2026022201 が勝つ → 変わる
            $this->e('a', '202602220', 3), $this->e('a', '2026010101', 4),
            // b: 変換前も後も 10 桁が勝つ → 変わらない
            $this->e('b', '202601010', 5), $this->e('b', '2026020101', 6),
            // c: 9 桁だけ → 対象外
            $this->e('c', '202601010', 7),
        ]);

        $this->assertSame(1, $r['order_change_key_count']);
    }
}

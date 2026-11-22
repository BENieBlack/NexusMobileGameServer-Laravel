<?php

namespace Tests\Feature\Models\Mst;

use App\Models\Mst\MstEquipmentLevel;
use App\Models\Mst\MstPlayerLevel;
use App\Models\Mst\MstUnitLevel;
use App\Repositories\Mst\MstEquipmentLevelRepository;
use App\Repositories\Mst\MstPlayerLevelRepository;
use App\Repositories\Mst\MstUnitLevelRepository;
use Database\Seeders\MstPlayerLevelSeeder;
use Database\Seeders\MstUnitLevelSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\RefreshMultipleDatabases;
use Tests\TestCase;

/**
 * mst_unit_level / mst_equipment_level / mst_player_level の string id のテスト
 *
 * id の生成規則・自然キーの unique・id での検索・level での検索が id 導入後も同じ結果を返すこと
 */
class MstLevelIdTest extends TestCase
{
    use RefreshMultipleDatabases;

    private const RARITIES = ['UR', 'SSR', 'SR', 'R', 'UC', 'C'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();

        parent::tearDown();
    }

    #[Test]
    public function idの生成規則はレアリティとレベルをコロンでつなぐ(): void
    {
        $this->assertSame('UR:1', MstUnitLevel::makeId('UR', 1));
        $this->assertSame('SSR:100', MstEquipmentLevel::makeId('SSR', 100));
        $this->assertSame('1', MstPlayerLevel::makeId(1));
        $this->assertSame('100', MstPlayerLevel::makeId(100));
    }

    #[Test]
    public function レアリティとレベルの全組み合わせでidが衝突しない(): void
    {
        $ids = [];
        foreach (self::RARITIES as $rarity) {
            for ($level = 1; $level <= 100; $level++) {
                $ids[] = MstUnitLevel::makeId($rarity, $level);
            }
        }

        $this->assertCount(600, array_unique($ids));
        // 照合順序は大文字小文字を区別しない。小文字にしても衝突しない
        $this->assertCount(600, array_unique(array_map('strtolower', $ids)));
        $this->assertLessThanOrEqual(255, max(array_map('strlen', $ids)));
    }

    #[Test]
    public function プレイヤーレベルのシーダーはidをレベルの文字列で入れる(): void
    {
        $this->seed(MstPlayerLevelSeeder::class);

        $rows = DB::connection('mst')->table('mst_player_level')->orderBy('level')->get();
        $this->assertCount(100, $rows);
        foreach ($rows as $row) {
            $this->assertSame((string) $row->level, $row->id);
        }
    }

    #[Test]
    public function ユニットレベルのシーダーはidをレアリティとレベルで入れる(): void
    {
        $this->seed(MstUnitLevelSeeder::class);

        $rows = DB::connection('mst')->table('mst_unit_level')->get();
        $this->assertCount(600, $rows);
        foreach ($rows as $row) {
            $this->assertSame($row->rarity.':'.$row->level, $row->id);
        }
        $this->assertCount(600, $rows->pluck('id')->unique());
    }

    #[Test]
    public function idの重複は主キーで拒否される(): void
    {
        $this->insertPlayerLevel('1', 1);

        $this->expectException(QueryException::class);
        $this->insertPlayerLevel('1', 2);
    }

    #[Test]
    public function プレイヤーレベルのlevelの重複はuniqueで拒否される(): void
    {
        $this->insertPlayerLevel('1', 1);

        $this->expectException(QueryException::class);
        $this->insertPlayerLevel('one', 1);
    }

    #[Test]
    public function ユニットレベルの自然キーの重複はuniqueで拒否される(): void
    {
        $this->insertLevelRow('mst_unit_level', 'UR:1', 'UR', 1);

        $this->expectException(QueryException::class);
        $this->insertLevelRow('mst_unit_level', 'other', 'UR', 1);
    }

    #[Test]
    public function 装備レベルの自然キーの重複はuniqueで拒否される(): void
    {
        $this->insertLevelRow('mst_equipment_level', 'SR:2', 'SR', 2);

        $this->expectException(QueryException::class);
        $this->insertLevelRow('mst_equipment_level', 'other', 'SR', 2);
    }

    #[Test]
    public function 同じレベルでもレアリティが違えば入る(): void
    {
        $this->insertLevelRow('mst_unit_level', 'UR:1', 'UR', 1);
        $this->insertLevelRow('mst_unit_level', 'C:1', 'C', 1);

        $this->assertSame(2, DB::connection('mst')->table('mst_unit_level')->count());
    }

    #[Test]
    public function プレイヤーレベルの_repositoryはidでもlevelでも引ける(): void
    {
        $this->insertPlayerLevel('1', 1, 0, 50);
        $this->insertPlayerLevel('2', 2, 100, 60);
        $this->insertPlayerLevel('3', 3, 250, 70);
        $this->refreshMstCache();

        $repository = app(MstPlayerLevelRepository::class);

        $this->assertSame(3, $repository->selectByLevel(3)?->getLevel());
        $this->assertSame(250, $repository->findRequiredExpForLevel(3));
        $this->assertSame(60, $repository->findMaxStaminaForLevel(2));
        $this->assertNull($repository->selectByLevel(99));
        $this->assertSame(['1', '2', '3'], array_map(fn ($m) => $m->getAttribute('id'), $repository->selectAll()->all()));
        $this->assertSame(2, $repository->selectById('2')?->getLevel());
        $this->assertSame(3, $repository->selectMaxLevel());
        $this->assertSame(2, $repository->calculateLevelFromExp(100));
    }

    #[Test]
    public function プレイヤーレベルはidが規則から外れていてもlevelで引ける(): void
    {
        // selectByLevel は鍵（id）に依存しない
        $this->insertPlayerLevel('level_7', 7, 900, 80);
        $this->refreshMstCache();

        $this->assertSame(900, app(MstPlayerLevelRepository::class)->findRequiredExpForLevel(7));
        $this->assertSame(900, MstPlayerLevel::findRequiredExpForLevel(7));
    }

    #[Test]
    public function ユニットと装備のレベルの_repositoryはレアリティとレベルで引ける(): void
    {
        $this->insertLevelRow('mst_unit_level', 'UR:1', 'UR', 1, 0);
        $this->insertLevelRow('mst_unit_level', 'UR:2', 'UR', 2, 500);
        $this->insertLevelRow('mst_unit_level', 'C:2', 'C', 2, 100);
        $this->insertLevelRow('mst_equipment_level', 'SR:1', 'SR', 1, 0);
        $this->insertLevelRow('mst_equipment_level', 'SR:2', 'SR', 2, 300);
        $this->refreshMstCache();

        $unit = app(MstUnitLevelRepository::class);
        $this->assertSame(500, $unit->findRequiredExpForLevel('UR', 2));
        $this->assertSame(100, $unit->findRequiredExpForLevel('C', 2));
        $this->assertSame(2, $unit->selectMaxLevel('UR'));
        $this->assertNull($unit->selectByRarityAndLevel('SR', 1));
        $this->assertSame('UR:2', $unit->selectById('UR:2')?->getAttribute('id'));

        $equipment = app(MstEquipmentLevelRepository::class);
        $this->assertSame(300, $equipment->findRequiredExpForLevel('SR', 2));
        $this->assertSame('SR:1', $equipment->selectById('SR:1')?->getAttribute('id'));
        $this->assertSame(300, MstEquipmentLevel::findByRarityAndLevel('SR', 2)?->getRequiredExp());
    }

    private function insertPlayerLevel(string $id, int $level, int $requiredExp = 0, int $maxStamina = 50): void
    {
        DB::connection('mst')->table('mst_player_level')->insert([
            'id' => $id,
            'level' => $level,
            'required_exp' => $requiredExp,
            'max_stamina' => $maxStamina,
        ]);
    }

    private function insertLevelRow(string $table, string $id, string $rarity, int $level, int $requiredExp = 0): void
    {
        DB::connection('mst')->table($table)->insert([
            'id' => $id,
            'rarity' => $rarity,
            'level' => $level,
            'required_exp' => $requiredExp,
        ]);
    }

    private function cleanUp(): void
    {
        DB::connection('mst')->table('mst_player_level')->delete();
        DB::connection('mst')->table('mst_unit_level')->delete();
        DB::connection('mst')->table('mst_equipment_level')->delete();
        $this->refreshMstCache();
    }
}

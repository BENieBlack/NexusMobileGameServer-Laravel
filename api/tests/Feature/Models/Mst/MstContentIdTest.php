<?php

namespace Tests\Feature\Models\Mst;

use App\Models\Mst\MstEventContent;
use App\Models\Mst\MstMailboxContent;
use App\Models\Mst\MstMissionContent;
use App\Models\Mst\MstTagContent;
use App\Models\Mst\MstTradeProductContent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\RefreshMultipleDatabases;
use Tests\TestCase;

/**
 * *_content 系 6 表（event / mailbox / mission / reward_track / tag / trade_product）の string id のテスト
 *
 * id の生成規則・id の主キー・自然キーの unique（報酬トラックは unique を置かず id の主キーが担う）・Model が id で引けることを確認する
 */
class MstContentIdTest extends TestCase
{
    use RefreshMultipleDatabases;

    private const PARENT = 'content_id_test_parent';

    private const REWARD_TRACK_TABLE = 'mst_reward_track_content';

    /**
     * @return array<string, array{string, string, class-string}>
     */
    public static function contentModels(): array
    {
        return [
            'event' => ['mst_event_content', 'mst_event_id', MstEventContent::class],
            'mailbox' => ['mst_mailbox_content', 'mst_mailbox_id', MstMailboxContent::class],
            'mission' => ['mst_mission_content', 'mst_mission_id', MstMissionContent::class],
            'tag' => ['mst_tag_content', 'mst_tag_id', MstTagContent::class],
            'trade_product' => ['mst_trade_product_content', 'mst_trade_product_id', MstTradeProductContent::class],
        ];
    }

    /**
     * Model を使わないテスト用（表名と親 ID の列だけ）
     *
     * @return array<string, array{string, string}>
     */
    public static function contentTables(): array
    {
        return array_map(fn (array $case) => [$case[0], $case[1]], self::contentModels());
    }

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
    public function idの生成規則は親idとcontent_typeとcontent_mst_idをコロンでつなぐ(): void
    {
        $this->assertSame('event_1:gacha:gacha_001', MstEventContent::makeId('event_1', 'gacha', 'gacha_001'));
        $this->assertSame('mail_1:item:item_001', MstMailboxContent::makeId('mail_1', 'item', 'item_001'));
        $this->assertSame('mission_1:item:item_001', MstMissionContent::makeId('mission_1', 'item', 'item_001'));
        $this->assertSame('tag_1:unit:unit_001', MstTagContent::makeId('tag_1', 'unit', 'unit_001'));
        $this->assertSame('product_1:item:free_diamond', MstTradeProductContent::makeId('product_1', 'item', 'free_diamond'));
    }

    /**
     * @param  class-string  $model
     */
    #[Test]
    #[DataProvider('contentModels')]
    public function modelはidで引ける(string $table, string $parentColumn, string $model): void
    {
        $id = $this->insertRow($table, $parentColumn, 'item', 'item_a');
        $this->insertRow($table, $parentColumn, 'item', 'item_b');

        $this->assertSame(2, DB::connection('mst')->table($table)->where($parentColumn, self::PARENT)->count());
        $found = $model::query()->find($id);
        $this->assertNotNull($found);
        $this->assertSame($id, $found->getAttribute('id'));
        $this->assertSame('item_a', $found->getContentMstId());
        $this->assertSame('string', (new $model)->getKeyType());
        $this->assertFalse((new $model)->getIncrementing());
    }

    #[Test]
    #[DataProvider('contentTables')]
    public function idの重複は主キーで拒否される(string $table, string $parentColumn): void
    {
        $this->insertRow($table, $parentColumn, 'item', 'item_a');

        $this->expectException(QueryException::class);
        // 自然キーは違うが id が同じ
        $this->insertRow($table, $parentColumn, 'item', 'item_b', self::PARENT.':item:item_a');
    }

    #[Test]
    #[DataProvider('contentTables')]
    public function 自然キーの重複はuniqueで拒否される(string $table, string $parentColumn): void
    {
        $this->insertRow($table, $parentColumn, 'item', 'item_a');

        $this->expectException(QueryException::class);
        // id は違うが自然キーが同じ
        $this->insertRow($table, $parentColumn, 'item', 'item_a', 'other_id');
    }

    #[Test]
    #[DataProvider('contentTables')]
    public function 同じ親でも中身が違えば入る(string $table, string $parentColumn): void
    {
        $this->insertRow($table, $parentColumn, 'item', 'item_a');
        $this->insertRow($table, $parentColumn, 'unit', 'item_a');

        $this->assertSame(2, DB::connection('mst')->table($table)->where($parentColumn, self::PARENT)->count());
    }

    #[Test]
    public function 報酬トラックの中身はidを4列でつなぐ(): void
    {
        $id = implode(':', [self::PARENT, 'line_free', 'gold', 'gold']);
        $this->insertRewardTrackRow($id, 'line_free');
        $this->insertRewardTrackRow(implode(':', [self::PARENT, 'line_paid', 'gold', 'gold']), 'line_paid');

        $this->assertSame(2, DB::connection('mst')->table(self::REWARD_TRACK_TABLE)->where('mst_reward_track_milestone_id', self::PARENT)->count());
        $this->assertSame('line_free', DB::connection('mst')->table(self::REWARD_TRACK_TABLE)->where('id', $id)->value('mst_reward_track_line_id'));
    }

    #[Test]
    public function 報酬トラックのidの重複は主キーで拒否される(): void
    {
        $this->insertRewardTrackRow('dup', 'line_free');

        $this->expectException(QueryException::class);
        $this->insertRewardTrackRow('dup', 'line_paid');
    }

    /**
     * 報酬トラックの 4 列 unique は、索引キー長の上限（3072 バイト）を超えるので置かない（string が 255 文字のため）。
     * 一意性は 4 列をつないだ id の主キーが担う。id が構成列から作られること（上のテスト）・id の PK の重複拒否と、
     * 取り込みが ':' を含む親 ID・content_type と、4 列が同じで id が違う行を拒否すること（tool の MasterImportServiceTest）で、id と自然キーの一致を守る。
     */
    #[Test]
    public function 報酬トラックの4列の索引は置かない(): void
    {
        $indexes = DB::connection('mst')->select(
            'SELECT DISTINCT INDEX_NAME AS name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [self::REWARD_TRACK_TABLE]
        );
        $names = array_map(static fn ($row) => $row->name, $indexes);

        $this->assertNotContains('uk_reward_track_content', $names);
        $this->assertContains('idx_milestone_line', $names);
    }

    #[Test]
    public function idは255文字ちょうどなら入り256文字は拒否される(): void
    {
        $this->insertRewardTrackRow(str_repeat('a', 255), 'line_free');
        $this->assertSame(1, DB::connection('mst')->table(self::REWARD_TRACK_TABLE)->where('id', str_repeat('a', 255))->count());

        $this->expectException(QueryException::class);
        $this->insertRewardTrackRow(str_repeat('b', 256), 'line_paid');
    }

    private function insertRow(string $table, string $parentColumn, string $contentType, string $contentMstId, ?string $id = null): string
    {
        $id ??= self::PARENT.':'.$contentType.':'.$contentMstId;
        DB::connection('mst')->table($table)->insert([
            'id' => $id,
            $parentColumn => self::PARENT,
            'content_type' => $contentType,
            'content_mst_id' => $contentMstId,
        ]);

        return $id;
    }

    private function insertRewardTrackRow(string $id, string $lineId): void
    {
        DB::connection('mst')->table(self::REWARD_TRACK_TABLE)->insert([
            'id' => $id,
            'mst_reward_track_milestone_id' => self::PARENT,
            'mst_reward_track_line_id' => $lineId,
            'content_type' => 'gold',
            'content_mst_id' => 'gold',
        ]);
    }

    private function cleanUp(): void
    {
        foreach (self::contentModels() as [$table, $parentColumn]) {
            DB::connection('mst')->table($table)->where($parentColumn, self::PARENT)->delete();
        }
        DB::connection('mst')->table(self::REWARD_TRACK_TABLE)->where('mst_reward_track_milestone_id', self::PARENT)->delete();
        $this->refreshMstCache();
    }
}

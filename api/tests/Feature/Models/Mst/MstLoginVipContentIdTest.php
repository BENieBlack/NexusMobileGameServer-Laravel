<?php

namespace Tests\Feature\Models\Mst;

use App\Models\Mst\MstLoginBonusContent;
use App\Models\Mst\MstVipLoginBonusContent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use NexusVip\Models\MstVipLevelReward;
use PHPUnit\Framework\Attributes\Test;
use Tests\RefreshMultipleDatabases;
use Tests\TestCase;

/**
 * mst_login_bonus_content・mst_vip_login_bonus_content・mst_vip_level_reward の string id のテスト
 *
 * id の生成規則・id の主キー・自然キーの unique・Model が id で引けることを確認する
 */
class MstLoginVipContentIdTest extends TestCase
{
    use RefreshMultipleDatabases;

    private const PARENT = 'login_vip_id_test_parent';

    /** mst_vip_level_reward は親テーブルが無いので、実在しない大きいレベルを使う */
    private const VIP_LEVEL = 9999;

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
    public function idの生成規則はコロンでつなぐ(): void
    {
        $this->assertSame('daily_login:1:item:item_potion', MstLoginBonusContent::makeId('daily_login', 1, 'item', 'item_potion'));
        $this->assertSame('vip_login_lv5:4:item:item_exp_002', MstVipLoginBonusContent::makeId('vip_login_lv5', 4, 'item', 'item_exp_002'));
        $this->assertSame('3:item:free_diamond', MstVipLevelReward::makeId(3, 'item', 'free_diamond'));
    }

    #[Test]
    public function modelはidで引ける(): void
    {
        $this->insertLogin('item_a');
        $this->insertVipLogin('item_a');
        $this->insertVipReward('item_a');

        $login = MstLoginBonusContent::query()->find(MstLoginBonusContent::makeId(self::PARENT, 1, 'item', 'item_a'));
        $this->assertNotNull($login);
        $this->assertSame('item_a', $login->getContentMstId());

        $vipLogin = MstVipLoginBonusContent::query()->find(MstVipLoginBonusContent::makeId(self::PARENT, 1, 'item', 'item_a'));
        $this->assertNotNull($vipLogin);
        $this->assertSame('item_a', $vipLogin->getAttribute('content_mst_id'));

        $reward = MstVipLevelReward::query()->find(MstVipLevelReward::makeId(self::VIP_LEVEL, 'item', 'item_a'));
        $this->assertNotNull($reward);
        $this->assertSame('item_a', $reward->getContentMstId());

        foreach ([new MstLoginBonusContent, new MstVipLoginBonusContent, new MstVipLevelReward] as $model) {
            $this->assertSame('string', $model->getKeyType());
            $this->assertFalse($model->getIncrementing());
        }
    }

    #[Test]
    public function idの重複は主キーで拒否される(): void
    {
        $this->insertLogin('item_a');

        $this->expectException(QueryException::class);
        // 自然キーは違うが id が同じ
        $this->insertLogin('item_b', MstLoginBonusContent::makeId(self::PARENT, 1, 'item', 'item_a'));
    }

    #[Test]
    public function ログインボーナスの自然キーの重複はuniqueで拒否される(): void
    {
        $this->insertLogin('item_a');

        $this->expectException(QueryException::class);
        $this->insertLogin('item_a', 'other_id');
    }

    #[Test]
    public function vipログインボーナスの自然キーの重複はuniqueで拒否される(): void
    {
        $this->insertVipLogin('item_a');

        $this->expectException(QueryException::class);
        // 以前は unique が無く同じ中身を 2 行置けた
        $this->insertVipLogin('item_a', 'other_id');
    }

    #[Test]
    public function vipログインボーナスのidの重複は主キーで拒否される(): void
    {
        $this->insertVipLogin('item_a');

        $this->expectException(QueryException::class);
        $this->insertVipLogin('item_b', MstVipLoginBonusContent::makeId(self::PARENT, 1, 'item', 'item_a'));
    }

    #[Test]
    public function vipレベル報酬の自然キーの重複はuniqueで拒否される(): void
    {
        $this->insertVipReward('item_a');

        $this->expectException(QueryException::class);
        $this->insertVipReward('item_a', 'other_id');
    }

    #[Test]
    public function 同じ親でも日や中身が違えば入る(): void
    {
        $this->insertLogin('item_a');
        $this->insertLogin('item_b');
        $this->insertVipLogin('item_a');
        $this->insertVipLogin('item_b');
        $this->insertVipReward('item_a');
        $this->insertVipReward('item_b');

        $this->assertSame(2, DB::connection('mst')->table('mst_login_bonus_content')->where('mst_login_bonus_id', self::PARENT)->count());
        $this->assertSame(2, DB::connection('mst')->table('mst_vip_login_bonus_content')->where('mst_vip_login_bonus_id', self::PARENT)->count());
        $this->assertSame(2, DB::connection('mst')->table('mst_vip_level_reward')->where('vip_level', self::VIP_LEVEL)->count());
    }

    private function insertLogin(string $contentMstId, ?string $id = null): void
    {
        DB::connection('mst')->table('mst_login_bonus_content')->insert([
            'id' => $id ?? MstLoginBonusContent::makeId(self::PARENT, 1, 'item', $contentMstId),
            'mst_login_bonus_id' => self::PARENT,
            'day' => 1,
            'content_type' => 'item',
            'content_mst_id' => $contentMstId,
        ]);
    }

    private function insertVipLogin(string $contentMstId, ?string $id = null): void
    {
        DB::connection('mst')->table('mst_vip_login_bonus_content')->insert([
            'id' => $id ?? MstVipLoginBonusContent::makeId(self::PARENT, 1, 'item', $contentMstId),
            'mst_vip_login_bonus_id' => self::PARENT,
            'day' => 1,
            'content_type' => 'item',
            'content_mst_id' => $contentMstId,
        ]);
    }

    private function insertVipReward(string $contentMstId, ?string $id = null): void
    {
        DB::connection('mst')->table('mst_vip_level_reward')->insert([
            'id' => $id ?? MstVipLevelReward::makeId(self::VIP_LEVEL, 'item', $contentMstId),
            'vip_level' => self::VIP_LEVEL,
            'content_type' => 'item',
            'content_mst_id' => $contentMstId,
        ]);
    }

    private function cleanUp(): void
    {
        DB::connection('mst')->table('mst_login_bonus_content')->where('mst_login_bonus_id', self::PARENT)->delete();
        DB::connection('mst')->table('mst_vip_login_bonus_content')->where('mst_vip_login_bonus_id', self::PARENT)->delete();
        DB::connection('mst')->table('mst_vip_level_reward')->where('vip_level', self::VIP_LEVEL)->delete();
        $this->refreshMstCache();
    }
}

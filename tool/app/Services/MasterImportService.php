<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * MasterImportService
 *
 * 版ごとの mst DB（db.md 6.6）へ行を投入するときの、行の変換と ID 規則の定義を持つ部品。DB への書き込みはしない。
 * 旧 import()（TRUNCATE + INSERT）と reflect()（基準の mst への差分反映）は削除した。
 *
 * - convertValue(): NULL 可の列だけ空文字を NULL にし、rarity 系の列は型に合わせて検証・変換する（MasterVersionDatabase が使う）
 * - rarityColumnsOf(): 表の rarity・bonus_rarity 列の型（ENUM / 整数）を実際の定義から判定する（MasterBuildRunner が検証 V10 のために使う）
 * - ID_RULES・UNIQUE_COLUMN_GROUPS・UNIQUE_NUMERIC_COLUMNS・MAX_ID_LENGTH・RARITY_MAP は MasterSheetValidator がリフレクションで読む（定義の正本。ID_RULES など 4 つは PHPStan の未使用定数の検査を避けるため public。RARITY_MAP は private のまま）。
 *   ID の規則は api の Model の makeId と同じ。tool は api の Model を読み込めないため、変えるときは両方を同時に直す
 */
class MasterImportService
{
    /**
     * id 列の最大長（実 DB の string は Schema::defaultStringLength(255) で varchar(255)）
     */
    public const MAX_ID_LENGTH = 255;

    /**
     * id が自然キーの規則どおりかを検証するテーブルと、id を作る列（':' でつなぐ）
     *
     * api の Model の makeId（MstUnitLevel・MstEquipmentLevel・MstPlayerLevel と、
     * MstEventContent・MstMailboxContent・MstMissionContent・MstTagContent・MstTradeProductContent・
     * MstLoginBonusContent・MstVipLoginBonusContent・NexusVip の MstVipLevelReward、
     * MstBillingPlatformProduct・MstInAppPurchaseContent・MstInAppPurchaseEffect）と同じ規則を持つ。
     * mst_in_app_purchase は自然キーが無く（人が決める安定した値。例 iap_diamond_100）、規則の対象外（id の空・重複・255 文字超だけ検証する）。
     * mst_billing_platform_product は billing_platform が先頭固定なので、platform_product_id が ':' を含んでも曖昧にならない（設計 3.1）。
     * mst_reward_track_content は api に Model が無く（アダプターが DB::table で読む）、この規則が唯一の定義になる。
     * tool は api の Model を読み込めず、api の docker コンテナも tool のコードを読めないため、
     * 突き合わせるテストが書けない。規則を変えるときは必ず両方を同時に直す。
     *
     * 列が 3 つ以上の規則は、最後の列（content_mst_id）以外が ':' を含んではならない（MasterSheetValidator の V11）
     */
    public const ID_RULES = [
        'mst_unit_level' => ['rarity', 'level'],
        'mst_equipment_level' => ['rarity', 'level'],
        'mst_player_level' => ['level'],
        'mst_event_content' => ['mst_event_id', 'content_type', 'content_mst_id'],
        'mst_mailbox_content' => ['mst_mailbox_id', 'content_type', 'content_mst_id'],
        'mst_mission_content' => ['mst_mission_id', 'content_type', 'content_mst_id'],
        'mst_reward_track_content' => ['mst_reward_track_milestone_id', 'mst_reward_track_line_id', 'content_type', 'content_mst_id'],
        'mst_tag_content' => ['mst_tag_id', 'content_type', 'content_mst_id'],
        'mst_trade_product_content' => ['mst_trade_product_id', 'content_type', 'content_mst_id'],
        'mst_login_bonus_content' => ['mst_login_bonus_id', 'day', 'content_type', 'content_mst_id'],
        'mst_vip_login_bonus_content' => ['mst_vip_login_bonus_id', 'day', 'content_type', 'content_mst_id'],
        'mst_vip_level_reward' => ['vip_level', 'content_type', 'content_mst_id'],
        'mst_billing_platform_product' => ['billing_platform', 'platform_product_id'],
        'mst_in_app_purchase_content' => ['mst_in_app_purchase_id', 'content_type', 'content_mst_id'],
        'mst_in_app_purchase_effect' => ['mst_in_app_purchase_id', 'effect_type'],
    ];

    /**
     * 同じ組み合わせで値が重なってはならない列（取り込みで拒否する）
     *
     * mst_vip_login_bonus_content は、同じ日の中の並びを sort_desc 降順で決める。同値の並びは
     * サーバー（MySQL の文字列順の id）とクライアント（SQLite の id ASC）で食い違いうるので、
     * (mst_vip_login_bonus_id, day, sort_desc) の重複を作らない（設計: mst-id-introduction/db.md 12 節 6）
     */
    public const UNIQUE_COLUMN_GROUPS = [
        'mst_vip_login_bonus_content' => ['mst_vip_login_bonus_id', 'day', 'sort_desc'],
    ];

    /**
     * UNIQUE_COLUMN_GROUPS のうち、数値として比べる列（'1' と '01' を同じ値とみなし、空は 0）。
     * それ以外の列（親 ID など）は文字列のまま、大文字小文字を区別せずに比べる（DB の照合順序に合わせる）
     */
    public const UNIQUE_NUMERIC_COLUMNS = ['day', 'sort_desc'];

    /**
     * レアリティ文字列 → 数値マッピング
     * 整数型（tinyint 等）の rarity 列にだけ使う。api 側の RarityType::findRank() と同じ値
     */
    private const RARITY_MAP = [
        'UR' => 6,
        'SSR' => 5,
        'SR' => 4,
        'R' => 3,
        'UC' => 2,
        'C' => 1,
    ];

    /**
     * レアリティを持つカラム名。変換するかどうかは名前ではなく、その列の型で決める
     */
    private const RARITY_COLUMNS = ['rarity', 'bonus_rarity'];

    /**
     * 表の rarity 系カラムの型（検証 V10 用。MasterBuildRunner が MasterSheetValidator へ渡す）
     *
     * @param  array<int, string>  $headers
     * @return array<string, array{kind: 'enum', values: array<int, string>}|array{kind: 'int'}>
     */
    public function rarityColumnsOf(string $tableName, array $headers): array
    {
        return $this->getRarityColumns($tableName, $headers);
    }

    /**
     * rarity 系カラムの型を判定する
     *
     * - ENUM 列: 文字列のまま入れる。値は列の定義（enum(...)）に含まれるものだけ許可する。
     *   並び順に依存しないので、C 先頭（mst_mailbox_content）でも UR 先頭（mst_unit 等）でも同じ
     * - 整数型の列（tinyint 等）: UR=6 … C=1 の数値へ変換する
     * - それ以外の型（文字列列など）: 何もしない
     *
     * @param  array<int, string>  $headers  取り込み対象のカラム名
     * @return array<string, array{kind: 'enum', values: array<int, string>}|array{kind: 'int'}>
     */
    private function getRarityColumns(string $tableName, array $headers): array
    {
        $targets = array_intersect($headers, self::RARITY_COLUMNS);
        if ($targets === []) {
            return [];
        }

        $result = [];
        foreach (DB::connection('mst')->select("SHOW COLUMNS FROM `{$tableName}`") as $row) {
            if (! in_array($row->Field, $targets, true)) {
                continue;
            }

            $type = strtolower((string) $row->Type);
            if (str_starts_with($type, 'enum(')) {
                preg_match_all("/'((?:[^']|'')*)'/", (string) $row->Type, $matches);
                $result[$row->Field] = [
                    'kind' => 'enum',
                    'values' => array_map(fn (string $v) => str_replace("''", "'", $v), $matches[1]),
                ];
            } elseif (preg_match('/^(tiny|small|medium|big)?int\b/', $type) === 1) {
                $result[$row->Field] = ['kind' => 'int'];
            }
        }

        return $result;
    }

    /**
     * 1 つの値を mst の列に入れる形にする（版の DB の投入用。db.md 6.6 の `version_db`・`work_db`）
     *
     * 旧 import の行変換と同じ規則: NULL 可の列だけ空文字を NULL にし、rarity 系の列は型に合わせて検証・変換する。
     *
     * @param  array{kind: 'enum', values: array<int, string>}|array{kind: 'int'}|null  $rarityColumn  rarityColumnsOf() の、その列の要素
     *
     * @throws InvalidArgumentException rarity の値が列に入れられないとき
     */
    public function convertValue(string $header, mixed $value, bool $nullable, ?array $rarityColumn): mixed
    {
        $converted = ($value === '' && $nullable) ? null : $value;
        if ($rarityColumn !== null && $converted !== null) {
            $converted = $this->normalizeRarity($header, $converted, $rarityColumn);
        }

        return $converted;
    }

    /**
     * rarity 系カラムの値を、列の型に合わせて検証・変換する
     *
     * @param  array{kind: 'enum', values: array<int, string>}|array{kind: 'int'}  $column
     *
     * @throws InvalidArgumentException 列に入れられない値のとき
     */
    private function normalizeRarity(string $header, mixed $value, array $column): mixed
    {
        $text = is_scalar($value) ? (string) $value : '';

        if ($column['kind'] === 'enum') {
            if (! in_array($text, $column['values'], true)) {
                throw new InvalidArgumentException(
                    "{$header} の値「{$text}」は使えません（".implode('・', $column['values']).' のいずれか）'
                );
            }

            return $text;
        }

        // 整数列: UR/SSR 等は数値へ変換。数値で書かれたシートは 1〜6 の範囲で受け付ける
        if (isset(self::RARITY_MAP[$text])) {
            return self::RARITY_MAP[$text];
        }
        if (preg_match('/^[1-6]$/', $text) === 1) {
            return (int) $text;
        }

        throw new InvalidArgumentException(
            "{$header} の値「{$text}」は使えません（".implode('・', array_keys(self::RARITY_MAP)).' または 1〜6 の数値）'
        );
    }
}

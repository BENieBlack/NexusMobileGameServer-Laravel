<?php

use App\Services\MasterDeployKeyPolicy;

/**
 * マスターデータのデプロイ（deploy_key の採番・固定キー・本番ガード）の設定
 *
 * 設計: docs/design/master-distribution/db.md 7.2
 * 数値は packages/nexus-version の DeployKeyConst と同じ値にする（tool は packages を使わないので 2 か所に持つ。
 * 一致は tests/Unit/MasterDeployKeyConstParityTest で確かめる。変えるときは両方を直す）。
 * DeployKeyConst::REAL_KEY_MAX（本物の上限）は tool には持たない（採番は予約帯の下限だけで止める。db.md 3.4 の 4）。
 */
return [

    /*
    | 固定キー。未設定（null）なら本物の採番。設定があればそのキーを T にして採番しない。
    | 許可リストに無い値は MasterDeployKeyPolicy が fixed_key_invalid で拒否する。
    */
    'fixed_key' => MasterDeployKeyPolicy::parseFixedKeyEnv(env('MASTER_DEPLOY_FIXED_KEY')),

    // 固定キーの許可リスト（録画・レベルデザイン・開発）
    'allowed_fixed_keys' => [7777777777, 8888888888, 9999999999],

    // この APP_ENV で fixed_key が設定されていたら fixed_key_forbidden
    'fixed_key_forbidden_envs' => ['production', 'staging'],

    // 予約帯の下限。採番の結果がこれ以上なら reserved_key_forbidden
    'reserved_floor' => 7000000000,

    /*
    | ここから版ごとの mst DB 設計（ADR 0007）の設定。設計: db.md 7.2
    | 値の意味と使う段階は db.md の各節。使うコードは段階 3 以降（tool の build・import の作り直し）で入る。
    */

    // build 環境の T の明示（QA の T、LD の確定後の V）。未設定か本物の形 YYYYMMDDNN（予約帯未満）。
    // fixed_key か explicit_key のどちらか 1 つが build 環境では必須（検査は段階 3 以降）。リクエストでは渡せない。
    'explicit_key' => env('MASTER_DEPLOY_KEY') !== null && env('MASTER_DEPLOY_KEY') !== ''
        ? (int) env('MASTER_DEPLOY_KEY')
        : null,

    // この環境の役割。build（develop・LD・QA）か import（review・production・staging）。
    // import の環境ではシートの読み取りと build を、build の環境では import を拒否する（誤操作の防止）
    'role' => env('MASTER_DEPLOY_ROLE', 'build'),

    // 明示された過去の start_at を拒否するか（production だけ true。db.md 3.6）
    'reject_past_start_at' => env('APP_ENV') === 'production',

    // 明示された過去の start_at の許容幅（秒）。これ以内は現在に丸めて通す（仮置き）
    'start_at_past_grace' => 60,

    // 確定済みの行だけを通す検査（db.md 6.7 の I4 の前進の検査と I5）。production だけ true
    'enforce_confirmed_rows' => env('APP_ENV') === 'production',

    // final.json が無い成果物の import を拒否するか（db.md 6.5.1。仮置き）。import の環境で true
    'require_finalized' => in_array(env('APP_ENV'), ['review', 'production', 'staging'], true),

    // 掃除で消さない直近の版の数 N（db.md 4.6。仮置き）
    'keep_version_count' => 3,

    // 孤児の DB・一時の場所を掃除するまでの猶予（時間。db.md 4.7。仮置き）
    'orphan_grace_hours' => 24,

    // API の切り替え予定の一覧のキャッシュ TTL（秒。db.md 8.3）。
    // start_at_too_soon・schedule_cancel_too_soon の判定（現在 + 2 × TTL）に使う。api 側の値と合わせる
    'schedule_ttl' => 60,

];

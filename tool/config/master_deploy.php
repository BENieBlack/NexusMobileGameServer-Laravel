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

];

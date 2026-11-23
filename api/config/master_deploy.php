<?php

/**
 * マスター配信（版ごとの mst DB）の API 側の設定
 *
 * 設計: docs/design/master-distribution/db.md 8.3
 */
return [

    // 切り替え予定の一覧（キャッシュキー sys:sys_deploy:schedule）の TTL（秒）。
    // 一覧にまだ載っていない新しい版が見えるまでの上限。start_at をまたぐ判定は
    // 一覧に対してリクエスト時刻で毎回行うので、TTL には依存しない。
    // 運営ツールの tool/config/master_deploy.php の schedule_ttl と合わせる（start_at_too_soon の判定に使われる）
    'schedule_ttl' => (int) env('MASTER_SCHEDULE_TTL', 60),

];

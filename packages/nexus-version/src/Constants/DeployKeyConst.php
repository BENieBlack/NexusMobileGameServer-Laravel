<?php

namespace NexusVersion\Constants;

/**
 * DeployKeyConst
 *
 * deploy_key（10 桁の YYYYMMDDNN）に関する定数
 * 設計: Nexus docs/design/master-distribution/db.md 3.3
 *
 * 運営ツール側の config/master_deploy.php の reserved_floor と同じ値を置く予定。
 * そのファイルはまだ存在しない（S3-9 で作る）。tool は packages を使わないため 2 か所に持ち、
 * 作るときに互いのコメントで相互参照する。変えるときは両方を直す。
 */
final class DeployKeyConst
{
    /**
     * 予約帯の下限（この値以上は固定キー・録画用の帯。本物の採番は取らない）
     */
    public const RESERVED_FLOOR = 7000000000;

    /** 録画用など、予約帯の固定キー */
    public const FIXED_KEY_RECORDING = 7777777777;

    public const FIXED_KEY_MID = 8888888888;

    public const FIXED_KEY_TOP = 9999999999;

    /**
     * 本物の deploy_key の上限（2999 年 12 月 31 日の 99 回目）
     * 仮置き（Nexus の overview.md の注。年 2999）
     */
    public const REAL_KEY_MAX = 2999123199;

    private function __construct() {}
}

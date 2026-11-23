<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * deploy_key の固定キー・予約帯の検査、採番、sys への登録に失敗したときの例外
 *
 * 設計: docs/design/master-distribution/api.md 4 節（エラー）
 */
class MasterDeployKeyException extends RuntimeException
{
    public const FIXED_KEY_INVALID = 'fixed_key_invalid';

    public const FIXED_KEY_FORBIDDEN = 'fixed_key_forbidden';

    public const RESERVED_KEY_FORBIDDEN = 'reserved_key_forbidden';

    public const DEPLOY_KEY_EXHAUSTED = 'deploy_key_exhausted';

    public const DEPLOY_KEY_CONFLICT = 'deploy_key_conflict';

    public const REGISTER_FAILED = 'register_failed';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fixedKeyInvalid(mixed $value): self
    {
        return new self(self::FIXED_KEY_INVALID, 'MASTER_DEPLOY_FIXED_KEY が許可リストにありません: '.(is_scalar($value) ? (string) $value : gettype($value)), 500);
    }

    public static function fixedKeyForbidden(string $appEnv): self
    {
        return new self(self::FIXED_KEY_FORBIDDEN, "APP_ENV={$appEnv} では MASTER_DEPLOY_FIXED_KEY を設定したデプロイはできません", 403);
    }

    public static function reservedKeyForbidden(int $key): self
    {
        return new self(self::RESERVED_KEY_FORBIDDEN, "予約帯の deploy_key は使えません: {$key}", 403);
    }

    public static function deployKeyExhausted(int $date): self
    {
        return new self(self::DEPLOY_KEY_EXHAUSTED, "{$date} の deploy_key は 99 件目まで使い切っています（翌日の範囲には入れません）", 422);
    }

    public static function deployKeyConflict(int $key): self
    {
        return new self(self::DEPLOY_KEY_CONFLICT, "deploy_key {$key} は別の hash で既に登録されています", 409);
    }

    /** 画面に出す message は固定文言（SQL・バインド値を出さない）。詳細は previous に残す */
    public static function registerFailed(?\Throwable $previous = null): self
    {
        return new self(self::REGISTER_FAILED, 'sys への登録に失敗しました。ログの詳細を確認してください', 500, $previous);
    }
}

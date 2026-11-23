<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * deploy_key の固定キー・予約帯の検査に失敗したときの例外
 *
 * 設計: docs/design/master-distribution/api.md 4 節（エラー）
 */
class MasterDeployKeyException extends RuntimeException
{
    public const FIXED_KEY_INVALID = 'fixed_key_invalid';

    public const FIXED_KEY_FORBIDDEN = 'fixed_key_forbidden';

    public const RESERVED_KEY_FORBIDDEN = 'reserved_key_forbidden';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
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
}

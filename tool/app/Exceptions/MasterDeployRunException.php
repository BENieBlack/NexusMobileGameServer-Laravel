<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * MasterDeployRunner が、api.md 4 節のエラーとして呼び出し側（画面・CLI）へ返す例外
 *
 * 設計: docs/design/master-distribution/api.md 2 節（失敗の形）・4 節（エラー）
 * toArray() が `{ status: error, code, message, error_list, run_id, ... }` を返す。HTTP ステータスは httpStatus。
 */
class MasterDeployRunException extends RuntimeException
{
    public const DEPLOY_LOCKED = 'deploy_locked';

    public const SHEET_NOT_CONFIGURED = 'sheet_not_configured';

    public const SHEET_READ_FAILED = 'sheet_read_failed';

    public const SHEET_INVALID = 'sheet_invalid';

    public const APPLY_FAILED = 'apply_failed';

    public const EXPORT_FAILED = 'export_failed';

    public const INCONSISTENT = 'inconsistent';

    public const INTERNAL_ERROR = 'internal_error';

    // ---- 版ごとの mst DB 設計（db.md 3.6・13.1 のエラー表）。build が使う ----

    public const ROLE_FORBIDDEN = 'role_forbidden';

    public const BASE_DATABASE_MISMATCH = 'base_database_mismatch';

    public const MST_DATABASE_NAME_TOO_LONG = 'mst_database_name_too_long';

    public const START_AT_PAST = 'start_at_past';

    public const LOAD_FAILED = 'load_failed';

    public const MST_DATABASE_COLLISION = 'mst_database_collision';

    public const ARTIFACT_DIR_COLLISION = 'artifact_dir_collision';

    public const STORE_FAILED = 'store_failed';

    public const REGISTER_FAILED = 'register_failed';

    public const REGISTER_UNKNOWN = 'register_unknown';

    public const DROP_FORBIDDEN = 'drop_forbidden';

    /**
     * @param  array<int, array<string, mixed>>  $errorList
     * @param  array<string, mixed>  $extra  error_total・error_count_by_code・compensated など、レスポンスへそのまま足す項目
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        public readonly array $errorList = [],
        public readonly ?int $runId = null,
        public readonly array $extra = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function withRunId(?int $runId): self
    {
        return new self($this->errorCode, $this->getMessage(), $this->httpStatus, $this->errorList, $runId, $this->extra, $this->getPrevious());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => 'error',
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'error_list' => $this->errorList,
            'run_id' => $this->runId,
            ...$this->extra,
        ];
    }
}

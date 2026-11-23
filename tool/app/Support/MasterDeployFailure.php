<?php

namespace App\Support;

use App\Exceptions\MasterDeployKeyException;
use App\Exceptions\MasterDeployRunException;
use Throwable;

/**
 * 画面（HTTP）と CLI が、操作の失敗を同じ MasterDeployRunException にそろえる
 *
 * 設計: docs/design/master-distribution/api.md 13.1。想定外の例外は internal_error（詳細は画面に出さずログに残す）。
 */
final class MasterDeployFailure
{
    public static function from(Throwable $e): MasterDeployRunException
    {
        if ($e instanceof MasterDeployRunException) {
            return $e;
        }
        if ($e instanceof MasterDeployKeyException) {
            return new MasterDeployRunException($e->errorCode, $e->getMessage(), $e->httpStatus, previous: $e);
        }

        report($e);

        return new MasterDeployRunException(
            MasterDeployRunException::INTERNAL_ERROR,
            '想定外のエラーが発生しました。ログを確認してください。',
            500,
            previous: $e,
        );
    }

    /**
     * CLI の終了コード（api.md 10 節。13.1 の「終了コード」の列）
     * 0 成功 / 1 実行中の失敗・想定外 / 2 入力・シート・成果物の検証の失敗 / 3 排他 / 4 環境・状態の前提で拒否
     */
    public static function exitCode(string $code): int
    {
        return match ($code) {
            MasterDeployRunException::DEPLOY_LOCKED => 3,
            MasterDeployRunException::SHEET_INVALID,
            MasterDeployRunException::ARTIFACT_INVALID,
            MasterDeployRunException::NOT_FINALIZED,
            MasterDeployRunException::FINALIZE_CONFLICT,
            MasterDeployRunException::SCHEMA_MISMATCH,
            MasterDeployRunException::CONFIRMED_KEY_MODIFIED,
            MasterDeployRunException::DEPLOY_KEY_REGRESSION,
            MasterDeployRunException::REFERENCE_UNAVAILABLE,
            MasterDeployRunException::START_AT_PAST,
            MasterDeployRunException::SHEET_CHANGED_SINCE_BUILD,
            MasterDeployRunException::DEPLOY_KEY_MISMATCH,
            MasterDeployRunException::ARTIFACT_NOT_FOUND => 2,
            MasterDeployRunException::ROLE_FORBIDDEN,
            'fixed_key_forbidden',
            'fixed_key_invalid',
            'deploy_key_not_configured',
            MasterDeployRunException::RESERVED_KEY_FORBIDDEN,
            MasterDeployRunException::BASE_DATABASE_MISMATCH,
            MasterDeployRunException::MST_DATABASE_NAME_TOO_LONG,
            MasterDeployRunException::UPSTREAM_NOT_CONFIGURED,
            MasterDeployRunException::SHEET_NOT_CONFIGURED,
            MasterDeployRunException::FOLLOW_CHECK_UNAVAILABLE,
            MasterDeployRunException::ROLLBACK_BLOCKED_BY_SCHEDULED,
            MasterDeployRunException::ROLLBACK_TARGET_NOT_FOUND,
            MasterDeployRunException::ROLLBACK_TARGET_UNAVAILABLE,
            MasterDeployRunException::SCHEDULE_NOT_FOUND,
            MasterDeployRunException::SCHEDULE_ALREADY_STARTED,
            MasterDeployRunException::SCHEDULE_CANCEL_TOO_SOON,
            MasterDeployRunException::DEPLOY_NOT_FOUND,
            MasterDeployRunException::DEACTIVATE_NO_FALLBACK,
            MasterDeployRunException::ACTIVATE_TARGET_DROPPED,
            MasterDeployRunException::RUN_NOT_FOUND,
            MasterDeployRunException::DROP_FORBIDDEN => 4,
            default => 1,
        };
    }
}

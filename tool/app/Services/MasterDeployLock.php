<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * マスターデプロイの排他（MySQL の名前付きロック。sys 接続）
 *
 * 設計: docs/design/master-distribution/api.md 11.1
 * 名前は `nexus_master_deploy:{sys の DB 名}`（環境ごとに別 DB なので衝突しない）。待たない（タイムアウト 0）。
 * ロックは接続（セッション）に紐づくので、プロセスが落ちれば自動で外れる。
 */
class MasterDeployLock
{
    /** MySQL のロック名の上限（64 文字）。超えるときはハッシュで縮める */
    private const NAME_MAX_LENGTH = 64;

    public function name(): string
    {
        $name = 'nexus_master_deploy:'.DB::connection('sys')->getDatabaseName();
        if (strlen($name) > self::NAME_MAX_LENGTH) {
            $name = 'nexus_master_deploy:'.md5($name);
        }

        return $name;
    }

    /**
     * 取れたら true、他が持っていれば false（待たない）
     */
    public function acquire(): bool
    {
        $row = DB::connection('sys')->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$this->name()]);
        if ($row === null || $row->acquired === null) {
            throw new RuntimeException('GET_LOCK がエラーを返しました');
        }

        return (int) $row->acquired === 1;
    }

    /**
     * 誰も持っていなければ true（読み取りだけ。ロックは取らない。target の running 判定用）
     */
    public function isFree(): bool
    {
        $row = DB::connection('sys')->selectOne('SELECT IS_FREE_LOCK(?) AS is_free', [$this->name()]);
        if ($row === null || $row->is_free === null) {
            throw new RuntimeException('IS_FREE_LOCK がエラーを返しました');
        }

        return (int) $row->is_free === 1;
    }

    public function release(): void
    {
        $row = DB::connection('sys')->selectOne('SELECT RELEASE_LOCK(?) AS released', [$this->name()]);
        // 1 以外（0: 他の接続が持っている、NULL: 存在しない）は異常なので残す
        if ($row === null || (int) $row->released !== 1) {
            Log::warning('マスターデプロイのロックを解放できませんでした', ['released' => $row?->released, 'name' => $this->name()]);
        }
    }
}

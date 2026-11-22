<?php

namespace App\Repositories\Sys;

use App\Persistence\ApiSession;
use Illuminate\Database\Eloquent\Builder;
use Nexus\Core\Repositories\Sys\_BaseSysRepository as PersistenceBaseSysRepository;
use Nexus\Core\UnitOfWork\Traits\UsesUnitOfWork;

/**
 * _BaseSysRepository
 *
 * Sysデータベースのリポジトリ基底クラス
 * キャッシュ機能を含む共通のCRUD操作を実装
 *
 * @template T of \Nexus\Core\Models\Sys\_BaseSys
 *
 * @extends PersistenceBaseSysRepository<T>
 *
 * @implements _BaseSysRepositoryInterface<T>
 */
abstract class _BaseSysRepository extends PersistenceBaseSysRepository implements _BaseSysRepositoryInterface
{
    use UsesUnitOfWork;

    protected static function hasSysPlayerId(): bool
    {
        return ApiSession::hasSysPlayerId();
    }

    protected static function getSysPlayerId(): int
    {
        return ApiSession::getSysPlayerId();
    }

    /**
     * 行ロック（SELECT ... FOR UPDATE）付きの読み取りクエリ
     *
     * キャッシュにも更新キューにも載せず、常に DB の最新の行を読む。
     * 接続は $connection（sys）に固定する。
     * トランザクションの外で呼ぶと例外にする（検査は _BaseRepository に共通化）。
     *
     * @return Builder<T>
     *
     * @throws \LogicException トランザクション内でない場合
     */
    protected function selectForUpdate(): Builder
    {
        $this->assertInTransactionForLock($this->connection);

        return $this->selectWithoutCache()->lockForUpdate();
    }
}

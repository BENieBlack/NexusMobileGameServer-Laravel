<?php

namespace App\Repositories\Shard;

use Nexus\Core\Repositories\Shard\_BaseTrxRepositoryInterface as PersistenceBaseTrxRepositoryInterface;

/**
 * _BaseTrxRepositoryInterface
 *
 * TrxデータRepository用のインターフェース
 * メモリキャッシュのみを使用し、ユニークキーで管理
 *
 * @template T of \Nexus\Core\Models\Shard\_BaseTrx
 *
 * @extends PersistenceBaseTrxRepositoryInterface<T>
 */
interface _BaseTrxRepositoryInterface extends PersistenceBaseTrxRepositoryInterface {}

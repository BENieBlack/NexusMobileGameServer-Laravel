<?php

namespace App\Repositories\Shard;

use Nexus\Core\Repositories\Shard\_BaseLogRepositoryInterface as PersistenceBaseLogRepositoryInterface;

/**
 * _BaseLogRepositoryInterface
 *
 * LogデータRepository用のインターフェース
 * ログはINSERT ONLYでキャッシュなし
 *
 * @template T of \Nexus\Core\Models\Shard\_BaseLog
 *
 * @extends PersistenceBaseLogRepositoryInterface<T>
 */
interface _BaseLogRepositoryInterface extends PersistenceBaseLogRepositoryInterface {}

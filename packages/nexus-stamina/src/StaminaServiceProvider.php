<?php

namespace NexusStamina;

use Illuminate\Support\ServiceProvider;

/**
 * Stamina Service Provider
 */
class StaminaServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // マイグレーションをロード（動的シャーディング対応）
        // 注意: php artisan trx:migrate で全シャード（trx1, trx2, ...）に実行
        $baseDir = __DIR__.'/../database/migrations';

        // 各サブディレクトリを個別に読み込む
        foreach (['mst', 'shard', 'sys'] as $type) {
            $path = "{$baseDir}/{$type}";
            if (is_dir($path)) {
                $this->loadMigrationsFrom($path);
            }
        }
    }
}

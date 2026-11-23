<?php

namespace App\Providers;

use App\Services\MasterActivationService;
use App\Services\MasterBuildRunner;
use App\Services\MasterCacheInvalidator;
use App\Services\MasterCancelScheduleService;
use App\Services\MasterCleanupService;
use App\Services\MasterDeployKeyPolicy;
use App\Services\MasterFinalizeRunner;
use App\Services\MasterImportRunner;
use App\Services\MasterResolveUnknownService;
use App\Services\MasterRollbackService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // コンストラクタが設定値を受け取るので、設定から作る（毎回作る。テストで config を差し替えても追従する）
        $this->app->bind(MasterDeployKeyPolicy::class, fn () => MasterDeployKeyPolicy::fromConfig());

        // コンストラクタの引数は全部省略可で、既定の部品を自分で作る。コンテナの自動解決に任せると、MasterCacheInvalidator の
        // `?Repository $cache` に既定のキャッシュストア（Redis とは限らない）が入ってしまうので、明示的に作る
        foreach ([
            MasterCacheInvalidator::class,
            MasterBuildRunner::class,
            MasterImportRunner::class,
            MasterFinalizeRunner::class,
            MasterRollbackService::class,
            MasterCancelScheduleService::class,
            MasterCleanupService::class,
            MasterActivationService::class,
            MasterResolveUnknownService::class,
        ] as $class) {
            $this->app->bind($class, fn () => new $class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

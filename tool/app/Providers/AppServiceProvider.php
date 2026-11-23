<?php

namespace App\Providers;

use App\Services\MasterDeployKeyPolicy;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

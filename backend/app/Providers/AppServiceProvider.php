<?php

namespace App\Providers;

use App\Contracts\MikrotikOperationExecutor;
use App\Contracts\MikrotikRouterConnectionTester;
use App\Services\Mikrotik\RouterOsMikrotikOperationExecutor;
use App\Services\Mikrotik\RouterOsMikrotikRouterConnectionTester;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MikrotikOperationExecutor::class, RouterOsMikrotikOperationExecutor::class);
        $this->app->bind(MikrotikRouterConnectionTester::class, RouterOsMikrotikRouterConnectionTester::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

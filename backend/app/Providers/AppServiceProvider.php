<?php

namespace App\Providers;

use App\Contracts\MikrotikOperationExecutor;
use App\Contracts\MikrotikRouterInspector;
use App\Contracts\MikrotikRouterConnectionTester;
use App\Services\Mikrotik\RouterOsMikrotikOperationExecutor;
use App\Services\Mikrotik\RouterOsMikrotikRouterConnectionTester;
use App\Services\Mikrotik\RouterOsMikrotikRouterInspector;
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
        $this->app->bind(MikrotikRouterInspector::class, RouterOsMikrotikRouterInspector::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

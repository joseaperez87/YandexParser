<?php

namespace App\Providers;

use App\Support\AntiBot\NullProxyPool;
use App\Support\AntiBot\ProxyPool;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // В проде — реализация с ротацией резидентных/дата-центр прокси.
        $this->app->bind(ProxyPool::class, NullProxyPool::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);
    }
}

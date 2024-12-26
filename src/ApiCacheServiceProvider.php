<?php

namespace FOfX\ApiCache;

use Illuminate\Support\ServiceProvider;

class ApiCacheServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__ . '/Database/migrations');
    }

    public function register()
    {
        // Register any bindings or services here
    }
}

<?php

namespace FOfX\ApiCache;

use Illuminate\Support\ServiceProvider;

class ApiCacheServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/api-cache.php' => config_path('api-cache.php'),
            __DIR__ . '/../config/logging.php'   => config_path('logging.php'),
        ]);

        $this->loadMigrationsFrom(__DIR__ . '/Database/migrations');
    }

    public function register()
    {
        // Register any bindings or services here
    }
}

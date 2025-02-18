<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\ServiceProvider;

class ApiCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/api-cache.php', 'api-cache');

        // Register factory for client-specific services
        $this->app->singleton('api-cache.factory', function ($app) {
            return new ApiCacheFactory($app);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'api-cache-migrations');

            $this->publishes([
                __DIR__ . '/../config/api-cache.php' => config_path('api-cache.php'),
            ], 'api-cache-config');
        }
    }
}

<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\ServiceProvider;

class ApiCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/api-cache.php', 'api-cache');

        // Register core services that have no dependencies
        $this->app->singleton(CompressionService::class, function () {
            return new CompressionService();
        });

        // Register services that depend on Laravel services
        $this->app->singleton(RateLimitService::class, function ($app) {
            return new RateLimitService(
                $app['cache']->driver()
            );
        });

        // Register services that depend on core services
        $this->app->singleton(CacheRepository::class, function ($app) {
            return new CacheRepository(
                $app['db']->connection(),
                $app->make(CompressionService::class)
            );
        });

        // Register manager that depends on other services
        $this->app->singleton(ApiCacheManager::class, function ($app) {
            return new ApiCacheManager(
                $app->make(CacheRepository::class),
                $app->make(RateLimitService::class)
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

            $this->publishes([
                __DIR__ . '/../config/api-cache.php' => config_path('api-cache.php'),
            ], 'config');

            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations'),
            ], 'migrations');
        }
    }
}

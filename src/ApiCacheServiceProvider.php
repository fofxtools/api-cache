<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Capsule\Manager as Capsule;

class ApiCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/api-cache.php', 'api-cache');

        // Register Laravel services
        $this->app->singleton('rate.limiter', function ($app) {
            return new RateLimiter($app['cache']->driver());
        });

        // Register api-cache core services that have no dependencies
        $this->app->singleton(CompressionService::class, function () {
            return new CompressionService();
        });

        // Register api-cache services with dependencies
        $this->app->singleton(RateLimitService::class, function ($app) {
            return new RateLimitService(
                $app['rate.limiter']
            );
        });
        $this->app->singleton(CacheRepository::class, function ($app) {
            return new CacheRepository(
                $app['db']->connection(),
                $app->make(CompressionService::class)
            );
        });

        $this->app->singleton(ApiCacheManager::class, function ($app) {
            return new ApiCacheManager(
                $app->make(CacheRepository::class),
                $app->make(RateLimitService::class)
            );
        });
    }

    /**
     * Register database connection for testing
     *
     * @param Capsule $capsule Database capsule instance
     */
    public function registerDatabase(Capsule $capsule): void
    {
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $this->app->singleton('db', function () use ($capsule) {
            return $capsule->getDatabaseManager();
        });

        $this->app->singleton('db.connection', function () use ($capsule) {
            return $capsule->getDatabaseManager()->connection();
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

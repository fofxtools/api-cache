<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Foundation\Application;

/**
 * Service provider for the API Cache package
 *
 * Registers and bootstraps all API Cache services.
 *
 * Basic usage:
 * ```php
 * // In config/app.php providers array, add:
 * FOfX\ApiCache\ApiCacheServiceProvider::class
 *
 * // Or register manually:
 * $app->register(ApiCacheServiceProvider::class);
 *
 * // Then use the services:
 * $manager = app(ApiCacheManager::class);
 * $client = new DemoApiClient($manager);
 * ```
 *
 * For testing:
 * ```php
 * $provider = new ApiCacheServiceProvider($app);
 * $provider->registerCache($app);
 * $provider->registerDatabase();
 * $app->register(ApiCacheServiceProvider::class);
 * ```
 */
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

    /**
     * Register cache services for testing
     *
     * Note: This is a helper method for tests and examples.
     * In a real Laravel app, cache should be registered by the app.
     *
     * @param \Illuminate\Foundation\Application $app
     */
    public function registerCache(Application $app): void
    {
        // Register cache config if not present
        if (!$app->bound('config')) {
            $app->singleton('config', fn () => new \Illuminate\Config\Repository());
        }

        if (!$app['config']->has('cache')) {
            $app['config']->set('cache', require __DIR__ . '/../config/cache.php');
        }

        // Register cache service if not present
        if (!$app->bound('cache')) {
            $app->singleton('cache', fn ($app) => new \Illuminate\Cache\CacheManager($app));
        }
    }

    /**
     * Register database connection for testing
     *
     * @param Capsule|null $capsule Database capsule instance
     */
    public function registerDatabase(?Capsule $capsule = null): void
    {
        if ($capsule === null) {
            $capsule    = new Capsule();
            $default    = 'sqlite_memory';
            $connection = config('database.connections.' . $default);
            $capsule->addConnection($connection);
        }

        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $this->app->singleton('db', function () use ($capsule) {
            return $capsule->getDatabaseManager();
        });

        $this->app->singleton('db.connection', function () use ($capsule) {
            return $capsule->getDatabaseManager()->connection();
        });

        $this->app->singleton('db.schema', function () use ($capsule) {
            return $capsule->getDatabaseManager()->connection()->getSchemaBuilder();
        });
    }
}

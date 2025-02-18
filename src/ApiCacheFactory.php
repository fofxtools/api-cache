<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Log;

class ApiCacheFactory
{
    /**
     * @var Application
     */
    protected Application $app;

    /**
     * Create a new factory instance
     *
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function createManager(string $clientName): ApiCacheManager
    {
        Log::debug('Factory: Creating cache manager', [
            'client' => $clientName,
        ]);

        // Create compression service
        $compression = new CompressionService();

        // Create repository
        $repository = new CacheRepository(
            $this->app['db']->connection(),
            $compression
        );

        // Create rate limiter
        $rateLimiter = new RateLimiter($this->app['cache']->driver());

        // Configure with client-specific settings
        $rateLimiter->for($clientName, function () use ($clientName) {
            return Limit::perMinute(
                config("api-cache.apis.{$clientName}.rate_limit.max_attempts"),
                config("api-cache.apis.{$clientName}.rate_limit.decay_minutes")
            );
        });

        // Wrap in our service
        $rateLimitService = new RateLimitService($rateLimiter);

        // Create and return manager
        return new ApiCacheManager($repository, $rateLimitService);
    }
}

<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\ApiCacheManager;
use FOfX\ApiCache\ApiCacheServiceProvider;
use FOfX\ApiCache\CacheRepository;
use FOfX\ApiCache\CompressionService;
use FOfX\ApiCache\RateLimitService;
use Illuminate\Cache\RateLimiter;
use FOfX\ApiCache\Tests\TestCase;

class ApiCacheServiceProviderTest extends TestCase
{
    /**
     * Get service providers to register for testing.
     * Called implicitly by Orchestra TestCase to register providers before tests run.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array
     */
    protected function getPackageProviders($app): array
    {
        return [ApiCacheServiceProvider::class];
    }

    public function test_RateLimiter_is_registered_correctly(): void
    {
        $rateLimiter = $this->app['rate.limiter'];
        $this->assertInstanceOf(RateLimiter::class, $rateLimiter);
    }

    public function test_RateLimitService_is_registered_correctly(): void
    {
        $service = $this->app->make(RateLimitService::class);
        $this->assertInstanceOf(RateLimitService::class, $service);
    }

    public function test_CompressionService_is_registered_correctly(): void
    {
        $service = $this->app->make(CompressionService::class);
        $this->assertInstanceOf(CompressionService::class, $service);
    }

    public function test_CacheRepository_is_registered_correctly(): void
    {
        $repository = $this->app->make(CacheRepository::class);
        $this->assertInstanceOf(CacheRepository::class, $repository);
    }

    public function test_ApiCacheManager_is_registered_correctly(): void
    {
        $manager = $this->app->make(ApiCacheManager::class);
        $this->assertInstanceOf(ApiCacheManager::class, $manager);
    }
}

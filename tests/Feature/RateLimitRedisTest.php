<?php

declare(strict_types=1);

namespace Tests\Feature;

use FOfX\ApiCache\ApiCacheServiceProvider;
use FOfX\ApiCache\RateLimitService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Redis;
use FOfX\ApiCache\Tests\TestCase;

class RateLimitRedisTest extends TestCase
{
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure Redis for testing
        config(['cache.default' => 'redis']);
        config(['database.redis.client' => 'phpredis']);

        // Configure rate limiting for test client
        config([
            'api-cache.apis.test-client.rate_limit_max_attempts'  => 3,
            'api-cache.apis.test-client.rate_limit_decay_seconds' => 60,
        ]);

        // Create rate limiter with Redis
        $rateLimiter   = new RateLimiter(app('cache')->driver());
        $this->service = new RateLimitService($rateLimiter);

        // Clear any existing rate limits
        $this->service->clear('test-client');
    }

    protected function tearDown(): void
    {
        // Clean up Redis after tests
        app('redis.connection')->flushDB();

        parent::tearDown();
    }

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

    public function test_rate_limits_are_shared_across_instances()
    {
        // First instance uses all attempts
        $this->assertTrue($this->service->allowRequest('test-client'));
        $this->service->incrementAttempts('test-client');

        $this->assertTrue($this->service->allowRequest('test-client'));
        $this->service->incrementAttempts('test-client');

        $this->assertTrue($this->service->allowRequest('test-client'));
        $this->service->incrementAttempts('test-client');

        // Fourth request should be denied
        $this->assertFalse($this->service->allowRequest('test-client'));

        // Create a new instance of the service (simulating a different process)
        $newService = new RateLimitService(new RateLimiter(app('cache')->driver()));

        // The new instance should see the same rate limit state
        $this->assertFalse($newService->allowRequest('test-client'));
        $this->assertEquals(0, $newService->getRemainingAttempts('test-client'));
    }

    public function test_rate_limit_resets_after_decay_period()
    {
        // Configure a short decay period for testing
        config(['api-cache.apis.test-client.rate_limit_decay_seconds' => 1]);

        // Use all attempts
        $this->assertTrue($this->service->allowRequest('test-client'));
        $this->service->incrementAttempts('test-client');

        $this->assertTrue($this->service->allowRequest('test-client'));
        $this->service->incrementAttempts('test-client');

        $this->assertTrue($this->service->allowRequest('test-client'));
        $this->service->incrementAttempts('test-client');

        // Fourth request should be denied
        $this->assertFalse($this->service->allowRequest('test-client'));

        // Wait for decay period
        sleep(2);

        // Should be able to make requests again
        $this->assertTrue($this->service->allowRequest('test-client'));
        $this->assertEquals(3, $this->service->getRemainingAttempts('test-client'));
    }

    public function test_clear_resets_rate_limits()
    {
        // Use all attempts
        $this->assertTrue($this->service->allowRequest('test-client'));
        $this->service->incrementAttempts('test-client');

        $this->assertTrue($this->service->allowRequest('test-client'));
        $this->service->incrementAttempts('test-client');

        $this->assertTrue($this->service->allowRequest('test-client'));
        $this->service->incrementAttempts('test-client');

        // Fourth request should be denied
        $this->assertFalse($this->service->allowRequest('test-client'));

        // Clear the rate limit
        $this->service->clear('test-client');

        // Should be able to make requests again
        $this->assertTrue($this->service->allowRequest('test-client'));
        $this->assertEquals(3, $this->service->getRemainingAttempts('test-client'));
    }

    public function test_increment_attempts_works_correctly()
    {
        // Initial attempts should be 3
        $this->assertEquals(3, $this->service->getRemainingAttempts('test-client'));

        // Increment attempts
        $this->service->incrementAttempts('test-client');

        // Remaining attempts should be 2
        $this->assertEquals(2, $this->service->getRemainingAttempts('test-client'));

        // Increment again
        $this->service->incrementAttempts('test-client');

        // Remaining attempts should be 1
        $this->assertEquals(1, $this->service->getRemainingAttempts('test-client'));

        // Increment again
        $this->service->incrementAttempts('test-client');

        // Remaining attempts should be 0
        $this->assertEquals(0, $this->service->getRemainingAttempts('test-client'));

        // Increment again (should go negative)
        $this->service->incrementAttempts('test-client');

        // Remaining attempts should be -1
        $this->assertEquals(-1, $this->service->getRemainingAttempts('test-client'));
    }
}

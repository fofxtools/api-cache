<?php

declare(strict_types=1);

namespace Tests\Unit;

use FOfX\ApiCache\RateLimitService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery;
use FOfX\ApiCache\Tests\TestCase;

class RateLimitServiceTest extends TestCase
{
    /** @var Mockery\MockInterface&RateLimiter */
    private RateLimiter $limiter;
    private RateLimitService $service;
    private string $client = 'test-client';

    protected function setUp(): void
    {
        parent::setUp();

        // Check if Redis server is available
        try {
            $redis = app('redis.connection');
            $redis->ping();
        } catch (\Exception $e) {
            $os      = PHP_OS_FAMILY;
            $command = match ($os) {
                'Windows' => 'redis-server --service-start --service-name redis',
                'Linux'   => 'sudo systemctl start redis-server',
                'Darwin'  => 'brew services start redis',
                default   => 'redis-server'
            };
            $this->markTestSkipped("Redis server is not running. Please start it with: {$command}");
        }

        $mockLimiter   = Mockery::mock(RateLimiter::class);
        $this->limiter = $mockLimiter;
        $this->service = new RateLimitService($this->limiter);

        // Mock config properly with default value
        Config::partialMock()
            ->shouldReceive('get')
            ->with("api-cache.apis.{$this->client}.rate_limit_max_attempts", null)
            ->andReturn(3)
            ->byDefault();

        Config::partialMock()
            ->shouldReceive('get')
            ->with("api-cache.apis.{$this->client}.rate_limit_decay_seconds", null)
            ->andReturn(60)
            ->byDefault();
    }

    protected function tearDown(): void
    {
        // Note: Calling parent::tearDown(); causes "InvalidArgumentException: Database connection [] not configured." errors
        // Thus it is ommitted.
        // Remove exception handlers and error handlers to avoid warnings
        restore_exception_handler();
        restore_error_handler();
        Mockery::close();
    }

    public function test_getRateLimitKey_generates_correct_key(): void
    {
        $expected = "api-cache:rate-limit:{$this->client}";
        $actual   = $this->service->getRateLimitKey($this->client);

        $this->assertEquals($expected, $actual);
    }

    public function test_getMaxAttempts_returns_value_from_config(): void
    {
        $this->assertEquals(3, $this->service->getMaxAttempts($this->client));
    }

    public function test_getMaxAttempts_returns_null_for_unlimited_attempts(): void
    {
        Config::partialMock()
            ->shouldReceive('get')
            ->with("api-cache.apis.{$this->client}.rate_limit_max_attempts", null)
            ->andReturn(null)
            ->zeroOrMoreTimes();

        $this->assertNull($this->service->getMaxAttempts($this->client));
    }

    public function test_getDecaySeconds_returns_value_from_config(): void
    {
        $this->assertEquals(60, $this->service->getDecaySeconds($this->client));
    }

    public function test_clear_resets_rate_limit_state(): void
    {
        $key = "api-cache:rate-limit:{$this->client}";

        // Expect 3 calls to remaining():
        // 1. Before clear
        // 2. After clear
        // 3. In our assertion
        $this->limiter->shouldReceive('remaining')
            ->times(3)
            ->andReturn(1, 3, 3);  // Values for each call

        $this->limiter->shouldReceive('clear')
            ->once()
            ->with($key);

        Log::shouldReceive('debug')->once()->with(
            'Rate limit state cleared',
            [
                'client'                          => $this->client,
                'remaining_attempts_before_clear' => 1,
                'remaining_attempts_after_clear'  => 3,
            ]
        );

        $this->service->clear($this->client);

        // Verify rate limit was actually reset to max attempts (3)
        $this->assertSame(3, $this->service->getRemainingAttempts($this->client));
    }

    public function test_getRemainingAttempts_returns_remaining_count(): void
    {
        $key = "api-cache:rate-limit:{$this->client}";

        $this->limiter->shouldReceive('remaining')
            ->once()
            ->with($key, 3)
            ->andReturn(2);

        $this->assertEquals(2, $this->service->getRemainingAttempts($this->client));
    }

    public function test_getRemainingAttempts_returns_max_int_for_unlimited(): void
    {
        Config::partialMock()
            ->shouldReceive('get')
            ->with("api-cache.apis.{$this->client}.rate_limit_max_attempts", null)
            ->andReturn(null);

        $this->limiter->shouldReceive('remaining')
            ->never();  // Should not be called for unlimited attempts

        $this->assertEquals(PHP_INT_MAX, $this->service->getRemainingAttempts($this->client));
    }

    public function test_getAvailableIn_returns_seconds_until_reset(): void
    {
        $key         = "api-cache:rate-limit:{$this->client}";
        $availableIn = 30;

        $this->limiter->shouldReceive('tooManyAttempts')
            ->once()
            ->with($key, 3)
            ->andReturn(true);

        $this->limiter->shouldReceive('availableIn')
            ->once()
            ->with($key)
            ->andReturn($availableIn);

        $this->assertEquals($availableIn, $this->service->getAvailableIn($this->client));
    }

    public function test_getAvailableIn_returns_zero_when_attempts_available(): void
    {
        $key = "api-cache:rate-limit:{$this->client}";

        $this->limiter->shouldReceive('tooManyAttempts')
            ->once()
            ->with($key, 3)
            ->andReturn(false);

        $this->assertEquals(0, $this->service->getAvailableIn($this->client));
    }

    public function test_allowRequest_returns_true_when_attempts_remain(): void
    {
        $this->limiter->shouldReceive('remaining')->andReturn(2);

        Log::shouldReceive('debug')->once()->with(
            'Rate limit status check',
            [
                'client'             => $this->client,
                'remaining_attempts' => 2,
                'max_attempts'       => 3,
            ]
        );

        $this->assertTrue($this->service->allowRequest($this->client));
    }

    public function test_allowRequest_returns_false_when_no_attempts_remain(): void
    {
        $key = "api-cache:rate-limit:{$this->client}";

        $this->limiter->shouldReceive('remaining')
            ->once()
            ->andReturn(0);

        $this->limiter->shouldReceive('tooManyAttempts')
            ->once()
            ->with($key, 3)
            ->andReturn(true);

        $this->limiter->shouldReceive('availableIn')
            ->once()
            ->with($key)
            ->andReturn(30);

        Log::shouldReceive('warning')->once();

        $this->assertFalse($this->service->allowRequest($this->client));
    }

    public function test_incrementAttempts_increases_count(): void
    {
        $key = "api-cache:rate-limit:{$this->client}";

        $this->limiter->shouldReceive('increment')
            ->once()
            ->with($key, 60, 1);

        $this->limiter->shouldReceive('remaining')
            ->twice() // Called once in incrementAttempts and once in getRemainingAttempts
            ->andReturn(2);

        Log::shouldReceive('debug')->once();

        $this->service->incrementAttempts($this->client);

        // Add assertion to avoid risky test
        $this->assertEquals(2, $this->service->getRemainingAttempts($this->client));
    }
}

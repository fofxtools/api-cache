<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\RateLimitException;
use PHPUnit\Framework\TestCase;

class RateLimitExceptionTest extends TestCase
{
    public function test_constructor_sets_default_message(): void
    {
        $exception = new RateLimitException('test-client', 60);

        $this->assertEquals(
            "Rate limit exceeded for client 'test-client'. Available in 60 seconds.",
            $exception->getMessage()
        );
        $this->assertEquals(429, $exception->getCode());
    }

    public function test_constructor_allows_custom_message(): void
    {
        $customMessage = 'Custom rate limit message';
        $exception     = new RateLimitException('test-client', 60, $customMessage);

        $this->assertEquals($customMessage, $exception->getMessage());
    }

    public function test_getClientName_returns_client_name(): void
    {
        $exception = new RateLimitException('test-client', 60);

        $this->assertEquals('test-client', $exception->getClientName());
    }

    public function test_getAvailableInSeconds_returns_wait_time(): void
    {
        $exception = new RateLimitException('test-client', 60);

        $this->assertEquals(60, $exception->getAvailableInSeconds());
    }
}

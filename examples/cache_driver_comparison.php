<?php

/**
 * Cache Driver Comparison Test
 *
 * This script demonstrates and verifies that the rate limiting service works correctly
 * with different cache drivers (array and Redis). It tests that:
 * - Rate limiting logic works consistently across drivers
 * - Each driver maintains its own state independently
 * - The service is truly driver-agnostic
 *
 * Technical Note:
 * We use app('cache')->store() instead of changing the default driver to ensure
 * proper state isolation between drivers. This is necessary because Laravel's
 * cache repository is bound as a singleton in the service container.
 *
 * Without using separate stores, changing the default driver would not create a new
 * cache repository instance, leading to unintended state sharing between tests.
 *
 * Using named stores ensures each driver has its own isolated cache repository
 * instance, preventing cross-contamination of state.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use FOfX\ApiCache\RateLimitService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Config;

function testRateLimiting(RateLimitService $service): void
{
    $client = 'test-client';

    // Before clear
    echo 'Remaining attempts before clear: ' . $service->getRemainingAttempts($client) . "\n";
    echo 'Decay seconds before clear: ' . $service->getDecaySeconds($client) . "\n";

    // Clear any existing rate limits
    $service->clear($client);

    // After clear
    echo 'Remaining attempts after clear: ' . $service->getRemainingAttempts($client) . "\n";
    echo 'Decay seconds after clear: ' . $service->getDecaySeconds($client) . "\n";

    // First request
    echo 'First request allowed: ' . ($service->allowRequest($client) ? 'Yes' : 'No') . "\n";
    $service->incrementAttempts($client);
    echo 'Remaining after first: ' . $service->getRemainingAttempts($client) . "\n";

    // Second request
    echo 'Second request allowed: ' . ($service->allowRequest($client) ? 'Yes' : 'No') . "\n";
    $service->incrementAttempts($client);
    echo 'Remaining after second: ' . $service->getRemainingAttempts($client) . "\n";

    // Third request
    echo 'Third request allowed: ' . ($service->allowRequest($client) ? 'Yes' : 'No') . "\n";
    $service->incrementAttempts($client);
    echo 'Remaining after third: ' . $service->getRemainingAttempts($client) . "\n";

    // Fourth request (should be denied)
    echo 'Fourth request allowed: ' . ($service->allowRequest($client) ? 'Yes' : 'No') . "\n";
    $service->incrementAttempts($client);
    echo 'Remaining after fourth: ' . $service->getRemainingAttempts($client) . "\n";

    // Clear and verify reset
    $service->clear($client);
    echo 'Remaining after clear: ' . $service->getRemainingAttempts($client) . "\n";

    // Test negative attempts
    for ($i = 0; $i < 5; $i++) {
        $service->incrementAttempts($client);
    }
    echo 'Remaining after 5 increments: ' . $service->getRemainingAttempts($client) . "\n";
}
// Configure test client
Config::set('api-cache.apis.test-client.rate_limit_max_attempts', 3);
Config::set('api-cache.apis.test-client.rate_limit_decay_seconds', 60);

// Test with array driver
echo "Testing with array driver:\n";
$arrayStore   = app('cache')->store('array');
$arrayService = new RateLimitService(new RateLimiter($arrayStore));
testRateLimiting($arrayService);

// Test with Redis driver
echo "\nTesting with Redis driver:\n";
$redisStore   = app('cache')->store('redis');
$redisService = new RateLimitService(new RateLimiter($redisStore));
testRateLimiting($redisService);

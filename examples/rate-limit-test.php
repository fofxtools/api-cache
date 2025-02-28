<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

use Illuminate\Support\Facades\Cache;
use Illuminate\Cache\RateLimiter;

// Create rate limiter
$rateLimiter = new RateLimiter(Cache::driver());
$service     = new RateLimitService($rateLimiter);

// Override settings for testing
config([
    'api-cache.apis.test-client' => [
        'rate_limit_max_attempts'  => 3,
        'rate_limit_decay_seconds' => 5,
    ],
]);

$rateLimitMaxAttempts  = config('api-cache.apis.test-client.rate_limit_max_attempts');
$rateLimitDecaySeconds = config('api-cache.apis.test-client.rate_limit_decay_seconds');

// Test basic rate limiting
$client = 'test-client';
echo "Testing rate limiting for client: {$client}\n";
echo "Configured for {$rateLimitMaxAttempts} attempts per {$rateLimitDecaySeconds} second window\n\n";

// Check initial state
echo 'Initial remaining attempts: ' . $service->getRemainingAttempts($client) . "\n";

// Make some requests
for ($i = 1; $i <= 4; $i++) {
    echo "\nRequest #{$i}:\n";

    if ($service->allowRequest($client)) {
        echo "Request allowed\n";
        $service->incrementAttempts($client);
        echo 'Remaining attempts: ' . $service->getRemainingAttempts($client) . "\n";
    } else {
        $resetTime = $service->getAvailableIn($client);
        echo "Request denied\n";
        echo "Available in: {$resetTime} seconds\n";

        // Wait for rate limit to reset
        if ($resetTime > 0) {
            $waitTime = $resetTime + 1;  // Full second buffer to avoid race conditions
            echo "Waiting {$waitTime} seconds for rate limit to reset...\n";
            sleep($waitTime);

            $remainingAttempts = $service->getRemainingAttempts($client);
            echo 'After reset, remaining attempts: ' . $remainingAttempts . "\n";
        }
    }
}

// Test increment amount
echo "\nTesting increment amount...\n";
$service->incrementAttempts($client, 2); // Increment by 2
echo 'After incrementing by 2, remaining attempts: ' . $service->getRemainingAttempts($client) . "\n";

// Test rate limit clearing
echo "\nClearing rate limits...\n";
$service->clear($client);
echo 'After clear, remaining attempts: ' . $service->getRemainingAttempts($client) . "\n";

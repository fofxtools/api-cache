<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

use Illuminate\Support\Facades\Cache;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RedisStore;

// Check for Redis extension
if (!extension_loaded('redis')) {
    echo "Error: PHP Redis extension is not installed.\n\n";
    echo "To install the Redis extension:\n";
    echo "1. For Windows (using PECL):\n";
    echo "   - Download the appropriate DLL from https://pecl.php.net/package/redis\n";
    echo "   - Add 'extension=php_redis.dll' to your php.ini\n\n";
    echo "2. For Linux:\n";
    echo "   sudo apt-get install php-redis    # Ubuntu/Debian\n";
    echo "   sudo apt-get install phpX.X-redis # Ubuntu/Debian PHP X.X for specific version\n";
    echo "   sudo yum install php-redis        # CentOS/RHEL\n\n";
    echo "3. For macOS:\n";
    echo "   brew install php-redis\n\n";
    echo "After installing, restart your web server and try again.\n";
    exit(1);
}

// Check if Redis server is running
try {
    app('redis.connection')->ping();
    echo "Redis server is running\n";
} catch (\Exception $e) {
    echo "Error: Cannot connect to Redis server.\n\n";
    echo "Please ensure Redis server is running:\n";
    echo "1. For Windows:\n";
    echo "   redis-server --service-install redis.windows.conf --service-name redis\n";
    echo "   (Run this command from the folder where redis-server.exe and redis.windows.conf are located)\n\n";
    echo "   redis-server --service-start --service-name redis\n\n";
    echo "2. For Linux:\n";
    echo "   sudo apt install redis-server\n\n";
    echo "   sudo systemctl start redis-server\n\n";
    echo "3. For macOS:\n";
    echo "   brew services start redis\n\n";
    echo "After starting Redis, try again.\n";
    exit(1);
}

// Configure Redis as the cache driver
config(['cache.default' => 'redis']);

// Set Redis client to predis
config(['database.redis.client' => 'phpredis']);

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
echo "Testing Redis rate limiting for client: {$client}\n";
echo "Configured for {$rateLimitMaxAttempts} attempts per {$rateLimitDecaySeconds} second window\n";

// Check cache driver and store
$driver = Cache::driver();
echo 'Cache driver type: ' . get_class($driver) . "\n";
echo 'Cache store type: ' . get_class($driver->getStore()) . "\n";
echo 'Using Redis driver: ' . ($driver->getStore() instanceof RedisStore ? 'Yes' : 'No') . "\n\n";

// Add delay to allow starting multiple instances
$delay = 10;
echo "Waiting {$delay} seconds to allow starting multiple instances...\n";
echo "To test shared rate limiting, please open another terminal and run this script now.\n";
sleep($delay);

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

// Test shared rate limiting
echo "\nTesting shared rate limiting...\n";
echo "To test shared rate limiting across instances:\n";
echo "1. Run this script in multiple terminals\n";
echo "2. Observe that rate limits are shared between instances\n";
echo '3. Rate limit key in Redis: ' . $service->getRateLimitKey($client) . "\n";

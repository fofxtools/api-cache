# Rate Limiting with Redis

The package uses Redis for distributed rate limiting, allowing rate limits to be shared across multiple application instances. This ensures consistent rate limiting even when your application is running on multiple servers or processes.

## Implementation

Rate limits are stored in Redis using Laravel's cache system. Since the RateLimitService is registered as singleton in this library's service provider, changing the default cache driver would affect all parts of the application. To avoid state cross-contamination between different cache drivers, use named stores:

```php
// Instead of:
Config::set('cache.default', 'array');
$arrayService = new RateLimitService(new RateLimiter(app('cache')->driver()));
Config::set('cache.default', 'redis');
$redisService = new RateLimitService(new RateLimiter(app('cache')->driver()));

// Use:
// Create two services with different cache stores
$arrayStore = app('cache')->store('array');
$arrayService = new RateLimitService(new RateLimiter($arrayStore));

$redisStore = app('cache')->store('redis');
$redisService = new RateLimitService(new RateLimiter($redisStore));

// Each service maintains its own state
$arrayService->incrementAttempts('client1');  // Only affects array store
$redisService->incrementAttempts('client1');  // Only affects Redis store

// Create another Redis service (simulating a different server/process)
$redisService2 = new RateLimitService(new RateLimiter($redisStore));
// Warning: This service shares rate limit state with $redisService
```

This ensures proper isolation between different cache stores while allowing rate limits to be shared across multiple instances using the same Redis store.
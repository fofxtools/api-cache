# API Cache Library

**🚧 Under Construction 🚧**

A Laravel-based PHP library for caching API responses. Currently in early development.

## Requirements

- **PHP 8.3+**
- **Laravel 11.38+**
- **Redis** (for distributed rate limiting)

## Documentation

Please see the [docs](docs) folder for:
- [Technical Specification](docs/technical-specification.md)
- [Usage](docs/usage.md)
- [Code Skeleton](docs/code-skeleton.md)

### Diagrams
- [Class Diagram](docs/diagrams/class-diagram.mmd)
- [Sequence Diagram](docs/diagrams/sequence-diagram.mmd)
- [Workflow Diagram](docs/diagrams/workflow-diagram.mmd)

## Development Setup

1. Clone the repository
2. Install dependencies:
```bash
composer install
```
3. Copy `.env.example` to `.env`
4. For testing the demo API:
```bash
php -S 0.0.0.0:8000 -t public
```

## Features

- API response caching
- Rate limiting with Redis
- Compression support
- Multiple API client support

## Caching Control

The `sendCachedRequest()` method respects the caching settings of the API client. You can control caching behavior using:

```php
// Create a new client instance
$client = new ScraperApiClient();

// Disable caching for a specific request
$client->setUseCache(false);

// Check current caching status
$isCachingEnabled = $client->getUseCache();
echo 'Is caching enabled: ' . ($isCachingEnabled ? 'true' : 'false') . PHP_EOL;

$response = $client->scrape('https://httpbin.org/headers');
echo format_api_response($response, true);

// Re-enable caching
$client->setUseCache(true);
```

## Rate Limiting with Redis

The package uses Redis for distributed rate limiting, allowing rate limits to be shared across multiple application instances. This ensures consistent rate limiting even when your application is running on multiple servers or processes.

### Implementation

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

## Database Migrations

This library takes an unconventional approach to database migrations in order to follow the DRY principle and simplify maintenance across multiple clients, while maintaining consistency between tables.

Instead of duplicating table creation logic in each migration file, we use shared helper functions defined in `src/functions.php`:

- `create_responses_table()`: Creates tables for storing API responses
- `create_pixabay_images_table()`: Creates tables for storing Pixabay image data

Example migration:
```php
public function up(): void
{
    $schema = Schema::connection($this->getConnection());
    create_responses_table($schema, 'api_cache_demo_responses', false);
}
```

## License

MIT 
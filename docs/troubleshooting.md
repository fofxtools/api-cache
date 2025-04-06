# Troubleshooting Guide

This document tracks various issues encountered during development and their solutions. It serves as a reference for both current and future developers working with this codebase.

## Development Issues

### April 2025

#### Cache Driver Data Persistence (4-5-25)
- **Issue**: Data cross-contamination of state across different cache drivers (e.g., array and Redis)
- **Cause**: Laravel's cache repository is bound as a singleton
- **Solution**: Use named stores instead of changing default driver:
  ```php
  // Instead of:
  Config::set('cache.default', 'array');
  $arrayService = new RateLimitService(new RateLimiter(app('cache')->driver()));
  Config::set('cache.default', 'redis');
  $redisService = new RateLimitService(new RateLimiter(app('cache')->driver()));
  
  // Use:
  $arrayStore = app('cache')->store('array');
  $arrayService = new RateLimitService(new RateLimiter($arrayStore));
  $redisStore = app('cache')->store('redis');
  $redisService = new RateLimitService(new RateLimiter($redisStore));
  ```

### March 2025

#### Cookie Issues with Pixabay API (3-16-25)
- **Issue**: Cookies being added to requests with Pixabay API but not with OpenAI.
- **Solution**: Modified `pendingRequest` in `BaseApiClient` to disable cookies:
  ```php
  $this->pendingRequest = Http::withOptions(['cookies' => false]);
  ```
- **Note**: Issue likely related to Pixabay using query parameters for auth instead of headers.

#### HeidiSQL Database Visibility (3-16-25)
- **Issue**: HeidiSQL showing Ubuntu WSL databases instead of Windows ones.
- **Solution**: Added HeidiSQL profiles with Hostname `localhost` for Windows and `127.0.0.1` for WSL
- **Verification**: Run this query to check which database you're connected to:
  ```sql
  SELECT @@hostname, @@version, @@datadir;
  ```

#### Laravel HTTP Client Testing (3-8-25)
- **Issue**: `Http::fake()` state management in tests.
- **Key Points**:
  1. `Http::fake()` affects global state
  2. Objects using HTTP client must be instantiated _after_ setting `Http::fake()`
  3. Changing `Http::fake()` doesn't affect existing objects
- **Solution**: Reinstantiate test objects after each `Http::fake()` call:
  ```php
  Http::fake([...]);
  $this->client = new BaseApiClient(); // Must create new instance
  ```

#### PHPUnit Database Testing (3-8-25)
- **Issue**: PHPUnit not finding database tables
- **Solution**:
  1. Create TestCase extending Orchestra
  2. Implement `defineDatabaseMigrations()`
  3. Use correct import: `use FOfX\ApiCache\Tests\TestCase`
  4. Implement `getPackageProviders()` in test classes
- **Example**:
  ```php
  use FOfX\ApiCache\Tests\TestCase;  // Not Orchestra\Testbench\TestCase
  
  class BaseApiClientTest extends TestCase
  {
      protected function getPackageProviders($app)
      {
          return [/* providers */];
      }
  }
  ```

### February 2025

#### MySQL Index Name Limitations (2-23-25)
- **Issue**: MySQL 64-char index name limit vs SQLite naming constraints
- **Solution**: Modified migration files and `create_responses_table()` function to set index names based on database driver

#### BaseApiClient Refactoring (2-21-25)
- **Change**: Converted from abstract to concrete class
- **Reason**: Simplify testing by avoiding need for concrete child test classes

#### CacheRepository TTL Testing (2-18-25)
- **Issue**: Sporadic unit test failures due to multiple `now()` calls in store()
- **Solution**: Single source of truth for time:
  ```php
  $now = now();
  // Use $now consistently
  ```

#### Architecture Changes (2-18-25)
- **Change**: Made `$clientName` a parameter instead of class property
- **Benefit**: Enabled use of singletons

#### URL Duplication Issue (2-14-25)
- **Issue**: Duplicate "demo-api-server.php/v1" in URLs
- **Solution**: Empty database cache

#### Local Development Server (2-11-25)
- **Issue**: Issues with `php -S localhost:8000`
- **Solution**: Use `php -S 0.0.0.0:8000 -t public`

#### PHPDoc Type Annotations (2-10-25)
- **Issue**: "Undefined method 'shouldReceive'" linting errors
- **Solution**: Add PHPDoc with intersection types:
  ```php
  /** @var \Illuminate\Http\Client\PendingRequest&\Mockery\MockInterface */
  ```
- **Note**: These were linting issues only, not runtime errors

## Best Practices Learned

1. **Testing**
   - Always create fresh instances after `Http::fake()`
   - Use proper TestCase inheritance and provider setup
   - Be mindful of time-sensitive tests

2. **Database**
   - Consider cross-database compatibility (MySQL vs SQLite)
   - Be aware of index naming limitations
   - Maintain clean cache state

3. **Architecture**
   - Prefer concrete classes over abstract when possible
   - Consider singleton compatibility in design
   - Use dependency injection where appropriate

4. **Development Environment**
   - Verify database connections
   - Use appropriate server configurations
   - Keep documentation updated

## Quick Reference

### Common Commands
```bash
# Local development server
php -S 0.0.0.0:8000 -t public

# Check database connection
SELECT @@hostname, @@version, @@datadir;
```

### Useful PHPDoc Examples
```php
/** @var \Illuminate\Http\Client\PendingRequest&\Mockery\MockInterface */
``` 
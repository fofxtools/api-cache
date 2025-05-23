# Troubleshooting Guide

This document tracks various issues encountered during development and their solutions. It serves as a reference for both current and future developers working with this codebase.

## Development Issues

### April 2025

#### URL Matching in Http::fake() Tests (4-25-25)
- **Issue**: In Laravel HTTP client tests, wildcards can cause failures with URL matching for APIs using URL parameters rather than authentication headers.
- **Problem**: When using wildcard URL patterns like `"{$this->apiBaseUrl}/endpoint*"` in `Http::fake()`, URL matching in assertions may not work properly with dynamically generated query strings.
- **Note**: APIs using URL parameters for authentication (like Pixabay, Scrapingdog, YouTube) should use `Str::startsWith()` rather than exact matching to handle the dynamically generated query string.
- **Solution**:
  1. For exact URL matching:
     ```php
     $boolean = $request->url() === "{$this->apiBaseUrl}/endpoint";
     ```
  2. For partial URL matching (needed for APIs with auth params):
     ```php
     use Illuminate\Support\Str;
     
     $boolean = Str::startsWith($request->url(), "{$this->apiBaseUrl}/endpoint");
     ```

#### Adding Credits Column to Response Tables (4-25-25)
- **Issue**: Need to track API credit costs in response tables
- **Implementation**:
  1. Add `credits` column to tables using `create_responses_table()` function:
     ```php
     $table->integer('credits')->nullable();
     $table->index('credits');
     ```
  2. Update parameter chains in these methods:
     - `ApiCacheManager::storeResponse()` - Add `?int $credits = null` parameter and store it
     - `ApiCacheManager::getCachedResponse()` - Include credits in returned data
     - `BaseApiClient::sendRequest()` - Add `$attributes` and `$credits` parameters and return them
     - `BaseApiClient::sendCachedRequest()` - Pass `$attributes` and `$amount` to `sendRequest()`, and `$amount` to `storeResponse()`
     - `CacheRepository::store()` - Add credits to metadata and insert
     - `CacheRepository::get()` - Return credits in response

  3. Unit test fixes required:
     - `ApiCacheManagerTest::apiResponseProvider()`: Added `'credits' => null` to the expected metadata array
     - `BaseApiClientTest::test_sendCachedRequest_handles_custom_amount()`: Updated mock expectation for `storeResponse()` to include the `$amount` parameter
     - `DemoApiClientTest`: 
       - Changed assertions from `$this->assertCount(8, $capturedArgs)` to `$this->assertCount(9, $capturedArgs)`
       - Added assertion `$this->assertEquals($amount, $capturedArgs[8], 'Ninth arg should be amount')`
     - All test cases needed to account for the new parameter order and count

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
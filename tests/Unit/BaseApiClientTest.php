<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\ApiCacheManager;
use FOfX\ApiCache\ApiCacheServiceProvider;
use FOfX\ApiCache\BaseApiClient;
use FOfX\ApiCache\RateLimitException;
use Illuminate\Support\Facades\DB;
use Mockery;
use FOfX\ApiCache\Tests\TestCase;

use function FOfX\ApiCache\check_server_status;

class BaseApiClientTest extends TestCase
{
    protected BaseApiClient $client;

    // Rename to apiBaseUrl to avoid conflict with TestBench $baseUrl
    protected string $apiBaseUrl;

    /** @var ApiCacheManager&Mockery\MockInterface */
    protected ApiCacheManager $cacheManager;

    // Use constant so it can be used in static method data providers
    protected const CLIENT_NAME  = 'demo';
    protected string $clientName = self::CLIENT_NAME;
    protected string $version    = 'v1';

    protected function setUp(): void
    {
        parent::setUp();

        // Get base URL from config
        $baseUrl = config("api-cache.apis.{$this->clientName}.base_url");

        // Set up cache manager mock
        $this->cacheManager = Mockery::mock(ApiCacheManager::class);

        // Create client instance
        $this->client = new BaseApiClient(
            $this->clientName,
            $baseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->cacheManager
        );

        // Enable WSL URL conversion
        $this->client->setWslEnabled(true);

        // Store the base URL (will be WSL-aware if needed)
        $this->apiBaseUrl = $this->client->getBaseUrl();

        // Skip test if server is not accessible
        $serverStatus = check_server_status($this->client->getBaseUrl());
        if (!$serverStatus) {
            $this->markTestSkipped('API server is not accessible at: ' . $this->client->getBaseUrl());
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
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

    public function test_getClientName_returns_correct_name(): void
    {
        $this->assertEquals($this->clientName, $this->client->getClientName());
    }

    public function test_getBaseUrl_returns_correct_url(): void
    {
        $this->assertEquals($this->apiBaseUrl, $this->client->getBaseUrl());
    }

    public function test_getApiKey_returns_correct_key(): void
    {
        $apiKey = config("api-cache.apis.{$this->clientName}.api_key");
        $this->assertEquals($apiKey, $this->client->getApiKey());
    }

    public function test_getVersion_returns_correct_version(): void
    {
        $this->assertEquals($this->version, $this->client->getVersion());
    }

    public function test_getTableName_returns_correct_name(): void
    {
        $sanitizedClientName = str_replace('-', '_', $this->clientName);
        $expectedTable       = 'api_cache_' . $sanitizedClientName . '_responses';

        $this->cacheManager->shouldReceive('getTableName')
            ->once()
            ->with($this->clientName)
            ->andReturn($expectedTable);

        $tableName = $this->client->getTableName($this->clientName);

        $this->assertEquals($expectedTable, $tableName);
    }

    public function test_getTimeout_returns_int(): void
    {
        $this->assertIsInt($this->client->getTimeout());
    }

    public function test_getUseCache_returns_true(): void
    {
        $this->assertTrue($this->client->getUseCache());
    }

    public function test_getCacheManager_returns_cache_manager(): void
    {
        $cacheManager = $this->client->getCacheManager();
        $this->assertInstanceOf(ApiCacheManager::class, $cacheManager);
        $this->assertSame($this->cacheManager, $cacheManager);
    }

    public function test_getAuthHeaders_returns_default_headers(): void
    {
        $headers = $this->client->getAuthHeaders();
        $this->assertEquals([
            'Authorization' => 'Bearer ' . $this->client->getApiKey(),
            'Accept'        => 'application/json',
        ], $headers);
    }

    public function test_getAuthParams_returns_default_params(): void
    {
        $params = $this->client->getAuthParams();
        $this->assertEquals([], $params);
    }

    public function test_setClientName_updates_client_name(): void
    {
        $newName = 'new-client';

        $result = $this->client->setClientName($newName);

        $this->assertSame($this->client, $result, 'Method should return $this for chaining');
        $this->assertEquals($newName, $this->client->getClientName());
    }

    public function test_setBaseUrl_updates_base_url(): void
    {
        $newUrl = 'http://api.newdomain.com/v2';

        $result = $this->client->setBaseUrl($newUrl);

        $this->assertSame($this->client, $result, 'Method should return $this for chaining');
        $this->assertEquals($newUrl, $this->client->getBaseUrl());
    }

    public function test_setApiKey_updates_api_key(): void
    {
        $newKey = 'new-api-key';

        $result = $this->client->setApiKey($newKey);

        $this->assertSame($this->client, $result, 'Method should return $this for chaining');
        $this->assertEquals($newKey, $this->client->getApiKey());
    }

    public function test_setVersion_updates_version(): void
    {
        $newVersion = 'v2';

        $result = $this->client->setVersion($newVersion);

        $this->assertSame($this->client, $result, 'Method should return $this for chaining');
        $this->assertEquals($newVersion, $this->client->getVersion());
    }

    public function test_setWslEnabled_updates_wsl_enabled(): void
    {
        $newWslEnabled = true;

        $result = $this->client->setWslEnabled($newWslEnabled);

        $this->assertSame($this->client, $result, 'Method should return $this for chaining');
        $this->assertEquals($newWslEnabled, $this->client->isWslEnabled());
    }

    public function test_setTimeout_sets_timeout(): void
    {
        $timeout = 2;
        $this->client->setTimeout($timeout);
        $this->assertEquals($timeout, $this->client->getTimeout());
    }

    public function test_setUseCache_updates_use_cache(): void
    {
        $newUseCache = false;
        $this->client->setUseCache($newUseCache);
        $this->assertEquals($newUseCache, $this->client->getUseCache());
    }

    public function test_isWslEnabled_updates_true(): void
    {
        $this->client->setWslEnabled(true);
        $this->assertTrue($this->client->isWslEnabled());
    }

    public function test_isWslEnabled_updates_false(): void
    {
        $this->client->setWslEnabled(false);
        $this->assertFalse($this->client->isWslEnabled());
    }

    public function test_clearRateLimit_called(): void
    {
        // This test only verifies delegation to ApiCacheManager.
        // The actual rate limiting functionality is tested in RateLimitServiceTest
        $this->expectNotToPerformAssertions();

        $this->cacheManager->shouldReceive('clearRateLimit')
            ->once()
            ->with($this->clientName);

        $this->client->clearRateLimit();
    }

    public function test_clearTable_called(): void
    {
        // This test only verifies delegation to CacheRepository.
        // The actual clearing functionality is tested in CacheRepositoryTest
        $this->expectNotToPerformAssertions();

        $this->cacheManager->shouldReceive('clearTable')
            ->once()
            ->with($this->clientName);

        $this->client->clearTable();
    }

    public function test_builds_url_with_leading_slash(): void
    {
        $url = $this->client->buildUrl('/predictions');
        $this->assertEquals($this->apiBaseUrl . '/predictions', $url);
    }

    public function test_builds_url_without_leading_slash(): void
    {
        $url = $this->client->buildUrl('predictions');
        $this->assertEquals($this->apiBaseUrl . '/predictions', $url);
    }

    public function test_builds_url_with_path_suffix(): void
    {
        $url = $this->client->buildUrl('predictions', 'details');
        $this->assertEquals($this->apiBaseUrl . '/predictions/details', $url);
    }

    public function test_builds_url_with_path_suffix_leading_slash(): void
    {
        $url = $this->client->buildUrl('predictions', '/details');
        $this->assertEquals($this->apiBaseUrl . '/predictions/details', $url);
    }

    public function test_calculateCost_returns_null_by_default(): void
    {
        $response = '{"data": {"key": "value"}}';
        $this->assertNull($this->client->calculateCost($response));
        $this->assertNull($this->client->calculateCost(null));
    }

    public function test_calculateCost_can_be_overridden_by_child_class(): void
    {
        // Create anonymous class extending BaseApiClient to test overriding
        $childClient = new class (
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->cacheManager
        ) extends BaseApiClient {
            public function calculateCost(?string $response): ?float
            {
                if ($response === null) {
                    return null;
                }

                // Simple implementation that calculates cost based on response length
                return strlen($response) * 0.001;
            }
        };

        $response     = '{"data": {"key": "value"}}';
        $expectedCost = strlen($response) * 0.001;

        $this->assertEquals($expectedCost, $childClient->calculateCost($response));
        $this->assertNull($childClient->calculateCost(null));
    }

    public function test_shouldCache_returns_true_by_default(): void
    {
        $response = '{"data": {"key": "value"}}';
        $this->assertTrue($this->client->shouldCache($response));
        $this->assertTrue($this->client->shouldCache(null));
    }

    public function test_shouldCache_can_be_overridden_by_child_class(): void
    {
        // Create anonymous class extending BaseApiClient to test overriding
        $childClient = new class (
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->cacheManager
        ) extends BaseApiClient {
            public function shouldCache(?string $responseBody): bool
            {
                if ($responseBody === null) {
                    return false;
                }

                // Only cache responses that contain "success": true
                $data = json_decode($responseBody, true);

                return isset($data['success']) && $data['success'] === true;
            }
        };

        // Test with valid success response
        $successResponse = '{"success": true, "data": {"key": "value"}}';
        $this->assertTrue($childClient->shouldCache($successResponse));

        // Test with error response
        $errorResponse = '{"success": false, "error": "Invalid request"}';
        $this->assertFalse($childClient->shouldCache($errorResponse));

        // Test with null
        $this->assertFalse($childClient->shouldCache(null));

        // Test with malformed JSON
        $invalidJson = '{not valid json}';
        $this->assertFalse($childClient->shouldCache($invalidJson));
    }

    public function test_logApiError_inserts_record_to_db_when_enabled(): void
    {
        config(['api-cache.error_logging.enabled' => true]);
        config(['api-cache.error_logging.log_events.test_error' => true]);
        config(['api-cache.error_logging.levels.test_error' => 'error']);

        $errorType = 'test_error';
        $message   = 'Test error message';
        $context   = ['test' => 'data'];
        $response  = 'Test response body';

        $this->client->logApiError($errorType, $message, $context, $response);

        $this->assertDatabaseHas('api_cache_errors', [
            'api_client'    => $this->clientName,
            'error_type'    => $errorType,
            'log_level'     => 'error',
            'error_message' => $message,
        ]);
    }

    public function test_logApiError_skips_db_insert_when_disabled(): void
    {
        config(['api-cache.error_logging.enabled' => false]);

        $errorType = 'test_error';
        $message   = 'Test error message';

        $this->client->logApiError($errorType, $message);

        $this->assertDatabaseMissing('api_cache_errors', [
            'api_client'    => $this->clientName,
            'error_type'    => $errorType,
            'error_message' => $message,
        ]);
    }

    public function test_logApiError_skips_db_insert_when_event_type_disabled(): void
    {
        config(['api-cache.error_logging.enabled' => true]);
        config(['api-cache.error_logging.log_events.test_error' => false]);

        $errorType = 'test_error';
        $message   = 'Test error message';

        $this->client->logApiError($errorType, $message);

        $this->assertDatabaseMissing('api_cache_errors', [
            'api_client'    => $this->clientName,
            'error_type'    => $errorType,
            'error_message' => $message,
        ]);
    }

    public function test_logHttpError_calls_logApiError_with_correct_parameters(): void
    {
        config(['api-cache.error_logging.enabled' => true]);
        config(['api-cache.error_logging.log_events.http_error' => true]);
        config(['api-cache.error_logging.levels.http_error' => 'error']);

        $statusCode = 404;
        $message    = 'Not found';
        $context    = ['url' => 'https://example.com'];
        $response   = 'Error response body';

        $this->client->logHttpError($statusCode, $message, $context, $response);

        $this->assertDatabaseHas('api_cache_errors', [
            'api_client'    => $this->clientName,
            'error_type'    => 'http_error',
            'log_level'     => 'error',
            'error_message' => $message,
        ]);
    }

    public function test_logCacheRejected_calls_logApiError_with_correct_parameters(): void
    {
        config(['api-cache.error_logging.enabled' => true]);
        config(['api-cache.error_logging.log_events.cache_rejected' => true]);
        config(['api-cache.error_logging.levels.cache_rejected' => 'error']);

        $message  = 'Custom cache rejection reason';
        $context  = ['reason' => 'invalid_format'];
        $response = 'Cache rejected response body';

        $this->client->logCacheRejected($message, $context, $response);

        $this->assertDatabaseHas('api_cache_errors', [
            'api_client'    => $this->clientName,
            'error_type'    => 'cache_rejected',
            'log_level'     => 'error',
            'error_message' => $message,
        ]);
    }

    public function test_logApiError_stores_pretty_printed_context_when_enabled(): void
    {
        config(['api-cache.error_logging.enabled' => true]);
        config(['api-cache.error_logging.log_events.test_error' => true]);
        config(['api-cache.error_logging.levels.test_error' => 'error']);

        $errorType = 'test_error';
        $message   = 'Test error message';
        $context   = ['nested' => ['data' => 'value', 'array' => [1, 2, 3]]];
        $response  = 'Test response body';

        // Test with prettyPrint = true (explicit)
        $this->client->logApiError($errorType, $message, $context, $response, null, true);

        // Verify context_data contains formatted JSON with newlines and indentation
        $record = DB::table('api_cache_errors')->first();
        $this->assertStringContainsString("\n", $record->context_data); // Has newlines
        $this->assertStringContainsString('    ', $record->context_data); // Has indentation
        $this->assertStringContainsString('nested', $record->context_data);
        $this->assertStringContainsString('data', $record->context_data);
    }

    public function test_logApiError_stores_compact_context_when_disabled(): void
    {
        config(['api-cache.error_logging.enabled' => true]);
        config(['api-cache.error_logging.log_events.test_error' => true]);
        config(['api-cache.error_logging.levels.test_error' => 'error']);

        $errorType = 'test_error';
        $message   = 'Test error message';
        $context   = ['nested' => ['data' => 'value', 'array' => [1, 2, 3]]];
        $response  = 'Test response body';

        // Test with prettyPrint = false
        $this->client->logApiError($errorType, $message, $context, $response, null, false);

        // Verify context_data contains compact JSON without newlines
        $record = DB::table('api_cache_errors')->first();
        $this->assertStringNotContainsString("\n", $record->context_data); // No newlines
        $this->assertStringNotContainsString('    ', $record->context_data); // No indentation
        $this->assertStringContainsString('nested', $record->context_data);
        $this->assertStringContainsString('data', $record->context_data);
    }

    public function test_logApiError_uses_pretty_print_by_default(): void
    {
        config(['api-cache.error_logging.enabled' => true]);
        config(['api-cache.error_logging.log_events.test_error' => true]);
        config(['api-cache.error_logging.levels.test_error' => 'error']);

        $errorType = 'test_error';
        $message   = 'Test error message';
        $context   = ['nested' => ['data' => 'value']];

        // Call without specifying prettyPrint parameter (should default to true)
        $this->client->logApiError($errorType, $message, $context);

        // Verify context_data contains formatted JSON (pretty printed by default)
        $record = DB::table('api_cache_errors')->first();
        $this->assertStringContainsString("\n", $record->context_data); // Has newlines
        $this->assertStringContainsString('    ', $record->context_data); // Has indentation
    }

    public function test_logApiError_stores_api_message_when_provided(): void
    {
        config(['api-cache.error_logging.enabled' => true]);
        config(['api-cache.error_logging.log_events.test_error' => true]);
        config(['api-cache.error_logging.levels.test_error' => 'error']);

        $errorType  = 'test_error';
        $message    = 'Test error message';
        $context    = ['test' => 'data'];
        $response   = 'Test response body';
        $apiMessage = 'API specific error message';

        $this->client->logApiError($errorType, $message, $context, $response, $apiMessage, true);

        $this->assertDatabaseHas('api_cache_errors', [
            'api_client'    => $this->clientName,
            'error_type'    => $errorType,
            'log_level'     => 'error',
            'error_message' => $message,
            'api_message'   => $apiMessage,
        ]);
    }

    public function test_logApiError_stores_null_api_message_when_not_provided(): void
    {
        config(['api-cache.error_logging.enabled' => true]);
        config(['api-cache.error_logging.log_events.test_error' => true]);
        config(['api-cache.error_logging.levels.test_error' => 'error']);

        $errorType = 'test_error';
        $message   = 'Test error message';
        $context   = ['test' => 'data'];
        $response  = 'Test response body';

        $this->client->logApiError($errorType, $message, $context, $response);

        $record = DB::table('api_cache_errors')->first();
        $this->assertEquals($this->clientName, $record->api_client);
        $this->assertEquals($errorType, $record->error_type);
        $this->assertEquals($message, $record->error_message);
        $this->assertNull($record->api_message);
    }

    public function test_sendCachedRequest_respects_shouldCache_result(): void
    {
        // Create test client that overrides shouldCache
        $testClient = new class (
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->cacheManager
        ) extends BaseApiClient {
            public bool $shouldCacheResult = false;

            public function shouldCache(?string $responseBody): bool
            {
                return $this->shouldCacheResult;
            }
        };

        // Mock the cacheManager for the test
        $this->cacheManager->shouldReceive('generateCacheKey')
            ->andReturn('test-cache-key');

        $this->cacheManager->shouldReceive('getCachedResponse')
            ->andReturnNull();

        $this->cacheManager->shouldReceive('allowRequest')
            ->andReturnTrue();

        $this->cacheManager->shouldReceive('incrementAttempts')
            ->with($this->clientName, 1);

        // Configure not to cache response (shouldCache returns false)
        $testClient->shouldCacheResult = false;

        // storeResponse should NOT be called when shouldCache returns false
        $this->cacheManager->shouldNotReceive('storeResponse');

        // Make the request
        $testClient->sendCachedRequest('predictions', ['query' => 'test']);

        // Now reconfigure to cache response (shouldCache returns true)
        $testClient->shouldCacheResult = true;

        // storeResponse SHOULD be called when shouldCache returns true
        $this->cacheManager->shouldReceive('storeResponse')
            ->once()
            ->withArgs(function ($client, $key, $params, $result, $endpoint, $version) {
                return $client === $this->clientName &&
                       $key === 'test-cache-key' &&
                       $endpoint === 'predictions';
            });

        // Make another request
        $testClient->sendCachedRequest('predictions', ['query' => 'test']);

        // Assert that the shouldCache() method returns the expected value
        $this->assertEquals(true, $testClient->shouldCache('test'));
    }

    public function test_sendRequest_makes_real_http_call(): void
    {
        $result = $this->client->sendRequest('predictions', ['query' => 'test']);

        $this->assertEquals(200, $result['response']->status());
        $responseData = $result['response']->json();
        $this->assertTrue($responseData['success']);
        $this->assertNotEmpty($responseData['data']);
    }

    public function test_sendRequest_handles_404(): void
    {
        $result = $this->client->sendRequest('nonexistentendpoint');

        $this->assertEquals(404, $result['response']->status());
        $responseData = $result['response']->json();
        $this->assertEquals('error', $responseData['status']);
    }

    public function test_sendCachedRequest_returns_cached_response(): void
    {
        $cachedResponse = ['cached' => 'data'];

        $this->cacheManager->shouldReceive('generateCacheKey')
            ->once()
            ->with($this->clientName, 'predictions', ['query' => 'test'], 'GET', $this->version)
            ->andReturn('test-cache-key');

        $this->cacheManager->shouldReceive('getCachedResponse')
            ->once()
            ->with($this->clientName, 'test-cache-key')
            ->andReturn($cachedResponse);

        $result = $this->client->sendCachedRequest('predictions', ['query' => 'test']);

        $this->assertEquals($cachedResponse, $result);
    }

    public function test_sendCachedRequest_handles_rate_limit(): void
    {
        $availableIn = 60;

        $this->cacheManager->shouldReceive('generateCacheKey')
            ->once()
            ->andReturn('test-key');

        $this->cacheManager->shouldReceive('getCachedResponse')
            ->once()
            ->andReturnNull();

        $this->cacheManager->shouldReceive('allowRequest')
            ->once()
            ->with($this->clientName)
            ->andReturnFalse();

        $this->cacheManager->shouldReceive('getAvailableIn')
            ->once()
            ->with($this->clientName)
            ->andReturn($availableIn);

        $this->expectException(RateLimitException::class);
        $this->expectExceptionMessage("Rate limit exceeded for client '{$this->clientName}'. Available in {$availableIn} seconds.");

        $this->client->sendCachedRequest('predictions', ['query' => 'test']);
    }

    public function test_sendCachedRequest_stores_new_response(): void
    {
        $this->cacheManager->shouldReceive('generateCacheKey')
            ->once()
            ->andReturn('test-cache-key');

        $this->cacheManager->shouldReceive('getCachedResponse')
            ->once()
            ->andReturnNull();

        $this->cacheManager->shouldReceive('allowRequest')
            ->once()
            ->andReturnTrue();

        $this->cacheManager->shouldReceive('incrementAttempts')
            ->once()
            ->with($this->clientName, 1);

        $params = ['query' => 'test'];
        $this->cacheManager->shouldReceive('storeResponse')
            ->once()
            ->withArgs(function ($client, $key, $params, $result, $endpoint, $version) {
                return $client === $this->clientName &&
                       $key === 'test-cache-key' &&
                       $params === ['query' => 'test'] &&
                       $endpoint === 'predictions' &&
                       $version === $this->version &&
                       isset($result['response']) &&
                       isset($result['response_time']);
            });

        $result = $this->client->sendCachedRequest('predictions', $params);

        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('response_time', $result);
    }

    public function test_sendCachedRequest_bypasses_cache_when_disabled(): void
    {
        // Disable caching
        $this->client->setUseCache(false);

        // Cache-specific methods should not be called
        $this->cacheManager->shouldNotReceive('getCachedResponse');
        $this->cacheManager->shouldNotReceive('storeResponse');

        // Rate limiting should still be checked
        $this->cacheManager->shouldReceive('allowRequest')
            ->once()
            ->with($this->clientName)
            ->andReturnTrue();

        $this->cacheManager->shouldReceive('incrementAttempts')
            ->once()
            ->with($this->clientName, 1);

        // Cache key is still needed for rate limiting
        $this->cacheManager->shouldReceive('generateCacheKey')
            ->once()
            ->andReturn('test-cache-key');

        // Make request with cache disabled
        $result = $this->client->sendCachedRequest('predictions', ['query' => 'test']);

        // Verify it's a direct request
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('response_time', $result);
        $this->assertFalse($result['is_cached']);

        // Verify the setting persists
        $this->assertFalse($this->client->getUseCache());
    }

    public function test_sendCachedRequest_handles_custom_amount(): void
    {
        $originalAmount = 10;
        $amount         = 5;
        $attributes     = 'test-attributes';

        $this->cacheManager->shouldReceive('generateCacheKey')
            ->once()
            ->andReturn('test-key');

        $this->cacheManager->shouldReceive('getCachedResponse')
            ->once()
            ->andReturnNull();

        $this->cacheManager->shouldReceive('allowRequest')
            ->once()
            ->with($this->clientName)
            ->andReturnTrue();

        // Mock getRemainingAttempts to return different values before and after
        $this->cacheManager->shouldReceive('getRemainingAttempts')
            ->once()
            ->with($this->clientName)
            ->andReturn($originalAmount)
            ->ordered();

        $this->cacheManager->shouldReceive('incrementAttempts')
            ->once()
            ->with($this->clientName, $amount)
            ->ordered();

        $this->cacheManager->shouldReceive('getRemainingAttempts')
            ->once()
            ->with($this->clientName)
            ->andReturn($originalAmount - $amount)
            ->ordered();

        $this->cacheManager->shouldReceive('storeResponse')
            ->once()
            ->with($this->clientName, 'test-key', ['query' => 'test'], Mockery::any(), 'predictions', $this->version, null, $attributes, $amount)
            ->andReturn(true);

        // Get remaining attempts before
        $beforeAttempts = $this->cacheManager->getRemainingAttempts($this->clientName);

        $result = $this->client->sendCachedRequest('predictions', ['query' => 'test'], 'GET', $attributes, $amount);

        // Get remaining attempts after
        $afterAttempts = $this->cacheManager->getRemainingAttempts($this->clientName);

        // Verify the rate limit was decremented by $amount
        $this->assertEquals($originalAmount - $amount, $beforeAttempts - $afterAttempts);
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('response_time', $result);
    }

    public function test_sendCachedRequest_logs_and_rethrows_connection_exception(): void
    {
        config(['api-cache.error_logging.enabled' => true]);
        config(['api-cache.error_logging.log_events.http_error' => true]);
        config(['api-cache.error_logging.levels.http_error' => 'error']);

        $this->cacheManager->shouldReceive('generateCacheKey')
            ->once()
            ->andReturn('test-cache-key');

        $this->cacheManager->shouldReceive('getCachedResponse')
            ->once()
            ->andReturnNull();

        $this->cacheManager->shouldReceive('allowRequest')
            ->once()
            ->with($this->clientName)
            ->andReturnTrue();

        // Create a test client that will throw ConnectionException
        $testClient = new class (
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->cacheManager
        ) extends BaseApiClient {
            public function sendRequest(string $endpoint, array $params = [], string $method = 'GET', ?string $attributes = null, ?int $credits = null): array
            {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 6: Could not resolve host: non-existent-host.com');
            }
        };

        $this->expectException(\Illuminate\Http\Client\ConnectionException::class);
        $this->expectExceptionMessage('cURL error 6: Could not resolve host: non-existent-host.com');

        try {
            $testClient->sendCachedRequest('predictions', ['query' => 'test']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Verify the error was logged to the database
            $this->assertDatabaseHas('api_cache_errors', [
                'api_client'    => $this->clientName,
                'error_type'    => 'http_error',
                'log_level'     => 'error',
                'error_message' => 'Connection error: cURL error 6: Could not resolve host: non-existent-host.com',
            ]);

            // Verify context data contains expected fields
            $errorRecord = DB::table('api_cache_errors')
                ->where('api_client', $this->clientName)
                ->where('error_type', 'http_error')
                ->latest('created_at')
                ->first();

            $contextData = json_decode($errorRecord->context_data, true);
            $this->assertEquals(0, $contextData['status_code']);
            $this->assertEquals('GET', $contextData['method']); // sendCachedRequest defaults to GET
            $this->assertEquals('test-cache-key', $contextData['cache_key']);
            $this->assertEquals('connection_error', $contextData['error_type']);
            $this->assertStringContainsString('/predictions', $contextData['url']);

            // Re-throw to satisfy expectException
            throw $e;
        }
    }

    public function test_sendCachedRequest_logs_and_rethrows_request_exception(): void
    {
        config(['api-cache.error_logging.enabled' => true]);
        config(['api-cache.error_logging.log_events.http_error' => true]);
        config(['api-cache.error_logging.levels.http_error' => 'error']);

        $this->cacheManager->shouldReceive('generateCacheKey')
            ->once()
            ->andReturn('test-cache-key');

        $this->cacheManager->shouldReceive('getCachedResponse')
            ->once()
            ->andReturnNull();

        $this->cacheManager->shouldReceive('allowRequest')
            ->once()
            ->with($this->clientName)
            ->andReturnTrue();

        // Create a test client that will make a request to demo server's 500 endpoint
        $testClient = new class (
            $this->clientName,
            $this->apiBaseUrl, // Use local demo server
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->cacheManager
        ) extends BaseApiClient {
            public function sendRequest(string $endpoint, array $params = [], string $method = 'GET', ?string $attributes = null, ?int $credits = null): array
            {
                // Make a request that will return a 500 status code using demo server's status endpoint
                $result = parent::sendRequest('500', $params, $method, $attributes, $credits);

                // Force throw RequestException for 500 status
                if ($result['response']->status() >= 400) {
                    $result['response']->throw();
                }

                return $result;
            }
        };

        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        try {
            $testClient->sendCachedRequest('predictions', ['query' => 'test']);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            // Verify the error was logged to the database
            $this->assertDatabaseHas('api_cache_errors', [
                'api_client' => $this->clientName,
                'error_type' => 'http_error',
                'log_level'  => 'error',
            ]);

            // Verify context data contains expected fields
            $errorRecord = DB::table('api_cache_errors')
                ->where('api_client', $this->clientName)
                ->where('error_type', 'http_error')
                ->latest('created_at')
                ->first();

            $contextData = json_decode($errorRecord->context_data, true);
            $this->assertEquals(500, $contextData['status_code']);
            $this->assertEquals('GET', $contextData['method']);
            $this->assertEquals('test-cache-key', $contextData['cache_key']);
            $this->assertEquals('request_error', $contextData['error_type']);
            $this->assertStringContainsString('predictions', $contextData['url']);

            // Verify some response body was logged (demo server returns JSON for /500)
            $this->assertNotNull($errorRecord->response_preview);
            $this->assertStringContainsString('HTTP request error:', $errorRecord->error_message);

            // Re-throw to satisfy expectException
            throw $e;
        }
    }

    public function test_getHealth_returns_health_endpoint_response(): void
    {
        $result = $this->client->getHealth();

        $this->assertEquals(200, $result['response']->status());
        $this->assertArrayHasKey('status', $result['response']->json());
        $this->assertEquals('OK', $result['response']->json()['status']);
    }
}

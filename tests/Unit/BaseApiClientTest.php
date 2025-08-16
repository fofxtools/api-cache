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
    protected ApiCacheManager $mockCacheManager;
    protected ApiCacheManager $realCacheManager;

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
        $this->mockCacheManager = Mockery::mock(ApiCacheManager::class);

        // Set up real cache manager
        $this->realCacheManager = app(ApiCacheManager::class);

        // Create client instance
        $this->client = new BaseApiClient(
            $this->clientName,
            $baseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->mockCacheManager
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

        $this->mockCacheManager->shouldReceive('getTableName')
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
        $this->assertSame($this->mockCacheManager, $cacheManager);
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

        $this->mockCacheManager->shouldReceive('clearRateLimit')
            ->once()
            ->with($this->clientName);

        $this->client->clearRateLimit();
    }

    public function test_clearTable_called(): void
    {
        // This test only verifies delegation to CacheRepository.
        // The actual clearing functionality is tested in CacheRepositoryTest
        $this->expectNotToPerformAssertions();

        $this->mockCacheManager->shouldReceive('clearTable')
            ->once()
            ->with($this->clientName);

        $this->client->clearTable();
    }

    public function test_resetProcessed_updates_processed_columns_to_null(): void
    {
        $tableName = 'api_cache_' . str_replace('-', '_', $this->clientName) . '_responses';

        $this->mockCacheManager->shouldReceive('getTableName')
            ->once()
            ->with($this->clientName)
            ->andReturn($tableName);

        // Insert some test data with processed_at and processed_status values
        DB::table($tableName)->insert([
            'key'                  => 'test-key-1',
            'client'               => $this->clientName,
            'response_status_code' => 200,
            'response_body'        => '{"test": "data1"}',
            'processed_at'         => now(),
            'processed_status'     => 'completed',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        DB::table($tableName)->insert([
            'key'                  => 'test-key-2',
            'client'               => $this->clientName,
            'response_status_code' => 200,
            'response_body'        => '{"test": "data2"}',
            'processed_at'         => now(),
            'processed_status'     => 'failed',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        // Call resetProcessed
        $this->client->resetProcessed();

        // Verify all processed_at and processed_status columns are now null
        $records = DB::table($tableName)->get();

        foreach ($records as $record) {
            $this->assertNull($record->processed_at);
            $this->assertNull($record->processed_status);
        }
    }

    public function test_resetProcessed_with_endpoint_filter(): void
    {
        $tableName = 'api_cache_' . str_replace('-', '_', $this->clientName) . '_responses';

        $this->mockCacheManager->shouldReceive('getTableName')
            ->once()
            ->with($this->clientName)
            ->andReturn($tableName);

        // Insert test data with different endpoints
        DB::table($tableName)->insert([
            'key'                  => 'test-key-1',
            'client'               => $this->clientName,
            'endpoint'             => 'api/search',
            'response_status_code' => 200,
            'response_body'        => '{"test": "data1"}',
            'processed_at'         => now(),
            'processed_status'     => 'completed',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        DB::table($tableName)->insert([
            'key'                  => 'test-key-2',
            'client'               => $this->clientName,
            'endpoint'             => 'api/details',
            'response_status_code' => 200,
            'response_body'        => '{"test": "data2"}',
            'processed_at'         => now(),
            'processed_status'     => 'failed',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        DB::table($tableName)->insert([
            'key'                  => 'test-key-3',
            'client'               => $this->clientName,
            'endpoint'             => 'api/search',
            'response_status_code' => 200,
            'response_body'        => '{"test": "data3"}',
            'processed_at'         => now(),
            'processed_status'     => 'pending',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        // Call resetProcessed with endpoint filter
        $this->client->resetProcessed('api/search');

        // Verify only 'api/search' endpoint rows were reset
        $searchRecords = DB::table($tableName)->where('endpoint', 'api/search')->get();
        foreach ($searchRecords as $record) {
            $this->assertNull($record->processed_at);
            $this->assertNull($record->processed_status);
        }

        // Verify 'api/details' endpoint row was NOT reset
        $detailsRecord = DB::table($tableName)->where('endpoint', 'api/details')->first();
        $this->assertNotNull($detailsRecord->processed_at);
        $this->assertNotNull($detailsRecord->processed_status);
        $this->assertEquals('failed', $detailsRecord->processed_status);
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
            $this->mockCacheManager
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
            $this->mockCacheManager
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
            $this->mockCacheManager
        ) extends BaseApiClient {
            public bool $shouldCacheResult = false;

            public function shouldCache(?string $responseBody): bool
            {
                return $this->shouldCacheResult;
            }
        };

        // Mock the mockCacheManager for the test
        $this->mockCacheManager->shouldReceive('generateCacheKey')
            ->andReturn('test-cache-key');

        $this->mockCacheManager->shouldReceive('getCachedResponse')
            ->andReturnNull();

        $this->mockCacheManager->shouldReceive('allowRequest')
            ->andReturnTrue();

        $this->mockCacheManager->shouldReceive('incrementAttempts')
            ->with($this->clientName, 1);

        // Configure not to cache response (shouldCache returns false)
        $testClient->shouldCacheResult = false;

        // storeResponse should NOT be called when shouldCache returns false
        $this->mockCacheManager->shouldNotReceive('storeResponse');

        // Make the request
        $testClient->sendCachedRequest('predictions', ['query' => 'test']);

        // Now reconfigure to cache response (shouldCache returns true)
        $testClient->shouldCacheResult = true;

        // storeResponse SHOULD be called when shouldCache returns true
        $this->mockCacheManager->shouldReceive('storeResponse')
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

        $this->mockCacheManager->shouldReceive('generateCacheKey')
            ->once()
            ->with($this->clientName, 'predictions', ['query' => 'test'], 'GET', $this->version)
            ->andReturn('test-cache-key');

        $this->mockCacheManager->shouldReceive('getCachedResponse')
            ->once()
            ->with($this->clientName, 'test-cache-key')
            ->andReturn($cachedResponse);

        $result = $this->client->sendCachedRequest('predictions', ['query' => 'test']);

        $this->assertEquals($cachedResponse, $result);
    }

    public function test_sendCachedRequest_handles_rate_limit(): void
    {
        $availableIn = 60;

        $this->mockCacheManager->shouldReceive('generateCacheKey')
            ->once()
            ->andReturn('test-key');

        $this->mockCacheManager->shouldReceive('getCachedResponse')
            ->once()
            ->andReturnNull();

        $this->mockCacheManager->shouldReceive('allowRequest')
            ->once()
            ->with($this->clientName)
            ->andReturnFalse();

        $this->mockCacheManager->shouldReceive('getAvailableIn')
            ->once()
            ->with($this->clientName)
            ->andReturn($availableIn);

        $this->expectException(RateLimitException::class);
        $this->expectExceptionMessage("Rate limit exceeded for client '{$this->clientName}'. Available in {$availableIn} seconds.");

        $this->client->sendCachedRequest('predictions', ['query' => 'test']);
    }

    public function test_sendCachedRequest_stores_new_response(): void
    {
        $this->mockCacheManager->shouldReceive('generateCacheKey')
            ->once()
            ->andReturn('test-cache-key');

        $this->mockCacheManager->shouldReceive('getCachedResponse')
            ->once()
            ->andReturnNull();

        $this->mockCacheManager->shouldReceive('allowRequest')
            ->once()
            ->andReturnTrue();

        $this->mockCacheManager->shouldReceive('incrementAttempts')
            ->once()
            ->with($this->clientName, 1);

        $params = ['query' => 'test'];
        $this->mockCacheManager->shouldReceive('storeResponse')
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
        $this->mockCacheManager->shouldNotReceive('getCachedResponse');
        $this->mockCacheManager->shouldNotReceive('storeResponse');

        // Rate limiting should still be checked
        $this->mockCacheManager->shouldReceive('allowRequest')
            ->once()
            ->with($this->clientName)
            ->andReturnTrue();

        $this->mockCacheManager->shouldReceive('incrementAttempts')
            ->once()
            ->with($this->clientName, 1);

        // Cache key is still needed for rate limiting
        $this->mockCacheManager->shouldReceive('generateCacheKey')
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

        $this->mockCacheManager->shouldReceive('generateCacheKey')
            ->once()
            ->andReturn('test-key');

        $this->mockCacheManager->shouldReceive('getCachedResponse')
            ->once()
            ->andReturnNull();

        $this->mockCacheManager->shouldReceive('allowRequest')
            ->once()
            ->with($this->clientName)
            ->andReturnTrue();

        // Mock getRemainingAttempts to return different values before and after
        $this->mockCacheManager->shouldReceive('getRemainingAttempts')
            ->once()
            ->with($this->clientName)
            ->andReturn($originalAmount)
            ->ordered();

        $this->mockCacheManager->shouldReceive('incrementAttempts')
            ->once()
            ->with($this->clientName, $amount)
            ->ordered();

        $this->mockCacheManager->shouldReceive('getRemainingAttempts')
            ->once()
            ->with($this->clientName)
            ->andReturn($originalAmount - $amount)
            ->ordered();

        $this->mockCacheManager->shouldReceive('storeResponse')
            ->once()
            ->with($this->clientName, 'test-key', ['query' => 'test'], Mockery::any(), 'predictions', $this->version, null, $attributes, $amount)
            ->andReturn(true);

        // Get remaining attempts before
        $beforeAttempts = $this->mockCacheManager->getRemainingAttempts($this->clientName);

        $result = $this->client->sendCachedRequest('predictions', ['query' => 'test'], 'GET', $attributes, $amount);

        // Get remaining attempts after
        $afterAttempts = $this->mockCacheManager->getRemainingAttempts($this->clientName);

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

        $this->mockCacheManager->shouldReceive('generateCacheKey')
            ->once()
            ->andReturn('test-cache-key');

        $this->mockCacheManager->shouldReceive('getCachedResponse')
            ->once()
            ->andReturnNull();

        $this->mockCacheManager->shouldReceive('allowRequest')
            ->once()
            ->with($this->clientName)
            ->andReturnTrue();

        // Create a test client that will throw ConnectionException
        $testClient = new class (
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->mockCacheManager
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

        $this->mockCacheManager->shouldReceive('generateCacheKey')
            ->once()
            ->andReturn('test-cache-key');

        $this->mockCacheManager->shouldReceive('getCachedResponse')
            ->once()
            ->andReturnNull();

        $this->mockCacheManager->shouldReceive('allowRequest')
            ->once()
            ->with($this->clientName)
            ->andReturnTrue();

        // Create a test client that will make a request to demo server's 500 endpoint
        $testClient = new class (
            $this->clientName,
            $this->apiBaseUrl, // Use local demo server
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->mockCacheManager
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

    public function test_updateProcessedStatus_updates_database_record(): void
    {
        $tableName = 'api_cache_' . str_replace('-', '_', $this->clientName) . '_responses';

        $this->mockCacheManager->shouldReceive('getTableName')
            ->once()
            ->with($this->clientName)
            ->andReturn($tableName);

        // Insert test data
        $testId = DB::table($tableName)->insertGetId([
            'key'                  => 'test-update-status',
            'client'               => $this->clientName,
            'endpoint'             => 'test',
            'attributes'           => 'https://example.com/test',
            'request_headers'      => json_encode([]),
            'request_body'         => '',
            'response_status_code' => 200,
            'response_headers'     => json_encode([]),
            'response_body'        => '<html>Test content</html>',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $status = [
            'status'    => 'OK',
            'error'     => null,
            'filename'  => 'test-file.html',
            'file_size' => 1234,
        ];

        // Call the actual method
        $this->client->updateProcessedStatus($testId, $status);

        // Verify database changes
        $record = DB::table($tableName)->where('id', $testId)->first();
        $this->assertNotNull($record->processed_at);
        $this->assertNotNull($record->processed_status);

        $decodedStatus = json_decode($record->processed_status, true);
        $this->assertEquals('OK', $decodedStatus['status']);
        $this->assertNull($decodedStatus['error']);
        $this->assertEquals('test-file.html', $decodedStatus['filename']);
        $this->assertEquals(1234, $decodedStatus['file_size']);
    }

    public function test_batchUpdateProcessedStatus_updates_multiple_records(): void
    {
        $tableName = 'api_cache_' . str_replace('-', '_', $this->clientName) . '_responses';

        $this->mockCacheManager->shouldReceive('getTableName')
            ->twice()
            ->with($this->clientName)
            ->andReturn($tableName);

        // Insert test data
        $testId1 = DB::table($tableName)->insertGetId([
            'key'                  => 'test-batch-1',
            'client'               => $this->clientName,
            'endpoint'             => 'test',
            'attributes'           => 'https://example.com/test1',
            'request_headers'      => json_encode([]),
            'request_body'         => '',
            'response_status_code' => 200,
            'response_headers'     => json_encode([]),
            'response_body'        => '<html>Test content 1</html>',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $testId2 = DB::table($tableName)->insertGetId([
            'key'                  => 'test-batch-2',
            'client'               => $this->clientName,
            'endpoint'             => 'test',
            'attributes'           => 'https://example.com/test2',
            'request_headers'      => json_encode([]),
            'request_body'         => '',
            'response_status_code' => 200,
            'response_headers'     => json_encode([]),
            'response_body'        => '<html>Test content 2</html>',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $updates = [
            [
                'id'     => $testId1,
                'status' => [
                    'status'    => 'OK',
                    'error'     => null,
                    'filename'  => 'file1.html',
                    'file_size' => 1111,
                ],
            ],
            [
                'id'     => $testId2,
                'status' => [
                    'status'    => 'Skipped',
                    'error'     => null,
                    'filename'  => 'file2.html',
                    'file_size' => null,
                ],
            ],
        ];

        // Call the actual method
        $this->client->batchUpdateProcessedStatus($updates);

        // Verify both records were updated
        $record1 = DB::table($tableName)->where('id', $testId1)->first();
        $record2 = DB::table($tableName)->where('id', $testId2)->first();

        $this->assertNotNull($record1->processed_at);
        $this->assertNotNull($record1->processed_status);
        $this->assertNotNull($record2->processed_at);
        $this->assertNotNull($record2->processed_status);

        $status1 = json_decode($record1->processed_status, true);
        $status2 = json_decode($record2->processed_status, true);

        $this->assertEquals('OK', $status1['status']);
        $this->assertEquals('file1.html', $status1['filename']);
        $this->assertEquals(1111, $status1['file_size']);

        $this->assertEquals('Skipped', $status2['status']);
        $this->assertEquals('file2.html', $status2['filename']);
        $this->assertNull($status2['file_size']);
    }

    public function test_resolveFilenameSlugSource_returns_attributes(): void
    {
        $row = (object) [
            'id'         => 123,
            'attributes' => 'https://example.com/test-page',
            'endpoint'   => 'test',
        ];

        $result = $this->client->resolveFilenameSlugSource($row);

        $this->assertEquals('https://example.com/test-page', $result);
    }

    public function test_generateFilename_with_normal_url(): void
    {
        $row = (object) ['id' => 123, 'attributes' => 'https://example.com/test-page'];

        $result = $this->client->generateFilename($row, 180);

        $this->assertEquals('123-httpsexamplecomtest-page.html', $result);
    }

    public function test_generateFilename_with_empty_attributes(): void
    {
        $row = (object) ['id' => 456, 'attributes' => ''];

        $result = $this->client->generateFilename($row, 180);

        $this->assertEquals('456.html', $result);
    }

    public function test_generateFilename_with_null_attributes(): void
    {
        $row = (object) ['id' => 789, 'attributes' => null];

        $result = $this->client->generateFilename($row, 180);

        $this->assertEquals('789.html', $result);
    }

    public function test_generateFilename_with_long_url_truncated(): void
    {
        $longUrl = 'https://example.com/' . str_repeat('very-long-path-segment-', 20);
        $row     = (object) ['id' => 999, 'attributes' => $longUrl];

        $result = $this->client->generateFilename($row, 50);

        $expectedSlug = substr(\Illuminate\Support\Str::slug($longUrl), 0, 50);
        $expected     = '999-' . $expectedSlug . '.html';

        $this->assertEquals($expected, $result);
        $this->assertLessThanOrEqual(50 + 4 + strlen('999-') + strlen('.html'), strlen($result)); // 50 chars + ID + dash + extension
    }

    public function test_saveResponseBodyToFile_saves_response_body_to_file(): void
    {
        // Create client with REAL dependencies for file operations
        $realClient = new BaseApiClient(
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->realCacheManager
        );

        // Get real table name and ensure table exists
        $tableName = $this->realCacheManager->getTableName($this->clientName);

        // Insert test data with response body
        $testResponseBody = '<html><head><title>Test Page</title></head><body><h1>Hello World</h1><p>This is test content.</p></body></html>';

        $testId = DB::table($tableName)->insertGetId([
            'key'                  => 'test-save-response-body',
            'client'               => $this->clientName,
            'endpoint'             => 'test-endpoint',
            'attributes'           => 'https://example.com/test-page',
            'request_headers'      => json_encode(['User-Agent' => 'Test']),
            'request_body'         => '',
            'response_status_code' => 200,
            'response_headers'     => json_encode(['Content-Type' => 'text/html']),
            'response_body'        => $testResponseBody,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $testDir = 'storage/app/test_file_save';

        // Call the method to save files for the test endpoint
        $stats = $realClient->saveResponseBodyToFile(10, 'test-endpoint', $testDir);

        // Verify statistics
        $this->assertEquals(1, $stats['processed'], 'Should process 1 record');
        $this->assertEquals(0, $stats['errors'], 'Should have no errors');
        $this->assertEquals(0, $stats['skipped'], 'Should skip no files');

        // Verify file was created with expected content
        $expectedFilename = $testId . '-httpsexamplecomtest-page.html';
        $expectedFilePath = base_path($testDir . '/' . $expectedFilename);

        $this->assertFileExists($expectedFilePath, 'Response body file should be created');

        $actualFileContent = file_get_contents($expectedFilePath);
        $this->assertEquals($testResponseBody, $actualFileContent, 'File content should match response body');

        // Verify specific content is in the file
        $this->assertStringContainsString('<title>Test Page</title>', $actualFileContent);
        $this->assertStringContainsString('<h1>Hello World</h1>', $actualFileContent);
        $this->assertStringContainsString('This is test content.', $actualFileContent);

        // Verify database was updated with processing status
        $record = DB::table($tableName)->where('id', $testId)->first();
        $this->assertNotNull($record->processed_at, 'processed_at should be set');
        $this->assertNotNull($record->processed_status, 'processed_status should be set');

        $status = json_decode($record->processed_status, true);
        $this->assertEquals('OK', $status['status'], 'Status should be OK');
        $this->assertStringContainsString($expectedFilename, $status['filename'], 'Filename should be recorded');
        $this->assertIsInt($status['file_size'], 'File size should be recorded');
        $this->assertGreaterThan(0, $status['file_size'], 'File size should be greater than 0');

        // Clean up
        if (file_exists($expectedFilePath)) {
            unlink($expectedFilePath);
        }
        if (is_dir(base_path($testDir))) {
            rmdir(base_path($testDir));
        }
    }

    public function test_saveResponseBodyToFile_skips_existing_files_when_overwrite_disabled(): void
    {
        $realClient = new BaseApiClient(
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->realCacheManager
        );

        $tableName = $this->realCacheManager->getTableName($this->clientName);
        $testDir   = 'storage/app/test_skip_files';

        // Insert test data
        $testId = DB::table($tableName)->insertGetId([
            'key'                  => 'test-skip-existing',
            'client'               => $this->clientName,
            'endpoint'             => 'skip-test',
            'attributes'           => 'https://example.com/skip-page',
            'request_headers'      => json_encode([]),
            'request_body'         => '',
            'response_status_code' => 200,
            'response_headers'     => json_encode([]),
            'response_body'        => '<html>New content</html>',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        // Create existing file
        $expectedFilename = $testId . '-httpsexamplecomskip-page.html';
        $expectedFilePath = base_path($testDir . '/' . $expectedFilename);

        if (!is_dir(base_path($testDir))) {
            mkdir(base_path($testDir), 0755, true);
        }
        file_put_contents($expectedFilePath, 'Original content');

        // Call method with overwriteExisting = false (default)
        $stats = $realClient->saveResponseBodyToFile(10, 'skip-test', $testDir, false);

        // Verify statistics
        $this->assertEquals(0, $stats['processed'], 'Should process 0 records');
        $this->assertEquals(0, $stats['errors'], 'Should have no errors');
        $this->assertEquals(1, $stats['skipped'], 'Should skip 1 file');

        // Verify file content wasn't changed
        $this->assertEquals('Original content', file_get_contents($expectedFilePath));

        // Verify database status shows skipped
        $record = DB::table($tableName)->where('id', $testId)->first();
        $this->assertNotNull($record->processed_status);

        $status = json_decode($record->processed_status, true);
        $this->assertEquals('Skipped', $status['status']);

        // Clean up
        if (file_exists($expectedFilePath)) {
            unlink($expectedFilePath);
        }
        if (is_dir(base_path($testDir))) {
            rmdir(base_path($testDir));
        }
    }

    public function test_saveResponseBodyToFile_overwrites_existing_files_when_enabled(): void
    {
        $realClient = new BaseApiClient(
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->realCacheManager
        );

        $tableName = $this->realCacheManager->getTableName($this->clientName);
        $testDir   = 'storage/app/test_overwrite_files';

        // Insert test data
        $testResponseBody = '<html>Updated content</html>';
        $testId           = DB::table($tableName)->insertGetId([
            'key'                  => 'test-overwrite-existing',
            'client'               => $this->clientName,
            'endpoint'             => 'overwrite-test',
            'attributes'           => 'https://example.com/overwrite-page',
            'request_headers'      => json_encode([]),
            'request_body'         => '',
            'response_status_code' => 200,
            'response_headers'     => json_encode([]),
            'response_body'        => $testResponseBody,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        // Create existing file
        $expectedFilename = $testId . '-httpsexamplecomoverwrite-page.html';
        $expectedFilePath = base_path($testDir . '/' . $expectedFilename);

        if (!is_dir(base_path($testDir))) {
            mkdir(base_path($testDir), 0755, true);
        }
        file_put_contents($expectedFilePath, 'Original content');

        // Call method with overwriteExisting = true
        $stats = $realClient->saveResponseBodyToFile(10, 'overwrite-test', $testDir, true);

        // Verify statistics
        $this->assertEquals(1, $stats['processed'], 'Should process 1 record');
        $this->assertEquals(0, $stats['errors'], 'Should have no errors');
        $this->assertEquals(0, $stats['skipped'], 'Should skip no files');

        // Verify file content was updated
        $this->assertEquals($testResponseBody, file_get_contents($expectedFilePath));

        // Verify database status shows processed
        $record = DB::table($tableName)->where('id', $testId)->first();
        $status = json_decode($record->processed_status, true);
        $this->assertEquals('OK', $status['status']);

        // Clean up
        if (file_exists($expectedFilePath)) {
            unlink($expectedFilePath);
        }
        if (is_dir(base_path($testDir))) {
            rmdir(base_path($testDir));
        }
    }

    public function test_saveResponseBodyToFile_filters_by_endpoint(): void
    {
        $realClient = new BaseApiClient(
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->realCacheManager
        );

        $tableName = $this->realCacheManager->getTableName($this->clientName);
        $testDir   = 'storage/app/test_endpoint_filter';

        // Insert records with different endpoints
        $testId1 = DB::table($tableName)->insertGetId([
            'key'                  => 'test-endpoint-1',
            'client'               => $this->clientName,
            'endpoint'             => 'target-endpoint',
            'attributes'           => 'https://example.com/target',
            'request_headers'      => json_encode([]),
            'request_body'         => '',
            'response_status_code' => 200,
            'response_headers'     => json_encode([]),
            'response_body'        => '<html>Target content</html>',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $testId2 = DB::table($tableName)->insertGetId([
            'key'                  => 'test-endpoint-2',
            'client'               => $this->clientName,
            'endpoint'             => 'other-endpoint',
            'attributes'           => 'https://example.com/other',
            'request_headers'      => json_encode([]),
            'request_body'         => '',
            'response_status_code' => 200,
            'response_headers'     => json_encode([]),
            'response_body'        => '<html>Other content</html>',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        // Call method filtering for 'target-endpoint' only
        $stats = $realClient->saveResponseBodyToFile(10, 'target-endpoint', $testDir);

        // Verify statistics - should only process the target endpoint
        $this->assertEquals(1, $stats['processed'], 'Should process 1 record');
        $this->assertEquals(0, $stats['errors'], 'Should have no errors');
        $this->assertEquals(0, $stats['skipped'], 'Should skip no files');

        // Verify only the target file was created
        $targetFile = base_path($testDir . '/' . $testId1 . '-httpsexamplecomtarget.html');
        $otherFile  = base_path($testDir . '/' . $testId2 . '-httpsexamplecomother.html');

        $this->assertFileExists($targetFile, 'Target endpoint file should exist');
        $this->assertFileDoesNotExist($otherFile, 'Other endpoint file should not exist');

        $this->assertEquals('<html>Target content</html>', file_get_contents($targetFile));

        // Clean up
        if (file_exists($targetFile)) {
            unlink($targetFile);
        }
        if (file_exists($otherFile)) {
            unlink($otherFile);
        }
        if (is_dir(base_path($testDir))) {
            rmdir(base_path($testDir));
        }
    }

    public function test_saveAllResponseBodiesToFile_processes_multiple_batches(): void
    {
        $realClient = new BaseApiClient(
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->realCacheManager
        );

        $tableName = $this->realCacheManager->getTableName($this->clientName);
        $testDir   = 'storage/app/test_batch_processing';

        // Insert 5 test records (more than one batch of size 2)
        $testIds = [];
        for ($i = 1; $i <= 5; $i++) {
            $testIds[] = DB::table($tableName)->insertGetId([
                'key'                  => "test-batch-{$i}",
                'client'               => $this->clientName,
                'endpoint'             => 'batch-test',
                'attributes'           => "https://example.com/batch{$i}",
                'request_headers'      => json_encode([]),
                'request_body'         => '',
                'response_status_code' => 200,
                'response_headers'     => json_encode([]),
                'response_body'        => "<html><body>Batch content {$i}</body></html>",
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
        }

        // Call method with batch size 2 to test multiple batches
        $stats = $realClient->saveAllResponseBodiesToFile(2, 'batch-test', $testDir);

        // Verify statistics - should process all 5 records across multiple batches
        $this->assertEquals(5, $stats['processed'], 'Should process all 5 records');
        $this->assertEquals(0, $stats['errors'], 'Should have no errors');
        $this->assertEquals(0, $stats['skipped'], 'Should skip no files');

        // Verify all files were created with correct content
        foreach ($testIds as $i => $testId) {
            $expectedFile = base_path($testDir . '/' . $testId . '-httpsexamplecombatch' . ($i + 1) . '.html');
            $this->assertFileExists($expectedFile, "Batch file {$i} should exist");

            $content = file_get_contents($expectedFile);
            $this->assertStringContainsString('Batch content ' . ($i + 1), $content);
        }

        // Verify all records were marked as processed
        $processedCount = DB::table($tableName)
            ->whereIn('id', $testIds)
            ->whereNotNull('processed_at')
            ->whereNotNull('processed_status')
            ->count();
        $this->assertEquals(5, $processedCount, 'All records should be marked as processed');

        // Clean up
        foreach ($testIds as $i => $testId) {
            $file = base_path($testDir . '/' . $testId . '-httpsexamplecombatch' . ($i + 1) . '.html');
            if (file_exists($file)) {
                unlink($file);
            }
        }
        if (is_dir(base_path($testDir))) {
            rmdir(base_path($testDir));
        }
    }
}

<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\ApiCacheManager;
use FOfX\ApiCache\ApiCacheServiceProvider;
use FOfX\ApiCache\BaseApiClient;
use FOfX\ApiCache\RateLimitException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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

        // Fake the local storage disk for clean test isolation
        Storage::fake('local');

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

    public function test_setExcludedArgs_updates_excluded_args(): void
    {
        // Create a test client to access the protected property
        $testClient = new class (
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->mockCacheManager
        ) extends BaseApiClient {
            public function getExcludedArgs(): array
            {
                return $this->excludedArgs;
            }

            public function testMethod(string $param1, array $customExcluded = []): array
            {
                // Use default backtrace mode to test excludedArgs property
                return $this->buildApiParams();
            }
        };

        // Verify default excluded args
        $defaultExcluded = ['additionalParams', 'attributes', 'attributes2', 'attributes3', 'amount'];
        $this->assertEquals($defaultExcluded, $testClient->getExcludedArgs());

        // Set new excluded args
        $newExcluded = ['param1', 'customParam'];
        $testClient->setExcludedArgs($newExcluded);
        $this->assertEquals($newExcluded, $testClient->getExcludedArgs());

        // Verify the new excluded args work in buildApiParams
        $result = $testClient->testMethod('test value', ['extra' => 'data']);
        $this->assertArrayNotHasKey('param1', $result); // Should be excluded
        $this->assertArrayHasKey('customExcluded', $result); // Should be included
        $this->assertEquals(['extra' => 'data'], $result['customExcluded']);
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

    public function test_buildApiParams_converts_camel_case_to_snake_case(): void
    {
        // Create a test client that uses snake_case conversion
        $testClient = new class (
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->mockCacheManager
        ) extends BaseApiClient {
            public array $excludedArgs = [];

            public function testMethod(string $testParam, int $anotherParam): array
            {
                return $this->buildApiParams(useSnakeCase: true); // useSnakeCase = true
            }
        };

        // Call the test method with actual camelCase parameters
        $result = $testClient->testMethod('test value', 123);

        // Verify parameters are converted to snake_case
        $this->assertArrayHasKey('test_param', $result);
        $this->assertArrayHasKey('another_param', $result);
        $this->assertEquals('test value', $result['test_param']);
        $this->assertEquals(123, $result['another_param']);
    }

    public function test_buildApiParams_keeps_camel_case_when_disabled(): void
    {
        // Create a test client that keeps camelCase
        $testClient = new class (
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->mockCacheManager
        ) extends BaseApiClient {
            public array $excludedArgs = [];

            public function testMethod(string $testParam, int $anotherParam): array
            {
                return $this->buildApiParams(useSnakeCase: false); // useSnakeCase = false
            }
        };

        // Call the test method with actual camelCase parameters
        $result = $testClient->testMethod('test value', 123);

        // Verify parameters keep camelCase
        $this->assertArrayHasKey('testParam', $result);
        $this->assertArrayHasKey('anotherParam', $result);
        $this->assertEquals('test value', $result['testParam']);
        $this->assertEquals(123, $result['anotherParam']);
    }

    public function test_buildApiParams_removes_null_values(): void
    {
        // Create a test client
        $testClient = new class (
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->mockCacheManager
        ) extends BaseApiClient {
            public array $excludedArgs = [];

            public function testMethod(
                string $nonNullParam,
                ?string $nullParam = null,
                ?int $zeroParam = 0,
                string $emptyStringParam = '',
                ?bool $falseParam = false
            ): array {
                return $this->buildApiParams();
            }
        };

        // Call with mixed null and non-null values
        $result = $testClient->testMethod('value', null, 0, '', false);

        // Verify only non-null parameters are included
        $this->assertArrayHasKey('nonNullParam', $result);
        $this->assertArrayHasKey('zeroParam', $result);
        $this->assertArrayHasKey('emptyStringParam', $result);
        $this->assertArrayHasKey('falseParam', $result);
        $this->assertArrayNotHasKey('nullParam', $result);

        $this->assertEquals('value', $result['nonNullParam']);
        $this->assertEquals(0, $result['zeroParam']);
        $this->assertEquals('', $result['emptyStringParam']);
        $this->assertEquals(false, $result['falseParam']);
    }

    public function test_buildApiParams_excludes_specified_arguments(): void
    {
        // Create a test client with excluded args
        $testClient = new class (
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->mockCacheManager
        ) extends BaseApiClient {
            // Test method with parameters that should be excluded
            public function testMethod(
                string $normalParam,
                array $additionalParams = [],
                ?string $attributes = null,
                ?int $amount = null
            ): array {
                // Pass the additionalParams directly to buildApiParams
                return $this->buildApiParams($additionalParams);
            }
        };

        // Extra parameters to pass as additionalParams
        $additionalParams = ['some' => 'value'];

        // Call with all types of parameters
        $result = $testClient->testMethod('value', $additionalParams, 'test', 5);

        // Verify excluded parameters are not present
        $this->assertArrayHasKey('normalParam', $result);
        $this->assertArrayHasKey('some', $result); // From additionalParams
        $this->assertArrayNotHasKey('additionalParams', $result); // Excluded
        $this->assertArrayNotHasKey('attributes', $result); // Excluded
        $this->assertArrayNotHasKey('amount', $result); // Excluded

        $this->assertEquals('value', $result['normalParam']);
        $this->assertEquals('value', $result['some']);
    }

    public function test_buildApiParams_merges_additional_params(): void
    {
        // Create a test client
        $testClient = new class (
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->mockCacheManager
        ) extends BaseApiClient {
            public array $excludedArgs = [];

            public function testMethod(string $param1, string $param2, array $additionalParams = []): array
            {
                return $this->buildApiParams($additionalParams);
            }
        };

        // Create additionalParams with overlapping and new keys
        $additionalParams = [
            'param1' => 'additional value', // Should be overridden by the method parameter
            'param3' => 'value3',          // Should be included as-is
        ];

        // Call with overlapping parameters
        $result = $testClient->testMethod('original value', 'value2', $additionalParams);

        // Verify parameter precedence and merging
        $this->assertArrayHasKey('param1', $result);
        $this->assertArrayHasKey('param2', $result);
        $this->assertArrayHasKey('param3', $result);

        // Method parameters should take precedence over additionalParams
        $this->assertEquals('original value', $result['param1']);
        $this->assertEquals('value2', $result['param2']);
        $this->assertEquals('value3', $result['param3']);
    }

    public function test_buildApiParams_real_world_example(): void
    {
        // Create a test client
        $testClient = new class (
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->mockCacheManager
        ) extends BaseApiClient {
            public function searchMethod(
                string $keyword,
                ?string $locationName = null,
                ?int $locationCode = null,
                bool $enableFeature = false,
                array $additionalParams = []
            ): array {
                return $this->buildApiParams($additionalParams);
            }
        };

        // Additional parameters to include
        $additionalParams = ['extraParam' => 'extra value'];

        // Call with a mix of parameters, including null
        $result = $testClient->searchMethod(
            'test keyword',
            'United States',
            null,
            true,
            $additionalParams
        );

        // Verify the parameter processing
        $this->assertArrayHasKey('keyword', $result);
        $this->assertArrayHasKey('locationName', $result);
        $this->assertArrayHasKey('enableFeature', $result);
        $this->assertArrayHasKey('extraParam', $result);
        $this->assertArrayNotHasKey('locationCode', $result); // Should be removed as it's null
        $this->assertArrayNotHasKey('additionalParams', $result); // Should be excluded
        $this->assertEquals('test keyword', $result['keyword']);
        $this->assertEquals('United States', $result['locationName']);
        $this->assertEquals(true, $result['enableFeature']);
        $this->assertEquals('extra value', $result['extraParam']);
    }

    public function test_buildApiParams_respects_additional_excluded_args(): void
    {
        // Create a test client
        $testClient = new class (
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->mockCacheManager
        ) extends BaseApiClient {
            public array $excludedArgs = [];

            public function testMethod(
                string $normalParam,
                string $shouldBeExcluded,
                array $additionalParams = []
            ): array {
                // Exclude 'shouldBeExcluded' parameter
                return $this->buildApiParams($additionalParams, ['shouldBeExcluded']);
            }
        };

        $result = $testClient->testMethod('include me', 'exclude me', []);

        $this->assertArrayHasKey('normalParam', $result);
        $this->assertArrayNotHasKey('shouldBeExcluded', $result);
        $this->assertEquals('include me', $result['normalParam']);
    }

    public function test_buildApiParams_merges_default_and_additional_excluded_args(): void
    {
        $testClient = new class (
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->mockCacheManager
        ) extends BaseApiClient {
            protected array $excludedArgs = ['additionalParams', 'attributes']; // Custom default excluded args

            public function testMethod(
                string $normalParam,
                string $customExcluded,       // Will be excluded via parameter
                array $customExcludedArgs,    // Should be included (not excluded)
                array $additionalParams = [], // Default excluded
                ?string $attributes = null    // Default excluded
            ): array {
                return $this->buildApiParams($additionalParams, ['customExcluded']);
            }
        };

        $result = $testClient->testMethod(
            'include me',
            'exclude me',
            ['should' => 'be included'],
            ['extra'  => 'value'],
            'test'
        );

        $this->assertArrayHasKey('normalParam', $result);
        $this->assertArrayHasKey('extra', $result);
        $this->assertArrayHasKey('customExcludedArgs', $result); // Should be included
        $this->assertArrayNotHasKey('additionalParams', $result); // Default excluded
        $this->assertArrayNotHasKey('attributes', $result);        // Default excluded
        $this->assertArrayNotHasKey('customExcluded', $result);   // Additional excluded
    }

    public function test_buildApiParams_works_with_empty_additional_excluded_args(): void
    {
        $testClient = new class (
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->mockCacheManager
        ) extends BaseApiClient {
            protected array $excludedArgs = ['additionalParams', 'attributes'];

            public function testMethod(
                string $normalParam,
                array $additionalParams = [],
                ?string $attributes = null
            ): array {
                // Pass empty array for additional excluded args
                return $this->buildApiParams($additionalParams, []);
            }
        };

        $result = $testClient->testMethod('include me', [], 'test');

        $this->assertArrayHasKey('normalParam', $result);
        $this->assertArrayNotHasKey('additionalParams', $result); // Default excluded
        $this->assertArrayNotHasKey('attributes', $result);        // Default excluded
        $this->assertEquals('include me', $result['normalParam']);
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
            ->with($this->clientName, 'test-key', ['query' => 'test'], Mockery::any(), 'predictions', $this->version, null, $attributes, null, null, $amount)
            ->andReturn(true);

        // Get remaining attempts before
        $beforeAttempts = $this->mockCacheManager->getRemainingAttempts($this->clientName);

        $result = $this->client->sendCachedRequest('predictions', ['query' => 'test'], 'GET', $attributes, null, null, $amount);

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
            public function sendRequest(string $endpoint, array $params = [], string $method = 'GET', ?string $attributes = null, ?string $attributes2 = null, ?string $attributes3 = null, ?int $credits = null): array
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
            public function sendRequest(string $endpoint, array $params = [], string $method = 'GET', ?string $attributes = null, ?string $attributes2 = null, ?string $attributes3 = null, ?int $credits = null): array
            {
                // Make a request that will return a 500 status code using demo server's status endpoint
                $result = parent::sendRequest('500', $params, $method, $attributes, $attributes2, $attributes3, $credits);

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

    public function test_sendRequest_includes_attributes2_and_attributes3_in_response(): void
    {
        $result = $this->client->sendRequest(
            'predictions',
            ['query' => 'test'],
            'GET',
            'attr1-value',
            'attr2-value',
            'attr3-value'
        );

        $this->assertEquals('attr1-value', $result['request']['attributes']);
        $this->assertEquals('attr2-value', $result['request']['attributes2']);
        $this->assertEquals('attr3-value', $result['request']['attributes3']);
    }

    public function test_sendCachedRequest_passes_attributes2_and_attributes3_to_storeResponse(): void
    {
        $this->mockCacheManager->shouldReceive('generateCacheKey')
            ->once()
            ->andReturn('test-cache-key');

        $this->mockCacheManager->shouldReceive('getCachedResponse')
            ->once()
            ->andReturnNull();

        $this->mockCacheManager->shouldReceive('allowRequest')
            ->once()
            ->andReturn(true);

        $this->mockCacheManager->shouldReceive('incrementAttempts')
            ->once();

        $this->mockCacheManager->shouldReceive('storeResponse')
            ->once()
            ->withArgs(function ($clientName, $cacheKey, $params, $apiResult, $endpoint, $version, $ttl, $attributes, $attributes2, $attributes3, $credits) {
                return $clientName === $this->clientName &&
                       $cacheKey === 'test-cache-key' &&
                       $endpoint === 'predictions' &&
                       $attributes === 'attr1-value' &&
                       $attributes2 === 'attr2-value' &&
                       $attributes3 === 'attr3-value' &&
                       $credits === 5;
            });

        $result = $this->client->sendCachedRequest(
            'predictions',
            ['query' => 'test'],
            'GET',
            'attr1-value',
            'attr2-value',
            'attr3-value',
            5
        );

        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('response_time', $result);
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

    public static function resolveFilenameSlugSourceProvider(): array
    {
        return [
            'single_attribute_1' => [
                'test-slug', null, null,
                'test-slug',
                'Should return single attribute when only attributes is set',
            ],
            'single_attribute_2' => [
                null, 'test-slug-2', null,
                'test-slug-2',
                'Should return single attribute when only attributes2 is set',
            ],
            'single_attribute_3' => [
                null, null, 'test-slug-3',
                'test-slug-3',
                'Should return single attribute when only attributes3 is set',
            ],
            'two_attributes_1_and_2' => [
                'attr1', 'attr2', null,
                'attr1-attr2',
                'Should concatenate attributes and attributes2 with dash',
            ],
            'two_attributes_1_and_3' => [
                'attr1', null, 'attr3',
                'attr1-attr3',
                'Should concatenate attributes and attributes3 with dash',
            ],
            'two_attributes_2_and_3' => [
                null, 'attr2', 'attr3',
                'attr2-attr3',
                'Should concatenate attributes2 and attributes3 with dash',
            ],
            'all_three_attributes' => [
                'attr1', 'attr2', 'attr3',
                'attr1-attr2-attr3',
                'Should concatenate all three attributes with dashes',
            ],
            'all_null_attributes' => [
                null, null, null,
                '',
                'Should return empty string when all attributes are null',
            ],
        ];
    }

    #[DataProvider('resolveFilenameSlugSourceProvider')]
    public function test_resolveFilenameSlugSource_concatenates_non_null_attributes(
        ?string $attributes,
        ?string $attributes2,
        ?string $attributes3,
        string $expected,
        string $description
    ): void {
        $row = (object) [
            'attributes'  => $attributes,
            'attributes2' => $attributes2,
            'attributes3' => $attributes3,
        ];

        $result = $this->client->resolveFilenameSlugSource($row);
        $this->assertEquals($expected, $result, $description);
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

    public function test_saveResponseBodiesToFilesForRows_saves_response_bodies_for_given_rows(): void
    {
        // Create client with REAL dependencies for file operations
        $realClient = new BaseApiClient(
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->realCacheManager
        );

        $tableName = $this->realCacheManager->getTableName($this->clientName);
        $testDir   = 'test_for_rows_file_save';

        // Insert test data with response body
        $testResponseBody = '<html><body>Rows helper content</body></html>';
        $testId           = DB::table($tableName)->insertGetId([
            'key'                  => 'test-for-rows',
            'client'               => $this->clientName,
            'endpoint'             => 'rows-endpoint',
            'attributes'           => 'https://example.com/rows-helper',
            'request_headers'      => json_encode([]),
            'request_body'         => '',
            'response_status_code' => 200,
            'response_headers'     => json_encode([]),
            'response_body'        => $testResponseBody,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        // Fetch rows explicitly and pass into helper
        $rows = DB::table($tableName)->whereIn('id', [$testId])->get()->all();

        $stats = $realClient->saveResponseBodiesToFilesForRows($rows, $testDir, false, 180, null);

        // Verify statistics
        $this->assertEquals(1, $stats['processed']);
        $this->assertEquals(0, $stats['errors']);
        $this->assertEquals(0, $stats['skipped']);

        // Verify file was created with expected content
        $expectedFilename = $testId . '-httpsexamplecomrows-helper.html';
        $expectedFilePath = $testDir . '/' . $expectedFilename;
        $this->assertTrue(Storage::disk('local')->exists($expectedFilePath));
        $this->assertEquals($testResponseBody, Storage::disk('local')->get($expectedFilePath));

        // Verify database status was updated
        $record = DB::table($tableName)->where('id', $testId)->first();
        $this->assertNotNull($record->processed_at);
        $this->assertNotNull($record->processed_status);
        $status = json_decode($record->processed_status, true);
        $this->assertEquals('OK', $status['status']);
        $this->assertStringContainsString($expectedFilename, $status['filename']);
    }

    public function test_saveResponseBodiesByIdsToFiles_saves_response_bodies_for_given_ids(): void
    {
        $realClient = new BaseApiClient(
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->realCacheManager
        );

        $tableName = $this->realCacheManager->getTableName($this->clientName);
        $testDir   = 'test_by_ids_file_save';

        // Insert two test rows
        $id1 = DB::table($tableName)->insertGetId([
            'key'                  => 'test-by-ids-1',
            'client'               => $this->clientName,
            'endpoint'             => 'ids-endpoint',
            'attributes'           => 'https://example.com/ids-one',
            'response_status_code' => 200,
            'response_body'        => '<html>One</html>',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $id2 = DB::table($tableName)->insertGetId([
            'key'                  => 'test-by-ids-2',
            'client'               => $this->clientName,
            'endpoint'             => 'ids-endpoint',
            'attributes'           => 'https://example.com/ids-two',
            'response_status_code' => 200,
            'response_body'        => '<html>Two</html>',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $stats = $realClient->saveResponseBodiesByIdsToFiles([$id1, $id2], $testDir, false, 180, null);

        // Verify statistics
        $this->assertEquals(2, $stats['processed']);
        $this->assertEquals(0, $stats['errors']);
        $this->assertEquals(0, $stats['skipped']);

        // Verify both files created
        $file1 = $testDir . '/' . $id1 . '-httpsexamplecomids-one.html';
        $file2 = $testDir . '/' . $id2 . '-httpsexamplecomids-two.html';
        $this->assertTrue(Storage::disk('local')->exists($file1));
        $this->assertTrue(Storage::disk('local')->exists($file2));
        $this->assertEquals('<html>One</html>', Storage::disk('local')->get($file1));
        $this->assertEquals('<html>Two</html>', Storage::disk('local')->get($file2));

        // Verify database status updated for both IDs
        foreach ([$id1, $id2] as $id) {
            $rec = DB::table($tableName)->where('id', $id)->first();
            $this->assertNotNull($rec->processed_at);
            $this->assertNotNull($rec->processed_status);
            $st = json_decode($rec->processed_status, true);
            $this->assertEquals('OK', $st['status']);
        }
    }

    public function test_saveResponseBodiesByIdsToFiles_returns_zero_stats_for_empty_ids(): void
    {
        $realClient = new BaseApiClient(
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->realCacheManager
        );

        $stats = $realClient->saveResponseBodiesByIdsToFiles([]);

        $this->assertEquals(0, $stats['processed']);
        $this->assertEquals(0, $stats['errors']);
        $this->assertEquals(0, $stats['skipped']);
    }

    public function test_saveResponseBodiesByIdsToFiles_ignores_nonexistent_ids_and_processes_existing(): void
    {
        $realClient = new BaseApiClient(
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->realCacheManager
        );

        $tableName = $this->realCacheManager->getTableName($this->clientName);
        $testDir   = 'test_by_ids_mixed';

        // Insert a single existing row
        $existingId = DB::table($tableName)->insertGetId([
            'key'                  => 'test-by-ids-existing',
            'client'               => $this->clientName,
            'endpoint'             => 'ids-endpoint',
            'attributes'           => 'https://example.com/ids-existing',
            'response_status_code' => 200,
            'response_body'        => '<html>Existing</html>',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $nonExistentId = 999999999; // very unlikely to exist in test DB

        $stats = $realClient->saveResponseBodiesByIdsToFiles([$existingId, $nonExistentId], $testDir, false, 180, null);

        // Verify only the existing row was processed
        $this->assertEquals(1, $stats['processed']);
        $this->assertEquals(0, $stats['errors']);
        $this->assertEquals(0, $stats['skipped']);

        $expectedFile = $testDir . '/' . $existingId . '-httpsexamplecomids-existing.html';
        $this->assertTrue(Storage::disk('local')->exists($expectedFile));
        $this->assertEquals('<html>Existing</html>', Storage::disk('local')->get($expectedFile));

        // Verify database status updated for existing ID
        $rec = DB::table($tableName)->where('id', $existingId)->first();
        $this->assertNotNull($rec->processed_at);
        $this->assertNotNull($rec->processed_status);
        $st = json_decode($rec->processed_status, true);
        $this->assertEquals('OK', $st['status']);
    }

    public function test_saveResponseBodiesToFilesBatch_saves_response_body_to_file(): void
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

        $testDir = 'test_file_save';

        // Call the method to save files for the test endpoint
        $stats = $realClient->saveResponseBodiesToFilesBatch(10, 'test-endpoint', $testDir);

        // Verify statistics
        $this->assertEquals(1, $stats['processed'], 'Should process 1 record');
        $this->assertEquals(0, $stats['errors'], 'Should have no errors');
        $this->assertEquals(0, $stats['skipped'], 'Should skip no files');

        // Verify file was created with expected content
        $expectedFilename = $testId . '-httpsexamplecomtest-page.html';
        $expectedFilePath = $testDir . '/' . $expectedFilename;

        $this->assertTrue(Storage::disk('local')->exists($expectedFilePath), 'Response body file should be created');

        $actualFileContent = Storage::disk('local')->get($expectedFilePath);
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
    }

    public function test_saveResponseBodiesToFilesBatch_skips_existing_files_when_overwrite_disabled(): void
    {
        $realClient = new BaseApiClient(
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->realCacheManager
        );

        $tableName = $this->realCacheManager->getTableName($this->clientName);
        $testDir   = 'test_skip_files';

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

        // Create existing file using Storage facade
        $expectedFilename = $testId . '-httpsexamplecomskip-page.html';
        $expectedFilePath = $testDir . '/' . $expectedFilename;

        Storage::disk('local')->put($expectedFilePath, 'Original content');

        // Call method with overwriteExisting = false (default)
        $stats = $realClient->saveResponseBodiesToFilesBatch(10, 'skip-test', $testDir, false);

        // Verify statistics
        $this->assertEquals(0, $stats['processed'], 'Should process 0 records');
        $this->assertEquals(0, $stats['errors'], 'Should have no errors');
        $this->assertEquals(1, $stats['skipped'], 'Should skip 1 file');

        // Verify file content wasn't changed
        $this->assertEquals('Original content', Storage::disk('local')->get($expectedFilePath));

        // Verify database status shows skipped
        $record = DB::table($tableName)->where('id', $testId)->first();
        $this->assertNotNull($record->processed_status);

        $status = json_decode($record->processed_status, true);
        $this->assertEquals('Skipped', $status['status']);
    }

    public function test_saveResponseBodiesToFilesBatch_overwrites_existing_files_when_enabled(): void
    {
        $realClient = new BaseApiClient(
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->realCacheManager
        );

        $tableName = $this->realCacheManager->getTableName($this->clientName);
        $testDir   = 'test_overwrite_files';

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

        // Create existing file using Storage facade
        $expectedFilename = $testId . '-httpsexamplecomoverwrite-page.html';
        $expectedFilePath = $testDir . '/' . $expectedFilename;

        Storage::disk('local')->put($expectedFilePath, 'Original content');

        // Call method with overwriteExisting = true
        $stats = $realClient->saveResponseBodiesToFilesBatch(10, 'overwrite-test', $testDir, true);

        // Verify statistics
        $this->assertEquals(1, $stats['processed'], 'Should process 1 record');
        $this->assertEquals(0, $stats['errors'], 'Should have no errors');
        $this->assertEquals(0, $stats['skipped'], 'Should skip no files');

        // Verify file content was updated
        $this->assertEquals($testResponseBody, Storage::disk('local')->get($expectedFilePath));

        // Verify database status shows processed
        $record = DB::table($tableName)->where('id', $testId)->first();
        $status = json_decode($record->processed_status, true);
        $this->assertEquals('OK', $status['status']);
    }

    public function test_saveResponseBodiesToFilesBatch_filters_by_endpoint(): void
    {
        $realClient = new BaseApiClient(
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->realCacheManager
        );

        $tableName = $this->realCacheManager->getTableName($this->clientName);
        $testDir   = 'test_endpoint_filter';

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
        $stats = $realClient->saveResponseBodiesToFilesBatch(10, 'target-endpoint', $testDir);

        // Verify statistics - should only process the target endpoint
        $this->assertEquals(1, $stats['processed'], 'Should process 1 record');
        $this->assertEquals(0, $stats['errors'], 'Should have no errors');
        $this->assertEquals(0, $stats['skipped'], 'Should skip no files');

        // Verify only the target file was created
        $targetFile = $testDir . '/' . $testId1 . '-httpsexamplecomtarget.html';
        $otherFile  = $testDir . '/' . $testId2 . '-httpsexamplecomother.html';

        $this->assertTrue(Storage::disk('local')->exists($targetFile), 'Target endpoint file should exist');
        $this->assertFalse(Storage::disk('local')->exists($otherFile), 'Other endpoint file should not exist');

        $this->assertEquals('<html>Target content</html>', Storage::disk('local')->get($targetFile));
    }

    public function test_saveResponseBodiesToFilesBatch_filters_by_attributes(): void
    {
        $realClient = new BaseApiClient(
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->realCacheManager
        );

        $tableName = $this->realCacheManager->getTableName($this->clientName);
        $testDir   = 'test_attributes_filter';

        // Insert records with different attributes values
        $testId1 = DB::table($tableName)->insertGetId([
            'key'                  => 'test-attributes-1',
            'client'               => $this->clientName,
            'endpoint'             => 'test-endpoint',
            'attributes'           => 'target-attribute',
            'request_headers'      => json_encode([]),
            'request_body'         => '',
            'response_status_code' => 200,
            'response_body'        => '<html>Target attribute content</html>',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $testId2 = DB::table($tableName)->insertGetId([
            'key'                  => 'test-attributes-2',
            'client'               => $this->clientName,
            'endpoint'             => 'test-endpoint',
            'attributes'           => 'other-attribute',
            'request_headers'      => json_encode([]),
            'request_body'         => '',
            'response_status_code' => 200,
            'response_body'        => '<html>Other attribute content</html>',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        // Call method filtering for 'target-attribute' only
        $stats = $realClient->saveResponseBodiesToFilesBatch(10, null, $testDir, false, 180, null, 'target-attribute');

        // Verify statistics - should only process the target attribute
        $this->assertEquals(1, $stats['processed'], 'Should process 1 record');
        $this->assertEquals(0, $stats['errors'], 'Should have no errors');
        $this->assertEquals(0, $stats['skipped'], 'Should skip no files');

        // Verify only the target file was created
        $targetFile = $testDir . '/' . $testId1 . '-target-attribute.html';
        $otherFile  = $testDir . '/' . $testId2 . '-other-attribute.html';

        $this->assertTrue(Storage::disk('local')->exists($targetFile), 'Target attribute file should exist');
        $this->assertFalse(Storage::disk('local')->exists($otherFile), 'Other attribute file should not exist');

        $this->assertEquals('<html>Target attribute content</html>', Storage::disk('local')->get($targetFile));
    }

    public function test_saveResponseBodiesToFilesBatch_filters_by_attributes2(): void
    {
        $realClient = new BaseApiClient(
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->realCacheManager
        );

        $tableName = $this->realCacheManager->getTableName($this->clientName);
        $testDir   = 'test_attributes2_filter';

        // Insert records with different attributes2 values
        $testId1 = DB::table($tableName)->insertGetId([
            'key'                  => 'test-attributes2-1',
            'client'               => $this->clientName,
            'endpoint'             => 'test-endpoint',
            'attributes'           => 'test-url',
            'attributes2'          => 'target-attr2',
            'request_headers'      => json_encode([]),
            'request_body'         => '',
            'response_status_code' => 200,
            'response_body'        => '<html>Target attributes2 content</html>',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $testId2 = DB::table($tableName)->insertGetId([
            'key'                  => 'test-attributes2-2',
            'client'               => $this->clientName,
            'endpoint'             => 'test-endpoint',
            'attributes'           => 'test-url',
            'attributes2'          => 'other-attr2',
            'request_headers'      => json_encode([]),
            'request_body'         => '',
            'response_status_code' => 200,
            'response_body'        => '<html>Other attributes2 content</html>',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        // Call method filtering for 'target-attr2' only
        $stats = $realClient->saveResponseBodiesToFilesBatch(10, null, $testDir, false, 180, null, null, 'target-attr2');

        // Verify statistics - should only process the target attributes2
        $this->assertEquals(1, $stats['processed'], 'Should process 1 record');
        $this->assertEquals(0, $stats['errors'], 'Should have no errors');
        $this->assertEquals(0, $stats['skipped'], 'Should skip no files');

        // Verify only the target file was created (filename includes all attributes)
        $targetFile = $testDir . '/' . $testId1 . '-test-url-target-attr2.html';
        $otherFile  = $testDir . '/' . $testId2 . '-test-url-other-attr2.html';

        $this->assertTrue(Storage::disk('local')->exists($targetFile), 'Target attributes2 file should exist');
        $this->assertFalse(Storage::disk('local')->exists($otherFile), 'Other attributes2 file should not exist');

        $this->assertEquals('<html>Target attributes2 content</html>', Storage::disk('local')->get($targetFile));
    }

    public function test_saveResponseBodiesToFilesBatch_filters_by_attributes3(): void
    {
        $realClient = new BaseApiClient(
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->realCacheManager
        );

        $tableName = $this->realCacheManager->getTableName($this->clientName);
        $testDir   = 'test_attributes3_filter';

        // Insert records with different attributes3 values
        $testId1 = DB::table($tableName)->insertGetId([
            'key'                  => 'test-attributes3-1',
            'client'               => $this->clientName,
            'endpoint'             => 'test-endpoint',
            'attributes'           => 'test-url',
            'attributes3'          => 'browserHtml',
            'request_headers'      => json_encode([]),
            'request_body'         => '',
            'response_status_code' => 200,
            'response_body'        => '<html>Browser HTML content</html>',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $testId2 = DB::table($tableName)->insertGetId([
            'key'                  => 'test-attributes3-2',
            'client'               => $this->clientName,
            'endpoint'             => 'test-endpoint',
            'attributes'           => 'test-url',
            'attributes3'          => 'screenshot',
            'request_headers'      => json_encode([]),
            'request_body'         => '',
            'response_status_code' => 200,
            'response_body'        => '<html>Screenshot content</html>',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        // Call method filtering for 'browserHtml' only
        $stats = $realClient->saveResponseBodiesToFilesBatch(10, null, $testDir, false, 180, null, null, null, 'browserHtml');

        // Verify statistics - should only process the browserHtml attribute
        $this->assertEquals(1, $stats['processed'], 'Should process 1 record');
        $this->assertEquals(0, $stats['errors'], 'Should have no errors');
        $this->assertEquals(0, $stats['skipped'], 'Should skip no files');

        // Verify only the target file was created (filename includes all attributes)
        $targetFile = $testDir . '/' . $testId1 . '-test-url-browserhtml.html';
        $otherFile  = $testDir . '/' . $testId2 . '-test-url-screenshot.html';

        $this->assertTrue(Storage::disk('local')->exists($targetFile), 'browserHtml file should exist');
        $this->assertFalse(Storage::disk('local')->exists($otherFile), 'screenshot file should not exist');

        $this->assertEquals('<html>Browser HTML content</html>', Storage::disk('local')->get($targetFile));
    }

    public function test_saveResponseBodiesToFilesBatch_extracts_json_field(): void
    {
        $realClient = new BaseApiClient(
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->realCacheManager
        );

        $tableName = $this->realCacheManager->getTableName($this->clientName);
        $testDir   = 'test_json_extraction';

        // Create JSON response body with browserHtml field
        $htmlContent      = '<html><head><title>Extracted HTML</title></head><body><h1>JSON Extracted Content</h1></body></html>';
        $jsonResponseBody = json_encode([
            'url'         => 'https://example.com',
            'statusCode'  => 200,
            'browserHtml' => $htmlContent,
            'otherField'  => 'should not be extracted',
        ]);

        $testId = DB::table($tableName)->insertGetId([
            'key'                  => 'test-json-extraction',
            'client'               => $this->clientName,
            'endpoint'             => 'extract-test',
            'attributes'           => 'https://example.com/json-test',
            'attributes3'          => 'browserHtml',
            'request_headers'      => json_encode([]),
            'request_body'         => '',
            'response_status_code' => 200,
            'response_body'        => $jsonResponseBody,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        // Call method with JSON extraction for 'browserHtml' field
        $stats = $realClient->saveResponseBodiesToFilesBatch(10, null, $testDir, false, 180, 'browserHtml', null, null, 'browserHtml');

        // Verify statistics
        $this->assertEquals(1, $stats['processed'], 'Should process 1 record');
        $this->assertEquals(0, $stats['errors'], 'Should have no errors');
        $this->assertEquals(0, $stats['skipped'], 'Should skip no files');

        // Verify file was created with extracted HTML content (not JSON)
        $expectedFilename = $testId . '-httpsexamplecomjson-test-browserhtml.html';
        $expectedFilePath = $testDir . '/' . $expectedFilename;

        $this->assertTrue(Storage::disk('local')->exists($expectedFilePath), 'Extracted HTML file should be created');

        $actualFileContent = Storage::disk('local')->get($expectedFilePath);
        $this->assertEquals($htmlContent, $actualFileContent, 'File should contain extracted HTML, not JSON');

        // Verify it contains HTML content, not JSON
        $this->assertStringContainsString('<title>Extracted HTML</title>', $actualFileContent);
        $this->assertStringContainsString('<h1>JSON Extracted Content</h1>', $actualFileContent);
        $this->assertStringNotContainsString('statusCode', $actualFileContent);
        $this->assertStringNotContainsString('otherField', $actualFileContent);
    }

    public function test_saveResponseBodiesToFilesBatch_handles_invalid_json_gracefully(): void
    {
        $realClient = new BaseApiClient(
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->realCacheManager
        );

        $tableName = $this->realCacheManager->getTableName($this->clientName);
        $testDir   = 'test_invalid_json';

        // Create invalid JSON response body
        $invalidJsonResponseBody = '{invalid json content}';

        $testId = DB::table($tableName)->insertGetId([
            'key'                  => 'test-invalid-json',
            'client'               => $this->clientName,
            'endpoint'             => 'invalid-json-test',
            'attributes'           => 'https://example.com/invalid-json',
            'request_headers'      => json_encode([]),
            'request_body'         => '',
            'response_status_code' => 200,
            'response_body'        => $invalidJsonResponseBody,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        // Call method with JSON extraction - should handle invalid JSON gracefully
        $stats = $realClient->saveResponseBodiesToFilesBatch(10, null, $testDir, false, 180, 'browserHtml');

        // Verify statistics - should have 1 error
        $this->assertEquals(0, $stats['processed'], 'Should process 0 records');
        $this->assertEquals(1, $stats['errors'], 'Should have 1 error');
        $this->assertEquals(0, $stats['skipped'], 'Should skip no files');

        // Verify no file was created
        $expectedFilename = $testId . '-httpsexamplecominvalid-json.html';
        $expectedFilePath = $testDir . '/' . $expectedFilename;
        $this->assertFalse(Storage::disk('local')->exists($expectedFilePath), 'No file should be created for invalid JSON');

        // Verify database record shows error status
        $record = DB::table($tableName)->where('id', $testId)->first();
        $this->assertNotNull($record->processed_status);
        $status = json_decode($record->processed_status, true);
        $this->assertEquals('ERROR', $status['status']);
        $this->assertStringContainsString('Invalid JSON', $status['error']);
    }

    public function test_saveResponseBodiesToFilesBatch_handles_missing_json_key_gracefully(): void
    {
        $realClient = new BaseApiClient(
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->realCacheManager
        );

        $tableName = $this->realCacheManager->getTableName($this->clientName);
        $testDir   = 'test_missing_json_key';

        // Create valid JSON but without the requested key
        $jsonResponseBody = json_encode([
            'url'        => 'https://example.com',
            'statusCode' => 200,
            'otherField' => 'some content',
            // Missing 'browserHtml' key
        ]);

        $testId = DB::table($tableName)->insertGetId([
            'key'                  => 'test-missing-key',
            'client'               => $this->clientName,
            'endpoint'             => 'missing-key-test',
            'attributes'           => 'https://example.com/missing-key',
            'request_headers'      => json_encode([]),
            'request_body'         => '',
            'response_status_code' => 200,
            'response_body'        => $jsonResponseBody,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        // Call method with JSON extraction for missing key
        $stats = $realClient->saveResponseBodiesToFilesBatch(10, null, $testDir, false, 180, 'browserHtml');

        // Verify statistics - should have 1 error
        $this->assertEquals(0, $stats['processed'], 'Should process 0 records');
        $this->assertEquals(1, $stats['errors'], 'Should have 1 error');
        $this->assertEquals(0, $stats['skipped'], 'Should skip no files');

        // Verify no file was created
        $expectedFilename = $testId . '-httpsexamplecommissing-key.html';
        $expectedFilePath = $testDir . '/' . $expectedFilename;
        $this->assertFalse(Storage::disk('local')->exists($expectedFilePath), 'No file should be created for missing JSON key');

        // Verify database record shows error status
        $record = DB::table($tableName)->where('id', $testId)->first();
        $this->assertNotNull($record->processed_status);
        $status = json_decode($record->processed_status, true);
        $this->assertEquals('ERROR', $status['status']);
        $this->assertStringContainsString("JSON key 'browserHtml' not found", $status['error']);
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
        $testDir   = 'test_batch_processing';

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
            $expectedFile = $testDir . '/' . $testId . '-httpsexamplecombatch' . ($i + 1) . '.html';
            $this->assertTrue(Storage::disk('local')->exists($expectedFile), "Batch file {$i} should exist");

            $content = Storage::disk('local')->get($expectedFile);
            $this->assertStringContainsString('Batch content ' . ($i + 1), $content);
        }

        // Verify all records were marked as processed
        $processedCount = DB::table($tableName)
            ->whereIn('id', $testIds)
            ->whereNotNull('processed_at')
            ->whereNotNull('processed_status')
            ->count();
        $this->assertEquals(5, $processedCount, 'All records should be marked as processed');
    }

    public function test_saveAllResponseBodiesToFile_passes_through_new_parameters(): void
    {
        $realClient = new BaseApiClient(
            $this->clientName,
            $this->apiBaseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->realCacheManager
        );

        $tableName = $this->realCacheManager->getTableName($this->clientName);
        $testDir   = 'test_passthrough_params';

        // Create JSON response with browserHtml field
        $htmlContent      = '<html>Passthrough test content</html>';
        $jsonResponseBody = json_encode([
            'url'         => 'https://example.com',
            'browserHtml' => $htmlContent,
        ]);

        $testId = DB::table($tableName)->insertGetId([
            'key'                  => 'test-passthrough',
            'client'               => $this->clientName,
            'endpoint'             => 'passthrough-test',
            'attributes'           => 'https://example.com/passthrough',
            'attributes3'          => 'browserHtml',
            'request_headers'      => json_encode([]),
            'request_body'         => '',
            'response_status_code' => 200,
            'response_body'        => $jsonResponseBody,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        // Call saveAllResponseBodiesToFile with new parameters
        $stats = $realClient->saveAllResponseBodiesToFile(10, null, $testDir, false, 180, 'browserHtml', null, null, 'browserHtml');

        // Verify statistics
        $this->assertEquals(1, $stats['processed'], 'Should process 1 record');
        $this->assertEquals(0, $stats['errors'], 'Should have no errors');
        $this->assertEquals(0, $stats['skipped'], 'Should skip no files');

        // Verify file was created with extracted content
        $expectedFilename = $testId . '-httpsexamplecompassthrough-browserhtml.html';
        $expectedFilePath = $testDir . '/' . $expectedFilename;

        $this->assertTrue(Storage::disk('local')->exists($expectedFilePath), 'File should be created');
        $this->assertEquals($htmlContent, Storage::disk('local')->get($expectedFilePath), 'File should contain extracted HTML');
    }
}

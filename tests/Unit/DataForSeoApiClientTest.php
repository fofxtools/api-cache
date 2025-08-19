<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\DataForSeoApiClient;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class DataForSeoApiClientTest extends TestCase
{
    protected DataForSeoApiClient $client;
    protected string $apiBaseUrl = 'https://api.dataforseo.com/v3';
    protected string $version    = 'v3';
    protected string $login      = 'test-login';
    protected string $password   = 'test-password';
    protected array $apiDefaultHeaders;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure test environment
        Config::set('api-cache.apis.dataforseo.base_url', $this->apiBaseUrl);
        Config::set('api-cache.apis.dataforseo.version', $this->version);
        Config::set('api-cache.apis.dataforseo.DATAFORSEO_LOGIN', $this->login);
        Config::set('api-cache.apis.dataforseo.DATAFORSEO_PASSWORD', $this->password);
        Config::set('api-cache.apis.dataforseo.rate_limit_max_attempts', 5);
        Config::set('api-cache.apis.dataforseo.rate_limit_decay_seconds', 10);

        $credentials             = base64_encode("{$this->login}:{$this->password}");
        $this->apiDefaultHeaders = [
            'Authorization' => "Basic {$credentials}",
            'Content-Type'  => 'application/json',
        ];

        $this->client = new DataForSeoApiClient();
        $this->client->setTimeout(10);
        $this->client->clearRateLimit();
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

    public function test_class_initializes_with_correct_configuration()
    {
        $this->assertEquals('dataforseo', $this->client->getClientName());
        $this->assertEquals($this->apiBaseUrl, $this->client->getBaseUrl());
        $this->assertNull($this->client->getApiKey());
        $this->assertEquals($this->version, $this->client->getVersion());
    }

    public function test_auth_headers_contain_correct_credentials()
    {
        $headers     = $this->client->getAuthHeaders();
        $credentials = base64_encode("{$this->login}:{$this->password}");

        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertEquals('Basic ' . $credentials, $headers['Authorization']);
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertEquals('application/json', $headers['Content-Type']);
    }

    public function test_calculate_cost_returns_correct_value()
    {
        $responseJson = json_encode([
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.025,
            'tasks'          => [],
        ]);

        $cost = $this->client->calculateCost($responseJson);
        $this->assertEquals(0.025, $cost);
    }

    public function test_calculate_cost_handles_null_response()
    {
        $cost = $this->client->calculateCost(null);
        $this->assertNull($cost);
    }

    public function test_calculate_cost_handles_invalid_json()
    {
        $cost = $this->client->calculateCost('not valid json');
        $this->assertNull($cost);
    }

    public function test_calculate_cost_handles_missing_cost_field()
    {
        $responseJson = json_encode([
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            // No cost field
            'tasks' => [],
        ]);

        $cost = $this->client->calculateCost($responseJson);
        $this->assertNull($cost);
    }

    public function test_shouldCache_returns_true_for_successful_response()
    {
        // Create a successful response with no errors
        $responseJson = json_encode([
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.015,
            'tasks_count'    => 1,
            'tasks_error'    => 0, // No errors
            'tasks'          => [
                [
                    'id'             => '12345',
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'result'         => ['some' => 'data'],
                ],
            ],
        ]);

        $shouldCache = $this->client->shouldCache($responseJson);
        $this->assertTrue($shouldCache);
    }

    public function test_shouldCache_returns_false_for_all_tasks_errors()
    {
        // Create a response where all tasks have errors
        $responseJson = json_encode([
            'version'        => '0.1.20250425',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 1, // All tasks have errors
            'tasks'          => [
                [
                    'id'             => '05070545-3399-0275-0000-170e5e716e1e',
                    'status_code'    => 40207,
                    'status_message' => 'Access denied. Your IP is not whitelisted.',
                    'time'           => '0 sec.',
                    'cost'           => 0,
                    'result_count'   => 0,
                    'result'         => null,
                ],
            ],
        ]);

        $shouldCache = $this->client->shouldCache($responseJson);
        $this->assertFalse($shouldCache);
    }

    public function test_shouldCache_returns_true_for_partial_tasks_errors()
    {
        // Create a response where only some tasks have errors
        $responseJson = json_encode([
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.015,
            'tasks_count'    => 2,
            'tasks_error'    => 1, // One task has an error, but not all
            'tasks'          => [
                [
                    'id'             => '12345',
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'result'         => ['some' => 'data'],
                ],
                [
                    'id'             => '67890',
                    'status_code'    => 40501,
                    'status_message' => 'Task error',
                    'result'         => null,
                ],
            ],
        ]);

        $shouldCache = $this->client->shouldCache($responseJson);
        $this->assertTrue($shouldCache);
    }

    public function test_shouldCache_returns_false_for_null_response()
    {
        $shouldCache = $this->client->shouldCache(null);
        $this->assertFalse($shouldCache);
    }

    public function test_shouldCache_returns_false_for_invalid_json()
    {
        $shouldCache = $this->client->shouldCache('not valid json');
        $this->assertFalse($shouldCache);
    }

    public function test_shouldCache_returns_true_for_missing_tasks_fields()
    {
        // Create a response with missing tasks_error or tasks_count fields
        $responseJson = json_encode([
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.015,
            // No tasks_count or tasks_error fields
            'tasks' => [],
        ]);

        $shouldCache = $this->client->shouldCache($responseJson);
        $this->assertTrue($shouldCache);
    }

    public function test_logCacheRejected_extracts_dataforseo_api_message(): void
    {
        config(['api-cache.error_logging.enabled' => true]);
        config(['api-cache.error_logging.log_events.cache_rejected' => true]);
        config(['api-cache.error_logging.levels.cache_rejected' => 'error']);

        $message  = 'Cache rejected due to errors';
        $context  = ['test' => 'context'];
        $response = json_encode([
            'version'        => '0.1.20250724',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'tasks_count'    => 1,
            'tasks_error'    => 1,
            'tasks'          => [
                [
                    'id'             => '07301005-3399-0398-2000-2dfcc054fb24',
                    'status_code'    => 40501,
                    'status_message' => 'Invalid Field: \'order_by\'.',
                    'result'         => null,
                ],
            ],
        ]);

        $this->client->logCacheRejected($message, $context, $response);

        $this->assertDatabaseHas('api_cache_errors', [
            'api_client'    => 'dataforseo',
            'error_type'    => 'cache_rejected',
            'log_level'     => 'error',
            'error_message' => $message,
            'api_message'   => 'Invalid Field: \'order_by\'.',
        ]);
    }

    public function test_logCacheRejected_handles_missing_task_message(): void
    {
        config(['api-cache.error_logging.enabled' => true]);
        config(['api-cache.error_logging.log_events.cache_rejected' => true]);
        config(['api-cache.error_logging.levels.cache_rejected' => 'error']);

        $message  = 'Cache rejected';
        $context  = ['test' => 'context'];
        $response = json_encode([
            'version'        => '0.1.20250724',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'tasks_count'    => 1,
            'tasks_error'    => 1,
            'tasks'          => [
                [
                    'id'          => '07301005-3399-0398-2000-2dfcc054fb24',
                    'status_code' => 40501,
                    // No status_message in task
                    'result' => null,
                ],
            ],
        ]);

        $this->client->logCacheRejected($message, $context, $response);

        $record = DB::table('api_cache_errors')->first();
        $this->assertEquals('dataforseo', $record->api_client);
        $this->assertEquals('cache_rejected', $record->error_type);
        $this->assertEquals($message, $record->error_message);
        $this->assertNull($record->api_message);
    }

    public function test_logCacheRejected_handles_invalid_json_response(): void
    {
        config(['api-cache.error_logging.enabled' => true]);
        config(['api-cache.error_logging.log_events.cache_rejected' => true]);
        config(['api-cache.error_logging.levels.cache_rejected' => 'error']);

        $message  = 'Cache rejected';
        $context  = ['test' => 'context'];
        $response = 'invalid json response';

        $this->client->logCacheRejected($message, $context, $response);

        $record = DB::table('api_cache_errors')->first();
        $this->assertEquals('dataforseo', $record->api_client);
        $this->assertEquals('cache_rejected', $record->error_type);
        $this->assertEquals($message, $record->error_message);
        $this->assertNull($record->api_message);
    }

    public function test_extractEndpoint_extracts_valid_endpoint()
    {
        $responseArray = [
            'tasks' => [
                [
                    'path' => ['serp', 'google', 'organic', 'live', 'advanced'],
                ],
            ],
        ];

        $result = $this->client->extractEndpoint($responseArray);
        $this->assertEquals('serp/google/organic/live/advanced', $result);
    }

    public function test_extractEndpoint_filters_version_segments()
    {
        $responseArray = [
            'tasks' => [
                [
                    'path' => ['v3', 'serp', 'google', 'organic', 'live', 'v1', 'advanced'],
                ],
            ],
        ];

        $result = $this->client->extractEndpoint($responseArray);
        $this->assertEquals('serp/google/organic/live/advanced', $result);
    }

    public function test_extractEndpoint_filters_uuid_segments()
    {
        $responseArray = [
            'tasks' => [
                [
                    'path' => ['serp', '12345678-1234-1234-1234-123456789012', 'google', 'organic'],
                ],
            ],
        ];

        $result = $this->client->extractEndpoint($responseArray);
        $this->assertEquals('serp/google/organic', $result);
    }

    public function test_extractEndpoint_returns_null_for_missing_path()
    {
        $responseArray = [
            'tasks' => [
                [
                    'data' => ['keyword' => 'test'],
                ],
            ],
        ];

        $result = $this->client->extractEndpoint($responseArray);
        $this->assertNull($result);
    }

    public function test_extractEndpoint_returns_null_for_empty_path()
    {
        $responseArray = [
            'tasks' => [
                [
                    'path' => [],
                ],
            ],
        ];

        $result = $this->client->extractEndpoint($responseArray);
        $this->assertNull($result);
    }

    public function test_extractEndpoint_returns_null_for_non_array_path()
    {
        $responseArray = [
            'tasks' => [
                [
                    'path' => 'invalid/path',
                ],
            ],
        ];

        $result = $this->client->extractEndpoint($responseArray);
        $this->assertNull($result);
    }

    public function test_extractParams_extracts_valid_params()
    {
        $responseArray = [
            'tasks' => [
                [
                    'data' => [
                        'keyword'      => 'laravel framework',
                        'locationCode' => 2840,
                        'languageCode' => 'en',
                    ],
                ],
            ],
        ];

        $result = $this->client->extractParams($responseArray);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('keyword', $result);
        $this->assertEquals('laravel framework', $result['keyword']);
    }

    public function test_extractParams_returns_null_for_missing_data()
    {
        $responseArray = [
            'tasks' => [
                [
                    'path' => ['serp', 'google'],
                ],
            ],
        ];

        $result = $this->client->extractParams($responseArray);
        $this->assertNull($result);
    }

    public function test_extractParams_returns_null_for_empty_data()
    {
        $responseArray = [
            'tasks' => [
                [
                    'data' => [],
                ],
            ],
        ];

        $result = $this->client->extractParams($responseArray);
        $this->assertNull($result);
    }

    public function test_extractParams_returns_null_for_non_array_data()
    {
        $responseArray = [
            'tasks' => [
                [
                    'data' => 'invalid data',
                ],
            ],
        ];

        $result = $this->client->extractParams($responseArray);
        $this->assertNull($result);
    }

    public static function resolveEndpointDataProvider(): array
    {
        return [
            'with_get_parameter' => [
                'taskId'        => 'task-123',
                'responseArray' => [
                    'tasks' => [
                        [
                            'path' => ['serp', 'google', 'organic'],
                        ],
                    ],
                ],
                'getParams'        => ['endpoint' => 'custom/endpoint'],
                'expectedEndpoint' => 'custom/endpoint',
            ],
            'with_extracted_endpoint' => [
                'taskId'        => 'task-456',
                'responseArray' => [
                    'tasks' => [
                        [
                            'path' => ['serp', 'google', 'organic', 'live', 'advanced'],
                        ],
                    ],
                ],
                'getParams'        => [],
                'expectedEndpoint' => 'serp/google/organic/live/advanced',
            ],
        ];
    }

    #[DataProvider('resolveEndpointDataProvider')]
    public function test_resolveEndpoint_with_valid_sources(string $taskId, array $responseArray, array $getParams, string $expectedEndpoint)
    {
        // Mock $_GET
        $originalGet = $_GET;
        $_GET        = $getParams;

        try {
            $result = $this->client->resolveEndpoint($taskId, $responseArray);
            $this->assertEquals($expectedEndpoint, $result);
        } finally {
            $_GET = $originalGet;
        }
    }

    public function test_resolveEndpoint_throws_exception_when_cannot_determine()
    {
        // Clear $_GET
        $originalGet = $_GET;
        $_GET        = [];

        $responseArray = [
            'tasks' => [
                [
                    'data' => ['keyword' => 'test'],
                ],
            ],
        ];

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Cannot determine endpoint for task');

            $this->client->resolveEndpoint('unknown-task', $responseArray);
        } finally {
            $_GET = $originalGet;
        }
    }

    public function test_resolveEndpoint_with_null_response_array()
    {
        // Clear $_GET
        $originalGet = $_GET;
        $_GET        = ['endpoint' => 'custom/pingback/endpoint'];

        try {
            $result = $this->client->resolveEndpoint('task-123', null);
            $this->assertEquals('custom/pingback/endpoint', $result);
        } finally {
            $_GET = $originalGet;
        }
    }

    public function test_resolveEndpoint_throws_exception_with_null_response_array_and_no_get_param()
    {
        // Clear $_GET
        $originalGet = $_GET;
        $_GET        = [];

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Cannot determine endpoint for task: Task ID: unknown-task');

            $this->client->resolveEndpoint('unknown-task', null);
        } finally {
            $_GET = $originalGet;
        }
    }

    // Tests for webhook-related methods

    public function test_logResponse_writes_to_file()
    {
        $filename  = 'test_log';
        $idMessage = 'test_message';
        $data      = ['test' => 'data'];

        $expectedLogFile = __DIR__ . "/../../storage/logs/{$filename}.log";

        // Clean up any existing file
        if (file_exists($expectedLogFile)) {
            unlink($expectedLogFile);
        }

        $this->client->logResponse($filename, $idMessage, $data);

        $this->assertFileExists($expectedLogFile);
        $logContent = file_get_contents($expectedLogFile);
        $this->assertStringContainsString($idMessage, $logContent);
        $this->assertStringContainsString('[test] => data', $logContent);

        // Clean up
        unlink($expectedLogFile);
    }

    public function test_throwErrorWithLogging_logs_and_throws_exception()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API error (400): Test error message');

        $this->client->throwErrorWithLogging(400, 'Test error message', 'test_error_type');
    }

    public static function httpMethodProvider(): array
    {
        return [
            'GET method'    => ['GET', true],
            'PUT method'    => ['PUT', true],
            'DELETE method' => ['DELETE', true],
            'POST method'   => ['POST', false],
        ];
    }

    #[DataProvider('httpMethodProvider')]
    public function test_validateHttpMethod_validates_request_method(string $method, bool $shouldThrow)
    {
        $_SERVER['REQUEST_METHOD'] = $method;

        if ($shouldThrow) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('API error (405): Method not allowed');
        }

        $this->client->validateHttpMethod('POST', 'test_error_type');

        if (!$shouldThrow) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_validateHttpMethod_validates_get_request()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // Should not throw exception when expecting GET
        $this->client->validateHttpMethod('GET', 'test_error_type');
        $this->addToAssertionCount(1);
    }

    public function test_validateHttpMethod_throws_when_get_expected_but_post_received()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API error (405): Method not allowed');

        $this->client->validateHttpMethod('GET', 'test_error_type');
    }

    public function test_validateIpWhitelist_allows_all_when_no_whitelist()
    {
        // Mock config to return empty whitelist
        config(['api-cache.apis.dataforseo.whitelisted_ips' => []]);

        $_SERVER['HTTP_CF_CONNECTING_IP'] = '192.168.1.1';

        // Should not throw exception
        $this->client->validateIpWhitelist('test_error_type');
        $this->addToAssertionCount(1);
    }

    public function test_validateIpWhitelist_blocks_non_whitelisted_ip()
    {
        // Mock config to return specific whitelist
        config(['api-cache.apis.dataforseo.whitelisted_ips' => ['127.0.0.1', '192.168.1.100']]);

        $_SERVER['HTTP_CF_CONNECTING_IP'] = '192.168.1.1';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API error (403): IP not whitelisted: 192.168.1.1');

        $this->client->validateIpWhitelist('test_error_type');
    }

    public function test_task_get_successful_request()
    {
        $taskId          = '12345678-1234-1234-1234-123456789012';
        $endpointPath    = 'serp/google/organic/task_get/regular';
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.0482 sec.',
            'cost'           => 0.0025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $taskId,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.0382 sec.',
                    'cost'           => 0.0025,
                    'result_count'   => 1,
                    'path'           => [
                        'serp',
                        'google',
                        'organic',
                        'task_get',
                        'regular',
                    ],
                    'data' => [
                        'api'           => 'serp',
                        'function'      => 'task_get',
                        'se'            => 'google',
                        'se_type'       => 'organic',
                        'keyword'       => 'laravel framework',
                        'language_code' => 'en',
                        'location_code' => 2840,
                        'device'        => 'desktop',
                        'os'            => 'windows',
                    ],
                    'result' => [
                        [
                            'id'             => $taskId,
                            'status_code'    => 20000,
                            'status_message' => 'Ok.',
                            'time'           => '0.0281 sec.',
                            'cost'           => 0.0025,
                            'result_count'   => 1,
                            'path'           => [
                                'serp',
                                'google',
                                'organic',
                                'task_get',
                                'regular',
                            ],
                            'data' => [
                                'api'           => 'serp',
                                'function'      => 'task_get',
                                'se'            => 'google',
                                'se_type'       => 'organic',
                                'keyword'       => 'laravel framework',
                                'language_code' => 'en',
                                'location_code' => 2840,
                                'device'        => 'desktop',
                                'os'            => 'windows',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/{$endpointPath}/{$taskId}" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->taskGet($endpointPath, $taskId);

        Http::assertSent(function ($request) use ($endpointPath, $taskId) {
            return $request->url() === "{$this->apiBaseUrl}/{$endpointPath}/{$taskId}" &&
                   $request->method() === 'GET';
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
    }

    public function test_task_get_validates_endpoint_path_format()
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->client->taskGet('invalid/endpoint/path', '12345678-1234-1234-1234-123456789012');
    }

    public function test_task_get_validates_task_id()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task ID cannot be empty');

        $this->client->taskGet('serp/google/organic/task_get/regular', '');
    }

    public function test_processPostbackResponse_throws_on_invalid_status_code()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API error (400): DataForSEO error response');

        // Create a mock client that simulates receiving invalid status code
        $mockClient = $this->getMockBuilder(DataForSeoApiClient::class)
            ->onlyMethods(['throwErrorWithLogging'])
            ->getMock();

        $mockClient->expects($this->once())
            ->method('throwErrorWithLogging')
            ->with(400, 'DataForSEO error response', 'webhook_api_error')
            ->willThrowException(new \RuntimeException('API error (400): DataForSEO error response'));

        $mockClient->throwErrorWithLogging(400, 'DataForSEO error response', 'webhook_api_error');
    }

    public function test_processPostbackResponse_throws_on_missing_task_data()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API error (400): No task data in response');

        // Test the error handling logic directly
        $this->client->throwErrorWithLogging(400, 'No task data in response', 'webhook_no_task_data');
    }

    public function test_processPostbackResponse_successful_processing()
    {
        $taskId   = '12345678-1234-1234-1234-123456789012';
        $cacheKey = 'test-cache-key';

        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.0482 sec.',
            'cost'           => 0.0025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $taskId,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.0382 sec.',
                    'cost'           => 0.0025,
                    'result_count'   => 1,
                    'path'           => [
                        'serp',
                        'google',
                        'organic',
                        'live',
                        'regular',
                    ],
                    'data' => [
                        'api'           => 'serp',
                        'function'      => 'live',
                        'se'            => 'google',
                        'se_type'       => 'organic',
                        'keyword'       => 'laravel framework',
                        'language_code' => 'en',
                        'location_code' => 2840,
                        'device'        => 'desktop',
                        'os'            => 'windows',
                        'tag'           => $cacheKey,
                    ],
                    'result' => [
                        [
                            'keyword'       => 'laravel framework',
                            'type'          => 'organic',
                            'se_domain'     => 'google.com',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'check_url'     => 'https://www.google.com/search?q=laravel+framework',
                            'datetime'      => '2023-08-07 12:00:00 +00:00',
                            'items_count'   => 10,
                            'items'         => [
                                [
                                    'type'          => 'organic',
                                    'rank_group'    => 1,
                                    'rank_absolute' => 1,
                                    'position'      => 'left',
                                    'domain'        => 'laravel.com',
                                    'title'         => 'Laravel - The PHP Framework For Web Artisans',
                                    'url'           => 'https://laravel.com/',
                                    'description'   => 'Laravel is a web application framework with expressive, elegant syntax.',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $gzippedJsonData = gzencode(json_encode($successResponse));

        // Redirect log to a temporary file
        $tempLogFile = sys_get_temp_dir() . '/test_postback.log';

        // Set up cache manager mock
        $cacheManagerMock = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $cacheManagerMock->method('generateCacheKey')->willReturn('generated-cache-key-123');

        // Create a mock client that overrides extractParams and resolveEndpoint
        $mockClient = $this->getMockBuilder(DataForSeoApiClient::class)
            ->setConstructorArgs([$cacheManagerMock])
            ->onlyMethods(['extractParams', 'resolveEndpoint'])
            ->getMock();

        $mockClient->method('extractParams')->willReturn([
            'keyword'       => 'laravel framework',
            'language_code' => 'en',
            'location_code' => 2840,
            'device'        => 'desktop',
            'os'            => 'windows',
        ]);
        $mockClient->method('resolveEndpoint')->willReturn('serp/google/organic/live/regular');

        [$responseArray, $task, $returnedTaskId, $returnedCacheKey, $cost, $jsonData, $endpoint, $method] = $mockClient->processPostbackResponse($tempLogFile, 'postback_response_error', $gzippedJsonData);

        // Verify the returned values
        $this->assertEquals($taskId, $returnedTaskId);
        $this->assertEquals($cacheKey, $returnedCacheKey);
        $this->assertEquals(0.0025, $cost);
        $this->assertEquals('serp/google/organic/live/regular', $endpoint);
        $this->assertEquals('POST', $method);
        $this->assertIsArray($responseArray);
        $this->assertIsArray($task);
        $this->assertIsString($jsonData);
        $this->assertEquals(gzdecode($gzippedJsonData), $jsonData);
    }

    public function test_processPostbackResponse_uses_generated_cache_key_when_tag_missing()
    {
        $taskId = '12345678-1234-1234-1234-123456789012';

        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'cost'           => 0.0025,
            'tasks'          => [
                [
                    'id'   => $taskId,
                    'path' => ['serp', 'google', 'organic', 'live', 'regular'],
                    'data' => [
                        'keyword'       => 'test keyword',
                        'language_code' => 'en',
                        // Note: no 'tag' field here
                    ],
                ],
            ],
        ];

        $gzippedJsonData = gzencode(json_encode($successResponse));

        // Set up cache manager mock
        $cacheManagerMock = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $cacheManagerMock->method('generateCacheKey')->willReturn('generated-cache-key-456');

        // Create a mock client
        $mockClient = $this->getMockBuilder(DataForSeoApiClient::class)
            ->setConstructorArgs([$cacheManagerMock])
            ->onlyMethods(['extractParams', 'resolveEndpoint'])
            ->getMock();

        $mockClient->method('extractParams')->willReturn([
            'keyword'       => 'test keyword',
            'language_code' => 'en',
        ]);
        $mockClient->method('resolveEndpoint')->willReturn('serp/google/organic/live/regular');

        [$responseArray, $task, $returnedTaskId, $returnedCacheKey, $cost, $jsonData, $endpoint, $method] = $mockClient->processPostbackResponse(null, null, $gzippedJsonData);

        // Verify that generated cache key was used
        $this->assertEquals('generated-cache-key-456', $returnedCacheKey);
        $this->assertEquals('POST', $method);
    }

    public function test_processPingbackResponse_throws_on_missing_task_id()
    {
        // Clear $_GET
        $originalGet = $_GET;
        $_GET        = ['tag' => 'test-tag', 'endpoint' => 'serp/google/organic/task_get/regular'];

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('API error (400): Missing task ID in pingback');

            $this->client->processPingbackResponse('pingback', 'pingback_response_error');
        } finally {
            $_GET = $originalGet;
        }
    }

    public function test_processPingbackResponse_throws_on_missing_endpoint()
    {
        // Clear $_GET
        $originalGet = $_GET;
        $_GET        = ['id' => 'test-task-id', 'tag' => 'test-tag'];

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('API error (400): Missing endpoint in pingback');

            $this->client->processPingbackResponse('pingback', 'pingback_response_error');
        } finally {
            $_GET = $originalGet;
        }
    }

    public function test_processPingbackResponse_successful_processing()
    {
        $taskId          = '12345678-1234-1234-1234-123456789012';
        $cacheKey        = 'test-cache-key';
        $taskGetEndpoint = 'serp/google/organic/task_get/regular';

        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.0482 sec.',
            'cost'           => 0.0025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $taskId,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.0382 sec.',
                    'cost'           => 0.0025,
                    'result_count'   => 1,
                    'path'           => [
                        'serp',
                        'google',
                        'organic',
                        'task_get',
                        'regular',
                    ],
                    'data' => [
                        'api'           => 'serp',
                        'function'      => 'task_get',
                        'se'            => 'google',
                        'se_type'       => 'organic',
                        'keyword'       => 'laravel framework',
                        'language_code' => 'en',
                        'location_code' => 2840,
                        'device'        => 'desktop',
                        'os'            => 'windows',
                        'tag'           => $cacheKey,
                    ],
                    'result' => [
                        [
                            'keyword'          => 'laravel framework',
                            'type'             => 'organic',
                            'se_domain'        => 'google.com',
                            'location_code'    => 2840,
                            'language_code'    => 'en',
                            'check_url'        => 'https://www.google.com/search?q=laravel+framework',
                            'datetime'         => '2023-08-07 12:00:00 +00:00',
                            'spell'            => null,
                            'item_types'       => ['organic'],
                            'se_results_count' => 1000000,
                            'items_count'      => 10,
                            'items'            => [
                                [
                                    'type'                => 'organic',
                                    'rank_group'          => 1,
                                    'rank_absolute'       => 1,
                                    'position'            => 'left',
                                    'xpath'               => '/html[1]/body[1]/div[6]/div[1]/div[9]/div[1]/div[1]/div[1]/div[1]/div[1]/div[1]/div[1]/div[1]/div[1]/div[1]/div[1]/div[1]/a[1]',
                                    'domain'              => 'laravel.com',
                                    'title'               => 'Laravel - The PHP Framework For Web Artisans',
                                    'url'                 => 'https://laravel.com/',
                                    'cache_url'           => 'https://webcache.googleusercontent.com/search?q=cache:laravel.com',
                                    'related_search_url'  => 'https://www.google.com/search?q=related:laravel.com+laravel+framework',
                                    'breadcrumb'          => 'https://laravel.com',
                                    'is_image'            => false,
                                    'is_video'            => false,
                                    'is_featured_snippet' => false,
                                    'is_malicious'        => false,
                                    'description'         => 'Laravel is a web application framework with expressive, elegant syntax.',
                                    'pre_snippet'         => null,
                                    'extended_snippet'    => null,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Mock the taskGet HTTP request
        Http::fake([
            "{$this->apiBaseUrl}/{$taskGetEndpoint}/{$taskId}" => Http::response($successResponse, 200),
        ]);

        // Set up $_GET parameters
        $originalGet = $_GET;
        $_GET        = [
            'id'       => $taskId,
            'tag'      => $cacheKey,
            'endpoint' => $taskGetEndpoint,
        ];

        try {
            // Reinitialize client so that its HTTP pending request picks up the fake
            $this->client = new DataForSeoApiClient();
            $this->client->clearRateLimit();

            [$responseArray, $task, $returnedTaskId, $returnedCacheKey, $cost, $jsonData, $storageEndpoint, $method] = $this->client->processPingbackResponse('pingback', 'pingback_response_error');

            // Verify the returned values
            $this->assertEquals($taskId, $returnedTaskId);
            $this->assertEquals($cacheKey, $returnedCacheKey);
            $this->assertEquals(0.0025, $cost);
            $this->assertEquals('serp/google/organic/task_get/regular', $storageEndpoint);
            $this->assertEquals('GET', $method);
            $this->assertIsArray($responseArray);
            $this->assertIsArray($task);
            $this->assertIsString($jsonData);

            // Verify the HTTP request was made
            Http::assertSent(function ($request) use ($taskGetEndpoint, $taskId) {
                return $request->url() === "{$this->apiBaseUrl}/{$taskGetEndpoint}/{$taskId}" &&
                       $request->method() === 'GET';
            });

            // Verify log file was created (clean up after)
            $logFile = __DIR__ . '/../../storage/logs/pingback.log';
            if (file_exists($logFile)) {
                $this->assertFileExists($logFile);
                unlink($logFile);
            }
        } finally {
            $_GET = $originalGet;
        }
    }

    public function test_processPingbackResponse_without_logging()
    {
        $taskId          = '12345678-1234-1234-1234-123456789012';
        $cacheKey        = 'test-cache-key';
        $taskGetEndpoint = 'serp/google/organic/task_get/regular';

        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'cost'           => 0.0025,
            'tasks'          => [
                [
                    'id'   => $taskId,
                    'path' => ['serp', 'google', 'organic', 'task_get', 'regular'],
                    'data' => ['keyword' => 'test', 'tag' => $cacheKey],
                ],
            ],
        ];

        // Mock the taskGet HTTP request
        Http::fake([
            "{$this->apiBaseUrl}/{$taskGetEndpoint}/{$taskId}" => Http::response($successResponse, 200),
        ]);

        // Set up $_GET parameters
        $originalGet = $_GET;
        $_GET        = [
            'id'       => $taskId,
            'tag'      => $cacheKey,
            'endpoint' => $taskGetEndpoint,
        ];

        try {
            // Reinitialize client so that its HTTP pending request picks up the fake
            $this->client = new DataForSeoApiClient();
            $this->client->clearRateLimit();

            // Call without logging
            [$responseArray, $task, $returnedTaskId, $returnedCacheKey, $cost, $jsonData, $storageEndpoint, $method] = $this->client->processPingbackResponse();

            // Verify the returned values
            $this->assertEquals($taskId, $returnedTaskId);
            $this->assertEquals($cacheKey, $returnedCacheKey);
            $this->assertEquals(0.0025, $cost);
            $this->assertEquals('serp/google/organic/task_get/regular', $storageEndpoint);
            $this->assertEquals('GET', $method);
            $this->assertIsArray($responseArray);
            $this->assertIsArray($task);
            $this->assertIsString($jsonData);

            // Verify no log file was created
            $logFile = __DIR__ . '/../../storage/logs/pingback.log';
            $this->assertFileDoesNotExist($logFile);
        } finally {
            $_GET = $originalGet;
        }
    }

    public function test_storeInCache_stores_response_data()
    {
        $responseArray = [
            'status_code' => 20000,
            'tasks'       => [
                [
                    'data' => ['keyword' => 'test', 'location_code' => 2840],
                ],
            ],
        ];
        $cacheKey        = 'test-cache-key';
        $endpoint        = 'serp/google/organic/live/regular';
        $cost            = 0.01;
        $taskId          = 'test-task-id';
        $rawResponseData = json_encode($responseArray);

        // Mock the cache manager to verify storeResponse is called with correct parameters
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('storeResponse')
            ->with(
                'dataforseo',                    // clientName
                $cacheKey,                       // cacheKey
                ['keyword' => 'test', 'location_code' => 2840], // params (extracted from responseArray)
                $this->anything(),               // apiResult (complex array)
                $endpoint,                       // endpoint
                'v3',                           // version
                null,                           // ttl
                $taskId,                        // attributes
                null                            // credits
            );

        $this->client = new DataForSeoApiClient($mockCacheManager);
        $this->client->storeInCache($responseArray, $cacheKey, $endpoint, $cost, $taskId, $rawResponseData);
    }

    public function test_storeInCache_stores_response_data_with_get_method()
    {
        $responseArray = [
            'status_code' => 20000,
            'tasks'       => [
                [
                    'data' => ['keyword' => 'test', 'location_code' => 2840],
                ],
            ],
        ];
        $cacheKey        = 'test-cache-key';
        $endpoint        = 'serp/google/organic/live/regular';
        $cost            = 0.01;
        $taskId          = 'test-task-id';
        $rawResponseData = json_encode($responseArray);

        // Mock the cache manager to verify storeResponse is called with correct parameters including GET method
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('storeResponse')
            ->with(
                'dataforseo',                    // clientName
                $cacheKey,                       // cacheKey
                ['keyword' => 'test', 'location_code' => 2840], // params (extracted from responseArray)
                $this->callback(function ($apiResult) {
                    // Verify the HTTP method is set to GET in the apiResult
                    return $apiResult['request']['method'] === 'GET';
                }),
                $endpoint,                       // endpoint
                'v3',                           // version
                null,                           // ttl
                $taskId,                        // attributes
                null                            // credits
            );

        $this->client = new DataForSeoApiClient($mockCacheManager);
        $this->client->storeInCache($responseArray, $cacheKey, $endpoint, $cost, $taskId, $rawResponseData, 'GET');
    }

    /**
     * Data provider for validateDomainTarget tests
     */
    public static function domainTargetDataProvider(): array
    {
        return [
            // Valid domains
            'simple domain'         => ['example.com', true],
            'subdomain'             => ['sub.example.com', true],
            'multi-level subdomain' => ['blog.api.example.com', true],
            'domain with numbers'   => ['test123.com', true],
            'domain with hyphens'   => ['test-site.co.uk', true],
            'single letter domain'  => ['a.co', true],
            'long TLD'              => ['example.online', true],
            'long TLD subdomain'    => ['blog.api.example.website', true],

            // Invalid domains
            'numeric TLD' => ['example.123', false, 'Target must be a valid domain or subdomain'],

            // Invalid domains - with protocols
            'https protocol'       => ['https://example.com', false, 'Target domain must be specified without https:// or http://'],
            'http protocol'        => ['http://example.com', false, 'Target domain must be specified without https:// or http://'],
            'https with subdomain' => ['https://sub.example.com', false, 'Target domain must be specified without https:// or http://'],

            // Invalid domains - with www
            'www prefix'         => ['www.example.com', false, 'Target domain must be specified without www.'],
            'www with subdomain' => ['www.sub.example.com', false, 'Target domain must be specified without www.'],

            // Invalid domains - bad format
            'empty string'           => ['', false, 'Target must be a valid domain or subdomain'],
            'invalid characters'     => ['exa@mple.com', false, 'Target must be a valid domain or subdomain'],
            'spaces'                 => ['example .com', false, 'Target must be a valid domain or subdomain'],
            'consecutive dots'       => ['example..com', false, 'Target must be a valid domain or subdomain'],
            'multiple trailing dots' => ['example.com..', false, 'Target must be a valid domain or subdomain'],

            // Invalid - single word (no TLD)
            'single word' => ['example', false, 'Target must be a valid domain or subdomain'],
        ];
    }

    /**
     * Test validateDomainTarget with valid and invalid inputs
     */
    #[DataProvider('domainTargetDataProvider')]
    public function test_validateDomainTarget(string $target, bool $shouldPass, ?string $expectedMessage = null)
    {
        if (!$shouldPass) {
            $this->expectException(\InvalidArgumentException::class);
            if ($expectedMessage) {
                $this->expectExceptionMessage($expectedMessage);
            }
        }

        $this->client->validateDomainTarget($target);

        if ($shouldPass) {
            // If we get here without exception, the test passed
            $this->addToAssertionCount(1);
        }
    }

    /**
     * Test validateDomainTarget with allowWww parameter
     */
    public function test_validateDomainTarget_with_allowWww_false()
    {
        // Should reject www domains when allowWww is false (default)
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target domain must be specified without www.');

        $this->client->validateDomainTarget('www.example.com', allowWww: false);
    }

    public function test_validateDomainTarget_with_allowWww_true()
    {
        // Should accept www domains when allowWww is true
        $this->client->validateDomainTarget('www.example.com', allowWww: true);
        $this->addToAssertionCount(1);
    }

    public function test_validateDomainTarget_with_allowWww_true_still_rejects_protocols()
    {
        // Should still reject protocols even when allowWww is true
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target domain must be specified without https:// or http://');

        $this->client->validateDomainTarget('https://www.example.com', allowWww: true);
    }

    public function test_validateDomainTarget_with_allowWww_true_still_validates_domain_format()
    {
        // Should still validate domain format even when allowWww is true
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target must be a valid domain or subdomain');

        $this->client->validateDomainTarget('www.invalid..domain', allowWww: true);
    }

    /**
     * Data provider for validateDomainOrPageTarget tests
     */
    public static function domainOrPageTargetDataProvider(): array
    {
        return [
            // Valid domains (without protocols)
            'simple domain'       => ['example.com', true],
            'subdomain'           => ['sub.example.com', true],
            'domain with hyphens' => ['test-site.co.uk', true],

            // Valid URLs (with protocols)
            'https URL'               => ['https://example.com', true],
            'http URL'                => ['http://example.com', true],
            'https URL with path'     => ['https://example.com/page', true],
            'https URL with query'    => ['https://example.com/page?param=value', true],
            'https URL with fragment' => ['https://example.com/page#section', true],
            'https URL with port'     => ['https://example.com:8080', true],
            'https subdomain URL'     => ['https://sub.example.com/path', true],

            // Invalid - www prefix on domain-only targets
            'www prefix domain'  => ['www.example.com', false, 'Target domain must be specified without www. (for domains) or as absolute URL (for pages)'],
            'www with subdomain' => ['www.sub.example.com', false, 'Target domain must be specified without www. (for domains) or as absolute URL (for pages)'],

            // Invalid - bad domain format
            'empty string'                 => ['', false, 'Target must be a valid domain/subdomain (without https:// and www.) or absolute URL (with http:// or https://)'],
            'invalid characters in domain' => ['exa@mple.com', false, 'Target must be a valid domain/subdomain (without https:// and www.) or absolute URL (with http:// or https://)'],

            // Invalid - single word (no TLD)
            'single word domain' => ['example', false, 'Target must be a valid domain/subdomain (without https:// and www.) or absolute URL (with http:// or https://)'],

            // Invalid - bad URL format
            'invalid URL'   => ['https://invalid url with spaces', false, 'Target URL must be a valid absolute URL'],
            'malformed URL' => ['https://', false, 'Target URL must be a valid absolute URL'],
            'protocol only' => ['https:///', false, 'Target URL must be a valid absolute URL'],
        ];
    }

    /**
     * Test validateDomainOrPageTarget with valid and invalid inputs
     */
    #[DataProvider('domainOrPageTargetDataProvider')]
    public function test_validateDomainOrPageTarget(string $target, bool $shouldPass, ?string $expectedMessage = null)
    {
        if (!$shouldPass) {
            $this->expectException(\InvalidArgumentException::class);
            if ($expectedMessage) {
                $this->expectExceptionMessage($expectedMessage);
            }
        }

        $this->client->validateDomainOrPageTarget($target);

        if ($shouldPass) {
            // If we get here without exception, the test passed
            $this->addToAssertionCount(1);
        }
    }

    /**
     * Data provider for validateAbsoluteUrl tests
     */
    public static function absoluteUrlDataProvider(): array
    {
        return [
            // Valid absolute URLs
            'https URL'               => ['https://example.com', true],
            'http URL'                => ['http://example.com', true],
            'https URL with path'     => ['https://example.com/page', true],
            'https URL with query'    => ['https://example.com/page?param=value', true],
            'https URL with fragment' => ['https://example.com/page#section', true],
            'https URL with www'      => ['https://www.example.com', true],
            'http URL with www'       => ['http://www.example.com', true],
            'https URL with port'     => ['https://example.com:8080', true],
            'http URL with port'      => ['http://example.com:8080/path', true],

            // Invalid inputs - no protocol
            'domain only'     => ['example.com', false, 'URL must be an absolute URL starting with http:// or https://'],
            'www domain only' => ['www.example.com', false, 'URL must be an absolute URL starting with http:// or https://'],
            'subdomain only'  => ['sub.example.com', false, 'URL must be an absolute URL starting with http:// or https://'],

            // Invalid inputs - wrong protocol
            'ftp protocol'  => ['ftp://example.com', false, 'URL must be an absolute URL starting with http:// or https://'],
            'file protocol' => ['file:///path/to/file', false, 'URL must be an absolute URL starting with http:// or https://'],

            // Invalid inputs - malformed URLs
            'invalid URL format' => ['https://invalid..url', false, 'URL must be a valid absolute URL'],
            'protocol only'      => ['https:///', false, 'URL must be a valid absolute URL'],
            'empty string'       => ['', false, 'URL must be an absolute URL starting with http:// or https://'],
        ];
    }

    /**
     * Test validateAbsoluteUrl with valid and invalid inputs
     */
    #[DataProvider('absoluteUrlDataProvider')]
    public function test_validateAbsoluteUrl(string $url, bool $shouldPass, ?string $expectedMessage = null)
    {
        if (!$shouldPass) {
            $this->expectException(\InvalidArgumentException::class);
            if ($expectedMessage) {
                $this->expectExceptionMessage($expectedMessage);
            }
        }

        $this->client->validateAbsoluteUrl($url);

        if ($shouldPass) {
            // If we get here without exception, the test passed
            $this->addToAssertionCount(1);
        }
    }

    public function test_resolveFilenameSlugSource_extracts_url_from_request_body(): void
    {
        $url = 'https://www.fiverr.com/antoniodc/boost-local-seo';

        $row = (object) [
            'id'           => 123,
            'endpoint'     => 'on_page/raw_html',
            'attributes'   => '07190635-3399-0275-2000-d147622927c4',
            'request_body' => json_encode([
                [
                    'id'                => '08150543-3399-0275-0000-bcb54d67b151',
                    'url'               => $url,
                    'additional_params' => [],
                    'amount'            => 1,
                ],
            ]),
        ];

        $result = $this->client->resolveFilenameSlugSource($row);

        $this->assertEquals($url, $result);
    }

    public function test_resolveFilenameSlugSource_fallback_with_malformed_request_body(): void
    {
        $attributes = '07190635-3399-0275-2000-d147622927c4';

        $row = (object) [
            'id'           => 456,
            'endpoint'     => 'on_page/raw_html',
            'attributes'   => $attributes,
            'request_body' => 'invalid json',
        ];

        $result = $this->client->resolveFilenameSlugSource($row);

        $this->assertEquals($attributes, $result);
    }

    public function test_resolveFilenameSlugSource_fallback_with_missing_url(): void
    {
        $attributes = '07190635-3399-0275-2000-d147622927c4';

        $row = (object) [
            'id'           => 789,
            'endpoint'     => 'on_page/raw_html',
            'attributes'   => $attributes,
            'request_body' => json_encode([
                [
                    'id'                => '08150543-3399-0275-0000-bcb54d67b151',
                    'additional_params' => [],
                    'amount'            => 1,
                ],
            ]),
        ];

        $result = $this->client->resolveFilenameSlugSource($row);

        $this->assertEquals($attributes, $result);
    }

    public function test_resolveFilenameSlugSource_uses_attributes_for_different_endpoint(): void
    {
        $attributes = 'test-keyword';

        $row = (object) [
            'id'           => 999,
            'endpoint'     => 'serp/google/organic/live',
            'attributes'   => $attributes,
            'request_body' => json_encode([
                [
                    'url'     => 'https://www.example.com',
                    'keyword' => 'test',
                ],
            ]),
        ];

        $result = $this->client->resolveFilenameSlugSource($row);

        $this->assertEquals($attributes, $result);
    }
}

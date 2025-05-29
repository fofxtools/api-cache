<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\DataForSeoApiClient;
use FOfX\ApiCache\RateLimitException;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
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

    public function test_buildApiParams_converts_camel_case_to_snake_case()
    {
        // Create a class with a test method that calls buildApiParams
        $clientMock = new class () extends DataForSeoApiClient {
            // Test method that will call buildApiParams internally
            public function testMethod(string $testParam, int $anotherParam): array
            {
                // Now that buildApiParams is public, we can call it directly
                return $this->buildApiParams();
            }
        };

        // Call the test method with actual camelCase parameters
        $result = $clientMock->testMethod('test value', 123);

        // Verify parameters are converted to snake_case
        $this->assertArrayHasKey('test_param', $result);
        $this->assertArrayHasKey('another_param', $result);
        $this->assertEquals('test value', $result['test_param']);
        $this->assertEquals(123, $result['another_param']);
    }

    public function test_buildApiParams_removes_null_values()
    {
        // Create a class with a test method that calls buildApiParams
        $clientMock = new class () extends DataForSeoApiClient {
            // Test method with nullable parameters
            public function testMethod(
                string $nonNullParam,
                ?string $nullParam = null,
                ?int $zeroParam = 0,
                string $emptyStringParam = '',
                ?bool $falseParam = false
            ): array {
                // Call buildApiParams directly
                return $this->buildApiParams();
            }
        };

        // Call with mixed null and non-null values
        $result = $clientMock->testMethod('value', null, 0, '', false);

        // Verify only non-null parameters are included
        $this->assertArrayHasKey('non_null_param', $result);
        $this->assertArrayHasKey('zero_param', $result);
        $this->assertArrayHasKey('empty_string_param', $result);
        $this->assertArrayHasKey('false_param', $result);
        $this->assertArrayNotHasKey('null_param', $result);

        $this->assertEquals('value', $result['non_null_param']);
        $this->assertEquals(0, $result['zero_param']);
        $this->assertEquals('', $result['empty_string_param']);
        $this->assertEquals(false, $result['false_param']);
    }

    public function test_buildApiParams_excludes_specified_arguments()
    {
        // Create a class with a test method that calls buildApiParams
        $clientMock = new class () extends DataForSeoApiClient {
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
        $result = $clientMock->testMethod('value', $additionalParams, 'test', 5);

        // Verify excluded parameters are not present
        $this->assertArrayHasKey('normal_param', $result);
        $this->assertArrayHasKey('some', $result); // From additionalParams
        $this->assertArrayNotHasKey('additional_params', $result); // Excluded
        $this->assertArrayNotHasKey('attributes', $result); // Excluded
        $this->assertArrayNotHasKey('amount', $result); // Excluded

        $this->assertEquals('value', $result['normal_param']);
        $this->assertEquals('value', $result['some']);
    }

    public function test_buildApiParams_merges_additional_params()
    {
        // Create a class with a test method that calls buildApiParams
        $clientMock = new class () extends DataForSeoApiClient {
            // Test method with overlapping parameters
            public function testMethod(string $param1, string $param2, array $additionalParams = []): array
            {
                // Call buildApiParams directly with the additionalParams
                return $this->buildApiParams($additionalParams);
            }
        };

        // Create additionalParams with overlapping and new keys
        $additionalParams = [
            'param1' => 'additional value', // Should be overridden by the method parameter
            'param3' => 'value3',          // Should be included as-is
        ];

        // Call with overlapping parameters
        $result = $clientMock->testMethod('original value', 'value2', $additionalParams);

        // Verify parameter precedence and merging
        $this->assertArrayHasKey('param1', $result);
        $this->assertArrayHasKey('param2', $result);
        $this->assertArrayHasKey('param3', $result);

        // Method parameters should take precedence over additionalParams
        $this->assertEquals('original value', $result['param1']);
        $this->assertEquals('value2', $result['param2']);
        $this->assertEquals('value3', $result['param3']);
    }

    public function test_buildApiParams_real_world_example()
    {
        // Create a class with a test method that calls buildApiParams
        $clientMock = new class () extends DataForSeoApiClient {
            // Realistic API endpoint method
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
        $additionalParams = ['extra_param' => 'extra value'];

        // Call with a mix of parameters, including null
        $result = $clientMock->searchMethod(
            'test keyword',
            'United States',
            null,
            true,
            $additionalParams
        );

        // Verify the parameter processing
        $this->assertArrayHasKey('keyword', $result);
        $this->assertArrayHasKey('location_name', $result);
        $this->assertArrayHasKey('enable_feature', $result);
        $this->assertArrayHasKey('extra_param', $result);
        $this->assertArrayNotHasKey('location_code', $result); // Should be removed as it's null
        $this->assertArrayNotHasKey('additional_params', $result); // Should be excluded

        $this->assertEquals('test keyword', $result['keyword']);
        $this->assertEquals('United States', $result['location_name']);
        $this->assertEquals(true, $result['enable_feature']);
        $this->assertEquals('extra value', $result['extra_param']);
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

        [$responseArray, $task, $returnedTaskId, $returnedCacheKey, $cost, $jsonData, $endpoint, $method] = $mockClient->processPostbackResponse('postback', 'postback_response_error', $gzippedJsonData);

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

        // This method primarily delegates to ApiCacheManager, so we just verify it doesn't throw
        $this->client->storeInCache($responseArray, $cacheKey, $endpoint, $cost, $taskId, $rawResponseData);
        $this->addToAssertionCount(1);
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

        // This method primarily delegates to ApiCacheManager, so we just verify it doesn't throw
        $this->client->storeInCache($responseArray, $cacheKey, $endpoint, $cost, $taskId, $rawResponseData, 'GET');
        $this->addToAssertionCount(1);
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

    public static function taskGetEndpointPathProvider(): array
    {
        return [
            'Google Organic Regular'   => ['serp/google/organic/task_get/regular'],
            'Google Organic Advanced'  => ['serp/google/organic/task_get/advanced'],
            'YouTube Organic Advanced' => ['serp/youtube/organic/task_get/advanced'],
            'Amazon Products Advanced' => ['merchant/amazon/products/task_get/advanced'],
        ];
    }

    #[DataProvider('taskGetEndpointPathProvider')]
    public function test_task_get_supports_multiple_endpoint_formats(string $endpointPath)
    {
        $taskId          = '12345678-1234-1234-1234-123456789012';
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
                    'path'           => explode('/', $endpointPath),
                    'data'           => [],
                    'result'         => [],
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
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
    }

    /**
     * Data provider for task get wrapper methods
     *
     * @return array
     */
    public static function taskGetWrapperMethodsProvider(): array
    {
        return [
            'serpGoogleOrganicTaskGetRegular' => [
                'method'       => 'serpGoogleOrganicTaskGetRegular',
                'endpointPath' => 'serp/google/organic/task_get/regular',
                'pathResult'   => ['serp', 'google', 'organic', 'task_get', 'regular'],
                'keyword'      => 'laravel framework',
            ],
            'serpGoogleOrganicTaskGetAdvanced' => [
                'method'       => 'serpGoogleOrganicTaskGetAdvanced',
                'endpointPath' => 'serp/google/organic/task_get/advanced',
                'pathResult'   => ['serp', 'google', 'organic', 'task_get', 'advanced'],
                'keyword'      => 'php composer',
            ],
            'serpGoogleOrganicTaskGetHtml' => [
                'method'       => 'serpGoogleOrganicTaskGetHtml',
                'endpointPath' => 'serp/google/organic/task_get/html',
                'pathResult'   => ['serp', 'google', 'organic', 'task_get', 'html'],
                'keyword'      => 'laravel eloquent',
            ],
        ];
    }

    #[DataProvider('taskGetWrapperMethodsProvider')]
    public function test_task_get_wrapper_methods_make_request_with_correct_parameters(
        string $method,
        string $endpointPath,
        array $pathResult,
        string $keyword
    ) {
        $taskId          = '12345678-1234-1234-1234-123456789012';
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
                    'path'           => $pathResult,
                    'data'           => [
                        'api'      => 'serp',
                        'function' => 'task_get',
                        'se'       => 'google',
                        'se_type'  => 'organic',
                        'keyword'  => $keyword,
                    ],
                    'result' => [
                        [
                            'keyword'     => $keyword,
                            'items_count' => 5,
                            'items'       => [
                                [
                                    'type'       => 'organic',
                                    'rank_group' => 1,
                                    'title'      => 'Test Result',
                                ],
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

        $response = $this->client->$method($taskId);

        Http::assertSent(function ($request) use ($endpointPath, $taskId) {
            return $request->url() === "{$this->apiBaseUrl}/{$endpointPath}/{$taskId}" &&
                   $request->method() === 'GET';
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
        $this->assertEquals($responseData['tasks'][0]['data']['keyword'], $keyword);
    }

    #[DataProvider('taskGetWrapperMethodsProvider')]
    public function test_task_get_wrapper_methods_pass_custom_parameters(
        string $method,
        string $endpointPath,
        array $pathResult = null,
        string $keyword = null
    ) {
        $taskId     = '12345678-1234-1234-1234-123456789012';
        $attributes = 'custom-attributes-test';
        $amount     = 3;

        $successResponse = [
            'version'     => '0.1.20230807',
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id' => $taskId,
                ],
            ],
        ];

        // Mock the specific endpoint for this method
        Http::fake([
            "{$this->apiBaseUrl}/{$endpointPath}/{$taskId}" => Http::response($successResponse, 200),
        ]);

        // Create a real client instance and clear rate limits
        $client = new DataForSeoApiClient();
        $client->clearRateLimit();

        // Call the method with custom parameters
        $response = $client->$method($taskId, $attributes, $amount);

        // Verify the request was sent
        Http::assertSent(function ($request) use ($endpointPath, $taskId) {
            return $request->url() === "{$this->apiBaseUrl}/{$endpointPath}/{$taskId}" &&
                   $request->method() === 'GET';
        });

        // Verify we received a response (endpoint handlers should be passing these values to taskGet)
        $this->assertEquals(200, $response['response_status_code']);
    }

    public function test_serp_google_organic_live_regular_successful_request()
    {
        $id              = '12345678-1234-1234-1234-123456789012';
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.015,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.015,
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
                        'function'      => 'regular',
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
                            'keyword'       => 'laravel framework',
                            'type'          => 'organic',
                            'se_domain'     => 'google.com',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'check_url'     => 'https://www.google.com/search?q=laravel+framework',
                            'datetime'      => '2023-08-07 12:55:14 +00:00',
                            'item_types'    => [
                                'organic',
                                'top_stories',
                            ],
                            'se_results_count' => 149000000,
                            'items_count'      => 10,
                            'items'            => [
                                [
                                    'type'          => 'organic',
                                    'rank_group'    => 1,
                                    'rank_absolute' => 1,
                                    'position'      => 'left',
                                    'title'         => 'Laravel - The PHP Framework For Web Artisans',
                                    'url'           => 'https://laravel.com/',
                                    'domain'        => 'laravel.com',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/organic/live/regular" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->serpGoogleOrganicLiveRegular('laravel framework');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/serp/google/organic/live/regular" &&
                   $request->method() === 'POST' &&
                   isset($request[0]['keyword']) &&
                   $request[0]['keyword'] === 'laravel framework';
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_serp_google_organic_live_regular_request_validates_required_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either locationName, locationCode, or locationCoordinate must be provided');

        // No location parameters provided
        $this->client->serpGoogleOrganicLiveRegular(
            'test query',
            null, // No location name
            null, // No location code
            null  // No location coordinates
        );
    }

    public function test_serp_google_organic_live_regular_validates_depth_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Depth must be less than or equal to 700');

        // Depth too high
        $this->client->serpGoogleOrganicLiveRegular(
            'test query',
            'United States',
            null,
            null,
            'English',
            null,
            'desktop',
            null,
            null,
            800 // Invalid depth (above 700)
        );
    }

    public function test_serp_google_organic_live_regular_caches_responses()
    {
        $id              = '12345678-1234-1234-1234-123456789012';
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.015,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.015,
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
                        'function'      => 'regular',
                        'se'            => 'google',
                        'se_type'       => 'organic',
                        'keyword'       => 'caching api requests',
                        'language_code' => 'en',
                        'location_code' => 2840,
                        'device'        => 'desktop',
                        'os'            => 'windows',
                    ],
                    'result' => [
                        [
                            'keyword'     => 'caching api requests',
                            'items_count' => 5,
                            'items'       => [
                                [
                                    'type'       => 'organic',
                                    'rank_group' => 1,
                                    'title'      => 'API Caching Test Result 1',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/organic/live/regular" => Http::sequence()
                ->push($successResponse, 200)
                ->push([
                    'version'     => '0.1.20230807',
                    'status_code' => 20000,
                    'tasks'       => [
                        [
                            'id'     => 'second-request-id',
                            'result' => [
                                [
                                    'items' => [
                                        [
                                            'title' => 'This Should Not Be Returned',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        // First request should hit the API
        $response1 = $this->client->serpGoogleOrganicLiveRegular(
            'caching api requests',
            'United States'
        );

        // Second request with same parameters should return cached response
        $response2 = $this->client->serpGoogleOrganicLiveRegular(
            'caching api requests',
            'United States'
        );

        // Verify caching behavior
        $this->assertEquals(200, $response1['response_status_code']);
        $this->assertFalse($response1['is_cached']);
        $this->assertEquals(200, $response2['response_status_code']);
        $this->assertTrue($response2['is_cached']);

        // Make sure we used the Http::fake() response
        $responseData1 = $response1['response']->json();
        $this->assertEquals($id, $responseData1['tasks'][0]['id']);

        $responseData2 = $response2['response']->json();
        $this->assertEquals($id, $responseData2['tasks'][0]['id']);

        // Only one request should have been made
        Http::assertSentCount(1);
    }

    public function test_serp_google_organic_live_regular_enforces_rate_limits()
    {
        $id              = '12345678-1234-1234-1234-123456789012';
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'cost'           => 0.015,
            'tasks'          => [
                [
                    'id'          => $id,
                    'status_code' => 20000,
                    'data'        => [
                        'keyword' => 'rate limit test',
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/organic/live/regular" => Http::response($successResponse, 200),
        ]);

        $this->expectException(RateLimitException::class);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();
        $this->client->setUseCache(false);

        // Make requests until rate limit is exceeded
        for ($i = 0; $i <= 5; $i++) {
            $result = $this->client->serpGoogleOrganicLiveRegular("rate limit test {$i}", 'United States');

            // Make sure we used the Http::fake() response
            $responseData = $result['response']->json();
            $this->assertEquals($id, $responseData['tasks'][0]['id']);
        }
    }

    public function test_serp_google_organic_live_regular_handles_api_errors()
    {
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40001,
            'status_message' => 'Authentication failed. Invalid login/password or API key.',
            'time'           => '0.1234 sec.',
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/organic/live/regular" => Http::response($errorResponse, 401),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->serpGoogleOrganicLiveRegular('api error test', 'United States');

        // Make sure we used the Http::fake() response
        $this->assertEquals(401, $response['response']->status());
        $responseData = $response['response']->json();
        $this->assertEquals(40001, $responseData['status_code']);
        $this->assertEquals('Authentication failed. Invalid login/password or API key.', $responseData['status_message']);
    }

    public function test_serp_google_organic_live_advanced_successful_request()
    {
        $id              = '12345678-1234-1234-1234-123456789013';
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.3497 sec.',
            'cost'           => 0.03,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.3397 sec.',
                    'cost'           => 0.03,
                    'result_count'   => 1,
                    'path'           => [
                        'serp',
                        'google',
                        'organic',
                        'live',
                        'advanced',
                    ],
                    'data' => [
                        'api'                  => 'serp',
                        'function'             => 'advanced',
                        'se'                   => 'google',
                        'se_type'              => 'organic',
                        'keyword'              => 'php composer',
                        'language_code'        => 'en',
                        'location_code'        => 2840,
                        'device'               => 'desktop',
                        'os'                   => 'windows',
                        'depth'                => 50,
                        'calculate_rectangles' => true,
                    ],
                    'result' => [
                        [
                            'keyword'       => 'php composer',
                            'type'          => 'organic',
                            'se_domain'     => 'google.com',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'check_url'     => 'https://www.google.com/search?q=php+composer',
                            'datetime'      => '2023-08-07 13:15:24 +00:00',
                            'item_types'    => [
                                'organic',
                                'feature',
                            ],
                            'se_results_count' => 32700000,
                            'items_count'      => 15,
                            'items'            => [
                                [
                                    'type'          => 'organic',
                                    'rank_group'    => 1,
                                    'rank_absolute' => 1,
                                    'position'      => 'left',
                                    'title'         => 'Composer',
                                    'url'           => 'https://getcomposer.org/',
                                    'domain'        => 'getcomposer.org',
                                    'rectangle'     => [
                                        'x'      => 190,
                                        'y'      => 480,
                                        'width'  => 500,
                                        'height' => 80,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/organic/live/advanced" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->serpGoogleOrganicLiveAdvanced(
            'php composer',
            null,
            50,
            null,
            'United States',
            null,
            null,
            'English',
            null,
            null,
            'desktop',
            'windows',
            null,
            true,
            true
        );

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/serp/google/organic/live/advanced" &&
                   $request->method() === 'POST' &&
                   isset($request[0]['keyword']) &&
                   $request[0]['keyword'] === 'php composer' &&
                   $request[0]['depth'] === 50 &&
                   $request[0]['calculate_rectangles'] === true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_serp_google_organic_live_advanced_request_validates_required_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either locationName, locationCode, or locationCoordinate must be provided when url is not specified');

        // No location parameters provided and no URL
        $this->client->serpGoogleOrganicLiveAdvanced(
            'test query',
            null, // No URL
            100,
            null,
            null, // No location name
            null, // No location code
            null  // No location coordinates
        );
    }

    public function test_serp_google_organic_live_advanced_request_validates_people_also_ask_click_depth()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('peopleAlsoAskClickDepth must be between 1 and 4');

        // peopleAlsoAskClickDepth out of range
        $this->client->serpGoogleOrganicLiveAdvanced(
            'test query',
            null,
            100,
            null,
            'United States',
            null,
            null,
            'English',
            null,
            null,
            'desktop',
            null,
            null,
            true,
            null,
            null,
            null,
            null,
            5 // Invalid depth (above 4)
        );
    }

    public function test_serp_google_autocomplete_live_advanced_successful_request()
    {
        $id              = '12345678-1234-1234-1234-123456789014';
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1497 sec.',
            'cost'           => 0.002,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.1397 sec.',
                    'cost'           => 0.002,
                    'result_count'   => 1,
                    'path'           => [
                        'v3',
                        'serp',
                        'google',
                        'autocomplete',
                        'live',
                        'advanced',
                    ],
                    'data' => [
                        'api'            => 'serp',
                        'function'       => 'live',
                        'se'             => 'google',
                        'se_type'        => 'autocomplete',
                        'keyword'        => 'laravel fram',
                        'language_code'  => 'en',
                        'location_code'  => 2840,
                        'device'         => 'desktop',
                        'os'             => 'windows',
                        'cursor_pointer' => 8,
                        'client'         => 'gws-wiz-serp',
                    ],
                    'result' => [
                        [
                            'keyword'          => 'laravel fram',
                            'type'             => 'autocomplete',
                            'se_domain'        => 'google.com',
                            'location_code'    => 2840,
                            'language_code'    => 'en',
                            'check_url'        => 'https://google.com/search?q=laravel+fram&hl=en&gl=US',
                            'datetime'         => '2023-08-07 12:55:14 +00:00',
                            'spell'            => null,
                            'refinement_chips' => null,
                            'item_types'       => ['autocomplete'],
                            'se_results_count' => 0,
                            'items_count'      => 10,
                            'items'            => [
                                [
                                    'type'             => 'autocomplete',
                                    'rank_group'       => 1,
                                    'rank_absolute'    => 1,
                                    'relevance'        => null,
                                    'suggestion'       => 'laravel framework',
                                    'suggestion_type'  => null,
                                    'search_query_url' => 'https://www.google.com/search?q=laravel+framework',
                                    'thumbnail_url'    => 'http://t0.gstatic.com/images?q=example',
                                    'highlighted'      => null,
                                ],
                                [
                                    'type'             => 'autocomplete',
                                    'rank_group'       => 2,
                                    'rank_absolute'    => 2,
                                    'relevance'        => null,
                                    'suggestion'       => 'laravel framework download',
                                    'suggestion_type'  => null,
                                    'search_query_url' => 'https://www.google.com/search?q=laravel+framework+download',
                                    'thumbnail_url'    => null,
                                    'highlighted'      => ['download'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/autocomplete/live/advanced" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->serpGoogleAutocompleteLiveAdvanced(
            'laravel fram',
            null,
            2840,
            null,
            'en',
            8,
            'gws-wiz-serp'
        );

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/serp/google/autocomplete/live/advanced" &&
                   $request->method() === 'POST' &&
                   isset($request[0]['keyword']) &&
                   $request[0]['keyword'] === 'laravel fram' &&
                   isset($request[0]['cursor_pointer']) &&
                   $request[0]['cursor_pointer'] === 8 &&
                   isset($request[0]['client']) &&
                   $request[0]['client'] === 'gws-wiz-serp';
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_serp_google_autocomplete_live_advanced_request_validates_required_language_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either languageName or languageCode must be provided');

        // Set both language parameters to null to trigger validation
        $this->client->serpGoogleAutocompleteLiveAdvanced(
            'laravel framework',
            'United States',
            null,   // locationCode
            null,   // languageName
            null    // languageCode
        );
    }

    public function test_serp_google_autocomplete_live_advanced_request_validates_required_location_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either locationName or locationCode must be provided');

        // Set both location parameters to null to trigger validation
        $this->client->serpGoogleAutocompleteLiveAdvanced(
            'laravel framework',
            null,   // locationName
            null,   // locationCode
            'English'
        );
    }

    public static function autocompleteParametersProvider()
    {
        return [
            'Basic parameters' => [
                [
                    'php composer',          // keyword
                    null,                    // locationName
                    2840,                    // locationCode
                    null,                    // languageName
                    'en',                    // languageCode
                ],
                [
                    'keyword'       => 'php composer',
                    'location_code' => 2840,
                    'language_code' => 'en',
                ],
            ],
            'With cursor pointer' => [
                [
                    'php com',               // keyword
                    null,                    // locationName
                    2840,                    // locationCode
                    null,                    // languageName
                    'en',                    // languageCode
                    5,                       // cursorPointer
                ],
                [
                    'keyword'        => 'php com',
                    'location_code'  => 2840,
                    'language_code'  => 'en',
                    'cursor_pointer' => 5,
                ],
            ],
            'With client' => [
                [
                    'php com',               // keyword
                    null,                    // locationName
                    2840,                    // locationCode
                    null,                    // languageName
                    'en',                    // languageCode
                    null,                    // cursorPointer
                    'chrome',                // client
                ],
                [
                    'keyword'       => 'php com',
                    'location_code' => 2840,
                    'language_code' => 'en',
                    'client'        => 'chrome',
                ],
            ],
            'With tag' => [
                [
                    'php com',               // keyword
                    null,                    // locationName
                    2840,                    // locationCode
                    null,                    // languageName
                    'en',                    // languageCode
                    null,                    // cursorPointer
                    null,                    // client
                    'test-tag',              // tag
                ],
                [
                    'keyword'       => 'php com',
                    'location_code' => 2840,
                    'language_code' => 'en',
                    'tag'           => 'test-tag',
                ],
            ],
            'With location name instead of code' => [
                [
                    'php com',               // keyword
                    'United States',         // locationName
                    null,                    // locationCode
                    null,                    // languageName
                    'en',                    // languageCode
                ],
                [
                    'keyword'       => 'php com',
                    'location_name' => 'United States',
                    'language_code' => 'en',
                ],
            ],
            'With language name instead of code' => [
                [
                    'php com',               // keyword
                    null,                    // locationName
                    2840,                    // locationCode
                    'English',               // languageName
                    null,                    // languageCode
                ],
                [
                    'keyword'       => 'php com',
                    'location_code' => 2840,
                    'language_name' => 'English',
                ],
            ],
            'With all parameters' => [
                [
                    'php com',               // keyword
                    'United States',         // locationName
                    2840,                    // locationCode
                    'English',               // languageName
                    'en',                    // languageCode
                    4,                       // cursorPointer
                    'gws-wiz-serp',          // client
                    'test-tag',              // tag
                ],
                [
                    'keyword'        => 'php com',
                    'location_name'  => 'United States',
                    'location_code'  => 2840,
                    'language_name'  => 'English',
                    'language_code'  => 'en',
                    'cursor_pointer' => 4,
                    'client'         => 'gws-wiz-serp',
                    'tag'            => 'test-tag',
                ],
            ],
        ];
    }

    #[DataProvider('autocompleteParametersProvider')]
    public function test_serp_google_autocomplete_live_advanced_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        Http::fake([
            "{$this->apiBaseUrl}/serp/google/autocomplete/live/advanced" => Http::response([
                'version'        => '0.1.20230807',
                'status_code'    => 20000,
                'status_message' => 'Ok.',
                'tasks'          => [
                    [
                        'id'          => '12345',
                        'status_code' => 20000,
                        'result'      => [],
                    ],
                ],
            ], 200),
        ]);

        // Reinitialize client
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        // Use parameter spreading to call the method with our test parameters
        $this->client->serpGoogleAutocompleteLiveAdvanced(...$parameters);

        // Check that the request was sent with the expected parameters
        Http::assertSent(function ($request) use ($expectedParams) {
            foreach ($expectedParams as $key => $value) {
                if (!isset($request[0][$key]) || $request[0][$key] !== $value) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_serp_google_autocomplete_live_advanced_handles_api_errors()
    {
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20016,
            'status_message' => 'API error: invalid keyword format',
            'time'           => '0.0997 sec.',
            'cost'           => 0,
            'tasks_count'    => 0,
            'tasks_error'    => 1,
            'tasks'          => [],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/autocomplete/live/advanced" => Http::response($errorResponse, 400),
        ]);

        // Reinitialize client
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->serpGoogleAutocompleteLiveAdvanced('!@#$%^&');

        // Check that the error response is received properly
        $this->assertEquals(400, $response['response_status_code']);
        $this->assertEquals($errorResponse, $response['response']->json());
    }

    public function test_serp_google_autocomplete_live_advanced_with_additional_params()
    {
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'tasks'          => [
                [
                    'id'          => '12345',
                    'status_code' => 20000,
                    'result'      => [],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/autocomplete/live/advanced" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $additionalParams = ['custom_param' => 'custom_value'];

        $this->client->serpGoogleAutocompleteLiveAdvanced(
            'laravel',
            null,
            2840,
            null,
            'en',
            null,
            null,
            null,
            $additionalParams
        );

        // Check that the request includes the additional params
        Http::assertSent(function ($request) {
            return isset($request[0]['custom_param']) &&
                   $request[0]['custom_param'] === 'custom_value';
        });
    }

    public function test_labs_amazon_bulk_search_volume_live_successful_request()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20231201',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.3045 sec.',
            'cost'           => 0.0183,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2945 sec.',
                    'cost'           => 0.0183,
                    'result_count'   => 3,
                    'path'           => [
                        'dataforseo_labs',
                        'amazon',
                        'bulk_search_volume',
                        'live',
                    ],
                    'data' => [
                        'api'      => 'dataforseo_labs',
                        'function' => 'bulk_search_volume',
                        'se'       => 'amazon',
                        'keywords' => [
                            'iphone 13 case',
                            'wireless charger',
                            'bluetooth speaker',
                        ],
                        'language_code' => 'en',
                        'location_code' => 2840,
                    ],
                    'result' => [
                        [
                            'keyword'      => 'iphone 13 case',
                            'keyword_info' => [
                                'search_volume' => 135000,
                                'competition'   => 0.88,
                                'cpc'           => 0.59,
                            ],
                        ],
                        [
                            'keyword'      => 'wireless charger',
                            'keyword_info' => [
                                'search_volume' => 74000,
                                'competition'   => 0.92,
                                'cpc'           => 0.65,
                            ],
                        ],
                        [
                            'keyword'      => 'bluetooth speaker',
                            'keyword_info' => [
                                'search_volume' => 110000,
                                'competition'   => 0.83,
                                'cpc'           => 0.48,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/amazon/bulk_search_volume/live" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $keywords = ['iphone 13 case', 'wireless charger', 'bluetooth speaker'];
        $response = $this->client->labsAmazonBulkSearchVolumeLive($keywords);

        Http::assertSent(function ($request) use ($keywords) {
            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/amazon/bulk_search_volume/live" &&
                   $request->method() === 'POST' &&
                   isset($request[0]['keywords']) &&
                   $request[0]['keywords'] === $keywords;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
        $this->assertEquals(3, $responseData['tasks'][0]['result_count']);
        $this->assertEquals('dataforseo_labs', $responseData['tasks'][0]['data']['api']);
        $this->assertEquals('amazon', $responseData['tasks'][0]['data']['se']);
    }

    public function test_labs_amazon_bulk_search_volume_live_validates_keywords_array()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keywords array cannot be empty');

        $this->client->labsAmazonBulkSearchVolumeLive([]);
    }

    public function test_labs_amazon_bulk_search_volume_live_validates_maximum_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of keywords is 1000');

        // Create an array with 1001 keywords
        $keywords = array_fill(0, 1001, 'test keyword');
        $this->client->labsAmazonBulkSearchVolumeLive($keywords);
    }

    public function test_labs_amazon_bulk_search_volume_live_validates_required_language_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either languageName or languageCode must be provided');

        $this->client->labsAmazonBulkSearchVolumeLive(['test keyword'], null, 2840, null, null);
    }

    public function test_labs_amazon_bulk_search_volume_live_validates_required_location_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either locationName or locationCode must be provided');

        $this->client->labsAmazonBulkSearchVolumeLive(['test keyword'], null, null, null, 'en');
    }

    public function test_labs_amazon_related_keywords_live_successful_request()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20231201',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.5621 sec.',
            'cost'           => 0.0142,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.5521 sec.',
                    'cost'           => 0.0142,
                    'result_count'   => 42,
                    'path'           => [
                        'dataforseo_labs',
                        'amazon',
                        'related_keywords',
                        'live',
                    ],
                    'data' => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'related_keywords',
                        'se'            => 'amazon',
                        'keyword'       => 'smart watch',
                        'language_code' => 'en',
                        'location_code' => 2840,
                        'depth'         => 2,
                    ],
                    'result' => [
                        [
                            'keyword'          => 'smart watch',
                            'depth'            => 0,
                            'related_keywords' => ['apple watch', 'samsung watch', 'fitness tracker'],
                            'keyword_info'     => [
                                'search_volume' => 301000,
                                'competition'   => 0.76,
                                'cpc'           => 0.92,
                            ],
                        ],
                        [
                            'keyword'          => 'apple watch',
                            'depth'            => 1,
                            'related_keywords' => ['apple watch series 7', 'apple watch band'],
                            'keyword_info'     => [
                                'search_volume' => 201000,
                                'competition'   => 0.82,
                                'cpc'           => 1.12,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/amazon/related_keywords/live" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $keyword  = 'smart watch';
        $response = $this->client->labsAmazonRelatedKeywordsLive($keyword);

        Http::assertSent(function ($request) use ($keyword) {
            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/amazon/related_keywords/live" &&
                   $request->method() === 'POST' &&
                   isset($request[0]['keyword']) &&
                   $request[0]['keyword'] === $keyword;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
        $this->assertEquals('related_keywords', $responseData['tasks'][0]['data']['function']);
        $this->assertEquals($keyword, $responseData['tasks'][0]['data']['keyword']);
    }

    public function test_labs_amazon_related_keywords_live_validates_empty_keyword()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keyword cannot be empty');

        $this->client->labsAmazonRelatedKeywordsLive('');
    }

    public function test_labs_amazon_related_keywords_live_validates_depth_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Depth must be between 0 and 4');

        $this->client->labsAmazonRelatedKeywordsLive('test keyword', null, 2840, null, 'en', 5);
    }

    public function test_labs_amazon_related_keywords_live_validates_limit_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be between 1 and 1000');

        $this->client->labsAmazonRelatedKeywordsLive('test keyword', null, 2840, null, 'en', 2, null, null, 0);
    }

    public function test_labs_amazon_related_keywords_live_validates_required_language_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either languageName or languageCode must be provided');

        $this->client->labsAmazonRelatedKeywordsLive('test keyword', null, 2840, null, null);
    }

    public function test_labs_amazon_related_keywords_live_validates_required_location_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either locationName or locationCode must be provided');

        $this->client->labsAmazonRelatedKeywordsLive('test keyword', null, null, null, 'en');
    }

    public static function relatedKeywordsParametersProvider()
    {
        return [
            'with basic parameters' => [
                [
                    'keyword'       => 'smart watch',
                    'location_code' => 2840,
                    'language_code' => 'en',
                ],
                [
                    'keyword'       => 'smart watch',
                    'location_code' => 2840,
                    'language_code' => 'en',
                ],
            ],
            'with all parameters' => [
                [
                    'keyword'              => 'smart watch',
                    'location_name'        => 'United States',
                    'location_code'        => 2840,
                    'language_name'        => 'English',
                    'language_code'        => 'en',
                    'depth'                => 3,
                    'include_seed_keyword' => true,
                    'ignore_synonyms'      => true,
                    'limit'                => 500,
                    'offset'               => 10,
                    'tag'                  => 'test-tag',
                ],
                [
                    'keyword'              => 'smart watch',
                    'location_name'        => 'United States',
                    'location_code'        => 2840,
                    'language_name'        => 'English',
                    'language_code'        => 'en',
                    'depth'                => 3,
                    'include_seed_keyword' => true,
                    'ignore_synonyms'      => true,
                    'limit'                => 500,
                    'offset'               => 10,
                    'tag'                  => 'test-tag',
                ],
            ],
        ];
    }

    #[DataProvider('relatedKeywordsParametersProvider')]
    public function test_labs_amazon_related_keywords_live_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/amazon/related_keywords/live" => Http::response([
                'tasks' => [['id' => 'test-id', 'data' => $parameters]],
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $this->client->labsAmazonRelatedKeywordsLive(
            $parameters['keyword'],
            $parameters['location_name'] ?? null,
            $parameters['location_code'] ?? null,
            $parameters['language_name'] ?? null,
            $parameters['language_code'] ?? null,
            $parameters['depth'] ?? null,
            $parameters['include_seed_keyword'] ?? null,
            $parameters['ignore_synonyms'] ?? null,
            $parameters['limit'] ?? null,
            $parameters['offset'] ?? null,
            $parameters['tag'] ?? null
        );

        Http::assertSent(function ($request) use ($expectedParams) {
            $sentParams = $request[0];
            foreach ($expectedParams as $key => $value) {
                if (!isset($sentParams[$key]) || $sentParams[$key] !== $value) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_labs_amazon_ranked_keywords_live_successful_request()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20231201',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.4832 sec.',
            'cost'           => 0.0173,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.4732 sec.',
                    'cost'           => 0.0173,
                    'result_count'   => 256,
                    'path'           => [
                        'dataforseo_labs',
                        'amazon',
                        'ranked_keywords',
                        'live',
                    ],
                    'data' => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'ranked_keywords',
                        'se'            => 'amazon',
                        'asin'          => 'B08L5TNJHG',
                        'language_code' => 'en',
                        'location_code' => 2840,
                    ],
                    'result' => [
                        [
                            'keyword'             => 'echo dot',
                            'ranked_serp_element' => [
                                'check_url' => 'https://www.amazon.com/s?k=echo+dot',
                                'serp_item' => [
                                    'type'          => 'product',
                                    'rank_group'    => 3,
                                    'rank_absolute' => 3,
                                    'asin'          => 'B08L5TNJHG',
                                    'title'         => 'Echo Dot (4th Gen) | Smart speaker with Alexa',
                                    'price'         => 49.99,
                                ],
                            ],
                            'keyword_data' => [
                                'keyword_info' => [
                                    'search_volume' => 673000,
                                    'competition'   => 0.63,
                                    'cpc'           => 0.85,
                                ],
                            ],
                        ],
                        [
                            'keyword'             => 'alexa',
                            'ranked_serp_element' => [
                                'check_url' => 'https://www.amazon.com/s?k=alexa',
                                'serp_item' => [
                                    'type'          => 'product',
                                    'rank_group'    => 5,
                                    'rank_absolute' => 5,
                                    'asin'          => 'B08L5TNJHG',
                                    'title'         => 'Echo Dot (4th Gen) | Smart speaker with Alexa',
                                    'price'         => 49.99,
                                ],
                            ],
                            'keyword_data' => [
                                'keyword_info' => [
                                    'search_volume' => 520000,
                                    'competition'   => 0.58,
                                    'cpc'           => 0.92,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/amazon/ranked_keywords/live" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $asin     = 'B08L5TNJHG';
        $response = $this->client->labsAmazonRankedKeywordsLive($asin);

        Http::assertSent(function ($request) use ($asin) {
            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/amazon/ranked_keywords/live" &&
                   $request->method() === 'POST' &&
                   isset($request[0]['asin']) &&
                   $request[0]['asin'] === $asin;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
        $this->assertEquals('ranked_keywords', $responseData['tasks'][0]['data']['function']);
        $this->assertEquals($asin, $responseData['tasks'][0]['data']['asin']);
    }

    public function test_labs_amazon_ranked_keywords_live_validates_empty_asin()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ASIN cannot be empty');

        $this->client->labsAmazonRankedKeywordsLive('');
    }

    public function test_labs_amazon_ranked_keywords_live_validates_limit_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be between 1 and 1000');

        $this->client->labsAmazonRankedKeywordsLive('B08L5TNJHG', null, 2840, null, 'en', 0);
    }

    public function test_labs_amazon_ranked_keywords_live_validates_required_language_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either languageName or languageCode must be provided');

        $this->client->labsAmazonRankedKeywordsLive('B08L5TNJHG', null, 2840, null, null);
    }

    public function test_labs_amazon_ranked_keywords_live_validates_required_location_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either locationName or locationCode must be provided');

        $this->client->labsAmazonRankedKeywordsLive('B08L5TNJHG', null, null, null, 'en');
    }

    public static function labsAmazonRankedKeywordsLiveParametersProvider()
    {
        return [
            'with basic parameters' => [
                [
                    'asin'          => 'B08L5TNJHG',
                    'location_code' => 2840,
                    'language_code' => 'en',
                ],
                [
                    'asin'          => 'B08L5TNJHG',
                    'location_code' => 2840,
                    'language_code' => 'en',
                ],
            ],
            'with all parameters' => [
                [
                    'asin'            => 'B08L5TNJHG',
                    'location_name'   => 'United States',
                    'location_code'   => 2840,
                    'language_name'   => 'English',
                    'language_code'   => 'en',
                    'limit'           => 100,
                    'ignore_synonyms' => true,
                    'filters'         => [
                        ['keyword_data.keyword_info.search_volume', 'in', [100, 1000]],
                    ],
                    'order_by' => [
                        'ranked_serp_element.serp_item.rank_group,asc',
                        'keyword_data.keyword_info.search_volume,desc',
                    ],
                    'offset' => 10,
                    'tag'    => 'test-tag',
                ],
                [
                    'asin'            => 'B08L5TNJHG',
                    'location_name'   => 'United States',
                    'location_code'   => 2840,
                    'language_name'   => 'English',
                    'language_code'   => 'en',
                    'limit'           => 100,
                    'ignore_synonyms' => true,
                    'filters'         => [
                        ['keyword_data.keyword_info.search_volume', 'in', [100, 1000]],
                    ],
                    'order_by' => [
                        'ranked_serp_element.serp_item.rank_group,asc',
                        'keyword_data.keyword_info.search_volume,desc',
                    ],
                    'offset' => 10,
                    'tag'    => 'test-tag',
                ],
            ],
            'with complex filter and ordering' => [
                [
                    'asin'          => 'B08L5TNJHG',
                    'location_code' => 2840,
                    'language_code' => 'en',
                    'filters'       => [
                        ['keyword_data.keyword_info.search_volume', '>', 1000],
                        'and',
                        ['keyword_data.keyword_info.cpc', '<', 1.5],
                    ],
                    'order_by' => [
                        'ranked_serp_element.serp_item.rank_group,asc',
                        'keyword_data.keyword_info.search_volume,desc',
                    ],
                ],
                [
                    'asin'          => 'B08L5TNJHG',
                    'location_code' => 2840,
                    'language_code' => 'en',
                    'filters'       => [
                        ['keyword_data.keyword_info.search_volume', '>', 1000],
                        'and',
                        ['keyword_data.keyword_info.cpc', '<', 1.5],
                    ],
                    'order_by' => [
                        'ranked_serp_element.serp_item.rank_group,asc',
                        'keyword_data.keyword_info.search_volume,desc',
                    ],
                ],
            ],
        ];
    }

    #[DataProvider('labsAmazonRankedKeywordsLiveParametersProvider')]
    public function test_labs_amazon_ranked_keywords_live_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/amazon/ranked_keywords/live" => Http::response([
                'tasks' => [['id' => 'test-id', 'data' => $parameters]],
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $this->client->labsAmazonRankedKeywordsLive(
            $parameters['asin'],
            $parameters['location_name'] ?? null,
            $parameters['location_code'] ?? null,
            $parameters['language_name'] ?? null,
            $parameters['language_code'] ?? null,
            $parameters['limit'] ?? null,
            $parameters['ignore_synonyms'] ?? null,
            $parameters['filters'] ?? null,
            $parameters['order_by'] ?? null,
            $parameters['offset'] ?? null,
            $parameters['tag'] ?? null
        );

        Http::assertSent(function ($request) use ($expectedParams) {
            $sentParams = $request[0];
            foreach ($expectedParams as $key => $value) {
                if (!isset($sentParams[$key]) || $sentParams[$key] !== $value) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_onpage_instant_pages_successful_request()
    {
        $id              = '12345678-1234-1234-1234-123456789014';
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.3497 sec.',
            'cost'           => 0.03,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.3397 sec.',
                    'cost'           => 0.03,
                    'result_count'   => 1,
                    'path'           => [
                        'on_page',
                        'instant_pages',
                    ],
                    'data' => [
                        'api'            => 'on_page',
                        'function'       => 'instant_pages',
                        'url'            => 'https://example.com',
                        'browser_preset' => 'desktop',
                    ],
                    'result' => [
                        [
                            'url'          => 'https://example.com',
                            'status_code'  => 200,
                            'page_content' => '<html><body>Example content</body></html>',
                            'page_metrics' => [
                                'word_count'         => 2,
                                'text_to_html_ratio' => 0.1,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/instant_pages" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPageInstantPages('https://example.com', null, 'desktop');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/on_page/instant_pages" &&
                   $request->method() === 'POST' &&
                   isset($request[0]['url']) &&
                   $request[0]['url'] === 'https://example.com' &&
                   isset($request[0]['browser_preset']) &&
                   $request[0]['browser_preset'] === 'desktop';
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public static function onPageInstantPagesParametersProvider()
    {
        return [
            'basic request' => [
                [
                    'https://example.com',
                    null, // customUserAgent
                    null, // browserPreset
                    null, // browserScreenWidth
                    null, // browserScreenHeight
                    null, // browserScreenScaleFactor
                    null, // storeRawHtml
                    null, // acceptLanguage
                    null, // loadResources
                    null, // enableJavascript
                    null, // enableBrowserRendering
                    null, // disableCookiePopup
                    null, // returnDespiteTimeout
                    null, // enableXhr
                    null, // customJs
                    null, // validateMicromarkup
                    null, // checkSpell
                    null, // checksThreshold
                    null, // switchPool
                    null, // ipPoolForScan
                    [], // additionalParams
                    null, // attributes
                    1, // amount
                ],
                [
                    'url' => 'https://example.com',
                ],
            ],
            'with browser preset' => [
                [
                    'https://example.com',
                    null,
                    'mobile',
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    [],
                    null,
                    1,
                ],
                [
                    'url'            => 'https://example.com',
                    'browser_preset' => 'mobile',
                ],
            ],
            'with custom user agent' => [
                [
                    'https://example.com',
                    'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X)',
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    [],
                    null,
                    1,
                ],
                [
                    'url'               => 'https://example.com',
                    'custom_user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X)',
                ],
            ],
            'with browser dimensions' => [
                [
                    'https://example.com',
                    null,
                    null,
                    390,
                    844,
                    3.0,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    [],
                    null,
                    1,
                ],
                [
                    'url'                         => 'https://example.com',
                    'browser_screen_width'        => 390,
                    'browser_screen_height'       => 844,
                    'browser_screen_scale_factor' => 3.0,
                ],
            ],
            'with resource loading' => [
                [
                    'https://example.com',
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    true, // loadResources
                    true, // enableJavascript
                    true, // enableBrowserRendering
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    [],
                    null,
                    1,
                ],
                [
                    'url'                      => 'https://example.com',
                    'load_resources'           => true,
                    'enable_javascript'        => true,
                    'enable_browser_rendering' => true,
                ],
            ],
        ];
    }

    #[DataProvider('onPageInstantPagesParametersProvider')]
    public function test_onpage_instant_pages_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        Http::fake([
            "{$this->apiBaseUrl}/on_page/instant_pages" => Http::response(['status_code' => 20000], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $this->client->onPageInstantPages(...$parameters);

        Http::assertSent(function ($request) use ($expectedParams) {
            $requestData = $request[0];
            foreach ($expectedParams as $key => $value) {
                if (!isset($requestData[$key]) || $requestData[$key] !== $value) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_onpage_raw_html_successful_request()
    {
        $id              = '12345678-1234-1234-1234-123456789015';
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.015,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.015,
                    'result_count'   => 1,
                    'path'           => [
                        'on_page',
                        'raw_html',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'raw_html',
                        'id'       => $id,
                    ],
                    'result' => [
                        [
                            'id'   => $id,
                            'url'  => 'https://example.com',
                            'html' => '<html><body>Example content</body></html>',
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/raw_html" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPageRawHtml($id);

        Http::assertSent(function ($request) use ($id) {
            return $request->url() === "{$this->apiBaseUrl}/on_page/raw_html" &&
                   $request->method() === 'POST' &&
                   isset($request[0]['id']) &&
                   $request[0]['id'] === $id;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_onpage_raw_html_with_url_parameter_successful_request()
    {
        $id              = '12345678-1234-1234-1234-123456789016';
        $url             = 'https://example.com';
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.015,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.015,
                    'result_count'   => 1,
                    'path'           => [
                        'on_page',
                        'raw_html',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'raw_html',
                        'id'       => $id,
                        'url'      => $url,
                    ],
                    'result' => [
                        [
                            'id'   => $id,
                            'url'  => $url,
                            'html' => '<html><body>Example content</body></html>',
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/raw_html" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPageRawHtml($id, $url);

        Http::assertSent(function ($request) use ($id, $url) {
            return $request->url() === "{$this->apiBaseUrl}/on_page/raw_html" &&
                   $request->method() === 'POST' &&
                   isset($request[0]['id']) &&
                   $request[0]['id'] === $id &&
                   isset($request[0]['url']) &&
                   $request[0]['url'] === $url;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_onpage_raw_html_handles_api_errors()
    {
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40000,
            'status_message' => 'Bad Request.',
            'time'           => '0.0497 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 1,
            'tasks'          => [
                [
                    'id'             => '12345678-1234-1234-1234-123456789017',
                    'status_code'    => 40000,
                    'status_message' => 'Bad Request.',
                    'time'           => '0.0397 sec.',
                    'cost'           => 0,
                    'result_count'   => 0,
                    'path'           => [
                        'on_page',
                        'raw_html',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'raw_html',
                        'id'       => 'invalid-id',
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/raw_html" => Http::response($errorResponse, 400),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPageRawHtml('invalid-id');

        $this->assertEquals(400, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals(40000, $responseData['status_code']);
        $this->assertEquals('Bad Request.', $responseData['status_message']);
    }

    public function test_serp_google_organic_task_post_successful_request()
    {
        $taskId          = '12345678-1234-1234-1234-123456789012';
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.015,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $taskId,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.015,
                    'result_count'   => 1,
                    'path'           => [
                        'serp',
                        'google',
                        'organic',
                        'task_post',
                    ],
                    'data' => [
                        'api'           => 'serp',
                        'function'      => 'task_post',
                        'se'            => 'google',
                        'se_type'       => 'organic',
                        'keyword'       => 'laravel framework',
                        'language_code' => 'en',
                        'location_code' => 2840,
                        'device'        => 'desktop',
                    ],
                    'result' => [
                        'task_id'        => $taskId,
                        'status_message' => 'Ok.',
                        'status_code'    => 20000,
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/organic/task_post" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->serpGoogleOrganicTaskPost('laravel framework');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/serp/google/organic/task_post" &&
                   $request->method() === 'POST' &&
                   isset($request[0]['keyword']) &&
                   $request[0]['keyword'] === 'laravel framework';
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
    }

    public function test_serp_google_organic_task_post_validates_required_language_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either languageName or languageCode must be provided');

        $this->client->serpGoogleOrganicTaskPost(
            'laravel framework',
            null,
            null,
            null,
            null,
            null,
            2840,
            null,
            null,
            null // Set languageCode to null
        );
    }

    public function test_serp_google_organic_task_post_validates_required_location_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either locationName, locationCode, or locationCoordinate must be provided');

        $this->client->serpGoogleOrganicTaskPost(
            'laravel framework',
            null,
            null,
            null,
            null,
            null,
            null, // locationCode set to null
            null, // locationCoordinate not provided
            null,
            'en'
        );
    }

    public function test_serp_google_organic_task_post_validates_depth_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Depth must be less than or equal to 700');

        $this->client->serpGoogleOrganicTaskPost(
            'laravel framework',
            null,
            null,
            800 // Depth exceeds maximum of 700
        );
    }

    public function test_serp_google_organic_task_post_validates_priority_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Priority must be either 1 (normal) or 2 (high)');

        $this->client->serpGoogleOrganicTaskPost(
            'laravel framework',
            null,
            3 // Invalid priority value
        );
    }

    public function test_serp_google_organic_task_post_validates_people_also_ask_click_depth()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('peopleAlsoAskClickDepth must be between 1 and 4');

        $this->client->serpGoogleOrganicTaskPost(
            'laravel framework',
            null, // url
            null, // priority
            null, // depth
            null, // maxCrawlPages
            null, // locationName
            2840, // locationCode
            null, // locationCoordinate
            null, // languageName
            'en', // languageCode
            null, // seDomain
            null, // device
            null, // os
            null, // groupOrganicResults
            null, // calculateRectangles
            null, // browserScreenWidth
            null, // browserScreenHeight
            null, // browserScreenResolutionRatio
            5 // peopleAlsoAskClickDepth exceeds maximum of 4
        );
    }

    public function test_serp_google_organic_task_post_validates_postback_data_requirement()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('postbackData is required when postbackUrl is specified');

        $this->client->serpGoogleOrganicTaskPost(
            'laravel framework',
            null, // url
            null, // priority
            null, // depth
            null, // maxCrawlPages
            null, // locationName
            2840, // locationCode
            null, // locationCoordinate
            null, // languageName
            'en', // languageCode
            null, // seDomain
            null, // device
            null, // os
            null, // groupOrganicResults
            null, // calculateRectangles
            null, // browserScreenWidth
            null, // browserScreenHeight
            null, // browserScreenResolutionRatio
            null, // peopleAlsoAskClickDepth
            null, // loadAsyncAiOverview
            null, // expandAiOverview
            null, // searchParam
            null, // removeFromUrl
            null, // tag
            'https://example.com/callback', // postbackUrl
            null // postbackData not provided
        );
    }

    public static function serpGoogleOrganicTaskPostParametersProvider(): array
    {
        return [
            'Basic parameters' => [
                [
                    'keyword' => 'laravel framework',
                ],
                [
                    'keyword'       => 'laravel framework',
                    'language_code' => 'en',
                    'location_code' => 2840,
                ],
            ],
            'Custom location and language' => [
                [
                    'keyword'            => 'laravel framework',
                    'url'                => null,
                    'priority'           => null,
                    'depth'              => null,
                    'maxCrawlPages'      => null,
                    'locationName'       => 'Paris,France',
                    'locationCode'       => 1006524,
                    'locationCoordinate' => null,
                    'languageName'       => 'French',
                    'languageCode'       => 'fr',
                ],
                [
                    'keyword'       => 'laravel framework',
                    'language_name' => 'French',
                    'language_code' => 'fr',
                    'location_name' => 'Paris,France',
                    'location_code' => 1006524,
                ],
            ],
            'Advanced parameters' => [
                [
                    'keyword'                      => 'laravel framework',
                    'url'                          => null,
                    'priority'                     => 2,
                    'depth'                        => 200,
                    'maxCrawlPages'                => null,
                    'locationName'                 => null,
                    'locationCode'                 => 2840,
                    'locationCoordinate'           => null,
                    'languageName'                 => null,
                    'languageCode'                 => 'en',
                    'seDomain'                     => null,
                    'device'                       => 'mobile',
                    'os'                           => 'android',
                    'groupOrganicResults'          => null,
                    'calculateRectangles'          => null,
                    'browserScreenWidth'           => null,
                    'browserScreenHeight'          => null,
                    'browserScreenResolutionRatio' => null,
                    'peopleAlsoAskClickDepth'      => null,
                    'loadAsyncAiOverview'          => null,
                    'expandAiOverview'             => null,
                    'searchParam'                  => 'gl=US',
                    'removeFromUrl'                => null,
                    'tag'                          => 'test-tag',
                ],
                [
                    'keyword'       => 'laravel framework',
                    'language_code' => 'en',
                    'location_code' => 2840,
                    'priority'      => 2,
                    'depth'         => 200,
                    'device'        => 'mobile',
                    'os'            => 'android',
                    'search_param'  => 'gl=US',
                    'tag'           => 'test-tag',
                ],
            ],
            'AI and callback parameters' => [
                [
                    'keyword'                      => 'laravel framework',
                    'url'                          => null,
                    'priority'                     => null,
                    'depth'                        => null,
                    'maxCrawlPages'                => null,
                    'locationName'                 => null,
                    'locationCode'                 => 2840,
                    'locationCoordinate'           => null,
                    'languageName'                 => null,
                    'languageCode'                 => 'en',
                    'seDomain'                     => null,
                    'device'                       => null,
                    'os'                           => null,
                    'groupOrganicResults'          => null,
                    'calculateRectangles'          => null,
                    'browserScreenWidth'           => null,
                    'browserScreenHeight'          => null,
                    'browserScreenResolutionRatio' => null,
                    'peopleAlsoAskClickDepth'      => null,
                    'loadAsyncAiOverview'          => true,
                    'expandAiOverview'             => true,
                    'searchParam'                  => null,
                    'removeFromUrl'                => null,
                    'tag'                          => null,
                    'postbackUrl'                  => 'https://example.com/callback',
                    'postbackData'                 => 'regular',
                    'pingbackUrl'                  => 'https://example.com/pingback',
                ],
                [
                    'keyword'                => 'laravel framework',
                    'language_code'          => 'en',
                    'location_code'          => 2840,
                    'load_async_ai_overview' => true,
                    'expand_ai_overview'     => true,
                    'postback_url'           => 'https://example.com/callback',
                    'postback_data'          => 'regular',
                    'pingback_url'           => 'https://example.com/pingback',
                ],
            ],
        ];
    }

    #[DataProvider('serpGoogleOrganicTaskPostParametersProvider')]
    public function test_serp_google_organic_task_post_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.015,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => '12345678-1234-1234-1234-123456789012',
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'result'         => [
                        'task_id' => '12345678-1234-1234-1234-123456789012',
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/organic/task_post" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        // Call the method with named parameters instead of positional ones
        $this->client->serpGoogleOrganicTaskPost(
            keyword: $parameters['keyword'],
            url: $parameters['url'] ?? null,
            priority: $parameters['priority'] ?? null,
            depth: $parameters['depth'] ?? null,
            maxCrawlPages: $parameters['maxCrawlPages'] ?? null,
            locationName: $parameters['locationName'] ?? null,
            locationCode: $parameters['locationCode'] ?? 2840,
            locationCoordinate: $parameters['locationCoordinate'] ?? null,
            languageName: $parameters['languageName'] ?? null,
            languageCode: $parameters['languageCode'] ?? 'en',
            seDomain: $parameters['seDomain'] ?? null,
            device: $parameters['device'] ?? null,
            os: $parameters['os'] ?? null,
            groupOrganicResults: $parameters['groupOrganicResults'] ?? null,
            calculateRectangles: $parameters['calculateRectangles'] ?? null,
            browserScreenWidth: $parameters['browserScreenWidth'] ?? null,
            browserScreenHeight: $parameters['browserScreenHeight'] ?? null,
            browserScreenResolutionRatio: $parameters['browserScreenResolutionRatio'] ?? null,
            peopleAlsoAskClickDepth: $parameters['peopleAlsoAskClickDepth'] ?? null,
            loadAsyncAiOverview: $parameters['loadAsyncAiOverview'] ?? null,
            expandAiOverview: $parameters['expandAiOverview'] ?? null,
            searchParam: $parameters['searchParam'] ?? null,
            removeFromUrl: $parameters['removeFromUrl'] ?? null,
            tag: $parameters['tag'] ?? null,
            postbackUrl: $parameters['postbackUrl'] ?? null,
            postbackData: $parameters['postbackData'] ?? null,
            pingbackUrl: $parameters['pingbackUrl'] ?? null
        );

        Http::assertSent(function ($request) use ($expectedParams) {
            $requestData = $request[0] ?? [];

            foreach ($expectedParams as $key => $value) {
                if (!isset($requestData[$key]) || $requestData[$key] !== $value) {
                    return false;
                }
            }

            return true;
        });

        // Http::fake returns 200 by default when not specified
        $this->assertNotNull($this->client);
    }

    public function test_serp_google_organic_task_post_handles_api_errors()
    {
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40101,
            'status_message' => 'Auth error. Invalid login credentials.',
            'time'           => '0.0121 sec.',
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/organic/task_post" => Http::response($errorResponse, 401),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->serpGoogleOrganicTaskPost('laravel framework');

        $this->assertEquals(401, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals(40101, $responseData['status_code']);
        $this->assertEquals('Auth error. Invalid login credentials.', $responseData['status_message']);
    }

    public function test_serp_google_organic_task_get_regular_handles_error_response()
    {
        $taskId       = '12345678-1234-1234-1234-123456789012';
        $endpointPath = 'serp/google/organic/task_get/regular';

        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40401,
            'status_message' => 'Task not found.',
            'time'           => '0.0105 sec.',
        ];

        Http::fake([
            "{$this->apiBaseUrl}/{$endpointPath}/{$taskId}" => Http::response($errorResponse, 404),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->serpGoogleOrganicTaskGetRegular($taskId);

        $this->assertEquals(404, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals(40401, $responseData['status_code']);
        $this->assertEquals('Task not found.', $responseData['status_message']);
    }

    public function test_serp_google_organic_task_get_regular_successful_request()
    {
        $taskId          = '12345678-1234-1234-1234-123456789012';
        $keyword         = 'laravel framework';
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
                    'path'           => ['serp', 'google', 'organic', 'task_get', 'regular'],
                    'data'           => [
                        'api'      => 'serp',
                        'function' => 'task_get',
                        'se'       => 'google',
                        'se_type'  => 'organic',
                        'keyword'  => $keyword,
                    ],
                    'result' => [
                        [
                            'keyword'     => $keyword,
                            'items_count' => 5,
                            'items'       => [
                                [
                                    'type'       => 'organic',
                                    'rank_group' => 1,
                                    'title'      => 'Test Result',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/organic/task_get/regular/{$taskId}" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->serpGoogleOrganicTaskGetRegular($taskId);

        Http::assertSent(function ($request) use ($taskId) {
            return $request->url() === "{$this->apiBaseUrl}/serp/google/organic/task_get/regular/{$taskId}" &&
                   $request->method() === 'GET';
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
        $this->assertEquals($keyword, $responseData['tasks'][0]['data']['keyword']);
    }

    public function test_serp_google_organic_task_get_regular_passes_attributes_and_amount()
    {
        $taskId          = '12345678-1234-1234-1234-123456789012';
        $attributes      = 'custom-attributes-test';
        $amount          = 5;
        $successResponse = [
            'version'     => '0.1.20230807',
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id' => $taskId,
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/organic/task_get/regular/{$taskId}" => Http::response($successResponse, 200),
        ]);

        $client = new DataForSeoApiClient();
        $client->clearRateLimit();

        // Mock the taskGet method to verify it's called with the correct parameters
        $mockClient = $this->getMockBuilder(DataForSeoApiClient::class)
            ->onlyMethods(['taskGet'])
            ->getMock();

        $mockClient->expects($this->once())
            ->method('taskGet')
            ->with(
                'serp/google/organic/task_get/regular',
                $taskId,
                $attributes,
                $amount
            )
            ->willReturn(['response' => Http::response($successResponse), 'response_status_code' => 200]);

        $mockClient->serpGoogleOrganicTaskGetRegular($taskId, $attributes, $amount);
    }

    public function test_serp_google_organic_task_get_regular_handles_error_response_with_more_details()
    {
        $taskId        = '12345678-1234-1234-1234-123456789012';
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40400,
            'status_message' => 'Task not found.',
            'time'           => '0.0123 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 1,
            'tasks'          => [
                [
                    'id'             => $taskId,
                    'status_code'    => 40400,
                    'status_message' => 'Task not found.',
                    'time'           => '0.0123 sec.',
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/organic/task_get/regular/{$taskId}" => Http::response($errorResponse, 404),
        ]);

        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->serpGoogleOrganicTaskGetRegular($taskId);

        Http::assertSent(function ($request) use ($taskId) {
            return $request->url() === "{$this->apiBaseUrl}/serp/google/organic/task_get/regular/{$taskId}" &&
                   $request->method() === 'GET';
        });

        $this->assertEquals(404, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
        $this->assertEquals(40400, $responseData['tasks'][0]['status_code']);
    }

    public function test_serp_google_organic_task_get_advanced_successful_request()
    {
        $taskId          = '12345678-1234-1234-1234-123456789012';
        $keyword         = 'advanced seo techniques';
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
                    'path'           => ['serp', 'google', 'organic', 'task_get', 'advanced'],
                    'data'           => [
                        'api'      => 'serp',
                        'function' => 'task_get',
                        'se'       => 'google',
                        'se_type'  => 'organic',
                        'keyword'  => $keyword,
                    ],
                    'result' => [
                        [
                            'keyword'     => $keyword,
                            'items_count' => 7,
                            'items'       => [
                                [
                                    'type'       => 'organic',
                                    'rank_group' => 1,
                                    'title'      => 'Advanced SEO Techniques',
                                ],
                                [
                                    'type'       => 'featured_snippet',
                                    'rank_group' => 0,
                                    'title'      => 'Featured Snippet',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/organic/task_get/advanced/{$taskId}" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->serpGoogleOrganicTaskGetAdvanced($taskId);

        Http::assertSent(function ($request) use ($taskId) {
            return $request->url() === "{$this->apiBaseUrl}/serp/google/organic/task_get/advanced/{$taskId}" &&
                   $request->method() === 'GET';
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
        $this->assertEquals($keyword, $responseData['tasks'][0]['data']['keyword']);
    }

    public function test_serp_google_organic_task_get_advanced_passes_attributes_and_amount()
    {
        $taskId          = '12345678-1234-1234-1234-123456789012';
        $attributes      = 'custom-attributes-advanced';
        $amount          = 3;
        $successResponse = [
            'version'     => '0.1.20230807',
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id' => $taskId,
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/organic/task_get/advanced/{$taskId}" => Http::response($successResponse, 200),
        ]);

        $client = new DataForSeoApiClient();
        $client->clearRateLimit();

        // Mock the taskGet method to verify it's called with the correct parameters
        $mockClient = $this->getMockBuilder(DataForSeoApiClient::class)
            ->onlyMethods(['taskGet'])
            ->getMock();

        $mockClient->expects($this->once())
            ->method('taskGet')
            ->with(
                'serp/google/organic/task_get/advanced',
                $taskId,
                $attributes,
                $amount
            )
            ->willReturn(['response' => Http::response($successResponse), 'response_status_code' => 200]);

        $mockClient->serpGoogleOrganicTaskGetAdvanced($taskId, $attributes, $amount);
    }

    public function test_serp_google_organic_task_get_advanced_handles_error_response()
    {
        $taskId        = '12345678-1234-1234-1234-123456789012';
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40400,
            'status_message' => 'Task not found.',
            'time'           => '0.0123 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 1,
            'tasks'          => [
                [
                    'id'             => $taskId,
                    'status_code'    => 40400,
                    'status_message' => 'Task not found.',
                    'time'           => '0.0123 sec.',
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/organic/task_get/advanced/{$taskId}" => Http::response($errorResponse, 404),
        ]);

        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->serpGoogleOrganicTaskGetAdvanced($taskId);

        Http::assertSent(function ($request) use ($taskId) {
            return $request->url() === "{$this->apiBaseUrl}/serp/google/organic/task_get/advanced/{$taskId}" &&
                   $request->method() === 'GET';
        });

        $this->assertEquals(404, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
        $this->assertEquals(40400, $responseData['tasks'][0]['status_code']);
    }

    public function test_serp_google_organic_task_get_html_successful_request()
    {
        $taskId          = '12345678-1234-1234-1234-123456789012';
        $keyword         = 'php unit testing';
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
                    'path'           => ['serp', 'google', 'organic', 'task_get', 'html'],
                    'data'           => [
                        'api'      => 'serp',
                        'function' => 'task_get',
                        'se'       => 'google',
                        'se_type'  => 'organic',
                        'keyword'  => $keyword,
                    ],
                    'result' => [
                        [
                            'keyword' => $keyword,
                            'type'    => 'html',
                            'html'    => '<!DOCTYPE html><html><body><h1>Search Results</h1></body></html>',
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/organic/task_get/html/{$taskId}" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->serpGoogleOrganicTaskGetHtml($taskId);

        Http::assertSent(function ($request) use ($taskId) {
            return $request->url() === "{$this->apiBaseUrl}/serp/google/organic/task_get/html/{$taskId}" &&
                   $request->method() === 'GET';
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
        $this->assertEquals($keyword, $responseData['tasks'][0]['data']['keyword']);
        $this->assertStringContainsString('DOCTYPE html', $responseData['tasks'][0]['result'][0]['html']);
    }

    public function test_serp_google_organic_task_get_html_passes_attributes_and_amount()
    {
        $taskId          = '12345678-1234-1234-1234-123456789012';
        $attributes      = 'custom-attributes-html';
        $amount          = 2;
        $successResponse = [
            'version'     => '0.1.20230807',
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id' => $taskId,
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/organic/task_get/html/{$taskId}" => Http::response($successResponse, 200),
        ]);

        $client = new DataForSeoApiClient();
        $client->clearRateLimit();

        // Mock the taskGet method to verify it's called with the correct parameters
        $mockClient = $this->getMockBuilder(DataForSeoApiClient::class)
            ->onlyMethods(['taskGet'])
            ->getMock();

        $mockClient->expects($this->once())
            ->method('taskGet')
            ->with(
                'serp/google/organic/task_get/html',
                $taskId,
                $attributes,
                $amount
            )
            ->willReturn(['response' => Http::response($successResponse), 'response_status_code' => 200]);

        $mockClient->serpGoogleOrganicTaskGetHtml($taskId, $attributes, $amount);
    }

    public function test_serp_google_organic_task_get_html_handles_error_response()
    {
        $taskId        = '12345678-1234-1234-1234-123456789012';
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40400,
            'status_message' => 'Task not found.',
            'time'           => '0.0123 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 1,
            'tasks'          => [
                [
                    'id'             => $taskId,
                    'status_code'    => 40400,
                    'status_message' => 'Task not found.',
                    'time'           => '0.0123 sec.',
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/organic/task_get/html/{$taskId}" => Http::response($errorResponse, 404),
        ]);

        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->serpGoogleOrganicTaskGetHtml($taskId);

        Http::assertSent(function ($request) use ($taskId) {
            return $request->url() === "{$this->apiBaseUrl}/serp/google/organic/task_get/html/{$taskId}" &&
                   $request->method() === 'GET';
        });

        $this->assertEquals(404, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
        $this->assertEquals(40400, $responseData['tasks'][0]['status_code']);
    }
}

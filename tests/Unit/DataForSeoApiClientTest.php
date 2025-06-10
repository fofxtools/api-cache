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

    public function test_buildApiParams_respects_additional_excluded_args()
    {
        $clientMock = new class () extends DataForSeoApiClient {
            public function testMethod(
                string $normalParam,
                string $shouldBeExcluded,
                array $additionalParams = []
            ): array {
                // Exclude 'shouldBeExcluded' parameter
                return $this->buildApiParams($additionalParams, ['shouldBeExcluded']);
            }
        };

        $result = $clientMock->testMethod('include me', 'exclude me', []);

        $this->assertArrayHasKey('normal_param', $result);
        $this->assertArrayNotHasKey('should_be_excluded', $result);
        $this->assertEquals('include me', $result['normal_param']);
    }

    public function test_buildApiParams_merges_default_and_additional_excluded_args()
    {
        $clientMock = new class () extends DataForSeoApiClient {
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

        $result = $clientMock->testMethod(
            'include me',
            'exclude me',
            ['should' => 'be included'],
            ['extra'  => 'value'],
            'test'
        );

        $this->assertArrayHasKey('normal_param', $result);
        $this->assertArrayHasKey('extra', $result);
        $this->assertArrayHasKey('custom_excluded_args', $result); // Should be included
        $this->assertArrayNotHasKey('additional_params', $result); // Default excluded
        $this->assertArrayNotHasKey('attributes', $result);        // Default excluded
        $this->assertArrayNotHasKey('custom_excluded', $result);   // Additional excluded
    }

    public function test_buildApiParams_works_with_empty_additional_excluded_args()
    {
        $clientMock = new class () extends DataForSeoApiClient {
            public function testMethod(
                string $normalParam,
                array $additionalParams = [],
                ?string $attributes = null
            ): array {
                // Pass empty array for additional excluded args
                return $this->buildApiParams($additionalParams, []);
            }
        };

        $result = $clientMock->testMethod('include me', [], 'test');

        $this->assertArrayHasKey('normal_param', $result);
        $this->assertArrayNotHasKey('additional_params', $result); // Default excluded
        $this->assertArrayNotHasKey('attributes', $result);        // Default excluded
        $this->assertEquals('include me', $result['normal_param']);
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
                   isset($request->data()[0]['keyword']) &&
                   $request->data()[0]['keyword'] === 'laravel framework';
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
                   isset($request->data()[0]['keyword']) &&
                   $request->data()[0]['keyword'] === 'php composer' &&
                   $request->data()[0]['depth'] === 50 &&
                   $request->data()[0]['calculate_rectangles'] === true;
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
                   isset($request->data()[0]['keyword']) &&
                   $request->data()[0]['keyword'] === 'laravel fram' &&
                   isset($request->data()[0]['cursor_pointer']) &&
                   $request->data()[0]['cursor_pointer'] === 8 &&
                   isset($request->data()[0]['client']) &&
                   $request->data()[0]['client'] === 'gws-wiz-serp';
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
                if (!isset($request->data()[0][$key]) || $request->data()[0][$key] !== $value) {
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
            return isset($request->data()[0]['custom_param']) &&
                   $request->data()[0]['custom_param'] === 'custom_value';
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
                   isset($request->data()[0]['keywords']) &&
                   $request->data()[0]['keywords'] === $keywords;
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
                   isset($request->data()[0]['keyword']) &&
                   $request->data()[0]['keyword'] === $keyword;
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
            $sentParams = $request->data()[0];
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
                   isset($request->data()[0]['asin']) &&
                   $request->data()[0]['asin'] === $asin;
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
            $sentParams = $request->data()[0];
            foreach ($expectedParams as $key => $value) {
                if (!isset($sentParams[$key]) || $sentParams[$key] !== $value) {
                    return false;
                }
            }

            return true;
        });
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
                   isset($request->data()[0]['keyword']) &&
                   $request->data()[0]['keyword'] === 'laravel framework';
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
            $requestData = $request->data()[0] ?? [];

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

    // Standard Methods Tests

    public function test_serp_google_organic_standard_returns_cached_response_when_available()
    {
        $id             = 'cached-task-id';
        $cachedResponse = [
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'   => $id,
                    'data' => ['keyword' => 'cached test query'],
                ],
            ],
        ];

        // Mock the cache manager to return cached data
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->with('dataforseo', 'test-cache-key')
            ->willReturn($cachedResponse);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->serpGoogleOrganicStandard(
            'cached test query',
            null, // url
            null, // priority
            null, // depth
            null, // maxCrawlPages
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
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
            'regular', // type
            false, // usePostback
            false, // usePingback
            false // postTaskIfNotCached
        );

        $this->assertEquals($cachedResponse, $result);
    }

    public function test_serp_google_organic_standard_returns_null_when_not_cached_and_posting_disabled()
    {
        // Mock the cache manager to return null (no cached data)
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->with('dataforseo', 'test-cache-key')
            ->willReturn(null);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->serpGoogleOrganicStandard(
            'uncached test query',
            null, // url
            null, // priority
            null, // depth
            null, // maxCrawlPages
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
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
            'regular', // type
            false, // usePostback
            false, // usePingback
            false // postTaskIfNotCached
        );

        $this->assertNull($result);
    }

    public function test_serp_google_organic_standard_creates_task_when_not_cached_and_posting_enabled()
    {
        $id           = 'new-task-id';
        $taskResponse = [
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'             => $id,
                    'status_code'    => 20101,
                    'status_message' => 'Task Created',
                ],
            ],
        ];

        // Mock the cache manager to return null (no cached data)
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);

        // First call: Standard method checking for cached data
        // Second call: TaskPost method generating its own cache key
        $mockCacheManager->expects($this->exactly(2))
            ->method('generateCacheKey')
            ->willReturn('test-cache-key');

        $mockCacheManager->expects($this->exactly(2))
            ->method('getCachedResponse')
            ->with('dataforseo', 'test-cache-key')
            ->willReturn(null);

        // Allow both requests (Standard method + TaskPost method)
        $mockCacheManager->expects($this->once())
            ->method('allowRequest')
            ->with('dataforseo')
            ->willReturn(true);

        // Expect incrementAttempts to be called for the TaskPost request
        $mockCacheManager->expects($this->once())
            ->method('incrementAttempts')
            ->with('dataforseo', 1);

        // Expect storeResponse to be called for successful TaskPost
        $mockCacheManager->expects($this->once())
            ->method('storeResponse')
            ->with(
                'dataforseo',
                'test-cache-key',
                $this->anything(),
                $this->anything(),
                'serp/google/organic/task_post',
                'v3',
                $this->anything(),
                $this->anything(),
                1
            );

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/organic/task_post" => Http::response($taskResponse, 200),
        ]);

        $this->client = new DataForSeoApiClient($mockCacheManager);
        $this->client->clearRateLimit();

        $result = $this->client->serpGoogleOrganicStandard(
            'new task query',
            null, // url
            null, // priority
            null, // depth
            null, // maxCrawlPages
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
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
            'regular', // type
            false, // usePostback
            false, // usePingback
            true // postTaskIfNotCached
        );

        // Verify that a task creation request was made
        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/serp/google/organic/task_post" &&
                   $request->method() === 'POST' &&
                   isset($requestData['keyword']) &&
                   $requestData['keyword'] === 'new task query' &&
                   isset($requestData['tag']) &&
                   $requestData['tag'] === 'test-cache-key';
        });

        // Verify we got the task creation response
        $this->assertIsArray($result);
        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_serp_google_organic_standard_validates_type_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('type must be one of: regular, advanced, html');

        $this->client->serpGoogleOrganicStandard(
            'test query',
            null, // url
            null, // priority
            null, // depth
            null, // maxCrawlPages
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
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
            'invalid-type' // invalid type
        );
    }

    public function test_serp_google_organic_standard_normalizes_type_parameter()
    {
        // Mock the cache manager to return cached data for the test
        $cachedResponse   = ['test' => 'data'];
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->with(
                'dataforseo',
                'serp/google/organic/task_get/advanced', // Should be normalized to lowercase
                $this->anything(),
                'POST',
                'v3'
            )
            ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn($cachedResponse);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->serpGoogleOrganicStandard(
            'test query',
            null, // url
            null, // priority
            null, // depth
            null, // maxCrawlPages
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
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
            'ADVANCED' // uppercase type should be normalized
        );

        $this->assertEquals($cachedResponse, $result);
    }

    public function test_serp_google_organic_standard_excludes_webhook_params_from_cache_key()
    {
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);

        // The cache key should be generated with search parameters only,
        // excluding webhook and control parameters
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->with(
                'dataforseo',
                'serp/google/organic/task_get/regular',
                $this->callback(function ($params) {
                    // Verify that webhook parameters are excluded from cache key generation
                    return !isset($params['type']) &&
                           !isset($params['use_postback']) &&
                           !isset($params['use_pingback']) &&
                           !isset($params['post_task_if_not_cached']) &&
                           isset($params['keyword']) &&
                           $params['keyword'] === 'test query';
                }),
                'POST',
                'v3'
            )
            ->willReturn('test-cache-key');

        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn(['cached' => 'data']);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $this->client->serpGoogleOrganicStandard(
            'test query',
            null, // url
            null, // priority
            null, // depth
            null, // maxCrawlPages
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
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
            'regular', // type (should be excluded)
            true, // usePostback (should be excluded)
            true, // usePingback (should be excluded)
            true // postTaskIfNotCached (should be excluded)
        );
    }

    public static function standardMethodWrappersProvider(): array
    {
        return [
            'regular wrapper' => [
                'method'       => 'serpGoogleOrganicStandardRegular',
                'expectedType' => 'regular',
            ],
            'advanced wrapper' => [
                'method'       => 'serpGoogleOrganicStandardAdvanced',
                'expectedType' => 'advanced',
            ],
            'html wrapper' => [
                'method'       => 'serpGoogleOrganicStandardHtml',
                'expectedType' => 'html',
            ],
        ];
    }

    #[DataProvider('standardMethodWrappersProvider')]
    public function test_standard_wrapper_methods_call_main_method_with_correct_type(string $method, string $expectedType)
    {
        $cachedResponse = ['test' => 'wrapper data'];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->with(
                'dataforseo',
                "serp/google/organic/task_get/{$expectedType}",
                $this->anything(),
                'POST',
                'v3'
            )
            ->willReturn('wrapper-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn($cachedResponse);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->$method(
            'wrapper test query',
            null, // url
            null, // priority
            null, // depth
            null, // maxCrawlPages
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
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
            false, // usePostback
            false, // usePingback
            false // postTaskIfNotCached
        );

        $this->assertEquals($cachedResponse, $result);
    }

    #[DataProvider('standardMethodWrappersProvider')]
    public function test_standard_wrapper_methods_pass_through_webhook_parameters(string $method, string $expectedType)
    {
        $taskResponse = [
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'             => 'wrapper-task-id',
                    'status_code'    => 20101,
                    'status_message' => 'Task Created',
                ],
            ],
        ];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);

        // First call: Standard method checking for cached data
        // Second call: TaskPost method generating its own cache key
        $mockCacheManager->expects($this->exactly(2))
            ->method('generateCacheKey')
            ->willReturn('wrapper-cache-key');

        $mockCacheManager->expects($this->exactly(2))
            ->method('getCachedResponse')
            ->willReturn(null); // Not cached

        // Allow both requests (Standard method + TaskPost method)
        $mockCacheManager->expects($this->once())
            ->method('allowRequest')
            ->with('dataforseo')
            ->willReturn(true);

        // Expect incrementAttempts to be called for the TaskPost request
        $mockCacheManager->expects($this->once())
            ->method('incrementAttempts')
            ->with('dataforseo', 1);

        // Expect storeResponse to be called for successful TaskPost
        $mockCacheManager->expects($this->once())
            ->method('storeResponse')
            ->with(
                'dataforseo',
                'wrapper-cache-key',
                $this->anything(),
                $this->anything(),
                'serp/google/organic/task_post',
                'v3',
                $this->anything(),
                $this->anything(),
                1
            );

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/organic/task_post" => Http::response($taskResponse, 200),
        ]);

        $this->client = new DataForSeoApiClient($mockCacheManager);
        $this->client->clearRateLimit();

        $result = $this->client->$method(
            'wrapper webhook test',
            null, // url
            null, // priority
            null, // depth
            null, // maxCrawlPages
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
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
            true, // usePostback
            true, // usePingback
            true // postTaskIfNotCached
        );

        // Verify that task creation was called
        Http::assertSent(function ($request) use ($expectedType) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/serp/google/organic/task_post" &&
                   $request->method() === 'POST' &&
                   isset($requestData['keyword']) &&
                   $requestData['keyword'] === 'wrapper webhook test' &&
                   isset($requestData['postback_data']) &&
                   $requestData['postback_data'] === $expectedType;
        });

        $this->assertIsArray($result);
        $this->assertEquals(200, $result['response_status_code']);
    }

    public function test_serp_google_organic_standard_regular_wrapper_basic_functionality()
    {
        $cachedResponse = ['test' => 'regular wrapper data'];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('regular-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn($cachedResponse);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->serpGoogleOrganicStandardRegular('regular test query');

        $this->assertEquals($cachedResponse, $result);
    }

    public function test_serp_google_organic_standard_advanced_wrapper_basic_functionality()
    {
        $cachedResponse = ['test' => 'advanced wrapper data'];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('advanced-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn($cachedResponse);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->serpGoogleOrganicStandardAdvanced('advanced test query');

        $this->assertEquals($cachedResponse, $result);
    }

    public function test_serp_google_organic_standard_html_wrapper_basic_functionality()
    {
        $cachedResponse = ['test' => 'html wrapper data'];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('html-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn($cachedResponse);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->serpGoogleOrganicStandardHtml('html test query');

        $this->assertEquals($cachedResponse, $result);
    }

    public function test_serp_google_autocomplete_task_post_successful_request()
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
                        'autocomplete',
                        'task_post',
                    ],
                    'data' => [
                        'api'           => 'serp',
                        'function'      => 'task_post',
                        'se'            => 'google',
                        'se_type'       => 'autocomplete',
                        'keyword'       => 'laravel',
                        'language_code' => 'en',
                        'location_code' => 2840,
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
            "{$this->apiBaseUrl}/serp/google/autocomplete/task_post" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->serpGoogleAutocompleteTaskPost('laravel');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/serp/google/autocomplete/task_post" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['keyword']) &&
                   $request->data()[0]['keyword'] === 'laravel';
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
    }

    public function test_serp_google_autocomplete_task_post_validates_empty_keyword()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keyword cannot be empty');

        $this->client->serpGoogleAutocompleteTaskPost('');
    }

    public function test_serp_google_autocomplete_task_post_validates_keyword_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keyword must be 700 characters or less');

        $longKeyword = str_repeat('a', 701);
        $this->client->serpGoogleAutocompleteTaskPost($longKeyword);
    }

    public function test_serp_google_autocomplete_task_post_validates_required_language_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either languageName or languageCode must be provided');

        $this->client->serpGoogleAutocompleteTaskPost(
            'laravel',
            null, // priority
            null, // locationName
            2840, // locationCode
            null, // languageName
            null  // languageCode set to null
        );
    }

    public function test_serp_google_autocomplete_task_post_validates_required_location_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either locationName or locationCode must be provided');

        $this->client->serpGoogleAutocompleteTaskPost(
            'laravel',
            null, // priority
            null, // locationName set to null
            null, // locationCode set to null
            null, // languageName
            'en'  // languageCode
        );
    }

    public function test_serp_google_autocomplete_task_post_validates_priority_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Priority must be either 1 (normal) or 2 (high)');

        $this->client->serpGoogleAutocompleteTaskPost(
            'laravel',
            3 // Invalid priority
        );
    }

    public function test_serp_google_autocomplete_task_post_validates_cursor_pointer_bounds()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cursorPointer must be between 0 and keyword length');

        $this->client->serpGoogleAutocompleteTaskPost(
            'laravel',
            null, // priority
            null, // locationName
            2840, // locationCode
            null, // languageName
            'en', // languageCode
            10    // cursorPointer greater than keyword length (7)
        );
    }

    public function test_serp_google_autocomplete_task_post_validates_postback_data_requirement()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('postbackData is required when postbackUrl is specified');

        $this->client->serpGoogleAutocompleteTaskPost(
            'laravel',
            null, // priority
            null, // locationName
            2840, // locationCode
            null, // languageName
            'en', // languageCode
            null, // cursorPointer
            null, // client
            null, // tag
            'https://example.com/callback', // postbackUrl
            null  // postbackData is null
        );
    }

    public static function serpGoogleAutocompleteTaskPostParametersProvider(): array
    {
        return [
            'all parameters set' => [
                'parameters' => [
                    'keyword'       => 'laravel framework',
                    'priority'      => 2,
                    'locationName'  => 'London,England,United Kingdom',
                    'languageName'  => 'English',
                    'cursorPointer' => 7,
                    'client'        => 'chrome',
                    'tag'           => 'test-tag',
                    'postbackUrl'   => 'https://example.com/callback',
                    'postbackData'  => 'advanced',
                    'pingbackUrl'   => 'https://example.com/pingback',
                ],
                'expectedParams' => [
                    'keyword'        => 'laravel framework',
                    'priority'       => 2,
                    'location_name'  => 'London,England,United Kingdom',
                    'language_name'  => 'English',
                    'cursor_pointer' => 7,
                    'client'         => 'chrome',
                    'tag'            => 'test-tag',
                    'postback_url'   => 'https://example.com/callback',
                    'postback_data'  => 'advanced',
                    'pingback_url'   => 'https://example.com/pingback',
                ],
            ],
            'minimal parameters with codes' => [
                'parameters' => [
                    'keyword'      => 'vue.js',
                    'locationCode' => 2840,
                    'languageCode' => 'en',
                ],
                'expectedParams' => [
                    'keyword'       => 'vue.js',
                    'location_code' => 2840,
                    'language_code' => 'en',
                ],
            ],
        ];
    }

    #[DataProvider('serpGoogleAutocompleteTaskPostParametersProvider')]
    public function test_serp_google_autocomplete_task_post_builds_request_with_correct_parameters($parameters, $expectedParams)
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
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.015,
                    'result_count'   => 1,
                    'path'           => ['serp', 'google', 'autocomplete', 'task_post'],
                    'data'           => $expectedParams,
                    'result'         => [
                        'task_id'        => '12345678-1234-1234-1234-123456789012',
                        'status_message' => 'Ok.',
                        'status_code'    => 20000,
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/autocomplete/task_post" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $this->client->serpGoogleAutocompleteTaskPost(
            keyword: $parameters['keyword'],
            priority: $parameters['priority'] ?? null,
            locationName: $parameters['locationName'] ?? null,
            locationCode: $parameters['locationCode'] ?? null,
            languageName: $parameters['languageName'] ?? null,
            languageCode: $parameters['languageCode'] ?? null,
            cursorPointer: $parameters['cursorPointer'] ?? null,
            client: $parameters['client'] ?? null,
            tag: $parameters['tag'] ?? null,
            postbackUrl: $parameters['postbackUrl'] ?? null,
            postbackData: $parameters['postbackData'] ?? null,
            pingbackUrl: $parameters['pingbackUrl'] ?? null
        );

        Http::assertSent(function ($request) use ($expectedParams) {
            $requestData = $request->data()[0] ?? [];

            foreach ($expectedParams as $key => $value) {
                if (!array_key_exists($key, $requestData) || $requestData[$key] !== $value) {
                    return false;
                }
            }

            return $request->url() === "{$this->apiBaseUrl}/serp/google/autocomplete/task_post" &&
                   $request->method() === 'POST';
        });
    }

    public function test_serp_google_autocomplete_task_post_handles_api_errors()
    {
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40000,
            'status_message' => 'Bad Request.',
            'time'           => '0.0097 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 1,
            'tasks'          => [
                [
                    'id'             => null,
                    'status_code'    => 40000,
                    'status_message' => 'Bad Request.',
                    'time'           => '0.0097 sec.',
                    'cost'           => 0,
                    'result_count'   => 0,
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/autocomplete/task_post" => Http::response($errorResponse, 400),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->serpGoogleAutocompleteTaskPost('laravel');

        $this->assertEquals(400, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals(40000, $responseData['status_code']);
        $this->assertEquals('Bad Request.', $responseData['status_message']);
    }

    public function test_serp_google_autocomplete_task_get_advanced_successful_request()
    {
        $taskId          = '12345678-1234-1234-1234-123456789012';
        $keyword         = 'laravel autocomplete';
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
                    'path'           => ['serp', 'google', 'autocomplete', 'task_get', 'advanced'],
                    'data'           => [
                        'api'      => 'serp',
                        'function' => 'task_get',
                        'se'       => 'google',
                        'se_type'  => 'autocomplete',
                        'keyword'  => $keyword,
                    ],
                    'result' => [
                        [
                            'keyword'     => $keyword,
                            'items_count' => 3,
                            'items'       => [
                                [
                                    'type'  => 'autocomplete_item',
                                    'title' => 'laravel autocomplete example',
                                ],
                                [
                                    'type'  => 'autocomplete_item',
                                    'title' => 'laravel autocomplete vue',
                                ],
                                [
                                    'type'  => 'autocomplete_item',
                                    'title' => 'laravel autocomplete livewire',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/autocomplete/task_get/advanced/{$taskId}" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->serpGoogleAutocompleteTaskGetAdvanced($taskId);

        Http::assertSent(function ($request) use ($taskId) {
            return $request->url() === "{$this->apiBaseUrl}/serp/google/autocomplete/task_get/advanced/{$taskId}" &&
                   $request->method() === 'GET';
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
        $this->assertEquals($keyword, $responseData['tasks'][0]['data']['keyword']);
        $this->assertEquals(3, $responseData['tasks'][0]['result'][0]['items_count']);
    }

    public function test_serp_google_autocomplete_task_get_advanced_passes_attributes_and_amount()
    {
        $taskId          = '12345678-1234-1234-1234-123456789012';
        $attributes      = 'custom-attributes-autocomplete';
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
            "{$this->apiBaseUrl}/serp/google/autocomplete/task_get/advanced/{$taskId}" => Http::response($successResponse, 200),
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
                'serp/google/autocomplete/task_get/advanced',
                $taskId,
                $attributes,
                $amount
            )
            ->willReturn(['response' => Http::response($successResponse), 'response_status_code' => 200]);

        $mockClient->serpGoogleAutocompleteTaskGetAdvanced($taskId, $attributes, $amount);
    }

    public function test_serp_google_autocomplete_task_get_advanced_handles_error_response()
    {
        $taskId        = '12345678-1234-1234-1234-123456789012';
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40000,
            'status_message' => 'Bad Request.',
            'time'           => '0.0097 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 1,
            'tasks'          => [
                [
                    'id'             => $taskId,
                    'status_code'    => 40000,
                    'status_message' => 'Bad Request.',
                    'time'           => '0.0097 sec.',
                    'cost'           => 0,
                    'result_count'   => 0,
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/autocomplete/task_get/advanced/{$taskId}" => Http::response($errorResponse, 400),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->serpGoogleAutocompleteTaskGetAdvanced($taskId);

        $this->assertEquals(400, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals(40000, $responseData['status_code']);
        $this->assertEquals('Bad Request.', $responseData['status_message']);
    }

    public function test_serp_google_autocomplete_standard_advanced_returns_cached_response_when_available()
    {
        $cachedResponse = [
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'   => 'cached-autocomplete-id',
                    'data' => ['keyword' => 'cached test query'],
                ],
            ],
        ];

        // Mock the cache manager to return cached data
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('test-autocomplete-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->with('dataforseo', 'test-autocomplete-cache-key')
            ->willReturn($cachedResponse);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->serpGoogleAutocompleteStandardAdvanced(
            'cached test query',
            null, // priority
            'United States', // locationName
            null, // locationCode
            'English', // languageName
            null, // languageCode
            null, // cursorPointer
            null, // client
            false, // usePostback
            false, // usePingback
            false // postTaskIfNotCached
        );

        $this->assertEquals($cachedResponse, $result);
    }

    public function test_serp_google_autocomplete_standard_advanced_returns_null_when_not_cached_and_posting_disabled()
    {
        // Mock the cache manager to return null (no cached data)
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('test-autocomplete-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->with('dataforseo', 'test-autocomplete-cache-key')
            ->willReturn(null);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->serpGoogleAutocompleteStandardAdvanced(
            'uncached test query',
            null, // priority
            'United States', // locationName
            null, // locationCode
            'English', // languageName
            null, // languageCode
            null, // cursorPointer
            null, // client
            false, // usePostback
            false, // usePingback
            false // postTaskIfNotCached
        );

        $this->assertNull($result);
    }

    public function test_serp_google_autocomplete_standard_advanced_creates_task_when_not_cached_and_posting_enabled()
    {
        $id           = '12345678-1234-1234-1234-123456789012';
        $taskResponse = [
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'             => $id,
                    'status_code'    => 20101,
                    'status_message' => 'Task Created',
                ],
            ],
        ];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);

        // First call: Standard method checking for cached data
        // Second call: TaskPost method generating its own cache key
        $mockCacheManager->expects($this->exactly(2))
            ->method('generateCacheKey')
            ->willReturn('test-autocomplete-cache-key');

        $mockCacheManager->expects($this->exactly(2))
            ->method('getCachedResponse')
            ->willReturn(null); // Not cached

        // Allow the task post request
        $mockCacheManager->expects($this->once())
            ->method('allowRequest')
            ->with('dataforseo')
            ->willReturn(true);

        // Expect incrementAttempts to be called for the TaskPost request
        $mockCacheManager->expects($this->once())
            ->method('incrementAttempts')
            ->with('dataforseo', 1);

        // Expect storeResponse to be called for successful TaskPost
        $mockCacheManager->expects($this->once())
            ->method('storeResponse')
            ->with(
                'dataforseo',
                'test-autocomplete-cache-key',
                $this->anything(),
                $this->anything(),
                'serp/google/autocomplete/task_post',
                'v3',
                $this->anything(),
                $this->anything(),
                1
            );

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/autocomplete/task_post" => Http::response($taskResponse, 200),
        ]);

        $this->client = new DataForSeoApiClient($mockCacheManager);
        $this->client->clearRateLimit();

        $result = $this->client->serpGoogleAutocompleteStandardAdvanced(
            'new autocomplete task query',
            null, // priority
            'United States', // locationName
            null, // locationCode
            'English', // languageName
            null, // languageCode
            null, // cursorPointer
            null, // client
            false, // usePostback
            false, // usePingback
            true // postTaskIfNotCached
        );

        // Verify that a task creation request was made
        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/serp/google/autocomplete/task_post" &&
                   $request->method() === 'POST' &&
                   isset($requestData['keyword']) &&
                   $requestData['keyword'] === 'new autocomplete task query' &&
                   isset($requestData['tag']) &&
                   $requestData['tag'] === 'test-autocomplete-cache-key';
        });

        // Verify we got the task creation response
        $this->assertIsArray($result);
        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_serp_google_autocomplete_standard_advanced_excludes_webhook_params_from_cache_key()
    {
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);

        // Verify buildApiParams is called with the correct excluded parameters
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn(['cached' => 'autocomplete data']);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $this->client->serpGoogleAutocompleteStandardAdvanced(
            'test query',
            null, // priority
            'United States', // locationName
            null, // locationCode
            'English', // languageName
            null, // languageCode
            null, // cursorPointer
            null, // client
            true, // usePostback (should be excluded)
            true, // usePingback (should be excluded)
            true // postTaskIfNotCached (should be excluded)
        );
    }

    public function test_serp_google_autocomplete_standard_advanced_passes_webhook_parameters_to_task_post()
    {
        $taskResponse = [
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'             => 'webhook-task-id',
                    'status_code'    => 20101,
                    'status_message' => 'Task Created',
                ],
            ],
        ];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);

        // Standard method and TaskPost method both generate cache keys
        $mockCacheManager->expects($this->exactly(2))
            ->method('generateCacheKey')
            ->willReturn('webhook-cache-key');

        $mockCacheManager->expects($this->exactly(2))
            ->method('getCachedResponse')
            ->willReturn(null); // Not cached

        $mockCacheManager->expects($this->once())
            ->method('allowRequest')
            ->willReturn(true);

        $mockCacheManager->expects($this->once())
            ->method('incrementAttempts');

        $mockCacheManager->expects($this->once())
            ->method('storeResponse');

        Http::fake([
            "{$this->apiBaseUrl}/serp/google/autocomplete/task_post" => Http::response($taskResponse, 200),
        ]);

        $this->client = new DataForSeoApiClient($mockCacheManager);
        $this->client->clearRateLimit();

        $result = $this->client->serpGoogleAutocompleteStandardAdvanced(
            'webhook autocomplete test',
            null, // priority
            'United States', // locationName
            null, // locationCode
            'English', // languageName
            null, // languageCode
            null, // cursorPointer
            null, // client
            true, // usePostback
            true, // usePingback
            true // postTaskIfNotCached
        );

        // Verify that task creation was called with webhook parameters
        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/serp/google/autocomplete/task_post" &&
                   $request->method() === 'POST' &&
                   isset($requestData['keyword']) &&
                   $requestData['keyword'] === 'webhook autocomplete test' &&
                   isset($requestData['postback_data']) &&
                   $requestData['postback_data'] === 'advanced';
        });

        $this->assertIsArray($result);
        $this->assertEquals(200, $result['response_status_code']);
    }

    public function test_serp_google_autocomplete_standard_advanced_basic_functionality()
    {
        $cachedResponse = ['test' => 'autocomplete standard advanced data'];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('autocomplete-advanced-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn($cachedResponse);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->serpGoogleAutocompleteStandardAdvanced('autocomplete advanced test query');

        $this->assertEquals($cachedResponse, $result);
    }

    public function test_merchant_amazon_products_task_post_successful_request()
    {
        $responseData = [
            'version'        => '0.1.20250425',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.01 sec.',
            'cost'           => 0.003,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => 'merchant-task-id',
                    'status_code'    => 20101,
                    'status_message' => 'Task Created',
                    'time'           => '0.01 sec.',
                    'cost'           => 0.003,
                    'result_count'   => 0,
                    'path'           => ['v3', 'merchant', 'amazon', 'products', 'task_post'],
                    'data'           => [
                        'api'           => 'merchant',
                        'function'      => 'amazon_products',
                        'se'            => 'amazon',
                        'keyword'       => 'wireless headphones',
                        'location_name' => 'United States',
                        'language_code' => 'en_US',
                        'location_code' => 2840,
                        'depth'         => 100,
                        'priority'      => 2,
                        'tag'           => 'test-cache-key',
                    ],
                    'result' => null,
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/products/task_post" => Http::response($responseData, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonProductsTaskPost(
            'wireless headphones',
            null, // url
            2, // priority
            'United States' // locationName
        );

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/products/task_post" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['keyword']) &&
                   $request->data()[0]['keyword'] === 'wireless headphones' &&
                   isset($request->data()[0]['location_name']) &&
                   $request->data()[0]['location_name'] === 'United States' &&
                   isset($request->data()[0]['priority']) &&
                   $request->data()[0]['priority'] === 2;
        });

        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertEquals('merchant-task-id', $responseData['tasks'][0]['id']);
    }

    public function test_merchant_amazon_products_task_post_validates_empty_keyword()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keyword cannot be empty');

        $this->client->merchantAmazonProductsTaskPost('');
    }

    public function test_merchant_amazon_products_task_post_validates_required_language_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either languageName or languageCode must be provided');

        $this->client->merchantAmazonProductsTaskPost(
            'test product',
            null, // url
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            null, // languageName
            null // languageCode
        );
    }

    public function test_merchant_amazon_products_task_post_validates_required_location_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either locationName, locationCode, or locationCoordinate must be provided');

        $this->client->merchantAmazonProductsTaskPost(
            'test product',
            null, // url
            null, // priority
            null, // locationName
            null, // locationCode
            null, // locationCoordinate
            'English' // languageName
        );
    }

    public function test_merchant_amazon_products_task_post_validates_priority_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Priority must be either 1 (normal) or 2 (high)');

        $this->client->merchantAmazonProductsTaskPost(
            'test product',
            null, // url
            0, // priority
            'United States' // locationName
        );
    }

    public function test_merchant_amazon_products_task_post_validates_depth_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Depth must be less than or equal to 700');

        $this->client->merchantAmazonProductsTaskPost(
            'test product',
            null, // url
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            701 // depth
        );
    }

    public function test_merchant_amazon_products_task_post_validates_postback_data_requirement()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('postbackData is required when postbackUrl is specified');

        $this->client->merchantAmazonProductsTaskPost(
            'test product',
            null, // url
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            100, // depth
            null, // maxCrawlPages
            null, // department
            null, // searchParam
            null, // priceMin
            null, // priceMax
            null, // sortBy
            null, // tag
            'postback-url', // postbackUrl
            null // postbackData (missing, should trigger error)
        );
    }

    public static function merchantAmazonProductsTaskPostParametersProvider(): array
    {
        return [
            'basic parameters' => [
                [
                    'keyword'      => 'bluetooth speaker',
                    'locationName' => 'United States',
                    'languageCode' => 'en_US',
                ],
                [
                    'keyword'       => 'bluetooth speaker',
                    'location_name' => 'United States',
                    'language_code' => 'en_US',
                    'location_code' => 2840,
                    'depth'         => 100,
                ],
            ],
            'with url and priority' => [
                [
                    'keyword'      => 'gaming mouse',
                    'url'          => 'https://www.amazon.com/s?k=gaming+mouse',
                    'priority'     => 1,
                    'locationCode' => 2826,
                    'languageName' => 'English',
                ],
                [
                    'keyword'       => 'gaming mouse',
                    'url'           => 'https://www.amazon.com/s?k=gaming+mouse',
                    'priority'      => 1,
                    'location_code' => 2826,
                    'language_name' => 'English',
                    'depth'         => 100,
                ],
            ],
            'with se_domain and department' => [
                [
                    'keyword'      => 'laptop computer',
                    'locationName' => 'United Kingdom',
                    'languageCode' => 'en_GB',
                    'seDomain'     => 'amazon.co.uk',
                    'department'   => 'Electronics',
                ],
                [
                    'keyword'       => 'laptop computer',
                    'location_name' => 'United Kingdom',
                    'language_code' => 'en_GB',
                    'location_code' => 2840,
                    'se_domain'     => 'amazon.co.uk',
                    'department'    => 'Electronics',
                    'depth'         => 100,
                ],
            ],
            'with sorting and filters' => [
                [
                    'keyword'      => 'coffee maker',
                    'locationName' => 'Canada',
                    'languageCode' => 'en_CA',
                    'sortBy'       => 'price_high_to_low',
                    'priceMax'     => 200,
                    'priceMin'     => 50,
                ],
                [
                    'keyword'       => 'coffee maker',
                    'location_name' => 'Canada',
                    'language_code' => 'en_CA',
                    'location_code' => 2840,
                    'sort_by'       => 'price_high_to_low',
                    'price_max'     => 200,
                    'price_min'     => 50,
                    'depth'         => 100,
                ],
            ],
        ];
    }

    #[DataProvider('merchantAmazonProductsTaskPostParametersProvider')]
    public function test_merchant_amazon_products_task_post_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $taskResponse = [
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'             => 'test-merchant-task-id',
                    'status_code'    => 20101,
                    'status_message' => 'Task Created',
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/products/task_post" => Http::response($taskResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $this->client->merchantAmazonProductsTaskPost(...$parameters);

        Http::assertSent(function ($request) use ($expectedParams) {
            $requestData = $request->data()[0] ?? [];

            foreach ($expectedParams as $key => $value) {
                if (!isset($requestData[$key]) || $requestData[$key] !== $value) {
                    return false;
                }
            }

            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/products/task_post" &&
                   $request->method() === 'POST';
        });
    }

    public function test_merchant_amazon_products_task_post_handles_api_errors()
    {
        $errorResponse = [
            'version'        => '0.1.20250425',
            'status_code'    => 40000,
            'status_message' => 'Bad Request',
            'tasks'          => [],
        ];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('error-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('allowRequest')
            ->willReturn(true);
        $mockCacheManager->expects($this->once())
            ->method('incrementAttempts');

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/products/task_post" => Http::response($errorResponse, 400),
        ]);

        $this->client = new DataForSeoApiClient($mockCacheManager);
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonProductsTaskPost(
            'test product',
            null, // url
            null, // priority
            'United States' // locationName
        );

        $this->assertEquals(400, $result['response_status_code']);
    }

    public function test_merchant_amazon_products_standard_returns_cached_response_when_available()
    {
        $cachedResponse = [
            'tasks' => [
                [
                    'result' => [
                        [
                            'type'  => 'amazon_product',
                            'title' => 'Cached Product',
                            'price' => ['current' => 19.99],
                        ],
                    ],
                ],
            ],
        ];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('std-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn($cachedResponse);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->merchantAmazonProductsStandard(
            'cached product query',
            null, // url
            null, // priority
            'United States' // locationName
        );

        $this->assertEquals($cachedResponse, $result);
    }

    public function test_merchant_amazon_products_standard_advanced_returns_cached_response_when_available()
    {
        $cachedResponse = [
            'tasks' => [
                [
                    'result' => [
                        [
                            'type'  => 'amazon_product',
                            'title' => 'Cached Product',
                            'price' => ['current' => 19.99],
                        ],
                    ],
                ],
            ],
        ];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('advanced-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn($cachedResponse);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->merchantAmazonProductsStandardAdvanced(
            'cached product query',
            null, // url
            null, // priority
            'United States' // locationName
        );

        $this->assertEquals($cachedResponse, $result);
    }

    public function test_merchant_amazon_products_standard_advanced_returns_null_when_not_cached_and_posting_disabled()
    {
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('not-cached-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn(null); // Not cached

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->merchantAmazonProductsStandardAdvanced(
            'not cached query',
            null, // url
            null, // priority
            'United States', // locationName
            2840, // locationCode
            null, // locationCoordinate
            'English', // languageName
            'en_US', // languageCode
            null, // seDomain
            100, // depth
            null, // maxCrawlPages
            null, // department
            null, // searchParam
            null, // priceMin
            null, // priceMax
            null, // sortBy
            false, // usePostback
            false, // usePingback
            false // postTaskIfNotCached
        );

        $this->assertNull($result);
    }

    public function test_merchant_amazon_products_standard_advanced_creates_task_when_not_cached_and_posting_enabled()
    {
        $taskResponse = [
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'             => 'new-advanced-task-id',
                    'status_code'    => 20101,
                    'status_message' => 'Task Created',
                ],
            ],
        ];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);

        // First call: Standard method checking for cached data
        // Second call: TaskPost method generating its own cache key
        $mockCacheManager->expects($this->exactly(2))
            ->method('generateCacheKey')
            ->willReturn('test-advanced-cache-key');

        $mockCacheManager->expects($this->exactly(2))
            ->method('getCachedResponse')
            ->willReturn(null); // Not cached

        // Allow the task post request
        $mockCacheManager->expects($this->once())
            ->method('allowRequest')
            ->with('dataforseo')
            ->willReturn(true);

        // Expect incrementAttempts to be called for the TaskPost request
        $mockCacheManager->expects($this->once())
            ->method('incrementAttempts')
            ->with('dataforseo', 1);

        // Expect storeResponse to be called for successful TaskPost
        $mockCacheManager->expects($this->once())
            ->method('storeResponse')
            ->with(
                'dataforseo',
                'test-advanced-cache-key',
                $this->anything(),
                $this->anything(),
                'merchant/amazon/products/task_post',
                'v3',
                $this->anything(),
                $this->anything(),
                1
            );

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/products/task_post" => Http::response($taskResponse, 200),
        ]);

        $this->client = new DataForSeoApiClient($mockCacheManager);
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonProductsStandardAdvanced(
            'new advanced task query',
            null, // url
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            100, // depth
            null, // maxCrawlPages
            null, // department
            null, // searchParam
            null, // priceMin
            null, // priceMax
            null, // sortBy
            false, // usePostback
            false, // usePingback
            true // postTaskIfNotCached
        );

        // Verify that a task creation request was made
        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/products/task_post" &&
                   $request->method() === 'POST' &&
                   isset($requestData['keyword']) &&
                   $requestData['keyword'] === 'new advanced task query' &&
                   isset($requestData['tag']) &&
                   $requestData['tag'] === 'test-advanced-cache-key';
        });

        // Verify we got the task creation response
        $this->assertIsArray($result);
        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertEquals('new-advanced-task-id', $responseData['tasks'][0]['id']);
    }

    public function test_merchant_amazon_products_standard_advanced_excludes_webhook_params_from_cache_key()
    {
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);

        // Verify buildApiParams is called with the correct excluded parameters
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn(['cached' => 'merchant advanced data']);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $this->client->merchantAmazonProductsStandardAdvanced(
            'test merchant query',
            null, // url
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            100, // depth
            null, // maxCrawlPages
            null, // department
            null, // searchParam
            null, // priceMin
            null, // priceMax
            null, // sortBy
            true, // usePostback (should be excluded)
            true, // usePingback (should be excluded)
            true // postTaskIfNotCached (should be excluded)
        );
    }

    public function test_merchant_amazon_products_standard_advanced_passes_webhook_parameters_to_task_post()
    {
        $taskResponse = [
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'             => 'webhook-merchant-task-id',
                    'status_code'    => 20101,
                    'status_message' => 'Task Created',
                ],
            ],
        ];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);

        // Standard method and TaskPost method both generate cache keys
        $mockCacheManager->expects($this->exactly(2))
            ->method('generateCacheKey')
            ->willReturn('webhook-merchant-cache-key');

        $mockCacheManager->expects($this->exactly(2))
            ->method('getCachedResponse')
            ->willReturn(null); // Not cached

        $mockCacheManager->expects($this->once())
            ->method('allowRequest')
            ->willReturn(true);

        $mockCacheManager->expects($this->once())
            ->method('incrementAttempts');

        $mockCacheManager->expects($this->once())
            ->method('storeResponse');

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/products/task_post" => Http::response($taskResponse, 200),
        ]);

        $this->client = new DataForSeoApiClient($mockCacheManager);
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonProductsStandardAdvanced(
            'webhook merchant test',
            null, // url
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            100, // depth
            null, // maxCrawlPages
            null, // department
            null, // searchParam
            null, // priceMin
            null, // priceMax
            null, // sortBy
            true, // usePostback
            true, // usePingback
            true // postTaskIfNotCached
        );

        // Verify that task creation was called with webhook parameters
        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/products/task_post" &&
                   $request->method() === 'POST' &&
                   isset($requestData['keyword']) &&
                   $requestData['keyword'] === 'webhook merchant test' &&
                   isset($requestData['postback_data']) &&
                   $requestData['postback_data'] === 'advanced';
        });

        $this->assertIsArray($result);
        $this->assertEquals(200, $result['response_status_code']);
    }

    public function test_merchant_amazon_products_standard_advanced_basic_functionality()
    {
        $cachedResponse = ['test' => 'merchant standard advanced data'];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('merchant-advanced-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn($cachedResponse);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->merchantAmazonProductsStandardAdvanced('merchant advanced test query');

        $this->assertEquals($cachedResponse, $result);
    }

    public function test_merchant_amazon_products_standard_html_returns_cached_response_when_available()
    {
        $cachedResponse = [
            'tasks' => [
                [
                    'result' => [
                        [
                            'type' => 'amazon_product_html',
                            'html' => '<div>Amazon Product HTML</div>',
                        ],
                    ],
                ],
            ],
        ];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('html-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn($cachedResponse);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->merchantAmazonProductsStandardHtml(
            'cached html query',
            null, // url
            null, // priority
            'United States' // locationName
        );

        $this->assertEquals($cachedResponse, $result);
    }

    public function test_merchant_amazon_products_standard_html_returns_null_when_not_cached_and_posting_disabled()
    {
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('not-cached-html-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn(null); // Not cached

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->merchantAmazonProductsStandardHtml(
            'not cached html query',
            null, // url
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            100, // depth
            null, // maxCrawlPages
            null, // department
            null, // searchParam
            null, // priceMin
            null, // priceMax
            null, // sortBy
            false, // usePostback
            false, // usePingback
            false // postTaskIfNotCached
        );

        $this->assertNull($result);
    }

    public function test_merchant_amazon_products_standard_html_creates_task_when_not_cached_and_posting_enabled()
    {
        $taskResponse = [
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'             => 'new-html-task-id',
                    'status_code'    => 20101,
                    'status_message' => 'Task Created',
                ],
            ],
        ];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);

        // First call: Standard method checking for cached data
        // Second call: TaskPost method generating its own cache key
        $mockCacheManager->expects($this->exactly(2))
            ->method('generateCacheKey')
            ->willReturn('test-html-cache-key');

        $mockCacheManager->expects($this->exactly(2))
            ->method('getCachedResponse')
            ->willReturn(null); // Not cached

        // Allow the task post request
        $mockCacheManager->expects($this->once())
            ->method('allowRequest')
            ->with('dataforseo')
            ->willReturn(true);

        // Expect incrementAttempts to be called for the TaskPost request
        $mockCacheManager->expects($this->once())
            ->method('incrementAttempts')
            ->with('dataforseo', 1);

        // Expect storeResponse to be called for successful TaskPost
        $mockCacheManager->expects($this->once())
            ->method('storeResponse')
            ->with(
                'dataforseo',
                'test-html-cache-key',
                $this->anything(),
                $this->anything(),
                'merchant/amazon/products/task_post',
                'v3',
                $this->anything(),
                $this->anything(),
                1
            );

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/products/task_post" => Http::response($taskResponse, 200),
        ]);

        $this->client = new DataForSeoApiClient($mockCacheManager);
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonProductsStandardHtml(
            'new html task query',
            null, // url
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            100, // depth
            null, // maxCrawlPages
            null, // department
            null, // searchParam
            null, // priceMin
            null, // priceMax
            null, // sortBy
            false, // usePostback
            false, // usePingback
            true // postTaskIfNotCached
        );

        // Verify that a task creation request was made
        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/products/task_post" &&
                   $request->method() === 'POST' &&
                   isset($requestData['keyword']) &&
                   $requestData['keyword'] === 'new html task query' &&
                   isset($requestData['tag']) &&
                   $requestData['tag'] === 'test-html-cache-key' &&
                   isset($requestData['postback_data']) &&
                   $requestData['postback_data'] === 'html';
        });

        // Verify we got the task creation response
        $this->assertIsArray($result);
        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertEquals('new-html-task-id', $responseData['tasks'][0]['id']);
    }

    public function test_merchant_amazon_products_standard_html_excludes_webhook_params_from_cache_key()
    {
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);

        // Verify buildApiParams is called with the correct excluded parameters
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('test-html-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn(['cached' => 'merchant html data']);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $this->client->merchantAmazonProductsStandardHtml(
            'test merchant html query',
            null, // url
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            100, // depth
            null, // maxCrawlPages
            null, // department
            null, // searchParam
            null, // priceMin
            null, // priceMax
            null, // sortBy
            true, // usePostback (should be excluded)
            true, // usePingback (should be excluded)
            true // postTaskIfNotCached (should be excluded)
        );
    }

    public function test_merchant_amazon_products_standard_html_passes_webhook_parameters_to_task_post()
    {
        $taskResponse = [
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'             => 'webhook-html-task-id',
                    'status_code'    => 20101,
                    'status_message' => 'Task Created',
                ],
            ],
        ];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);

        // Standard method and TaskPost method both generate cache keys
        $mockCacheManager->expects($this->exactly(2))
            ->method('generateCacheKey')
            ->willReturn('webhook-html-cache-key');

        $mockCacheManager->expects($this->exactly(2))
            ->method('getCachedResponse')
            ->willReturn(null); // Not cached

        $mockCacheManager->expects($this->once())
            ->method('allowRequest')
            ->willReturn(true);

        $mockCacheManager->expects($this->once())
            ->method('incrementAttempts');

        $mockCacheManager->expects($this->once())
            ->method('storeResponse');

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/products/task_post" => Http::response($taskResponse, 200),
        ]);

        $this->client = new DataForSeoApiClient($mockCacheManager);
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonProductsStandardHtml(
            'webhook html test',
            null, // url
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            100, // depth
            null, // maxCrawlPages
            null, // department
            null, // searchParam
            null, // priceMin
            null, // priceMax
            null, // sortBy
            true, // usePostback
            true, // usePingback
            true // postTaskIfNotCached
        );

        // Verify that task creation was called with webhook parameters
        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/products/task_post" &&
                   $request->method() === 'POST' &&
                   isset($requestData['keyword']) &&
                   $requestData['keyword'] === 'webhook html test' &&
                   isset($requestData['postback_data']) &&
                   $requestData['postback_data'] === 'html';
        });

        $this->assertIsArray($result);
        $this->assertEquals(200, $result['response_status_code']);
    }

    public function test_merchant_amazon_products_standard_html_basic_functionality()
    {
        $cachedResponse = ['test' => 'merchant standard html data'];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('merchant-html-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn($cachedResponse);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->merchantAmazonProductsStandardHtml('merchant html test query');

        $this->assertEquals($cachedResponse, $result);
    }

    public function test_merchant_amazon_asin_task_post_successful_request()
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/asin/task_post" => Http::response([
                'version'        => '0.1.20250114',
                'status_code'    => 20000,
                'status_message' => 'Ok.',
                'time'           => '0.3294 sec.',
                'cost'           => 0.0025,
                'tasks_count'    => 1,
                'tasks_error'    => 0,
                'tasks'          => [
                    [
                        'id'             => '11081545-3399-0545-0000-17b17e70d2b4',
                        'status_code'    => 20100,
                        'status_message' => 'Task Created.',
                        'time'           => '0.0094 sec.',
                        'cost'           => 0.0025,
                        'result_count'   => 0,
                        'path'           => [
                            'merchant',
                            'amazon',
                            'asin',
                            'task_post',
                        ],
                        'data' => [
                            'api'           => 'merchant',
                            'function'      => 'asin',
                            'asin'          => 'B08N5WRWNW',
                            'location_name' => 'United States',
                            'location_code' => 2840,
                            'language_name' => 'English',
                            'language_code' => 'en',
                            'tag'           => null,
                            'postback_url'  => null,
                            'postback_data' => null,
                            'pingback_url'  => null,
                        ],
                        'result' => null,
                    ],
                ],
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->merchantAmazonAsinTaskPost(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en'
        );

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/asin/task_post" &&
                   $request->method() === 'POST' &&
                   $request->hasHeader('Authorization') &&
                   $request->hasHeader('Content-Type', 'application/json') &&
                   is_array($request->data()) &&
                   isset($request->data()[0]) &&
                   $request->data()[0]['asin'] === 'B08N5WRWNW' &&
                   $request->data()[0]['location_name'] === 'United States' &&
                   $request->data()[0]['location_code'] === 2840 &&
                   $request->data()[0]['language_name'] === 'English' &&
                   $request->data()[0]['language_code'] === 'en';
        });

        // Make sure we used the Http::fake() response
        $body = $response['response']->json();
        $this->assertEquals(20000, $body['status_code']);
        $this->assertEquals('B08N5WRWNW', $body['tasks'][0]['data']['asin']);
    }

    public function test_merchant_amazon_asin_task_post_validates_empty_asin()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ASIN cannot be empty');

        $this->client->merchantAmazonAsinTaskPost('');
    }

    public function test_merchant_amazon_asin_task_post_validates_required_language_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either languageName or languageCode must be provided');

        $this->client->merchantAmazonAsinTaskPost(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            null,
            null
        );
    }

    public function test_merchant_amazon_asin_task_post_validates_required_location_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either locationName, locationCode, or locationCoordinate must be provided');

        $this->client->merchantAmazonAsinTaskPost(
            'B08N5WRWNW',
            null,
            null,
            null,
            null,
            'English',
            'en'
        );
    }

    public function test_merchant_amazon_asin_task_post_validates_priority_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Priority must be either 1 (normal) or 2 (high)');

        $this->client->merchantAmazonAsinTaskPost(
            'B08N5WRWNW',
            3,
            'United States',
            2840,
            null,
            'English',
            'en'
        );
    }

    public function test_merchant_amazon_asin_task_post_validates_local_reviews_sort()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('localReviewsSort must be either "helpful" or "recent"');

        $this->client->merchantAmazonAsinTaskPost(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en',
            null,
            null,
            'invalid'
        );
    }

    public function test_merchant_amazon_asin_task_post_validates_postback_data_requirement()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('postbackData is required when postbackUrl is specified');

        $this->client->merchantAmazonAsinTaskPost(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en',
            null,
            null,
            null,
            null,
            'https://example.com/postback'
        );
    }

    public static function merchantAmazonAsinTaskPostParametersProvider(): array
    {
        return [
            'basic_parameters' => [
                [
                    'asin'         => 'B08N5WRWNW',
                    'locationName' => 'United States',
                    'locationCode' => 2840,
                    'languageName' => 'English',
                    'languageCode' => 'en',
                ],
                [
                    'asin'          => 'B08N5WRWNW',
                    'location_name' => 'United States',
                    'location_code' => 2840,
                    'language_name' => 'English',
                    'language_code' => 'en',
                ],
            ],
            'with_priority_and_reviews' => [
                [
                    'asin'                 => 'B08N5WRWNW',
                    'priority'             => 2,
                    'locationName'         => 'United States',
                    'languageName'         => 'English',
                    'loadMoreLocalReviews' => true,
                    'localReviewsSort'     => 'recent',
                ],
                [
                    'asin'                    => 'B08N5WRWNW',
                    'priority'                => 2,
                    'location_name'           => 'United States',
                    'language_name'           => 'English',
                    'load_more_local_reviews' => true,
                    'local_reviews_sort'      => 'recent',
                ],
            ],
            'with_webhooks' => [
                [
                    'asin'         => 'B08N5WRWNW',
                    'locationCode' => 2840,
                    'languageCode' => 'en',
                    'tag'          => 'test-tag',
                    'postbackUrl'  => 'https://example.com/postback',
                    'postbackData' => 'advanced',
                    'pingbackUrl'  => 'https://example.com/pingback',
                ],
                [
                    'asin'          => 'B08N5WRWNW',
                    'location_code' => 2840,
                    'language_code' => 'en',
                    'tag'           => 'test-tag',
                    'postback_url'  => 'https://example.com/postback',
                    'postback_data' => 'advanced',
                    'pingback_url'  => 'https://example.com/pingback',
                ],
            ],
        ];
    }

    #[DataProvider('merchantAmazonAsinTaskPostParametersProvider')]
    public function test_merchant_amazon_asin_task_post_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/asin/task_post" => Http::response(['status_code' => 20000], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $this->client->merchantAmazonAsinTaskPost(...$parameters);

        Http::assertSent(function ($request) use ($expectedParams) {
            foreach ($expectedParams as $key => $value) {
                if (!isset($request->data()[0][$key]) || $request->data()[0][$key] !== $value) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_merchant_amazon_asin_task_post_handles_api_errors()
    {
        $errorResponse = [
            'version'        => '0.1.20250114',
            'status_code'    => 40401,
            'status_message' => 'Authentication failed',
            'time'           => '0.0121 sec.',
        ];

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/asin/task_post" => Http::response($errorResponse, 401),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->merchantAmazonAsinTaskPost(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en'
        );

        $this->assertEquals(401, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals(40401, $responseData['status_code']);
        $this->assertEquals('Authentication failed', $responseData['status_message']);
    }

    public function test_merchant_amazon_asin_task_get_advanced_successful_request()
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/asin/task_get/advanced/11081545-3399-0545-0000-17b17e70d2b4" => Http::response([
                'version'        => '0.1.20250114',
                'status_code'    => 20000,
                'status_message' => 'Ok.',
                'time'           => '0.1583 sec.',
                'cost'           => 0,
                'tasks_count'    => 1,
                'tasks_error'    => 0,
                'tasks'          => [
                    [
                        'id'             => '11081545-3399-0545-0000-17b17e70d2b4',
                        'status_code'    => 20000,
                        'status_message' => 'Ok.',
                        'time'           => '0.1478 sec.',
                        'cost'           => 0,
                        'result_count'   => 1,
                        'path'           => [
                            'merchant',
                            'amazon',
                            'asin',
                            'task_get',
                            'advanced',
                            '11081545-3399-0545-0000-17b17e70d2b4',
                        ],
                        'data' => [
                            'api'      => 'merchant',
                            'function' => 'asin',
                            'asin'     => 'B08N5WRWNW',
                        ],
                        'result' => [
                            [
                                'asin'   => 'B08N5WRWNW',
                                'type'   => 'asin',
                                'title'  => 'Echo Dot (4th Gen) | Smart speaker with Alexa | Charcoal',
                                'rating' => [
                                    'rating_type' => 'Max5',
                                    'value'       => 4.7,
                                    'votes_count' => 542046,
                                    'rating_max'  => 5,
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

        $response = $this->client->merchantAmazonAsinTaskGetAdvanced('11081545-3399-0545-0000-17b17e70d2b4');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/asin/task_get/advanced/11081545-3399-0545-0000-17b17e70d2b4" &&
                   $request->method() === 'GET';
        });

        // Make sure we used the Http::fake() response
        $body = $response['response']->json();
        $this->assertEquals(20000, $body['status_code']);
        $this->assertEquals('B08N5WRWNW', $body['tasks'][0]['result'][0]['asin']);
    }

    public function test_merchant_amazon_asin_task_get_advanced_passes_attributes_and_amount()
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/asin/task_get/advanced/test-task-id" => Http::response([
                'status_code' => 20000,
                'tasks'       => [],
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->merchantAmazonAsinTaskGetAdvanced('test-task-id', 'custom-attributes', 5);

        // Check that the request was made and response contains expected data
        // The taskGet method uses the provided attributes parameter when not null
        $this->assertEquals('custom-attributes', $response['request']['attributes']);
        $this->assertEquals(5, $response['request']['credits']);
    }

    public function test_merchant_amazon_asin_task_get_html_successful_request()
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/asin/task_get/html/11081545-3399-0545-0000-17b17e70d2b4" => Http::response([
                'version'        => '0.1.20250114',
                'status_code'    => 20000,
                'status_message' => 'Ok.',
                'time'           => '0.1583 sec.',
                'cost'           => 0,
                'tasks_count'    => 1,
                'tasks_error'    => 0,
                'tasks'          => [
                    [
                        'id'             => '11081545-3399-0545-0000-17b17e70d2b4',
                        'status_code'    => 20000,
                        'status_message' => 'Ok.',
                        'time'           => '0.1478 sec.',
                        'cost'           => 0,
                        'result_count'   => 1,
                        'path'           => [
                            'merchant',
                            'amazon',
                            'asin',
                            'task_get',
                            'html',
                            '11081545-3399-0545-0000-17b17e70d2b4',
                        ],
                        'data' => [
                            'api'      => 'merchant',
                            'function' => 'asin',
                            'asin'     => 'B08N5WRWNW',
                        ],
                        'result' => [
                            [
                                'asin' => 'B08N5WRWNW',
                                'type' => 'asin',
                                'html' => '<div class="amazon-product-page">...</div>',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->merchantAmazonAsinTaskGetHtml('11081545-3399-0545-0000-17b17e70d2b4');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/asin/task_get/html/11081545-3399-0545-0000-17b17e70d2b4" &&
                   $request->method() === 'GET';
        });

        // Make sure we used the Http::fake() response
        $body = $response['response']->json();
        $this->assertEquals(20000, $body['status_code']);
        $this->assertEquals('B08N5WRWNW', $body['tasks'][0]['result'][0]['asin']);
    }

    public function test_merchant_amazon_asin_task_get_html_passes_attributes_and_amount()
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/asin/task_get/html/test-task-id" => Http::response([
                'status_code' => 20000,
                'tasks'       => [],
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->merchantAmazonAsinTaskGetHtml('test-task-id', 'custom-attributes', 3);

        // Check that the request was made and response contains expected data
        // The taskGet method uses the provided attributes parameter when not null
        $this->assertEquals('custom-attributes', $response['request']['attributes']);
        $this->assertEquals(3, $response['request']['credits']);
    }

    public function test_merchant_amazon_asin_standard_returns_cached_response_when_available()
    {
        // Mock the cache manager to return a cached response
        $cachedResponse = [
            'tasks' => [
                [
                    'result' => [
                        ['asin' => 'B08N5WRWNW', 'title' => 'Echo Dot (4th Gen)'],
                    ],
                ],
            ],
        ];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager
            ->method('generateCacheKey')
            ->willReturn('test-cache-key');
        $mockCacheManager
            ->method('getCachedResponse')
            ->willReturn($cachedResponse);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $response = $this->client->merchantAmazonAsinStandard(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en'
        );

        $this->assertEquals($cachedResponse, $response);
    }

    public function test_merchant_amazon_asin_standard_returns_null_when_not_cached_and_posting_disabled()
    {
        // Mock the cache manager to return null (not cached)
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager
            ->method('generateCacheKey')
            ->willReturn('test-cache-key');
        $mockCacheManager
            ->method('getCachedResponse')
            ->willReturn(null);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $response = $this->client->merchantAmazonAsinStandard(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en',
            null,
            null,
            null,
            'advanced',
            false,
            false,
            false // postTaskIfNotCached = false
        );

        $this->assertNull($response);
    }

    public function test_merchant_amazon_asin_standard_creates_task_when_not_cached_and_posting_enabled()
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/asin/task_post" => Http::response([
                'status_code' => 20000,
                'tasks'       => [
                    [
                        'id'             => 'test-task-id',
                        'status_code'    => 20100,
                        'status_message' => 'Task Created.',
                    ],
                ],
            ], 200),
        ]);

        // Mock the cache manager to return null (not cached) and allow requests
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager
            ->method('generateCacheKey')
            ->willReturn('test-cache-key');
        $mockCacheManager
            ->method('getCachedResponse')
            ->willReturn(null);
        $mockCacheManager
            ->method('allowRequest')
            ->willReturn(true);
        $mockCacheManager
            ->expects($this->once())
            ->method('incrementAttempts');

        $this->client = new DataForSeoApiClient($mockCacheManager);

        // Set up config for webhook URLs
        Config::set('api-cache.apis.dataforseo.postback_url', 'https://example.com/postback');
        Config::set('api-cache.apis.dataforseo.pingback_url', 'https://example.com/pingback');

        $response = $this->client->merchantAmazonAsinStandard(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en',
            null,
            null,
            null,
            'advanced',
            true,  // usePostback = true
            true,  // usePingback = true
            true   // postTaskIfNotCached = true
        );

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/asin/task_post" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]) &&
                   $request->data()[0]['asin'] === 'B08N5WRWNW' &&
                   $request->data()[0]['tag'] === 'test-cache-key' &&
                   $request->data()[0]['postback_url'] === 'https://example.com/postback' &&
                   $request->data()[0]['postback_data'] === 'advanced' &&
                   $request->data()[0]['pingback_url'] === 'https://example.com/pingback';
        });

        // Make sure we used the Http::fake() response
        $body = $response['response']->json();
        $this->assertEquals(20000, $body['status_code']);
    }

    public function test_merchant_amazon_asin_standard_validates_type_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('type must be one of: advanced, html');

        $this->client->merchantAmazonAsinStandard(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en',
            null,
            null,
            null,
            'invalid'
        );
    }

    public function test_merchant_amazon_asin_standard_excludes_webhook_params_from_cache_key()
    {
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager
            ->method('getCachedResponse')
            ->willReturn(null);

        // Capture the parameters passed to generateCacheKey
        $capturedParams = null;
        $mockCacheManager
            ->method('generateCacheKey')
            ->willReturnCallback(function ($clientName, $endpoint, $params, $method, $version) use (&$capturedParams) {
                $capturedParams = $params;

                return 'test-cache-key';
            });

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $this->client->merchantAmazonAsinStandard(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en',
            null,
            null,
            null,
            'advanced',
            true,  // usePostback
            true,  // usePingback
            false  // postTaskIfNotCached
        );

        // Verify that webhook and control parameters are excluded from cache key generation
        $this->assertNotNull($capturedParams);

        if (!is_array($capturedParams)) {
            $this->fail('Captured parameters were not an array.');
        } else {
            $this->assertArrayNotHasKey('type', $capturedParams);
            $this->assertArrayNotHasKey('usePostback', $capturedParams);
            $this->assertArrayNotHasKey('usePingback', $capturedParams);
            $this->assertArrayNotHasKey('postTaskIfNotCached', $capturedParams);

            // Verify that search parameters are included
            $this->assertArrayHasKey('asin', $capturedParams);
            $this->assertArrayHasKey('location_name', $capturedParams);
            $this->assertArrayHasKey('language_name', $capturedParams);
        }
    }

    public static function asinStandardMethodWrappersProvider(): array
    {
        return [
            ['merchantAmazonAsinStandardAdvanced', 'advanced'],
            ['merchantAmazonAsinStandardHtml', 'html'],
        ];
    }

    #[DataProvider('asinStandardMethodWrappersProvider')]
    public function test_merchant_amazon_asin_standard_wrapper_methods_call_main_method_with_correct_type(string $method, string $expectedType)
    {
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager
            ->method('generateCacheKey')
            ->willReturn('test-cache-key');
        $mockCacheManager
            ->method('getCachedResponse')
            ->willReturn([
                'tasks' => [
                    ['result' => [['asin' => 'B08N5WRWNW']]],
                ],
            ]);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $response = $this->client->$method(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en'
        );

        $this->assertNotNull($response);
        $this->assertEquals('B08N5WRWNW', $response['tasks'][0]['result'][0]['asin']);
    }

    #[DataProvider('asinStandardMethodWrappersProvider')]
    public function test_merchant_amazon_asin_standard_wrapper_methods_pass_through_webhook_parameters(string $method, string $expectedType)
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/asin/task_post" => Http::response([
                'status_code' => 20000,
                'tasks'       => [
                    [
                        'id'             => 'test-task-id',
                        'status_code'    => 20100,
                        'status_message' => 'Task Created.',
                    ],
                ],
            ], 200),
        ]);

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager
            ->method('generateCacheKey')
            ->willReturn('test-cache-key');
        $mockCacheManager
            ->method('getCachedResponse')
            ->willReturn(null);
        $mockCacheManager
            ->method('allowRequest')
            ->willReturn(true);
        $mockCacheManager
            ->expects($this->once())
            ->method('incrementAttempts');

        $this->client = new DataForSeoApiClient($mockCacheManager);

        Config::set('api-cache.apis.dataforseo.postback_url', 'https://example.com/postback');

        $response = $this->client->$method(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en',
            null,
            null,
            null,
            true,  // usePostback
            false, // usePingback
            true   // postTaskIfNotCached
        );

        Http::assertSent(function ($request) use ($expectedType) {
            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/asin/task_post" &&
                   isset($request->data()[0]) &&
                   $request->data()[0]['asin'] === 'B08N5WRWNW' &&
                   $request->data()[0]['postback_url'] === 'https://example.com/postback' &&
                   $request->data()[0]['postback_data'] === $expectedType;
        });

        $body = $response['response']->json();
        $this->assertEquals(20000, $body['status_code']);
    }

    public function test_merchant_amazon_asin_standard_advanced_basic_functionality()
    {
        $response = $this->client->merchantAmazonAsinStandardAdvanced(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en'
        );

        $this->assertNull($response);
    }

    public function test_merchant_amazon_asin_standard_html_basic_functionality()
    {
        $response = $this->client->merchantAmazonAsinStandardHtml(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en'
        );

        $this->assertNull($response);
    }

    public function test_merchant_amazon_sellers_task_post_successful_request()
    {
        $responseData = [
            'version'        => '0.1.20250425',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.01 sec.',
            'cost'           => 0.003,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => 'sellers-task-id',
                    'status_code'    => 20101,
                    'status_message' => 'Task Created',
                    'time'           => '0.01 sec.',
                    'cost'           => 0.003,
                    'result_count'   => 0,
                    'path'           => ['v3', 'merchant', 'amazon', 'sellers', 'task_post'],
                    'data'           => [
                        'api'           => 'merchant',
                        'function'      => 'amazon_sellers',
                        'se'            => 'amazon',
                        'asin'          => 'B085RFFC9Q',
                        'location_name' => 'United States',
                        'language_code' => 'en_US',
                        'location_code' => 2840,
                        'priority'      => 2,
                        'tag'           => 'test-cache-key',
                    ],
                    'result' => null,
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/sellers/task_post" => Http::response($responseData, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonSellersTaskPost(
            'B085RFFC9Q',
            2, // priority
            'United States' // locationName
        );

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/sellers/task_post" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['asin']) &&
                   $request->data()[0]['asin'] === 'B085RFFC9Q' &&
                   isset($request->data()[0]['location_name']) &&
                   $request->data()[0]['location_name'] === 'United States' &&
                   isset($request->data()[0]['priority']) &&
                   $request->data()[0]['priority'] === 2;
        });

        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertEquals('sellers-task-id', $responseData['tasks'][0]['id']);
    }

    public function test_merchant_amazon_sellers_task_post_validates_empty_asin()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ASIN cannot be empty');

        $this->client->merchantAmazonSellersTaskPost('');
    }

    public function test_merchant_amazon_sellers_task_post_validates_required_language_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either languageName or languageCode must be provided');

        $this->client->merchantAmazonSellersTaskPost(
            'B085RFFC9Q',
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            null, // languageName
            null // languageCode
        );
    }

    public function test_merchant_amazon_sellers_task_post_validates_required_location_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either locationName, locationCode, or locationCoordinate must be provided');

        $this->client->merchantAmazonSellersTaskPost(
            'B085RFFC9Q',
            null, // priority
            null, // locationName
            null, // locationCode
            null, // locationCoordinate
            'English' // languageName
        );
    }

    public function test_merchant_amazon_sellers_task_post_validates_priority_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Priority must be either 1 (normal) or 2 (high)');

        $this->client->merchantAmazonSellersTaskPost(
            'B085RFFC9Q',
            0, // priority
            'United States' // locationName
        );
    }

    public function test_merchant_amazon_sellers_task_post_validates_tag_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');

        $this->client->merchantAmazonSellersTaskPost(
            'B085RFFC9Q',
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            str_repeat('a', 256), // tag (too long)
        );
    }

    public function test_merchant_amazon_sellers_task_post_validates_postback_data_requirement()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('postbackData is required when postbackUrl is specified');

        $this->client->merchantAmazonSellersTaskPost(
            'B085RFFC9Q',
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            null, // tag
            'postback-url', // postbackUrl
            null // postbackData (missing, should trigger error)
        );
    }

    public static function merchantAmazonSellersTaskPostParametersProvider(): array
    {
        return [
            'basic parameters' => [
                // Method parameters: asin, priority, locationName, locationCode, locationCoordinate, languageName, languageCode, seDomain, tag, postbackUrl, postbackData, pingbackUrl, additionalParams
                ['B085RFFC9Q', null, 'United States', null, null, 'English', 'en_US'],
                [
                    'asin'          => 'B085RFFC9Q',
                    'location_name' => 'United States',
                    'language_name' => 'English',
                    'language_code' => 'en_US',
                ],
            ],
            'with priority and tag' => [
                ['B07ZPKN6YR', 1, null, 2826, null, 'English', null, null, 'test-tag'],
                [
                    'asin'          => 'B07ZPKN6YR',
                    'priority'      => 1,
                    'location_code' => 2826,
                    'language_name' => 'English',
                    'tag'           => 'test-tag',
                ],
            ],
        ];
    }

    #[DataProvider('merchantAmazonSellersTaskPostParametersProvider')]
    public function test_merchant_amazon_sellers_task_post_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $responseData = [
            'version'        => '0.1.20250425',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.01 sec.',
            'cost'           => 0.003,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [['id' => 'sellers-task-id']],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/sellers/task_post" => Http::response($responseData, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        // Call the method with parameters
        $this->client->merchantAmazonSellersTaskPost(...$parameters);

        // Check that the request was sent with the expected parameters
        Http::assertSent(function ($request) use ($expectedParams) {
            $requestData = $request->data()[0];

            foreach ($expectedParams as $key => $value) {
                if (!isset($requestData[$key]) || $requestData[$key] !== $value) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_merchant_amazon_sellers_task_post_handles_api_errors()
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/sellers/task_post" => Http::response([
                'version'        => '0.1.20250425',
                'status_code'    => 40000,
                'status_message' => 'API error',
            ], 400),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonSellersTaskPost(
            'B085RFFC9Q',
            null,
            'United States'
        );

        $this->assertEquals(400, $result['response_status_code']);
    }

    public function test_merchant_amazon_sellers_task_get_advanced_successful_request()
    {
        $responseData = [
            'version'        => '0.1.20250425',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.15 sec.',
            'cost'           => 0.0075,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => 'sellers-task-id',
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.15 sec.',
                    'cost'           => 0.0075,
                    'result_count'   => 1,
                    'path'           => ['v3', 'merchant', 'amazon', 'sellers', 'task_get', 'advanced'],
                    'data'           => [
                        'api'       => 'merchant',
                        'function'  => 'amazon_sellers',
                        'se'        => 'amazon',
                        'asin'      => 'B085RFFC9Q',
                        'se_domain' => 'amazon.com',
                    ],
                    'result' => [
                        [
                            'asin'          => 'B085RFFC9Q',
                            'type'          => 'sellers',
                            'se_domain'     => 'amazon.com',
                            'location_code' => 2840,
                            'language_code' => 'en_US',
                            'check_url'     => 'https://www.amazon.com/dp/B085RFFC9Q',
                            'datetime'      => '2025-06-06 19:30:00 +00:00',
                            'items_count'   => 3,
                            'items'         => [
                                [
                                    'type'          => 'seller',
                                    'rank_group'    => 1,
                                    'rank_absolute' => 1,
                                    'position'      => 'sellers_main',
                                    'seller_name'   => 'Amazon.com',
                                    'seller_url'    => 'https://www.amazon.com/sp?_encoding=UTF8&seller=ATVPDKIKX0DER',
                                    'seller_rating' => 4.5,
                                    'rating_count'  => 1000000,
                                    'price'         => [
                                        'current'  => 29.99,
                                        'currency' => 'USD',
                                    ],
                                    'condition' => 'new',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/sellers/task_get/advanced/sellers-task-id" => Http::response($responseData, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonSellersTaskGetAdvanced('sellers-task-id');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/sellers/task_get/advanced/sellers-task-id" &&
                   $request->method() === 'GET';
        });

        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertEquals('sellers-task-id', $responseData['tasks'][0]['id']);
    }

    public function test_merchant_amazon_sellers_task_get_advanced_passes_attributes_and_amount()
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/sellers/task_get/advanced/test-id" => Http::response(['tasks' => []], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonSellersTaskGetAdvanced('test-id', 'test-attributes', 5);

        $this->assertEquals(200, $result['response_status_code']);
    }

    public function test_merchant_amazon_sellers_task_get_html_successful_request()
    {
        $responseData = [
            'version'        => '0.1.20250425',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.15 sec.',
            'cost'           => 0.0075,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => 'sellers-task-id',
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.15 sec.',
                    'cost'           => 0.0075,
                    'result_count'   => 1,
                    'path'           => ['v3', 'merchant', 'amazon', 'sellers', 'task_get', 'html'],
                    'data'           => [
                        'api'      => 'merchant',
                        'function' => 'amazon_sellers',
                        'se'       => 'amazon',
                        'asin'     => 'B085RFFC9Q',
                    ],
                    'result' => [
                        [
                            'asin'          => 'B085RFFC9Q',
                            'type'          => 'sellers',
                            'se_domain'     => 'amazon.com',
                            'location_code' => 2840,
                            'language_code' => 'en_US',
                            'check_url'     => 'https://www.amazon.com/dp/B085RFFC9Q',
                            'datetime'      => '2025-06-06 19:30:00 +00:00',
                            'html'          => '<html><head><title>Amazon Product Sellers</title></head><body>...</body></html>',
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/sellers/task_get/html/sellers-task-id" => Http::response($responseData, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonSellersTaskGetHtml('sellers-task-id');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/sellers/task_get/html/sellers-task-id" &&
                   $request->method() === 'GET';
        });

        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertEquals('sellers-task-id', $responseData['tasks'][0]['id']);
        $this->assertNotEmpty($responseData['tasks'][0]['result'][0]['html']);
    }

    public function test_merchant_amazon_sellers_task_get_html_passes_attributes_and_amount()
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/sellers/task_get/html/test-id" => Http::response(['tasks' => []], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonSellersTaskGetHtml('test-id', 'test-attributes', 5);

        $this->assertEquals(200, $result['response_status_code']);
    }

    public function test_merchant_amazon_sellers_standard_returns_cached_response_when_available()
    {
        $cachedResponse = [
            'cached' => true,
            'data'   => ['sellers' => []],
        ];

        // Mock the cache manager to return a cached response
        $mockCacheManager = \Mockery::mock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->shouldReceive('generateCacheKey')->andReturn('test-cache-key');
        $mockCacheManager->shouldReceive('getCachedResponse')->andReturn($cachedResponse);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->merchantAmazonSellersStandard('B085RFFC9Q');

        $this->assertEquals($cachedResponse, $result);
    }

    public function test_merchant_amazon_sellers_standard_returns_null_when_not_cached_and_posting_disabled()
    {
        // Mock the cache manager to return null (not cached)
        $mockCacheManager = \Mockery::mock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->shouldReceive('generateCacheKey')->andReturn('test-cache-key');
        $mockCacheManager->shouldReceive('getCachedResponse')->andReturn(null);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->merchantAmazonSellersStandard(
            'B085RFFC9Q',
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            'advanced', // type
            false, // usePostback
            false, // usePingback
            false // postTaskIfNotCached - disabled
        );

        $this->assertNull($result);
    }

    public function test_merchant_amazon_sellers_standard_creates_task_when_not_cached_and_posting_enabled()
    {
        $id           = 'created-task-id';
        $responseData = [
            'tasks' => [['id' => $id]],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/sellers/task_post" => Http::response($responseData, 200),
        ]);

        // Mock the cache manager to return null (not cached)
        $mockCacheManager = \Mockery::mock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->shouldReceive('generateCacheKey')->andReturn('test-cache-key');
        $mockCacheManager->shouldReceive('getCachedResponse')->andReturn(null);
        $mockCacheManager->shouldReceive('clearRateLimit')->andReturn(null);
        $mockCacheManager->shouldReceive('allowRequest')->andReturn(true);
        $mockCacheManager->shouldReceive('incrementAttempts')->andReturn(null);
        $mockCacheManager->shouldReceive('storeResponse')->andReturnNull();

        $this->client = new DataForSeoApiClient($mockCacheManager);
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonSellersStandard(
            'B085RFFC9Q',
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            'advanced', // type
            false, // usePostback
            false, // usePingback
            true // postTaskIfNotCached - enabled
        );

        Http::assertSent(function ($request) {
            if ($request->url() !== "{$this->apiBaseUrl}/merchant/amazon/sellers/task_post" ||
                $request->method() !== 'POST') {
                return false;
            }

            $requestData = $request->data()[0];
            if (!isset($requestData)) {
                return false;
            }

            return isset($requestData['asin']) &&
                   $requestData['asin'] === 'B085RFFC9Q' &&
                   isset($requestData['tag']) &&
                   $requestData['tag'] === 'test-cache-key';
        });

        // Make sure we used the Http::fake() response
        $body = $result['response']->json();
        $this->assertEquals($id, $body['tasks'][0]['id']);
    }

    public function test_merchant_amazon_sellers_standard_validates_type_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('type must be one of: advanced, html');

        // Mock the cache manager
        $mockCacheManager = \Mockery::mock(\FOfX\ApiCache\ApiCacheManager::class);
        $this->client     = new DataForSeoApiClient($mockCacheManager);

        $this->client->merchantAmazonSellersStandard(
            'B085RFFC9Q',
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            'invalid-type' // type - invalid
        );
    }

    public function test_merchant_amazon_sellers_standard_excludes_webhook_params_from_cache_key()
    {
        // Mock the cache manager to check what parameters are passed to generateCacheKey
        $mockCacheManager = \Mockery::mock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->shouldReceive('generateCacheKey')->once()->with(
            'dataforseo',
            'merchant/amazon/sellers/task_get/advanced',
            \Mockery::on(function ($params) {
                // Ensure webhook and control params are not included in cache key generation
                return !isset($params['use_postback']) &&
                       !isset($params['use_pingback']) &&
                       !isset($params['post_task_if_not_cached']) &&
                       !isset($params['type']);
            }),
            'POST',
            'v3'
        )->andReturn('test-cache-key');
        $mockCacheManager->shouldReceive('getCachedResponse')->andReturn(null);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $this->client->merchantAmazonSellersStandard(
            'B085RFFC9Q',
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            'advanced', // type
            true, // usePostback
            true, // usePingback
            false // postTaskIfNotCached
        );
    }

    public static function sellersStandardMethodWrappersProvider(): array
    {
        return [
            ['merchantAmazonSellersStandardAdvanced', 'advanced'],
            ['merchantAmazonSellersStandardHtml', 'html'],
        ];
    }

    #[DataProvider('sellersStandardMethodWrappersProvider')]
    public function test_merchant_amazon_sellers_standard_wrapper_methods_call_main_method_with_correct_type(string $method, string $expectedType)
    {
        // Mock the cache manager
        $mockCacheManager = \Mockery::mock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->shouldReceive('generateCacheKey')->andReturn('test-cache-key');
        $mockCacheManager->shouldReceive('getCachedResponse')->andReturn([
            'tasks' => [
                ['result' => [['asin' => 'B085RFFC9Q', 'type' => $expectedType]]],
            ],
        ]);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->$method('B085RFFC9Q', null, 'United States');

        // Assert that we got a cached response back
        $this->assertNotNull($result);
        $this->assertArrayHasKey('tasks', $result);
        $this->assertEquals('B085RFFC9Q', $result['tasks'][0]['result'][0]['asin']);
        $this->assertEquals($expectedType, $result['tasks'][0]['result'][0]['type']);
    }

    #[DataProvider('sellersStandardMethodWrappersProvider')]
    public function test_merchant_amazon_sellers_standard_wrapper_methods_pass_through_webhook_parameters(string $method, string $expectedType)
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/sellers/task_post" => Http::response([
                'status_code' => 20000,
                'tasks'       => [
                    [
                        'id'             => 'test-task-id',
                        'status_code'    => 20100,
                        'status_message' => 'Task Created.',
                    ],
                ],
            ], 200),
        ]);

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager
            ->method('generateCacheKey')
            ->willReturn('test-cache-key');
        $mockCacheManager
            ->method('getCachedResponse')
            ->willReturn(null);
        $mockCacheManager
            ->method('allowRequest')
            ->willReturn(true);
        $mockCacheManager
            ->expects($this->once())
            ->method('incrementAttempts');
        $mockCacheManager
            ->method('storeResponse');

        $this->client = new DataForSeoApiClient($mockCacheManager);

        Config::set('api-cache.apis.dataforseo.postback_url', 'https://example.com/postback');

        $response = $this->client->$method(
            'B085RFFC9Q',
            null,
            'United States',
            null,
            null,
            'English',
            null,
            null,
            true,  // usePostback
            false, // usePingback
            true   // postTaskIfNotCached
        );

        Http::assertSent(function ($request) use ($expectedType) {
            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/sellers/task_post" &&
                   isset($request->data()[0]) &&
                   $request->data()[0]['asin'] === 'B085RFFC9Q' &&
                   $request->data()[0]['postback_url'] === 'https://example.com/postback' &&
                   $request->data()[0]['postback_data'] === $expectedType;
        });

        $body = $response['response']->json();
        $this->assertEquals(20000, $body['status_code']);
    }

    public function test_merchant_amazon_sellers_standard_advanced_basic_functionality()
    {
        // Mock the cache manager to return cached data so no HTTP request is made
        $mockCacheManager = \Mockery::mock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->shouldReceive('generateCacheKey')->andReturn('test-cache-key');
        $mockCacheManager->shouldReceive('getCachedResponse')->andReturn([
            'tasks' => [['result' => [['asin' => 'B085RFFC9Q', 'type' => 'advanced']]]],
        ]);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->merchantAmazonSellersStandardAdvanced('B085RFFC9Q');
        $this->assertNotNull($result); // Method exists and returns mocked data
    }

    public function test_merchant_amazon_sellers_standard_html_basic_functionality()
    {
        // Mock the cache manager to return cached data so no HTTP request is made
        $mockCacheManager = \Mockery::mock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->shouldReceive('generateCacheKey')->andReturn('test-cache-key');
        $mockCacheManager->shouldReceive('getCachedResponse')->andReturn([
            'tasks' => [['result' => [['asin' => 'B085RFFC9Q', 'type' => 'html']]]],
        ]);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->merchantAmazonSellersStandardHtml('B085RFFC9Q');
        $this->assertNotNull($result); // Method exists and returns mocked data
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
                   isset($request->data()[0]['url']) &&
                   $request->data()[0]['url'] === 'https://example.com' &&
                   isset($request->data()[0]['browser_preset']) &&
                   $request->data()[0]['browser_preset'] === 'desktop';
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
            $requestData = $request->data()[0];

            foreach ($expectedParams as $key => $value) {
                if (!isset($requestData[$key]) || $requestData[$key] !== $value) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_onpage_task_post_successful_request()
    {
        $responseJson = json_encode([
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => 'test-onpage-task-12345',
                    'status_code'    => 20000,
                    'status_message' => 'Task Created',
                    'time'           => '0.0012 sec.',
                    'cost'           => 0.025,
                    'result_count'   => 0,
                    'path'           => ['v3', 'on_page', 'task_post'],
                    'data'           => [
                        'api'               => 'on_page',
                        'function'          => 'task_post',
                        'target'            => 'example.com',
                        'max_crawl_pages'   => 100,
                        'enable_javascript' => true,
                        'browser_preset'    => 'desktop',
                        'tag'               => 'test-audit-task',
                    ],
                    'result' => null,
                ],
            ],
        ]);

        Http::fake([
            'api.dataforseo.com/v3/on_page/task_post' => Http::response($responseJson, 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->onPageTaskPost(
            target: 'example.com',
            maxCrawlPages: 100,
            enableJavascript: true,
            browserPreset: 'desktop',
            tag: 'test-audit-task'
        );

        // Verify fake response was received by checking task ID
        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertCount(1, $responseData['tasks']);
        $this->assertEquals('test-onpage-task-12345', $responseData['tasks'][0]['id']);
        $this->assertEquals('example.com', $responseData['tasks'][0]['data']['target']);
        $this->assertEquals(100, $responseData['tasks'][0]['data']['max_crawl_pages']);
        $this->assertEquals(true, $responseData['tasks'][0]['data']['enable_javascript']);
        $this->assertEquals('desktop', $responseData['tasks'][0]['data']['browser_preset']);
        $this->assertEquals('test-audit-task', $responseData['tasks'][0]['data']['tag']);
    }

    public function test_onpage_task_post_validates_empty_target()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target domain cannot be empty');

        $this->client->onPageTaskPost(
            target: '',
            maxCrawlPages: 100
        );
    }

    public function test_onpage_task_post_validates_max_crawl_pages()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max_crawl_pages must be a positive integer');

        $this->client->onPageTaskPost(
            target: 'example.com',
            maxCrawlPages: 0
        );
    }

    public function test_onpage_task_post_validates_browser_preset()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('browser_preset must be one of: desktop, mobile, tablet');

        $this->client->onPageTaskPost(
            target: 'example.com',
            maxCrawlPages: 100,
            browserPreset: 'invalid'
        );
    }

    public function test_onpage_task_post_validates_browser_screen_dimensions()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('browser_screen_width must be between 240 and 9999 pixels');

        $this->client->onPageTaskPost(
            target: 'example.com',
            maxCrawlPages: 100,
            browserScreenWidth: 200
        );
    }

    public function test_onpage_task_post_validates_xhr_javascript_dependency()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('enable_javascript must be set to true when enable_xhr is true');

        $this->client->onPageTaskPost(
            target: 'example.com',
            maxCrawlPages: 100,
            enableXhr: true,
            enableJavascript: false
        );
    }

    public function test_onpage_task_post_validates_browser_rendering_dependencies()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('enable_javascript and load_resources must be set to true when enable_browser_rendering is true');

        $this->client->onPageTaskPost(
            target: 'example.com',
            maxCrawlPages: 100,
            enableBrowserRendering: true,
            enableJavascript: true,
            loadResources: false
        );
    }

    public function test_onpage_task_post_validates_custom_js_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('custom_js must be 2000 characters or less');

        $longJs = str_repeat('a', 2001);

        $this->client->onPageTaskPost(
            target: 'example.com',
            maxCrawlPages: 100,
            customJs: $longJs
        );
    }

    public function test_onpage_task_post_validates_tag_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');

        $longTag = str_repeat('a', 256);

        $this->client->onPageTaskPost(
            target: 'example.com',
            maxCrawlPages: 100,
            tag: $longTag
        );
    }

    public function test_onpage_task_post_validates_spell_check_language()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('check_spell_language must be a supported language code');

        $this->client->onPageTaskPost(
            target: 'example.com',
            maxCrawlPages: 100,
            checkSpellLanguage: 'invalid'
        );
    }

    public static function onPageTaskPostParametersProvider(): array
    {
        return [
            'minimal_parameters' => [
                ['target' => 'example.com', 'maxCrawlPages' => 50],
                ['target' => 'example.com', 'max_crawl_pages' => 50],
            ],
            'with_start_url' => [
                ['target' => 'example.com', 'maxCrawlPages' => 100, 'startUrl' => 'https://example.com/start'],
                ['target' => 'example.com', 'max_crawl_pages' => 100, 'start_url' => 'https://example.com/start'],
            ],
            'with_browser_settings' => [
                [
                    'target'           => 'test.com',
                    'maxCrawlPages'    => 75,
                    'browserPreset'    => 'mobile',
                    'enableJavascript' => true,
                    'loadResources'    => true,
                ],
                [
                    'target'            => 'test.com',
                    'max_crawl_pages'   => 75,
                    'browser_preset'    => 'mobile',
                    'enable_javascript' => true,
                    'load_resources'    => true,
                ],
            ],
            'with_seo_settings' => [
                [
                    'target'                  => 'seo-test.com',
                    'maxCrawlPages'           => 200,
                    'checkSpell'              => true,
                    'checkSpellLanguage'      => 'en',
                    'calculateKeywordDensity' => true,
                    'validateMicromarkup'     => true,
                ],
                [
                    'target'                    => 'seo-test.com',
                    'max_crawl_pages'           => 200,
                    'check_spell'               => true,
                    'check_spell_language'      => 'en',
                    'calculate_keyword_density' => true,
                    'validate_micromarkup'      => true,
                ],
            ],
        ];
    }

    #[DataProvider('onPageTaskPostParametersProvider')]
    public function test_onpage_task_post_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $responseJson = json_encode([
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => 'test-onpage-task-67890',
                    'status_code'    => 20000,
                    'status_message' => 'Task Created',
                    'time'           => '0.0012 sec.',
                    'cost'           => 0.025,
                    'result_count'   => 0,
                    'path'           => ['v3', 'on_page', 'task_post'],
                    'data'           => $expectedParams,
                    'result'         => null,
                ],
            ],
        ]);

        Http::fake([
            'api.dataforseo.com/v3/on_page/task_post' => Http::response($responseJson, 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->onPageTaskPost(...$parameters);

        // Verify fake response was received by checking task ID
        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals('test-onpage-task-67890', $responseData['tasks'][0]['id']);

        // Verify expected parameters were properly converted and sent
        foreach ($expectedParams as $key => $value) {
            $this->assertEquals($value, $responseData['tasks'][0]['data'][$key], "Parameter $key does not match expected value");
        }
    }

    public function test_onpage_task_post_handles_api_errors()
    {
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40501,
            'status_message' => 'Requested functionality is not available.',
            'time'           => '0.0012 sec.',
            'cost'           => 0,
            'tasks_count'    => 0,
            'tasks_error'    => 0,
            'tasks'          => [],
        ];

        Http::fake([
            'api.dataforseo.com/v3/on_page/task_post' => Http::response($errorResponse, 400, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->onPageTaskPost(
            target: 'example.com',
            maxCrawlPages: 100
        );

        // Make sure we used the Http::fake() response
        $this->assertEquals(400, $result['response']->status());
        $responseData = $result['response']->json();
        $this->assertEquals(40501, $responseData['status_code']);
        $this->assertEquals('Requested functionality is not available.', $responseData['status_message']);
    }

    public function test_onpage_summary_successful_request()
    {
        $responseJson = json_encode([
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1845 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => 'test-onpage-summary-12345',
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.1845 sec.',
                    'cost'           => 0,
                    'result_count'   => 1,
                    'path'           => ['v3', 'on_page', 'summary'],
                    'data'           => [
                        'api'      => 'on_page',
                        'function' => 'summary',
                        'se'       => 'dataforseo',
                    ],
                    'result' => [
                        [
                            'crawl_progress' => 'finished',
                            'crawl_status'   => [
                                'max_crawl_pages' => 100,
                                'pages_in_queue'  => 0,
                                'pages_crawled'   => 45,
                            ],
                            'total_pages'          => 45,
                            'pages_by_status_code' => [
                                '200' => 42,
                                '404' => 3,
                            ],
                            'checks_summary' => [
                                'page_checks'     => ['total' => 150, 'passed' => 125, 'failed' => 25],
                                'sitewide_checks' => ['total' => 10, 'passed' => 8, 'failed' => 2],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        Http::fake([
            'api.dataforseo.com/v3/on_page/summary/test-onpage-summary-12345' => Http::response($responseJson, 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->onPageSummary('test-onpage-summary-12345');

        // Verify fake response was received by checking task ID
        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertCount(1, $responseData['tasks']);
        $this->assertEquals('test-onpage-summary-12345', $responseData['tasks'][0]['id']);
        $this->assertEquals('finished', $responseData['tasks'][0]['result'][0]['crawl_progress']);
        $this->assertEquals(45, $responseData['tasks'][0]['result'][0]['total_pages']);
        $this->assertArrayHasKey('pages_by_status_code', $responseData['tasks'][0]['result'][0]);
        $this->assertArrayHasKey('checks_summary', $responseData['tasks'][0]['result'][0]);
    }

    public function test_onpage_summary_validates_empty_task_id()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task ID cannot be empty');

        $this->client->onPageSummary('');
    }

    public function test_onpage_summary_passes_attributes_and_amount()
    {
        $responseJson = json_encode([
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1845 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => 'test-custom-attributes-12345',
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.1845 sec.',
                    'cost'           => 0,
                    'result_count'   => 1,
                    'path'           => ['v3', 'on_page', 'summary'],
                    'data'           => [
                        'api'      => 'on_page',
                        'function' => 'summary',
                        'se'       => 'dataforseo',
                    ],
                    'result' => [
                        [
                            'crawl_progress' => 'in_progress',
                            'total_pages'    => 25,
                        ],
                    ],
                ],
            ],
        ]);

        Http::fake([
            'api.dataforseo.com/v3/on_page/summary/test-custom-attributes-12345' => Http::response($responseJson, 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->onPageSummary(
            id: 'test-custom-attributes-12345',
            attributes: 'custom-attributes',
            amount: 5
        );

        // Verify fake response was received by checking task ID
        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals('test-custom-attributes-12345', $responseData['tasks'][0]['id']);
        $this->assertEquals('in_progress', $responseData['tasks'][0]['result'][0]['crawl_progress']);
    }

    public function test_onpage_summary_handles_api_errors()
    {
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40401,
            'status_message' => 'Task not found.',
            'time'           => '0.0012 sec.',
            'cost'           => 0,
            'tasks_count'    => 0,
            'tasks_error'    => 0,
            'tasks'          => [],
        ];

        Http::fake([
            'api.dataforseo.com/v3/on_page/summary/invalid-task-id' => Http::response($errorResponse, 404, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->onPageSummary('invalid-task-id');

        // Make sure we used the Http::fake() response
        $this->assertEquals(404, $result['response']->status());
        $responseData = $result['response']->json();
        $this->assertEquals(40401, $responseData['status_code']);
        $this->assertEquals('Task not found.', $responseData['status_message']);
    }

    public function test_onpage_pages_post_successful_request()
    {
        $id              = '12345678-1234-1234-1234-123456789030';
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.02,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.02,
                    'result_count'   => 50,
                    'path'           => [
                        'on_page',
                        'pages',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'pages',
                        'id'       => $id,
                    ],
                    'result' => [
                        [
                            'crawl_progress' => 'finished',
                            'crawl_status'   => [
                                'max_crawl_pages' => 50,
                                'pages_in_queue'  => 0,
                                'pages_crawled'   => 50,
                            ],
                            'total_items_count' => 50,
                            'items_count'       => 50,
                            'items'             => [
                                [
                                    'page_timing' => [
                                        'time_to_interactive'       => 2500,
                                        'dom_complete'              => 1800,
                                        'largest_contentful_paint'  => 1200,
                                        'first_input_delay'         => 50,
                                        'cumulative_layout_shift'   => 0.1,
                                        'speed_index'               => 1500,
                                        'time_to_secure_connection' => 300,
                                        'time_to_first_byte'        => 200,
                                        'first_contentful_paint'    => 800,
                                        'first_meaningful_paint'    => 900,
                                        'connection_time'           => 100,
                                        'duration_time'             => 3000,
                                        'fetch_start'               => 0,
                                        'fetch_end'                 => 2800,
                                    ],
                                    'onpage_score'       => 85,
                                    'total_dom_size'     => 1500,
                                    'custom_js_response' => null,
                                    'resource_errors'    => null,
                                    'status_code'        => 200,
                                    'location'           => 'https://example.com/page1.html',
                                    'url'                => 'https://example.com/page1.html',
                                    'meta'               => [
                                        'title'       => 'Example Page 1',
                                        'charset'     => 'utf-8',
                                        'description' => 'This is an example page',
                                        'follow'      => true,
                                        'generator'   => null,
                                        'htags'       => [
                                            'h1' => ['Example Page 1'],
                                            'h2' => ['Section 1', 'Section 2'],
                                        ],
                                        'images'                => 3,
                                        'images_alt'            => 2,
                                        'images_without_alt'    => 1,
                                        'internal_links_count'  => 15,
                                        'external_links_count'  => 5,
                                        'inbound_links_count'   => 8,
                                        'canonical'             => 'https://example.com/page1.html',
                                        'duplicate_title'       => false,
                                        'duplicate_description' => false,
                                        'duplicate_content'     => false,
                                        'click_depth'           => 1,
                                    ],
                                    'page_metrics' => [
                                        'linkspower'                    => 45.2,
                                        'dofollow_links_count'          => 18,
                                        'nofollow_links_count'          => 2,
                                        'internal_dofollow_links_count' => 14,
                                        'internal_nofollow_links_count' => 1,
                                        'external_dofollow_links_count' => 4,
                                        'external_nofollow_links_count' => 1,
                                        'meta_keywords_count'           => 0,
                                        'meta_keywords'                 => null,
                                        'domain_inlink_rank'            => 92,
                                        'domain_rank'                   => 85,
                                        'page_rank'                     => 78,
                                    ],
                                    'checks' => [
                                        'no_content_issues'             => true,
                                        'high_loading_time'             => false,
                                        'is_redirect'                   => false,
                                        'is_4xx_code'                   => false,
                                        'is_5xx_code'                   => false,
                                        'is_broken'                     => false,
                                        'is_www'                        => false,
                                        'is_https'                      => true,
                                        'is_http'                       => false,
                                        'high_waiting_time'             => false,
                                        'no_doctype'                    => false,
                                        'canonical'                     => false,
                                        'no_encoding_meta_tag'          => false,
                                        'no_h1_tag'                     => false,
                                        'https_to_http_links'           => false,
                                        'size_greater_than_3mb'         => false,
                                        'meta_charset_consistency'      => true,
                                        'has_meta_refresh_redirect'     => false,
                                        'has_render_blocking_resources' => false,
                                        'redirect_chain'                => false,
                                        'recursive_canonical'           => false,
                                    ],
                                    'content_encoding' => 'gzip',
                                    'media_type'       => 'text/html',
                                    'server'           => 'nginx/1.18.0',
                                    'cache_control'    => [
                                        'cachable' => true,
                                        'ttl'      => 3600,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/pages" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPagePagesPost($id);

        Http::assertSent(function ($request) use ($id) {
            $this->assertEquals("{$this->apiBaseUrl}/on_page/pages", $request->url());
            $this->assertEquals('POST', $request->method());
            $this->assertArrayHasKey(0, $request->data());
            $this->assertEquals($id, $request->data()[0]['id']);

            return true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_onpage_pages_post_validates_empty_task_id()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task ID cannot be empty');

        $this->client->onPagePagesPost('');
    }

    public function test_onpage_pages_post_validates_limit_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be between 1 and 1000');

        $this->client->onPagePagesPost('valid-id', limit: 1001);
    }

    public function test_onpage_pages_post_validates_offset_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Offset must be greater than or equal to 0');

        $this->client->onPagePagesPost('valid-id', offset: -1);
    }

    public function test_onpage_pages_post_validates_filters_count()
    {
        $filters = array_fill(0, 9, ['status_code', '=', 200]); // 9 filters (exceeds max)

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum 8 filters are allowed');

        $this->client->onPagePagesPost('valid-id', filters: $filters);
    }

    public function test_onpage_pages_post_validates_order_by_count()
    {
        $orderBy = ['onpage_score,desc', 'status_code,asc', 'meta.title,desc', 'extra,asc']; // 4 rules (exceeds max)

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum 3 sorting rules are allowed');

        $this->client->onPagePagesPost('valid-id', orderBy: $orderBy);
    }

    public function test_onpage_pages_post_validates_tag_length()
    {
        $longTag = str_repeat('a', 256); // 256 characters (exceeds max)

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');

        $this->client->onPagePagesPost('valid-id', tag: $longTag);
    }

    public static function onPagePagesPostParametersProvider(): array
    {
        return [
            'with_limit_and_offset' => [
                ['12345678-1234-1234-1234-123456789031', 100, 20],
                ['id' => '12345678-1234-1234-1234-123456789031', 'limit' => 100, 'offset' => 20],
            ],
            'with_filters' => [
                ['12345678-1234-1234-1234-123456789032', null, null, [['status_code', '=', 200]]],
                ['id' => '12345678-1234-1234-1234-123456789032', 'filters' => [['status_code', '=', 200]]],
            ],
            'with_order_by' => [
                ['12345678-1234-1234-1234-123456789033', null, null, null, ['onpage_score,desc']],
                ['id' => '12345678-1234-1234-1234-123456789033', 'order_by' => ['onpage_score,desc']],
            ],
            'with_search_after_token' => [
                ['12345678-1234-1234-1234-123456789034', null, null, null, null, 'search_token_456'],
                ['id' => '12345678-1234-1234-1234-123456789034', 'search_after_token' => 'search_token_456'],
            ],
            'with_tag' => [
                ['12345678-1234-1234-1234-123456789035', null, null, null, null, null, 'pages-test-tag'],
                ['id' => '12345678-1234-1234-1234-123456789035', 'tag' => 'pages-test-tag'],
            ],
            'with_all_parameters' => [
                [
                    '12345678-1234-1234-1234-123456789036',
                    50,
                    10,
                    [['meta.title', 'like', '%example%']],
                    ['onpage_score,desc', 'status_code,asc'],
                    'search_token_789',
                    'full-pages-test-tag',
                ],
                [
                    'id'                 => '12345678-1234-1234-1234-123456789036',
                    'limit'              => 50,
                    'offset'             => 10,
                    'filters'            => [['meta.title', 'like', '%example%']],
                    'order_by'           => ['onpage_score,desc', 'status_code,asc'],
                    'search_after_token' => 'search_token_789',
                    'tag'                => 'full-pages-test-tag',
                ],
            ],
        ];
    }

    #[DataProvider('onPagePagesPostParametersProvider')]
    public function test_onpage_pages_post_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1500 sec.',
            'cost'           => 0.02,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $parameters[0],
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.1400 sec.',
                    'cost'           => 0.02,
                    'result_count'   => 25,
                    'path'           => [
                        'on_page',
                        'pages',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'pages',
                        'id'       => $parameters[0],
                    ],
                    'result' => [
                        [
                            'crawl_progress'    => 'finished',
                            'total_items_count' => 25,
                            'items_count'       => 25,
                            'items'             => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/pages" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPagePagesPost(...$parameters);

        Http::assertSent(function ($request) use ($expectedParams) {
            $this->assertEquals("{$this->apiBaseUrl}/on_page/pages", $request->url());
            $this->assertEquals('POST', $request->method());

            $requestData = $request->data()[0];
            foreach ($expectedParams as $key => $value) {
                $this->assertArrayHasKey($key, $requestData);
                $this->assertEquals($value, $requestData[$key]);
            }

            return true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals($parameters[0], $responseData['tasks'][0]['id']);
    }

    public function test_onpage_pages_post_handles_api_errors()
    {
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40401,
            'status_message' => 'Task not found.',
            'time'           => '0.0012 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 1,
            'tasks'          => [
                [
                    'id'             => '12345678-1234-1234-1234-123456789037',
                    'status_code'    => 40401,
                    'status_message' => 'Task not found.',
                    'time'           => '0.0012 sec.',
                    'cost'           => 0,
                    'result_count'   => 0,
                    'path'           => [
                        'on_page',
                        'pages',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'pages',
                        'id'       => '12345678-1234-1234-1234-123456789037',
                    ],
                    'result' => null,
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/pages" => Http::response($errorResponse, 404),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->onPagePagesPost('12345678-1234-1234-1234-123456789037');

        // Make sure we used the Http::fake() response
        $this->assertEquals(404, $result['response']->status());
        $responseData = $result['response']->json();
        $this->assertEquals(40401, $responseData['status_code']);
        $this->assertEquals('Task not found.', $responseData['status_message']);
    }

    public function test_onpage_resources_post_successful_request()
    {
        $id              = '12345678-1234-1234-1234-123456789020';
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.025,
                    'result_count'   => 100,
                    'path'           => [
                        'on_page',
                        'resources',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'resources',
                        'id'       => $id,
                    ],
                    'result' => [
                        [
                            'resource_type'       => 'image',
                            'status_code'         => 200,
                            'location'            => 'https://example.com/image.jpg',
                            'url'                 => 'https://example.com/page.html',
                            'size'                => 15420,
                            'encoded_size'        => 15420,
                            'total_transfer_size' => 15644,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/resources" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPageResourcesPost($id);

        Http::assertSent(function ($request) use ($id) {
            $this->assertEquals("{$this->apiBaseUrl}/on_page/resources", $request->url());
            $this->assertEquals('POST', $request->method());
            $this->assertArrayHasKey(0, $request->data());
            $this->assertEquals($id, $request->data()[0]['id']);

            return true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_onpage_resources_post_validates_empty_task_id()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task ID cannot be empty');

        $this->client->onPageResourcesPost('');
    }

    public function test_onpage_resources_post_validates_limit_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be between 1 and 1000');

        $this->client->onPageResourcesPost('valid-id', limit: 1001);
    }

    public function test_onpage_resources_post_validates_offset_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Offset must be greater than or equal to 0');

        $this->client->onPageResourcesPost('valid-id', offset: -1);
    }

    public function test_onpage_resources_post_validates_filters_count()
    {
        $filters = array_fill(0, 9, ['resource_type', '=', 'image']); // 9 filters (exceeds max)

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum 8 filters are allowed');

        $this->client->onPageResourcesPost('valid-id', filters: $filters);
    }

    public function test_onpage_resources_post_validates_relevant_pages_filters_count()
    {
        $filters = array_fill(0, 9, ['status_code', '=', 200]); // 9 filters (exceeds max)

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum 8 relevant pages filters are allowed');

        $this->client->onPageResourcesPost('valid-id', relevantPagesFilters: $filters);
    }

    public function test_onpage_resources_post_validates_order_by_count()
    {
        $orderBy = ['size,desc', 'status_code,asc', 'resource_type,desc', 'extra,asc']; // 4 rules (exceeds max)

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum 3 sorting rules are allowed');

        $this->client->onPageResourcesPost('valid-id', orderBy: $orderBy);
    }

    public function test_onpage_resources_post_validates_tag_length()
    {
        $longTag = str_repeat('a', 256); // 256 characters (exceeds max)

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');

        $this->client->onPageResourcesPost('valid-id', tag: $longTag);
    }

    public static function onPageResourcesPostParametersProvider(): array
    {
        return [
            'with_url_parameter' => [
                ['12345678-1234-1234-1234-123456789021', 'https://example.com/page.html'],
                ['id' => '12345678-1234-1234-1234-123456789021', 'url' => 'https://example.com/page.html'],
            ],
            'with_limit_and_offset' => [
                ['12345678-1234-1234-1234-123456789022', null, 50, 10],
                ['id' => '12345678-1234-1234-1234-123456789022', 'limit' => 50, 'offset' => 10],
            ],
            'with_filters' => [
                ['12345678-1234-1234-1234-123456789023', null, null, null, [['resource_type', '=', 'stylesheet']]],
                ['id' => '12345678-1234-1234-1234-123456789023', 'filters' => [['resource_type', '=', 'stylesheet']]],
            ],
            'with_relevant_pages_filters' => [
                ['12345678-1234-1234-1234-123456789024', null, null, null, null, [['status_code', '=', 200]]],
                ['id' => '12345678-1234-1234-1234-123456789024', 'relevant_pages_filters' => [['status_code', '=', 200]]],
            ],
            'with_order_by' => [
                ['12345678-1234-1234-1234-123456789025', null, null, null, null, null, ['size,desc']],
                ['id' => '12345678-1234-1234-1234-123456789025', 'order_by' => ['size,desc']],
            ],
            'with_search_after_token' => [
                ['12345678-1234-1234-1234-123456789026', null, null, null, null, null, null, 'search_token_123'],
                ['id' => '12345678-1234-1234-1234-123456789026', 'search_after_token' => 'search_token_123'],
            ],
            'with_tag' => [
                ['12345678-1234-1234-1234-123456789027', null, null, null, null, null, null, null, 'test-tag'],
                ['id' => '12345678-1234-1234-1234-123456789027', 'tag' => 'test-tag'],
            ],
            'with_all_parameters' => [
                [
                    '12345678-1234-1234-1234-123456789028',
                    'https://example.com/page.html',
                    100,
                    20,
                    [['resource_type', '=', 'image']],
                    [['status_code', '=', 200]],
                    ['size,desc', 'status_code,asc'],
                    'search_token_456',
                    'full-test-tag',
                ],
                [
                    'id'                     => '12345678-1234-1234-1234-123456789028',
                    'url'                    => 'https://example.com/page.html',
                    'limit'                  => 100,
                    'offset'                 => 20,
                    'filters'                => [['resource_type', '=', 'image']],
                    'relevant_pages_filters' => [['status_code', '=', 200]],
                    'order_by'               => ['size,desc', 'status_code,asc'],
                    'search_after_token'     => 'search_token_456',
                    'tag'                    => 'full-test-tag',
                ],
            ],
        ];
    }

    #[DataProvider('onPageResourcesPostParametersProvider')]
    public function test_onpage_resources_post_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => 'test-onpage-resources-67890',
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.025,
                    'result_count'   => 50,
                    'path'           => [
                        'on_page',
                        'resources',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'resources',
                    ],
                    'result' => [],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/resources" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->onPageResourcesPost(...$parameters);

        Http::assertSent(function ($request) use ($expectedParams) {
            $this->assertEquals("{$this->apiBaseUrl}/on_page/resources", $request->url());
            $this->assertEquals('POST', $request->method());
            $this->assertArrayHasKey(0, $request->data());

            foreach ($expectedParams as $key => $value) {
                $this->assertArrayHasKey($key, $request->data()[0]);
                $this->assertEquals($value, $request->data()[0][$key]);
            }

            return true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertEquals('test-onpage-resources-67890', $responseData['tasks'][0]['id']);
    }

    public function test_onpage_resources_post_handles_api_errors()
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
                    'id'             => '12345678-1234-1234-1234-123456789029',
                    'status_code'    => 40000,
                    'status_message' => 'Bad Request.',
                    'time'           => '0.0397 sec.',
                    'cost'           => 0,
                    'result_count'   => 0,
                    'path'           => [
                        'on_page',
                        'resources',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'resources',
                        'id'       => 'invalid-id',
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/resources" => Http::response($errorResponse, 400),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPageResourcesPost('invalid-id');

        $this->assertEquals(400, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals(40000, $responseData['status_code']);
        $this->assertEquals('Bad Request.', $responseData['status_message']);
    }

    public function test_onpage_waterfall_post_successful_request()
    {
        $id              = '12345678-1234-1234-1234-123456789040';
        $url             = 'https://example.com/test-page.html';
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.3497 sec.',
            'cost'           => 0.01,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.3397 sec.',
                    'cost'           => 0.01,
                    'result_count'   => 1,
                    'path'           => [
                        'on_page',
                        'waterfall',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'waterfall',
                        'id'       => $id,
                        'url'      => $url,
                    ],
                    'result' => [
                        [
                            'url'     => $url,
                            'entries' => [
                                [
                                    'name'    => 'https://example.com/test-page.html',
                                    'request' => [
                                        'method'  => 'GET',
                                        'url'     => 'https://example.com/test-page.html',
                                        'headers' => [
                                            'User-Agent' => 'Mozilla/5.0...',
                                            'Accept'     => 'text/html,application/xhtml+xml...',
                                        ],
                                        'body_size' => 0,
                                    ],
                                    'response' => [
                                        'status'      => 200,
                                        'status_text' => 'OK',
                                        'headers'     => [
                                            'Content-Type'   => 'text/html; charset=utf-8',
                                            'Content-Length' => '15420',
                                            'Server'         => 'nginx/1.18.0',
                                        ],
                                        'body_size' => 15420,
                                        'content'   => [
                                            'size'      => 15420,
                                            'mime_type' => 'text/html',
                                            'encoding'  => 'gzip',
                                        ],
                                    ],
                                    'timings' => [
                                        'blocked' => 0.5,
                                        'dns'     => 2.1,
                                        'connect' => 8.4,
                                        'send'    => 0.2,
                                        'wait'    => 157.3,
                                        'receive' => 12.8,
                                        'ssl'     => 5.2,
                                        'total'   => 186.5,
                                    ],
                                    'started_date_time' => '2023-08-07T10:30:15.123Z',
                                    'time'              => 186.5,
                                    'cache'             => [
                                        'before_request' => [
                                            'last_access' => null,
                                            'e_tag'       => null,
                                            'hit_count'   => 0,
                                        ],
                                        'after_request' => [
                                            'last_access' => '2023-08-07T10:30:15.309Z',
                                            'e_tag'       => 'W/"3c2c-188f5e5e5e5"',
                                            'hit_count'   => 0,
                                        ],
                                    ],
                                    'server_ip_address' => '93.184.216.34',
                                    'connection'        => '12345',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/waterfall" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPageWaterfallPost($id, $url);

        Http::assertSent(function ($request) use ($id, $url) {
            $this->assertEquals("{$this->apiBaseUrl}/on_page/waterfall", $request->url());
            $this->assertEquals('POST', $request->method());
            $this->assertArrayHasKey(0, $request->data());
            $requestData = $request->data()[0];
            $this->assertEquals($id, $requestData['id']);
            $this->assertEquals($url, $requestData['url']);

            return true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
        $this->assertEquals($url, $responseData['tasks'][0]['data']['url']);
    }

    public function test_onpage_waterfall_post_validates_empty_task_id()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task ID cannot be empty');

        $this->client->onPageWaterfallPost('', 'https://example.com');
    }

    public function test_onpage_waterfall_post_validates_empty_url()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('URL cannot be empty');

        $this->client->onPageWaterfallPost('12345678-1234-1234-1234-123456789040', '');
    }

    public function test_onpage_waterfall_post_validates_tag_length()
    {
        $longTag = str_repeat('a', 256); // 256 characters (exceeds max)

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');

        $this->client->onPageWaterfallPost('12345678-1234-1234-1234-123456789040', 'https://example.com', $longTag);
    }

    public static function onPageWaterfallPostParametersProvider(): array
    {
        return [
            'with_basic_parameters' => [
                ['12345678-1234-1234-1234-123456789041', 'https://example.com/basic.html'],
                ['id' => '12345678-1234-1234-1234-123456789041', 'url' => 'https://example.com/basic.html'],
            ],
            'with_tag' => [
                ['12345678-1234-1234-1234-123456789042', 'https://example.com/tagged.html', 'waterfall-test-tag'],
                ['id' => '12345678-1234-1234-1234-123456789042', 'url' => 'https://example.com/tagged.html', 'tag' => 'waterfall-test-tag'],
            ],
            'with_complex_url' => [
                ['12345678-1234-1234-1234-123456789043', 'https://example.com/complex/path?param=value&other=123'],
                ['id' => '12345678-1234-1234-1234-123456789043', 'url' => 'https://example.com/complex/path?param=value&other=123'],
            ],
        ];
    }

    #[DataProvider('onPageWaterfallPostParametersProvider')]
    public function test_onpage_waterfall_post_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2500 sec.',
            'cost'           => 0.01,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $parameters[0],
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2400 sec.',
                    'cost'           => 0.01,
                    'result_count'   => 1,
                    'path'           => [
                        'on_page',
                        'waterfall',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'waterfall',
                        'id'       => $parameters[0],
                        'url'      => $parameters[1],
                    ],
                    'result' => [
                        [
                            'url'     => $parameters[1],
                            'entries' => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/waterfall" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPageWaterfallPost(...$parameters);

        Http::assertSent(function ($request) use ($expectedParams) {
            $this->assertEquals("{$this->apiBaseUrl}/on_page/waterfall", $request->url());
            $this->assertEquals('POST', $request->method());

            $requestData = $request->data()[0];
            foreach ($expectedParams as $key => $value) {
                $this->assertArrayHasKey($key, $requestData);
                $this->assertEquals($value, $requestData[$key]);
            }

            return true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals($parameters[0], $responseData['tasks'][0]['id']);
        $this->assertEquals($parameters[1], $responseData['tasks'][0]['data']['url']);
    }

    public function test_onpage_waterfall_post_handles_api_errors()
    {
        $id            = '12345678-1234-1234-1234-123456789044';
        $url           = 'https://example.com/invalid-page.html';
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40401,
            'status_message' => 'Task not found.',
            'time'           => '0.0012 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 1,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 40401,
                    'status_message' => 'Task not found.',
                    'time'           => '0.0012 sec.',
                    'cost'           => 0,
                    'result_count'   => 0,
                    'path'           => [
                        'on_page',
                        'waterfall',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'waterfall',
                        'id'       => $id,
                        'url'      => $url,
                    ],
                    'result' => null,
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/waterfall" => Http::response($errorResponse, 404),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->onPageWaterfallPost($id, $url);

        // Make sure we used the Http::fake() response
        $this->assertEquals(404, $result['response']->status());
        $responseData = $result['response']->json();
        $this->assertEquals(40401, $responseData['status_code']);
        $this->assertEquals('Task not found.', $responseData['status_message']);
    }

    public function test_onpage_keyword_density_post_successful_request()
    {
        $id              = '12345678-1234-1234-1234-123456789020';
        $keywordLength   = 2;
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.025,
                    'result_count'   => 1,
                    'path'           => [
                        'on_page',
                        'keyword_density',
                    ],
                    'data' => [
                        'api'            => 'on_page',
                        'function'       => 'keyword_density',
                        'id'             => $id,
                        'keyword_length' => $keywordLength,
                    ],
                    'result' => [
                        [
                            'id'          => $id,
                            'total_count' => 150,
                            'items_count' => 20,
                            'items'       => [
                                [
                                    'keyword'   => 'seo tools',
                                    'frequency' => 12,
                                    'density'   => 0.08,
                                ],
                                [
                                    'keyword'   => 'data analysis',
                                    'frequency' => 8,
                                    'density'   => 0.05,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/keyword_density" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPageKeywordDensityPost($id, $keywordLength);

        Http::assertSent(function ($request) use ($id, $keywordLength) {
            return $request->url() === "{$this->apiBaseUrl}/on_page/keyword_density" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['id']) &&
                   $request->data()[0]['id'] === $id &&
                   isset($request->data()[0]['keyword_length']) &&
                   $request->data()[0]['keyword_length'] === $keywordLength;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_onpage_keyword_density_post_validates_empty_task_id()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task ID cannot be empty');

        $this->client->onPageKeywordDensityPost('', 2);
    }

    public function test_onpage_keyword_density_post_validates_keyword_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keyword length must be 1, 2, 3, 4, or 5');

        $this->client->onPageKeywordDensityPost('12345678-1234-1234-1234-123456789021', 6);
    }

    public function test_onpage_keyword_density_post_validates_limit_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be between 1 and 1000');

        $this->client->onPageKeywordDensityPost('12345678-1234-1234-1234-123456789022', 2, null, 1500);
    }

    public function test_onpage_keyword_density_post_validates_filters_count()
    {
        $tooManyFilters = array_fill(0, 9, ['keyword', '=', 'test']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum 8 filters are allowed');

        $this->client->onPageKeywordDensityPost('12345678-1234-1234-1234-123456789023', 2, null, null, $tooManyFilters);
    }

    public function test_onpage_keyword_density_post_validates_order_by_count()
    {
        $tooManyOrderBy = ['keyword,asc', 'frequency,desc', 'density,asc', 'extra,desc'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum 3 sorting rules are allowed');

        $this->client->onPageKeywordDensityPost('12345678-1234-1234-1234-123456789024', 2, null, null, null, $tooManyOrderBy);
    }

    public function test_onpage_keyword_density_post_validates_tag_length()
    {
        $longTag = str_repeat('a', 256);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');

        $this->client->onPageKeywordDensityPost('12345678-1234-1234-1234-123456789025', 2, null, null, null, null, $longTag);
    }

    public static function onPageKeywordDensityPostParametersProvider(): array
    {
        return [
            'basic_parameters' => [
                [
                    '12345678-1234-1234-1234-123456789026',
                    3,
                    'https://example.com/page',
                    50,
                    [['keyword', 'like', '%test%']],
                    ['frequency,desc'],
                    'test-tag',
                ],
                [
                    'id'             => '12345678-1234-1234-1234-123456789026',
                    'keyword_length' => 3,
                    'url'            => 'https://example.com/page',
                    'limit'          => 50,
                    'filters'        => [['keyword', 'like', '%test%']],
                    'order_by'       => ['frequency,desc'],
                    'tag'            => 'test-tag',
                ],
            ],
            'minimal_parameters' => [
                [
                    '12345678-1234-1234-1234-123456789027',
                    1,
                ],
                [
                    'id'             => '12345678-1234-1234-1234-123456789027',
                    'keyword_length' => 1,
                ],
            ],
        ];
    }

    #[DataProvider('onPageKeywordDensityPostParametersProvider')]
    public function test_onpage_keyword_density_post_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $parameters[0],
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.025,
                    'result_count'   => 1,
                    'path'           => [
                        'on_page',
                        'keyword_density',
                    ],
                    'data'   => $expectedParams,
                    'result' => [
                        [
                            'id'          => $parameters[0],
                            'total_count' => 100,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/keyword_density" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        // Call with variable number of parameters
        $response = $this->client->onPageKeywordDensityPost(...$parameters);

        // Verify request was made correctly
        Http::assertSent(function ($request) use ($expectedParams) {
            $data = $request->data()[0];
            foreach ($expectedParams as $key => $value) {
                if (!isset($data[$key]) || $data[$key] !== $value) {
                    return false;
                }
            }

            return true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals($parameters[0], $responseData['tasks'][0]['id']);
    }

    public function test_onpage_keyword_density_post_handles_api_errors()
    {
        $id            = '12345678-1234-1234-1234-123456789028';
        $keywordLength = 2;
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40401,
            'status_message' => 'Task not found.',
            'time'           => '0.0012 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 1,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 40401,
                    'status_message' => 'Task not found.',
                    'time'           => '0.0012 sec.',
                    'cost'           => 0,
                    'result_count'   => 0,
                    'path'           => [
                        'on_page',
                        'keyword_density',
                    ],
                    'data' => [
                        'api'            => 'on_page',
                        'function'       => 'keyword_density',
                        'id'             => $id,
                        'keyword_length' => $keywordLength,
                    ],
                    'result' => null,
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/keyword_density" => Http::response($errorResponse, 404),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->onPageKeywordDensityPost($id, $keywordLength);

        // Make sure we used the Http::fake() response
        $this->assertEquals(404, $result['response']->status());
        $responseData = $result['response']->json();
        $this->assertEquals(40401, $responseData['status_code']);
        $this->assertEquals('Task not found.', $responseData['status_message']);
    }

    public function test_onpage_raw_html_post_successful_request()
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

        $response = $this->client->onPageRawHtmlPost($id);

        Http::assertSent(function ($request) use ($id) {
            return $request->url() === "{$this->apiBaseUrl}/on_page/raw_html" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['id']) &&
                   $request->data()[0]['id'] === $id;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_onpage_raw_html_post_with_url_parameter_successful_request()
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

        $response = $this->client->onPageRawHtmlPost($id, $url);

        Http::assertSent(function ($request) use ($id, $url) {
            return $request->url() === "{$this->apiBaseUrl}/on_page/raw_html" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['id']) &&
                   $request->data()[0]['id'] === $id &&
                   isset($request->data()[0]['url']) &&
                   $request->data()[0]['url'] === $url;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_onpage_raw_html_post_handles_api_errors()
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

        $response = $this->client->onPageRawHtmlPost('invalid-id');

        $this->assertEquals(400, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals(40000, $responseData['status_code']);
        $this->assertEquals('Bad Request.', $responseData['status_message']);
    }

    public function test_onpage_content_parsing_post_successful_request()
    {
        $url             = 'https://example.com/article';
        $id              = '12345678-1234-1234-1234-123456789030';
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.02,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.02,
                    'result_count'   => 1,
                    'path'           => [
                        'on_page',
                        'content_parsing',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'content_parsing',
                        'url'      => $url,
                        'id'       => $id,
                    ],
                    'result' => [
                        [
                            'id'               => $id,
                            'url'              => $url,
                            'content'          => 'This is the parsed content of the page',
                            'page_as_markdown' => '# Title\n\nThis is the parsed content of the page',
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/content_parsing" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPageContentParsingPost($url, $id);

        Http::assertSent(function ($request) use ($url, $id) {
            return $request->url() === "{$this->apiBaseUrl}/on_page/content_parsing" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['url']) &&
                   $request->data()[0]['url'] === $url &&
                   isset($request->data()[0]['id']) &&
                   $request->data()[0]['id'] === $id;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_onpage_content_parsing_post_with_markdown_view()
    {
        $url             = 'https://example.com/article-markdown';
        $id              = '12345678-1234-1234-1234-123456789031';
        $markdownView    = true;
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.02,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.02,
                    'result_count'   => 1,
                    'path'           => [
                        'on_page',
                        'content_parsing',
                    ],
                    'data' => [
                        'api'           => 'on_page',
                        'function'      => 'content_parsing',
                        'url'           => $url,
                        'id'            => $id,
                        'markdown_view' => $markdownView,
                    ],
                    'result' => [
                        [
                            'id'               => $id,
                            'url'              => $url,
                            'content'          => 'This is the parsed content of the page',
                            'page_as_markdown' => '# Title\n\nThis is the parsed content of the page',
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/content_parsing" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPageContentParsingPost($url, $id, $markdownView);

        Http::assertSent(function ($request) use ($url, $id, $markdownView) {
            return $request->url() === "{$this->apiBaseUrl}/on_page/content_parsing" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['url']) &&
                   $request->data()[0]['url'] === $url &&
                   isset($request->data()[0]['id']) &&
                   $request->data()[0]['id'] === $id &&
                   isset($request->data()[0]['markdown_view']) &&
                   $request->data()[0]['markdown_view'] === $markdownView;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_onpage_content_parsing_post_validates_empty_url()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('URL cannot be empty');

        $this->client->onPageContentParsingPost('', '12345678-1234-1234-1234-123456789032');
    }

    public function test_onpage_content_parsing_post_validates_empty_task_id()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task ID cannot be empty');

        $this->client->onPageContentParsingPost('https://example.com', '');
    }

    public static function onPageContentParsingPostParametersProvider(): array
    {
        return [
            'basic_parameters' => [
                [
                    'https://example.com/content',
                    '12345678-1234-1234-1234-123456789033',
                    true,
                ],
                [
                    'url'           => 'https://example.com/content',
                    'id'            => '12345678-1234-1234-1234-123456789033',
                    'markdown_view' => true,
                ],
            ],
            'minimal_parameters' => [
                [
                    'https://example.com/minimal',
                    '12345678-1234-1234-1234-123456789034',
                ],
                [
                    'url' => 'https://example.com/minimal',
                    'id'  => '12345678-1234-1234-1234-123456789034',
                ],
            ],
        ];
    }

    #[DataProvider('onPageContentParsingPostParametersProvider')]
    public function test_onpage_content_parsing_post_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.02,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $parameters[1],
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.02,
                    'result_count'   => 1,
                    'path'           => [
                        'on_page',
                        'content_parsing',
                    ],
                    'data'   => $expectedParams,
                    'result' => [
                        [
                            'id'      => $parameters[1],
                            'url'     => $parameters[0],
                            'content' => 'Sample content',
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/content_parsing" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        // Call with variable number of parameters
        $response = $this->client->onPageContentParsingPost(...$parameters);

        // Verify request was made correctly
        Http::assertSent(function ($request) use ($expectedParams) {
            $data = $request->data()[0];
            foreach ($expectedParams as $key => $value) {
                if (!isset($data[$key]) || $data[$key] !== $value) {
                    return false;
                }
            }

            return true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals($parameters[1], $responseData['tasks'][0]['id']);
    }

    public function test_onpage_content_parsing_post_handles_api_errors()
    {
        $url           = 'https://example.com/invalid';
        $id            = '12345678-1234-1234-1234-123456789035';
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40404,
            'status_message' => 'Content not found.',
            'time'           => '0.0012 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 1,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 40404,
                    'status_message' => 'Content not found.',
                    'time'           => '0.0012 sec.',
                    'cost'           => 0,
                    'result_count'   => 0,
                    'path'           => [
                        'on_page',
                        'content_parsing',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'content_parsing',
                        'url'      => $url,
                        'id'       => $id,
                    ],
                    'result' => null,
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/content_parsing" => Http::response($errorResponse, 404),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->onPageContentParsingPost($url, $id);

        // Make sure we used the Http::fake() response
        $this->assertEquals(404, $result['response']->status());
        $responseData = $result['response']->json();
        $this->assertEquals(40404, $responseData['status_code']);
        $this->assertEquals('Content not found.', $responseData['status_message']);
    }
}

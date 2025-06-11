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

class DataForSeoApiClientSerpGoogleTest extends TestCase
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

    public static function serpGoogleTaskGetEndpointPathProvider(): array
    {
        return [
            'Google Organic Regular'   => ['serp/google/organic/task_get/regular'],
            'Google Organic Advanced'  => ['serp/google/organic/task_get/advanced'],
            'YouTube Organic Advanced' => ['serp/youtube/organic/task_get/advanced'],
            'Amazon Products Advanced' => ['merchant/amazon/products/task_get/advanced'],
        ];
    }

    #[DataProvider('serpGoogleTaskGetEndpointPathProvider')]
    public function test_serp_google_task_get_supports_multiple_endpoint_formats(string $endpointPath)
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
     * Data provider for wrapper methods
     *
     * @return array
     */
    public static function serpGoogleTaskGetWrapperMethodsProvider(): array
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

    #[DataProvider('serpGoogleTaskGetWrapperMethodsProvider')]
    public function test_serp_google_task_get_wrapper_methods_make_request_with_correct_parameters(
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

    #[DataProvider('serpGoogleTaskGetWrapperMethodsProvider')]
    public function test_serp_google_task_get_wrapper_methods_pass_custom_parameters(
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
}

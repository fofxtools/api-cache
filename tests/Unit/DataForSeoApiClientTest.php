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

    public function test_initializes_with_correct_configuration()
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

    public function test_makes_successful_serp_google_organic_live_regular_request()
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

    public function test_makes_successful_serp_google_organic_live_advanced_request()
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

    public function test_advanced_request_validates_required_parameters()
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

    public function test_regular_request_validates_required_parameters()
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

    public function test_advanced_request_validates_people_also_ask_click_depth()
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

    public function test_validates_depth_parameter()
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

    public function test_caches_responses()
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

    public function test_enforces_rate_limits()
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

    public function test_handles_api_errors()
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

    public function test_makes_successful_serp_google_autocomplete_live_advanced_request()
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

    public function test_autocomplete_request_validates_required_language_parameters()
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

    public function test_autocomplete_request_validates_required_location_parameters()
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

    #[DataProvider('autocompleteParametersProvider')]
    public function test_builds_request_with_correct_parameters($parameters, $expectedParams)
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

    public function test_autocomplete_handles_api_errors()
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

    public function test_autocomplete_with_additional_params()
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

    public function test_makes_successful_labs_amazon_bulk_search_volume_live_request()
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

    public function test_bulk_search_volume_validates_keywords_array()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keywords array cannot be empty');

        $this->client->labsAmazonBulkSearchVolumeLive([]);
    }

    public function test_bulk_search_volume_validates_maximum_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of keywords is 1000');

        // Create an array with 1001 keywords
        $keywords = array_fill(0, 1001, 'test keyword');
        $this->client->labsAmazonBulkSearchVolumeLive($keywords);
    }

    public function test_bulk_search_volume_validates_required_language_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either languageName or languageCode must be provided');

        $this->client->labsAmazonBulkSearchVolumeLive(['test keyword'], null, 2840, null, null);
    }

    public function test_bulk_search_volume_validates_required_location_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either locationName or locationCode must be provided');

        $this->client->labsAmazonBulkSearchVolumeLive(['test keyword'], null, null, null, 'en');
    }

    public function test_makes_successful_labs_amazon_related_keywords_live_request()
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

    public function test_related_keywords_validates_empty_keyword()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keyword cannot be empty');

        $this->client->labsAmazonRelatedKeywordsLive('');
    }

    public function test_related_keywords_validates_depth_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Depth must be between 0 and 4');

        $this->client->labsAmazonRelatedKeywordsLive('test keyword', null, 2840, null, 'en', 5);
    }

    public function test_related_keywords_validates_limit_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be between 1 and 1000');

        $this->client->labsAmazonRelatedKeywordsLive('test keyword', null, 2840, null, 'en', 2, null, null, 0);
    }

    public function test_related_keywords_validates_required_language_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either languageName or languageCode must be provided');

        $this->client->labsAmazonRelatedKeywordsLive('test keyword', null, 2840, null, null);
    }

    public function test_related_keywords_validates_required_location_parameters()
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
    public function test_related_keywords_builds_request_with_correct_parameters($parameters, $expectedParams)
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

    public function test_makes_successful_labs_amazon_ranked_keywords_live_request()
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

    public function test_ranked_keywords_validates_empty_asin()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ASIN cannot be empty');

        $this->client->labsAmazonRankedKeywordsLive('');
    }

    public function test_ranked_keywords_validates_limit_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be between 1 and 1000');

        $this->client->labsAmazonRankedKeywordsLive('B08L5TNJHG', null, 2840, null, 'en', 0);
    }

    public function test_ranked_keywords_validates_required_language_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either languageName or languageCode must be provided');

        $this->client->labsAmazonRankedKeywordsLive('B08L5TNJHG', null, 2840, null, null);
    }

    public function test_ranked_keywords_validates_required_location_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either locationName or locationCode must be provided');

        $this->client->labsAmazonRankedKeywordsLive('B08L5TNJHG', null, null, null, 'en');
    }

    public static function rankedKeywordsParametersProvider()
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

    #[DataProvider('rankedKeywordsParametersProvider')]
    public function test_ranked_keywords_builds_request_with_correct_parameters($parameters, $expectedParams)
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

    public function test_makes_successful_onpage_instant_pages_request()
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

    public function test_makes_successful_onpage_raw_html_request()
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

    public function test_onpage_raw_html_with_url_parameter()
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
}

<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\DataForSeoApiClient;
use FOfX\ApiCache\RateLimitException;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

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
}

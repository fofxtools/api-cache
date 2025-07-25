<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\DataForSeoApiClient;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;

class DataForSeoApiClientLabsGoogleTest extends TestCase
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

    public function test_labs_google_keywords_for_site_live_successful_request()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20250526',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '4.5705 sec.',
            'cost'           => 0.0103,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '4.4965 sec.',
                    'cost'           => 0.0103,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'keywords_for_site', 'live'],
                    'data'           => [
                        'api'                => 'dataforseo_labs',
                        'function'           => 'keywords_for_site',
                        'se_type'            => 'google',
                        'target'             => 'apple.com',
                        'language_code'      => 'en',
                        'location_code'      => 2840,
                        'include_serp_info'  => true,
                        'include_subdomains' => true,
                        'limit'              => 3,
                    ],
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'target'        => 'apple.com',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'total_count'   => 61789671,
                            'items_count'   => 3,
                            'offset'        => 0,
                            'items'         => [
                                [
                                    'se_type'       => 'google',
                                    'keyword'       => 'video editing app for ipad pro',
                                    'location_code' => 2840,
                                    'language_code' => 'en',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/keywords_for_site/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleKeywordsForSiteLive('apple.com');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/keywords_for_site/live" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['target']) &&
                   $request->data()[0]['target'] === 'apple.com';
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
        $this->assertEquals(1, $responseData['tasks'][0]['result_count']);
        $this->assertEquals('dataforseo_labs', $responseData['tasks'][0]['data']['api']);
        $this->assertEquals('google', $responseData['tasks'][0]['data']['se_type']);
        $this->assertEquals('apple.com', $responseData['tasks'][0]['data']['target']);
    }

    public function test_labs_google_keywords_for_site_live_validates_empty_target()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target domain cannot be empty');

        $this->client->labsGoogleKeywordsForSiteLive('');
    }

    public function test_labs_google_keywords_for_site_live_validates_required_location_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either locationName or locationCode must be provided');

        $this->client->labsGoogleKeywordsForSiteLive('apple.com', null, null);
    }

    public function test_labs_google_keywords_for_site_live_validates_limit_range()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be between 1 and 1000');

        $this->client->labsGoogleKeywordsForSiteLive('apple.com', null, 2840, null, 'en', null, null, null, 1001);
    }

    public function test_labs_google_keywords_for_site_live_validates_negative_offset()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Offset must be non-negative');

        $this->client->labsGoogleKeywordsForSiteLive('apple.com', null, 2840, null, 'en', null, null, null, null, -1);
    }

    public function test_labs_google_keywords_for_site_live_validates_maximum_filters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of filters is 8');

        $filters = array_fill(0, 9, ['keyword', '>', 'test']);
        $this->client->labsGoogleKeywordsForSiteLive('apple.com', null, 2840, null, 'en', null, null, null, null, null, null, $filters);
    }

    public function test_labs_google_keywords_for_site_live_validates_maximum_sorting_rules()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of sorting rules is 3');

        $orderBy = array_fill(0, 4, ['keyword', 'asc']);
        $this->client->labsGoogleKeywordsForSiteLive('apple.com', null, 2840, null, 'en', null, null, null, null, null, null, null, $orderBy);
    }

    public function test_labs_google_keywords_for_site_live_validates_tag_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');

        $longTag = str_repeat('a', 256);
        $this->client->labsGoogleKeywordsForSiteLive('apple.com', null, 2840, null, 'en', null, null, null, null, null, null, null, null, $longTag);
    }

    public function test_labs_google_keywords_for_site_live_validates_domain_with_https()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target domain must be specified without https:// or http://');

        $this->client->labsGoogleKeywordsForSiteLive('https://apple.com');
    }

    public function test_labs_google_keywords_for_site_live_accepts_www_domains()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20250526',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '4.5705 sec.',
            'cost'           => 0.0103,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '4.4965 sec.',
                    'cost'           => 0.0103,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'keywords_for_site', 'live'],
                    'data'           => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'keywords_for_site',
                        'se_type'       => 'google',
                        'target'        => 'www.apple.com',
                        'location_code' => 2840,
                        'language_code' => 'en',
                    ],
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'target'        => 'www.apple.com',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'total_count'   => 1000000,
                            'items_count'   => 100,
                            'offset'        => 0,
                            'items'         => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/keywords_for_site/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        // This should NOT throw an exception since www is allowed per API documentation
        $response = $this->client->labsGoogleKeywordsForSiteLive('www.apple.com');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/keywords_for_site/live" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['target']) &&
                   $request->data()[0]['target'] === 'www.apple.com';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_keywords_for_site_live_accepts_location_name_only()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20250526',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '4.5705 sec.',
            'cost'           => 0.0103,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '4.4965 sec.',
                    'cost'           => 0.0103,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'keywords_for_site', 'live'],
                    'data'           => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'keywords_for_site',
                        'se_type'       => 'google',
                        'target'        => 'example.com',
                        'location_name' => 'United States',
                        'language_code' => 'en',
                    ],
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'target'        => 'example.com',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'total_count'   => 1000000,
                            'items_count'   => 100,
                            'offset'        => 0,
                            'items'         => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/keywords_for_site/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleKeywordsForSiteLive('example.com', 'United States', null, null, 'en');

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/keywords_for_site/live" &&
                   $request->method() === 'POST' &&
                   isset($requestData['location_name']) &&
                   $requestData['location_name'] === 'United States';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_keywords_for_site_live_uses_default_attributes()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20250526',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '4.5705 sec.',
            'cost'           => 0.0103,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '4.4965 sec.',
                    'cost'           => 0.0103,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'keywords_for_site', 'live'],
                    'data'           => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'keywords_for_site',
                        'se_type'       => 'google',
                        'target'        => 'test-domain.com',
                        'location_code' => 2840,
                        'language_code' => 'en',
                    ],
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'target'        => 'test-domain.com',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'total_count'   => 500000,
                            'items_count'   => 50,
                            'offset'        => 0,
                            'items'         => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/keywords_for_site/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $target   = 'test-domain.com';
        $response = $this->client->labsGoogleKeywordsForSiteLive($target);

        // Verify that default attributes (target domain) are used
        $this->assertArrayHasKey('request', $response);
        $this->assertArrayHasKey('attributes', $response['request']);
        $this->assertEquals($target, $response['request']['attributes']);

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_related_keywords_live_successful_request()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20240801',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.0995 sec.',
            'cost'           => 0.0103,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.0326 sec.',
                    'cost'           => 0.0103,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'related_keywords', 'live'],
                    'data'           => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'related_keywords',
                        'se_type'       => 'google',
                        'keyword'       => 'phone',
                        'language_name' => 'English',
                        'location_code' => 2840,
                        'limit'         => 3,
                    ],
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'seed_keyword'  => 'phone',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'total_count'   => 9,
                            'items_count'   => 3,
                            'items'         => [
                                [
                                    'se_type'      => 'google',
                                    'keyword_data' => [
                                        'keyword'       => 'phone',
                                        'location_code' => 2840,
                                        'language_code' => 'en',
                                    ],
                                    'depth'            => 0,
                                    'related_keywords' => ['phone app', 'phone call'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/related_keywords/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleRelatedKeywordsLive('phone');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/related_keywords/live";
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'POST';
        });

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return isset($requestData['keyword']) && $requestData['keyword'] === 'phone';
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
        $this->assertEquals(1, $responseData['tasks'][0]['result_count']);
        $this->assertEquals('dataforseo_labs', $responseData['tasks'][0]['data']['api']);
        $this->assertEquals('google', $responseData['tasks'][0]['data']['se_type']);
        $this->assertEquals('phone', $responseData['tasks'][0]['data']['keyword']);
    }

    public function test_labs_google_related_keywords_live_validates_empty_keyword()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keyword cannot be empty');

        $this->client->labsGoogleRelatedKeywordsLive('');
    }

    public function test_labs_google_related_keywords_live_validates_required_location_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either locationName or locationCode must be provided');

        $this->client->labsGoogleRelatedKeywordsLive('test keyword', null, null);
    }

    public function test_labs_google_related_keywords_live_validates_required_language_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either languageName or languageCode must be provided');

        $this->client->labsGoogleRelatedKeywordsLive('test keyword', null, 2840, null, null);
    }

    public function test_labs_google_related_keywords_live_validates_depth_range()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Depth must be between 0 and 4');

        $this->client->labsGoogleRelatedKeywordsLive('test keyword', null, 2840, null, 'en', 5);
    }

    public function test_labs_google_related_keywords_live_validates_limit_range()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be between 1 and 1000');

        $this->client->labsGoogleRelatedKeywordsLive('test keyword', null, 2840, null, 'en', null, null, null, null, null, null, null, null, 1001);
    }

    public function test_labs_google_related_keywords_live_validates_negative_offset()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Offset must be non-negative');

        $this->client->labsGoogleRelatedKeywordsLive('test keyword', null, 2840, null, 'en', null, null, null, null, null, null, null, null, null, -1);
    }

    public function test_labs_google_related_keywords_live_validates_maximum_filters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of filters is 8');

        $filters = array_fill(0, 9, ['keyword', '>', 'test']);
        $this->client->labsGoogleRelatedKeywordsLive('test keyword', null, 2840, null, 'en', null, null, null, null, null, null, $filters);
    }

    public function test_labs_google_related_keywords_live_validates_maximum_sorting_rules()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of sorting rules is 3');

        $orderBy = array_fill(0, 4, ['keyword', 'asc']);
        $this->client->labsGoogleRelatedKeywordsLive('test keyword', null, 2840, null, 'en', null, null, null, null, null, null, null, $orderBy);
    }

    public function test_labs_google_related_keywords_live_validates_tag_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');

        $longTag = str_repeat('a', 256);
        $this->client->labsGoogleRelatedKeywordsLive('test keyword', null, 2840, null, 'en', null, null, null, null, null, null, null, null, null, null, $longTag);
    }

    public function test_labs_google_related_keywords_live_accepts_location_name_only()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20240801',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.0995 sec.',
            'cost'           => 0.0103,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.0326 sec.',
                    'cost'           => 0.0103,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'related_keywords', 'live'],
                    'data'           => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'related_keywords',
                        'se_type'       => 'google',
                        'keyword'       => 'marketing',
                        'location_name' => 'United States',
                        'language_code' => 'en',
                    ],
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'seed_keyword'  => 'marketing',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'total_count'   => 50,
                            'items_count'   => 10,
                            'items'         => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/related_keywords/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleRelatedKeywordsLive('marketing', 'United States', null, null, 'en');

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/related_keywords/live" &&
                   $request->method() === 'POST' &&
                   isset($requestData['location_name']) &&
                   $requestData['location_name'] === 'United States';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_related_keywords_live_accepts_language_name_only()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20240801',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.0995 sec.',
            'cost'           => 0.0103,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.0326 sec.',
                    'cost'           => 0.0103,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'related_keywords', 'live'],
                    'data'           => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'related_keywords',
                        'se_type'       => 'google',
                        'keyword'       => 'seo',
                        'location_code' => 2840,
                        'language_name' => 'English',
                    ],
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'seed_keyword'  => 'seo',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'total_count'   => 25,
                            'items_count'   => 5,
                            'items'         => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/related_keywords/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleRelatedKeywordsLive('seo', null, 2840, 'English', null);

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/related_keywords/live" &&
                   $request->method() === 'POST' &&
                   isset($requestData['language_name']) &&
                   $requestData['language_name'] === 'English';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_related_keywords_live_uses_default_attributes()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20240801',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.0995 sec.',
            'cost'           => 0.0103,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.0326 sec.',
                    'cost'           => 0.0103,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'related_keywords', 'live'],
                    'data'           => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'related_keywords',
                        'se_type'       => 'google',
                        'keyword'       => 'test-keyword',
                        'location_code' => 2840,
                        'language_code' => 'en',
                    ],
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'seed_keyword'  => 'test-keyword',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'total_count'   => 15,
                            'items_count'   => 3,
                            'items'         => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/related_keywords/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $keyword  = 'test-keyword';
        $response = $this->client->labsGoogleRelatedKeywordsLive($keyword);

        // Verify that default attributes (keyword) are used
        $this->assertArrayHasKey('request', $response);
        $this->assertArrayHasKey('attributes', $response['request']);
        $this->assertEquals($keyword, $response['request']['attributes']);

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public static function relatedKeywordsParametersProvider(): array
    {
        return [
            'with location and language codes' => [
                [
                    'keyword'      => 'digital marketing',
                    'locationCode' => 2840,
                    'languageCode' => 'en',
                    'depth'        => 2,
                    'limit'        => 50,
                ],
                [
                    'keyword'       => 'digital marketing',
                    'location_code' => 2840,
                    'language_code' => 'en',
                    'depth'         => 2,
                    'limit'         => 50,
                ],
            ],
            'with location and language names' => [
                [
                    'keyword'      => 'content marketing',
                    'locationName' => 'United Kingdom',
                    'languageName' => 'English',
                    'depth'        => 1,
                ],
                [
                    'keyword'       => 'content marketing',
                    'location_name' => 'United Kingdom',
                    'language_name' => 'English',
                    'depth'         => 1,
                ],
            ],
            'with boolean flags' => [
                [
                    'keyword'                => 'seo tools',
                    'locationCode'           => 2826,
                    'languageCode'           => 'en',
                    'includeSeedKeyword'     => true,
                    'includeSerp_info'       => true,
                    'includeClickstreamData' => false,
                    'ignoreSynonyms'         => true,
                ],
                [
                    'keyword'                  => 'seo tools',
                    'location_code'            => 2826,
                    'language_code'            => 'en',
                    'include_seed_keyword'     => true,
                    'include_serp_info'        => true,
                    'include_clickstream_data' => false,
                    'ignore_synonyms'          => true,
                ],
            ],
            'with filters and sorting' => [
                [
                    'keyword'      => 'web development',
                    'locationCode' => 2840,
                    'languageCode' => 'en',
                    'filters'      => [['keyword_data.keyword_info.search_volume', '>', 100]],
                    'orderBy'      => [['keyword_data.keyword_info.search_volume', 'desc']],
                    'offset'       => 10,
                    'tag'          => 'test-tag',
                ],
                [
                    'keyword'       => 'web development',
                    'location_code' => 2840,
                    'language_code' => 'en',
                    'filters'       => [['keyword_data.keyword_info.search_volume', '>', 100]],
                    'order_by'      => [['keyword_data.keyword_info.search_volume', 'desc']],
                    'offset'        => 10,
                    'tag'           => 'test-tag',
                ],
            ],
        ];
    }

    #[DataProvider('relatedKeywordsParametersProvider')]
    public function test_labs_google_related_keywords_live_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20240801',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.0995 sec.',
            'cost'           => 0.0103,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.0326 sec.',
                    'cost'           => 0.0103,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'related_keywords', 'live'],
                    'data'           => array_merge([
                        'api'      => 'dataforseo_labs',
                        'function' => 'related_keywords',
                        'se_type'  => 'google',
                    ], $expectedParams),
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'seed_keyword'  => $parameters['keyword'],
                            'location_code' => $expectedParams['location_code'] ?? 2840,
                            'language_code' => 'en',
                            'total_count'   => 20,
                            'items_count'   => 5,
                            'items'         => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/related_keywords/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleRelatedKeywordsLive(
            $parameters['keyword'],
            $parameters['locationName'] ?? null,
            $parameters['locationCode'] ?? null,
            $parameters['languageName'] ?? null,
            $parameters['languageCode'] ?? null,
            $parameters['depth'] ?? null,
            $parameters['includeSeedKeyword'] ?? null,
            $parameters['includeSerp_info'] ?? null,
            $parameters['includeClickstreamData'] ?? null,
            $parameters['ignoreSynonyms'] ?? null,
            $parameters['replaceWithCoreKeyword'] ?? null,
            $parameters['filters'] ?? null,
            $parameters['orderBy'] ?? null,
            $parameters['limit'] ?? null,
            $parameters['offset'] ?? null,
            $parameters['tag'] ?? null,
            $parameters['additionalParams'] ?? []
        );

        Http::assertSent(function ($request) use ($expectedParams) {
            $requestData = $request->data()[0];
            foreach ($expectedParams as $key => $value) {
                if (!isset($requestData[$key]) || $requestData[$key] !== $value) {
                    return false;
                }
            }

            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/related_keywords/live" &&
                   $request->method() === 'POST';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_keyword_suggestions_live_successful_request()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20240801',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2704 sec.',
            'cost'           => 0.0101,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2019 sec.',
                    'cost'           => 0.0101,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'keyword_suggestions', 'live'],
                    'data'           => [
                        'api'                  => 'dataforseo_labs',
                        'function'             => 'keyword_suggestions',
                        'se_type'              => 'google',
                        'keyword'              => 'phone',
                        'location_code'        => 2840,
                        'language_code'        => 'en',
                        'include_serp_info'    => true,
                        'include_seed_keyword' => true,
                        'limit'                => 3,
                    ],
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'seed_keyword'  => 'phone',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'total_count'   => 3488300,
                            'items_count'   => 3,
                            'offset'        => 0,
                            'items'         => [
                                [
                                    'se_type'       => 'google',
                                    'keyword'       => 'boost cell phone',
                                    'location_code' => 2840,
                                    'language_code' => 'en',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_suggestions/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleKeywordSuggestionsLive('phone');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_suggestions/live";
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'POST';
        });

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return isset($requestData['keyword']) && $requestData['keyword'] === 'phone';
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
        $this->assertEquals(1, $responseData['tasks'][0]['result_count']);
        $this->assertEquals('dataforseo_labs', $responseData['tasks'][0]['data']['api']);
        $this->assertEquals('google', $responseData['tasks'][0]['data']['se_type']);
        $this->assertEquals('phone', $responseData['tasks'][0]['data']['keyword']);
    }

    public function test_labs_google_keyword_suggestions_live_validates_empty_keyword()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keyword cannot be empty');

        $this->client->labsGoogleKeywordSuggestionsLive('');
    }

    public function test_labs_google_keyword_suggestions_live_validates_limit_range()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be between 1 and 1000');

        $this->client->labsGoogleKeywordSuggestionsLive('test keyword', null, null, null, null, null, null, null, null, null, null, null, 1001);
    }

    public function test_labs_google_keyword_suggestions_live_validates_negative_offset()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Offset must be non-negative');

        $this->client->labsGoogleKeywordSuggestionsLive('test keyword', null, null, null, null, null, null, null, null, null, null, null, null, -1);
    }

    public function test_labs_google_keyword_suggestions_live_validates_maximum_filters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of filters is 8');

        $filters = array_fill(0, 9, ['keyword', '>', 'test']);
        $this->client->labsGoogleKeywordSuggestionsLive('test keyword', null, null, null, null, null, null, null, null, null, $filters);
    }

    public function test_labs_google_keyword_suggestions_live_validates_maximum_sorting_rules()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of sorting rules is 3');

        $orderBy = array_fill(0, 4, ['keyword', 'asc']);
        $this->client->labsGoogleKeywordSuggestionsLive('test keyword', null, null, null, null, null, null, null, null, null, null, $orderBy);
    }

    public function test_labs_google_keyword_suggestions_live_validates_tag_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');

        $longTag = str_repeat('a', 256);
        $this->client->labsGoogleKeywordSuggestionsLive('test keyword', null, null, null, null, null, null, null, null, null, null, null, null, null, null, $longTag);
    }

    public function test_labs_google_keyword_suggestions_live_accepts_optional_location_and_language()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20240801',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2704 sec.',
            'cost'           => 0.0101,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2019 sec.',
                    'cost'           => 0.0101,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'keyword_suggestions', 'live'],
                    'data'           => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'keyword_suggestions',
                        'se_type'       => 'google',
                        'keyword'       => 'marketing',
                        'location_name' => 'United Kingdom',
                        'language_name' => 'English',
                    ],
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'seed_keyword'  => 'marketing',
                            'location_code' => 2826,
                            'language_code' => 'en',
                            'total_count'   => 1500000,
                            'items_count'   => 10,
                            'offset'        => 0,
                            'items'         => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_suggestions/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleKeywordSuggestionsLive('marketing', 'United Kingdom', null, 'English', null);

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_suggestions/live" &&
                   $request->method() === 'POST' &&
                   isset($requestData['location_name']) &&
                   $requestData['location_name'] === 'United Kingdom' &&
                   isset($requestData['language_name']) &&
                   $requestData['language_name'] === 'English';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_keyword_suggestions_live_accepts_no_location_or_language()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20240801',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2704 sec.',
            'cost'           => 0.0101,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2019 sec.',
                    'cost'           => 0.0101,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'keyword_suggestions', 'live'],
                    'data'           => [
                        'api'      => 'dataforseo_labs',
                        'function' => 'keyword_suggestions',
                        'se_type'  => 'google',
                        'keyword'  => 'global keyword',
                    ],
                    'result' => [
                        [
                            'se_type'      => 'google',
                            'seed_keyword' => 'global keyword',
                            'total_count'  => 2000000,
                            'items_count'  => 20,
                            'offset'       => 0,
                            'items'        => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_suggestions/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        // Test with no location or language parameters (all optional)
        $response = $this->client->labsGoogleKeywordSuggestionsLive('global keyword');

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_suggestions/live" &&
                   $request->method() === 'POST' &&
                   isset($requestData['keyword']) &&
                   $requestData['keyword'] === 'global keyword' &&
                   !isset($requestData['location_code']) &&
                   !isset($requestData['location_name']) &&
                   !isset($requestData['language_code']) &&
                   !isset($requestData['language_name']);
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_keyword_suggestions_live_uses_default_attributes()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20240801',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2704 sec.',
            'cost'           => 0.0101,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2019 sec.',
                    'cost'           => 0.0101,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'keyword_suggestions', 'live'],
                    'data'           => [
                        'api'      => 'dataforseo_labs',
                        'function' => 'keyword_suggestions',
                        'se_type'  => 'google',
                        'keyword'  => 'test-keyword-suggestions',
                    ],
                    'result' => [
                        [
                            'se_type'      => 'google',
                            'seed_keyword' => 'test-keyword-suggestions',
                            'total_count'  => 100000,
                            'items_count'  => 5,
                            'offset'       => 0,
                            'items'        => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_suggestions/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $keyword  = 'test-keyword-suggestions';
        $response = $this->client->labsGoogleKeywordSuggestionsLive($keyword);

        // Verify that default attributes (keyword) are used
        $this->assertArrayHasKey('request', $response);
        $this->assertArrayHasKey('attributes', $response['request']);
        $this->assertEquals($keyword, $response['request']['attributes']);

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public static function keywordSuggestionsParametersProvider(): array
    {
        return [
            'with location and language codes' => [
                [
                    'keyword'      => 'seo tools',
                    'locationCode' => 2840,
                    'languageCode' => 'en',
                    'limit'        => 50,
                    'exactMatch'   => true,
                ],
                [
                    'keyword'       => 'seo tools',
                    'location_code' => 2840,
                    'language_code' => 'en',
                    'limit'         => 50,
                    'exact_match'   => true,
                ],
            ],
            'with boolean flags and filters' => [
                [
                    'keyword'                => 'digital marketing',
                    'locationCode'           => 2826,
                    'languageCode'           => 'en',
                    'includeSeedKeyword'     => true,
                    'includeSerp_info'       => true,
                    'includeClickstreamData' => false,
                    'ignoreSynonyms'         => true,
                    'filters'                => [['keyword_info.search_volume', '>', 100]],
                ],
                [
                    'keyword'                  => 'digital marketing',
                    'location_code'            => 2826,
                    'language_code'            => 'en',
                    'include_seed_keyword'     => true,
                    'include_serp_info'        => true,
                    'include_clickstream_data' => false,
                    'ignore_synonyms'          => true,
                    'filters'                  => [['keyword_info.search_volume', '>', 100]],
                ],
            ],
            'with sorting and pagination' => [
                [
                    'keyword'      => 'web development',
                    'locationCode' => 2840,
                    'languageCode' => 'en',
                    'orderBy'      => [['keyword_info.search_volume', 'desc']],
                    'offset'       => 20,
                    'offsetToken'  => 'test-token-123',
                    'tag'          => 'test-suggestions',
                ],
                [
                    'keyword'       => 'web development',
                    'location_code' => 2840,
                    'language_code' => 'en',
                    'order_by'      => [['keyword_info.search_volume', 'desc']],
                    'offset'        => 20,
                    'offset_token'  => 'test-token-123',
                    'tag'           => 'test-suggestions',
                ],
            ],
            'with location and language names only' => [
                [
                    'keyword'      => 'content marketing',
                    'locationName' => 'Canada',
                    'languageName' => 'English',
                    'exactMatch'   => false,
                ],
                [
                    'keyword'       => 'content marketing',
                    'location_name' => 'Canada',
                    'language_name' => 'English',
                    'exact_match'   => false,
                ],
            ],
        ];
    }

    #[DataProvider('keywordSuggestionsParametersProvider')]
    public function test_labs_google_keyword_suggestions_live_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20240801',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2704 sec.',
            'cost'           => 0.0101,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2019 sec.',
                    'cost'           => 0.0101,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'keyword_suggestions', 'live'],
                    'data'           => array_merge([
                        'api'      => 'dataforseo_labs',
                        'function' => 'keyword_suggestions',
                        'se_type'  => 'google',
                    ], $expectedParams),
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'seed_keyword'  => $parameters['keyword'],
                            'location_code' => $expectedParams['location_code'] ?? null,
                            'language_code' => $expectedParams['language_code'] ?? null,
                            'total_count'   => 500000,
                            'items_count'   => 10,
                            'offset'        => $expectedParams['offset'] ?? 0,
                            'items'         => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_suggestions/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleKeywordSuggestionsLive(
            $parameters['keyword'],
            $parameters['locationName'] ?? null,
            $parameters['locationCode'] ?? null,
            $parameters['languageName'] ?? null,
            $parameters['languageCode'] ?? null,
            $parameters['includeSeedKeyword'] ?? null,
            $parameters['includeSerp_info'] ?? null,
            $parameters['includeClickstreamData'] ?? null,
            $parameters['exactMatch'] ?? null,
            $parameters['ignoreSynonyms'] ?? null,
            $parameters['filters'] ?? null,
            $parameters['orderBy'] ?? null,
            $parameters['limit'] ?? null,
            $parameters['offset'] ?? null,
            $parameters['offsetToken'] ?? null,
            $parameters['tag'] ?? null,
            $parameters['additionalParams'] ?? []
        );

        Http::assertSent(function ($request) use ($expectedParams) {
            $requestData = $request->data()[0];
            foreach ($expectedParams as $key => $value) {
                if (!isset($requestData[$key]) || $requestData[$key] !== $value) {
                    return false;
                }
            }

            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_suggestions/live" &&
                   $request->method() === 'POST';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_keyword_ideas_live_successful_request()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20240801',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.7097 sec.',
            'cost'           => 0.0103,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.6455 sec.',
                    'cost'           => 0.0103,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'keyword_ideas', 'live'],
                    'data'           => [
                        'api'               => 'dataforseo_labs',
                        'function'          => 'keyword_ideas',
                        'se_type'           => 'google',
                        'keywords'          => ['phone', 'watch'],
                        'location_code'     => 2840,
                        'language_code'     => 'en',
                        'include_serp_info' => true,
                        'limit'             => 3,
                    ],
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'seed_keywords' => ['phone', 'watch'],
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'total_count'   => 533763,
                            'items_count'   => 3,
                            'offset'        => 0,
                            'items'         => [
                                [
                                    'se_type'       => 'google',
                                    'keyword'       => 'phone',
                                    'location_code' => 2840,
                                    'language_code' => 'en',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_ideas/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleKeywordIdeasLive(['phone', 'watch'], null, 2840);

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_ideas/live";
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'POST';
        });

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return isset($requestData['keywords']) && $requestData['keywords'] === ['phone', 'watch'];
        });

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return isset($requestData['location_code']) && $requestData['location_code'] === 2840;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
        $this->assertEquals(1, $responseData['tasks'][0]['result_count']);
        $this->assertEquals('dataforseo_labs', $responseData['tasks'][0]['data']['api']);
        $this->assertEquals('google', $responseData['tasks'][0]['data']['se_type']);
        $this->assertEquals(['phone', 'watch'], $responseData['tasks'][0]['data']['keywords']);
    }

    public function test_labs_google_keyword_ideas_live_validates_empty_keywords_array()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keywords array cannot be empty');

        $this->client->labsGoogleKeywordIdeasLive([], null, 2840);
    }

    public function test_labs_google_keyword_ideas_live_validates_maximum_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of keywords is 200');

        $keywords = array_fill(0, 201, 'test keyword');
        $this->client->labsGoogleKeywordIdeasLive($keywords, null, 2840);
    }

    public function test_labs_google_keyword_ideas_live_validates_empty_individual_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Individual keywords cannot be empty');

        $this->client->labsGoogleKeywordIdeasLive(['valid keyword', ''], null, 2840);
    }

    public function test_labs_google_keyword_ideas_live_validates_location_required()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either locationName or locationCode must be provided');

        // Pass null explicitly for both location parameters to trigger validation
        $this->client->labsGoogleKeywordIdeasLive(['test keyword'], null, null);
    }

    public function test_labs_google_keyword_ideas_live_validates_limit_range()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be between 1 and 1000');

        $this->client->labsGoogleKeywordIdeasLive(['test keyword'], null, 2840, null, null, null, null, null, null, 1001);
    }

    public function test_labs_google_keyword_ideas_live_validates_negative_offset()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Offset must be non-negative');

        $this->client->labsGoogleKeywordIdeasLive(['test keyword'], null, 2840, null, null, null, null, null, null, null, -1);
    }

    public function test_labs_google_keyword_ideas_live_validates_maximum_filters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of filters is 8');

        $filters = array_fill(0, 9, ['keyword', '>', 'test']);
        $this->client->labsGoogleKeywordIdeasLive(['test keyword'], null, 2840, null, null, null, null, null, null, null, null, null, $filters);
    }

    public function test_labs_google_keyword_ideas_live_validates_maximum_sorting_rules()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of sorting rules is 3');

        $orderBy = array_fill(0, 4, ['keyword', 'asc']);
        $this->client->labsGoogleKeywordIdeasLive(['test keyword'], null, 2840, null, null, null, null, null, null, null, null, null, null, $orderBy);
    }

    public function test_labs_google_keyword_ideas_live_validates_tag_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');

        $longTag = str_repeat('a', 256);
        $this->client->labsGoogleKeywordIdeasLive(['test keyword'], null, 2840, null, null, null, null, null, null, null, null, null, null, null, $longTag);
    }

    public function test_labs_google_keyword_ideas_live_accepts_location_name()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20240801',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.7097 sec.',
            'cost'           => 0.0103,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.6455 sec.',
                    'cost'           => 0.0103,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'keyword_ideas', 'live'],
                    'data'           => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'keyword_ideas',
                        'se_type'       => 'google',
                        'keywords'      => ['marketing', 'seo'],
                        'location_name' => 'United Kingdom',
                        'language_name' => 'English',
                    ],
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'seed_keywords' => ['marketing', 'seo'],
                            'location_code' => 2826,
                            'language_code' => 'en',
                            'total_count'   => 1500000,
                            'items_count'   => 10,
                            'offset'        => 0,
                            'items'         => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_ideas/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleKeywordIdeasLive(['marketing', 'seo'], 'United Kingdom', null, 'English');

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_ideas/live" &&
                   $request->method() === 'POST' &&
                   isset($requestData['location_name']) &&
                   $requestData['location_name'] === 'United Kingdom' &&
                   isset($requestData['language_name']) &&
                   $requestData['language_name'] === 'English';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_keyword_ideas_live_uses_default_attributes()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20240801',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.7097 sec.',
            'cost'           => 0.0103,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.6455 sec.',
                    'cost'           => 0.0103,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'keyword_ideas', 'live'],
                    'data'           => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'keyword_ideas',
                        'se_type'       => 'google',
                        'keywords'      => ['test', 'keyword', 'ideas', 'bulk'],
                        'location_code' => 2840,
                    ],
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'seed_keywords' => ['test', 'keyword', 'ideas', 'bulk'],
                            'location_code' => 2840,
                            'total_count'   => 100000,
                            'items_count'   => 5,
                            'offset'        => 0,
                            'items'         => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_ideas/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $keywords = ['test', 'keyword', 'ideas', 'bulk'];
        $response = $this->client->labsGoogleKeywordIdeasLive($keywords, null, 2840);

        // Verify that attributes is all keywords concatenated
        $this->assertArrayHasKey('request', $response);
        $this->assertArrayHasKey('attributes', $response['request']);
        $this->assertEquals(implode(',', $keywords), $response['request']['attributes']);

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_keyword_ideas_live_uses_all_keywords_for_short_list()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20240801',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.7097 sec.',
            'cost'           => 0.0103,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.6455 sec.',
                    'cost'           => 0.0103,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'keyword_ideas', 'live'],
                    'data'           => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'keyword_ideas',
                        'se_type'       => 'google',
                        'keywords'      => ['seo', 'marketing'],
                        'location_code' => 2840,
                    ],
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'seed_keywords' => ['seo', 'marketing'],
                            'location_code' => 2840,
                            'total_count'   => 50000,
                            'items_count'   => 10,
                            'offset'        => 0,
                            'items'         => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_ideas/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $keywords = ['seo', 'marketing'];
        $response = $this->client->labsGoogleKeywordIdeasLive($keywords, null, 2840);

        // Verify that attributes is all keywords concatenated
        $this->assertArrayHasKey('request', $response);
        $this->assertArrayHasKey('attributes', $response['request']);
        $this->assertEquals(implode(',', $keywords), $response['request']['attributes']);

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public static function keywordIdeasParametersProvider(): array
    {
        return [
            'with location and language codes and boolean flags' => [
                [
                    'keywords'               => ['seo tools', 'marketing'],
                    'locationCode'           => 2840,
                    'languageCode'           => 'en',
                    'closelyVariants'        => true,
                    'ignoreSynonyms'         => true,
                    'includeSerp_info'       => true,
                    'includeClickstreamData' => false,
                    'limit'                  => 50,
                ],
                [
                    'keywords'                 => ['seo tools', 'marketing'],
                    'location_code'            => 2840,
                    'language_code'            => 'en',
                    'closely_variants'         => true,
                    'ignore_synonyms'          => true,
                    'include_serp_info'        => true,
                    'include_clickstream_data' => false,
                    'limit'                    => 50,
                ],
            ],
            'with filters and sorting' => [
                [
                    'keywords'     => ['digital marketing', 'content'],
                    'locationCode' => 2826,
                    'languageCode' => 'en',
                    'filters'      => [['keyword_info.search_volume', '>', 100]],
                    'orderBy'      => [['keyword_info.search_volume', 'desc']],
                    'offset'       => 20,
                    'offsetToken'  => 'test-token-123',
                    'tag'          => 'test-ideas',
                ],
                [
                    'keywords'      => ['digital marketing', 'content'],
                    'location_code' => 2826,
                    'language_code' => 'en',
                    'filters'       => [['keyword_info.search_volume', '>', 100]],
                    'order_by'      => [['keyword_info.search_volume', 'desc']],
                    'offset'        => 20,
                    'offset_token'  => 'test-token-123',
                    'tag'           => 'test-ideas',
                ],
            ],
            'with location and language names only' => [
                [
                    'keywords'        => ['web development', 'programming'],
                    'locationName'    => 'Canada',
                    'languageName'    => 'English',
                    'closelyVariants' => false,
                ],
                [
                    'keywords'         => ['web development', 'programming'],
                    'location_name'    => 'Canada',
                    'language_name'    => 'English',
                    'closely_variants' => false,
                ],
            ],
        ];
    }

    #[DataProvider('keywordIdeasParametersProvider')]
    public function test_labs_google_keyword_ideas_live_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20240801',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.7097 sec.',
            'cost'           => 0.0103,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.6455 sec.',
                    'cost'           => 0.0103,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'keyword_ideas', 'live'],
                    'data'           => array_merge([
                        'api'      => 'dataforseo_labs',
                        'function' => 'keyword_ideas',
                        'se_type'  => 'google',
                    ], $expectedParams),
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'seed_keywords' => $parameters['keywords'],
                            'location_code' => $expectedParams['location_code'] ?? null,
                            'language_code' => $expectedParams['language_code'] ?? null,
                            'total_count'   => 500000,
                            'items_count'   => 10,
                            'offset'        => $expectedParams['offset'] ?? 0,
                            'items'         => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_ideas/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleKeywordIdeasLive(
            $parameters['keywords'],
            $parameters['locationName'] ?? null,
            $parameters['locationCode'] ?? null,
            $parameters['languageName'] ?? null,
            $parameters['languageCode'] ?? null,
            $parameters['closelyVariants'] ?? null,
            $parameters['ignoreSynonyms'] ?? null,
            $parameters['includeSerp_info'] ?? null,
            $parameters['includeClickstreamData'] ?? null,
            $parameters['limit'] ?? null,
            $parameters['offset'] ?? null,
            $parameters['offsetToken'] ?? null,
            $parameters['filters'] ?? null,
            $parameters['orderBy'] ?? null,
            $parameters['tag'] ?? null,
            $parameters['additionalParams'] ?? []
        );

        Http::assertSent(function ($request) use ($expectedParams) {
            $requestData = $request->data()[0];
            foreach ($expectedParams as $key => $value) {
                if (!isset($requestData[$key]) || $requestData[$key] !== $value) {
                    return false;
                }
            }

            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_ideas/live" &&
                   $request->method() === 'POST';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_bulk_keyword_difficulty_live_successful_request()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20250526',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.4521 sec.',
            'cost'           => 0.0125,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.4321 sec.',
                    'cost'           => 0.0125,
                    'result_count'   => 5,
                    'path'           => [
                        'v3',
                        'dataforseo_labs',
                        'google',
                        'bulk_keyword_difficulty',
                        'live',
                    ],
                    'data' => [
                        'api'      => 'dataforseo_labs',
                        'function' => 'bulk_keyword_difficulty',
                        'se_type'  => 'google',
                        'keywords' => [
                            'apple iphones',
                            'samsung phones',
                            'home theater',
                            'kitchenaid mixer',
                            'dyson vacuum',
                        ],
                        'language_code' => 'en',
                        'location_code' => 2840,
                    ],
                    'result' => [
                        [
                            'keyword'    => 'apple iphones',
                            'difficulty' => 85,
                        ],
                        [
                            'keyword'    => 'samsung phones',
                            'difficulty' => 78,
                        ],
                        [
                            'keyword'    => 'home theater',
                            'difficulty' => 65,
                        ],
                        [
                            'keyword'    => 'kitchenaid mixer',
                            'difficulty' => 72,
                        ],
                        [
                            'keyword'    => 'dyson vacuum',
                            'difficulty' => 81,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/bulk_keyword_difficulty/live" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $keywords = ['apple iphones', 'samsung phones', 'home theater', 'kitchenaid mixer', 'dyson vacuum'];
        $response = $this->client->labsGoogleBulkKeywordDifficultyLive($keywords);

        Http::assertSent(function ($request) use ($keywords) {
            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/bulk_keyword_difficulty/live" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['keywords']) &&
                   $request->data()[0]['keywords'] === $keywords;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
        $this->assertEquals(5, $responseData['tasks'][0]['result_count']);
        $this->assertEquals('dataforseo_labs', $responseData['tasks'][0]['data']['api']);
        $this->assertEquals('google', $responseData['tasks'][0]['data']['se_type']);
    }

    public function test_labs_google_bulk_keyword_difficulty_live_validates_keywords_array()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keywords array cannot be empty');

        $this->client->labsGoogleBulkKeywordDifficultyLive([]);
    }

    public function test_labs_google_bulk_keyword_difficulty_live_validates_maximum_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of keywords is 1000');

        // Create an array with 1001 keywords
        $keywords = array_fill(0, 1001, 'test keyword');
        $this->client->labsGoogleBulkKeywordDifficultyLive($keywords);
    }

    public function test_labs_google_bulk_keyword_difficulty_live_validates_required_language_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either languageName or languageCode must be provided');

        $this->client->labsGoogleBulkKeywordDifficultyLive(['test keyword'], null, 2840, null, null);
    }

    public function test_labs_google_bulk_keyword_difficulty_live_validates_required_location_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either locationName or locationCode must be provided');

        $this->client->labsGoogleBulkKeywordDifficultyLive(['test keyword'], null, null, null, 'en');
    }

    public function test_labs_google_bulk_keyword_difficulty_live_accepts_language_name_only()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20250526',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.3245 sec.',
            'cost'           => 0.0025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.3145 sec.',
                    'cost'           => 0.0025,
                    'result_count'   => 1,
                    'path'           => [
                        'v3',
                        'dataforseo_labs',
                        'google',
                        'bulk_keyword_difficulty',
                        'live',
                    ],
                    'data' => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'bulk_keyword_difficulty',
                        'se_type'       => 'google',
                        'keywords'      => ['test keyword'],
                        'language_name' => 'English',
                        'location_code' => 2840,
                    ],
                    'result' => [
                        [
                            'keyword'    => 'test keyword',
                            'difficulty' => 45,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/bulk_keyword_difficulty/live" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleBulkKeywordDifficultyLive(['test keyword'], null, 2840, 'English', null);

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/bulk_keyword_difficulty/live" &&
                   $request->method() === 'POST' &&
                   isset($requestData['language_name']) &&
                   $requestData['language_name'] === 'English';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_bulk_keyword_difficulty_live_accepts_location_name_only()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20250526',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2845 sec.',
            'cost'           => 0.0025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2745 sec.',
                    'cost'           => 0.0025,
                    'result_count'   => 1,
                    'path'           => [
                        'v3',
                        'dataforseo_labs',
                        'google',
                        'bulk_keyword_difficulty',
                        'live',
                    ],
                    'data' => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'bulk_keyword_difficulty',
                        'se_type'       => 'google',
                        'keywords'      => ['test keyword'],
                        'language_code' => 'en',
                        'location_name' => 'United States',
                    ],
                    'result' => [
                        [
                            'keyword'    => 'test keyword',
                            'difficulty' => 45,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/bulk_keyword_difficulty/live" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleBulkKeywordDifficultyLive(['test keyword'], 'United States', null, null, 'en');

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/bulk_keyword_difficulty/live" &&
                   $request->method() === 'POST' &&
                   isset($requestData['location_name']) &&
                   $requestData['location_name'] === 'United States';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public static function bulkKeywordDifficultyParametersProvider(): array
    {
        return [
            'with location and language codes' => [
                [
                    'keywords'     => ['seo tools', 'keyword research'],
                    'locationCode' => 2840,
                    'languageCode' => 'en',
                    'tag'          => 'test-tag-1',
                ],
                [
                    'keywords'      => ['seo tools', 'keyword research'],
                    'location_code' => 2840,
                    'language_code' => 'en',
                    'tag'           => 'test-tag-1',
                ],
            ],
            'with location and language names' => [
                [
                    'keywords'     => ['marketing tools'],
                    'locationName' => 'United Kingdom',
                    'languageName' => 'English',
                ],
                [
                    'keywords'      => ['marketing tools'],
                    'location_name' => 'United Kingdom',
                    'language_name' => 'English',
                ],
            ],
            'with mixed location and language parameters' => [
                [
                    'keywords'     => ['analytics software', 'data visualization'],
                    'locationName' => 'Canada',
                    'languageCode' => 'en',
                    'tag'          => 'mixed-params',
                ],
                [
                    'keywords'      => ['analytics software', 'data visualization'],
                    'location_name' => 'Canada',
                    'language_code' => 'en',
                    'tag'           => 'mixed-params',
                ],
            ],
            'with additional parameters' => [
                [
                    'keywords'         => ['content management'],
                    'locationCode'     => 2826,
                    'languageCode'     => 'en',
                    'additionalParams' => [
                        'custom_param' => 'custom_value',
                    ],
                ],
                [
                    'keywords'      => ['content management'],
                    'location_code' => 2826,
                    'language_code' => 'en',
                    'custom_param'  => 'custom_value',
                ],
            ],
        ];
    }

    #[DataProvider('bulkKeywordDifficultyParametersProvider')]
    public function test_labs_google_bulk_keyword_difficulty_live_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20250526',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2145 sec.',
            'cost'           => 0.005,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2045 sec.',
                    'cost'           => 0.005,
                    'result_count'   => count($parameters['keywords']),
                    'path'           => [
                        'v3',
                        'dataforseo_labs',
                        'google',
                        'bulk_keyword_difficulty',
                        'live',
                    ],
                    'data' => array_merge([
                        'api'      => 'dataforseo_labs',
                        'function' => 'bulk_keyword_difficulty',
                        'se_type'  => 'google',
                    ], $expectedParams),
                    'result' => array_map(function ($keyword) {
                        return [
                            'keyword'    => $keyword,
                            'difficulty' => rand(20, 90),
                        ];
                    }, $parameters['keywords']),
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/bulk_keyword_difficulty/live" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleBulkKeywordDifficultyLive(
            $parameters['keywords'],
            $parameters['locationName'] ?? null,
            $parameters['locationCode'] ?? null,
            $parameters['languageName'] ?? null,
            $parameters['languageCode'] ?? null,
            $parameters['tag'] ?? null,
            $parameters['additionalParams'] ?? []
        );

        Http::assertSent(function ($request) use ($expectedParams) {
            $requestData = $request->data()[0];

            foreach ($expectedParams as $key => $value) {
                if (!isset($requestData[$key]) || $requestData[$key] !== $value) {
                    return false;
                }
            }

            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/bulk_keyword_difficulty/live" &&
                   $request->method() === 'POST';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
        $this->assertEquals(count($parameters['keywords']), $responseData['tasks'][0]['result_count']);
    }

    public function test_labs_google_bulk_keyword_difficulty_live_handles_api_error_response()
    {
        $errorResponse = [
            'version'        => '0.1.20250526',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.0520 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 1,
            'tasks'          => [
                [
                    'id'             => '06130129-3399-0392-0000-99bcbe46cfa5',
                    'status_code'    => 40501,
                    'status_message' => "Invalid Field: 'location_name'.",
                    'time'           => '0 sec.',
                    'cost'           => 0,
                    'result_count'   => 0,
                    'path'           => [
                        'v3',
                        'dataforseo_labs',
                        'google',
                        'bulk_keyword_difficulty',
                        'live',
                    ],
                    'data' => [
                        'api'      => 'dataforseo_labs',
                        'function' => 'bulk_keyword_difficulty',
                        'se_type'  => 'google',
                        'keywords' => ['test keyword'],
                    ],
                    'result' => null,
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/bulk_keyword_difficulty/live" => Http::response($errorResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleBulkKeywordDifficultyLive(['test keyword']);

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals(1, $responseData['tasks_error']);
        $this->assertEquals(40501, $responseData['tasks'][0]['status_code']);
        $this->assertStringContainsString("Invalid Field: 'location_name'", $responseData['tasks'][0]['status_message']);
    }

    public function test_labs_google_bulk_keyword_difficulty_live_uses_default_attributes()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20250526',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1845 sec.',
            'cost'           => 0.005,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.1745 sec.',
                    'cost'           => 0.005,
                    'result_count'   => 2,
                    'path'           => [
                        'v3',
                        'dataforseo_labs',
                        'google',
                        'bulk_keyword_difficulty',
                        'live',
                    ],
                    'data' => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'bulk_keyword_difficulty',
                        'se_type'       => 'google',
                        'keywords'      => ['keyword one', 'keyword two'],
                        'language_code' => 'en',
                        'location_code' => 2840,
                    ],
                    'result' => [
                        [
                            'keyword'    => 'keyword one',
                            'difficulty' => 55,
                        ],
                        [
                            'keyword'    => 'keyword two',
                            'difficulty' => 62,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/bulk_keyword_difficulty/live" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $keywords = ['keyword one', 'keyword two'];
        $response = $this->client->labsGoogleBulkKeywordDifficultyLive($keywords);

        // Verify that attributes is all keywords concatenated
        $this->assertArrayHasKey('request', $response);
        $this->assertArrayHasKey('attributes', $response['request']);
        $this->assertEquals(implode(',', $keywords), $response['request']['attributes']);

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_bulk_keyword_difficulty_live_uses_custom_attributes()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20250526',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1645 sec.',
            'cost'           => 0.0025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.1545 sec.',
                    'cost'           => 0.0025,
                    'result_count'   => 1,
                    'path'           => [
                        'v3',
                        'dataforseo_labs',
                        'google',
                        'bulk_keyword_difficulty',
                        'live',
                    ],
                    'data' => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'bulk_keyword_difficulty',
                        'se_type'       => 'google',
                        'keywords'      => ['custom keyword'],
                        'language_code' => 'en',
                        'location_code' => 2840,
                    ],
                    'result' => [
                        [
                            'keyword'    => 'custom keyword',
                            'difficulty' => 48,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/bulk_keyword_difficulty/live" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $customAttributes = 'custom-test-attributes';
        $response         = $this->client->labsGoogleBulkKeywordDifficultyLive(
            ['custom keyword'],
            null,
            2840,
            null,
            'en',
            null,
            [],
            $customAttributes
        );

        // Verify that custom attributes are used
        $this->assertArrayHasKey('request', $response);
        $this->assertArrayHasKey('attributes', $response['request']);
        $this->assertEquals($customAttributes, $response['request']['attributes']);

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_search_intent_live_successful_request()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20221214',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1285 sec.',
            'cost'           => 0.0014,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.0253 sec.',
                    'cost'           => 0.0014,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'search_intent', 'live'],
                    'data'           => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'search_intent',
                        'se_type'       => 'google',
                        'language_code' => 'en',
                        'keywords'      => ['login page', 'audi a7', 'elon musk'],
                    ],
                    'result' => [
                        [
                            'language_code' => 'en',
                            'items_count'   => 3,
                            'items'         => [
                                [
                                    'keyword'        => 'login page',
                                    'keyword_intent' => [
                                        'label'       => 'navigational',
                                        'probability' => 0.9694191,
                                    ],
                                    'secondary_keyword_intents' => null,
                                ],
                                [
                                    'keyword'        => 'audi a7',
                                    'keyword_intent' => [
                                        'label'       => 'commercial',
                                        'probability' => 1,
                                    ],
                                    'secondary_keyword_intents' => null,
                                ],
                                [
                                    'keyword'        => 'elon musk',
                                    'keyword_intent' => [
                                        'label'       => 'informational',
                                        'probability' => 0.95328856,
                                    ],
                                    'secondary_keyword_intents' => null,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/search_intent/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleSearchIntentLive(['login page', 'audi a7', 'elon musk']);

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/search_intent/live";
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'POST';
        });

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return isset($requestData['keywords']) && $requestData['keywords'] === ['login page', 'audi a7', 'elon musk'];
        });

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return isset($requestData['language_code']) && $requestData['language_code'] === 'en';
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
        $this->assertEquals(1, $responseData['tasks'][0]['result_count']);
        $this->assertEquals('dataforseo_labs', $responseData['tasks'][0]['data']['api']);
        $this->assertEquals('google', $responseData['tasks'][0]['data']['se_type']);
        $this->assertEquals(['login page', 'audi a7', 'elon musk'], $responseData['tasks'][0]['data']['keywords']);
    }

    public function test_labs_google_search_intent_live_validates_empty_keywords_array()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keywords array cannot be empty');

        $this->client->labsGoogleSearchIntentLive([]);
    }

    public function test_labs_google_search_intent_live_validates_maximum_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of keywords is 1000');

        $keywords = array_fill(0, 1001, 'test keyword');
        $this->client->labsGoogleSearchIntentLive($keywords);
    }

    public function test_labs_google_search_intent_live_validates_empty_individual_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Individual keywords cannot be empty');

        $this->client->labsGoogleSearchIntentLive(['valid keyword', '']);
    }

    public function test_labs_google_search_intent_live_validates_language_required()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either languageName or languageCode must be provided');

        // Pass null explicitly for both language parameters to trigger validation
        $this->client->labsGoogleSearchIntentLive(['test keyword'], null, null);
    }

    public function test_labs_google_search_intent_live_validates_tag_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');

        $longTag = str_repeat('a', 256);
        $this->client->labsGoogleSearchIntentLive(['test keyword'], null, 'en', $longTag);
    }

    public function test_labs_google_search_intent_live_accepts_language_name()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20221214',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1285 sec.',
            'cost'           => 0.0014,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.0253 sec.',
                    'cost'           => 0.0014,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'search_intent', 'live'],
                    'data'           => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'search_intent',
                        'se_type'       => 'google',
                        'language_name' => 'English',
                        'keywords'      => ['marketing strategy', 'seo tools'],
                    ],
                    'result' => [
                        [
                            'language_code' => 'en',
                            'items_count'   => 2,
                            'items'         => [
                                [
                                    'keyword'        => 'marketing strategy',
                                    'keyword_intent' => [
                                        'label'       => 'informational',
                                        'probability' => 0.8234567,
                                    ],
                                    'secondary_keyword_intents' => null,
                                ],
                                [
                                    'keyword'        => 'seo tools',
                                    'keyword_intent' => [
                                        'label'       => 'commercial',
                                        'probability' => 0.9123456,
                                    ],
                                    'secondary_keyword_intents' => null,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/search_intent/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleSearchIntentLive(['marketing strategy', 'seo tools'], 'English');

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/search_intent/live" &&
                   $request->method() === 'POST' &&
                   isset($requestData['language_name']) &&
                   $requestData['language_name'] === 'English';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_search_intent_live_uses_default_attributes()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20221214',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1285 sec.',
            'cost'           => 0.0014,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.0253 sec.',
                    'cost'           => 0.0014,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'search_intent', 'live'],
                    'data'           => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'search_intent',
                        'se_type'       => 'google',
                        'language_code' => 'en',
                        'keywords'      => ['test', 'keyword', 'intent', 'analysis'],
                    ],
                    'result' => [
                        [
                            'language_code' => 'en',
                            'items_count'   => 4,
                            'items'         => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/search_intent/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $keywords = ['test', 'keyword', 'intent', 'analysis'];
        $response = $this->client->labsGoogleSearchIntentLive($keywords);

        // Verify that attributes is all keywords concatenated
        $this->assertArrayHasKey('request', $response);
        $this->assertArrayHasKey('attributes', $response['request']);
        $this->assertEquals(implode(',', $keywords), $response['request']['attributes']);

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_search_intent_live_uses_all_keywords_for_short_list()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20221214',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1285 sec.',
            'cost'           => 0.0014,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.0253 sec.',
                    'cost'           => 0.0014,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'search_intent', 'live'],
                    'data'           => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'search_intent',
                        'se_type'       => 'google',
                        'language_code' => 'en',
                        'keywords'      => ['buy shoes', 'nike store'],
                    ],
                    'result' => [
                        [
                            'language_code' => 'en',
                            'items_count'   => 2,
                            'items'         => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/search_intent/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $keywords = ['buy shoes', 'nike store'];
        $response = $this->client->labsGoogleSearchIntentLive($keywords);

        // Verify that attributes is all keywords concatenated
        $this->assertArrayHasKey('request', $response);
        $this->assertArrayHasKey('attributes', $response['request']);
        $this->assertEquals(implode(',', $keywords), $response['request']['attributes']);

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public static function searchIntentParametersProvider(): array
    {
        return [
            'with language code and tag' => [
                [
                    'keywords'     => ['buy laptop', 'best laptop 2024'],
                    'languageCode' => 'en',
                    'tag'          => 'intent-analysis-test',
                ],
                [
                    'keywords'      => ['buy laptop', 'best laptop 2024'],
                    'language_code' => 'en',
                    'tag'           => 'intent-analysis-test',
                ],
            ],
            'with language name only' => [
                [
                    'keywords'     => ['marketing digital', 'estrategia seo'],
                    'languageName' => 'Spanish',
                ],
                [
                    'keywords'      => ['marketing digital', 'estrategia seo'],
                    'language_name' => 'Spanish',
                ],
            ],
            'with multiple intent keywords' => [
                [
                    'keywords'     => ['how to cook pasta', 'amazon login', 'buy iphone', 'apple website'],
                    'languageCode' => 'en',
                    'tag'          => 'multi-intent-test',
                ],
                [
                    'keywords'      => ['how to cook pasta', 'amazon login', 'buy iphone', 'apple website'],
                    'language_code' => 'en',
                    'tag'           => 'multi-intent-test',
                ],
            ],
        ];
    }

    #[DataProvider('searchIntentParametersProvider')]
    public function test_labs_google_search_intent_live_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20221214',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1285 sec.',
            'cost'           => 0.0014,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.0253 sec.',
                    'cost'           => 0.0014,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'search_intent', 'live'],
                    'data'           => array_merge([
                        'api'      => 'dataforseo_labs',
                        'function' => 'search_intent',
                        'se_type'  => 'google',
                    ], $expectedParams),
                    'result' => [
                        [
                            'language_code' => $expectedParams['language_code'] ?? 'en',
                            'items_count'   => count($parameters['keywords']),
                            'items'         => array_map(function ($keyword) {
                                return [
                                    'keyword'        => $keyword,
                                    'keyword_intent' => [
                                        'label'       => 'informational',
                                        'probability' => 0.8,
                                    ],
                                    'secondary_keyword_intents' => null,
                                ];
                            }, $parameters['keywords']),
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/search_intent/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleSearchIntentLive(
            $parameters['keywords'],
            $parameters['languageName'] ?? null,
            $parameters['languageCode'] ?? null,
            $parameters['tag'] ?? null,
            $parameters['additionalParams'] ?? []
        );

        Http::assertSent(function ($request) use ($expectedParams) {
            $requestData = $request->data()[0];
            foreach ($expectedParams as $key => $value) {
                if (!isset($requestData[$key]) || $requestData[$key] !== $value) {
                    return false;
                }
            }

            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/search_intent/live" &&
                   $request->method() === 'POST';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_keyword_overview_live_successful_request()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20241227',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1845 sec.',
            'cost'           => 0.0201,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.1052 sec.',
                    'cost'           => 0.0201,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'keyword_overview', 'live'],
                    'data'           => [
                        'api'                      => 'dataforseo_labs',
                        'function'                 => 'keyword_overview',
                        'se_type'                  => 'google',
                        'language_code'            => 'en',
                        'location_code'            => 2840,
                        'include_clickstream_data' => true,
                        'include_serp_info'        => true,
                        'keywords'                 => ['iphone'],
                    ],
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'items_count'   => 1,
                            'items'         => [
                                [
                                    'se_type'         => 'google',
                                    'keyword'         => 'iphone',
                                    'location_code'   => 2840,
                                    'language_code'   => 'en',
                                    'search_partners' => false,
                                    'keyword_info'    => [
                                        'se_type'              => 'google',
                                        'last_updated_time'    => '2025-03-06 03:08:24 +00:00',
                                        'competition'          => 1,
                                        'competition_level'    => 'HIGH',
                                        'cpc'                  => 6.45,
                                        'search_volume'        => 1220000,
                                        'low_top_of_page_bid'  => 2.6,
                                        'high_top_of_page_bid' => 5.14,
                                    ],
                                    'keyword_properties' => [
                                        'se_type'                      => 'google',
                                        'core_keyword'                 => null,
                                        'synonym_clustering_algorithm' => 'text_processing',
                                        'keyword_difficulty'           => 89,
                                        'detected_language'            => 'en',
                                        'is_another_language'          => false,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_overview/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleKeywordOverviewLive(['iphone']);

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_overview/live";
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'POST';
        });

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return isset($requestData['keywords']) && $requestData['keywords'] === ['iphone'];
        });

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return isset($requestData['location_code']) && $requestData['location_code'] === 2840;
        });

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return isset($requestData['language_code']) && $requestData['language_code'] === 'en';
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
        $this->assertEquals(1, $responseData['tasks'][0]['result_count']);
        $this->assertEquals('dataforseo_labs', $responseData['tasks'][0]['data']['api']);
        $this->assertEquals('google', $responseData['tasks'][0]['data']['se_type']);
        $this->assertEquals(['iphone'], $responseData['tasks'][0]['data']['keywords']);
    }

    public function test_labs_google_keyword_overview_live_validates_empty_keywords_array()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keywords array cannot be empty');

        $this->client->labsGoogleKeywordOverviewLive([]);
    }

    public function test_labs_google_keyword_overview_live_validates_maximum_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of keywords is 700');

        $keywords = array_fill(0, 701, 'test keyword');
        $this->client->labsGoogleKeywordOverviewLive($keywords);
    }

    public function test_labs_google_keyword_overview_live_validates_empty_individual_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Individual keywords cannot be empty');

        $this->client->labsGoogleKeywordOverviewLive(['valid keyword', '']);
    }

    public function test_labs_google_keyword_overview_live_validates_location_required()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either locationName or locationCode must be provided');

        // Pass null explicitly for both location parameters to trigger validation
        $this->client->labsGoogleKeywordOverviewLive(['test keyword'], null, null);
    }

    public function test_labs_google_keyword_overview_live_validates_language_required()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either languageName or languageCode must be provided');

        // Pass null explicitly for both language parameters to trigger validation
        $this->client->labsGoogleKeywordOverviewLive(['test keyword'], null, 2840, null, null);
    }

    public function test_labs_google_keyword_overview_live_validates_tag_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');

        $longTag = str_repeat('a', 256);
        $this->client->labsGoogleKeywordOverviewLive(['test keyword'], null, 2840, null, 'en', null, null, $longTag);
    }

    public function test_labs_google_keyword_overview_live_accepts_location_and_language_names()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20241227',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1845 sec.',
            'cost'           => 0.0201,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.1052 sec.',
                    'cost'           => 0.0201,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'keyword_overview', 'live'],
                    'data'           => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'keyword_overview',
                        'se_type'       => 'google',
                        'location_name' => 'United Kingdom',
                        'language_name' => 'English',
                        'keywords'      => ['marketing strategy', 'seo tools'],
                    ],
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'location_code' => 2826,
                            'language_code' => 'en',
                            'items_count'   => 2,
                            'items'         => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_overview/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleKeywordOverviewLive(['marketing strategy', 'seo tools'], 'United Kingdom', null, 'English');

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_overview/live" &&
                   $request->method() === 'POST' &&
                   isset($requestData['location_name']) &&
                   $requestData['location_name'] === 'United Kingdom' &&
                   isset($requestData['language_name']) &&
                   $requestData['language_name'] === 'English';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_keyword_overview_live_with_optional_parameters()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20241227',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1845 sec.',
            'cost'           => 0.0201,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.1052 sec.',
                    'cost'           => 0.0201,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'keyword_overview', 'live'],
                    'data'           => [
                        'api'                      => 'dataforseo_labs',
                        'function'                 => 'keyword_overview',
                        'se_type'                  => 'google',
                        'location_code'            => 2840,
                        'language_code'            => 'en',
                        'include_serp_info'        => true,
                        'include_clickstream_data' => true,
                        'tag'                      => 'overview-test',
                        'keywords'                 => ['digital marketing'],
                    ],
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'items_count'   => 1,
                            'items'         => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_overview/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleKeywordOverviewLive(
            ['digital marketing'],
            null,
            2840,
            null,
            'en',
            true,
            true,
            'overview-test'
        );

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_overview/live" &&
                   $request->method() === 'POST' &&
                   isset($requestData['include_serp_info']) &&
                   $requestData['include_serp_info'] === true &&
                   isset($requestData['include_clickstream_data']) &&
                   $requestData['include_clickstream_data'] === true &&
                   isset($requestData['tag']) &&
                   $requestData['tag'] === 'overview-test';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_keyword_overview_live_uses_default_attributes()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20241227',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1845 sec.',
            'cost'           => 0.0201,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.1052 sec.',
                    'cost'           => 0.0201,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'keyword_overview', 'live'],
                    'data'           => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'keyword_overview',
                        'se_type'       => 'google',
                        'location_code' => 2840,
                        'language_code' => 'en',
                        'keywords'      => ['test', 'keyword', 'overview', 'analysis'],
                    ],
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'items_count'   => 4,
                            'items'         => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_overview/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $keywords = ['test', 'keyword', 'overview', 'analysis'];
        $response = $this->client->labsGoogleKeywordOverviewLive($keywords);

        // Verify that attributes is all keywords concatenated
        $this->assertArrayHasKey('request', $response);
        $this->assertArrayHasKey('attributes', $response['request']);
        $this->assertEquals(implode(',', $keywords), $response['request']['attributes']);

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public static function keywordOverviewParametersProvider(): array
    {
        return [
            'with location and language codes and boolean flags' => [
                [
                    'keywords'               => ['seo tools', 'marketing'],
                    'locationCode'           => 2840,
                    'languageCode'           => 'en',
                    'includeSerp_info'       => true,
                    'includeClickstreamData' => false,
                    'tag'                    => 'overview-test',
                ],
                [
                    'keywords'                 => ['seo tools', 'marketing'],
                    'location_code'            => 2840,
                    'language_code'            => 'en',
                    'include_serp_info'        => true,
                    'include_clickstream_data' => false,
                    'tag'                      => 'overview-test',
                ],
            ],
            'with location and language names' => [
                [
                    'keywords'               => ['digital marketing', 'content'],
                    'locationName'           => 'Canada',
                    'languageName'           => 'English',
                    'includeSerp_info'       => false,
                    'includeClickstreamData' => true,
                ],
                [
                    'keywords'                 => ['digital marketing', 'content'],
                    'location_name'            => 'Canada',
                    'language_name'            => 'English',
                    'include_serp_info'        => false,
                    'include_clickstream_data' => true,
                ],
            ],
            'with mixed parameters' => [
                [
                    'keywords'               => ['web development', 'programming'],
                    'locationName'           => 'United Kingdom',
                    'languageCode'           => 'en',
                    'includeSerp_info'       => true,
                    'includeClickstreamData' => true,
                    'tag'                    => 'mixed-test',
                ],
                [
                    'keywords'                 => ['web development', 'programming'],
                    'location_name'            => 'United Kingdom',
                    'language_code'            => 'en',
                    'include_serp_info'        => true,
                    'include_clickstream_data' => true,
                    'tag'                      => 'mixed-test',
                ],
            ],
        ];
    }

    #[DataProvider('keywordOverviewParametersProvider')]
    public function test_labs_google_keyword_overview_live_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20241227',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1845 sec.',
            'cost'           => 0.0201,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.1052 sec.',
                    'cost'           => 0.0201,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'keyword_overview', 'live'],
                    'data'           => array_merge([
                        'api'      => 'dataforseo_labs',
                        'function' => 'keyword_overview',
                        'se_type'  => 'google',
                    ], $expectedParams),
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'location_code' => $expectedParams['location_code'] ?? 2826,
                            'language_code' => $expectedParams['language_code'] ?? 'en',
                            'items_count'   => count($parameters['keywords']),
                            'items'         => array_map(function ($keyword) {
                                return [
                                    'se_type'       => 'google',
                                    'keyword'       => $keyword,
                                    'location_code' => 2840,
                                    'language_code' => 'en',
                                    'keyword_info'  => [
                                        'se_type'       => 'google',
                                        'search_volume' => 10000,
                                        'cpc'           => 1.5,
                                        'competition'   => 0.5,
                                    ],
                                ];
                            }, $parameters['keywords']),
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_overview/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleKeywordOverviewLive(
            $parameters['keywords'],
            $parameters['locationName'] ?? null,
            $parameters['locationCode'] ?? null,
            $parameters['languageName'] ?? null,
            $parameters['languageCode'] ?? null,
            $parameters['includeSerp_info'] ?? null,
            $parameters['includeClickstreamData'] ?? null,
            $parameters['tag'] ?? null,
            $parameters['additionalParams'] ?? []
        );

        Http::assertSent(function ($request) use ($expectedParams) {
            $requestData = $request->data()[0];
            foreach ($expectedParams as $key => $value) {
                if (!isset($requestData[$key]) || $requestData[$key] !== $value) {
                    return false;
                }
            }

            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/keyword_overview/live" &&
                   $request->method() === 'POST';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_historical_keyword_data_live_successful_request()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20241227',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1578 sec.',
            'cost'           => 0.0101,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.0842 sec.',
                    'cost'           => 0.0101,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'historical_keyword_data', 'live'],
                    'data'           => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'historical_keyword_data',
                        'se_type'       => 'google',
                        'language_code' => 'en',
                        'location_code' => 2840,
                        'keywords'      => ['iphone'],
                    ],
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'items_count'   => 1,
                            'items'         => [
                                [
                                    'se_type'       => 'google',
                                    'keyword'       => 'iphone',
                                    'location_code' => 2840,
                                    'language_code' => 'en',
                                    'history'       => [
                                        [
                                            'year'         => 2025,
                                            'month'        => 2,
                                            'keyword_info' => [
                                                'se_type'             => 'google',
                                                'last_updated_time'   => '2025-03-06 03:08:24 +00:00',
                                                'competition'         => 1,
                                                'competition_level'   => 'HIGH',
                                                'cpc'                 => 6.45,
                                                'search_volume'       => 1220000,
                                                'search_volume_trend' => [
                                                    'monthly'   => 0,
                                                    'quarterly' => -19,
                                                    'yearly'    => 22,
                                                ],
                                            ],
                                        ],
                                        [
                                            'year'         => 2025,
                                            'month'        => 1,
                                            'keyword_info' => [
                                                'se_type'             => 'google',
                                                'last_updated_time'   => '2025-02-06 05:57:16 +00:00',
                                                'competition'         => 1,
                                                'competition_level'   => 'HIGH',
                                                'cpc'                 => 5.72,
                                                'search_volume'       => 1220000,
                                                'search_volume_trend' => [
                                                    'monthly'   => -19,
                                                    'quarterly' => 0,
                                                    'yearly'    => 22,
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/historical_keyword_data/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleHistoricalKeywordDataLive(['iphone']);

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/historical_keyword_data/live";
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'POST';
        });

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return isset($requestData['keywords']) && $requestData['keywords'] === ['iphone'];
        });

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return isset($requestData['location_code']) && $requestData['location_code'] === 2840;
        });

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return isset($requestData['language_code']) && $requestData['language_code'] === 'en';
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
        $this->assertEquals(1, $responseData['tasks'][0]['result_count']);
        $this->assertEquals('dataforseo_labs', $responseData['tasks'][0]['data']['api']);
        $this->assertEquals('google', $responseData['tasks'][0]['data']['se_type']);
        $this->assertEquals(['iphone'], $responseData['tasks'][0]['data']['keywords']);
    }

    public function test_labs_google_historical_keyword_data_live_validates_empty_keywords_array()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keywords array cannot be empty');

        $this->client->labsGoogleHistoricalKeywordDataLive([]);
    }

    public function test_labs_google_historical_keyword_data_live_validates_maximum_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of keywords is 700');

        $keywords = array_fill(0, 701, 'test keyword');
        $this->client->labsGoogleHistoricalKeywordDataLive($keywords);
    }

    public function test_labs_google_historical_keyword_data_live_validates_empty_individual_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Individual keywords cannot be empty');

        $this->client->labsGoogleHistoricalKeywordDataLive(['valid keyword', '']);
    }

    public function test_labs_google_historical_keyword_data_live_validates_location_required()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either locationName or locationCode must be provided');

        // Pass null explicitly for both location parameters to trigger validation
        $this->client->labsGoogleHistoricalKeywordDataLive(['test keyword'], null, null);
    }

    public function test_labs_google_historical_keyword_data_live_validates_language_required()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either languageName or languageCode must be provided');

        // Pass null explicitly for both language parameters to trigger validation
        $this->client->labsGoogleHistoricalKeywordDataLive(['test keyword'], null, 2840, null, null);
    }

    public function test_labs_google_historical_keyword_data_live_validates_tag_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');

        $longTag = str_repeat('a', 256);
        $this->client->labsGoogleHistoricalKeywordDataLive(['test keyword'], null, 2840, null, 'en', $longTag);
    }

    public function test_labs_google_historical_keyword_data_live_accepts_location_and_language_names()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20241227',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1578 sec.',
            'cost'           => 0.0101,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.0842 sec.',
                    'cost'           => 0.0101,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'historical_keyword_data', 'live'],
                    'data'           => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'historical_keyword_data',
                        'se_type'       => 'google',
                        'location_name' => 'United Kingdom',
                        'language_name' => 'English',
                        'keywords'      => ['marketing strategy', 'seo tools'],
                    ],
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'location_code' => 2826,
                            'language_code' => 'en',
                            'items_count'   => 2,
                            'items'         => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/historical_keyword_data/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleHistoricalKeywordDataLive(['marketing strategy', 'seo tools'], 'United Kingdom', null, 'English');

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/historical_keyword_data/live" &&
                   $request->method() === 'POST' &&
                   isset($requestData['location_name']) &&
                   $requestData['location_name'] === 'United Kingdom' &&
                   isset($requestData['language_name']) &&
                   $requestData['language_name'] === 'English';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_historical_keyword_data_live_with_tag()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20241227',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1578 sec.',
            'cost'           => 0.0101,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.0842 sec.',
                    'cost'           => 0.0101,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'historical_keyword_data', 'live'],
                    'data'           => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'historical_keyword_data',
                        'se_type'       => 'google',
                        'location_code' => 2840,
                        'language_code' => 'en',
                        'tag'           => 'historical-test',
                        'keywords'      => ['digital marketing'],
                    ],
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'items_count'   => 1,
                            'items'         => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/historical_keyword_data/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleHistoricalKeywordDataLive(
            ['digital marketing'],
            null,
            2840,
            null,
            'en',
            'historical-test'
        );

        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/historical_keyword_data/live" &&
                   $request->method() === 'POST' &&
                   isset($requestData['tag']) &&
                   $requestData['tag'] === 'historical-test';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_labs_google_historical_keyword_data_live_uses_default_attributes()
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20241227',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1578 sec.',
            'cost'           => 0.0101,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.0842 sec.',
                    'cost'           => 0.0101,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'historical_keyword_data', 'live'],
                    'data'           => [
                        'api'           => 'dataforseo_labs',
                        'function'      => 'historical_keyword_data',
                        'se_type'       => 'google',
                        'location_code' => 2840,
                        'language_code' => 'en',
                        'keywords'      => ['test', 'keyword', 'historical', 'analysis'],
                    ],
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'items_count'   => 4,
                            'items'         => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/historical_keyword_data/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $keywords = ['test', 'keyword', 'historical', 'analysis'];
        $response = $this->client->labsGoogleHistoricalKeywordDataLive($keywords);

        // Verify that attributes is all keywords concatenated
        $this->assertArrayHasKey('request', $response);
        $this->assertArrayHasKey('attributes', $response['request']);
        $this->assertEquals(implode(',', $keywords), $response['request']['attributes']);

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public static function historicalKeywordDataParametersProvider(): array
    {
        return [
            'with location and language codes and tag' => [
                [
                    'keywords'     => ['seo trends', 'marketing'],
                    'locationCode' => 2840,
                    'languageCode' => 'en',
                    'tag'          => 'historical-test',
                ],
                [
                    'keywords'      => ['seo trends', 'marketing'],
                    'location_code' => 2840,
                    'language_code' => 'en',
                    'tag'           => 'historical-test',
                ],
            ],
            'with location and language names' => [
                [
                    'keywords'     => ['digital trends', 'content'],
                    'locationName' => 'Canada',
                    'languageName' => 'English',
                ],
                [
                    'keywords'      => ['digital trends', 'content'],
                    'location_name' => 'Canada',
                    'language_name' => 'English',
                ],
            ],
            'with mixed parameters' => [
                [
                    'keywords'     => ['web trends', 'programming'],
                    'locationName' => 'United Kingdom',
                    'languageCode' => 'en',
                    'tag'          => 'mixed-historical-test',
                ],
                [
                    'keywords'      => ['web trends', 'programming'],
                    'location_name' => 'United Kingdom',
                    'language_code' => 'en',
                    'tag'           => 'mixed-historical-test',
                ],
            ],
        ];
    }

    #[DataProvider('historicalKeywordDataParametersProvider')]
    public function test_labs_google_historical_keyword_data_live_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $id              = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20241227',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1578 sec.',
            'cost'           => 0.0101,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.0842 sec.',
                    'cost'           => 0.0101,
                    'result_count'   => 1,
                    'path'           => ['v3', 'dataforseo_labs', 'google', 'historical_keyword_data', 'live'],
                    'data'           => array_merge([
                        'api'      => 'dataforseo_labs',
                        'function' => 'historical_keyword_data',
                        'se_type'  => 'google',
                    ], $expectedParams),
                    'result' => [
                        [
                            'se_type'       => 'google',
                            'location_code' => $expectedParams['location_code'] ?? 2826,
                            'language_code' => $expectedParams['language_code'] ?? 'en',
                            'items_count'   => count($parameters['keywords']),
                            'items'         => array_map(function ($keyword) {
                                return [
                                    'se_type'       => 'google',
                                    'keyword'       => $keyword,
                                    'location_code' => 2840,
                                    'language_code' => 'en',
                                    'history'       => [
                                        [
                                            'year'         => 2025,
                                            'month'        => 1,
                                            'keyword_info' => [
                                                'se_type'       => 'google',
                                                'search_volume' => 10000,
                                                'cpc'           => 1.5,
                                                'competition'   => 0.5,
                                            ],
                                        ],
                                    ],
                                ];
                            }, $parameters['keywords']),
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/dataforseo_labs/google/historical_keyword_data/live" => Http::response($successResponse, 200, $this->apiDefaultHeaders),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->labsGoogleHistoricalKeywordDataLive(
            $parameters['keywords'],
            $parameters['locationName'] ?? null,
            $parameters['locationCode'] ?? null,
            $parameters['languageName'] ?? null,
            $parameters['languageCode'] ?? null,
            $parameters['tag'] ?? null,
            $parameters['additionalParams'] ?? []
        );

        Http::assertSent(function ($request) use ($expectedParams) {
            $requestData = $request->data()[0];
            foreach ($expectedParams as $key => $value) {
                if (!isset($requestData[$key]) || $requestData[$key] !== $value) {
                    return false;
                }
            }

            return $request->url() === "{$this->apiBaseUrl}/dataforseo_labs/google/historical_keyword_data/live" &&
                   $request->method() === 'POST';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }
}

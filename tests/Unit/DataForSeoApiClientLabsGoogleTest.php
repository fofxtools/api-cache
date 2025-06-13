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

        // Verify that default attributes (concatenated keywords) are used
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
}

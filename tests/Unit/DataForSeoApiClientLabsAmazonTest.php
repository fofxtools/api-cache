<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\DataForSeoApiClient;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;

class DataForSeoApiClientLabsAmazonTest extends TestCase
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
}

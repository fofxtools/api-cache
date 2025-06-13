<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\DataForSeoApiClient;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;

class DataForSeoApiClientKeywordsDataGoogleAdsTest extends TestCase
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

    // ========== Search Volume Task Post Tests ==========

    public function test_keywords_data_google_ads_search_volume_task_post_successful_request()
    {
        $taskId          = '01234567-89ab-cdef-0123-456789abcdef';
        $keywords        = ['digital marketing', 'seo tools', 'keyword research'];
        $successResponse = [
            'version'        => '0.1.20250612',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1234 sec.',
            'cost'           => 0.0025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $taskId,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.1234 sec.',
                    'cost'           => 0.0025,
                    'result_count'   => 1,
                    'path'           => [
                        'v3',
                        'keywords_data',
                        'google_ads',
                        'search_volume',
                        'task_post',
                    ],
                    'data' => [
                        'keywords'      => $keywords,
                        'location_code' => 2840,
                        'language_code' => 'en',
                    ],
                    'result' => [
                        'task_id' => $taskId,
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/search_volume/task_post" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->keywordsDataGoogleAdsSearchVolumeTaskPost($keywords);

        Http::assertSent(function ($request) use ($keywords) {
            return $request->url() === "{$this->apiBaseUrl}/keywords_data/google_ads/search_volume/task_post" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['keywords']) &&
                   $request->data()[0]['keywords'] === $keywords;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
        $this->assertEquals('keywords_data', $responseData['tasks'][0]['path'][1]);
        $this->assertEquals('google_ads', $responseData['tasks'][0]['path'][2]);
        $this->assertEquals('search_volume', $responseData['tasks'][0]['path'][3]);
        $this->assertEquals('task_post', $responseData['tasks'][0]['path'][4]);
    }

    public function test_keywords_data_google_ads_search_volume_task_post_validates_empty_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keywords array cannot be empty');

        $this->client->keywordsDataGoogleAdsSearchVolumeTaskPost([]);
    }

    public function test_keywords_data_google_ads_search_volume_task_post_validates_maximum_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of keywords is 1000');

        // Create an array with 1001 keywords
        $keywords = array_fill(0, 1001, 'test keyword');
        $this->client->keywordsDataGoogleAdsSearchVolumeTaskPost($keywords);
    }

    public function test_keywords_data_google_ads_search_volume_task_post_validates_keyword_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Each keyword cannot exceed 80 characters');

        $longKeyword = str_repeat('a', 81);
        $this->client->keywordsDataGoogleAdsSearchVolumeTaskPost([$longKeyword]);
    }

    public function test_keywords_data_google_ads_search_volume_task_post_validates_keyword_word_count()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Each keyword cannot exceed 10 words');

        $manyWordsKeyword = str_repeat('word ', 11);
        $this->client->keywordsDataGoogleAdsSearchVolumeTaskPost([$manyWordsKeyword]);
    }

    public function test_keywords_data_google_ads_search_volume_task_post_validates_date_format()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dateFrom must be in yyyy-mm-dd format');

        $this->client->keywordsDataGoogleAdsSearchVolumeTaskPost(['test'], null, 2840, null, null, 'en', false, '2024/01/01');
    }

    public function test_keywords_data_google_ads_search_volume_task_post_validates_date_range()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dateFrom cannot be greater than dateTo');

        $this->client->keywordsDataGoogleAdsSearchVolumeTaskPost(['test'], null, 2840, null, null, 'en', false, '2024-02-01', '2024-01-01');
    }

    public function test_keywords_data_google_ads_search_volume_task_post_validates_location_coordinate()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('locationCoordinate must be in "latitude,longitude" format');

        $this->client->keywordsDataGoogleAdsSearchVolumeTaskPost(['test'], null, 2840, 'invalid-coordinate');
    }

    public function test_keywords_data_google_ads_search_volume_task_post_validates_sort_by()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sortBy must be one of: relevance, search_volume, competition_index, low_top_of_page_bid, high_top_of_page_bid');

        $this->client->keywordsDataGoogleAdsSearchVolumeTaskPost(['test'], null, 2840, null, null, 'en', false, null, null, false, 'invalid_sort');
    }

    public static function searchVolumeTaskPostParametersProvider(): array
    {
        return [
            'with location name' => [
                ['keywords' => ['test keyword'], 'locationName' => 'United States'],
                ['location_name' => 'United States'],
            ],
            'with location code' => [
                ['keywords' => ['test keyword'], 'locationCode' => 2840],
                ['location_code' => 2840],
            ],
            'with location coordinate' => [
                ['keywords' => ['test keyword'], 'locationCoordinate' => '40.7128,-74.0060'],
                ['location_coordinate' => '40.7128,-74.0060'],
            ],
            'with language name' => [
                ['keywords' => ['test keyword'], 'languageName' => 'English'],
                ['language_name' => 'English'],
            ],
            'with language code' => [
                ['keywords' => ['test keyword'], 'languageCode' => 'en'],
                ['language_code' => 'en'],
            ],
            'with search partners' => [
                ['keywords' => ['test keyword'], 'searchPartners' => true],
                ['search_partners' => true],
            ],
            'with date range' => [
                ['keywords' => ['test keyword'], 'dateFrom' => '2024-01-01', 'dateTo' => '2024-01-31'],
                ['date_from' => '2024-01-01', 'date_to' => '2024-01-31'],
            ],
            'with include adult keywords' => [
                ['keywords' => ['test keyword'], 'includeAdultKeywords' => true],
                ['include_adult_keywords' => true],
            ],
            'with sort by' => [
                ['keywords' => ['test keyword'], 'sortBy' => 'search_volume'],
                ['sort_by' => 'search_volume'],
            ],
            'with tag' => [
                ['keywords' => ['test keyword'], 'tag' => 'test-tag'],
                ['tag' => 'test-tag'],
            ],
        ];
    }

    #[DataProvider('searchVolumeTaskPostParametersProvider')]
    public function test_keywords_data_google_ads_search_volume_task_post_builds_request_with_correct_parameters($methodParams, $expectedParams)
    {
        $taskId          = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'     => '0.1.20250612',
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'          => $taskId,
                    'status_code' => 20000,
                    'result'      => ['task_id' => $taskId],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/search_volume/task_post" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $this->client->keywordsDataGoogleAdsSearchVolumeTaskPost(...$methodParams);

        Http::assertSent(function ($request) use ($expectedParams) {
            $requestData = $request->data()[0];
            foreach ($expectedParams as $key => $value) {
                if (!array_key_exists($key, $requestData) || $requestData[$key] !== $value) {
                    return false;
                }
            }

            return true;
        });

        // Make sure we used the Http::fake() response
        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/keywords_data/google_ads/search_volume/task_post";
        });
    }

    // ========== Search Volume Task Get Tests ==========

    public function test_keywords_data_google_ads_search_volume_task_get_successful_request()
    {
        $taskId          = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20250612',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1234 sec.',
            'cost'           => 0.0025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $taskId,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.1234 sec.',
                    'cost'           => 0.0025,
                    'result_count'   => 3,
                    'path'           => [
                        'v3',
                        'keywords_data',
                        'google_ads',
                        'search_volume',
                        'task_get',
                        $taskId,
                    ],
                    'data' => [
                        'keywords'      => ['digital marketing', 'seo tools', 'keyword research'],
                        'location_code' => 2840,
                        'language_code' => 'en',
                    ],
                    'result' => [
                        [
                            'keyword'              => 'digital marketing',
                            'location_code'        => 2840,
                            'language_code'        => 'en',
                            'search_volume'        => 74000,
                            'competition'          => 0.99,
                            'competition_index'    => 100,
                            'low_top_of_page_bid'  => 0.21,
                            'high_top_of_page_bid' => 3.4,
                        ],
                        [
                            'keyword'              => 'seo tools',
                            'location_code'        => 2840,
                            'language_code'        => 'en',
                            'search_volume'        => 49500,
                            'competition'          => 0.85,
                            'competition_index'    => 85,
                            'low_top_of_page_bid'  => 0.18,
                            'high_top_of_page_bid' => 2.8,
                        ],
                        [
                            'keyword'              => 'keyword research',
                            'location_code'        => 2840,
                            'language_code'        => 'en',
                            'search_volume'        => 18100,
                            'competition'          => 0.78,
                            'competition_index'    => 78,
                            'low_top_of_page_bid'  => 0.15,
                            'high_top_of_page_bid' => 2.1,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/search_volume/task_get/{$taskId}" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->keywordsDataGoogleAdsSearchVolumeTaskGet($taskId);

        Http::assertSent(function ($request) use ($taskId) {
            return $request->url() === "{$this->apiBaseUrl}/keywords_data/google_ads/search_volume/task_get/{$taskId}" &&
                   $request->method() === 'GET';
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
        $this->assertEquals(3, $responseData['tasks'][0]['result_count']);
        $this->assertCount(3, $responseData['tasks'][0]['result']);
        $this->assertEquals('digital marketing', $responseData['tasks'][0]['result'][0]['keyword']);
        $this->assertEquals(74000, $responseData['tasks'][0]['result'][0]['search_volume']);
    }

    // ========== Search Volume Live Tests ==========

    public function test_keywords_data_google_ads_search_volume_live_successful_request()
    {
        $keywords        = ['digital marketing', 'seo tools'];
        $successResponse = [
            'version'        => '0.1.20250612',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.8765 sec.',
            'cost'           => 0.005,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => '01234567-89ab-cdef-0123-456789abcdef',
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.8765 sec.',
                    'cost'           => 0.005,
                    'result_count'   => 2,
                    'path'           => [
                        'v3',
                        'keywords_data',
                        'google_ads',
                        'search_volume',
                        'live',
                    ],
                    'data' => [
                        'keywords'      => $keywords,
                        'location_code' => 2840,
                        'language_code' => 'en',
                    ],
                    'result' => [
                        [
                            'keyword'              => 'digital marketing',
                            'location_code'        => 2840,
                            'language_code'        => 'en',
                            'search_volume'        => 74000,
                            'competition'          => 0.99,
                            'competition_index'    => 100,
                            'low_top_of_page_bid'  => 0.21,
                            'high_top_of_page_bid' => 3.4,
                        ],
                        [
                            'keyword'              => 'seo tools',
                            'location_code'        => 2840,
                            'language_code'        => 'en',
                            'search_volume'        => 49500,
                            'competition'          => 0.85,
                            'competition_index'    => 85,
                            'low_top_of_page_bid'  => 0.18,
                            'high_top_of_page_bid' => 2.8,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/search_volume/live" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->keywordsDataGoogleAdsSearchVolumeLive($keywords);

        Http::assertSent(function ($request) use ($keywords) {
            return $request->url() === "{$this->apiBaseUrl}/keywords_data/google_ads/search_volume/live" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['keywords']) &&
                   $request->data()[0]['keywords'] === $keywords;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals(2, $responseData['tasks'][0]['result_count']);
        $this->assertCount(2, $responseData['tasks'][0]['result']);
        $this->assertEquals('digital marketing', $responseData['tasks'][0]['result'][0]['keyword']);
        $this->assertEquals('seo tools', $responseData['tasks'][0]['result'][1]['keyword']);
        $this->assertEquals('live', $responseData['tasks'][0]['path'][4]);
    }

    public function test_keywords_data_google_ads_search_volume_live_validates_empty_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keywords array cannot be empty');

        $this->client->keywordsDataGoogleAdsSearchVolumeLive([]);
    }

    public function test_keywords_data_google_ads_search_volume_live_validates_maximum_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of keywords is 1000');

        // Create an array with 1001 keywords
        $keywords = array_fill(0, 1001, 'test keyword');
        $this->client->keywordsDataGoogleAdsSearchVolumeLive($keywords);
    }

    // ========== Keywords For Site Task Post Tests ==========

    public function test_keywords_data_google_ads_keywords_for_site_task_post_successful_request()
    {
        $taskId          = '01234567-89ab-cdef-0123-456789abcdef';
        $target          = 'example.com';
        $successResponse = [
            'version'        => '0.1.20250612',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1234 sec.',
            'cost'           => 0.0025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $taskId,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.1234 sec.',
                    'cost'           => 0.0025,
                    'result_count'   => 1,
                    'path'           => [
                        'v3',
                        'keywords_data',
                        'google_ads',
                        'keywords_for_site',
                        'task_post',
                    ],
                    'data' => [
                        'target'        => $target,
                        'target_type'   => 'page',
                        'location_code' => 2840,
                        'language_code' => 'en',
                    ],
                    'result' => [
                        'task_id' => $taskId,
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_site/task_post" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->keywordsDataGoogleAdsKeywordsForSiteTaskPost($target);

        Http::assertSent(function ($request) use ($target) {
            return $request->url() === "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_site/task_post" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['target']) &&
                   $request->data()[0]['target'] === $target;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
        $this->assertEquals('keywords_for_site', $responseData['tasks'][0]['path'][3]);
        $this->assertEquals('task_post', $responseData['tasks'][0]['path'][4]);
    }

    public function test_keywords_data_google_ads_keywords_for_site_task_post_validates_empty_target()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target cannot be empty');

        $this->client->keywordsDataGoogleAdsKeywordsForSiteTaskPost('');
    }

    public function test_keywords_data_google_ads_keywords_for_site_task_post_validates_target_type()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('targetType must be one of: site, page');

        $this->client->keywordsDataGoogleAdsKeywordsForSiteTaskPost('example.com', 'invalid');
    }

    public function test_keywords_data_google_ads_keywords_for_site_task_post_validates_tag_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag cannot exceed 255 characters');

        $longTag = str_repeat('a', 256);
        $this->client->keywordsDataGoogleAdsKeywordsForSiteTaskPost('example.com', 'page', null, 2840, null, null, 'en', false, null, null, false, 'relevance', null, null, $longTag);
    }

    public static function keywordsForSiteTaskPostParametersProvider(): array
    {
        return [
            'with target type site' => [
                ['target' => 'example.com', 'targetType' => 'site'],
                ['target_type' => 'site'],
            ],
            'with target type page' => [
                ['target' => 'example.com', 'targetType' => 'page'],
                ['target_type' => 'page'],
            ],
            'with location name' => [
                ['target' => 'example.com', 'locationName' => 'United States'],
                ['location_name' => 'United States'],
            ],
            'with location code' => [
                ['target' => 'example.com', 'locationCode' => 2840],
                ['location_code' => 2840],
            ],
            'with postback url' => [
                ['target' => 'example.com', 'postbackUrl' => 'https://example.com/postback'],
                ['postback_url' => 'https://example.com/postback'],
            ],
            'with pingback url' => [
                ['target' => 'example.com', 'pingbackUrl' => 'https://example.com/pingback'],
                ['pingback_url' => 'https://example.com/pingback'],
            ],
        ];
    }

    #[DataProvider('keywordsForSiteTaskPostParametersProvider')]
    public function test_keywords_data_google_ads_keywords_for_site_task_post_builds_request_with_correct_parameters($methodParams, $expectedParams)
    {
        $taskId          = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'     => '0.1.20250612',
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'          => $taskId,
                    'status_code' => 20000,
                    'result'      => ['task_id' => $taskId],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_site/task_post" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $this->client->keywordsDataGoogleAdsKeywordsForSiteTaskPost(...$methodParams);

        Http::assertSent(function ($request) use ($expectedParams) {
            $requestData = $request->data()[0];
            foreach ($expectedParams as $key => $value) {
                if (!array_key_exists($key, $requestData) || $requestData[$key] !== $value) {
                    return false;
                }
            }

            return true;
        });

        // Make sure we used the Http::fake() response
        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_site/task_post";
        });
    }

    // ========== Keywords For Site Task Get Tests ==========

    public function test_keywords_data_google_ads_keywords_for_site_task_get_successful_request()
    {
        $taskId          = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20250612',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1234 sec.',
            'cost'           => 0.0025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $taskId,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.1234 sec.',
                    'cost'           => 0.0025,
                    'result_count'   => 2,
                    'path'           => [
                        'v3',
                        'keywords_data',
                        'google_ads',
                        'keywords_for_site',
                        'task_get',
                        $taskId,
                    ],
                    'data' => [
                        'target'        => 'example.com',
                        'target_type'   => 'page',
                        'location_code' => 2840,
                        'language_code' => 'en',
                    ],
                    'result' => [
                        [
                            'keyword'              => 'example website',
                            'location_code'        => 2840,
                            'language_code'        => 'en',
                            'search_volume'        => 1000,
                            'competition'          => 0.5,
                            'competition_index'    => 50,
                            'low_top_of_page_bid'  => 0.1,
                            'high_top_of_page_bid' => 1.0,
                        ],
                        [
                            'keyword'              => 'example site',
                            'location_code'        => 2840,
                            'language_code'        => 'en',
                            'search_volume'        => 800,
                            'competition'          => 0.4,
                            'competition_index'    => 40,
                            'low_top_of_page_bid'  => 0.08,
                            'high_top_of_page_bid' => 0.8,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_site/task_get/{$taskId}" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->keywordsDataGoogleAdsKeywordsForSiteTaskGet($taskId);

        Http::assertSent(function ($request) use ($taskId) {
            return $request->url() === "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_site/task_get/{$taskId}" &&
                   $request->method() === 'GET';
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
        $this->assertEquals(2, $responseData['tasks'][0]['result_count']);
        $this->assertCount(2, $responseData['tasks'][0]['result']);
        $this->assertEquals('example website', $responseData['tasks'][0]['result'][0]['keyword']);
        $this->assertEquals(1000, $responseData['tasks'][0]['result'][0]['search_volume']);
    }

    // ========== Keywords For Site Live Tests ==========

    public function test_keywords_data_google_ads_keywords_for_site_live_successful_request()
    {
        $target          = 'example.com';
        $successResponse = [
            'version'        => '0.1.20250612',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '1.2345 sec.',
            'cost'           => 0.01,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => '01234567-89ab-cdef-0123-456789abcdef',
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '1.2345 sec.',
                    'cost'           => 0.01,
                    'result_count'   => 2,
                    'path'           => [
                        'v3',
                        'keywords_data',
                        'google_ads',
                        'keywords_for_site',
                        'live',
                    ],
                    'data' => [
                        'target'        => $target,
                        'target_type'   => 'page',
                        'location_code' => 2840,
                        'language_code' => 'en',
                    ],
                    'result' => [
                        [
                            'keyword'              => 'example website',
                            'location_code'        => 2840,
                            'language_code'        => 'en',
                            'search_volume'        => 1000,
                            'competition'          => 0.5,
                            'competition_index'    => 50,
                            'low_top_of_page_bid'  => 0.1,
                            'high_top_of_page_bid' => 1.0,
                        ],
                        [
                            'keyword'              => 'example site',
                            'location_code'        => 2840,
                            'language_code'        => 'en',
                            'search_volume'        => 800,
                            'competition'          => 0.4,
                            'competition_index'    => 40,
                            'low_top_of_page_bid'  => 0.08,
                            'high_top_of_page_bid' => 0.8,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_site/live" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->keywordsDataGoogleAdsKeywordsForSiteLive($target);

        Http::assertSent(function ($request) use ($target) {
            return $request->url() === "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_site/live" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['target']) &&
                   $request->data()[0]['target'] === $target;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals(2, $responseData['tasks'][0]['result_count']);
        $this->assertCount(2, $responseData['tasks'][0]['result']);
        $this->assertEquals('example website', $responseData['tasks'][0]['result'][0]['keyword']);
        $this->assertEquals('example site', $responseData['tasks'][0]['result'][1]['keyword']);
        $this->assertEquals('live', $responseData['tasks'][0]['path'][4]);
    }

    public function test_keywords_data_google_ads_keywords_for_site_live_validates_empty_target()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target cannot be empty');

        $this->client->keywordsDataGoogleAdsKeywordsForSiteLive('');
    }

    public function test_keywords_data_google_ads_keywords_for_site_live_validates_target_type()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('targetType must be one of: site, page');

        $this->client->keywordsDataGoogleAdsKeywordsForSiteLive('example.com', 'invalid');
    }

    // ========== Keywords For Keywords Task Post Tests ==========

    public function test_keywords_data_google_ads_keywords_for_keywords_task_post_successful_request()
    {
        $taskId          = '01234567-89ab-cdef-0123-456789abcdef';
        $keywords        = ['digital marketing', 'seo tools', 'keyword research'];
        $successResponse = [
            'version'        => '0.1.20250612',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1234 sec.',
            'cost'           => 0.0025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $taskId,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.1234 sec.',
                    'cost'           => 0.0025,
                    'result_count'   => 1,
                    'path'           => [
                        'v3',
                        'keywords_data',
                        'google_ads',
                        'keywords_for_keywords',
                        'task_post',
                    ],
                    'data' => [
                        'keywords'      => $keywords,
                        'location_code' => 2840,
                        'language_code' => 'en',
                    ],
                    'result' => [
                        'task_id' => $taskId,
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_keywords/task_post" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->keywordsDataGoogleAdsKeywordsForKeywordsTaskPost($keywords);

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_keywords/task_post";
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'POST';
        });

        Http::assertSent(function ($request) use ($keywords) {
            return isset($request->data()[0]['keywords']) && $request->data()[0]['keywords'] === $keywords;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
        $this->assertEquals('keywords_for_keywords', $responseData['tasks'][0]['path'][3]);
        $this->assertEquals('task_post', $responseData['tasks'][0]['path'][4]);
    }

    public function test_keywords_data_google_ads_keywords_for_keywords_task_post_validates_empty_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keywords array cannot be empty');

        $this->client->keywordsDataGoogleAdsKeywordsForKeywordsTaskPost([]);
    }

    public function test_keywords_data_google_ads_keywords_for_keywords_task_post_validates_maximum_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of keywords is 20');

        // Create an array with 21 keywords
        $keywords = array_fill(0, 21, 'test keyword');
        $this->client->keywordsDataGoogleAdsKeywordsForKeywordsTaskPost($keywords);
    }

    public function test_keywords_data_google_ads_keywords_for_keywords_task_post_validates_keyword_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Each keyword cannot exceed 80 characters');

        $longKeyword = str_repeat('a', 81);
        $this->client->keywordsDataGoogleAdsKeywordsForKeywordsTaskPost([$longKeyword]);
    }

    public function test_keywords_data_google_ads_keywords_for_keywords_task_post_validates_date_format()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dateFrom must be in yyyy-mm-dd format');

        $this->client->keywordsDataGoogleAdsKeywordsForKeywordsTaskPost(['test'], null, null, 2840, null, null, 'en', false, '2024/01/01');
    }

    public function test_keywords_data_google_ads_keywords_for_keywords_task_post_validates_date_range()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dateFrom cannot be greater than dateTo');

        $this->client->keywordsDataGoogleAdsKeywordsForKeywordsTaskPost(['test'], null, null, 2840, null, null, 'en', false, '2024-02-01', '2024-01-01');
    }

    public function test_keywords_data_google_ads_keywords_for_keywords_task_post_validates_location_coordinate()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('locationCoordinate must be in "latitude,longitude" format');

        $this->client->keywordsDataGoogleAdsKeywordsForKeywordsTaskPost(['test'], null, null, 2840, 'invalid-coordinate');
    }

    public function test_keywords_data_google_ads_keywords_for_keywords_task_post_validates_sort_by()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sortBy must be one of: relevance, search_volume, competition_index, low_top_of_page_bid, high_top_of_page_bid');

        $this->client->keywordsDataGoogleAdsKeywordsForKeywordsTaskPost(['test'], null, null, 2840, null, null, 'en', false, null, null, 'invalid_sort');
    }

    public function test_keywords_data_google_ads_keywords_for_keywords_task_post_validates_tag_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag cannot exceed 255 characters');

        $longTag = str_repeat('a', 256);
        $this->client->keywordsDataGoogleAdsKeywordsForKeywordsTaskPost(['test'], null, null, 2840, null, null, 'en', false, null, null, 'relevance', false, null, null, $longTag);
    }

    public static function keywordsForKeywordsTaskPostParametersProvider(): array
    {
        return [
            'with target' => [
                ['keywords' => ['test keyword'], 'target' => 'example.com'],
                ['target' => 'example.com'],
            ],
            'with location name' => [
                ['keywords' => ['test keyword'], 'locationName' => 'United States'],
                ['location_name' => 'United States'],
            ],
            'with location code' => [
                ['keywords' => ['test keyword'], 'locationCode' => 2840],
                ['location_code' => 2840],
            ],
            'with location coordinate' => [
                ['keywords' => ['test keyword'], 'locationCoordinate' => '40.7128,-74.0060'],
                ['location_coordinate' => '40.7128,-74.0060'],
            ],
            'with language name' => [
                ['keywords' => ['test keyword'], 'languageName' => 'English'],
                ['language_name' => 'English'],
            ],
            'with language code' => [
                ['keywords' => ['test keyword'], 'languageCode' => 'en'],
                ['language_code' => 'en'],
            ],
            'with search partners' => [
                ['keywords' => ['test keyword'], 'searchPartners' => true],
                ['search_partners' => true],
            ],
            'with date range' => [
                ['keywords' => ['test keyword'], 'dateFrom' => '2024-01-01', 'dateTo' => '2024-01-31'],
                ['date_from' => '2024-01-01', 'date_to' => '2024-01-31'],
            ],
            'with sort by' => [
                ['keywords' => ['test keyword'], 'sortBy' => 'search_volume'],
                ['sort_by' => 'search_volume'],
            ],
            'with include adult keywords' => [
                ['keywords' => ['test keyword'], 'includeAdultKeywords' => true],
                ['include_adult_keywords' => true],
            ],
            'with postback url' => [
                ['keywords' => ['test keyword'], 'postbackUrl' => 'https://example.com/postback'],
                ['postback_url' => 'https://example.com/postback'],
            ],
            'with pingback url' => [
                ['keywords' => ['test keyword'], 'pingbackUrl' => 'https://example.com/pingback'],
                ['pingback_url' => 'https://example.com/pingback'],
            ],
            'with tag' => [
                ['keywords' => ['test keyword'], 'tag' => 'test-tag'],
                ['tag' => 'test-tag'],
            ],
        ];
    }

    #[DataProvider('keywordsForKeywordsTaskPostParametersProvider')]
    public function test_keywords_data_google_ads_keywords_for_keywords_task_post_builds_request_with_correct_parameters($methodParams, $expectedParams)
    {
        $taskId          = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'     => '0.1.20250612',
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'          => $taskId,
                    'status_code' => 20000,
                    'result'      => ['task_id' => $taskId],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_keywords/task_post" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $this->client->keywordsDataGoogleAdsKeywordsForKeywordsTaskPost(...$methodParams);

        Http::assertSent(function ($request) use ($expectedParams) {
            $requestData = $request->data()[0];
            foreach ($expectedParams as $key => $value) {
                if (!array_key_exists($key, $requestData) || $requestData[$key] !== $value) {
                    return false;
                }
            }

            return true;
        });

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_keywords/task_post";
        });
    }

    // ========== Keywords For Keywords Task Get Tests ==========

    public function test_keywords_data_google_ads_keywords_for_keywords_task_get_successful_request()
    {
        $taskId          = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20250612',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1234 sec.',
            'cost'           => 0.0025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $taskId,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.1234 sec.',
                    'cost'           => 0.0025,
                    'result_count'   => 3,
                    'path'           => [
                        'v3',
                        'keywords_data',
                        'google_ads',
                        'keywords_for_keywords',
                        'task_get',
                        $taskId,
                    ],
                    'data' => [
                        'keywords'      => ['digital marketing', 'seo tools', 'keyword research'],
                        'location_code' => 2840,
                        'language_code' => 'en',
                    ],
                    'result' => [
                        [
                            'keyword'              => 'digital marketing',
                            'location_code'        => 2840,
                            'language_code'        => 'en',
                            'search_volume'        => 74000,
                            'competition'          => 0.99,
                            'competition_index'    => 100,
                            'low_top_of_page_bid'  => 0.21,
                            'high_top_of_page_bid' => 3.4,
                        ],
                        [
                            'keyword'              => 'seo strategy',
                            'location_code'        => 2840,
                            'language_code'        => 'en',
                            'search_volume'        => 12000,
                            'competition'          => 0.75,
                            'competition_index'    => 75,
                            'low_top_of_page_bid'  => 0.18,
                            'high_top_of_page_bid' => 2.8,
                        ],
                        [
                            'keyword'              => 'content marketing',
                            'location_code'        => 2840,
                            'language_code'        => 'en',
                            'search_volume'        => 18100,
                            'competition'          => 0.78,
                            'competition_index'    => 78,
                            'low_top_of_page_bid'  => 0.15,
                            'high_top_of_page_bid' => 2.1,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_keywords/task_get/{$taskId}" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->keywordsDataGoogleAdsKeywordsForKeywordsTaskGet($taskId);

        Http::assertSent(function ($request) use ($taskId) {
            return $request->url() === "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_keywords/task_get/{$taskId}";
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'GET';
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
        $this->assertEquals(3, $responseData['tasks'][0]['result_count']);
        $this->assertCount(3, $responseData['tasks'][0]['result']);
        $this->assertEquals('digital marketing', $responseData['tasks'][0]['result'][0]['keyword']);
        $this->assertEquals(74000, $responseData['tasks'][0]['result'][0]['search_volume']);
    }

    // ========== Keywords For Keywords Live Tests ==========

    public function test_keywords_data_google_ads_keywords_for_keywords_live_successful_request()
    {
        $keywords        = ['digital marketing', 'seo tools'];
        $successResponse = [
            'version'        => '0.1.20250612',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.8765 sec.',
            'cost'           => 0.005,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => '01234567-89ab-cdef-0123-456789abcdef',
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.8765 sec.',
                    'cost'           => 0.005,
                    'result_count'   => 2,
                    'path'           => [
                        'v3',
                        'keywords_data',
                        'google_ads',
                        'keywords_for_keywords',
                        'live',
                    ],
                    'data' => [
                        'keywords'      => $keywords,
                        'location_code' => 2840,
                        'language_code' => 'en',
                    ],
                    'result' => [
                        [
                            'keyword'              => 'content strategy',
                            'location_code'        => 2840,
                            'language_code'        => 'en',
                            'search_volume'        => 8100,
                            'competition'          => 0.85,
                            'competition_index'    => 85,
                            'low_top_of_page_bid'  => 0.21,
                            'high_top_of_page_bid' => 3.4,
                        ],
                        [
                            'keyword'              => 'social media',
                            'location_code'        => 2840,
                            'language_code'        => 'en',
                            'search_volume'        => 165000,
                            'competition'          => 0.92,
                            'competition_index'    => 92,
                            'low_top_of_page_bid'  => 0.18,
                            'high_top_of_page_bid' => 2.8,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_keywords/live" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->keywordsDataGoogleAdsKeywordsForKeywordsLive($keywords);

        Http::assertSent(function ($request) use ($keywords) {
            return $request->url() === "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_keywords/live"
                && $request->method() === 'POST'
                && isset($request->data()[0]['keywords'])
                && $request->data()[0]['keywords'] === $keywords;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals(2, $responseData['tasks'][0]['result_count']);
        $this->assertCount(2, $responseData['tasks'][0]['result']);
        $this->assertEquals('content strategy', $responseData['tasks'][0]['result'][0]['keyword']);
        $this->assertEquals('social media', $responseData['tasks'][0]['result'][1]['keyword']);
        $this->assertEquals('live', $responseData['tasks'][0]['path'][4]);
    }

    public function test_keywords_data_google_ads_keywords_for_keywords_live_validates_empty_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keywords array cannot be empty');

        $this->client->keywordsDataGoogleAdsKeywordsForKeywordsLive([]);
    }

    public function test_keywords_data_google_ads_keywords_for_keywords_live_validates_maximum_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of keywords is 20');

        // Create an array with 21 keywords
        $keywords = array_fill(0, 21, 'test keyword');
        $this->client->keywordsDataGoogleAdsKeywordsForKeywordsLive($keywords);
    }

    public function test_keywords_data_google_ads_keywords_for_keywords_live_validates_keyword_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Each keyword cannot exceed 80 characters');

        $longKeyword = str_repeat('a', 81);
        $this->client->keywordsDataGoogleAdsKeywordsForKeywordsLive([$longKeyword]);
    }

    // ========== Ad Traffic By Keywords Task Post Tests ==========

    public function test_keywords_data_google_ads_ad_traffic_by_keywords_task_post_successful_request()
    {
        $taskId          = '01234567-89ab-cdef-0123-456789abcdef';
        $keywords        = ['digital marketing', 'seo tools', 'content marketing'];
        $bid             = 2.5;
        $match           = 'exact';
        $successResponse = [
            'version'        => '0.1.20250612',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1234 sec.',
            'cost'           => 0.0025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $taskId,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.1234 sec.',
                    'cost'           => 0.0025,
                    'result_count'   => 1,
                    'path'           => [
                        'v3',
                        'keywords_data',
                        'google_ads',
                        'ad_traffic_by_keywords',
                        'task_post',
                    ],
                    'data' => [
                        'keywords'      => $keywords,
                        'bid'           => $bid,
                        'match'         => $match,
                        'location_code' => 2840,
                        'language_code' => 'en',
                    ],
                    'result' => [
                        'task_id' => $taskId,
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/ad_traffic_by_keywords/task_post" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->keywordsDataGoogleAdsAdTrafficByKeywordsTaskPost($keywords, $bid, $match);

        Http::assertSent(function ($request) use ($keywords, $bid, $match) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/keywords_data/google_ads/ad_traffic_by_keywords/task_post" && $request->method() === 'POST' && isset($requestData['keywords']) && $requestData['keywords'] === $keywords
                && isset($requestData['bid']) && $requestData['bid'] === $bid
                && isset($requestData['match']) && $requestData['match'] === $match;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
        $this->assertEquals('ad_traffic_by_keywords', $responseData['tasks'][0]['path'][3]);
        $this->assertEquals('task_post', $responseData['tasks'][0]['path'][4]);
    }

    public function test_keywords_data_google_ads_ad_traffic_by_keywords_task_post_validates_empty_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keywords array cannot be empty');

        $this->client->keywordsDataGoogleAdsAdTrafficByKeywordsTaskPost([], 2.5, 'exact');
    }

    public function test_keywords_data_google_ads_ad_traffic_by_keywords_task_post_validates_maximum_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of keywords is 1000');

        // Create an array with 1001 keywords
        $keywords = array_fill(0, 1001, 'test keyword');
        $this->client->keywordsDataGoogleAdsAdTrafficByKeywordsTaskPost($keywords, 2.5, 'exact');
    }

    public function test_keywords_data_google_ads_ad_traffic_by_keywords_task_post_validates_keyword_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Each keyword cannot exceed 80 characters');

        $longKeyword = str_repeat('a', 81);
        $this->client->keywordsDataGoogleAdsAdTrafficByKeywordsTaskPost([$longKeyword], 2.5, 'exact');
    }

    public function test_keywords_data_google_ads_ad_traffic_by_keywords_task_post_validates_match_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('match must be one of: exact, broad, phrase');

        $this->client->keywordsDataGoogleAdsAdTrafficByKeywordsTaskPost(['test'], 2.5, 'invalid');
    }

    public function test_keywords_data_google_ads_ad_traffic_by_keywords_task_post_validates_date_interval()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dateInterval must be one of: next_week, next_month, next_quarter');

        $this->client->keywordsDataGoogleAdsAdTrafficByKeywordsTaskPost(['test'], 2.5, 'exact', false, null, 2840, null, null, 'en', null, null, 'invalid_interval');
    }

    public function test_keywords_data_google_ads_ad_traffic_by_keywords_task_post_validates_date_range_mutual_exclusion()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot specify both dateFrom/dateTo and dateInterval');

        $this->client->keywordsDataGoogleAdsAdTrafficByKeywordsTaskPost(['test'], 2.5, 'exact', false, null, 2840, null, null, 'en', '2024-01-01', '2024-01-31', 'next_month');
    }

    // ========== Ad Traffic By Keywords Task Get Tests ==========

    public function test_keywords_data_google_ads_ad_traffic_by_keywords_task_get_successful_request()
    {
        $taskId          = '01234567-89ab-cdef-0123-456789abcdef';
        $successResponse = [
            'version'        => '0.1.20250612',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1234 sec.',
            'cost'           => 0.0025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $taskId,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.1234 sec.',
                    'cost'           => 0.0025,
                    'result_count'   => 2,
                    'path'           => [
                        'v3',
                        'keywords_data',
                        'google_ads',
                        'ad_traffic_by_keywords',
                        'task_get',
                        $taskId,
                    ],
                    'data' => [
                        'keywords'      => ['digital marketing', 'seo tools'],
                        'bid'           => 2.5,
                        'match'         => 'exact',
                        'location_code' => 2840,
                        'language_code' => 'en',
                    ],
                    'result' => [
                        [
                            'keyword'     => 'digital marketing',
                            'impressions' => 5200,
                            'clicks'      => 312,
                            'ctr'         => 6.0,
                            'average_cpc' => 2.15,
                            'cost'        => 670.80,
                        ],
                        [
                            'keyword'     => 'seo tools',
                            'impressions' => 3800,
                            'clicks'      => 190,
                            'ctr'         => 5.0,
                            'average_cpc' => 1.85,
                            'cost'        => 351.50,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/ad_traffic_by_keywords/task_get/{$taskId}" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->keywordsDataGoogleAdsAdTrafficByKeywordsTaskGet($taskId);

        Http::assertSent(function ($request) use ($taskId) {
            return $request->url() === "{$this->apiBaseUrl}/keywords_data/google_ads/ad_traffic_by_keywords/task_get/{$taskId}";
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'GET';
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
        $this->assertEquals(2, $responseData['tasks'][0]['result_count']);
        $this->assertCount(2, $responseData['tasks'][0]['result']);
        $this->assertEquals('digital marketing', $responseData['tasks'][0]['result'][0]['keyword']);
        $this->assertEquals(5200, $responseData['tasks'][0]['result'][0]['impressions']);
        $this->assertEquals(312, $responseData['tasks'][0]['result'][0]['clicks']);
    }

    // ========== Ad Traffic By Keywords Live Tests ==========

    public function test_keywords_data_google_ads_ad_traffic_by_keywords_live_successful_request()
    {
        $keywords        = ['digital marketing', 'seo tools'];
        $bid             = 3.0;
        $match           = 'phrase';
        $successResponse = [
            'version'        => '0.1.20250612',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.8765 sec.',
            'cost'           => 0.01,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => '01234567-89ab-cdef-0123-456789abcdef',
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.8765 sec.',
                    'cost'           => 0.01,
                    'result_count'   => 2,
                    'path'           => [
                        'v3',
                        'keywords_data',
                        'google_ads',
                        'ad_traffic_by_keywords',
                        'live',
                    ],
                    'data' => [
                        'keywords'      => $keywords,
                        'bid'           => $bid,
                        'match'         => $match,
                        'location_code' => 2840,
                        'language_code' => 'en',
                    ],
                    'result' => [
                        [
                            'keyword'     => 'digital marketing',
                            'impressions' => 4800,
                            'clicks'      => 288,
                            'ctr'         => 6.0,
                            'average_cpc' => 2.42,
                            'cost'        => 697.00,
                        ],
                        [
                            'keyword'     => 'seo tools',
                            'impressions' => 3200,
                            'clicks'      => 160,
                            'ctr'         => 5.0,
                            'average_cpc' => 1.95,
                            'cost'        => 312.00,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/ad_traffic_by_keywords/live" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->keywordsDataGoogleAdsAdTrafficByKeywordsLive($keywords, $bid, $match);

        Http::assertSent(function ($request) use ($keywords, $bid, $match) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/keywords_data/google_ads/ad_traffic_by_keywords/live" && $request->method() === 'POST' && isset($requestData['keywords']) && $requestData['keywords'] === $keywords
                && isset($requestData['bid']) && $requestData['bid'] === $bid
                && isset($requestData['match']) && $requestData['match'] === $match;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals(2, $responseData['tasks'][0]['result_count']);
        $this->assertCount(2, $responseData['tasks'][0]['result']);
        $this->assertEquals('digital marketing', $responseData['tasks'][0]['result'][0]['keyword']);
        $this->assertEquals('seo tools', $responseData['tasks'][0]['result'][1]['keyword']);
        $this->assertEquals('live', $responseData['tasks'][0]['path'][4]);
    }

    public function test_keywords_data_google_ads_ad_traffic_by_keywords_live_validates_empty_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keywords array cannot be empty');

        $this->client->keywordsDataGoogleAdsAdTrafficByKeywordsLive([], 3.0, 'exact');
    }

    public function test_keywords_data_google_ads_ad_traffic_by_keywords_live_validates_maximum_keywords()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of keywords is 1000');

        // Create an array with 1001 keywords
        $keywords = array_fill(0, 1001, 'test keyword');
        $this->client->keywordsDataGoogleAdsAdTrafficByKeywordsLive($keywords, 3.0, 'exact');
    }

    public function test_keywords_data_google_ads_ad_traffic_by_keywords_live_validates_match_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('match must be one of: exact, broad, phrase');

        $this->client->keywordsDataGoogleAdsAdTrafficByKeywordsLive(['test'], 3.0, 'invalid');
    }

    // ========== Additional Error Response Tests for New Methods ==========

    public function test_keywords_data_google_ads_keywords_for_keywords_task_post_handles_api_error_response()
    {
        $keywords      = ['digital marketing', 'seo tools'];
        $errorResponse = [
            'version'        => '0.1.20250612',
            'status_code'    => 40101,
            'status_message' => 'Authentication failed',
            'time'           => '0.0123 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 1,
            'tasks'          => [
                [
                    'id'             => '01234567-89ab-cdef-0123-456789abcdef',
                    'status_code'    => 40101,
                    'status_message' => 'Authentication failed',
                    'time'           => '0.0123 sec.',
                    'cost'           => 0,
                    'result_count'   => 0,
                    'path'           => [
                        'v3',
                        'keywords_data',
                        'google_ads',
                        'keywords_for_keywords',
                        'task_post',
                    ],
                    'data'   => null,
                    'result' => null,
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_keywords/task_post" => Http::response($errorResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->keywordsDataGoogleAdsKeywordsForKeywordsTaskPost($keywords);

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals(40101, $responseData['status_code']);
        $this->assertEquals('Authentication failed', $responseData['status_message']);
        $this->assertEquals(1, $responseData['tasks_error']);
    }

    public function test_keywords_data_google_ads_ad_traffic_by_keywords_live_handles_api_error_response()
    {
        $keywords      = ['digital marketing'];
        $bid           = 3.0;
        $match         = 'exact';
        $errorResponse = [
            'version'        => '0.1.20250612',
            'status_code'    => 50000,
            'status_message' => 'Internal server error',
            'time'           => '0.0123 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 1,
            'tasks'          => [
                [
                    'id'             => '01234567-89ab-cdef-0123-456789abcdef',
                    'status_code'    => 50000,
                    'status_message' => 'Internal server error',
                    'time'           => '0.0123 sec.',
                    'cost'           => 0,
                    'result_count'   => 0,
                    'path'           => [
                        'v3',
                        'keywords_data',
                        'google_ads',
                        'ad_traffic_by_keywords',
                        'live',
                    ],
                    'data'   => null,
                    'result' => null,
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/ad_traffic_by_keywords/live" => Http::response($errorResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->keywordsDataGoogleAdsAdTrafficByKeywordsLive($keywords, $bid, $match);

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals(50000, $responseData['status_code']);
        $this->assertEquals('Internal server error', $responseData['status_message']);
        $this->assertEquals(1, $responseData['tasks_error']);
    }

    // ========== Error Response Tests ==========

    public function test_keywords_data_google_ads_search_volume_task_post_handles_api_error_response()
    {
        $errorResponse = [
            'version'        => '0.1.20250612',
            'status_code'    => 40101,
            'status_message' => 'Authentication failed',
            'time'           => '0.1234 sec.',
            'cost'           => 0,
            'tasks_count'    => 0,
            'tasks_error'    => 1,
            'tasks'          => [],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/search_volume/task_post" => Http::response($errorResponse, 401),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->keywordsDataGoogleAdsSearchVolumeTaskPost(['test keyword']);

        // Make sure we used the Http::fake() response
        $this->assertEquals(401, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals(40101, $responseData['status_code']);
        $this->assertEquals('Authentication failed', $responseData['status_message']);
    }

    public function test_keywords_data_google_ads_keywords_for_site_live_handles_api_error_response()
    {
        $errorResponse = [
            'version'        => '0.1.20250612',
            'status_code'    => 40102,
            'status_message' => 'Invalid target',
            'time'           => '0.1234 sec.',
            'cost'           => 0,
            'tasks_count'    => 0,
            'tasks_error'    => 1,
            'tasks'          => [],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_site/live" => Http::response($errorResponse, 400),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->keywordsDataGoogleAdsKeywordsForSiteLive('invalid-target');

        // Make sure we used the Http::fake() response
        $this->assertEquals(400, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals(40102, $responseData['status_code']);
        $this->assertEquals('Invalid target', $responseData['status_message']);
    }

    // ========== Attributes Tests ==========

    public function test_keywords_data_google_ads_search_volume_task_post_uses_default_attributes()
    {
        $taskId          = '01234567-89ab-cdef-0123-456789abcdef';
        $keywords        = ['keyword1', 'keyword2', 'keyword3', 'keyword4', 'keyword5', 'keyword6'];
        $successResponse = [
            'version'     => '0.1.20250612',
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'          => $taskId,
                    'status_code' => 20000,
                    'result'      => ['task_id' => $taskId],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/search_volume/task_post" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->keywordsDataGoogleAdsSearchVolumeTaskPost($keywords);

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
    }

    public function test_keywords_data_google_ads_search_volume_task_post_uses_custom_attributes()
    {
        $taskId           = '01234567-89ab-cdef-0123-456789abcdef';
        $keywords         = ['test keyword'];
        $customAttributes = 'custom-search-volume-test';
        $successResponse  = [
            'version'     => '0.1.20250612',
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'          => $taskId,
                    'status_code' => 20000,
                    'result'      => ['task_id' => $taskId],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/search_volume/task_post" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->keywordsDataGoogleAdsSearchVolumeTaskPost($keywords, null, 2840, null, null, 'en', false, null, null, false, 'relevance', null, null, $customAttributes);

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
    }

    public function test_keywords_data_google_ads_keywords_for_site_task_post_uses_target_as_default_attributes()
    {
        $taskId          = '01234567-89ab-cdef-0123-456789abcdef';
        $target          = 'example.com';
        $successResponse = [
            'version'     => '0.1.20250612',
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'          => $taskId,
                    'status_code' => 20000,
                    'result'      => ['task_id' => $taskId],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_site/task_post" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->keywordsDataGoogleAdsKeywordsForSiteTaskPost($target);

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
    }

    // ========== Standard Methods Tests ==========

    public function test_keywords_data_google_ads_search_volume_standard_returns_cached_data()
    {
        $keywords   = ['test keyword'];
        $cachedData = [
            'version'        => '0.1.20250612',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'tasks'          => [
                [
                    'id'     => 'cached-task-id',
                    'result' => [
                        ['keyword' => 'test keyword', 'search_volume' => 1000],
                    ],
                ],
            ],
        ];

        // Mock cache manager to return cached data
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
                        ->method('generateCacheKey')
                        ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->once())
                        ->method('getCachedResponse')
                        ->with('dataforseo', 'test-cache-key')
                        ->willReturn($cachedData);

        $client = new DataForSeoApiClient($mockCacheManager);

        $result = $client->keywordsDataGoogleAdsSearchVolumeStandard($keywords, null, null, 2840, null, null, 'en', false, null, null, false, 'relevance', false, false, false);

        $this->assertEquals($cachedData, $result);
    }

    public function test_keywords_data_google_ads_search_volume_standard_returns_null_when_not_cached_and_posting_disabled()
    {
        $keywords = ['test keyword'];

        // Mock cache manager to return null (not cached)
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
                        ->method('generateCacheKey')
                        ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->once())
                        ->method('getCachedResponse')
                        ->with('dataforseo', 'test-cache-key')
                        ->willReturn(null);

        $client = new DataForSeoApiClient($mockCacheManager);

        $result = $client->keywordsDataGoogleAdsSearchVolumeStandard(
            $keywords,
            null,
            null,
            2840,
            null,
            null,
            'en',
            false,
            null,
            null,
            false,
            'relevance',
            false, // usePostback
            false, // usePingback
            false  // postTaskIfNotCached - disabled
        );

        $this->assertNull($result);
    }

    public function test_keywords_data_google_ads_search_volume_standard_creates_task_when_not_cached_and_posting_enabled()
    {
        $keywords     = ['test keyword'];
        $taskId       = '01234567-89ab-cdef-0123-456789abcdef';
        $taskResponse = [
            'version'        => '0.1.20250612',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'tasks'          => [
                [
                    'id'     => $taskId,
                    'result' => ['task_id' => $taskId],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/search_volume/task_post" => Http::response($taskResponse, 200),
        ]);

        // Mock cache manager to return null (not cached)
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->exactly(2))
                        ->method('generateCacheKey')
                        ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->exactly(2))
                        ->method('getCachedResponse')
                        ->with('dataforseo', 'test-cache-key')
                        ->willReturn(null);
        $mockCacheManager->expects($this->any())
                        ->method('allowRequest')
                        ->willReturn(true);
        $mockCacheManager->expects($this->any())
                        ->method('incrementAttempts');
        $mockCacheManager->expects($this->any())
                        ->method('storeResponse');

        // Reinitialize client so that its HTTP pending request picks up the fake
        $client = new DataForSeoApiClient($mockCacheManager);
        $client->clearRateLimit();

        $result = $client->keywordsDataGoogleAdsSearchVolumeStandard(
            $keywords,
            null,
            null,
            2840,
            null,
            null,
            'en',
            false,
            null,
            null,
            false,
            'relevance',
            false, // usePostback
            false, // usePingback
            true   // postTaskIfNotCached - enabled
        );

        Http::assertSent(function ($request) use ($keywords) {
            $requestData = $request->data();

            return $request->url() === "{$this->apiBaseUrl}/keywords_data/google_ads/search_volume/task_post" &&
                   $request->method() === 'POST' &&
                   isset($requestData[0]['keywords']) &&
                   $requestData[0]['keywords'] === $keywords;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
    }

    public function test_keywords_data_google_ads_search_volume_standard_excludes_webhook_params_from_cache_key()
    {
        $keywords = ['test keyword'];

        // Mock cache manager to verify cache key generation excludes webhook params
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
                        ->method('generateCacheKey')
                        ->with(
                            'dataforseo',
                            'keywords_data/google_ads/search_volume/task_get',
                            $this->callback(function ($params) {
                                // Verify webhook params are excluded from cache key
                                $this->assertArrayNotHasKey('usePostback', $params);
                                $this->assertArrayNotHasKey('usePingback', $params);
                                $this->assertArrayNotHasKey('postTaskIfNotCached', $params);

                                return true;
                            }),
                            'POST',
                            'v3'
                        )
                        ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->once())
                        ->method('getCachedResponse')
                        ->willReturn(null);

        $client = new DataForSeoApiClient($mockCacheManager);

        $client->keywordsDataGoogleAdsSearchVolumeStandard(
            $keywords,
            null,
            null,
            2840,
            null,
            null,
            'en',
            false,
            null,
            null,
            false,
            'relevance',
            true,  // usePostback - should be excluded
            true, // usePingback - should be excluded
            false  // postTaskIfNotCached - should be excluded
        );
    }

    #[DataProvider('searchVolumeStandardParametersProvider')]
    public function test_keywords_data_google_ads_search_volume_standard_with_various_parameters($testParams, $expectedCacheKeyParams)
    {
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
                        ->method('generateCacheKey')
                        ->with(
                            'dataforseo',
                            'keywords_data/google_ads/search_volume/task_get',
                            $this->callback(function ($params) use ($expectedCacheKeyParams) {
                                // Check that all expected parameters are present with correct values
                                foreach ($expectedCacheKeyParams as $key => $value) {
                                    if (!isset($params[$key]) || $params[$key] !== $value) {
                                        return false;
                                    }
                                }
                                // Verify that webhook params are excluded
                                $this->assertArrayNotHasKey('usePostback', $params);
                                $this->assertArrayNotHasKey('usePingback', $params);
                                $this->assertArrayNotHasKey('postTaskIfNotCached', $params);

                                return true;
                            }),
                            'POST',
                            'v3'
                        )
                        ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->once())
                        ->method('getCachedResponse')
                        ->willReturn(null);

        $client = new DataForSeoApiClient($mockCacheManager);

        $client->keywordsDataGoogleAdsSearchVolumeStandard(...$testParams);
    }

    public static function searchVolumeStandardParametersProvider(): array
    {
        return [
            'basic parameters' => [
                [['test keyword'], null, 'United States', 2840, null, 'English', 'en', false, null, null, false, 'relevance', false, false, false],
                ['keywords' => ['test keyword'], 'location_name' => 'United States', 'location_code' => 2840, 'language_name' => 'English', 'language_code' => 'en', 'search_partners' => false, 'include_adult_keywords' => false, 'sort_by' => 'relevance'],
            ],
            'with location coordinate' => [
                [['test keyword'], null, null, 2840, '40.7128,-74.0060', null, 'en', false, null, null, false, 'relevance', false, false, false],
                ['keywords' => ['test keyword'], 'location_code' => 2840, 'location_coordinate' => '40.7128,-74.0060', 'language_code' => 'en', 'search_partners' => false, 'include_adult_keywords' => false, 'sort_by' => 'relevance'],
            ],
            'with date range' => [
                [['test keyword'], null, null, 2840, null, null, 'en', false, '2024-01-01', '2024-01-31', false, 'relevance', false, false, false],
                ['keywords' => ['test keyword'], 'location_code' => 2840, 'language_code' => 'en', 'search_partners' => false, 'date_from' => '2024-01-01', 'date_to' => '2024-01-31', 'include_adult_keywords' => false, 'sort_by' => 'relevance'],
            ],
            'with search partners and adult keywords' => [
                [['test keyword'], null, null, 2840, null, null, 'en', true, null, null, true, 'search_volume', false, false, false],
                ['keywords' => ['test keyword'], 'location_code' => 2840, 'language_code' => 'en', 'search_partners' => true, 'include_adult_keywords' => true, 'sort_by' => 'search_volume'],
            ],
        ];
    }

    public function test_keywords_data_google_ads_keywords_for_site_standard_returns_cached_data()
    {
        $target     = 'example.com';
        $cachedData = [
            'version'        => '0.1.20250612',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'tasks'          => [
                [
                    'id'     => 'cached-task-id',
                    'result' => [
                        ['keyword' => 'example keyword', 'search_volume' => 500],
                    ],
                ],
            ],
        ];

        // Mock cache manager to return cached data
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
                        ->method('generateCacheKey')
                        ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->once())
                        ->method('getCachedResponse')
                        ->with('dataforseo', 'test-cache-key')
                        ->willReturn($cachedData);

        $client = new DataForSeoApiClient($mockCacheManager);

        $result = $client->keywordsDataGoogleAdsKeywordsForSiteStandard($target, 'page', null, null, 2840, null, null, 'en', false, null, null, false, 'relevance', false, false, false);

        $this->assertEquals($cachedData, $result);
    }

    public function test_keywords_data_google_ads_keywords_for_site_standard_returns_null_when_not_cached_and_posting_disabled()
    {
        $target = 'example.com';

        // Mock cache manager to return null (not cached)
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
                        ->method('generateCacheKey')
                        ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->once())
                        ->method('getCachedResponse')
                        ->with('dataforseo', 'test-cache-key')
                        ->willReturn(null);

        $client = new DataForSeoApiClient($mockCacheManager);

        $result = $client->keywordsDataGoogleAdsKeywordsForSiteStandard(
            $target,
            'page',
            null,
            null,
            2840,
            null,
            null,
            'en',
            false,
            null,
            null,
            false,
            'relevance',
            false, // usePostback
            false, // usePingback
            false  // postTaskIfNotCached - disabled
        );

        $this->assertNull($result);
    }

    public function test_keywords_data_google_ads_keywords_for_site_standard_creates_task_when_not_cached_and_posting_enabled()
    {
        $target       = 'example.com';
        $taskId       = '01234567-89ab-cdef-0123-456789abcdef';
        $taskResponse = [
            'version'        => '0.1.20250612',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'tasks'          => [
                [
                    'id'     => $taskId,
                    'result' => ['task_id' => $taskId],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_site/task_post" => Http::response($taskResponse, 200),
        ]);

        // Mock cache manager to return null (not cached)
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->exactly(2))
                        ->method('generateCacheKey')
                        ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->exactly(2))
                        ->method('getCachedResponse')
                        ->with('dataforseo', 'test-cache-key')
                        ->willReturn(null);
        $mockCacheManager->expects($this->any())
                        ->method('allowRequest')
                        ->willReturn(true);
        $mockCacheManager->expects($this->any())
                        ->method('incrementAttempts');
        $mockCacheManager->expects($this->any())
                        ->method('storeResponse');

        // Reinitialize client so that its HTTP pending request picks up the fake
        $client = new DataForSeoApiClient($mockCacheManager);
        $client->clearRateLimit();

        $result = $client->keywordsDataGoogleAdsKeywordsForSiteStandard(
            $target,
            'page',
            null,
            null,
            2840,
            null,
            null,
            'en',
            false,
            null,
            null,
            false,
            'relevance',
            false, // usePostback
            false, // usePingback
            true   // postTaskIfNotCached - enabled
        );

        Http::assertSent(function ($request) use ($target) {
            $requestData = $request->data();

            return $request->url() === "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_site/task_post" &&
                   $request->method() === 'POST' &&
                   isset($requestData[0]['target']) &&
                   $requestData[0]['target'] === $target;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
    }

    public function test_keywords_data_google_ads_keywords_for_keywords_standard_returns_cached_data()
    {
        $keywords   = ['seo', 'marketing'];
        $cachedData = [
            'version'        => '0.1.20250612',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'tasks'          => [
                [
                    'id'     => 'cached-task-id',
                    'result' => [
                        ['keyword' => 'seo tools', 'search_volume' => 750],
                    ],
                ],
            ],
        ];

        // Mock cache manager to return cached data
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
                        ->method('generateCacheKey')
                        ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->once())
                        ->method('getCachedResponse')
                        ->with('dataforseo', 'test-cache-key')
                        ->willReturn($cachedData);

        $client = new DataForSeoApiClient($mockCacheManager);

        $result = $client->keywordsDataGoogleAdsKeywordsForKeywordsStandard($keywords, null, null, null, 2840, null, null, 'en', false, null, null, 'relevance', false, false, false, false);

        $this->assertEquals($cachedData, $result);
    }

    public function test_keywords_data_google_ads_keywords_for_keywords_standard_returns_null_when_not_cached_and_posting_disabled()
    {
        $keywords = ['seo', 'marketing'];

        // Mock cache manager to return null (not cached)
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
                        ->method('generateCacheKey')
                        ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->once())
                        ->method('getCachedResponse')
                        ->with('dataforseo', 'test-cache-key')
                        ->willReturn(null);

        $client = new DataForSeoApiClient($mockCacheManager);

        $result = $client->keywordsDataGoogleAdsKeywordsForKeywordsStandard(
            $keywords,
            null,
            null,
            null,
            2840,
            null,
            null,
            'en',
            false,
            null,
            null,
            'relevance',
            false,
            false, // usePostback
            false, // usePingback
            false  // postTaskIfNotCached - disabled
        );

        $this->assertNull($result);
    }

    public function test_keywords_data_google_ads_keywords_for_keywords_standard_creates_task_when_not_cached_and_posting_enabled()
    {
        $keywords     = ['seo', 'marketing'];
        $taskId       = '01234567-89ab-cdef-0123-456789abcdef';
        $taskResponse = [
            'version'        => '0.1.20250612',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'tasks'          => [
                [
                    'id'     => $taskId,
                    'result' => ['task_id' => $taskId],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_keywords/task_post" => Http::response($taskResponse, 200),
        ]);

        // Mock cache manager to return null (not cached)
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->exactly(2))
                        ->method('generateCacheKey')
                        ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->exactly(2))
                        ->method('getCachedResponse')
                        ->with('dataforseo', 'test-cache-key')
                        ->willReturn(null);
        $mockCacheManager->expects($this->any())
                        ->method('allowRequest')
                        ->willReturn(true);
        $mockCacheManager->expects($this->any())
                        ->method('incrementAttempts');
        $mockCacheManager->expects($this->any())
                        ->method('storeResponse');

        // Reinitialize client so that its HTTP pending request picks up the fake
        $client = new DataForSeoApiClient($mockCacheManager);
        $client->clearRateLimit();

        $result = $client->keywordsDataGoogleAdsKeywordsForKeywordsStandard(
            $keywords,
            null,
            null,
            null,
            2840,
            null,
            null,
            'en',
            false,
            null,
            null,
            'relevance',
            false,
            false, // usePostback
            false, // usePingback
            true   // postTaskIfNotCached - enabled
        );

        Http::assertSent(function ($request) use ($keywords) {
            $requestData = $request->data();

            return $request->url() === "{$this->apiBaseUrl}/keywords_data/google_ads/keywords_for_keywords/task_post" &&
                   $request->method() === 'POST' &&
                   isset($requestData[0]['keywords']) &&
                   $requestData[0]['keywords'] === $keywords;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
    }

    public function test_keywords_data_google_ads_ad_traffic_by_keywords_standard_returns_cached_data()
    {
        $keywords   = ['digital marketing'];
        $bid        = 2.50;
        $match      = 'exact';
        $cachedData = [
            'version'        => '0.1.20250612',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'tasks'          => [
                [
                    'id'     => 'cached-task-id',
                    'result' => [
                        ['keyword' => 'digital marketing', 'impressions' => 1500, 'clicks' => 150, 'cost' => 375.00],
                    ],
                ],
            ],
        ];

        // Mock cache manager to return cached data
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
                        ->method('generateCacheKey')
                        ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->once())
                        ->method('getCachedResponse')
                        ->with('dataforseo', 'test-cache-key')
                        ->willReturn($cachedData);

        $client = new DataForSeoApiClient($mockCacheManager);

        $result = $client->keywordsDataGoogleAdsAdTrafficByKeywordsStandard($keywords, $bid, $match, null, false, null, 2840, null, null, 'en', null, null, 'next_month', 'relevance', false, false, false);

        $this->assertEquals($cachedData, $result);
    }

    public function test_keywords_data_google_ads_ad_traffic_by_keywords_standard_returns_null_when_not_cached_and_posting_disabled()
    {
        $keywords = ['digital marketing'];
        $bid      = 2.50;
        $match    = 'exact';

        // Mock cache manager to return null (not cached)
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
                        ->method('generateCacheKey')
                        ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->once())
                        ->method('getCachedResponse')
                        ->with('dataforseo', 'test-cache-key')
                        ->willReturn(null);

        $client = new DataForSeoApiClient($mockCacheManager);

        $result = $client->keywordsDataGoogleAdsAdTrafficByKeywordsStandard(
            $keywords,
            $bid,
            $match,
            null,
            false,
            null,
            2840,
            null,
            null,
            'en',
            null,
            null,
            'next_month',
            'relevance',
            false, // usePostback
            false, // usePingback
            false  // postTaskIfNotCached - disabled
        );

        $this->assertNull($result);
    }

    public function test_keywords_data_google_ads_ad_traffic_by_keywords_standard_creates_task_when_not_cached_and_posting_enabled()
    {
        $keywords     = ['digital marketing'];
        $bid          = 2.50;
        $match        = 'exact';
        $taskId       = '01234567-89ab-cdef-0123-456789abcdef';
        $taskResponse = [
            'version'        => '0.1.20250612',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'tasks'          => [
                [
                    'id'     => $taskId,
                    'result' => ['task_id' => $taskId],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/keywords_data/google_ads/ad_traffic_by_keywords/task_post" => Http::response($taskResponse, 200),
        ]);

        // Mock cache manager to return null (not cached)
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->exactly(2))
                        ->method('generateCacheKey')
                        ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->exactly(2))
                        ->method('getCachedResponse')
                        ->with('dataforseo', 'test-cache-key')
                        ->willReturn(null);
        $mockCacheManager->expects($this->any())
                        ->method('allowRequest')
                        ->willReturn(true);
        $mockCacheManager->expects($this->any())
                        ->method('incrementAttempts');
        $mockCacheManager->expects($this->any())
                        ->method('storeResponse');

        // Reinitialize client so that its HTTP pending request picks up the fake
        $client = new DataForSeoApiClient($mockCacheManager);
        $client->clearRateLimit();

        $result = $client->keywordsDataGoogleAdsAdTrafficByKeywordsStandard(
            $keywords,
            $bid,
            $match,
            null,
            false,
            null,
            2840,
            null,
            null,
            'en',
            null,
            null,
            'next_month',
            'relevance',
            false, // usePostback
            false, // usePingback
            true   // postTaskIfNotCached - enabled
        );

        Http::assertSent(function ($request) use ($keywords, $bid, $match) {
            $requestData = $request->data();

            return $request->url() === "{$this->apiBaseUrl}/keywords_data/google_ads/ad_traffic_by_keywords/task_post" &&
                   $request->method() === 'POST' &&
                   isset($requestData[0]['keywords']) &&
                   $requestData[0]['keywords'] === $keywords &&
                   $requestData[0]['bid'] === $bid &&
                   $requestData[0]['match'] === $match;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertEquals($taskId, $responseData['tasks'][0]['id']);
    }

    #[DataProvider('adTrafficStandardParametersProvider')]
    public function test_keywords_data_google_ads_ad_traffic_by_keywords_standard_with_various_parameters($testParams, $expectedCacheKeyParams)
    {
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
                        ->method('generateCacheKey')
                        ->with(
                            'dataforseo',
                            'keywords_data/google_ads/ad_traffic_by_keywords/task_get',
                            $this->callback(function ($params) use ($expectedCacheKeyParams) {
                                // Check that all expected parameters are present with correct values
                                foreach ($expectedCacheKeyParams as $key => $value) {
                                    if (!isset($params[$key]) || $params[$key] !== $value) {
                                        return false;
                                    }
                                }
                                // Verify that webhook params are excluded
                                $this->assertArrayNotHasKey('usePostback', $params);
                                $this->assertArrayNotHasKey('usePingback', $params);
                                $this->assertArrayNotHasKey('postTaskIfNotCached', $params);

                                return true;
                            }),
                            'POST',
                            'v3'
                        )
                        ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->once())
                        ->method('getCachedResponse')
                        ->willReturn(null);

        $client = new DataForSeoApiClient($mockCacheManager);

        $client->keywordsDataGoogleAdsAdTrafficByKeywordsStandard(...$testParams);
    }

    public static function adTrafficStandardParametersProvider(): array
    {
        return [
            'basic parameters' => [
                [['test keyword'], 2.50, 'exact', null, false, null, 2840, null, null, 'en', null, null, 'next_month', 'relevance', false, false, false],
                ['keywords' => ['test keyword'], 'bid' => 2.50, 'match' => 'exact', 'search_partners' => false, 'location_code' => 2840, 'language_code' => 'en', 'date_interval' => 'next_month', 'sort_by' => 'relevance'],
            ],
            'with search partners and location name' => [
                [['test keyword'], 1.75, 'broad', null, true, 'United States', null, null, null, 'en', null, null, 'next_quarter', 'impressions', false, false, false],
                ['keywords' => ['test keyword'], 'bid' => 1.75, 'match' => 'broad', 'search_partners' => true, 'location_name' => 'United States', 'language_code' => 'en', 'date_interval' => 'next_quarter', 'sort_by' => 'impressions'],
            ],
            'with date range instead of interval' => [
                [['test keyword'], 3.00, 'phrase', null, false, null, 2840, null, null, 'en', '2024-01-01', '2024-01-31', null, 'cost', false, false, false],
                ['keywords' => ['test keyword'], 'bid' => 3.00, 'match' => 'phrase', 'search_partners' => false, 'location_code' => 2840, 'language_code' => 'en', 'date_from' => '2024-01-01', 'date_to' => '2024-01-31', 'sort_by' => 'cost'],
            ],
        ];
    }

    public function test_all_standard_methods_exclude_webhook_params_from_cache_key()
    {
        // Test each method individually with proper parameter counts
        $this->test_search_volume_standard_excludes_webhook_params();
        $this->test_keywords_for_site_standard_excludes_webhook_params();
        $this->test_keywords_for_keywords_standard_excludes_webhook_params();
        $this->test_ad_traffic_standard_excludes_webhook_params();
    }

    private function test_search_volume_standard_excludes_webhook_params()
    {
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
                        ->method('generateCacheKey')
                        ->with(
                            'dataforseo',
                            'keywords_data/google_ads/search_volume/task_get',
                            $this->callback(function ($params) {
                                $this->assertArrayNotHasKey('usePostback', $params);
                                $this->assertArrayNotHasKey('usePingback', $params);
                                $this->assertArrayNotHasKey('postTaskIfNotCached', $params);

                                return true;
                            }),
                            'POST',
                            'v3'
                        )
                        ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->once())
                        ->method('getCachedResponse')
                        ->willReturn(null);

        $client = new DataForSeoApiClient($mockCacheManager);
        $client->keywordsDataGoogleAdsSearchVolumeStandard(
            ['test keyword'],
            null,
            null,
            2840,
            null,
            null,
            'en',
            false,
            null,
            null,
            false,
            'relevance',
            true,
            true,
            false
        );
    }

    private function test_keywords_for_site_standard_excludes_webhook_params()
    {
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
                        ->method('generateCacheKey')
                        ->with(
                            'dataforseo',
                            'keywords_data/google_ads/keywords_for_site/task_get',
                            $this->callback(function ($params) {
                                $this->assertArrayNotHasKey('usePostback', $params);
                                $this->assertArrayNotHasKey('usePingback', $params);
                                $this->assertArrayNotHasKey('postTaskIfNotCached', $params);

                                return true;
                            }),
                            'POST',
                            'v3'
                        )
                        ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->once())
                        ->method('getCachedResponse')
                        ->willReturn(null);

        $client = new DataForSeoApiClient($mockCacheManager);
        $client->keywordsDataGoogleAdsKeywordsForSiteStandard(
            'example.com',
            'page',
            null,
            null,
            2840,
            null,
            null,
            'en',
            false,
            null,
            null,
            false,
            'relevance',
            true,
            true,
            false
        );
    }

    private function test_keywords_for_keywords_standard_excludes_webhook_params()
    {
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
                        ->method('generateCacheKey')
                        ->with(
                            'dataforseo',
                            'keywords_data/google_ads/keywords_for_keywords/task_get',
                            $this->callback(function ($params) {
                                $this->assertArrayNotHasKey('usePostback', $params);
                                $this->assertArrayNotHasKey('usePingback', $params);
                                $this->assertArrayNotHasKey('postTaskIfNotCached', $params);

                                return true;
                            }),
                            'POST',
                            'v3'
                        )
                        ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->once())
                        ->method('getCachedResponse')
                        ->willReturn(null);

        $client = new DataForSeoApiClient($mockCacheManager);
        $client->keywordsDataGoogleAdsKeywordsForKeywordsStandard(
            ['test keyword'],
            null,
            null,
            null,
            2840,
            null,
            null,
            'en',
            false,
            null,
            null,
            'relevance',
            false,
            true,
            true,
            false
        );
    }

    private function test_ad_traffic_standard_excludes_webhook_params()
    {
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
                        ->method('generateCacheKey')
                        ->with(
                            'dataforseo',
                            'keywords_data/google_ads/ad_traffic_by_keywords/task_get',
                            $this->callback(function ($params) {
                                $this->assertArrayNotHasKey('usePostback', $params);
                                $this->assertArrayNotHasKey('usePingback', $params);
                                $this->assertArrayNotHasKey('postTaskIfNotCached', $params);

                                return true;
                            }),
                            'POST',
                            'v3'
                        )
                        ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->once())
                        ->method('getCachedResponse')
                        ->willReturn(null);

        $client = new DataForSeoApiClient($mockCacheManager);
        $client->keywordsDataGoogleAdsAdTrafficByKeywordsStandard(
            ['test keyword'],
            2.50,
            'exact',
            null,
            false,
            null,
            2840,
            null,
            null,
            'en',
            null,
            null,
            'next_month',
            'relevance',
            true,
            true,
            false
        );
    }
}

<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\PixabayApiClient;
use FOfX\ApiCache\RateLimitException;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PixabayApiClientTest extends TestCase
{
    use RefreshDatabase;

    protected PixabayApiClient $client;
    protected string $apiKey     = 'test-api-key';
    protected string $apiBaseUrl = 'https://pixabay.com';
    protected array $defaultSearchParams;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure test environment
        Config::set('api-cache.apis.pixabay.api_key', $this->apiKey);
        Config::set('api-cache.apis.pixabay.base_url', $this->apiBaseUrl);
        Config::set('api-cache.apis.pixabay.rate_limit_max_attempts', 5);
        Config::set('api-cache.apis.pixabay.rate_limit_decay_seconds', 10);

        $this->defaultSearchParams = [
            'lang'           => 'en',
            'image_type'     => 'all',
            'orientation'    => 'all',
            'min_width'      => 0,
            'min_height'     => 0,
            'editors_choice' => false,
            'safesearch'     => false,
            'order'          => 'popular',
            'page'           => 1,
            'per_page'       => 20,
        ];

        $this->client = new PixabayApiClient();
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
        $this->assertEquals('pixabay', $this->client->getClientName());
        $this->assertEquals($this->apiBaseUrl, $this->client->getBaseUrl());
        $this->assertEquals($this->apiKey, $this->client->getApiKey());
        $this->assertNull($this->client->getVersion());
    }

    public function test_makes_successful_image_search_request()
    {
        Http::fake([
            '*' => Http::response([
                'total'     => 334263,
                'totalHits' => 500,
                'hits'      => [
                    [
                        'id'            => 7679117,
                        'pageURL'       => 'https://pixabay.com/photos/flower-stamens-hypericum-macro-7679117/',
                        'type'          => 'photo',
                        'tags'          => 'flower, flower background, stamens',
                        'previewURL'    => 'https://cdn.pixabay.com/photo/2022/12/26/13/50/flower-7679117_150.jpg',
                        'webformatURL'  => 'https://pixabay.com/get/sample_640.jpg',
                        'largeImageURL' => 'https://pixabay.com/get/sample_1280.jpg',
                        'imageWidth'    => 6000,
                        'imageHeight'   => 4000,
                        'imageSize'     => 8137356,
                        'views'         => 28153,
                        'downloads'     => 21728,
                        'likes'         => 122,
                        'user'          => 'test_user',
                    ],
                ],
            ], 200, [
                'X-RateLimit-Limit'     => '100',
                'X-RateLimit-Remaining' => '99',
                'X-RateLimit-Reset'     => '60',
            ]),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new PixabayApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->searchImages('yellow flowers');

        Http::assertSent(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), "{$this->apiBaseUrl}/api") &&
                   $request->method() === 'GET' &&
                   $query['key'] === $this->apiKey &&
                   $query['q'] === 'yellow flowers' &&
                   $query['lang'] === $this->defaultSearchParams['lang'] &&
                   $query['image_type'] === $this->defaultSearchParams['image_type'] &&
                   $query['orientation'] === $this->defaultSearchParams['orientation'] &&
                   (string)$query['min_width'] === (string)$this->defaultSearchParams['min_width'] &&
                   (string)$query['min_height'] === (string)$this->defaultSearchParams['min_height'] &&
                   $query['editors_choice'] === '0' &&
                   $query['safesearch'] === '0' &&
                   $query['order'] === $this->defaultSearchParams['order'] &&
                   (string)$query['page'] === (string)$this->defaultSearchParams['page'] &&
                   (string)$query['per_page'] === (string)$this->defaultSearchParams['per_page'];
        });

        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('total', $responseData);
        $this->assertArrayHasKey('totalHits', $responseData);
        $this->assertArrayHasKey('hits', $responseData);
        $this->assertEquals(334263, $responseData['total']);
        $this->assertEquals(500, $responseData['totalHits']);
        $this->assertCount(1, $responseData['hits']);
        $this->assertEquals(7679117, $responseData['hits'][0]['id']);
    }

    public function test_caches_responses()
    {
        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'total'     => 100,
                    'totalHits' => 50,
                    'hits'      => [['id' => 1]],
                ], 200)
                ->push([
                    'total'     => 200,
                    'totalHits' => 100,
                    'hits'      => [['id' => 2]],
                ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new PixabayApiClient();
        $this->client->clearRateLimit();

        // First request should hit the API
        $response1     = $this->client->searchImages('test');
        $responseData1 = $response1['response']->json();
        $this->assertArrayHasKey('hits', $responseData1);
        $this->assertEquals(1, $responseData1['hits'][0]['id'] ?? null);

        // Second request with same parameters should return cached response
        $response2     = $this->client->searchImages('test');
        $responseData2 = $response2['response']->json();
        $this->assertArrayHasKey('hits', $responseData2);
        $this->assertEquals(1, $responseData2['hits'][0]['id'] ?? null);

        // Verify caching behavior
        $this->assertEquals(200, $response1['response_status_code']);
        $this->assertFalse($response1['is_cached']);
        $this->assertEquals(200, $response2['response_status_code']);
        $this->assertTrue($response2['is_cached']);

        // Only one request should have been made
        Http::assertSentCount(1);
    }

    public function test_enforces_rate_limits()
    {
        Http::fake([
            "{$this->apiBaseUrl}/api*" => Http::response([
                'total' => 100,
                'hits'  => [['id' => 1]],
            ], 200),
        ]);

        $this->expectException(RateLimitException::class);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new PixabayApiClient();
        $this->client->clearRateLimit();
        $this->client->setUseCache(false);

        // Make requests until rate limit is exceeded
        for ($i = 0; $i <= 5; $i++) {
            $this->client->searchImages("test {$i}");
        }
    }

    public function test_handles_api_errors()
    {
        Http::fake([
            "{$this->apiBaseUrl}/api*" => Http::response([
                'error' => 'Invalid API key',
            ], 400),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new PixabayApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->searchImages('test');

        $this->assertEquals(400, $response['response']->status());
        $this->assertEquals('Invalid API key', $response['response']->json()['error']);
    }

    public function test_handles_search_with_custom_parameters()
    {
        Http::fake([
            '*' => Http::response([
                'total' => 100,
                'hits'  => [['id' => 1]],
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new PixabayApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->searchImages(
            'test',
            'fr',
            null,
            'photo',
            'horizontal',
            'nature',
            1920,
            1080,
            'red,blue',
            true,
            true,
            'latest',
            2,
            50
        );

        Http::assertSent(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), "{$this->apiBaseUrl}/api") &&
                   $request->method() === 'GET' &&
                   $query['lang'] === 'fr' &&
                   $query['image_type'] === 'photo' &&
                   $query['orientation'] === 'horizontal' &&
                   $query['category'] === 'nature' &&
                   (string)$query['min_width'] === '1920' &&
                   (string)$query['min_height'] === '1080' &&
                   $query['colors'] === 'red,blue' &&
                   $query['editors_choice'] === '1' &&
                   $query['safesearch'] === '1' &&
                   $query['order'] === 'latest' &&
                   (string)$query['page'] === '2' &&
                   (string)$query['per_page'] === '50';
        });
    }

    public function test_auth_headers_and_params()
    {
        // Test that auth headers are empty (no Bearer token)
        $this->assertEmpty($this->client->getAuthHeaders());

        // Test that auth params contain the API key
        $authParams = $this->client->getAuthParams();
        $this->assertArrayHasKey('key', $authParams);
        $this->assertEquals($this->apiKey, $authParams['key']);
    }

    public function test_processResponses()
    {
        // Arrange
        $responseBody = json_encode([
            'hits' => [
                [
                    'id'           => 123,
                    'pageURL'      => 'https://example.com/123',
                    'previewURL'   => 'https://example.com/preview/123',
                    'webformatURL' => 'https://example.com/web/123',
                    'user'         => 'testuser',
                    'views'        => 100,
                    'downloads'    => 50,
                ],
                [
                    'id'           => 456,
                    'pageURL'      => 'https://example.com/456',
                    'previewURL'   => 'https://example.com/preview/456',
                    'webformatURL' => 'https://example.com/web/456',
                    'user'         => 'testuser2',
                    'views'        => 200,
                    'downloads'    => 100,
                ],
            ],
        ]);

        $now = now();

        // Insert test response
        DB::table('api_cache_pixabay_responses')->insert([
            'key'                  => 'test_key_1',
            'client'               => 'pixabay',
            'endpoint'             => 'api',
            'response_status_code' => 200,
            'response_body'        => $responseBody,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);

        // Act
        $result = $this->client->processResponses(1);

        // Assert
        $this->assertEquals(2, $result['processed']);
        $this->assertEquals(0, $result['duplicates']);

        // Verify images were inserted
        $this->assertDatabaseHas('api_cache_pixabay_images', [
            'id'      => 123,
            'pageURL' => 'https://example.com/123',
            'user'    => 'testuser',
        ]);

        $this->assertDatabaseHas('api_cache_pixabay_images', [
            'id'      => 456,
            'pageURL' => 'https://example.com/456',
            'user'    => 'testuser2',
        ]);

        // Verify response was marked as processed
        $this->assertDatabaseHas('api_cache_pixabay_responses', [
            'key'          => 'test_key_1',
            'endpoint'     => 'api',
            'processed_at' => $now,
        ]);
    }

    public function test_processResponses_handles_empty_hits_array()
    {
        // Arrange
        $responseBody = json_encode([
            'total'     => 0,
            'totalHits' => 0,
            'hits'      => [],
        ]);

        $now = now();

        // Insert test response
        DB::table('api_cache_pixabay_responses')->insert([
            'key'                  => 'test_key_2',
            'client'               => 'pixabay',
            'endpoint'             => 'api',
            'response_status_code' => 200,
            'response_body'        => $responseBody,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);

        // Act
        $result = $this->client->processResponses(1);

        // Assert
        $this->assertEquals(0, $result['processed']);
        $this->assertEquals(0, $result['duplicates']);

        // Verify no images were inserted
        $this->assertDatabaseCount('api_cache_pixabay_images', 0);

        // Verify response was marked as processed
        $this->assertDatabaseHas('api_cache_pixabay_responses', [
            'key'          => 'test_key_2',
            'endpoint'     => 'api',
            'processed_at' => $now,
        ]);
    }

    public function test_processResponses_handles_invalid_response_body()
    {
        // Arrange
        $responseBody = 'invalid json';

        $now = now();

        // Insert test response
        DB::table('api_cache_pixabay_responses')->insert([
            'key'                  => 'test_key_3',
            'client'               => 'pixabay',
            'endpoint'             => 'api',
            'response_status_code' => 200,
            'response_body'        => $responseBody,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);

        // Act
        $result = $this->client->processResponses(1);

        // Assert
        $this->assertEquals(0, $result['processed']);
        $this->assertEquals(0, $result['duplicates']);

        // Verify no images were inserted
        $this->assertDatabaseCount('api_cache_pixabay_images', 0);
    }
}

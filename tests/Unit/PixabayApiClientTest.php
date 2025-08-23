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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PixabayApiClientTest extends TestCase
{
    protected string $imagesTableName = 'pixabay_images';
    protected PixabayApiClient $client;
    protected string $apiKey     = 'test-api-key';
    protected string $apiBaseUrl = 'https://pixabay.com';
    protected array $defaultSearchParams;

    protected function setUp(): void
    {
        parent::setUp();

        // Fake the public storage disk for clean test isolation
        Storage::fake('public');

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

            return Str::startsWith($request->url(), "{$this->apiBaseUrl}/api") &&
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

        // Make sure we used the Http::fake() response
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

        // Make sure we used the Http::fake() response
        $responseData1 = $response1['response']->json();
        $this->assertEquals(50, $responseData1['totalHits']);

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
            $result = $this->client->searchImages("test {$i}");

            // Make sure we used the Http::fake() response
            $responseData = $result['response']->json();
            $this->assertEquals(100, $responseData['total']);
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

        // Make sure we used the Http::fake() response
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

        // Make sure we used the Http::fake() response
        Http::assertSent(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return Str::startsWith($request->url(), "{$this->apiBaseUrl}/api") &&
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

    public function test_resetProcessed()
    {
        // Arrange
        $now          = now();
        $responseBody = json_encode(['hits' => [['id' => 123]]]);

        // Insert test responses with processed status
        DB::table('api_cache_pixabay_responses')->insert([
            [
                'key'                  => 'test_key_1',
                'client'               => 'pixabay',
                'endpoint'             => 'api',
                'response_status_code' => 200,
                'response_body'        => $responseBody,
                'processed_at'         => $now,
                'processed_status'     => json_encode(['status' => 'OK']),
                'created_at'           => $now,
                'updated_at'           => $now,
            ],
            [
                'key'                  => 'test_key_2',
                'client'               => 'pixabay',
                'endpoint'             => 'api/videos',
                'response_status_code' => 200,
                'response_body'        => $responseBody,
                'processed_at'         => $now,
                'processed_status'     => json_encode(['status' => 'OK']),
                'created_at'           => $now,
                'updated_at'           => $now,
            ],
        ]);

        // Act
        $this->client->resetProcessed('api');

        // Assert - only 'api' endpoint should be reset
        $this->assertDatabaseHas('api_cache_pixabay_responses', [
            'key'              => 'test_key_1',
            'endpoint'         => 'api',
            'processed_at'     => null,
            'processed_status' => null,
        ]);

        // Video endpoint should remain unchanged
        $this->assertDatabaseHas('api_cache_pixabay_responses', [
            'key'              => 'test_key_2',
            'endpoint'         => 'api/videos',
            'processed_at'     => $now,
            'processed_status' => json_encode(['status' => 'OK']),
        ]);
    }

    public function test_clearProcessedTable()
    {
        // Arrange
        $now = now();

        // Insert test images
        DB::table($this->imagesTableName)->insert([
            [
                'id'         => 123,
                'pageURL'    => 'https://example.com/123',
                'user'       => 'testuser1',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id'         => 456,
                'pageURL'    => 'https://example.com/456',
                'user'       => 'testuser2',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Verify data exists
        $this->assertDatabaseCount($this->imagesTableName, 2);

        // Act - default behavior now returns null (no counting)
        $count = $this->client->clearProcessedTable();

        // Assert
        $this->assertNull($count);
        $this->assertDatabaseCount($this->imagesTableName, 0);
    }

    public function test_clearProcessedTable_with_count_true()
    {
        // Arrange
        $now = now();

        // Insert test images
        DB::table($this->imagesTableName)->insert([
            [
                'id'         => 789,
                'pageURL'    => 'https://example.com/789',
                'user'       => 'testuser3',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id'         => 101112,
                'pageURL'    => 'https://example.com/101112',
                'user'       => 'testuser4',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id'         => 131415,
                'pageURL'    => 'https://example.com/131415',
                'user'       => 'testuser5',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Verify data exists
        $this->assertDatabaseCount($this->imagesTableName, 3);

        // Act
        $count = $this->client->clearProcessedTable(true);

        // Assert
        $this->assertEquals(3, $count);
        $this->assertDatabaseCount($this->imagesTableName, 0);
    }

    public function test_clearProcessedTable_with_count_false()
    {
        // Arrange
        $now = now();

        // Insert test images
        DB::table($this->imagesTableName)->insert([
            [
                'id'         => 161718,
                'pageURL'    => 'https://example.com/161718',
                'user'       => 'testuser6',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id'         => 192021,
                'pageURL'    => 'https://example.com/192021',
                'user'       => 'testuser7',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Verify data exists
        $this->assertDatabaseCount($this->imagesTableName, 2);

        // Act
        $count = $this->client->clearProcessedTable(false);

        // Assert
        $this->assertNull($count); // Should return null when withCount is false
        $this->assertDatabaseCount($this->imagesTableName, 0); // Table should still be cleared
    }

    public function test_resetFileInfo()
    {
        // Arrange
        $now = now();

        // Insert test images with file info
        DB::table($this->imagesTableName)->insert([
            [
                'id'                       => 111222,
                'pageURL'                  => 'https://example.com/111222',
                'user'                     => 'testuser1',
                'file_contents_preview'    => 'preview file content',
                'file_contents_webformat'  => 'webformat file content',
                'file_contents_largeImage' => 'large image file content',
                'filesize_preview'         => 1024,
                'filesize_webformat'       => 2048,
                'filesize_largeImage'      => 4096,
                'created_at'               => $now,
                'updated_at'               => $now,
            ],
            [
                'id'                       => 333444,
                'pageURL'                  => 'https://example.com/333444',
                'user'                     => 'testuser2',
                'file_contents_preview'    => 'another preview content',
                'file_contents_webformat'  => 'another webformat content',
                'file_contents_largeImage' => 'another large content',
                'filesize_preview'         => 512,
                'filesize_webformat'       => 1536,
                'filesize_largeImage'      => 3072,
                'created_at'               => $now,
                'updated_at'               => $now,
            ],
        ]);

        // Verify data exists with file info
        $this->assertDatabaseHas($this->imagesTableName, [
            'id'                       => 111222,
            'file_contents_preview'    => 'preview file content',
            'file_contents_webformat'  => 'webformat file content',
            'file_contents_largeImage' => 'large image file content',
            'filesize_preview'         => 1024,
            'filesize_webformat'       => 2048,
            'filesize_largeImage'      => 4096,
        ]);

        // Act
        $updatedCount = $this->client->resetFileInfo();

        // Assert
        $this->assertEquals(2, $updatedCount);

        // Verify file info is reset to null
        $this->assertDatabaseHas($this->imagesTableName, [
            'id'                       => 111222,
            'file_contents_preview'    => null,
            'file_contents_webformat'  => null,
            'file_contents_largeImage' => null,
            'filesize_preview'         => null,
            'filesize_webformat'       => null,
            'filesize_largeImage'      => null,
        ]);

        $this->assertDatabaseHas($this->imagesTableName, [
            'id'                       => 333444,
            'file_contents_preview'    => null,
            'file_contents_webformat'  => null,
            'file_contents_largeImage' => null,
            'filesize_preview'         => null,
            'filesize_webformat'       => null,
            'filesize_largeImage'      => null,
        ]);
    }

    public function test_resetStorageFilepaths()
    {
        // Arrange
        $now = now();

        // Insert test images with storage filepaths
        DB::table($this->imagesTableName)->insert([
            [
                'id'                          => 123456,
                'pageURL'                     => 'https://example.com/123456',
                'user'                        => 'testuser1',
                'storage_filepath_preview'    => '/path/to/preview.jpg',
                'storage_filepath_webformat'  => '/path/to/webformat.jpg',
                'storage_filepath_largeImage' => '/path/to/large.jpg',
                'created_at'                  => $now,
                'updated_at'                  => $now,
            ],
            [
                'id'                          => 789012,
                'pageURL'                     => 'https://example.com/789012',
                'user'                        => 'testuser2',
                'storage_filepath_preview'    => '/path/to/preview2.jpg',
                'storage_filepath_webformat'  => '/path/to/webformat2.jpg',
                'storage_filepath_largeImage' => '/path/to/large2.jpg',
                'created_at'                  => $now,
                'updated_at'                  => $now,
            ],
        ]);

        // Verify data exists with filepaths
        $this->assertDatabaseHas($this->imagesTableName, [
            'id'                          => 123456,
            'storage_filepath_preview'    => '/path/to/preview.jpg',
            'storage_filepath_webformat'  => '/path/to/webformat.jpg',
            'storage_filepath_largeImage' => '/path/to/large.jpg',
        ]);

        // Act
        $updatedCount = $this->client->resetStorageFilepaths();

        // Assert
        $this->assertEquals(2, $updatedCount);

        // Verify filepaths are reset to null
        $this->assertDatabaseHas($this->imagesTableName, [
            'id'                          => 123456,
            'storage_filepath_preview'    => null,
            'storage_filepath_webformat'  => null,
            'storage_filepath_largeImage' => null,
        ]);

        $this->assertDatabaseHas($this->imagesTableName, [
            'id'                          => 789012,
            'storage_filepath_preview'    => null,
            'storage_filepath_webformat'  => null,
            'storage_filepath_largeImage' => null,
        ]);
    }

    public function test_resetImagesFolder()
    {
        // Arrange - create test files using Storage facade
        $testFiles = [
            'test_images/test1.jpg',
            'test_images/test2.png',
            'test_images/test3.gif',
        ];

        foreach ($testFiles as $file) {
            Storage::disk('public')->put($file, 'test content');
        }

        // Verify files exist
        foreach ($testFiles as $file) {
            $this->assertTrue(Storage::disk('public')->exists($file));
        }

        // Act
        $result = $this->client->resetImagesFolder('test_images');

        // Assert
        $this->assertTrue($result);

        // Verify files are deleted
        foreach ($testFiles as $file) {
            $this->assertFalse(Storage::disk('public')->exists($file));
        }
    }

    public function test_resetImagesFolder_with_default_path()
    {
        // Arrange - create files in default path using Storage facade
        $testFiles = [
            'images/default1.jpg',
            'images/default2.png',
        ];

        foreach ($testFiles as $file) {
            Storage::disk('public')->put($file, 'default test content');
        }

        // Verify files exist
        foreach ($testFiles as $file) {
            $this->assertTrue(Storage::disk('public')->exists($file));
        }

        // Act
        $result = $this->client->resetImagesFolder();

        // Assert
        $this->assertTrue($result);

        // Verify files are deleted
        foreach ($testFiles as $file) {
            $this->assertFalse(Storage::disk('public')->exists($file));
        }
    }

    public function test_resetImagesFolder_with_nonexistent_directory()
    {
        // Act - try to reset a directory that doesn't exist
        $result = $this->client->resetImagesFolder('nonexistent_folder');

        // Assert - should return true for nonexistent directory
        $this->assertTrue($result);
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
        $this->assertDatabaseHas($this->imagesTableName, [
            'id'      => 123,
            'pageURL' => 'https://example.com/123',
            'user'    => 'testuser',
        ]);

        $this->assertDatabaseHas($this->imagesTableName, [
            'id'      => 456,
            'pageURL' => 'https://example.com/456',
            'user'    => 'testuser2',
        ]);

        // Verify response was marked as processed
        $this->assertDatabaseHas('api_cache_pixabay_responses', [
            'key'              => 'test_key_1',
            'endpoint'         => 'api',
            'processed_at'     => $now,
            'processed_status' => json_encode([
                'status'     => 'OK',
                'error'      => null,
                'processed'  => 2,
                'duplicates' => 0,
            ], JSON_PRETTY_PRINT),
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
        $this->assertDatabaseCount($this->imagesTableName, 0);

        // Verify response was marked as processed
        $this->assertDatabaseHas('api_cache_pixabay_responses', [
            'key'              => 'test_key_2',
            'endpoint'         => 'api',
            'processed_at'     => $now,
            'processed_status' => json_encode([
                'status'     => 'OK',
                'error'      => null,
                'processed'  => 0,
                'duplicates' => 0,
            ], JSON_PRETTY_PRINT),
        ]);
    }

    public function test_processResponses_handles_invalid_json_response()
    {
        // Arrange
        $responseBody = '{"hits": [{"id": 123, "pageURL": "https://example.com/123"}], invalid json}';
        $now          = now();

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
        $this->assertDatabaseCount($this->imagesTableName, 0);

        // Verify response was marked as processed with error
        $this->assertDatabaseHas('api_cache_pixabay_responses', [
            'key'              => 'test_key_3',
            'endpoint'         => 'api',
            'processed_status' => json_encode([
                'status'     => 'ERROR',
                'error'      => 'Failed to decode response body: Syntax error',
                'processed'  => 0,
                'duplicates' => 0,
            ], JSON_PRETTY_PRINT),
        ]);

        // Verify processed_at is not null
        $this->assertNotNull(
            DB::table('api_cache_pixabay_responses')
                ->where('key', 'test_key_3')
                ->value('processed_at')
        );
    }

    public function test_downloadImage_downloads_specific_image_type()
    {
        // Arrange
        $imageId   = 4384750;
        $imageType = 'preview';
        $now       = now();
        $imageData = 'fake image data';

        // Insert test image
        DB::table($this->imagesTableName)->insert([
            'id'            => $imageId,
            'previewURL'    => 'https://example.com/preview.jpg',
            'webformatURL'  => 'https://example.com/webformat.jpg',
            'largeImageURL' => 'https://example.com/large.jpg',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        // Mock HTTP response
        Http::fake([
            'https://example.com/preview.jpg' => Http::response($imageData, 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        // Act
        $downloadedCount = $this->client->downloadImage($imageId, $imageType);

        // Assert
        $this->assertEquals(1, $downloadedCount);

        // Verify database has the Http::fake() response $imageData
        $this->assertDatabaseHas($this->imagesTableName, [
            'id'                    => $imageId,
            'file_contents_preview' => $imageData,
            'filesize_preview'      => strlen($imageData),
        ]);
    }

    public function test_downloadImage_downloads_next_undownloaded_image()
    {
        // Arrange
        $imageId   = 4384750;
        $imageType = 'webformat';
        $now       = now();
        $imageData = 'fake image data';

        // Insert test image
        DB::table($this->imagesTableName)->insert([
            'id'            => $imageId,
            'previewURL'    => 'https://example.com/preview.jpg',
            'webformatURL'  => 'https://example.com/webformat.jpg',
            'largeImageURL' => 'https://example.com/large.jpg',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        // Mock HTTP response
        Http::fake([
            'https://example.com/webformat.jpg' => Http::response($imageData, 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        // Act
        $downloadedCount = $this->client->downloadImage(null, $imageType);

        // Assert
        $this->assertEquals(1, $downloadedCount);

        // Verify database has the Http::fake() response $imageData
        $this->assertDatabaseHas($this->imagesTableName, [
            'id'                      => $imageId,
            'file_contents_webformat' => $imageData,
            'filesize_webformat'      => strlen($imageData),
        ]);
    }

    public function test_downloadImage_downloads_all_types()
    {
        // Arrange
        $imageId = 4384750;
        $now     = now();

        // Insert test image
        DB::table($this->imagesTableName)->insert([
            'id'            => $imageId,
            'previewURL'    => 'https://example.com/preview.jpg',
            'webformatURL'  => 'https://example.com/webformat.jpg',
            'largeImageURL' => 'https://example.com/large.jpg',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        // Mock HTTP responses
        Http::fake([
            'https://example.com/preview.jpg' => Http::response('preview data', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
            'https://example.com/webformat.jpg' => Http::response('webformat data', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
            'https://example.com/large.jpg' => Http::response('large data', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        // Act
        $downloadedCount = $this->client->downloadImage($imageId, 'all');

        // Assert
        $this->assertEquals(3, $downloadedCount);

        // Verify database has the Http::fake() responses
        $this->assertDatabaseHas($this->imagesTableName, [
            'id'                       => $imageId,
            'file_contents_preview'    => 'preview data',
            'filesize_preview'         => strlen('preview data'),
            'file_contents_webformat'  => 'webformat data',
            'filesize_webformat'       => strlen('webformat data'),
            'file_contents_largeImage' => 'large data',
            'filesize_largeImage'      => strlen('large data'),
        ]);
    }

    public function test_downloadImage_throws_exception_for_invalid_type()
    {
        // Arrange
        $imageId     = 4384750;
        $invalidType = 'invalid_type';

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid image type. Must be one of: preview, webformat, largeImage, all');

        // Act
        $this->client->downloadImage($imageId, $invalidType);
    }

    public function test_downloadImage_throws_exception_for_invalid_id()
    {
        // Arrange
        $invalidId = 999999;

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Image not found with ID: $invalidId");

        // Act
        $this->client->downloadImage($invalidId, 'preview');
    }

    public function test_downloadImage_skips_already_downloaded_types()
    {
        // Arrange
        $imageId = 4384750;
        $now     = now();

        // Insert test image with some types already downloaded
        DB::table($this->imagesTableName)->insert([
            'id'                    => $imageId,
            'previewURL'            => 'https://example.com/preview.jpg',
            'webformatURL'          => 'https://example.com/webformat.jpg',
            'largeImageURL'         => 'https://example.com/large.jpg',
            'file_contents_preview' => 'already downloaded',
            'filesize_preview'      => strlen('already downloaded'),
            'created_at'            => $now,
            'updated_at'            => $now,
        ]);

        // Mock HTTP responses for webformat and largeImage
        Http::fake([
            'https://example.com/webformat.jpg' => Http::response('new data', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
            'https://example.com/large.jpg' => Http::response('new data', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        // Act
        $downloadedCount = $this->client->downloadImage($imageId, 'all');

        // Assert
        $this->assertEquals(2, $downloadedCount);

        // Verify database has the Http::fake() responses
        $this->assertDatabaseHas($this->imagesTableName, [
            'id'                       => $imageId,
            'file_contents_preview'    => 'already downloaded',
            'file_contents_webformat'  => 'new data',
            'filesize_webformat'       => strlen('new data'),
            'file_contents_largeImage' => 'new data',
            'filesize_largeImage'      => strlen('new data'),
        ]);
    }

    public function test_saveImageToFile_saves_specific_image_type()
    {
        // Arrange
        $imageId      = 4384750;
        $now          = now();
        $imageData    = 'fake image data';
        $relativeFile = 'images/' . $imageId . '_preview.jpg';

        // Insert test image with content
        DB::table($this->imagesTableName)->insert([
            'id'                    => $imageId,
            'previewURL'            => 'https://example.com/preview.jpg',
            'webformatURL'          => 'https://example.com/webformat.jpg',
            'largeImageURL'         => 'https://example.com/large.jpg',
            'file_contents_preview' => $imageData,
            'filesize_preview'      => strlen($imageData),
            'created_at'            => $now,
            'updated_at'            => $now,
        ]);

        // Act
        $savedCount = $this->client->saveImageToFile($imageId, 'preview');

        // Assert
        $this->assertEquals(1, $savedCount);
        $this->assertTrue(Storage::disk('public')->exists($relativeFile));
        $this->assertEquals($imageData, Storage::disk('public')->get($relativeFile));

        // Verify database stores relative path
        $this->assertDatabaseHas($this->imagesTableName, [
            'id'                       => $imageId,
            'storage_filepath_preview' => $relativeFile,
        ]);
    }

    public function test_saveImageToFile_saves_next_unsaved_image()
    {
        // Arrange
        $imageId      = 4384750;
        $now          = now();
        $imageData    = 'fake image data';
        $relativeFile = 'images/' . $imageId . '_webformat.jpg';

        // Insert test image with content
        DB::table($this->imagesTableName)->insert([
            'id'                      => $imageId,
            'previewURL'              => 'https://example.com/preview.jpg',
            'webformatURL'            => 'https://example.com/webformat.jpg',
            'largeImageURL'           => 'https://example.com/large.jpg',
            'file_contents_webformat' => $imageData,
            'filesize_webformat'      => strlen($imageData),
            'created_at'              => $now,
            'updated_at'              => $now,
        ]);

        // Act
        $savedCount = $this->client->saveImageToFile(null, 'webformat');

        // Assert
        $this->assertEquals(1, $savedCount);
        $this->assertTrue(Storage::disk('public')->exists($relativeFile));
        $this->assertEquals($imageData, Storage::disk('public')->get($relativeFile));

        // Verify database stores relative path
        $this->assertDatabaseHas($this->imagesTableName, [
            'id'                         => $imageId,
            'storage_filepath_webformat' => $relativeFile,
        ]);
    }

    public function test_saveImageToFile_saves_all_types()
    {
        // Arrange
        $imageId       = 4384750;
        $now           = now();
        $relativeFiles = [
            'preview'    => 'images/' . $imageId . '_preview.jpg',
            'webformat'  => 'images/' . $imageId . '_webformat.jpg',
            'largeImage' => 'images/' . $imageId . '_largeImage.jpg',
        ];

        // Insert test image with content
        DB::table($this->imagesTableName)->insert([
            'id'                       => $imageId,
            'previewURL'               => 'https://example.com/preview.jpg',
            'webformatURL'             => 'https://example.com/webformat.jpg',
            'largeImageURL'            => 'https://example.com/large.jpg',
            'file_contents_preview'    => 'preview data',
            'filesize_preview'         => strlen('preview data'),
            'file_contents_webformat'  => 'webformat data',
            'filesize_webformat'       => strlen('webformat data'),
            'file_contents_largeImage' => 'largeImage data',
            'filesize_largeImage'      => strlen('largeImage data'),
            'created_at'               => $now,
            'updated_at'               => $now,
        ]);

        // Act
        $savedCount = $this->client->saveImageToFile($imageId, 'all');

        // Assert
        $this->assertEquals(3, $savedCount);
        foreach ($relativeFiles as $type => $relativeFile) {
            $this->assertTrue(Storage::disk('public')->exists($relativeFile));
            $this->assertEquals($type . ' data', Storage::disk('public')->get($relativeFile));
        }

        // Verify database stores relative paths
        $this->assertDatabaseHas($this->imagesTableName, [
            'id'                          => $imageId,
            'storage_filepath_preview'    => $relativeFiles['preview'],
            'storage_filepath_webformat'  => $relativeFiles['webformat'],
            'storage_filepath_largeImage' => $relativeFiles['largeImage'],
        ]);
    }

    public function test_saveImageToFile_throws_exception_for_invalid_type()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid image type. Must be one of: preview, webformat, largeImage, all');

        $this->client->saveImageToFile(4384750, 'invalid_type');
    }

    public function test_saveImageToFile_throws_exception_for_invalid_id()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Image not found with ID: 999999');

        $this->client->saveImageToFile(999999);
    }

    public function test_saveImageToFile_throws_exception_for_missing_content()
    {
        // Arrange
        $imageId = 4384750;
        $now     = now();

        // Insert test image without content
        DB::table($this->imagesTableName)->insert([
            'id'            => $imageId,
            'previewURL'    => 'https://example.com/preview.jpg',
            'webformatURL'  => 'https://example.com/webformat.jpg',
            'largeImageURL' => 'https://example.com/large.jpg',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No content found for type: preview');

        // Act
        $this->client->saveImageToFile($imageId, 'preview');
    }
}

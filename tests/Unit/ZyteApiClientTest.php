<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\ZyteApiClient;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class ZyteApiClientTest extends TestCase
{
    protected ZyteApiClient $client;
    protected string $apiKey     = 'test-api-key';
    protected string $apiBaseUrl = 'https://api.zyte.com/v1';
    protected array $apiDefaultHeaders;

    protected function setUp(): void
    {
        parent::setUp();

        // Fake the local storage disk for clean test isolation
        Storage::fake('local');

        // Configure test environment
        Config::set('api-cache.apis.zyte.api_key', $this->apiKey);
        Config::set('api-cache.apis.zyte.base_url', $this->apiBaseUrl);
        Config::set('api-cache.apis.zyte.version', 'v1');
        Config::set('api-cache.apis.zyte.rate_limit_max_attempts', 5);
        Config::set('api-cache.apis.zyte.rate_limit_decay_seconds', 10);

        $this->apiDefaultHeaders = [
            'Authorization' => 'Basic ' . base64_encode($this->apiKey . ':'),
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];

        $this->client = new ZyteApiClient();
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
        $this->assertEquals('zyte', $this->client->getClientName());
        $this->assertEquals($this->apiBaseUrl, $this->client->getBaseUrl());
        $this->assertEquals($this->apiKey, $this->client->getApiKey());
        $this->assertEquals('v1', $this->client->getVersion());
    }

    public function test_constructor_accepts_null_cache_manager()
    {
        $client = new ZyteApiClient(null);

        $this->assertEquals('zyte', $client->getClientName());
        $this->assertEquals($this->apiBaseUrl, $client->getBaseUrl());
    }

    public function test_getAuthHeaders_returns_correct_basic_auth()
    {
        $headers = $this->client->getAuthHeaders();

        $expectedAuth = 'Basic ' . base64_encode($this->apiKey . ':');
        $this->assertEquals($expectedAuth, $headers['Authorization']);
    }

    public function test_getAuthHeaders_returns_empty_when_no_api_key()
    {
        Config::set('api-cache.apis.zyte.api_key', null);
        $client = new ZyteApiClient();

        $headers = $client->getAuthHeaders();
        $this->assertEquals([], $headers);
    }

    public function test_logHttpError_extracts_zyte_api_error()
    {
        $errorResponse = json_encode([
            'type'   => '/zyte-api/errors/request-error',
            'title'  => 'Request Error',
            'detail' => 'Invalid URL provided',
        ]);

        $this->client->logHttpError(400, 'Bad Request', ['test_context' => 'value'], $errorResponse);

        // Verify the error was logged to the database with Zyte-specific api_message
        $this->assertDatabaseHas('api_cache_errors', [
            'api_client'    => 'zyte',
            'error_type'    => 'http_error',
            'error_message' => 'Bad Request',
            'api_message'   => 'Invalid URL provided', // This is the Zyte-specific extraction
        ]);

        // Verify the context data was stored
        $errorRecord = DB::table('api_cache_errors')->where('api_client', 'zyte')->first();
        $contextData = json_decode($errorRecord->context_data, true);
        $this->assertEquals(400, $contextData['status_code']);
        $this->assertEquals('value', $contextData['test_context']);
    }

    public function test_logHttpError_handles_malformed_json_response()
    {
        // Test with malformed JSON that can't be parsed
        $malformedJson = '{"type": "/zyte-api/errors/request-error", "title": "Request Error", "detail": "Invalid URL provided"'; // Missing closing brace

        $this->client->logHttpError(400, 'Bad Request', [], $malformedJson);

        // Verify the error was logged to the database but without api_message (since JSON parsing failed)
        $this->assertDatabaseHas('api_cache_errors', [
            'api_client'    => 'zyte',
            'error_type'    => 'http_error',
            'error_message' => 'Bad Request',
            'api_message'   => null, // Should be null since JSON parsing failed
        ]);
    }

    public function test_calculateCredits_returns_one()
    {
        $credits = $this->client->calculateCredits(['url' => 'https://example.com']);
        $this->assertEquals(1, $credits);
    }

    public function test_saveScreenshot_saves_file_and_returns_path()
    {
        // Create a test row in the database
        $tableName = $this->client->getTableName();
        $rowId     = DB::table($tableName)->insertGetId([
            'key'           => 'test-key',
            'client'        => 'zyte',
            'endpoint'      => 'extract',
            'method'        => 'POST',
            'full_url'      => 'https://example.com',
            'request_body'  => json_encode(['url' => 'https://example.com', 'screenshot' => true]),
            'response_body' => json_encode([
                'screenshot' => base64_encode('fake-image-data'),
            ]),
            'response_status_code' => 200,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $relativePath = $this->client->saveScreenshot($rowId);

        // Verify the method returns the expected relative path
        $expectedPath = "zyte/screenshots/screenshot_{$rowId}.jpeg";
        $this->assertEquals($expectedPath, $relativePath);

        // Verify file was created
        $this->assertTrue(Storage::disk('local')->exists($relativePath));

        // Verify file contains the expected data
        $fileContent = Storage::disk('local')->get($relativePath);
        $this->assertEquals('fake-image-data', $fileContent);
    }

    public function test_saveScreenshot_throws_exception_when_row_not_found()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Row with ID 99999 not found');

        $this->client->saveScreenshot(99999);
    }

    public function test_saveScreenshot_throws_exception_when_no_screenshot_data()
    {
        // Create a test row without screenshot data
        $tableName = $this->client->getTableName();
        $rowId     = DB::table($tableName)->insertGetId([
            'key'           => 'test-key-no-screenshot',
            'client'        => 'zyte',
            'endpoint'      => 'extract',
            'method'        => 'POST',
            'full_url'      => 'https://example.com',
            'request_body'  => json_encode(['url' => 'https://example.com']),
            'response_body' => json_encode([
                'url' => 'https://example.com',
            ]),
            'response_status_code' => 200,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not contain screenshot=true in request_body');

        $this->client->saveScreenshot($rowId);
    }

    public function test_saveScreenshot_throws_exception_when_response_missing_screenshot()
    {
        // Create a test row with screenshot request but no screenshot in response
        $tableName = $this->client->getTableName();
        $rowId     = DB::table($tableName)->insertGetId([
            'key'           => 'test-key-missing-response',
            'client'        => 'zyte',
            'endpoint'      => 'extract',
            'method'        => 'POST',
            'full_url'      => 'https://example.com',
            'request_body'  => json_encode(['url' => 'https://example.com', 'screenshot' => true]),
            'response_body' => json_encode([
                'url'        => 'https://example.com',
                'statusCode' => 200,
            ]),
            'response_status_code' => 200,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Screenshot data not found in response');

        $this->client->saveScreenshot($rowId);
    }

    public function test_saveScreenshot_handles_different_formats()
    {
        // Test PNG format
        $tableName = $this->client->getTableName();
        $rowId     = DB::table($tableName)->insertGetId([
            'key'          => 'test-key-png',
            'client'       => 'zyte',
            'endpoint'     => 'extract',
            'method'       => 'POST',
            'full_url'     => 'https://example.com',
            'request_body' => json_encode([
                'url'               => 'https://example.com',
                'screenshot'        => true,
                'screenshotOptions' => ['format' => 'png'],
            ]),
            'response_body' => json_encode([
                'screenshot' => base64_encode('fake-png-data'),
            ]),
            'response_status_code' => 200,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $relativePath = $this->client->saveScreenshot($rowId);

        // Verify PNG extension is used
        $this->assertStringContainsString('.png', $relativePath);
        $this->assertTrue(Storage::disk('local')->exists($relativePath));
    }

    public function test_saveScreenshot_handles_malformed_request_body_json()
    {
        // Create a test row with malformed JSON in request_body
        $tableName = $this->client->getTableName();
        $rowId     = DB::table($tableName)->insertGetId([
            'key'           => 'test-key-malformed-request',
            'client'        => 'zyte',
            'endpoint'      => 'extract',
            'method'        => 'POST',
            'full_url'      => 'https://example.com',
            'request_body'  => '{"url": "https://example.com", "screenshot": true', // Missing closing brace
            'response_body' => json_encode([
                'screenshot' => base64_encode('fake-image-data'),
            ]),
            'response_status_code' => 200,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $this->expectException(\Exception::class);

        $this->client->saveScreenshot($rowId);
    }

    public function test_saveScreenshot_handles_malformed_response_body_json()
    {
        // Create a test row with malformed JSON in response_body
        $tableName = $this->client->getTableName();
        $rowId     = DB::table($tableName)->insertGetId([
            'key'                  => 'test-key-malformed-response',
            'client'               => 'zyte',
            'endpoint'             => 'extract',
            'method'               => 'POST',
            'full_url'             => 'https://example.com',
            'request_body'         => json_encode(['url' => 'https://example.com', 'screenshot' => true]),
            'response_body'        => '{"screenshot": "' . base64_encode('fake-image-data') . '"', // Missing closing brace
            'response_status_code' => 200,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $this->expectException(\Exception::class);

        $this->client->saveScreenshot($rowId);
    }

    public function test_extract_throws_exception_when_no_required_fields_set()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one of the following request fields must be set to true');

        // Call extract with no browser interaction or automatic extraction fields set
        $this->client->extract('https://example.com');
    }

    public function test_extract_accepts_browser_interaction_fields()
    {
        Http::fake([
            "{$this->apiBaseUrl}/extract" => Http::response(['url' => 'https://example.com'], 200),
        ]);

        $this->client = new ZyteApiClient();
        $this->client->clearRateLimit();

        // Test browserHtml field
        $response = $this->client->extract(url: 'https://example.com', browserHtml: true);
        $this->assertArrayHasKey('response', $response);

        // Test httpResponseBody field
        $response = $this->client->extract(url: 'https://example.com', httpResponseBody: true);
        $this->assertArrayHasKey('response', $response);

        // Test httpResponseHeaders field
        $response = $this->client->extract(url: 'https://example.com', httpResponseHeaders: true);
        $this->assertArrayHasKey('response', $response);

        // Test screenshot field
        $response = $this->client->extract(url: 'https://example.com', screenshot: true);
        $this->assertArrayHasKey('response', $response);
    }

    public function test_extract_accepts_automatic_extraction_fields()
    {
        Http::fake([
            "{$this->apiBaseUrl}/extract" => Http::response(['url' => 'https://example.com'], 200),
        ]);

        $this->client = new ZyteApiClient();
        $this->client->clearRateLimit();

        // Test article field
        $response = $this->client->extract(url: 'https://example.com', article: true);
        $this->assertArrayHasKey('response', $response);

        // Test product field
        $response = $this->client->extract(url: 'https://example.com', product: true);
        $this->assertArrayHasKey('response', $response);

        // Test serp field
        $response = $this->client->extract(url: 'https://example.com', serp: true);
        $this->assertArrayHasKey('response', $response);
    }

    public function test_extract_sets_attributes2_with_registrable_domain()
    {
        Http::fake([
            "{$this->apiBaseUrl}/extract" => Http::response(['url' => 'https://example.com'], 200),
        ]);

        $this->client = new ZyteApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->extract(url: 'https://subdomain.example.com', browserHtml: true);

        // Check that attributes2 was set correctly in the database
        $tableName = $this->client->getTableName();
        $row       = DB::table($tableName)->where('attributes', 'https://subdomain.example.com')->first();
        $this->assertEquals('example.com', $row->attributes2);
    }

    public function test_makes_successful_extract_request()
    {
        $fakeTaskId = 'fake-task-123';

        Http::fake([
            "{$this->apiBaseUrl}/extract" => Http::response([
                'url'         => 'https://example.com',
                'statusCode'  => 200,
                'browserHtml' => '<html><body>Test content</body></html>',
                'taskId'      => $fakeTaskId,
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ZyteApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->extract(
            'https://example.com',
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            true
        );

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/extract" &&
                   $request->method() === 'POST';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($fakeTaskId, $responseData['taskId']);
        $this->assertEquals('https://example.com', $responseData['url']);
        $this->assertArrayHasKey('browserHtml', $responseData);
        $this->assertEquals('<html><body>Test content</body></html>', $responseData['browserHtml']);
    }

    public function test_makes_successful_extractCommon_request()
    {
        $fakeTaskId = 'fake-common-456';

        Http::fake([
            "{$this->apiBaseUrl}/extract" => Http::response([
                'url'         => 'https://example.com',
                'statusCode'  => 200,
                'browserHtml' => '<html><body>Common content</body></html>',
                'taskId'      => $fakeTaskId,
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ZyteApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->extractCommon(
            'https://example.com',
            null,
            null,
            true
        );

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === "{$this->apiBaseUrl}/extract" &&
                   $request->method() === 'POST' &&
                   $data['url'] === 'https://example.com' &&
                   $data['browserHtml'] === true;
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($fakeTaskId, $responseData['taskId']);
        $this->assertEquals('<html><body>Common content</body></html>', $responseData['browserHtml']);
    }

    public function test_makes_successful_extractBrowserHtml_request()
    {
        $fakeTaskId = 'fake-browser-789';

        Http::fake([
            "{$this->apiBaseUrl}/extract" => Http::response([
                'url'         => 'https://example.com',
                'statusCode'  => 200,
                'browserHtml' => '<html><body>Browser HTML content</body></html>',
                'taskId'      => $fakeTaskId,
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ZyteApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->extractBrowserHtml('https://example.com');

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === "{$this->apiBaseUrl}/extract" &&
                   $request->method() === 'POST' &&
                   $data['url'] === 'https://example.com' &&
                   $data['browserHtml'] === true;
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($fakeTaskId, $responseData['taskId']);
        $this->assertEquals('<html><body>Browser HTML content</body></html>', $responseData['browserHtml']);
    }

    public function test_makes_successful_extractArticle_request()
    {
        $fakeTaskId = 'fake-article-101';

        Http::fake([
            "{$this->apiBaseUrl}/extract" => Http::response([
                'url'        => 'https://example.com/article',
                'statusCode' => 200,
                'article'    => [
                    'headline'    => 'Test Article',
                    'articleBody' => 'This is test content',
                ],
                'taskId' => $fakeTaskId,
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ZyteApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->extractArticle(
            'https://example.com/article',
            ['headline' => true, 'articleBody' => true]
        );

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === "{$this->apiBaseUrl}/extract" &&
                   $request->method() === 'POST' &&
                   $data['url'] === 'https://example.com/article' &&
                   isset($data['article']);
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($fakeTaskId, $responseData['taskId']);
        $this->assertEquals('Test Article', $responseData['article']['headline']);
    }

    public function test_makes_successful_extractArticleList_request()
    {
        $fakeTaskId = 'fake-articlelist-111';

        Http::fake([
            "{$this->apiBaseUrl}/extract" => Http::response([
                'url'         => 'https://example.com/articles',
                'statusCode'  => 200,
                'articleList' => [
                    ['headline' => 'Article 1', 'url' => 'https://example.com/article1'],
                    ['headline' => 'Article 2', 'url' => 'https://example.com/article2'],
                ],
                'taskId' => $fakeTaskId,
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ZyteApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->extractArticleList('https://example.com/articles');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/extract" &&
                   $request->method() === 'POST';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($fakeTaskId, $responseData['taskId']);
        $this->assertCount(2, $responseData['articleList']);
    }

    public function test_makes_successful_extractArticleNavigation_request()
    {
        $fakeTaskId = 'fake-articlenav-222';

        Http::fake([
            "{$this->apiBaseUrl}/extract" => Http::response([
                'url'               => 'https://example.com/nav',
                'statusCode'        => 200,
                'articleNavigation' => [
                    'nextPage' => 'https://example.com/page2',
                    'prevPage' => 'https://example.com/page1',
                ],
                'taskId' => $fakeTaskId,
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ZyteApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->extractArticleNavigation('https://example.com/nav');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/extract" &&
                   $request->method() === 'POST';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($fakeTaskId, $responseData['taskId']);
        $this->assertArrayHasKey('nextPage', $responseData['articleNavigation']);
    }

    public function test_makes_successful_extractForumThread_request()
    {
        $fakeTaskId = 'fake-forum-333';

        Http::fake([
            "{$this->apiBaseUrl}/extract" => Http::response([
                'url'         => 'https://example.com/forum/thread',
                'statusCode'  => 200,
                'forumThread' => [
                    'title' => 'Forum Thread Title',
                    'posts' => [
                        ['author' => 'User1', 'content' => 'First post'],
                        ['author' => 'User2', 'content' => 'Second post'],
                    ],
                ],
                'taskId' => $fakeTaskId,
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ZyteApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->extractForumThread('https://example.com/forum/thread');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/extract" &&
                   $request->method() === 'POST';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($fakeTaskId, $responseData['taskId']);
        $this->assertEquals('Forum Thread Title', $responseData['forumThread']['title']);
    }

    public function test_makes_successful_extractJobPosting_request()
    {
        $fakeTaskId = 'fake-job-444';

        Http::fake([
            "{$this->apiBaseUrl}/extract" => Http::response([
                'url'        => 'https://example.com/job/123',
                'statusCode' => 200,
                'jobPosting' => [
                    'title'       => 'Software Engineer',
                    'company'     => 'Tech Corp',
                    'location'    => 'San Francisco, CA',
                    'description' => 'Great job opportunity',
                ],
                'taskId' => $fakeTaskId,
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ZyteApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->extractJobPosting('https://example.com/job/123');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/extract" &&
                   $request->method() === 'POST';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($fakeTaskId, $responseData['taskId']);
        $this->assertEquals('Software Engineer', $responseData['jobPosting']['title']);
    }

    public function test_makes_successful_extractJobPostingNavigation_request()
    {
        $fakeTaskId = 'fake-jobnav-555';

        Http::fake([
            "{$this->apiBaseUrl}/extract" => Http::response([
                'url'                  => 'https://example.com/jobs',
                'statusCode'           => 200,
                'jobPostingNavigation' => [
                    'nextPage' => 'https://example.com/jobs?page=2',
                    'prevPage' => null,
                ],
                'taskId' => $fakeTaskId,
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ZyteApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->extractJobPostingNavigation('https://example.com/jobs');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/extract" &&
                   $request->method() === 'POST';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($fakeTaskId, $responseData['taskId']);
        $this->assertArrayHasKey('nextPage', $responseData['jobPostingNavigation']);
    }

    public function test_makes_successful_extractPageContent_request()
    {
        $fakeTaskId = 'fake-page-666';

        Http::fake([
            "{$this->apiBaseUrl}/extract" => Http::response([
                'url'         => 'https://example.com/page',
                'statusCode'  => 200,
                'pageContent' => [
                    'title'   => 'Page Title',
                    'content' => 'Page content here',
                    'links'   => ['https://example.com/link1', 'https://example.com/link2'],
                ],
                'taskId' => $fakeTaskId,
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ZyteApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->extractPageContent('https://example.com/page');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/extract" &&
                   $request->method() === 'POST';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($fakeTaskId, $responseData['taskId']);
        $this->assertEquals('Page Title', $responseData['pageContent']['title']);
    }

    public function test_makes_successful_extractProduct_request()
    {
        $fakeTaskId = 'fake-product-777';

        Http::fake([
            "{$this->apiBaseUrl}/extract" => Http::response([
                'url'        => 'https://example.com/product/123',
                'statusCode' => 200,
                'product'    => [
                    'name'         => 'Test Product',
                    'price'        => '$29.99',
                    'description'  => 'Great product',
                    'availability' => 'In Stock',
                ],
                'taskId' => $fakeTaskId,
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ZyteApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->extractProduct('https://example.com/product/123');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/extract" &&
                   $request->method() === 'POST';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($fakeTaskId, $responseData['taskId']);
        $this->assertEquals('Test Product', $responseData['product']['name']);
    }

    public function test_extractProduct_sets_correct_attributes3()
    {
        $fakeTaskId = 'fake-product-attributes-test';

        Http::fake([
            "{$this->apiBaseUrl}/extract" => Http::response([
                'url'        => 'https://example.com/product/test',
                'statusCode' => 200,
                'taskId'     => $fakeTaskId,
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ZyteApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->extractProduct('https://example.com/product/test');

        // Verify the response contains our fake task ID
        $responseData = $response['response']->json();
        $this->assertEquals($fakeTaskId, $responseData['taskId']);

        // Check that attributes3 was set correctly in the database
        $tableName = $this->client->getTableName();
        $row       = DB::table($tableName)->where('attributes', 'https://example.com/product/test')->first();
        $this->assertEquals('product', $row->attributes3);
    }

    public function test_makes_successful_extractProductList_request()
    {
        $fakeTaskId = 'fake-productlist-888';

        Http::fake([
            "{$this->apiBaseUrl}/extract" => Http::response([
                'url'         => 'https://example.com/products',
                'statusCode'  => 200,
                'productList' => [
                    ['name' => 'Product 1', 'price' => '$19.99'],
                    ['name' => 'Product 2', 'price' => '$29.99'],
                ],
                'taskId' => $fakeTaskId,
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ZyteApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->extractProductList('https://example.com/products');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/extract" &&
                   $request->method() === 'POST';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($fakeTaskId, $responseData['taskId']);
        $this->assertCount(2, $responseData['productList']);
    }

    public function test_makes_successful_extractProductNavigation_request()
    {
        $fakeTaskId = 'fake-prodnav-999';

        Http::fake([
            "{$this->apiBaseUrl}/extract" => Http::response([
                'url'               => 'https://example.com/products/nav',
                'statusCode'        => 200,
                'productNavigation' => [
                    'nextPage'   => 'https://example.com/products?page=2',
                    'prevPage'   => null,
                    'totalPages' => 10,
                ],
                'taskId' => $fakeTaskId,
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ZyteApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->extractProductNavigation('https://example.com/products/nav');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/extract" &&
                   $request->method() === 'POST';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($fakeTaskId, $responseData['taskId']);
        $this->assertEquals(10, $responseData['productNavigation']['totalPages']);
    }

    public function test_makes_successful_extractSerp_request()
    {
        $fakeTaskId = 'fake-serp-000';

        Http::fake([
            "{$this->apiBaseUrl}/extract" => Http::response([
                'url'        => 'https://google.com/search?q=test',
                'statusCode' => 200,
                'serp'       => [
                    'results' => [
                        ['title' => 'Result 1', 'url' => 'https://example1.com'],
                        ['title' => 'Result 2', 'url' => 'https://example2.com'],
                    ],
                    'totalResults' => 1000000,
                ],
                'taskId' => $fakeTaskId,
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ZyteApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->extractSerp('https://google.com/search?q=test');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/extract" &&
                   $request->method() === 'POST';
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($fakeTaskId, $responseData['taskId']);
        $this->assertEquals(1000000, $responseData['serp']['totalResults']);
    }

    public function test_extractCustomAttributes_throws_exception_for_invalid_extraction_type()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid extraction type: 'invalid'");

        $customAttributes = [
            'title' => ['css' => 'h1'],
        ];

        $this->client->extractCustomAttributes(
            'https://example.com',
            $customAttributes,
            'invalid'
        );
    }

    public function test_extractCustomAttributes_sets_correct_attributes3()
    {
        Http::fake([
            "{$this->apiBaseUrl}/extract" => Http::response([
                'url'    => 'https://example.com',
                'taskId' => 'test-123',
            ], 200),
        ]);

        $this->client = new ZyteApiClient();
        $this->client->clearRateLimit();

        $customAttributes = [
            'title' => ['css' => 'h1'],
        ];

        $response = $this->client->extractCustomAttributes(
            'https://example.com',
            $customAttributes,
            'article'
        );

        // Check that attributes3 was set correctly in the database
        $tableName = $this->client->getTableName();
        $row       = DB::table($tableName)->where('attributes', 'https://example.com')->first();
        $this->assertEquals('customAttributes-article', $row->attributes3);
    }

    public function test_makes_successful_extractCustomAttributes_request()
    {
        $fakeTaskId = 'fake-custom-303';

        Http::fake([
            "{$this->apiBaseUrl}/extract" => Http::response([
                'url'              => 'https://example.com',
                'statusCode'       => 200,
                'customAttributes' => [
                    'title' => 'Custom Title',
                    'price' => '$19.99',
                ],
                'taskId' => $fakeTaskId,
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ZyteApiClient();
        $this->client->clearRateLimit();

        $customAttributes = [
            'title' => ['css' => 'h1'],
            'price' => ['css' => '.price'],
        ];

        $response = $this->client->extractCustomAttributes(
            'https://example.com',
            $customAttributes,
            'product'
        );

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === "{$this->apiBaseUrl}/extract" &&
                   $request->method() === 'POST' &&
                   $data['url'] === 'https://example.com' &&
                   isset($data['customAttributes']);
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($fakeTaskId, $responseData['taskId']);
        $this->assertEquals('Custom Title', $responseData['customAttributes']['title']);
        $this->assertEquals('$19.99', $responseData['customAttributes']['price']);
    }

    public function test_makes_successful_screenshot_request()
    {
        $fakeTaskId = 'fake-screenshot-202';

        Http::fake([
            "{$this->apiBaseUrl}/extract" => Http::response([
                'url'        => 'https://example.com',
                'statusCode' => 200,
                'screenshot' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==',
                'taskId'     => $fakeTaskId,
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ZyteApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->screenshot('https://example.com');

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === "{$this->apiBaseUrl}/extract" &&
                   $request->method() === 'POST' &&
                   $data['url'] === 'https://example.com' &&
                   $data['screenshot'] === true;
        });

        // Make sure we used the Http::fake() response
        $responseData = $response['response']->json();
        $this->assertEquals($fakeTaskId, $responseData['taskId']);
        $this->assertNotEmpty($responseData['screenshot']);
    }
}

<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\DataForSeoApiClient;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;

class DataForSeoApiClientMerchantAmazonTest extends TestCase
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

    public function test_merchant_amazon_products_task_post_successful_request()
    {
        $responseData = [
            'version'        => '0.1.20250425',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.01 sec.',
            'cost'           => 0.003,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => 'merchant-task-id',
                    'status_code'    => 20101,
                    'status_message' => 'Task Created',
                    'time'           => '0.01 sec.',
                    'cost'           => 0.003,
                    'result_count'   => 0,
                    'path'           => ['v3', 'merchant', 'amazon', 'products', 'task_post'],
                    'data'           => [
                        'api'           => 'merchant',
                        'function'      => 'amazon_products',
                        'se'            => 'amazon',
                        'keyword'       => 'wireless headphones',
                        'location_name' => 'United States',
                        'language_code' => 'en_US',
                        'location_code' => 2840,
                        'depth'         => 100,
                        'priority'      => 2,
                        'tag'           => 'test-cache-key',
                    ],
                    'result' => null,
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/products/task_post" => Http::response($responseData, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonProductsTaskPost(
            'wireless headphones',
            null, // url
            2, // priority
            'United States' // locationName
        );

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/products/task_post" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['keyword']) &&
                   $request->data()[0]['keyword'] === 'wireless headphones' &&
                   isset($request->data()[0]['location_name']) &&
                   $request->data()[0]['location_name'] === 'United States' &&
                   isset($request->data()[0]['priority']) &&
                   $request->data()[0]['priority'] === 2;
        });

        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertEquals('merchant-task-id', $responseData['tasks'][0]['id']);
    }

    public function test_merchant_amazon_products_task_post_validates_empty_keyword()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keyword cannot be empty');

        $this->client->merchantAmazonProductsTaskPost('');
    }

    public function test_merchant_amazon_products_task_post_validates_required_language_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either languageName or languageCode must be provided');

        $this->client->merchantAmazonProductsTaskPost(
            'test product',
            null, // url
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            null, // languageName
            null // languageCode
        );
    }

    public function test_merchant_amazon_products_task_post_validates_required_location_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either locationName, locationCode, or locationCoordinate must be provided');

        $this->client->merchantAmazonProductsTaskPost(
            'test product',
            null, // url
            null, // priority
            null, // locationName
            null, // locationCode
            null, // locationCoordinate
            'English' // languageName
        );
    }

    public function test_merchant_amazon_products_task_post_validates_priority_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Priority must be either 1 (normal) or 2 (high)');

        $this->client->merchantAmazonProductsTaskPost(
            'test product',
            null, // url
            0, // priority
            'United States' // locationName
        );
    }

    public function test_merchant_amazon_products_task_post_validates_depth_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Depth must be less than or equal to 700');

        $this->client->merchantAmazonProductsTaskPost(
            'test product',
            null, // url
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            701 // depth
        );
    }

    public function test_merchant_amazon_products_task_post_validates_postback_data_requirement()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('postbackData is required when postbackUrl is specified');

        $this->client->merchantAmazonProductsTaskPost(
            'test product',
            null, // url
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            100, // depth
            null, // maxCrawlPages
            null, // department
            null, // searchParam
            null, // priceMin
            null, // priceMax
            null, // sortBy
            null, // tag
            'postback-url', // postbackUrl
            null // postbackData (missing, should trigger error)
        );
    }

    public static function merchantAmazonProductsTaskPostParametersProvider(): array
    {
        return [
            'basic parameters' => [
                [
                    'keyword'      => 'bluetooth speaker',
                    'locationName' => 'United States',
                    'languageCode' => 'en_US',
                ],
                [
                    'keyword'       => 'bluetooth speaker',
                    'location_name' => 'United States',
                    'language_code' => 'en_US',
                    'location_code' => 2840,
                    'depth'         => 100,
                ],
            ],
            'with url and priority' => [
                [
                    'keyword'      => 'gaming mouse',
                    'url'          => 'https://www.amazon.com/s?k=gaming+mouse',
                    'priority'     => 1,
                    'locationCode' => 2826,
                    'languageName' => 'English',
                ],
                [
                    'keyword'       => 'gaming mouse',
                    'url'           => 'https://www.amazon.com/s?k=gaming+mouse',
                    'priority'      => 1,
                    'location_code' => 2826,
                    'language_name' => 'English',
                    'depth'         => 100,
                ],
            ],
            'with se_domain and department' => [
                [
                    'keyword'      => 'laptop computer',
                    'locationName' => 'United Kingdom',
                    'languageCode' => 'en_GB',
                    'seDomain'     => 'amazon.co.uk',
                    'department'   => 'Electronics',
                ],
                [
                    'keyword'       => 'laptop computer',
                    'location_name' => 'United Kingdom',
                    'language_code' => 'en_GB',
                    'location_code' => 2840,
                    'se_domain'     => 'amazon.co.uk',
                    'department'    => 'Electronics',
                    'depth'         => 100,
                ],
            ],
            'with sorting and filters' => [
                [
                    'keyword'      => 'coffee maker',
                    'locationName' => 'Canada',
                    'languageCode' => 'en_CA',
                    'sortBy'       => 'price_high_to_low',
                    'priceMax'     => 200,
                    'priceMin'     => 50,
                ],
                [
                    'keyword'       => 'coffee maker',
                    'location_name' => 'Canada',
                    'language_code' => 'en_CA',
                    'location_code' => 2840,
                    'sort_by'       => 'price_high_to_low',
                    'price_max'     => 200,
                    'price_min'     => 50,
                    'depth'         => 100,
                ],
            ],
        ];
    }

    #[DataProvider('merchantAmazonProductsTaskPostParametersProvider')]
    public function test_merchant_amazon_products_task_post_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $taskResponse = [
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'             => 'test-merchant-task-id',
                    'status_code'    => 20101,
                    'status_message' => 'Task Created',
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/products/task_post" => Http::response($taskResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $this->client->merchantAmazonProductsTaskPost(...$parameters);

        Http::assertSent(function ($request) use ($expectedParams) {
            $requestData = $request->data()[0] ?? [];

            foreach ($expectedParams as $key => $value) {
                if (!isset($requestData[$key]) || $requestData[$key] !== $value) {
                    return false;
                }
            }

            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/products/task_post" &&
                   $request->method() === 'POST';
        });
    }

    public function test_merchant_amazon_products_task_post_handles_api_errors()
    {
        $errorResponse = [
            'version'        => '0.1.20250425',
            'status_code'    => 40000,
            'status_message' => 'Bad Request',
            'tasks'          => [],
        ];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('error-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('allowRequest')
            ->willReturn(true);
        $mockCacheManager->expects($this->once())
            ->method('incrementAttempts');

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/products/task_post" => Http::response($errorResponse, 400),
        ]);

        $this->client = new DataForSeoApiClient($mockCacheManager);
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonProductsTaskPost(
            'test product',
            null, // url
            null, // priority
            'United States' // locationName
        );

        $this->assertEquals(400, $result['response_status_code']);
    }

    public function test_merchant_amazon_products_standard_returns_cached_response_when_available()
    {
        $cachedResponse = [
            'tasks' => [
                [
                    'result' => [
                        [
                            'type'  => 'amazon_product',
                            'title' => 'Cached Product',
                            'price' => ['current' => 19.99],
                        ],
                    ],
                ],
            ],
        ];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('std-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn($cachedResponse);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->merchantAmazonProductsStandard(
            'cached product query',
            null, // url
            null, // priority
            'United States' // locationName
        );

        $this->assertEquals($cachedResponse, $result);
    }

    public function test_merchant_amazon_products_standard_advanced_returns_cached_response_when_available()
    {
        $cachedResponse = [
            'tasks' => [
                [
                    'result' => [
                        [
                            'type'  => 'amazon_product',
                            'title' => 'Cached Product',
                            'price' => ['current' => 19.99],
                        ],
                    ],
                ],
            ],
        ];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('advanced-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn($cachedResponse);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->merchantAmazonProductsStandardAdvanced(
            'cached product query',
            null, // url
            null, // priority
            'United States' // locationName
        );

        $this->assertEquals($cachedResponse, $result);
    }

    public function test_merchant_amazon_products_standard_advanced_returns_null_when_not_cached_and_posting_disabled()
    {
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('not-cached-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn(null); // Not cached

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->merchantAmazonProductsStandardAdvanced(
            'not cached query',
            null, // url
            null, // priority
            'United States', // locationName
            2840, // locationCode
            null, // locationCoordinate
            'English', // languageName
            'en_US', // languageCode
            null, // seDomain
            100, // depth
            null, // maxCrawlPages
            null, // department
            null, // searchParam
            null, // priceMin
            null, // priceMax
            null, // sortBy
            false, // usePostback
            false, // usePingback
            false // postTaskIfNotCached
        );

        $this->assertNull($result);
    }

    public function test_merchant_amazon_products_standard_advanced_creates_task_when_not_cached_and_posting_enabled()
    {
        $taskResponse = [
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'             => 'new-advanced-task-id',
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
            ->willReturn('test-advanced-cache-key');

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
                'test-advanced-cache-key',
                $this->anything(),
                $this->anything(),
                'merchant/amazon/products/task_post',
                'v3',
                $this->anything(),
                $this->anything(),
                1
            );

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/products/task_post" => Http::response($taskResponse, 200),
        ]);

        $this->client = new DataForSeoApiClient($mockCacheManager);
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonProductsStandardAdvanced(
            'new advanced task query',
            null, // url
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            100, // depth
            null, // maxCrawlPages
            null, // department
            null, // searchParam
            null, // priceMin
            null, // priceMax
            null, // sortBy
            false, // usePostback
            false, // usePingback
            true // postTaskIfNotCached
        );

        // Verify that a task creation request was made
        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/products/task_post" &&
                   $request->method() === 'POST' &&
                   isset($requestData['keyword']) &&
                   $requestData['keyword'] === 'new advanced task query' &&
                   isset($requestData['tag']) &&
                   $requestData['tag'] === 'test-advanced-cache-key';
        });

        // Verify we got the task creation response
        $this->assertIsArray($result);
        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertEquals('new-advanced-task-id', $responseData['tasks'][0]['id']);
    }

    public function test_merchant_amazon_products_standard_advanced_excludes_webhook_params_from_cache_key()
    {
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);

        // Verify buildApiParams is called with the correct excluded parameters
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('test-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn(['cached' => 'merchant advanced data']);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $this->client->merchantAmazonProductsStandardAdvanced(
            'test merchant query',
            null, // url
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            100, // depth
            null, // maxCrawlPages
            null, // department
            null, // searchParam
            null, // priceMin
            null, // priceMax
            null, // sortBy
            true, // usePostback (should be excluded)
            true, // usePingback (should be excluded)
            true // postTaskIfNotCached (should be excluded)
        );
    }

    public function test_merchant_amazon_products_standard_advanced_passes_webhook_parameters_to_task_post()
    {
        $taskResponse = [
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'             => 'webhook-merchant-task-id',
                    'status_code'    => 20101,
                    'status_message' => 'Task Created',
                ],
            ],
        ];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);

        // Standard method and TaskPost method both generate cache keys
        $mockCacheManager->expects($this->exactly(2))
            ->method('generateCacheKey')
            ->willReturn('webhook-merchant-cache-key');

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
            "{$this->apiBaseUrl}/merchant/amazon/products/task_post" => Http::response($taskResponse, 200),
        ]);

        $this->client = new DataForSeoApiClient($mockCacheManager);
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonProductsStandardAdvanced(
            'webhook merchant test',
            null, // url
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            100, // depth
            null, // maxCrawlPages
            null, // department
            null, // searchParam
            null, // priceMin
            null, // priceMax
            null, // sortBy
            true, // usePostback
            true, // usePingback
            true // postTaskIfNotCached
        );

        // Verify that task creation was called with webhook parameters
        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/products/task_post" &&
                   $request->method() === 'POST' &&
                   isset($requestData['keyword']) &&
                   $requestData['keyword'] === 'webhook merchant test' &&
                   isset($requestData['postback_data']) &&
                   $requestData['postback_data'] === 'advanced';
        });

        $this->assertIsArray($result);
        $this->assertEquals(200, $result['response_status_code']);
    }

    public function test_merchant_amazon_products_standard_advanced_basic_functionality()
    {
        $cachedResponse = ['test' => 'merchant standard advanced data'];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('merchant-advanced-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn($cachedResponse);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->merchantAmazonProductsStandardAdvanced('merchant advanced test query');

        $this->assertEquals($cachedResponse, $result);
    }

    public function test_merchant_amazon_products_standard_html_returns_cached_response_when_available()
    {
        $cachedResponse = [
            'tasks' => [
                [
                    'result' => [
                        [
                            'type' => 'amazon_product_html',
                            'html' => '<div>Amazon Product HTML</div>',
                        ],
                    ],
                ],
            ],
        ];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('html-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn($cachedResponse);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->merchantAmazonProductsStandardHtml(
            'cached html query',
            null, // url
            null, // priority
            'United States' // locationName
        );

        $this->assertEquals($cachedResponse, $result);
    }

    public function test_merchant_amazon_products_standard_html_returns_null_when_not_cached_and_posting_disabled()
    {
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('not-cached-html-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn(null); // Not cached

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->merchantAmazonProductsStandardHtml(
            'not cached html query',
            null, // url
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            100, // depth
            null, // maxCrawlPages
            null, // department
            null, // searchParam
            null, // priceMin
            null, // priceMax
            null, // sortBy
            false, // usePostback
            false, // usePingback
            false // postTaskIfNotCached
        );

        $this->assertNull($result);
    }

    public function test_merchant_amazon_products_standard_html_creates_task_when_not_cached_and_posting_enabled()
    {
        $taskResponse = [
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'             => 'new-html-task-id',
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
            ->willReturn('test-html-cache-key');

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
                'test-html-cache-key',
                $this->anything(),
                $this->anything(),
                'merchant/amazon/products/task_post',
                'v3',
                $this->anything(),
                $this->anything(),
                1
            );

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/products/task_post" => Http::response($taskResponse, 200),
        ]);

        $this->client = new DataForSeoApiClient($mockCacheManager);
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonProductsStandardHtml(
            'new html task query',
            null, // url
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            100, // depth
            null, // maxCrawlPages
            null, // department
            null, // searchParam
            null, // priceMin
            null, // priceMax
            null, // sortBy
            false, // usePostback
            false, // usePingback
            true // postTaskIfNotCached
        );

        // Verify that a task creation request was made
        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/products/task_post" &&
                   $request->method() === 'POST' &&
                   isset($requestData['keyword']) &&
                   $requestData['keyword'] === 'new html task query' &&
                   isset($requestData['tag']) &&
                   $requestData['tag'] === 'test-html-cache-key' &&
                   isset($requestData['postback_data']) &&
                   $requestData['postback_data'] === 'html';
        });

        // Verify we got the task creation response
        $this->assertIsArray($result);
        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertEquals('new-html-task-id', $responseData['tasks'][0]['id']);
    }

    public function test_merchant_amazon_products_standard_html_excludes_webhook_params_from_cache_key()
    {
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);

        // Verify buildApiParams is called with the correct excluded parameters
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('test-html-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn(['cached' => 'merchant html data']);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $this->client->merchantAmazonProductsStandardHtml(
            'test merchant html query',
            null, // url
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            100, // depth
            null, // maxCrawlPages
            null, // department
            null, // searchParam
            null, // priceMin
            null, // priceMax
            null, // sortBy
            true, // usePostback (should be excluded)
            true, // usePingback (should be excluded)
            true // postTaskIfNotCached (should be excluded)
        );
    }

    public function test_merchant_amazon_products_standard_html_passes_webhook_parameters_to_task_post()
    {
        $taskResponse = [
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'             => 'webhook-html-task-id',
                    'status_code'    => 20101,
                    'status_message' => 'Task Created',
                ],
            ],
        ];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);

        // Standard method and TaskPost method both generate cache keys
        $mockCacheManager->expects($this->exactly(2))
            ->method('generateCacheKey')
            ->willReturn('webhook-html-cache-key');

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
            "{$this->apiBaseUrl}/merchant/amazon/products/task_post" => Http::response($taskResponse, 200),
        ]);

        $this->client = new DataForSeoApiClient($mockCacheManager);
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonProductsStandardHtml(
            'webhook html test',
            null, // url
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            100, // depth
            null, // maxCrawlPages
            null, // department
            null, // searchParam
            null, // priceMin
            null, // priceMax
            null, // sortBy
            true, // usePostback
            true, // usePingback
            true // postTaskIfNotCached
        );

        // Verify that task creation was called with webhook parameters
        Http::assertSent(function ($request) {
            $requestData = $request->data()[0];

            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/products/task_post" &&
                   $request->method() === 'POST' &&
                   isset($requestData['keyword']) &&
                   $requestData['keyword'] === 'webhook html test' &&
                   isset($requestData['postback_data']) &&
                   $requestData['postback_data'] === 'html';
        });

        $this->assertIsArray($result);
        $this->assertEquals(200, $result['response_status_code']);
    }

    public function test_merchant_amazon_products_standard_html_basic_functionality()
    {
        $cachedResponse = ['test' => 'merchant standard html data'];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->expects($this->once())
            ->method('generateCacheKey')
            ->willReturn('merchant-html-cache-key');
        $mockCacheManager->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn($cachedResponse);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->merchantAmazonProductsStandardHtml('merchant html test query');

        $this->assertEquals($cachedResponse, $result);
    }

    public function test_merchant_amazon_asin_task_post_successful_request()
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/asin/task_post" => Http::response([
                'version'        => '0.1.20250114',
                'status_code'    => 20000,
                'status_message' => 'Ok.',
                'time'           => '0.3294 sec.',
                'cost'           => 0.0025,
                'tasks_count'    => 1,
                'tasks_error'    => 0,
                'tasks'          => [
                    [
                        'id'             => '11081545-3399-0545-0000-17b17e70d2b4',
                        'status_code'    => 20100,
                        'status_message' => 'Task Created.',
                        'time'           => '0.0094 sec.',
                        'cost'           => 0.0025,
                        'result_count'   => 0,
                        'path'           => [
                            'merchant',
                            'amazon',
                            'asin',
                            'task_post',
                        ],
                        'data' => [
                            'api'           => 'merchant',
                            'function'      => 'asin',
                            'asin'          => 'B08N5WRWNW',
                            'location_name' => 'United States',
                            'location_code' => 2840,
                            'language_name' => 'English',
                            'language_code' => 'en',
                            'tag'           => null,
                            'postback_url'  => null,
                            'postback_data' => null,
                            'pingback_url'  => null,
                        ],
                        'result' => null,
                    ],
                ],
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->merchantAmazonAsinTaskPost(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en'
        );

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/asin/task_post" &&
                   $request->method() === 'POST' &&
                   $request->hasHeader('Authorization') &&
                   $request->hasHeader('Content-Type', 'application/json') &&
                   is_array($request->data()) &&
                   isset($request->data()[0]) &&
                   $request->data()[0]['asin'] === 'B08N5WRWNW' &&
                   $request->data()[0]['location_name'] === 'United States' &&
                   $request->data()[0]['location_code'] === 2840 &&
                   $request->data()[0]['language_name'] === 'English' &&
                   $request->data()[0]['language_code'] === 'en';
        });

        // Make sure we used the Http::fake() response
        $body = $response['response']->json();
        $this->assertEquals(20000, $body['status_code']);
        $this->assertEquals('B08N5WRWNW', $body['tasks'][0]['data']['asin']);
    }

    public function test_merchant_amazon_asin_task_post_validates_empty_asin()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ASIN cannot be empty');

        $this->client->merchantAmazonAsinTaskPost('');
    }

    public function test_merchant_amazon_asin_task_post_validates_required_language_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either languageName or languageCode must be provided');

        $this->client->merchantAmazonAsinTaskPost(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            null,
            null
        );
    }

    public function test_merchant_amazon_asin_task_post_validates_required_location_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either locationName, locationCode, or locationCoordinate must be provided');

        $this->client->merchantAmazonAsinTaskPost(
            'B08N5WRWNW',
            null,
            null,
            null,
            null,
            'English',
            'en'
        );
    }

    public function test_merchant_amazon_asin_task_post_validates_priority_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Priority must be either 1 (normal) or 2 (high)');

        $this->client->merchantAmazonAsinTaskPost(
            'B08N5WRWNW',
            3,
            'United States',
            2840,
            null,
            'English',
            'en'
        );
    }

    public function test_merchant_amazon_asin_task_post_validates_local_reviews_sort()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('localReviewsSort must be either "helpful" or "recent"');

        $this->client->merchantAmazonAsinTaskPost(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en',
            null,
            null,
            'invalid'
        );
    }

    public function test_merchant_amazon_asin_task_post_validates_postback_data_requirement()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('postbackData is required when postbackUrl is specified');

        $this->client->merchantAmazonAsinTaskPost(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en',
            null,
            null,
            null,
            null,
            'https://example.com/postback'
        );
    }

    public static function merchantAmazonAsinTaskPostParametersProvider(): array
    {
        return [
            'basic_parameters' => [
                [
                    'asin'         => 'B08N5WRWNW',
                    'locationName' => 'United States',
                    'locationCode' => 2840,
                    'languageName' => 'English',
                    'languageCode' => 'en',
                ],
                [
                    'asin'          => 'B08N5WRWNW',
                    'location_name' => 'United States',
                    'location_code' => 2840,
                    'language_name' => 'English',
                    'language_code' => 'en',
                ],
            ],
            'with_priority_and_reviews' => [
                [
                    'asin'                 => 'B08N5WRWNW',
                    'priority'             => 2,
                    'locationName'         => 'United States',
                    'languageName'         => 'English',
                    'loadMoreLocalReviews' => true,
                    'localReviewsSort'     => 'recent',
                ],
                [
                    'asin'                    => 'B08N5WRWNW',
                    'priority'                => 2,
                    'location_name'           => 'United States',
                    'language_name'           => 'English',
                    'load_more_local_reviews' => true,
                    'local_reviews_sort'      => 'recent',
                ],
            ],
            'with_webhooks' => [
                [
                    'asin'         => 'B08N5WRWNW',
                    'locationCode' => 2840,
                    'languageCode' => 'en',
                    'tag'          => 'test-tag',
                    'postbackUrl'  => 'https://example.com/postback',
                    'postbackData' => 'advanced',
                    'pingbackUrl'  => 'https://example.com/pingback',
                ],
                [
                    'asin'          => 'B08N5WRWNW',
                    'location_code' => 2840,
                    'language_code' => 'en',
                    'tag'           => 'test-tag',
                    'postback_url'  => 'https://example.com/postback',
                    'postback_data' => 'advanced',
                    'pingback_url'  => 'https://example.com/pingback',
                ],
            ],
        ];
    }

    #[DataProvider('merchantAmazonAsinTaskPostParametersProvider')]
    public function test_merchant_amazon_asin_task_post_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/asin/task_post" => Http::response(['status_code' => 20000], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $this->client->merchantAmazonAsinTaskPost(...$parameters);

        Http::assertSent(function ($request) use ($expectedParams) {
            foreach ($expectedParams as $key => $value) {
                if (!isset($request->data()[0][$key]) || $request->data()[0][$key] !== $value) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_merchant_amazon_asin_task_post_handles_api_errors()
    {
        $errorResponse = [
            'version'        => '0.1.20250114',
            'status_code'    => 40401,
            'status_message' => 'Authentication failed',
            'time'           => '0.0121 sec.',
        ];

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/asin/task_post" => Http::response($errorResponse, 401),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->merchantAmazonAsinTaskPost(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en'
        );

        $this->assertEquals(401, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals(40401, $responseData['status_code']);
        $this->assertEquals('Authentication failed', $responseData['status_message']);
    }

    public function test_merchant_amazon_asin_task_get_advanced_successful_request()
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/asin/task_get/advanced/11081545-3399-0545-0000-17b17e70d2b4" => Http::response([
                'version'        => '0.1.20250114',
                'status_code'    => 20000,
                'status_message' => 'Ok.',
                'time'           => '0.1583 sec.',
                'cost'           => 0,
                'tasks_count'    => 1,
                'tasks_error'    => 0,
                'tasks'          => [
                    [
                        'id'             => '11081545-3399-0545-0000-17b17e70d2b4',
                        'status_code'    => 20000,
                        'status_message' => 'Ok.',
                        'time'           => '0.1478 sec.',
                        'cost'           => 0,
                        'result_count'   => 1,
                        'path'           => [
                            'merchant',
                            'amazon',
                            'asin',
                            'task_get',
                            'advanced',
                            '11081545-3399-0545-0000-17b17e70d2b4',
                        ],
                        'data' => [
                            'api'      => 'merchant',
                            'function' => 'asin',
                            'asin'     => 'B08N5WRWNW',
                        ],
                        'result' => [
                            [
                                'asin'   => 'B08N5WRWNW',
                                'type'   => 'asin',
                                'title'  => 'Echo Dot (4th Gen) | Smart speaker with Alexa | Charcoal',
                                'rating' => [
                                    'rating_type' => 'Max5',
                                    'value'       => 4.7,
                                    'votes_count' => 542046,
                                    'rating_max'  => 5,
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

        $response = $this->client->merchantAmazonAsinTaskGetAdvanced('11081545-3399-0545-0000-17b17e70d2b4');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/asin/task_get/advanced/11081545-3399-0545-0000-17b17e70d2b4" &&
                   $request->method() === 'GET';
        });

        // Make sure we used the Http::fake() response
        $body = $response['response']->json();
        $this->assertEquals(20000, $body['status_code']);
        $this->assertEquals('B08N5WRWNW', $body['tasks'][0]['result'][0]['asin']);
    }

    public function test_merchant_amazon_asin_task_get_advanced_passes_attributes_and_amount()
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/asin/task_get/advanced/test-task-id" => Http::response([
                'status_code' => 20000,
                'tasks'       => [],
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->merchantAmazonAsinTaskGetAdvanced('test-task-id', 'custom-attributes', 5);

        // Check that the request was made and response contains expected data
        // The taskGet method uses the provided attributes parameter when not null
        $this->assertEquals('custom-attributes', $response['request']['attributes']);
        $this->assertEquals(5, $response['request']['credits']);
    }

    public function test_merchant_amazon_asin_task_get_html_successful_request()
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/asin/task_get/html/11081545-3399-0545-0000-17b17e70d2b4" => Http::response([
                'version'        => '0.1.20250114',
                'status_code'    => 20000,
                'status_message' => 'Ok.',
                'time'           => '0.1583 sec.',
                'cost'           => 0,
                'tasks_count'    => 1,
                'tasks_error'    => 0,
                'tasks'          => [
                    [
                        'id'             => '11081545-3399-0545-0000-17b17e70d2b4',
                        'status_code'    => 20000,
                        'status_message' => 'Ok.',
                        'time'           => '0.1478 sec.',
                        'cost'           => 0,
                        'result_count'   => 1,
                        'path'           => [
                            'merchant',
                            'amazon',
                            'asin',
                            'task_get',
                            'html',
                            '11081545-3399-0545-0000-17b17e70d2b4',
                        ],
                        'data' => [
                            'api'      => 'merchant',
                            'function' => 'asin',
                            'asin'     => 'B08N5WRWNW',
                        ],
                        'result' => [
                            [
                                'asin' => 'B08N5WRWNW',
                                'type' => 'asin',
                                'html' => '<div class="amazon-product-page">...</div>',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->merchantAmazonAsinTaskGetHtml('11081545-3399-0545-0000-17b17e70d2b4');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/asin/task_get/html/11081545-3399-0545-0000-17b17e70d2b4" &&
                   $request->method() === 'GET';
        });

        // Make sure we used the Http::fake() response
        $body = $response['response']->json();
        $this->assertEquals(20000, $body['status_code']);
        $this->assertEquals('B08N5WRWNW', $body['tasks'][0]['result'][0]['asin']);
    }

    public function test_merchant_amazon_asin_task_get_html_passes_attributes_and_amount()
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/asin/task_get/html/test-task-id" => Http::response([
                'status_code' => 20000,
                'tasks'       => [],
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->merchantAmazonAsinTaskGetHtml('test-task-id', 'custom-attributes', 3);

        // Check that the request was made and response contains expected data
        // The taskGet method uses the provided attributes parameter when not null
        $this->assertEquals('custom-attributes', $response['request']['attributes']);
        $this->assertEquals(3, $response['request']['credits']);
    }

    public function test_merchant_amazon_asin_standard_returns_cached_response_when_available()
    {
        // Mock the cache manager to return a cached response
        $cachedResponse = [
            'tasks' => [
                [
                    'result' => [
                        ['asin' => 'B08N5WRWNW', 'title' => 'Echo Dot (4th Gen)'],
                    ],
                ],
            ],
        ];

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager
            ->method('generateCacheKey')
            ->willReturn('test-cache-key');
        $mockCacheManager
            ->method('getCachedResponse')
            ->willReturn($cachedResponse);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $response = $this->client->merchantAmazonAsinStandard(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en'
        );

        $this->assertEquals($cachedResponse, $response);
    }

    public function test_merchant_amazon_asin_standard_returns_null_when_not_cached_and_posting_disabled()
    {
        // Mock the cache manager to return null (not cached)
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager
            ->method('generateCacheKey')
            ->willReturn('test-cache-key');
        $mockCacheManager
            ->method('getCachedResponse')
            ->willReturn(null);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $response = $this->client->merchantAmazonAsinStandard(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en',
            null,
            null,
            null,
            'advanced',
            false,
            false,
            false // postTaskIfNotCached = false
        );

        $this->assertNull($response);
    }

    public function test_merchant_amazon_asin_standard_creates_task_when_not_cached_and_posting_enabled()
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/asin/task_post" => Http::response([
                'status_code' => 20000,
                'tasks'       => [
                    [
                        'id'             => 'test-task-id',
                        'status_code'    => 20100,
                        'status_message' => 'Task Created.',
                    ],
                ],
            ], 200),
        ]);

        // Mock the cache manager to return null (not cached) and allow requests
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager
            ->method('generateCacheKey')
            ->willReturn('test-cache-key');
        $mockCacheManager
            ->method('getCachedResponse')
            ->willReturn(null);
        $mockCacheManager
            ->method('allowRequest')
            ->willReturn(true);
        $mockCacheManager
            ->expects($this->once())
            ->method('incrementAttempts');

        $this->client = new DataForSeoApiClient($mockCacheManager);

        // Set up config for webhook URLs
        Config::set('api-cache.apis.dataforseo.postback_url', 'https://example.com/postback');
        Config::set('api-cache.apis.dataforseo.pingback_url', 'https://example.com/pingback');

        $response = $this->client->merchantAmazonAsinStandard(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en',
            null,
            null,
            null,
            'advanced',
            true,  // usePostback = true
            true,  // usePingback = true
            true   // postTaskIfNotCached = true
        );

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/asin/task_post" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]) &&
                   $request->data()[0]['asin'] === 'B08N5WRWNW' &&
                   $request->data()[0]['tag'] === 'test-cache-key' &&
                   $request->data()[0]['postback_url'] === 'https://example.com/postback' &&
                   $request->data()[0]['postback_data'] === 'advanced' &&
                   $request->data()[0]['pingback_url'] === 'https://example.com/pingback';
        });

        // Make sure we used the Http::fake() response
        $body = $response['response']->json();
        $this->assertEquals(20000, $body['status_code']);
    }

    public function test_merchant_amazon_asin_standard_validates_type_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('type must be one of: advanced, html');

        $this->client->merchantAmazonAsinStandard(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en',
            null,
            null,
            null,
            'invalid'
        );
    }

    public function test_merchant_amazon_asin_standard_excludes_webhook_params_from_cache_key()
    {
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager
            ->method('getCachedResponse')
            ->willReturn(null);

        // Capture the parameters passed to generateCacheKey
        $capturedParams = null;
        $mockCacheManager
            ->method('generateCacheKey')
            ->willReturnCallback(function ($clientName, $endpoint, $params, $method, $version) use (&$capturedParams) {
                $capturedParams = $params;

                return 'test-cache-key';
            });

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $this->client->merchantAmazonAsinStandard(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en',
            null,
            null,
            null,
            'advanced',
            true,  // usePostback
            true,  // usePingback
            false  // postTaskIfNotCached
        );

        // Verify that webhook and control parameters are excluded from cache key generation
        $this->assertNotNull($capturedParams);

        if (!is_array($capturedParams)) {
            $this->fail('Captured parameters were not an array.');
        } else {
            $this->assertArrayNotHasKey('type', $capturedParams);
            $this->assertArrayNotHasKey('usePostback', $capturedParams);
            $this->assertArrayNotHasKey('usePingback', $capturedParams);
            $this->assertArrayNotHasKey('postTaskIfNotCached', $capturedParams);

            // Verify that search parameters are included
            $this->assertArrayHasKey('asin', $capturedParams);
            $this->assertArrayHasKey('location_name', $capturedParams);
            $this->assertArrayHasKey('language_name', $capturedParams);
        }
    }

    public static function asinStandardMethodWrappersProvider(): array
    {
        return [
            ['merchantAmazonAsinStandardAdvanced', 'advanced'],
            ['merchantAmazonAsinStandardHtml', 'html'],
        ];
    }

    #[DataProvider('asinStandardMethodWrappersProvider')]
    public function test_merchant_amazon_asin_standard_wrapper_methods_call_main_method_with_correct_type(string $method, string $expectedType)
    {
        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager
            ->method('generateCacheKey')
            ->willReturn('test-cache-key');
        $mockCacheManager
            ->method('getCachedResponse')
            ->willReturn([
                'tasks' => [
                    ['result' => [['asin' => 'B08N5WRWNW']]],
                ],
            ]);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $response = $this->client->$method(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en'
        );

        $this->assertNotNull($response);
        $this->assertEquals('B08N5WRWNW', $response['tasks'][0]['result'][0]['asin']);
    }

    #[DataProvider('asinStandardMethodWrappersProvider')]
    public function test_merchant_amazon_asin_standard_wrapper_methods_pass_through_webhook_parameters(string $method, string $expectedType)
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/asin/task_post" => Http::response([
                'status_code' => 20000,
                'tasks'       => [
                    [
                        'id'             => 'test-task-id',
                        'status_code'    => 20100,
                        'status_message' => 'Task Created.',
                    ],
                ],
            ], 200),
        ]);

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager
            ->method('generateCacheKey')
            ->willReturn('test-cache-key');
        $mockCacheManager
            ->method('getCachedResponse')
            ->willReturn(null);
        $mockCacheManager
            ->method('allowRequest')
            ->willReturn(true);
        $mockCacheManager
            ->expects($this->once())
            ->method('incrementAttempts');

        $this->client = new DataForSeoApiClient($mockCacheManager);

        Config::set('api-cache.apis.dataforseo.postback_url', 'https://example.com/postback');

        $response = $this->client->$method(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en',
            null,
            null,
            null,
            true,  // usePostback
            false, // usePingback
            true   // postTaskIfNotCached
        );

        Http::assertSent(function ($request) use ($expectedType) {
            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/asin/task_post" &&
                   isset($request->data()[0]) &&
                   $request->data()[0]['asin'] === 'B08N5WRWNW' &&
                   $request->data()[0]['postback_url'] === 'https://example.com/postback' &&
                   $request->data()[0]['postback_data'] === $expectedType;
        });

        $body = $response['response']->json();
        $this->assertEquals(20000, $body['status_code']);
    }

    public function test_merchant_amazon_asin_standard_advanced_basic_functionality()
    {
        $response = $this->client->merchantAmazonAsinStandardAdvanced(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en'
        );

        $this->assertNull($response);
    }

    public function test_merchant_amazon_asin_standard_html_basic_functionality()
    {
        $response = $this->client->merchantAmazonAsinStandardHtml(
            'B08N5WRWNW',
            null,
            'United States',
            2840,
            null,
            'English',
            'en'
        );

        $this->assertNull($response);
    }

    public function test_merchant_amazon_sellers_task_post_successful_request()
    {
        $responseData = [
            'version'        => '0.1.20250425',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.01 sec.',
            'cost'           => 0.003,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => 'sellers-task-id',
                    'status_code'    => 20101,
                    'status_message' => 'Task Created',
                    'time'           => '0.01 sec.',
                    'cost'           => 0.003,
                    'result_count'   => 0,
                    'path'           => ['v3', 'merchant', 'amazon', 'sellers', 'task_post'],
                    'data'           => [
                        'api'           => 'merchant',
                        'function'      => 'amazon_sellers',
                        'se'            => 'amazon',
                        'asin'          => 'B085RFFC9Q',
                        'location_name' => 'United States',
                        'language_code' => 'en_US',
                        'location_code' => 2840,
                        'priority'      => 2,
                        'tag'           => 'test-cache-key',
                    ],
                    'result' => null,
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/sellers/task_post" => Http::response($responseData, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonSellersTaskPost(
            'B085RFFC9Q',
            2, // priority
            'United States' // locationName
        );

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/sellers/task_post" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['asin']) &&
                   $request->data()[0]['asin'] === 'B085RFFC9Q' &&
                   isset($request->data()[0]['location_name']) &&
                   $request->data()[0]['location_name'] === 'United States' &&
                   isset($request->data()[0]['priority']) &&
                   $request->data()[0]['priority'] === 2;
        });

        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertEquals('sellers-task-id', $responseData['tasks'][0]['id']);
    }

    public function test_merchant_amazon_sellers_task_post_validates_empty_asin()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ASIN cannot be empty');

        $this->client->merchantAmazonSellersTaskPost('');
    }

    public function test_merchant_amazon_sellers_task_post_validates_required_language_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either languageName or languageCode must be provided');

        $this->client->merchantAmazonSellersTaskPost(
            'B085RFFC9Q',
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            null, // languageName
            null // languageCode
        );
    }

    public function test_merchant_amazon_sellers_task_post_validates_required_location_parameters()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either locationName, locationCode, or locationCoordinate must be provided');

        $this->client->merchantAmazonSellersTaskPost(
            'B085RFFC9Q',
            null, // priority
            null, // locationName
            null, // locationCode
            null, // locationCoordinate
            'English' // languageName
        );
    }

    public function test_merchant_amazon_sellers_task_post_validates_priority_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Priority must be either 1 (normal) or 2 (high)');

        $this->client->merchantAmazonSellersTaskPost(
            'B085RFFC9Q',
            0, // priority
            'United States' // locationName
        );
    }

    public function test_merchant_amazon_sellers_task_post_validates_tag_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');

        $this->client->merchantAmazonSellersTaskPost(
            'B085RFFC9Q',
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            str_repeat('a', 256), // tag (too long)
        );
    }

    public function test_merchant_amazon_sellers_task_post_validates_postback_data_requirement()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('postbackData is required when postbackUrl is specified');

        $this->client->merchantAmazonSellersTaskPost(
            'B085RFFC9Q',
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            null, // tag
            'postback-url', // postbackUrl
            null // postbackData (missing, should trigger error)
        );
    }

    public static function merchantAmazonSellersTaskPostParametersProvider(): array
    {
        return [
            'basic parameters' => [
                // Method parameters: asin, priority, locationName, locationCode, locationCoordinate, languageName, languageCode, seDomain, tag, postbackUrl, postbackData, pingbackUrl, additionalParams
                ['B085RFFC9Q', null, 'United States', null, null, 'English', 'en_US'],
                [
                    'asin'          => 'B085RFFC9Q',
                    'location_name' => 'United States',
                    'language_name' => 'English',
                    'language_code' => 'en_US',
                ],
            ],
            'with priority and tag' => [
                ['B07ZPKN6YR', 1, null, 2826, null, 'English', null, null, 'test-tag'],
                [
                    'asin'          => 'B07ZPKN6YR',
                    'priority'      => 1,
                    'location_code' => 2826,
                    'language_name' => 'English',
                    'tag'           => 'test-tag',
                ],
            ],
        ];
    }

    #[DataProvider('merchantAmazonSellersTaskPostParametersProvider')]
    public function test_merchant_amazon_sellers_task_post_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $responseData = [
            'version'        => '0.1.20250425',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.01 sec.',
            'cost'           => 0.003,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [['id' => 'sellers-task-id']],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/sellers/task_post" => Http::response($responseData, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        // Call the method with parameters
        $this->client->merchantAmazonSellersTaskPost(...$parameters);

        // Check that the request was sent with the expected parameters
        Http::assertSent(function ($request) use ($expectedParams) {
            $requestData = $request->data()[0];

            foreach ($expectedParams as $key => $value) {
                if (!isset($requestData[$key]) || $requestData[$key] !== $value) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_merchant_amazon_sellers_task_post_handles_api_errors()
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/sellers/task_post" => Http::response([
                'version'        => '0.1.20250425',
                'status_code'    => 40000,
                'status_message' => 'API error',
            ], 400),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonSellersTaskPost(
            'B085RFFC9Q',
            null,
            'United States'
        );

        $this->assertEquals(400, $result['response_status_code']);
    }

    public function test_merchant_amazon_sellers_task_get_advanced_successful_request()
    {
        $responseData = [
            'version'        => '0.1.20250425',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.15 sec.',
            'cost'           => 0.0075,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => 'sellers-task-id',
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.15 sec.',
                    'cost'           => 0.0075,
                    'result_count'   => 1,
                    'path'           => ['v3', 'merchant', 'amazon', 'sellers', 'task_get', 'advanced'],
                    'data'           => [
                        'api'       => 'merchant',
                        'function'  => 'amazon_sellers',
                        'se'        => 'amazon',
                        'asin'      => 'B085RFFC9Q',
                        'se_domain' => 'amazon.com',
                    ],
                    'result' => [
                        [
                            'asin'          => 'B085RFFC9Q',
                            'type'          => 'sellers',
                            'se_domain'     => 'amazon.com',
                            'location_code' => 2840,
                            'language_code' => 'en_US',
                            'check_url'     => 'https://www.amazon.com/dp/B085RFFC9Q',
                            'datetime'      => '2025-06-06 19:30:00 +00:00',
                            'items_count'   => 3,
                            'items'         => [
                                [
                                    'type'          => 'seller',
                                    'rank_group'    => 1,
                                    'rank_absolute' => 1,
                                    'position'      => 'sellers_main',
                                    'seller_name'   => 'Amazon.com',
                                    'seller_url'    => 'https://www.amazon.com/sp?_encoding=UTF8&seller=ATVPDKIKX0DER',
                                    'seller_rating' => 4.5,
                                    'rating_count'  => 1000000,
                                    'price'         => [
                                        'current'  => 29.99,
                                        'currency' => 'USD',
                                    ],
                                    'condition' => 'new',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/sellers/task_get/advanced/sellers-task-id" => Http::response($responseData, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonSellersTaskGetAdvanced('sellers-task-id');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/sellers/task_get/advanced/sellers-task-id" &&
                   $request->method() === 'GET';
        });

        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertEquals('sellers-task-id', $responseData['tasks'][0]['id']);
    }

    public function test_merchant_amazon_sellers_task_get_advanced_passes_attributes_and_amount()
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/sellers/task_get/advanced/test-id" => Http::response(['tasks' => []], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonSellersTaskGetAdvanced('test-id', 'test-attributes', 5);

        $this->assertEquals(200, $result['response_status_code']);
    }

    public function test_merchant_amazon_sellers_task_get_html_successful_request()
    {
        $responseData = [
            'version'        => '0.1.20250425',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.15 sec.',
            'cost'           => 0.0075,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => 'sellers-task-id',
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.15 sec.',
                    'cost'           => 0.0075,
                    'result_count'   => 1,
                    'path'           => ['v3', 'merchant', 'amazon', 'sellers', 'task_get', 'html'],
                    'data'           => [
                        'api'      => 'merchant',
                        'function' => 'amazon_sellers',
                        'se'       => 'amazon',
                        'asin'     => 'B085RFFC9Q',
                    ],
                    'result' => [
                        [
                            'asin'          => 'B085RFFC9Q',
                            'type'          => 'sellers',
                            'se_domain'     => 'amazon.com',
                            'location_code' => 2840,
                            'language_code' => 'en_US',
                            'check_url'     => 'https://www.amazon.com/dp/B085RFFC9Q',
                            'datetime'      => '2025-06-06 19:30:00 +00:00',
                            'html'          => '<html><head><title>Amazon Product Sellers</title></head><body>...</body></html>',
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/sellers/task_get/html/sellers-task-id" => Http::response($responseData, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonSellersTaskGetHtml('sellers-task-id');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/sellers/task_get/html/sellers-task-id" &&
                   $request->method() === 'GET';
        });

        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertEquals('sellers-task-id', $responseData['tasks'][0]['id']);
        $this->assertNotEmpty($responseData['tasks'][0]['result'][0]['html']);
    }

    public function test_merchant_amazon_sellers_task_get_html_passes_attributes_and_amount()
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/sellers/task_get/html/test-id" => Http::response(['tasks' => []], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonSellersTaskGetHtml('test-id', 'test-attributes', 5);

        $this->assertEquals(200, $result['response_status_code']);
    }

    public function test_merchant_amazon_sellers_standard_returns_cached_response_when_available()
    {
        $cachedResponse = [
            'cached' => true,
            'data'   => ['sellers' => []],
        ];

        // Mock the cache manager to return a cached response
        $mockCacheManager = \Mockery::mock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->shouldReceive('generateCacheKey')->andReturn('test-cache-key');
        $mockCacheManager->shouldReceive('getCachedResponse')->andReturn($cachedResponse);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->merchantAmazonSellersStandard('B085RFFC9Q');

        $this->assertEquals($cachedResponse, $result);
    }

    public function test_merchant_amazon_sellers_standard_returns_null_when_not_cached_and_posting_disabled()
    {
        // Mock the cache manager to return null (not cached)
        $mockCacheManager = \Mockery::mock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->shouldReceive('generateCacheKey')->andReturn('test-cache-key');
        $mockCacheManager->shouldReceive('getCachedResponse')->andReturn(null);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->merchantAmazonSellersStandard(
            'B085RFFC9Q',
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            'advanced', // type
            false, // usePostback
            false, // usePingback
            false // postTaskIfNotCached - disabled
        );

        $this->assertNull($result);
    }

    public function test_merchant_amazon_sellers_standard_creates_task_when_not_cached_and_posting_enabled()
    {
        $id           = 'created-task-id';
        $responseData = [
            'tasks' => [['id' => $id]],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/sellers/task_post" => Http::response($responseData, 200),
        ]);

        // Mock the cache manager to return null (not cached)
        $mockCacheManager = \Mockery::mock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->shouldReceive('generateCacheKey')->andReturn('test-cache-key');
        $mockCacheManager->shouldReceive('getCachedResponse')->andReturn(null);
        $mockCacheManager->shouldReceive('clearRateLimit')->andReturn(null);
        $mockCacheManager->shouldReceive('allowRequest')->andReturn(true);
        $mockCacheManager->shouldReceive('incrementAttempts')->andReturn(null);
        $mockCacheManager->shouldReceive('storeResponse')->andReturnNull();

        $this->client = new DataForSeoApiClient($mockCacheManager);
        $this->client->clearRateLimit();

        $result = $this->client->merchantAmazonSellersStandard(
            'B085RFFC9Q',
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            'advanced', // type
            false, // usePostback
            false, // usePingback
            true // postTaskIfNotCached - enabled
        );

        Http::assertSent(function ($request) {
            if ($request->url() !== "{$this->apiBaseUrl}/merchant/amazon/sellers/task_post" ||
                $request->method() !== 'POST') {
                return false;
            }

            $requestData = $request->data()[0];
            if (!isset($requestData)) {
                return false;
            }

            return isset($requestData['asin']) &&
                   $requestData['asin'] === 'B085RFFC9Q' &&
                   isset($requestData['tag']) &&
                   $requestData['tag'] === 'test-cache-key';
        });

        // Make sure we used the Http::fake() response
        $body = $result['response']->json();
        $this->assertEquals($id, $body['tasks'][0]['id']);
    }

    public function test_merchant_amazon_sellers_standard_validates_type_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('type must be one of: advanced, html');

        // Mock the cache manager
        $mockCacheManager = \Mockery::mock(\FOfX\ApiCache\ApiCacheManager::class);
        $this->client     = new DataForSeoApiClient($mockCacheManager);

        $this->client->merchantAmazonSellersStandard(
            'B085RFFC9Q',
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            'invalid-type' // type - invalid
        );
    }

    public function test_merchant_amazon_sellers_standard_excludes_webhook_params_from_cache_key()
    {
        // Mock the cache manager to check what parameters are passed to generateCacheKey
        $mockCacheManager = \Mockery::mock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->shouldReceive('generateCacheKey')->once()->with(
            'dataforseo',
            'merchant/amazon/sellers/task_get/advanced',
            \Mockery::on(function ($params) {
                // Ensure webhook and control params are not included in cache key generation
                return !isset($params['use_postback']) &&
                       !isset($params['use_pingback']) &&
                       !isset($params['post_task_if_not_cached']) &&
                       !isset($params['type']);
            }),
            'POST',
            'v3'
        )->andReturn('test-cache-key');
        $mockCacheManager->shouldReceive('getCachedResponse')->andReturn(null);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $this->client->merchantAmazonSellersStandard(
            'B085RFFC9Q',
            null, // priority
            'United States', // locationName
            null, // locationCode
            null, // locationCoordinate
            'English', // languageName
            null, // languageCode
            null, // seDomain
            'advanced', // type
            true, // usePostback
            true, // usePingback
            false // postTaskIfNotCached
        );
    }

    public static function sellersStandardMethodWrappersProvider(): array
    {
        return [
            ['merchantAmazonSellersStandardAdvanced', 'advanced'],
            ['merchantAmazonSellersStandardHtml', 'html'],
        ];
    }

    #[DataProvider('sellersStandardMethodWrappersProvider')]
    public function test_merchant_amazon_sellers_standard_wrapper_methods_call_main_method_with_correct_type(string $method, string $expectedType)
    {
        // Mock the cache manager
        $mockCacheManager = \Mockery::mock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->shouldReceive('generateCacheKey')->andReturn('test-cache-key');
        $mockCacheManager->shouldReceive('getCachedResponse')->andReturn([
            'tasks' => [
                ['result' => [['asin' => 'B085RFFC9Q', 'type' => $expectedType]]],
            ],
        ]);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->$method('B085RFFC9Q', null, 'United States');

        // Assert that we got a cached response back
        $this->assertNotNull($result);
        $this->assertArrayHasKey('tasks', $result);
        $this->assertEquals('B085RFFC9Q', $result['tasks'][0]['result'][0]['asin']);
        $this->assertEquals($expectedType, $result['tasks'][0]['result'][0]['type']);
    }

    #[DataProvider('sellersStandardMethodWrappersProvider')]
    public function test_merchant_amazon_sellers_standard_wrapper_methods_pass_through_webhook_parameters(string $method, string $expectedType)
    {
        Http::fake([
            "{$this->apiBaseUrl}/merchant/amazon/sellers/task_post" => Http::response([
                'status_code' => 20000,
                'tasks'       => [
                    [
                        'id'             => 'test-task-id',
                        'status_code'    => 20100,
                        'status_message' => 'Task Created.',
                    ],
                ],
            ], 200),
        ]);

        $mockCacheManager = $this->createMock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager
            ->method('generateCacheKey')
            ->willReturn('test-cache-key');
        $mockCacheManager
            ->method('getCachedResponse')
            ->willReturn(null);
        $mockCacheManager
            ->method('allowRequest')
            ->willReturn(true);
        $mockCacheManager
            ->expects($this->once())
            ->method('incrementAttempts');
        $mockCacheManager
            ->method('storeResponse');

        $this->client = new DataForSeoApiClient($mockCacheManager);

        Config::set('api-cache.apis.dataforseo.postback_url', 'https://example.com/postback');

        $response = $this->client->$method(
            'B085RFFC9Q',
            null,
            'United States',
            null,
            null,
            'English',
            null,
            null,
            true,  // usePostback
            false, // usePingback
            true   // postTaskIfNotCached
        );

        Http::assertSent(function ($request) use ($expectedType) {
            return $request->url() === "{$this->apiBaseUrl}/merchant/amazon/sellers/task_post" &&
                   isset($request->data()[0]) &&
                   $request->data()[0]['asin'] === 'B085RFFC9Q' &&
                   $request->data()[0]['postback_url'] === 'https://example.com/postback' &&
                   $request->data()[0]['postback_data'] === $expectedType;
        });

        $body = $response['response']->json();
        $this->assertEquals(20000, $body['status_code']);
    }

    public function test_merchant_amazon_sellers_standard_advanced_basic_functionality()
    {
        // Mock the cache manager to return cached data so no HTTP request is made
        $mockCacheManager = \Mockery::mock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->shouldReceive('generateCacheKey')->andReturn('test-cache-key');
        $mockCacheManager->shouldReceive('getCachedResponse')->andReturn([
            'tasks' => [['result' => [['asin' => 'B085RFFC9Q', 'type' => 'advanced']]]],
        ]);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->merchantAmazonSellersStandardAdvanced('B085RFFC9Q');
        $this->assertNotNull($result); // Method exists and returns mocked data
    }

    public function test_merchant_amazon_sellers_standard_html_basic_functionality()
    {
        // Mock the cache manager to return cached data so no HTTP request is made
        $mockCacheManager = \Mockery::mock(\FOfX\ApiCache\ApiCacheManager::class);
        $mockCacheManager->shouldReceive('generateCacheKey')->andReturn('test-cache-key');
        $mockCacheManager->shouldReceive('getCachedResponse')->andReturn([
            'tasks' => [['result' => [['asin' => 'B085RFFC9Q', 'type' => 'html']]]],
        ]);

        $this->client = new DataForSeoApiClient($mockCacheManager);

        $result = $this->client->merchantAmazonSellersStandardHtml('B085RFFC9Q');
        $this->assertNotNull($result); // Method exists and returns mocked data
    }
}

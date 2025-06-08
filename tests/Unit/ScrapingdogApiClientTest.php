<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\ScrapingdogApiClient;
use FOfX\ApiCache\RateLimitException;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

class ScrapingdogApiClientTest extends TestCase
{
    protected ScrapingdogApiClient $client;
    protected string $apiKey     = 'test-api-key';
    protected string $apiBaseUrl = 'https://api.scrapingdog.com';

    protected function setUp(): void
    {
        parent::setUp();

        // Configure test environment
        Config::set('api-cache.apis.scrapingdog.api_key', $this->apiKey);
        Config::set('api-cache.apis.scrapingdog.base_url', $this->apiBaseUrl);
        Config::set('api-cache.apis.scrapingdog.rate_limit_max_attempts', 10);
        Config::set('api-cache.apis.scrapingdog.rate_limit_decay_seconds', 10);

        $this->client = new ScrapingdogApiClient();
        $this->client->clearRateLimit();
    }

    /**
     * Get service providers to register for testing.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array
     */
    protected function getPackageProviders($app): array
    {
        return [ApiCacheServiceProvider::class];
    }

    /**
     * Extract query parameters from request URL as strings (HTTP reality)
     *
     * This preserves the actual HTTP query string values without type conversion,
     * ensuring tests accurately reflect what's sent over the wire.
     *
     * @param mixed $request
     *
     * @return array
     */
    private function getQueryParams($request): array
    {
        $query = [];
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_initializes_with_correct_configuration()
    {
        $this->assertEquals('scrapingdog', $this->client->getClientName());
        $this->assertEquals($this->apiBaseUrl, $this->client->getBaseUrl());
        $this->assertEquals($this->apiKey, $this->client->getApiKey());
    }

    public function test_auth_methods_return_expected_values()
    {
        // Scrapingdog uses query parameters, not headers for auth
        $this->assertEmpty($this->client->getAuthHeaders());
        $this->assertEquals(['api_key' => $this->apiKey], $this->client->getAuthParams());
    }

    /**
     * Data provider for credit calculation tests
     */
    public static function calculateCreditsDataProvider(): array
    {
        return [
            'basic scraping' => [
                'expected'         => 1,
                'dynamic'          => false,
                'premium'          => false,
                'ai_query'         => null,
                'ai_extract_rules' => null,
                'super_proxy'      => null,
            ],
            'default params' => [
                'expected'         => 1,
                'dynamic'          => null,
                'premium'          => null,
                'ai_query'         => null,
                'ai_extract_rules' => null,
                'super_proxy'      => null,
            ],
            'dynamic scraping' => [
                'expected'         => 5,
                'dynamic'          => true,
                'premium'          => false,
                'ai_query'         => null,
                'ai_extract_rules' => null,
                'super_proxy'      => null,
            ],
            'premium scraping' => [
                'expected'         => 10,
                'dynamic'          => false,
                'premium'          => true,
                'ai_query'         => null,
                'ai_extract_rules' => null,
                'super_proxy'      => null,
            ],
            'dynamic premium scraping' => [
                'expected'         => 25,
                'dynamic'          => true,
                'premium'          => true,
                'ai_query'         => null,
                'ai_extract_rules' => null,
                'super_proxy'      => null,
            ],
            'super proxy' => [
                'expected'         => 75,
                'dynamic'          => null,
                'premium'          => null,
                'ai_query'         => null,
                'ai_extract_rules' => null,
                'super_proxy'      => true,
            ],
            'ai query' => [
                'expected'         => 6,
                'dynamic'          => false,
                'premium'          => false,
                'ai_query'         => 'Extract all the product information',
                'ai_extract_rules' => null,
                'super_proxy'      => null,
            ],
            'ai extract rules' => [
                'expected'         => 6,
                'dynamic'          => false,
                'premium'          => false,
                'ai_query'         => null,
                'ai_extract_rules' => ['title' => 'h1'],
                'super_proxy'      => null,
            ],
            'dynamic with ai query' => [
                'expected'         => 10,
                'dynamic'          => true,
                'premium'          => false,
                'ai_query'         => 'Extract all the product information',
                'ai_extract_rules' => null,
                'super_proxy'      => null,
            ],
            'premium with ai extract rules' => [
                'expected'         => 15,
                'dynamic'          => false,
                'premium'          => true,
                'ai_query'         => null,
                'ai_extract_rules' => ['title' => 'Extract the title'],
                'super_proxy'      => null,
            ],
            'dynamic premium with ai query' => [
                'expected'         => 30,
                'dynamic'          => true,
                'premium'          => true,
                'ai_query'         => 'Extract all the product information',
                'ai_extract_rules' => null,
                'super_proxy'      => null,
            ],
        ];
    }

    #[DataProvider('calculateCreditsDataProvider')]
    public function test_calculate_credits(int $expected, ?bool $dynamic, ?bool $premium, ?string $ai_query, ?array $ai_extract_rules, ?bool $super_proxy)
    {
        $this->assertEquals($expected, $this->client->calculateCredits($dynamic, $premium, $ai_query, $ai_extract_rules, $super_proxy));
    }

    public function test_scrape_throws_exception_for_mutually_exclusive_params()
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->client->scrape(
            'https://example.com',
            true,  // dynamic
            true,  // premium
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            true   // super_proxy
        );
    }

    public function test_scrape_makes_successful_request()
    {
        $testUrl  = 'https://example.com';
        $testHtml = '<html><body><h1>Example Domain</h1></body></html>';

        Http::fake([
            "{$this->apiBaseUrl}/scrape*" => Http::response($testHtml, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ScrapingdogApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->scrape($testUrl);

        Http::assertSent(function ($request) use ($testUrl) {
            $query = $this->getQueryParams($request);

            $this->assertTrue(Str::startsWith($request->url(), "{$this->apiBaseUrl}/scrape"));
            $this->assertSame('GET', $request->method());
            $this->assertSame($this->apiKey, $query['api_key']);
            $this->assertSame($testUrl, $query['url']);
            $this->assertSame('0', $query['dynamic']);

            return true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response']->status());
        $this->assertEquals($testHtml, $response['response']->body());
    }

    public function test_scrape_with_dynamic_rendering()
    {
        $testUrl  = 'https://example.com';
        $testHtml = '<html><body>Dynamic content</body></html>';

        Http::fake([
            "{$this->apiBaseUrl}/scrape*" => Http::response($testHtml, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ScrapingdogApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->scrape($testUrl, true);

        Http::assertSent(function ($request) use ($testUrl) {
            $query = $this->getQueryParams($request);

            $this->assertTrue(Str::startsWith($request->url(), "{$this->apiBaseUrl}/scrape"));
            $this->assertSame($testUrl, $query['url']);
            $this->assertSame('1', $query['dynamic']);

            return true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response']->status());
        $this->assertEquals($testHtml, $response['response']->body());
    }

    public function test_scrape_with_advanced_options()
    {
        $testUrl  = 'https://example.com';
        $testHtml = '<html><body>Custom response</body></html>';

        Http::fake([
            "{$this->apiBaseUrl}/scrape*" => Http::response($testHtml, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ScrapingdogApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->scrape(
            $testUrl,
            false, // dynamic
            true,  // premium
            true,  // custom_headers
            5000,  // wait
            'us',  // country
            '12345', // session_number
            true,  // image
            true   // markdown
        );

        Http::assertSent(function ($request) use ($testUrl) {
            $query = $this->getQueryParams($request);

            $this->assertTrue(Str::startsWith($request->url(), "{$this->apiBaseUrl}/scrape"));
            $this->assertSame($testUrl, $query['url']);
            $this->assertSame('0', $query['dynamic']);
            $this->assertSame('1', $query['premium']);
            $this->assertSame('1', $query['custom_headers']);
            $this->assertSame('5000', $query['wait']);
            $this->assertSame('us', $query['country']);
            $this->assertSame('12345', $query['session_number']);
            $this->assertSame('1', $query['image']);
            $this->assertSame('1', $query['markdown']);

            return true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response']->status());
        $this->assertEquals($testHtml, $response['response']->body());
    }

    public function test_scrape_with_ai_query()
    {
        $testUrl      = 'https://example.com';
        $aiQuery      = 'Extract all product information';
        $testResponse = 'Product Name: Example, Price: $10';

        Http::fake([
            "{$this->apiBaseUrl}/scrape*" => Http::response($testResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ScrapingdogApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->scrape(
            $testUrl,
            false,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $aiQuery
        );

        Http::assertSent(function ($request) use ($testUrl, $aiQuery) {
            $query = $this->getQueryParams($request);

            $this->assertTrue(Str::startsWith($request->url(), "{$this->apiBaseUrl}/scrape"));
            $this->assertSame($testUrl, $query['url']);
            $this->assertSame($aiQuery, $query['ai_query']);

            return true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response']->status());
        $this->assertEquals($testResponse, $response['response']->body());
    }

    public function test_scrape_with_ai_extract_rules()
    {
        $testUrl      = 'https://example.com';
        $extractRules = [
            'title' => 'Extract the title',
            'price' => 'Extract the price',
        ];
        $testResponse = json_encode(['title' => 'Example Product', 'price' => '$10.99']);

        Http::fake([
            "{$this->apiBaseUrl}/scrape*" => Http::response(
                $testResponse,
                200,
                ['Content-Type' => 'application/json']
            ),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ScrapingdogApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->scrape(
            $testUrl,
            false,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $extractRules
        );

        Http::assertSent(function ($request) use ($testUrl, $extractRules) {
            $query = $this->getQueryParams($request);

            $this->assertTrue(Str::startsWith($request->url(), "{$this->apiBaseUrl}/scrape"));
            $this->assertSame($testUrl, $query['url']);
            $this->assertSame(json_encode($extractRules), $query['ai_extract_rules']);

            return true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response']->status());
        $this->assertEquals($testResponse, $response['response']->body());
    }

    public function test_scrape_with_additional_params()
    {
        $testUrl          = 'https://example.com';
        $additionalParams = ['custom_param' => 'value'];
        $testHtml         = '<html><body>Custom response</body></html>';
        ;

        Http::fake([
            "{$this->apiBaseUrl}/scrape*" => Http::response($testHtml, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ScrapingdogApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->scrape(
            $testUrl,
            false,
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
            $additionalParams
        );

        Http::assertSent(function ($request) use ($testUrl) {
            $query = $this->getQueryParams($request);

            $this->assertTrue(Str::startsWith($request->url(), "{$this->apiBaseUrl}/scrape"));
            $this->assertSame($testUrl, $query['url']);
            $this->assertSame('value', $query['custom_param']);

            return true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response']->status());
        $this->assertEquals($testHtml, $response['response']->body());
    }

    public function test_caches_responses()
    {
        $testUrl   = 'https://example.com';
        $testHtml1 = '<html><body>First response</body></html>';
        $testHtml2 = '<html><body>Second response</body></html>';

        // The second response should not be used, as the first response is cached
        Http::fake([
            "{$this->apiBaseUrl}/scrape*" => Http::sequence()
                ->push($testHtml1, 200)
                ->push($testHtml2, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ScrapingdogApiClient();
        $this->client->clearRateLimit();

        // First request
        $response1 = $this->client->scrape($testUrl);

        // Second request (should be cached)
        $response2 = $this->client->scrape($testUrl);

        // Verify only one HTTP request was made
        Http::assertSentCount(1);

        // Both responses should be identical as only the first response was used
        $this->assertEquals($response1['response']->body(), $response2['response']->body());
        $this->assertEquals($testHtml1, $response2['response']->body());
        $this->assertTrue($response2['is_cached']);
    }

    public function test_enforces_rate_limits()
    {
        $testUrl  = 'https://example.com';
        $testHtml = '<html><body>Test response</body></html>';

        Http::fake([
            "{$this->apiBaseUrl}/scrape*" => Http::response($testHtml, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ScrapingdogApiClient();
        $this->client->clearRateLimit();

        // Configure a lower rate limit for testing
        Config::set('api-cache.apis.scrapingdog.rate_limit_max_attempts', 3);

        // Make requests until rate limit is exceeded
        $this->expectException(RateLimitException::class);

        for ($i = 0; $i <= 3; $i++) {
            // Disable caching to ensure we hit the rate limit
            $this->client->setUseCache(false);
            $response = $this->client->scrape($testUrl);

            // Make sure we used the Http::fake() response
            $this->assertEquals(200, $response['response']->status());
            $this->assertEquals($testHtml, $response['response']->body());
        }
    }

    public function test_handles_api_errors()
    {
        $testUrl      = 'https://example.com';
        $testResponse = json_encode(['error' => 'Invalid API key']);

        Http::fake([
            "{$this->apiBaseUrl}/scrape*" => Http::response($testResponse, 401),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new ScrapingdogApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->scrape($testUrl);

        // Make sure we used the Http::fake() response
        $this->assertEquals(401, $response['response']->status());
        $this->assertEquals($testResponse, $response['response']->body());
    }
}

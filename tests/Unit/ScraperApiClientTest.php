<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\ScraperApiClient;
use FOfX\ApiCache\ApiCacheManager;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class ScraperApiClientTest extends TestCase
{
    private ScraperApiClient $client;
    private ApiCacheManager $cacheManager;
    private string $apiKey;
    private string $clientName;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up test configuration
        Config::set('api-cache.apis.scraperapi.api_key', 'test_api_key');
        Config::set('api-cache.apis.scraperapi.base_url', 'https://api.scraperapi.com');
        Config::set('api-cache.apis.scraperapi.rate_limit_max_attempts', 1000);
        Config::set('api-cache.apis.scraperapi.rate_limit_decay_seconds', 60);

        $this->apiKey       = Config::get('api-cache.apis.scraperapi.api_key');
        $this->clientName   = 'scraperapi';
        $this->cacheManager = app(ApiCacheManager::class);
        $this->client       = new ScraperApiClient();
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

    public function test_initialization()
    {
        $this->assertEquals($this->apiKey, $this->client->getApiKey());
        $this->assertEquals('https://api.scraperapi.com', $this->client->getBaseUrl());
        $this->assertEquals('scraperapi', $this->client->getClientName());
    }

    public function test_scrape_success()
    {
        Http::fake([
            'api.scraperapi.com/*' => Http::response([
                'status'  => 'success',
                'body'    => 'Test response',
                'headers' => ['Content-Type' => 'text/html'],
            ], 200),
        ]);

        // Reinitialize client after Http::fake()
        $this->client = new ScraperApiClient();
        $this->client->clearRateLimit();

        $url    = 'https://example.com';
        $result = $this->client->scrape($url);

        $this->assertArrayHasKey('request', $result);
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('response_status_code', $result);
        $this->assertArrayHasKey('response_size', $result);
        $this->assertArrayHasKey('response_time', $result);
        $this->assertArrayHasKey('is_cached', $result);

        $this->assertEquals(200, $result['response_status_code']);

        // Make sure we used the Http::fake() response
        $responseData = $result['response']->json();
        $this->assertEquals('Test response', $responseData['body']);

        $this->assertFalse($result['is_cached']);
        $this->assertEquals(999, $this->cacheManager->getRemainingAttempts($this->clientName));
    }

    public function test_scrape_with_large_response()
    {
        $largeResponse = str_repeat('Test response ', 1000);
        Http::fake([
            'api.scraperapi.com/*' => Http::response([
                'status'  => 'success',
                'body'    => $largeResponse,
                'headers' => ['Content-Type' => 'text/html'],
            ], 200),
        ]);

        // Reinitialize client after Http::fake()
        $this->client = new ScraperApiClient();
        $this->client->clearRateLimit();

        $url    = 'https://example.com';
        $result = $this->client->scrape($url);

        $this->assertArrayHasKey('request', $result);
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('response_status_code', $result);
        $this->assertArrayHasKey('response_size', $result);
        $this->assertArrayHasKey('response_time', $result);
        $this->assertArrayHasKey('is_cached', $result);

        $this->assertEquals(200, $result['response_status_code']);

        // Make sure we used the Http::fake() response
        $responseData = $result['response']->json();
        $this->assertEquals($largeResponse, $responseData['body']);
    }

    public function test_scrape_with_cache()
    {
        // First request - cache miss
        Http::fake([
            'api.scraperapi.com/*' => Http::response([
                'status'  => 'success',
                'body'    => 'Test response',
                'headers' => ['Content-Type' => 'text/html'],
            ], 200),
        ]);

        // Reinitialize client after Http::fake()
        $this->client = new ScraperApiClient();
        $this->client->clearRateLimit();

        $url                 = 'https://example.com';
        $result1             = $this->client->scrape($url);
        $remainingAfterFirst = $this->cacheManager->getRemainingAttempts($this->clientName);

        // Second request - should be cached
        $result2              = $this->client->scrape($url);
        $remainingAfterSecond = $this->cacheManager->getRemainingAttempts($this->clientName);

        $this->assertArrayHasKey('request', $result1);
        $this->assertArrayHasKey('response', $result1);
        $this->assertArrayHasKey('is_cached', $result1);
        $this->assertArrayHasKey('request', $result2);
        $this->assertArrayHasKey('response', $result2);
        $this->assertArrayHasKey('is_cached', $result2);

        $this->assertFalse($result1['is_cached']);
        $this->assertTrue($result2['is_cached']);
        $this->assertEquals($remainingAfterFirst, $remainingAfterSecond);

        // Make sure we used the Http::fake() response
        $responseData = $result1['response']->json();
        $this->assertEquals('Test response', $responseData['body']);
    }

    public function test_scrape_error()
    {
        Http::fake([
            'api.scraperapi.com/*' => Http::response([
                'status'  => 'error',
                'message' => 'API error',
            ], 500),
        ]);

        // Reinitialize client after Http::fake()
        $this->client = new ScraperApiClient();
        $this->client->clearRateLimit();

        $url    = 'https://example.com';
        $result = $this->client->scrape($url);

        $this->assertArrayHasKey('request', $result);
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('response_status_code', $result);
        $this->assertArrayHasKey('response_size', $result);
        $this->assertArrayHasKey('response_time', $result);
        $this->assertArrayHasKey('is_cached', $result);

        $this->assertEquals(500, $result['response_status_code']);

        // Make sure we used the Http::fake() response
        $responseData = $result['response']->json();
        $this->assertEquals('API error', $responseData['message']);
    }

    public function test_scrape_sets_attributes2_with_registrable_domain(): void
    {
        Http::fake([
            'api.scraperapi.com/*' => Http::response(json_encode([
                'status' => 'success',
                'data'   => '<html><body>Test content</body></html>',
            ]), 200, ['Content-Type' => 'application/json']),
        ]);

        $this->client = new ScraperApiClient();
        $this->client->clearRateLimit();

        $url    = 'https://subdomain.example.com/path/to/page';
        $result = $this->client->scrape($url);

        $this->assertArrayHasKey('request', $result);
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('is_cached', $result);
        $this->assertFalse($result['is_cached']);

        // Verify that attributes2 contains the registrable domain
        $this->assertArrayHasKey('attributes2', $result['request']);
        $this->assertEquals('example.com', $result['request']['attributes2']);
    }
}

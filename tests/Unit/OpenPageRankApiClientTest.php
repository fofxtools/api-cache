<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\OpenPageRankApiClient;
use FOfX\ApiCache\RateLimitException;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class OpenPageRankApiClientTest extends TestCase
{
    protected OpenPageRankApiClient $client;
    protected string $apiKey     = 'test-opr-key';
    protected string $apiBaseUrl = 'https://openpagerank.com/api/v1.0';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('api-cache.apis.openpagerank.api_key', $this->apiKey);
        Config::set('api-cache.apis.openpagerank.base_url', $this->apiBaseUrl);
        Config::set('api-cache.apis.openpagerank.version', null);
        Config::set('api-cache.apis.openpagerank.rate_limit_max_attempts', 5);
        Config::set('api-cache.apis.openpagerank.rate_limit_decay_seconds', 10);

        $this->client = new OpenPageRankApiClient();
        $this->client->clearRateLimit();
    }

    protected function getPackageProviders($app): array
    {
        return [ApiCacheServiceProvider::class];
    }

    public function test_initializes_with_correct_configuration()
    {
        $this->assertEquals('openpagerank', $this->client->getClientName());
        $this->assertEquals($this->apiBaseUrl, $this->client->getBaseUrl());
        $this->assertEquals($this->apiKey, $this->client->getApiKey());
        $this->assertNull($this->client->getVersion());
    }

    public function test_gets_pagerank_for_single_domain()
    {
        $fakeResponse = [
            'status_code' => 200,
            'response'    => [[
                'status_code'       => 200,
                'error'             => '',
                'page_rank_integer' => 10,
                'page_rank_decimal' => 10,
                'rank'              => '4',
                'domain'            => 'google.com',
            ]],
            'last_updated' => '25th Dec 2024',
        ];

        Http::fake([
            "{$this->apiBaseUrl}/getPageRank*" => Http::response($fakeResponse, 200),
        ]);
        $this->client = new OpenPageRankApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->getPageRank(['google.com']);

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/getPageRank?domains%5B0%5D=google.com" &&
                   $request->method() === 'GET';
        });

        $this->assertEquals($fakeResponse, $response['response']->json());
    }

    public function test_gets_pagerank_for_multiple_domains()
    {
        $fakeResponse = [
            'status_code' => 200,
            'response'    => [
                [
                    'status_code'       => 200,
                    'error'             => '',
                    'page_rank_integer' => 10,
                    'page_rank_decimal' => 10,
                    'rank'              => '4',
                    'domain'            => 'google.com',
                ],
                [
                    'status_code'       => 200,
                    'error'             => '',
                    'page_rank_integer' => 8,
                    'page_rank_decimal' => 7.55,
                    'rank'              => '82',
                    'domain'            => 'apple.com',
                ],
            ],
            'last_updated' => '25th Dec 2024',
        ];

        Http::fake([
            "{$this->apiBaseUrl}/getPageRank*" => Http::response($fakeResponse, 200),
        ]);
        $this->client = new OpenPageRankApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->getPageRank(['google.com', 'apple.com']);

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/getPageRank?domains%5B0%5D=google.com&domains%5B1%5D=apple.com" &&
                   $request->method() === 'GET';
        });

        $this->assertEquals($fakeResponse, $response['response']->json());
    }

    public function test_throws_on_empty_domains()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one domain must be provided');
        $this->client->getPageRank([]);
    }

    public function test_throws_on_too_many_domains()
    {
        $domains = array_fill(0, 101, 'example.com');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum of 100 domains allowed per request');
        $this->client->getPageRank($domains);
    }

    public function test_caches_responses()
    {
        $fakeResponse = [
            'status_code' => 200,
            'response'    => [[
                'status_code'       => 200,
                'error'             => '',
                'page_rank_integer' => 7,
                'page_rank_decimal' => 6.9,
                'rank'              => '199',
                'domain'            => 'example.com',
            ]],
            'last_updated' => '25th Dec 2024',
        ];

        Http::fake([
            "{$this->apiBaseUrl}/getPageRank*" => Http::response($fakeResponse, 200),
        ]);
        $this->client = new OpenPageRankApiClient();
        $this->client->clearRateLimit();

        // First call should hit the fake and store in cache
        $response1 = $this->client->getPageRank(['example.com']);
        $this->assertEquals($fakeResponse, $response1['response']->json());

        // Second call should hit the cache, not the fake
        Http::fake([
            "{$this->apiBaseUrl}/getPageRank*" => Http::response([], 500), // Should not be called
        ]);
        $this->client = new OpenPageRankApiClient();
        $this->client->clearRateLimit();
        $response2 = $this->client->getPageRank(['example.com']);
        $this->assertEquals($fakeResponse, $response2['response']->json());
    }

    public function test_enforces_rate_limits()
    {
        $fakeResponse = [
            'status_code' => 200,
            'response'    => [[
                'status_code'       => 200,
                'error'             => '',
                'page_rank_integer' => 3,
                'page_rank_decimal' => 3.49,
                'rank'              => '1833681',
                'domain'            => 'test.com',
            ]],
            'last_updated' => '25th Dec 2024',
        ];

        Http::fake([
            "{$this->apiBaseUrl}/getPageRank*" => Http::response($fakeResponse, 200),
        ]);
        $this->client = new OpenPageRankApiClient();
        $this->client->clearRateLimit();

        // Hit the rate limit
        for ($i = 0; $i < 5; $i++) {
            $this->client->getPageRank(["test{$i}.com"]);
        }

        $this->expectException(RateLimitException::class);
        $this->client->getPageRank(['test6.com']);
    }
}

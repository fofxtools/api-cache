<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\JinaApiClient;
use FOfX\ApiCache\RateLimitException;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class JinaApiClientTest extends TestCase
{
    protected JinaApiClient $client;
    protected string $apiKey             = 'test-api-key';
    protected string $baseUrlR           = 'https://r.jina.ai';
    protected string $baseUrlRWildcard   = 'https://r.jina.ai/*';
    protected string $baseUrlS           = 'https://s.jina.ai';
    protected string $baseUrlSWildcard   = 'https://s.jina.ai/*';
    protected string $baseUrlApi         = 'https://api.jina.ai';
    protected string $baseUrlApiWildcard = 'https://api.jina.ai/*';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('api-cache.apis.jina.api_key', $this->apiKey);
        Config::set('api-cache.apis.jina.base_url', $this->baseUrlR);
        Config::set('api-cache.apis.jina.version', null);
        Config::set('api-cache.apis.jina.rate_limit_max_attempts', 5);
        Config::set('api-cache.apis.jina.rate_limit_decay_seconds', 10);

        $this->client = new JinaApiClient();
        $this->client->setTimeout(10);
        $this->client->clearRateLimit();
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array
     */
    protected function getPackageProviders($app): array
    {
        return [ApiCacheServiceProvider::class];
    }

    public function test_initializes_with_correct_configuration(): void
    {
        $this->assertEquals('jina', $this->client->getClientName());
        $this->assertEquals($this->baseUrlR, $this->client->getBaseUrl());
        $this->assertEquals($this->apiKey, $this->client->getApiKey());
        $this->assertNull($this->client->getVersion());
    }

    public function test_get_token_balance_success(): void
    {
        Http::fake([
            $this->baseUrlRWildcard => Http::response(json_encode([
                'code'   => 200,
                'status' => 20000,
                'data'   => [
                    'balanceLeft' => 123,
                ],
            ]), 200, ['Content-Type' => 'application/json']),
        ]);
        $this->client = new JinaApiClient();
        $this->client->clearRateLimit();
        $balance = $this->client->getTokenBalance();
        $this->assertEquals(123, $balance);
    }

    public function test_get_token_balance_throws_on_invalid_response(): void
    {
        Http::fake([
            $this->baseUrlRWildcard => Http::response(json_encode(['unexpected' => 'data']), 200, ['Content-Type' => 'application/json']),
        ]);
        $this->client = new JinaApiClient();
        $this->client->clearRateLimit();
        $this->expectException(\RuntimeException::class);
        $this->client->getTokenBalance();
    }

    public function test_reader_endpoint_success(): void
    {
        $url          = 'https://en.wikipedia.org/wiki/Laravel';
        $responseData = [
            'code'   => 200,
            'status' => 20000,
            'data'   => [
                'title'       => 'Laravel',
                'content'     => 'Laravel content',
                'url'         => $url,
                'description' => 'PHP Framework for Web Artisans',
            ],
        ];

        Http::fake([
            $this->baseUrlRWildcard => Http::response(json_encode($responseData), 200, ['Content-Type' => 'application/json']),
        ]);

        $this->client = new JinaApiClient();
        $this->client->clearRateLimit();
        $result = $this->client->reader($url);

        $this->assertEquals(200, $result['response_status_code']);
        $this->assertStringContainsString('"title":"Laravel"', $result['response']->body());
        $this->assertFalse($result['is_cached']);
    }

    public function test_serp_endpoint_success(): void
    {
        $query        = 'Laravel PHP framework';
        $responseData = [
            'code'   => 200,
            'status' => 20000,
            'data'   => [
                [
                    'title'       => 'Laravel - The PHP Framework For Web Artisans',
                    'url'         => 'https://laravel.com/',
                    'description' => 'Laravel is a PHP web application framework',
                    'content'     => 'Laravel content...',
                ],
            ],
        ];

        Http::fake([
            $this->baseUrlSWildcard => Http::response(json_encode($responseData), 200, ['Content-Type' => 'application/json']),
        ]);

        $this->client = new JinaApiClient();
        $this->client->clearRateLimit();
        $result = $this->client->serp($query);

        $this->assertEquals(200, $result['response_status_code']);
        $this->assertStringContainsString('Laravel - The PHP Framework For Web Artisans', $result['response']->body());
        $this->assertFalse($result['is_cached']);
    }

    public function test_rerank_endpoint_success(): void
    {
        $query     = 'What is Laravel?';
        $documents = [
            'Laravel is a web application framework.',
            'Symfony is another PHP framework.',
        ];

        $responseData = [
            'code'   => 200,
            'status' => 20000,
            'data'   => [
                'model'   => 'jina-reranker-v2-base-multilingual',
                'usage'   => ['total_tokens' => 50],
                'results' => [
                    ['index' => 0, 'relevance_score' => 0.87],
                    ['index' => 1, 'relevance_score' => 0.14],
                ],
            ],
        ];

        Http::fake([
            $this->baseUrlApiWildcard => Http::response(json_encode($responseData), 200, ['Content-Type' => 'application/json']),
        ]);

        $this->client = new JinaApiClient();
        $this->client->clearRateLimit();
        $result = $this->client->rerank($query, $documents);

        $this->assertEquals(200, $result['response_status_code']);
        $this->assertStringContainsString('relevance_score', $result['response']->body());
        $this->assertFalse($result['is_cached']);

        // Make sure we used the Http::fake() response
        $responseData = json_decode($result['response']->body(), true);
        $this->assertEquals(0.87, $responseData['data']['results'][0]['relevance_score']);
    }

    public function test_caches_responses(): void
    {
        $url          = 'https://en.wikipedia.org/wiki/Laravel';
        $responseData = [
            'code'   => 200,
            'status' => 20000,
            'data'   => [
                'title'       => 'Laravel',
                'content'     => 'Laravel content',
                'url'         => $url,
                'description' => 'PHP Framework for Web Artisans',
            ],
        ];

        Http::fake([
            $this->baseUrlRWildcard => Http::response(json_encode($responseData), 200, ['Content-Type' => 'application/json']),
        ]);

        $this->client = new JinaApiClient();
        $this->client->clearRateLimit();

        // First request (not cached)
        $response1 = $this->client->reader($url);

        // Second request (should be cached)
        $response2 = $this->client->reader($url);

        $this->assertEquals(200, $response1['response_status_code']);
        $this->assertEquals(200, $response2['response_status_code']);
        $this->assertFalse($response1['is_cached']);
        $this->assertTrue($response2['is_cached']);

        // Make sure we used the Http::fake() response
        $responseData1 = json_decode($response1['response']->body(), true);
        $this->assertEquals('Laravel content', $responseData1['data']['content']);
    }

    public function test_enforces_rate_limits(): void
    {
        $responseData = [
            'code'   => 200,
            'status' => 20000,
            'data'   => [
                'model'   => 'jina-reranker-v2-base-multilingual',
                'usage'   => ['total_tokens' => 50],
                'results' => [
                    ['index' => 0, 'relevance_score' => 0.87],
                ],
            ],
        ];

        Http::fake([
            $this->baseUrlApiWildcard => Http::response(json_encode($responseData), 200, ['Content-Type' => 'application/json']),
        ]);

        $this->client = new JinaApiClient();
        $this->client->clearRateLimit();
        $this->client->setUseCache(false);
        $this->expectException(RateLimitException::class);

        for ($i = 0; $i <= 5; $i++) {
            $result = $this->client->rerank('test', ['doc']);

            // Make sure we used the Http::fake() response
            $responseData = json_decode($result['response']->body(), true);
            $this->assertEquals(0.87, $responseData['data']['results'][0]['relevance_score']);
        }
    }
}

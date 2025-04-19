<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\YouTubeApiClient;
use FOfX\ApiCache\RateLimitException;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class YouTubeApiClientTest extends TestCase
{
    protected YouTubeApiClient $client;
    protected string $apiKey     = 'test-youtube-api-key';
    protected string $apiBaseUrl = 'https://www.googleapis.com/youtube/v3';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('api-cache.apis.youtube.api_key', $this->apiKey);
        Config::set('api-cache.apis.youtube.base_url', $this->apiBaseUrl);
        Config::set('api-cache.apis.youtube.version', 'v3');
        Config::set('api-cache.apis.youtube.rate_limit_max_attempts', 2);
        Config::set('api-cache.apis.youtube.rate_limit_decay_seconds', 60);
        Config::set('api-cache.apis.youtube.video_parts', 'snippet,contentDetails');
        $this->client = new YouTubeApiClient();
        $this->client->clearRateLimit();
    }

    protected function getPackageProviders($app): array
    {
        return [ApiCacheServiceProvider::class];
    }

    public function test_initializes_with_correct_configuration()
    {
        $this->assertEquals('youtube', $this->client->getClientName());
        $this->assertEquals($this->apiBaseUrl, $this->client->getBaseUrl());
        $this->assertEquals($this->apiKey, $this->client->getApiKey());
        $this->assertEquals('v3', $this->client->getVersion());
    }

    public function test_search_success()
    {
        Http::fake([
            $this->apiBaseUrl . '/search*' => Http::response([
                'kind'  => 'youtube#searchListResponse',
                'items' => [
                    [
                        'id'      => ['kind' => 'youtube#video', 'videoId' => 'abc123'],
                        'snippet' => ['title' => 'Test Video'],
                    ],
                ],
            ], 200),
        ]);

        // Reinitialize client after Http::fake()
        $this->client = new YouTubeApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->search('test');
        Http::assertSent(function ($request) {
            return strpos($request->url(), $this->apiBaseUrl . '/search') === 0 && $request->method() === 'GET';
        });

        // Make sure we used the Http::fake() response
        $body = $result['response']->json();
        $this->assertEquals('youtube#searchListResponse', $body['kind']);
        $this->assertEquals('Test Video', $body['items'][0]['snippet']['title']);
    }

    public function test_videos_success_by_id()
    {
        Http::fake([
            $this->apiBaseUrl . '/videos*' => Http::response([
                'kind'  => 'youtube#videoListResponse',
                'items' => [
                    [
                        'id'      => 'abc123',
                        'snippet' => ['title' => 'Test Video'],
                    ],
                ],
            ], 200),
        ]);

        // Reinitialize client after Http::fake()
        $this->client = new YouTubeApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->videos('abc123');
        Http::assertSent(function ($request) {
            return strpos($request->url(), $this->apiBaseUrl . '/videos') === 0 && $request->method() === 'GET';
        });

        // Make sure we used the Http::fake() response
        $body = $result['response']->json();
        $this->assertEquals('youtube#videoListResponse', $body['kind']);
        $this->assertEquals('Test Video', $body['items'][0]['snippet']['title']);
    }

    public function test_videos_success_by_chart()
    {
        Http::fake([
            $this->apiBaseUrl . '/videos*' => Http::response([
                'kind'  => 'youtube#videoListResponse',
                'items' => [
                    [
                        'id'      => 'xyz789',
                        'snippet' => ['title' => 'Popular Video'],
                    ],
                ],
            ], 200),
        ]);

        // Reinitialize client after Http::fake()
        $this->client = new YouTubeApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->videos(null, 'mostPopular');
        Http::assertSent(function ($request) {
            return strpos($request->url(), $this->apiBaseUrl . '/videos') === 0 && $request->method() === 'GET';
        });

        // Make sure we used the Http::fake() response
        $body = $result['response']->json();
        $this->assertEquals('youtube#videoListResponse', $body['kind']);
        $this->assertEquals('Popular Video', $body['items'][0]['snippet']['title']);
    }

    public function test_videos_throws_on_invalid_params()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client->videos();
        $this->expectException(\InvalidArgumentException::class);
        $this->client->videos('abc123', 'mostPopular');
    }

    public function test_caches_responses()
    {
        Http::fake([
            $this->apiBaseUrl . '/search*' => Http::sequence()
                ->push([
                    'kind'  => 'youtube#searchListResponse',
                    'items' => [['id' => ['videoId' => 'abc123']]],
                ], 200)
                ->push([
                    'kind'  => 'youtube#searchListResponse',
                    'items' => [['id' => ['videoId' => 'abc123']]],
                ], 200),
        ]);

        // Reinitialize client after Http::fake()
        $this->client = new YouTubeApiClient();
        $this->client->clearRateLimit();

        $result1 = $this->client->search('test');
        $result2 = $this->client->search('test');
        $this->assertFalse($result1['is_cached']);
        $this->assertTrue($result2['is_cached']);

        // Make sure we used the Http::fake() response
        $body = $result1['response']->json();
        $this->assertEquals('youtube#searchListResponse', $body['kind']);
        $this->assertEquals('abc123', $body['items'][0]['id']['videoId']);
    }

    public function test_enforces_rate_limits()
    {
        Http::fake([
            $this->apiBaseUrl . '/search*' => Http::response([
                'kind'  => 'youtube#searchListResponse',
                'items' => [['id' => ['videoId' => 'abc123']]],
            ], 200),
        ]);
        Config::set('api-cache.apis.youtube.rate_limit_max_attempts', 1);

        // Reinitialize client after Http::fake()
        $this->client = new YouTubeApiClient();
        $this->client->clearRateLimit();

        // Disable caching
        $this->client->setUseCache(false);

        $result1 = $this->client->search('test');

        // Make sure we used the Http::fake() response
        $body = $result1['response']->json();
        $this->assertEquals('youtube#searchListResponse', $body['kind']);
        $this->assertEquals('abc123', $body['items'][0]['id']['videoId']);

        $this->expectException(RateLimitException::class);
        $this->client->search('test');
    }
}

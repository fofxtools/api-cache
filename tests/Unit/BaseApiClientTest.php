<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\ApiCacheManager;
use FOfX\ApiCache\BaseApiClient;
use FOfX\ApiCache\RateLimitException;
use Mockery;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use FOfX\ApiCache\Tests\Traits\ApiServerTestTrait;

class BaseApiClientTest extends TestCase
{
    use ApiServerTestTrait;

    /** @var BaseApiClient&MockObject */
    protected BaseApiClient $client;

    /** @var ApiCacheManager&Mockery\MockInterface */
    protected ApiCacheManager $cacheManager;

    protected string $version = 'v1';

    /**
     * Get package providers.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return ['FOfX\ApiCache\ApiCacheServiceProvider'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $baseUrl = config('api-cache.apis.demo.base_url');
        $baseUrl = $this->getWslAwareBaseUrl($baseUrl);

        $this->checkServerStatus($baseUrl);

        $this->cacheManager = Mockery::mock(ApiCacheManager::class);

        // Create partial mock, only mock buildUrl
        $this->client = $this->getMockBuilder(BaseApiClient::class)
            ->setConstructorArgs([
                'test-client',
                $baseUrl,
                config('api-cache.apis.demo.api_key'),
                $this->version,
                $this->cacheManager,
            ])
            ->onlyMethods(['buildUrl'])
            ->getMock();

        // Configure buildUrl to work with demo server
        $this->client->method('buildUrl')
            ->willReturnCallback(function (string $endpoint) use ($baseUrl) {
                return $baseUrl . '/' . ltrim($endpoint, '/');
            });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_getClientName_returns_correct_name(): void
    {
        $this->assertEquals('test-client', $this->client->getClientName());
    }

    public function test_getVersion_returns_correct_version(): void
    {
        $this->assertEquals($this->version, $this->client->getVersion());
    }

    public function test_getTimeout_returns_int(): void
    {
        $this->assertIsInt($this->client->getTimeout());
    }

    public function test_setTimeout_sets_timeout(): void
    {
        $timeout = 2;
        $this->client->setTimeout($timeout);
        $this->assertEquals($timeout, $this->client->getTimeout());
    }

    public function test_builds_url_with_leading_slash(): void
    {
        $baseUrl = $this->getWslAwareBaseUrl(config('api-cache.apis.demo.base_url'));
        $url     = $this->client->buildUrl('/predictions');
        $this->assertEquals($baseUrl . '/predictions', $url);
    }

    public function test_builds_url_without_leading_slash(): void
    {
        $baseUrl = $this->getWslAwareBaseUrl(config('api-cache.apis.demo.base_url'));
        $url     = $this->client->buildUrl('predictions');
        $this->assertEquals($baseUrl . '/predictions', $url);
    }

    public function test_sendRequest_makes_real_http_call(): void
    {
        $result = $this->client->sendRequest('predictions', ['query' => 'test']);

        $this->assertEquals(200, $result['response']->status());
        $responseData = json_decode($result['response']->body(), true);
        $this->assertTrue($responseData['success']);
        $this->assertNotEmpty($responseData['data']);
    }

    public function test_sendRequest_handles_404(): void
    {
        $result = $this->client->sendRequest('nonexistentendpoint');

        $this->assertEquals(404, $result['response']->status());
        $responseData = json_decode($result['response']->body(), true);
        $this->assertEquals('error', $responseData['status']);
    }

    public function test_sendCachedRequest_returns_cached_response(): void
    {
        $cachedResponse = ['cached' => 'data'];

        $this->cacheManager->shouldReceive('generateCacheKey')
            ->once()
            ->with('test-client', 'predictions', ['query' => 'test'], 'GET', $this->version)
            ->andReturn('test-cache-key');

        $this->cacheManager->shouldReceive('getCachedResponse')
            ->once()
            ->with('test-client', 'test-cache-key')
            ->andReturn($cachedResponse);

        $result = $this->client->sendCachedRequest('predictions', ['query' => 'test']);

        $this->assertEquals($cachedResponse, $result);
    }

    public function test_sendCachedRequest_handles_rate_limit(): void
    {
        $availableIn = 60;

        $this->cacheManager->shouldReceive('generateCacheKey')
            ->once()
            ->andReturn('test-key');

        $this->cacheManager->shouldReceive('getCachedResponse')
            ->once()
            ->andReturnNull();

        $this->cacheManager->shouldReceive('allowRequest')
            ->once()
            ->with('test-client')
            ->andReturnFalse();

        $this->cacheManager->shouldReceive('getAvailableIn')
            ->once()
            ->with('test-client')
            ->andReturn($availableIn);

        $this->expectException(RateLimitException::class);
        $this->expectExceptionMessage("Rate limit exceeded for client 'test-client'. Available in {$availableIn} seconds.");

        $this->client->sendCachedRequest('predictions', ['query' => 'test']);
    }

    public function test_sendCachedRequest_stores_new_response(): void
    {
        $this->cacheManager->shouldReceive('generateCacheKey')
            ->once()
            ->andReturn('test-cache-key');

        $this->cacheManager->shouldReceive('getCachedResponse')
            ->once()
            ->andReturnNull();

        $this->cacheManager->shouldReceive('allowRequest')
            ->once()
            ->andReturnTrue();

        $this->cacheManager->shouldReceive('incrementAttempts')
            ->once()
            ->with('test-client');

        $this->cacheManager->shouldReceive('storeResponse')
            ->once()
            ->withArgs(function ($client, $key, $result, $endpoint, $version) {
                return $client === 'test-client' &&
                       $key === 'test-cache-key' &&
                       $endpoint === 'predictions' &&
                       $version === $this->version &&
                       isset($result['response']) &&
                       isset($result['response_time']);
            });

        $result = $this->client->sendCachedRequest('predictions', ['query' => 'test']);

        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('response_time', $result);
    }

    public function test_getHealth_returns_health_endpoint_response(): void
    {
        $result = $this->client->getHealth();

        $this->assertEquals(200, $result['response']->status());
        $this->assertArrayHasKey('status', $result['response']->json());
        $this->assertEquals('OK', $result['response']->json()['status']);
    }

    /**
     * Call protected resolveCacheManager method using reflection
     */
    private function callResolveCacheManager(?ApiCacheManager $manager): ?ApiCacheManager
    {
        $method = new \ReflectionMethod(BaseApiClient::class, 'resolveCacheManager');
        $method->setAccessible(true);

        return $method->invoke($this->client, $manager);
    }

    public function test_resolve_cache_manager_returns_injected_manager(): void
    {
        // Already injected in setUp()
        $resolvedManager = $this->callResolveCacheManager($this->cacheManager);

        $this->assertSame($this->cacheManager, $resolvedManager);
    }

    public function test_resolve_cache_manager_returns_singleton_when_not_injected(): void
    {
        // Create new singleton instance
        $singletonManager = $this->mock(ApiCacheManager::class);
        $this->app->instance(ApiCacheManager::class, $singletonManager);

        // Test with null to simulate no injection
        $resolvedManager = $this->callResolveCacheManager(null);

        $this->assertSame($singletonManager, $resolvedManager);
    }
}

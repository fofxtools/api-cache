<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\ApiCacheManager;
use FOfX\ApiCache\ApiCacheServiceProvider;
use FOfX\ApiCache\BaseApiClient;
use FOfX\ApiCache\RateLimitException;
use Mockery;
use FOfX\ApiCache\Tests\TestCase;

class BaseApiClientTest extends TestCase
{
    protected BaseApiClient $client;

    // Rename to apiBaseUrl to avoid conflict with TestBench $baseUrl
    protected string $apiBaseUrl;

    /** @var ApiCacheManager&Mockery\MockInterface */
    protected ApiCacheManager $cacheManager;

    // Use constant so it can be used in static method data providers
    protected const CLIENT_NAME  = 'demo';
    protected string $clientName = self::CLIENT_NAME;
    protected string $version    = 'v1';

    protected function setUp(): void
    {
        parent::setUp();

        // Get base URL from config
        $baseUrl = config("api-cache.apis.{$this->clientName}.base_url");

        // Set up cache manager mock
        $this->cacheManager = Mockery::mock(ApiCacheManager::class);

        // Create client instance
        $this->client = new BaseApiClient(
            $this->clientName,
            $baseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->cacheManager
        );

        // Enable WSL URL conversion
        $this->client->setWslEnabled(true);

        // Store the base URL (will be WSL-aware if needed)
        $this->apiBaseUrl = $this->client->getBaseUrl();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
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

    public function test_getClientName_returns_correct_name(): void
    {
        $this->assertEquals($this->clientName, $this->client->getClientName());
    }

    public function test_getBaseUrl_returns_correct_url(): void
    {
        $this->assertEquals($this->apiBaseUrl, $this->client->getBaseUrl());
    }

    public function test_getApiKey_returns_correct_key(): void
    {
        $apiKey = config("api-cache.apis.{$this->clientName}.api_key");
        $this->assertEquals($apiKey, $this->client->getApiKey());
    }

    public function test_getVersion_returns_correct_version(): void
    {
        $this->assertEquals($this->version, $this->client->getVersion());
    }

    public function test_getTableName_returns_correct_name(): void
    {
        $sanitizedClientName = str_replace('-', '_', $this->clientName);
        $expectedTable       = 'api_cache_' . $sanitizedClientName . '_responses';

        $this->cacheManager->shouldReceive('getTableName')
            ->once()
            ->with($this->clientName)
            ->andReturn($expectedTable);

        $tableName = $this->client->getTableName($this->clientName);

        $this->assertEquals($expectedTable, $tableName);
    }

    public function test_getTimeout_returns_int(): void
    {
        $this->assertIsInt($this->client->getTimeout());
    }

    public function test_getUseCache_returns_true(): void
    {
        $this->assertTrue($this->client->getUseCache());
    }

    public function test_getAuthHeaders_returns_default_headers(): void
    {
        $headers = $this->client->getAuthHeaders();
        $this->assertEquals([
            'Authorization' => 'Bearer ' . $this->client->getApiKey(),
            'Accept'        => 'application/json',
        ], $headers);
    }

    public function test_getAuthParams_returns_default_params(): void
    {
        $params = $this->client->getAuthParams();
        $this->assertEquals([], $params);
    }

    public function test_setClientName_updates_client_name(): void
    {
        $newName = 'new-client';

        $result = $this->client->setClientName($newName);

        $this->assertSame($this->client, $result, 'Method should return $this for chaining');
        $this->assertEquals($newName, $this->client->getClientName());
    }

    public function test_setBaseUrl_updates_base_url(): void
    {
        $newUrl = 'http://api.newdomain.com/v2';

        $result = $this->client->setBaseUrl($newUrl);

        $this->assertSame($this->client, $result, 'Method should return $this for chaining');
        $this->assertEquals($newUrl, $this->client->getBaseUrl());
    }

    public function test_setApiKey_updates_api_key(): void
    {
        $newKey = 'new-api-key';

        $result = $this->client->setApiKey($newKey);

        $this->assertSame($this->client, $result, 'Method should return $this for chaining');
        $this->assertEquals($newKey, $this->client->getApiKey());
    }

    public function test_setVersion_updates_version(): void
    {
        $newVersion = 'v2';

        $result = $this->client->setVersion($newVersion);

        $this->assertSame($this->client, $result, 'Method should return $this for chaining');
        $this->assertEquals($newVersion, $this->client->getVersion());
    }

    public function test_setWslEnabled_updates_wsl_enabled(): void
    {
        $newWslEnabled = true;

        $result = $this->client->setWslEnabled($newWslEnabled);

        $this->assertSame($this->client, $result, 'Method should return $this for chaining');
        $this->assertEquals($newWslEnabled, $this->client->isWslEnabled());
    }

    public function test_setTimeout_sets_timeout(): void
    {
        $timeout = 2;
        $this->client->setTimeout($timeout);
        $this->assertEquals($timeout, $this->client->getTimeout());
    }

    public function test_setUseCache_updates_use_cache(): void
    {
        $newUseCache = false;
        $this->client->setUseCache($newUseCache);
        $this->assertEquals($newUseCache, $this->client->getUseCache());
    }

    public function test_isWslEnabled_updates_true(): void
    {
        $this->client->setWslEnabled(true);
        $this->assertTrue($this->client->isWslEnabled());
    }

    public function test_isWslEnabled_updates_false(): void
    {
        $this->client->setWslEnabled(false);
        $this->assertFalse($this->client->isWslEnabled());
    }

    public function test_clearRateLimit_called(): void
    {
        $this->cacheManager->shouldReceive('clearRateLimit')
            ->once()
            ->with($this->clientName);

        $this->client->clearRateLimit();
        // @phpstan-ignore-next-line
        $this->assertTrue(true);
    }

    public function test_builds_url_with_leading_slash(): void
    {
        $url = $this->client->buildUrl('/predictions');
        $this->assertEquals($this->apiBaseUrl . '/predictions', $url);
    }

    public function test_builds_url_without_leading_slash(): void
    {
        $url = $this->client->buildUrl('predictions');
        $this->assertEquals($this->apiBaseUrl . '/predictions', $url);
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
            ->with($this->clientName, 'predictions', ['query' => 'test'], 'GET', $this->version)
            ->andReturn('test-cache-key');

        $this->cacheManager->shouldReceive('getCachedResponse')
            ->once()
            ->with($this->clientName, 'test-cache-key')
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
            ->with($this->clientName)
            ->andReturnFalse();

        $this->cacheManager->shouldReceive('getAvailableIn')
            ->once()
            ->with($this->clientName)
            ->andReturn($availableIn);

        $this->expectException(RateLimitException::class);
        $this->expectExceptionMessage("Rate limit exceeded for client '{$this->clientName}'. Available in {$availableIn} seconds.");

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
            ->with($this->clientName, 1);

        $params = ['query' => 'test'];
        $this->cacheManager->shouldReceive('storeResponse')
            ->once()
            ->withArgs(function ($client, $key, $params, $result, $endpoint, $version) {
                return $client === $this->clientName &&
                       $key === 'test-cache-key' &&
                       $params === ['query' => 'test'] &&
                       $endpoint === 'predictions' &&
                       $version === $this->version &&
                       isset($result['response']) &&
                       isset($result['response_time']);
            });

        $result = $this->client->sendCachedRequest('predictions', $params);

        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('response_time', $result);
    }

    public function test_sendCachedRequest_bypasses_cache_when_disabled(): void
    {
        // Disable caching
        $this->client->setUseCache(false);

        // Cache-specific methods should not be called
        $this->cacheManager->shouldNotReceive('getCachedResponse');
        $this->cacheManager->shouldNotReceive('storeResponse');

        // Rate limiting should still be checked
        $this->cacheManager->shouldReceive('allowRequest')
            ->once()
            ->with($this->clientName)
            ->andReturnTrue();

        $this->cacheManager->shouldReceive('incrementAttempts')
            ->once()
            ->with($this->clientName, 1);

        // Cache key is still needed for rate limiting
        $this->cacheManager->shouldReceive('generateCacheKey')
            ->once()
            ->andReturn('test-cache-key');

        // Make request with cache disabled
        $result = $this->client->sendCachedRequest('predictions', ['query' => 'test']);

        // Verify it's a direct request
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('response_time', $result);
        $this->assertFalse($result['is_cached']);

        // Verify the setting persists
        $this->assertFalse($this->client->getUseCache());
    }

    public function test_sendCachedRequest_handles_custom_amount(): void
    {
        $originalAmount = 10;
        $amount         = 5;

        $this->cacheManager->shouldReceive('generateCacheKey')
            ->once()
            ->andReturn('test-key');

        $this->cacheManager->shouldReceive('getCachedResponse')
            ->once()
            ->andReturnNull();

        $this->cacheManager->shouldReceive('allowRequest')
            ->once()
            ->with($this->clientName)
            ->andReturnTrue();

        // Mock getRemainingAttempts to return different values before and after
        $this->cacheManager->shouldReceive('getRemainingAttempts')
            ->once()
            ->with($this->clientName)
            ->andReturn($originalAmount)
            ->ordered();

        $this->cacheManager->shouldReceive('incrementAttempts')
            ->once()
            ->with($this->clientName, $amount)
            ->ordered();

        $this->cacheManager->shouldReceive('getRemainingAttempts')
            ->once()
            ->with($this->clientName)
            ->andReturn($originalAmount - $amount)
            ->ordered();

        $this->cacheManager->shouldReceive('storeResponse')
            ->once()
            ->with($this->clientName, 'test-key', ['query' => 'test'], Mockery::any(), 'predictions', $this->version)
            ->andReturn(true);

        // Get remaining attempts before
        $beforeAttempts = $this->cacheManager->getRemainingAttempts($this->clientName);

        $result = $this->client->sendCachedRequest('predictions', ['query' => 'test'], 'GET', $amount);

        // Get remaining attempts after
        $afterAttempts = $this->cacheManager->getRemainingAttempts($this->clientName);

        // Verify the rate limit was decremented by $amount
        $this->assertEquals($originalAmount - $amount, $beforeAttempts - $afterAttempts);
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

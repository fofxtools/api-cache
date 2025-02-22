<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\ApiCacheManager;
use FOfX\ApiCache\BaseApiClient;
use FOfX\ApiCache\RateLimitException;
use Mockery;
use Orchestra\Testbench\TestCase;
use FOfX\ApiCache\Tests\Traits\ApiCacheTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;

class BaseApiClientTest extends TestCase
{
    use ApiCacheTestTrait;

    protected BaseApiClient $client;

    // Rename to apiBaseUrl to avoid conflict with TestBench $baseUrl
    protected string $apiBaseUrl;

    /** @var ApiCacheManager&Mockery\MockInterface */
    protected ApiCacheManager $cacheManager;

    // Use constant so it can be used in static method data providers
    protected const CLIENT_NAME  = 'default';
    protected string $clientName = self::CLIENT_NAME;
    protected string $version    = 'v1';

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

        // Get base URL from config
        $baseUrl = config("api-cache.apis.{$this->clientName}.base_url");

        // Set up cache manager mock
        $this->cacheManager = Mockery::mock(ApiCacheManager::class);

        // Create client with original URL and get WSL-aware version
        $this->client = new BaseApiClient(
            $this->clientName,
            $baseUrl,
            config("api-cache.apis.{$this->clientName}.api_key"),
            $this->version,
            $this->cacheManager
        );

        // Update base URL to WSL-aware version
        $this->apiBaseUrl = $this->client->getWslAwareBaseUrl($baseUrl);
        $this->client->setBaseUrl($this->apiBaseUrl);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
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

    public function test_setTimeout_sets_timeout(): void
    {
        $timeout = 2;
        $this->client->setTimeout($timeout);
        $this->assertEquals($timeout, $this->client->getTimeout());
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
            ->with($this->clientName);

        $this->cacheManager->shouldReceive('storeResponse')
            ->once()
            ->withArgs(function ($client, $key, $result, $endpoint, $version) {
                return $client === $this->clientName &&
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

    public static function urlProvider(): array
    {
        return [
            'localhost_basic' => [
                'url' => 'http://localhost/api',
            ],
            'localhost_with_port' => [
                'url' => 'http://localhost:8000/api',
            ],
            'localhost_with_port_and_version' => [
                'url' => 'http://localhost:10001/api/v1',
            ],
            'external_api' => [
                'url' => 'http://api.example.com/v1',
            ],
            'ip_basic' => [
                'url' => 'http://127.0.0.1/api',
            ],
            'ip_with_port' => [
                'url' => 'http://172.20.128.1:8000/api',
            ],
            'ip_with_port_and_version' => [
                'url' => 'http://172.20.128.1:10001/api/v1',
            ],
        ];
    }

    /**
     * Test WSL-aware URL conversion based on actual environment
     */
    #[DataProvider('urlProvider')]
    public function test_get_wsl_aware_base_url(string $url): void
    {
        $client = new BaseApiClient('default');
        $result = $client->getWslAwareBaseUrl($url);

        if (PHP_OS_FAMILY === 'Linux' && getenv('WSL_DISTRO_NAME')) {
            // In WSL environment
            if (str_contains($url, 'localhost')) {
                // Should convert localhost to IP
                $this->assertStringNotContainsString(
                    'localhost',
                    $result,
                    'WSL should convert localhost to IP address'
                );

                // Should preserve port if present
                if (preg_match('/:\d+/', $url, $matches)) {
                    $this->assertStringContainsString(
                        $matches[0],
                        $result,
                        'WSL should preserve port number'
                    );
                }
            } else {
                // Non-localhost URLs should remain unchanged
                $this->assertEquals(
                    $url,
                    $result,
                    'Non-localhost URLs should not be modified in WSL'
                );
            }
        } else {
            // In Windows/non-WSL environment, so don't modify the URL
            $this->assertEquals(
                $url,
                $result,
                'URLs should remain unchanged in non-WSL environment'
            );
        }
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

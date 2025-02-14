<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\ApiCacheManager;
use FOfX\ApiCache\BaseApiClient;
use FOfX\ApiCache\RateLimitException;
use Mockery;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class BaseApiClientTest extends TestCase
{
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

    protected function checkServerStatus(string $url): void
    {
        $healthUrl = $url . '/health';
        $ch        = curl_init($healthUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Timeout after 2 seconds
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            static::markTestSkipped(
                'Demo API server not accessible: ' . $error . "\n" .
                'Start with: php -S 0.0.0.0:8000 -t public'
            );
        }

        $data = json_decode($response, true);
        if (!isset($data['status']) || $data['status'] !== 'OK') {
            static::markTestSkipped('Demo API server health check failed');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Get base URL and handle WSL if needed
        $baseUrl = config('api-cache.apis.demo.base_url');
        $apiKey  = config('api-cache.apis.demo.api_key');

        if (PHP_OS_FAMILY === 'Linux' && getenv('WSL_DISTRO_NAME')) {
            $nameserver = trim(shell_exec("grep nameserver /etc/resolv.conf | awk '{print $2}'"));
            $baseUrl    = str_replace('localhost', $nameserver, $baseUrl);
        }

        $this->checkServerStatus($baseUrl);

        $this->cacheManager = Mockery::mock(ApiCacheManager::class);

        // Create partial mock, only mock buildUrl
        $this->client = $this->getMockBuilder(BaseApiClient::class)
            ->setConstructorArgs([
                'test-client',
                $baseUrl,
                $apiKey,
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
}

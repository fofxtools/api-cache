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
    /** @var string */
    protected static $apiBaseUrl;

    /** @var BaseApiClient&MockObject */
    protected BaseApiClient $client;

    /** @var ApiCacheManager&Mockery\MockInterface */
    protected ApiCacheManager $cacheManager;

    protected string $apiKey  = 'demo-api-key';
    protected string $version = 'v1';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Get the appropriate host based on environment
        if (PHP_OS_FAMILY === 'Linux' && getenv('WSL_DISTRO_NAME')) {
            // In WSL2, /etc/resolv.conf's nameserver points to the Windows host
            $nameserver       = trim(shell_exec("grep nameserver /etc/resolv.conf | awk '{print $2}'"));
            self::$apiBaseUrl = 'http://' . $nameserver . ':8000/demo-api-server.php';
        } else {
            self::$apiBaseUrl = 'http://localhost:8000/demo-api-server.php';
        }

        // Verify server is running
        static::checkServerStatus();
    }

    protected static function checkServerStatus(): void
    {
        $healthUrl = self::$apiBaseUrl . '/health';
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

        $this->baseUrl = self::$apiBaseUrl;

        $this->cacheManager = Mockery::mock(ApiCacheManager::class);

        // Create partial mock, only mock buildUrl
        $this->client = $this->getMockBuilder(BaseApiClient::class)
            ->setConstructorArgs([
                'test-client',
                $this->baseUrl,
                $this->apiKey,
                $this->version,
                $this->cacheManager,
            ])
            ->onlyMethods(['buildUrl'])
            ->getMock();

        // Configure buildUrl to work with demo server
        $this->client->method('buildUrl')
            ->willReturnCallback(function (string $endpoint) {
                return $this->baseUrl . '/v1/' . ltrim($endpoint, '/');
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

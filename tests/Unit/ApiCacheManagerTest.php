<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\ApiCacheManager;
use FOfX\ApiCache\CacheRepository;
use FOfX\ApiCache\RateLimitService;
use Illuminate\Http\Client\Response;
use Mockery;
use FOfX\ApiCache\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function FOfX\ApiCache\normalize_params;
use function FOfX\ApiCache\summarize_params;

class ApiCacheManagerTest extends TestCase
{
    protected ApiCacheManager $manager;

    /** @var \Mockery\MockInterface&CacheRepository */
    protected CacheRepository $repository;

    /** @var \Mockery\MockInterface&RateLimitService */
    protected RateLimitService $rateLimiter;

    // Use constant so it can be used in static method data providers
    protected const CLIENT_NAME  = 'test-client';
    protected string $clientName = self::CLIENT_NAME;
    protected string $endpoint   = '/test';
    protected array $params      = ['key' => 'value'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository  = Mockery::mock(CacheRepository::class);
        $this->rateLimiter = Mockery::mock(RateLimitService::class);
        $this->manager     = new ApiCacheManager($this->repository, $this->rateLimiter);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return \Mockery\MockInterface&\Illuminate\Http\Client\Response
     */
    private static function mockResponse(int $status, array $headers, string $body)
    {
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('status')->andReturn($status);
        $response->shouldReceive('headers')->andReturn($headers);
        $response->shouldReceive('body')->andReturn($body);

        return $response;
    }

    public function test_getTableName_returns_correct_name(): void
    {
        $sanitizedClientName = str_replace('-', '_', $this->clientName);
        $expectedTable       = 'api_cache_' . $sanitizedClientName . '_responses';

        $this->repository->shouldReceive('getTableName')
            ->once()
            ->with($this->clientName)
            ->andReturn($expectedTable);

        $this->assertEquals($expectedTable, $this->manager->getTableName($this->clientName));
    }

    public function test_allow_request_checks_rate_limit(): void
    {
        $this->rateLimiter->shouldReceive('allowRequest')
            ->once()
            ->with($this->clientName)
            ->andReturn(true);

        $this->assertTrue($this->manager->allowRequest($this->clientName));
    }

    public function test_get_remaining_attempts_returns_value(): void
    {
        $this->rateLimiter->shouldReceive('getRemainingAttempts')
            ->once()
            ->with($this->clientName)
            ->andReturn(10);

        $this->assertEquals(10, $this->manager->getRemainingAttempts($this->clientName));
    }

    public function test_get_available_in_returns_seconds_until_reset(): void
    {
        $this->rateLimiter->shouldReceive('getAvailableIn')
            ->once()
            ->with($this->clientName)
            ->andReturn(30);

        $this->assertEquals(30, $this->manager->getAvailableIn($this->clientName));
    }

    public function test_increment_attempts_decrements_remaining(): void
    {
        // Setup
        $this->rateLimiter->shouldReceive('getRemainingAttempts')
            ->twice()
            ->with($this->clientName)
            ->andReturn(10, 9);  // First call returns 10, second call returns 9

        $this->rateLimiter->shouldReceive('incrementAttempts')
            ->once()
            ->with($this->clientName, 1);

        // Test
        $this->assertEquals(10, $this->manager->getRemainingAttempts($this->clientName));
        $this->manager->incrementAttempts($this->clientName);
        $this->assertEquals(9, $this->manager->getRemainingAttempts($this->clientName));
    }

    public function test_increment_attempts_with_amount(): void
    {
        // Setup
        $this->rateLimiter->shouldReceive('getRemainingAttempts')
            ->twice()
            ->with($this->clientName)
            ->andReturn(10, 5); // First call returns 10, second call returns 5

        $this->rateLimiter->shouldReceive('incrementAttempts')
            ->once()
            ->with($this->clientName, 5);

        $this->assertEquals(10, $this->manager->getRemainingAttempts($this->clientName));
        $this->manager->incrementAttempts($this->clientName, 5);
        $this->assertEquals(5, $this->manager->getRemainingAttempts($this->clientName));
    }

    public function test_clearRateLimit_called(): void
    {
        // This test only verifies delegation to RateLimitService.
        // The actual rate limiting functionality is tested in RateLimitServiceTest
        $this->expectNotToPerformAssertions();

        $this->rateLimiter->shouldReceive('clear')
            ->once()
            ->with($this->clientName);

        $this->manager->clearRateLimit($this->clientName);
    }

    public function test_clearTable_called(): void
    {
        // This test only verifies delegation to CacheRepository.
        // The actual clearing functionality is tested in CacheRepositoryTest
        $this->expectNotToPerformAssertions();

        $this->repository->shouldReceive('clearTable')
            ->once()
            ->with($this->clientName);

        $this->manager->clearTable($this->clientName);
    }

    public static function apiResponseProvider(): array
    {
        $requestHeaders  = ['Accept' => 'application/json'];
        $responseHeaders = ['Content-Type' => 'application/json'];
        $params          = ['query' => 'test'];

        return [
            'simple json response' => [
                'apiResult' => [
                    'request' => [
                        'base_url'   => 'https://api.test',
                        'full_url'   => 'https://api.test/endpoint',
                        'method'     => 'GET',
                        'attributes' => null,
                        'credits'    => null,
                        'cost'       => null,
                        'headers'    => $requestHeaders,
                        'body'       => '{"query":"test"}',
                    ],
                    'response' => self::mockResponse(
                        200,
                        $responseHeaders,
                        '{"test":"data"}'
                    ),
                    'response_time' => 0.5,
                ],
                'params'           => $params,
                'expectedMetadata' => [
                    'endpoint'               => '/test',
                    'version'                => null,
                    'base_url'               => 'https://api.test',
                    'full_url'               => 'https://api.test/endpoint',
                    'method'                 => 'GET',
                    'attributes'             => null,
                    'credits'                => null,
                    'cost'                   => null,
                    'request_params_summary' => summarize_params($params),
                    'request_headers'        => $requestHeaders,
                    'request_body'           => '{"query":"test"}',
                    'response_headers'       => $responseHeaders,
                    'response_body'          => '{"test":"data"}',
                    'response_status_code'   => 200,
                    'response_size'          => 15,
                    'response_time'          => 0.5,
                ],
            ],
        ];
    }

    #[DataProvider('apiResponseProvider')]
    public function test_store_response_saves_correct_metadata(array $apiResult, array $params, array $expectedMetadata): void
    {
        $this->repository->shouldReceive('store')
            ->once()
            ->withArgs(function ($client, $key, $metadata, $ttl) use ($expectedMetadata) {
                $this->assertEquals($this->clientName, $client);
                $this->assertEquals('test-key', $key);
                $this->assertEquals($expectedMetadata, $metadata);
                $this->assertNull($ttl);

                return true;
            });

        $this->manager->storeResponse(
            $this->clientName,
            'test-key',
            $params,
            $apiResult,
            $this->endpoint
        );
    }

    #[DataProvider('apiResponseProvider')]
    public function test_get_cached_response_returns_response_when_found(array $apiResult, array $params, array $expectedMetadata): void
    {
        $this->repository->shouldReceive('get')
            ->once()
            ->with($this->clientName, 'test-key')
            ->andReturn($expectedMetadata);

        $result = $this->manager->getCachedResponse($this->clientName, 'test-key');

        // Verify response structure
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('response_time', $result);
        $this->assertArrayHasKey('request', $result);

        // Verify response content
        $this->assertEquals($expectedMetadata['response_body'], $result['response']->body());
        $this->assertEquals($expectedMetadata['response_status_code'], $result['response']->status());
        $this->assertIsFloat($result['response_time']);

        // Verify request data
        $this->assertEquals($expectedMetadata['base_url'], $result['request']['base_url']);
        $this->assertEquals($expectedMetadata['full_url'], $result['request']['full_url']);
        $this->assertEquals($expectedMetadata['method'], $result['request']['method']);
    }

    public function test_get_cached_response_returns_null_when_not_found(): void
    {
        $this->repository->shouldReceive('get')
            ->once()
            ->with($this->clientName, 'test-key')
            ->andReturnNull();

        $this->assertNull($this->manager->getCachedResponse($this->clientName, 'test-key'));
    }

    public function test_generate_cache_key_creates_consistent_keys(): void
    {
        $key1 = $this->manager->generateCacheKey(
            $this->clientName,
            $this->endpoint,
            ['b' => 2, 'a' => 1]
        );

        $key2 = $this->manager->generateCacheKey(
            $this->clientName,
            $this->endpoint,
            ['a' => 1, 'b' => 2]
        );

        $this->assertEquals($key1, $key2);
    }

    /**
     * Calculate expected hash for parameters using normalize_params
     *
     * @param array $params Parameters to hash
     *
     * @return string SHA1 hash of normalized, JSON-encoded parameters
     */
    private static function calculateExpectedHash(array $params): string
    {
        $normalized = normalize_params($params);

        return sha1(json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    public static function cacheKeyProvider(): array
    {
        $params = [
            'simple parameters'                 => ['name' => 'John', 'age' => 25],
            'simple parameters different order' => ['age' => 25, 'name' => 'John'],
            'with version'                      => ['query' => 'test'],
            'different http method'             => ['id' => 1],
            'endpoint without slash'            => ['id' => 1],
            'empty parameters'                  => [],
            'nested parameters'                 => ['filter' => ['status' => 'active', 'type' => 'user']],
            'unicode in parameters'             => ['name' => '世界'],
            'special characters in endpoint'    => [],
            'mixed parameter types'             => ['id' => 1, 'active' => true, 'score' => 99.9],
            'parameters with null values'       => ['name' => 'John', 'title' => null],
        ];

        return [
            'simple parameters' => [
                'clientName'  => self::CLIENT_NAME,
                'endpoint'    => '/users',
                'params'      => $params['simple parameters'],
                'method'      => 'GET',
                'version'     => null,
                'expectedKey' => self::CLIENT_NAME . '.get.users.' . self::calculateExpectedHash($params['simple parameters']),
            ],
            'simple parameters different order' => [
                'clientName'  => self::CLIENT_NAME,
                'endpoint'    => '/users',
                'params'      => $params['simple parameters different order'],
                'method'      => 'GET',
                'version'     => null,
                'expectedKey' => self::CLIENT_NAME . '.get.users.' . self::calculateExpectedHash($params['simple parameters different order']),
            ],
            'with version' => [
                'clientName'  => self::CLIENT_NAME,
                'endpoint'    => '/users',
                'params'      => $params['with version'],
                'method'      => 'GET',
                'version'     => 'v1',
                'expectedKey' => self::CLIENT_NAME . '.get.users.' . self::calculateExpectedHash($params['with version']) . '.v1',
            ],
            'different http method' => [
                'clientName'  => self::CLIENT_NAME,
                'endpoint'    => '/users',
                'params'      => $params['different http method'],
                'method'      => 'POST',
                'version'     => null,
                'expectedKey' => self::CLIENT_NAME . '.post.users.' . self::calculateExpectedHash($params['different http method']),
            ],
            'endpoint without slash' => [
                'clientName'  => self::CLIENT_NAME,
                'endpoint'    => 'users',
                'params'      => $params['endpoint without slash'],
                'method'      => 'GET',
                'version'     => null,
                'expectedKey' => self::CLIENT_NAME . '.get.users.' . self::calculateExpectedHash($params['endpoint without slash']),
            ],
            'empty parameters' => [
                'clientName'  => self::CLIENT_NAME,
                'endpoint'    => '/users',
                'params'      => $params['empty parameters'],
                'method'      => 'GET',
                'version'     => null,
                'expectedKey' => self::CLIENT_NAME . '.get.users.' . self::calculateExpectedHash($params['empty parameters']),
            ],
            'nested parameters' => [
                'clientName'  => self::CLIENT_NAME,
                'endpoint'    => '/search',
                'params'      => $params['nested parameters'],
                'method'      => 'GET',
                'version'     => null,
                'expectedKey' => self::CLIENT_NAME . '.get.search.' . self::calculateExpectedHash($params['nested parameters']),
            ],
            'unicode in parameters' => [
                'clientName'  => self::CLIENT_NAME,
                'endpoint'    => '/users',
                'params'      => $params['unicode in parameters'],
                'method'      => 'GET',
                'version'     => null,
                'expectedKey' => self::CLIENT_NAME . '.get.users.' . self::calculateExpectedHash($params['unicode in parameters']),
            ],
            'special characters in endpoint' => [
                'clientName'  => self::CLIENT_NAME,
                'endpoint'    => '/user-profiles/123',
                'params'      => $params['special characters in endpoint'],
                'method'      => 'GET',
                'version'     => null,
                'expectedKey' => self::CLIENT_NAME . '.get.user-profiles/123.' . self::calculateExpectedHash($params['special characters in endpoint']),
            ],
            'mixed parameter types' => [
                'clientName'  => self::CLIENT_NAME,
                'endpoint'    => '/data',
                'params'      => $params['mixed parameter types'],
                'method'      => 'GET',
                'version'     => null,
                'expectedKey' => self::CLIENT_NAME . '.get.data.' . self::calculateExpectedHash($params['mixed parameter types']),
            ],
            'parameters with null values' => [
                'clientName'  => self::CLIENT_NAME,
                'endpoint'    => '/users',
                'params'      => $params['parameters with null values'],
                'method'      => 'GET',
                'version'     => null,
                'expectedKey' => self::CLIENT_NAME . '.get.users.' . self::calculateExpectedHash($params['parameters with null values']),
            ],
        ];
    }

    #[DataProvider('cacheKeyProvider')]
    public function test_generate_cache_key_formats_key_correctly(
        string $clientName,
        string $endpoint,
        array $params,
        string $method,
        ?string $version,
        string $expectedKey
    ): void {
        $key = $this->manager->generateCacheKey($clientName, $endpoint, $params, $method, $version);
        $this->assertEquals($expectedKey, $key);
    }
}

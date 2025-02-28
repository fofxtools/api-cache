<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\ApiCacheServiceProvider;
use FOfX\ApiCache\CacheRepository;
use FOfX\ApiCache\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class CacheRepositoryTest extends TestCase
{
    protected CacheRepository $repository;

    // Use constant so it can be used in static method data providers
    protected const COMPRESSED_CLIENT    = 'demo';
    protected const UNCOMPRESSED_CLIENT  = 'openai';
    protected string $compressedClient   = self::COMPRESSED_CLIENT;
    protected string $uncompressedClient = self::UNCOMPRESSED_CLIENT;
    protected string $key                = 'test-key';
    protected array $testData;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure compression for different clients
        config()->set("api-cache.apis.{$this->compressedClient}.compression_enabled", true);
        config()->set("api-cache.apis.{$this->uncompressedClient}.compression_enabled", false);

        // Let Laravel handle DI
        $this->repository = app(CacheRepository::class);

        // Test data
        $this->testData = [
            'endpoint'         => '/test',
            'response_body'    => 'This is the response body for the test. It may or may not be stored compressed.',
            'response_headers' => ['Content-Type' => 'application/json'],
            'method'           => 'GET',
        ];
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

    public function test_getTableName_without_compression(): void
    {
        $tableName = $this->repository->getTableName($this->uncompressedClient);
        $this->assertEquals('api_cache_' . $this->uncompressedClient . '_responses', $tableName);
    }

    public function test_getTableName_with_compression(): void
    {
        $tableName = $this->repository->getTableName($this->compressedClient);
        $this->assertEquals('api_cache_' . $this->compressedClient . '_responses_compressed', $tableName);
    }

    public static function clientNamesProvider(): array
    {
        return [
            'uncompressed client' => [self::UNCOMPRESSED_CLIENT],
            'compressed client'   => [self::COMPRESSED_CLIENT],
        ];
    }

    #[DataProvider('clientNamesProvider')]
    public function test_store_and_get(string $clientName): void
    {
        $this->repository->store($clientName, $this->key, $this->testData);
        $retrieved = $this->repository->get($clientName, $this->key);

        $this->assertNotNull($retrieved);
        $this->assertEquals($this->testData['endpoint'], $retrieved['endpoint']);
        $this->assertEquals($this->testData['response_body'], $retrieved['response_body']);
        $this->assertEquals($this->testData['method'], $retrieved['method']);
    }

    #[DataProvider('clientNamesProvider')]
    public function test_get_respects_ttl(string $clientName): void
    {
        $this->repository->store($clientName, $this->key, $this->testData, 1);

        usleep(1100000);

        $this->assertNull($this->repository->get($clientName, $this->key));
    }

    public function test_store_validates_required_fields_without_compression(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $invalidData = [
            'method' => 'GET',
            // Deliberately missing required fields 'endpoint' and 'response_body'
        ];

        $this->repository->store($this->uncompressedClient, $this->key, $invalidData);
    }

    public function test_store_validates_required_fields_with_compression(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $invalidData = [
            'method' => 'GET',
            // Deliberately missing required fields 'endpoint' and 'response_body' to test validation
        ];

        $this->repository->store($this->compressedClient, $this->key, $invalidData);
    }

    public static function tableNameVariationsProvider(): array
    {
        return [
            'simple name compressed' => [
                'clientName'   => 'demo',
                'isCompressed' => true,
                'expected'     => 'api_cache_demo_responses_compressed',
            ],
            'simple name uncompressed' => [
                'clientName'   => 'demo',
                'isCompressed' => false,
                'expected'     => 'api_cache_demo_responses',
            ],
            'long name compressed' => [
                'clientName'   => str_repeat('a', 64),
                'isCompressed' => true,
                'expected'     => 'api_cache_' . substr(str_repeat('a', 64), 0, 33) . '_responses_compressed',
            ],
            'long name uncompressed' => [
                'clientName'   => str_repeat('a', 64),
                'isCompressed' => false,
                'expected'     => 'api_cache_' . substr(str_repeat('a', 64), 0, 33) . '_responses',
            ],
            'name with dashes compressed' => [
                'clientName'   => 'data-for-seo',
                'isCompressed' => true,
                'expected'     => 'api_cache_data_for_seo_responses_compressed',
            ],
            'name with dashes uncompressed' => [
                'clientName'   => 'data-for-seo',
                'isCompressed' => false,
                'expected'     => 'api_cache_data_for_seo_responses',
            ],
            'numbers only compressed' => [
                'clientName'   => '1234567890',
                'isCompressed' => true,
                'expected'     => 'api_cache_1234567890_responses_compressed',
            ],
            'numbers only uncompressed' => [
                'clientName'   => '1234567890',
                'isCompressed' => false,
                'expected'     => 'api_cache_1234567890_responses',
            ],
        ];
    }

    #[DataProvider('tableNameVariationsProvider')]
    public function test_get_table_name_handles_variations(
        string $clientName,
        bool $isCompressed,
        string $expected
    ): void {
        // Configure compression for this client
        config()->set("api-cache.apis.{$clientName}.compression_enabled", $isCompressed);

        // Use existing repository
        $this->assertEquals($expected, $this->repository->getTableName($clientName));
    }

    public static function invalidTableNameProvider(): array
    {
        return [
            'name with dots' => [
                'clientName' => 'api.client.v1',
            ],
            'name with spaces' => [
                'clientName' => 'open ai',
            ],
            'chinese characters' => [
                'clientName' => 'chinese-天气-api',
            ],
            'unicode characters' => [
                'clientName' => 'über-api',
            ],
            'special characters' => [
                'clientName' => '!@#$%^&*()',
            ],
            'empty string' => [
                'clientName' => '',
            ],
        ];
    }

    #[DataProvider('invalidTableNameProvider')]
    public function test_get_table_name_throws_exception_for_invalid_names(string $clientName): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repository->getTableName($clientName);
    }

    /**
     * Test countTotalResponses returns correct count with no records
     */
    public function test_countTotalResponses_returns_zero_for_empty_table(): void
    {
        $count = $this->repository->countTotalResponses($this->uncompressedClient);

        $this->assertEquals(0, $count);
    }

    /**
     * Test countTotalResponses returns correct count with multiple records
     */
    public function test_countTotalResponses_returns_correct_count(): void
    {
        // Store some test data
        $metadata = [
            'endpoint'      => '/test',
            'response_body' => 'Test response body',
        ];

        // Store multiple records
        $this->repository->store($this->uncompressedClient, 'key1', $metadata);
        $this->repository->store($this->uncompressedClient, 'key2', $metadata);
        $this->repository->store($this->uncompressedClient, 'key3', $metadata);

        $count = $this->repository->countTotalResponses($this->uncompressedClient);

        $this->assertEquals(3, $count);
    }

    /**
     * Test countTotalResponses works with compressed tables
     */
    public function test_countTotalResponses_works_with_compressed_table(): void
    {
        $metadata = [
            'endpoint'      => '/test',
            'response_body' => 'Test response body',
        ];

        // Store in compressed table
        $this->repository->store($this->compressedClient, 'key1', $metadata);
        $this->repository->store($this->compressedClient, 'key2', $metadata);

        $count = $this->repository->countTotalResponses($this->compressedClient);

        $this->assertEquals(2, $count);
    }

    /**
     * Test countTotalResponses includes expired records
     */
    public function test_countTotalResponses_includes_expired_records(): void
    {
        $metadata = [
            'endpoint'      => '/test',
            'response_body' => 'Test response body',
        ];

        // Store one regular record
        $this->repository->store($this->uncompressedClient, 'active-key', $metadata);

        // Store one expired record
        $this->repository->store($this->uncompressedClient, 'expired-key', $metadata, 1);

        // Wait for expiry
        sleep(2);

        $count = $this->repository->countTotalResponses($this->uncompressedClient);

        // Should count both active and expired records
        $this->assertEquals(2, $count);
    }

    /**
     * Test countActiveResponses returns only non-expired records
     */
    public function test_countActiveResponses_returns_only_non_expired_records(): void
    {
        $metadata = [
            'endpoint'      => '/test',
            'response_body' => 'Test response body',
        ];

        // Store one regular record
        $this->repository->store($this->uncompressedClient, 'active-key', $metadata);

        // Store one expired record
        $this->repository->store($this->uncompressedClient, 'expired-key', $metadata, 1);

        // Wait for expiry
        sleep(2);

        $count = $this->repository->countActiveResponses($this->uncompressedClient);

        // Should only count active record
        $this->assertEquals(1, $count);
    }

    /**
     * Test countExpiredResponses returns only expired records
     */
    public function test_countExpiredResponses_returns_only_expired_records(): void
    {
        $metadata = [
            'endpoint'      => '/test',
            'response_body' => 'Test response body',
        ];

        // Store one regular record
        $this->repository->store($this->uncompressedClient, 'active-key', $metadata);

        // Store one expired record
        $this->repository->store($this->uncompressedClient, 'expired-key', $metadata, 1);

        // Wait for expiry
        sleep(2);

        $count = $this->repository->countExpiredResponses($this->uncompressedClient);

        // Should only count expired record
        $this->assertEquals(1, $count);
    }

    #[DataProvider('clientNamesProvider')]
    public function test_deleteExpired_removes_expired_data(string $clientName): void
    {
        // Store data with TTL
        $this->repository->store($clientName, $this->key, $this->testData, 1);

        // Verify row exists and is active before expiry
        $this->assertEquals(
            1,
            $this->repository->countTotalResponses($clientName),
            'Should have one total response before expiry'
        );
        $this->assertEquals(
            1,
            $this->repository->countActiveResponses($clientName),
            'Response should be active before expiry'
        );
        $this->assertEquals(
            0,
            $this->repository->countExpiredResponses($clientName),
            'Should have no expired responses before expiry'
        );

        usleep(1100000);

        // Verify row is now expired but still exists
        $this->assertEquals(
            1,
            $this->repository->countTotalResponses($clientName),
            'Should still have one total response after expiry'
        );
        $this->assertEquals(
            0,
            $this->repository->countActiveResponses($clientName),
            'Should have no active responses after expiry'
        );
        $this->assertEquals(
            1,
            $this->repository->countExpiredResponses($clientName),
            'Response should be expired after expiry'
        );

        // Run deleteExpired
        $this->repository->deleteExpired($clientName);

        // Verify row was actually deleted
        $this->assertEquals(
            0,
            $this->repository->countTotalResponses($clientName),
            'Should have no responses after deleteExpired'
        );
        $this->assertEquals(
            0,
            $this->repository->countActiveResponses($clientName),
            'Should have no active responses after deleteExpired'
        );
        $this->assertEquals(
            0,
            $this->repository->countExpiredResponses($clientName),
            'Should have no expired responses after deleteExpired'
        );
    }
}

<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\CacheRepository;
use FOfX\ApiCache\CompressionService;
use Illuminate\Database\Connection;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class CacheRepositoryTest extends TestCase
{
    protected Connection $db;
    protected CompressionService $compressionService;
    protected CacheRepository $repository;
    protected string $compressedClient   = 'compressed-client';
    protected string $uncompressedClient = 'uncompressed-client';
    protected string $key                = 'test-key';
    protected array $testData;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup database
        $this->app['config']->set('database.default', 'testbench');
        $this->app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);

        // Configure compression for different clients
        $this->app['config']->set("api-cache.apis.{$this->compressedClient}.compression_enabled", true);
        $this->app['config']->set("api-cache.apis.{$this->uncompressedClient}.compression_enabled", false);

        $this->db                 = $this->app['db']->connection();
        $this->compressionService = new CompressionService();
        $this->repository         = new CacheRepository(
            $this->db,
            $this->compressionService
        );

        // Create tables for both clients
        $this->createTestTable($this->repository->getTableName($this->compressedClient));
        $this->createTestTable($this->repository->getTableName($this->uncompressedClient));

        // Test data
        $this->testData = [
            'endpoint'      => '/test',
            'response_body' => 'This is the response body for the test. It may or may not be stored compressed.',
            'method'        => 'GET',
        ];
    }

    private function createTestTable(string $tableName): void
    {
        if (!$this->db->getSchemaBuilder()->hasTable($tableName)) {
            $this->db->getSchemaBuilder()->create($tableName, function ($table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('client');
                $table->string('version')->nullable();
                $table->string('endpoint');
                $table->string('base_url')->nullable();
                $table->string('full_url')->nullable();
                $table->string('method')->nullable();
                $table->mediumText('request_headers')->nullable();
                $table->mediumText('request_body')->nullable();
                $table->integer('response_status_code')->nullable();
                $table->mediumText('response_headers')->nullable();
                $table->mediumText('response_body')->nullable();
                $table->integer('response_size')->nullable();
                $table->double('response_time')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function test_getTableName_without_compression(): void
    {
        $this->assertEquals(
            'api_cache_uncompressed_client_responses',
            $this->repository->getTableName($this->uncompressedClient)
        );
    }

    public function test_getTableName_with_compression(): void
    {
        $this->assertEquals(
            'api_cache_compressed_client_responses_compressed',
            $this->repository->getTableName($this->compressedClient)
        );
    }

    public static function clientNamesProvider(): array
    {
        return [
            'uncompressed client' => ['uncompressed-client'],
            'compressed client'   => ['compressed-client'],
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

        $this->assertNotNull($this->repository->get($clientName, $this->key));

        sleep(2);

        $this->assertNull($this->repository->get($clientName, $this->key));
    }

    #[DataProvider('clientNamesProvider')]
    public function test_cleanup_removes_expired_data(string $clientName): void
    {
        $this->repository->store($clientName, $this->key, $this->testData, 1);

        sleep(2);

        $this->repository->cleanup($clientName);
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
        $this->app['config']->set("api-cache.apis.{$clientName}.compression_enabled", $isCompressed);

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
}

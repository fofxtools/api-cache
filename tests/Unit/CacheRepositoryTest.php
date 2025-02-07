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
    protected CompressionService $uncompressedService;
    protected CompressionService $compressedService;
    protected CacheRepository $uncompressedCache;
    protected CacheRepository $compressedCache;
    protected string $clientName = 'test-client';
    protected string $key        = 'test-key';
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

        $this->db = $this->app['db']->connection();

        // Setup services
        $this->uncompressedService = new CompressionService(false);
        $this->compressedService   = new CompressionService(true);

        // Setup repositories
        $this->uncompressedCache = new CacheRepository($this->db, $this->uncompressedService);
        $this->compressedCache   = new CacheRepository($this->db, $this->compressedService);

        // Create both tables
        $this->createTestTable($this->uncompressedCache->getTableName($this->clientName));
        $this->createTestTable($this->compressedCache->getTableName($this->clientName));

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
            'api_cache_test_client_responses',
            $this->uncompressedCache->getTableName('test-client')
        );
    }

    public function test_getTableName_with_compression(): void
    {
        $this->assertEquals(
            'api_cache_test_client_responses_compressed',
            $this->compressedCache->getTableName('test-client')
        );
    }

    public static function tableNameProvider(): array
    {
        return [
            'simple name' => [
                'clientName'            => 'demo',
                'expected_uncompressed' => 'api_cache_demo_responses',
                'expected_compressed'   => 'api_cache_demo_responses_compressed',
            ],
            'long name' => [
                'clientName'            => str_repeat('a', 64),
                'expected_uncompressed' => 'api_cache_' . substr(str_repeat('a', 64), 0, 33) . '_responses',
                'expected_compressed'   => 'api_cache_' . substr(str_repeat('a', 64), 0, 33) . '_responses_compressed',
            ],
            'name with spaces' => [
                'clientName'            => 'open ai',
                'expected_uncompressed' => 'api_cache_open_ai_responses',
                'expected_compressed'   => 'api_cache_open_ai_responses_compressed',
            ],
            'name with dashes' => [
                'clientName'            => 'data-for-seo',
                'expected_uncompressed' => 'api_cache_data_for_seo_responses',
                'expected_compressed'   => 'api_cache_data_for_seo_responses_compressed',
            ],
            'name with dots' => [
                'clientName'            => 'api.client.v1',
                'expected_uncompressed' => 'api_cache_api_client_v1_responses',
                'expected_compressed'   => 'api_cache_api_client_v1_responses_compressed',
            ],
            'unicode characters' => [
                'clientName'            => 'über-api',
                'expected_uncompressed' => 'api_cache_ber_api_responses',
                'expected_compressed'   => 'api_cache_ber_api_responses_compressed',
            ],
            'chinese characters' => [
                'clientName'            => 'chinese-天气-api',
                'expected_uncompressed' => 'api_cache_chinese_api_responses',
                'expected_compressed'   => 'api_cache_chinese_api_responses_compressed',
            ],
            'numbers only' => [
                'clientName'            => '123456',
                'expected_uncompressed' => 'api_cache_123456_responses',
                'expected_compressed'   => 'api_cache_123456_responses_compressed',
            ],
        ];
    }

    public static function invalidTableNameProvider(): array
    {
        return [
            'special characters' => [
                'clientName' => '!@#$%^&*()',
            ],
            'empty string' => [
                'clientName' => '',
            ],
        ];
    }

    #[DataProvider('tableNameProvider')]
    public function test_get_table_name_handles_various_client_names_uncompressed(
        string $clientName,
        string $expected_uncompressed,
        string $expected_compressed
    ): void {
        $repository = new CacheRepository($this->db, $this->uncompressedService);

        $this->assertEquals($expected_uncompressed, $repository->getTableName($clientName));
    }

    #[DataProvider('tableNameProvider')]
    public function test_get_table_name_handles_various_client_names_compressed(
        string $clientName,
        string $expected_uncompressed,
        string $expected_compressed
    ): void {
        $repository = new CacheRepository($this->db, $this->compressedService);

        $this->assertEquals($expected_compressed, $repository->getTableName($clientName));
    }

    #[DataProvider('invalidTableNameProvider')]
    public function test_get_table_name_throws_exception_for_invalid_names_uncompressed(string $clientName): void
    {
        $repository = new CacheRepository($this->db, $this->uncompressedService);

        $this->expectException(\InvalidArgumentException::class);
        $repository->getTableName($clientName);
    }

    #[DataProvider('invalidTableNameProvider')]
    public function test_get_table_name_throws_exception_for_invalid_names_compressed(string $clientName): void
    {
        $repository = new CacheRepository($this->db, $this->compressedService);

        $this->expectException(\InvalidArgumentException::class);
        $repository->getTableName($clientName);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $invalidData = [
            'method' => 'GET',
            // Deliberately missing required fields 'endpoint' and 'response_body' to test validation
        ];

        $this->uncompressedCache->store($this->clientName, $this->key, $invalidData);
    }

    public function test_store_and_get_without_compression(): void
    {
        $this->uncompressedCache->store($this->clientName, $this->key, $this->testData);
        $retrieved = $this->uncompressedCache->get($this->clientName, $this->key);

        $this->assertNotNull($retrieved);
        $this->assertEquals($this->testData['endpoint'], $retrieved['endpoint']);
        $this->assertEquals($this->testData['response_body'], $retrieved['response_body']);
        $this->assertEquals($this->testData['method'], $retrieved['method']);
    }

    public function test_store_and_get_with_compression(): void
    {
        $this->compressedCache->store($this->clientName, $this->key, $this->testData);
        $retrieved = $this->compressedCache->get($this->clientName, $this->key);

        $this->assertNotNull($retrieved);
        $this->assertEquals($this->testData['endpoint'], $retrieved['endpoint']);
        $this->assertEquals($this->testData['response_body'], $retrieved['response_body']);
        $this->assertEquals($this->testData['method'], $retrieved['method']);
    }

    public function test_get_respects_ttl_without_compression(): void
    {
        $this->uncompressedCache->store($this->clientName, $this->key, $this->testData, 1);

        $this->assertNotNull($this->uncompressedCache->get($this->clientName, $this->key));

        sleep(2);

        $this->assertNull($this->uncompressedCache->get($this->clientName, $this->key));
    }

    public function test_get_respects_ttl_with_compression(): void
    {
        $this->compressedCache->store($this->clientName, $this->key, $this->testData, 1);

        $this->assertNotNull($this->compressedCache->get($this->clientName, $this->key));

        sleep(2);

        $this->assertNull($this->compressedCache->get($this->clientName, $this->key));
    }

    public function test_cleanup_removes_expired_data_without_compression(): void
    {
        $this->uncompressedCache->store($this->clientName, $this->key, $this->testData, 1);

        sleep(2);

        $this->uncompressedCache->cleanup($this->clientName);

        $this->assertNull($this->uncompressedCache->get($this->clientName, $this->key));
    }

    public function test_cleanup_removes_expired_data_with_compression(): void
    {
        $this->compressedCache->store($this->clientName, $this->key, $this->testData, 1);

        sleep(2);

        $this->compressedCache->cleanup($this->clientName);

        $this->assertNull($this->compressedCache->get($this->clientName, $this->key));
    }
}

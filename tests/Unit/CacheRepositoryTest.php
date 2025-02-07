<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\CacheRepository;
use FOfX\ApiCache\CompressionService;
use Illuminate\Database\Connection;
use Orchestra\Testbench\TestCase;

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

<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\ApiCacheServiceProvider;
use FOfX\ApiCache\CacheRepository;
use FOfX\ApiCache\CompressionService;
use FOfX\ApiCache\ResponsesTableDecompressionConverter;
use FOfX\ApiCache\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

class ResponsesTableDecompressionConverterTest extends TestCase
{
    private ResponsesTableDecompressionConverter $converter;
    private CacheRepository $cacheRepository;
    private CompressionService $compressionService;
    private string $clientName = 'demo';

    protected function setUp(): void
    {
        parent::setUp();

        // Configure test client (disable compression for basic tests)
        config()->set("api-cache.apis.{$this->clientName}.compression_enabled", false);

        // Use real dependencies through DI container
        $this->cacheRepository    = app(CacheRepository::class);
        $this->compressionService = app(CompressionService::class);
        $this->converter          = new ResponsesTableDecompressionConverter(
            $this->clientName,
            $this->cacheRepository,
            $this->compressionService
        );
    }

    protected function getPackageProviders($app): array
    {
        return [ApiCacheServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }

    public function testConstructorWithDependencyInjection(): void
    {
        $converter = new ResponsesTableDecompressionConverter(
            'test-client',
            $this->cacheRepository,
            $this->compressionService
        );

        $this->assertSame('test-client', $converter->getClientName());
    }

    public function testConstructorWithoutDependencyInjection(): void
    {
        $converter = new ResponsesTableDecompressionConverter('test-client');

        $this->assertSame('test-client', $converter->getClientName());
    }

    public function testGetClientName(): void
    {
        $this->assertSame($this->clientName, $this->converter->getClientName());
    }

    public function testSetClientName(): void
    {
        $newClientName = 'new-client';
        $this->converter->setClientName($newClientName);

        $this->assertSame($newClientName, $this->converter->getClientName());
    }

    public function testGetBatchSize(): void
    {
        $this->assertSame(100, $this->converter->getBatchSize());
    }

    public function testSetBatchSize(): void
    {
        $newBatchSize = 50;
        $this->converter->setBatchSize($newBatchSize);

        $this->assertSame($newBatchSize, $this->converter->getBatchSize());
    }

    public function testGetOverwrite(): void
    {
        $this->assertFalse($this->converter->getOverwrite());
    }

    public function testSetOverwrite(): void
    {
        $this->converter->setOverwrite(true);

        $this->assertTrue($this->converter->getOverwrite());
    }

    public function testGetCopyProcessingState(): void
    {
        $this->assertFalse($this->converter->getCopyProcessingState());
    }

    public function testSetCopyProcessingState(): void
    {
        $this->converter->setCopyProcessingState(true);

        $this->assertTrue($this->converter->getCopyProcessingState());
    }

    public function testGetUncompressedRowCount(): void
    {
        $count = $this->converter->getUncompressedRowCount();
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testGetCompressedRowCount(): void
    {
        $count = $this->converter->getCompressedRowCount();
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testPrepareUncompressedRowWithCopyProcessingStateTrue(): void
    {
        $this->converter->setCopyProcessingState(true);

        $row                         = new stdClass();
        $row->id                     = 1;
        $row->key                    = 'test-key';
        $row->client                 = $this->clientName;
        $row->version                = '1.0';
        $row->endpoint               = 'test-endpoint';
        $row->base_url               = 'https://api.example.com';
        $row->full_url               = 'https://api.example.com/test-endpoint';
        $row->method                 = 'POST';
        $row->attributes             = null;
        $row->credits                = 5;
        $row->cost                   = 0.01;
        $row->request_params_summary = 'test params';
        $row->request_headers        = $this->compressionService->forceCompress($this->clientName, '{"Content-Type": "application/json"}', 'request_headers');
        $row->response_headers       = $this->compressionService->forceCompress($this->clientName, '{"X-Rate-Limit": "100"}', 'response_headers');
        $row->request_body           = $this->compressionService->forceCompress($this->clientName, '{"query": "test"}', 'request_body');
        $row->response_body          = $this->compressionService->forceCompress($this->clientName, '{"result": "success"}', 'response_body');
        $row->response_status_code   = 200;
        $row->response_size          = 100;
        $row->response_time          = 1.5;
        $row->expires_at             = '2024-01-01 12:00:00';
        $row->created_at             = '2024-01-01 10:00:00';
        $row->updated_at             = '2024-01-01 10:00:00';
        $row->processed_at           = '2024-01-01 11:00:00';
        $row->processed_status       = '{"status": "processed"}';

        $result = $this->converter->prepareUncompressedRow($row);

        $this->assertSame('test-key', $result['key']);
        $this->assertSame($this->clientName, $result['client']);
        $this->assertSame('{"Content-Type": "application/json"}', $result['request_headers']);
        $this->assertSame('{"X-Rate-Limit": "100"}', $result['response_headers']);
        $this->assertSame('{"query": "test"}', $result['request_body']);
        $this->assertSame('{"result": "success"}', $result['response_body']);
        $this->assertSame(strlen('{"result": "success"}'), $result['response_size']);
        $this->assertSame('2024-01-01 11:00:00', $result['processed_at']);
        $this->assertSame('{"status": "processed"}', $result['processed_status']);
    }

    public function testPrepareUncompressedRowWithCopyProcessingStateFalse(): void
    {
        $this->converter->setCopyProcessingState(false);

        $row                         = new stdClass();
        $row->id                     = 1;
        $row->key                    = 'test-key';
        $row->client                 = $this->clientName;
        $row->version                = '1.0';
        $row->endpoint               = 'test-endpoint';
        $row->base_url               = 'https://api.example.com';
        $row->full_url               = 'https://api.example.com/test-endpoint';
        $row->method                 = 'POST';
        $row->attributes             = null;
        $row->credits                = 5;
        $row->cost                   = 0.01;
        $row->request_params_summary = 'test params';
        $row->request_headers        = $this->compressionService->forceCompress($this->clientName, '{"Content-Type": "application/json"}', 'request_headers');
        $row->response_headers       = $this->compressionService->forceCompress($this->clientName, '{"X-Rate-Limit": "100"}', 'response_headers');
        $row->request_body           = $this->compressionService->forceCompress($this->clientName, '{"query": "test"}', 'request_body');
        $row->response_body          = $this->compressionService->forceCompress($this->clientName, '{"result": "success"}', 'response_body');
        $row->response_status_code   = 200;
        $row->response_size          = 100;
        $row->response_time          = 1.5;
        $row->expires_at             = '2024-01-01 12:00:00';
        $row->created_at             = '2024-01-01 10:00:00';
        $row->updated_at             = '2024-01-01 10:00:00';
        $row->processed_at           = '2024-01-01 11:00:00';
        $row->processed_status       = '{"status": "processed"}';

        $result = $this->converter->prepareUncompressedRow($row);

        $this->assertSame('test-key', $result['key']);
        $this->assertSame($this->clientName, $result['client']);
        $this->assertSame('{"Content-Type": "application/json"}', $result['request_headers']);
        $this->assertSame('{"X-Rate-Limit": "100"}', $result['response_headers']);
        $this->assertSame('{"query": "test"}', $result['request_body']);
        $this->assertSame('{"result": "success"}', $result['response_body']);
        $this->assertSame(strlen('{"result": "success"}'), $result['response_size']);
        $this->assertNull($result['processed_at']);
        $this->assertNull($result['processed_status']);
    }

    public function testPrepareUncompressedRowWithNullFields(): void
    {
        $row                         = new stdClass();
        $row->id                     = 1;
        $row->key                    = 'test-key';
        $row->client                 = $this->clientName;
        $row->version                = null;
        $row->endpoint               = null;
        $row->base_url               = null;
        $row->full_url               = null;
        $row->method                 = null;
        $row->attributes             = null;
        $row->credits                = null;
        $row->cost                   = null;
        $row->request_params_summary = null;
        $row->request_headers        = null;
        $row->response_headers       = null;
        $row->request_body           = null;
        $row->response_body          = null;
        $row->response_status_code   = 200;
        $row->response_size          = null;
        $row->response_time          = null;
        $row->expires_at             = null;
        $row->created_at             = '2024-01-01 10:00:00';
        $row->updated_at             = '2024-01-01 10:00:00';
        $row->processed_at           = null;
        $row->processed_status       = null;

        $result = $this->converter->prepareUncompressedRow($row);

        $this->assertSame('test-key', $result['key']);
        $this->assertSame($this->clientName, $result['client']);
        $this->assertNull($result['request_headers']);
        $this->assertNull($result['response_headers']);
        $this->assertNull($result['request_body']);
        $this->assertNull($result['response_body']);
        $this->assertNull($result['response_size']);
        $this->assertNull($result['processed_at']);
        $this->assertNull($result['processed_status']);
    }

    public function testValidateCompressedFieldWithMatchingHeaders(): void
    {
        $uncompressedHeaders = '{"Content-Type": "application/json", "Authorization": "Bearer token"}';
        $compressedHeaders   = $this->compressionService->forceCompress($this->clientName, $uncompressedHeaders, 'request_headers');

        $result = $this->converter->validateCompressedField($compressedHeaders, $uncompressedHeaders, 'headers');
        $this->assertTrue($result);
    }

    public function testValidateCompressedFieldWithMatchingBody(): void
    {
        $uncompressedBody = '{"query": "test search", "limit": 10}';
        $compressedBody   = $this->compressionService->forceCompress($this->clientName, $uncompressedBody, 'request_body');

        $result = $this->converter->validateCompressedField($compressedBody, $uncompressedBody, 'body');
        $this->assertTrue($result);
    }

    public function testValidateCompressedFieldWithNullValues(): void
    {
        $result = $this->converter->validateCompressedField(null, null, 'headers');
        $this->assertTrue($result);

        $result = $this->converter->validateCompressedField(null, null, 'body');
        $this->assertTrue($result);
    }

    public function testValidateCompressedFieldWithMismatchedNulls(): void
    {
        $compressedData = $this->compressionService->forceCompress($this->clientName, '{"test": "data"}', 'request_headers');
        $result         = $this->converter->validateCompressedField($compressedData, null, 'headers');
        $this->assertFalse($result);

        $result = $this->converter->validateCompressedField(null, '{"test": "data"}', 'body');
        $this->assertFalse($result);
    }

    public function testValidateCompressedFieldWithMismatchedData(): void
    {
        $originalHeaders     = '{"Content-Type": "application/json"}';
        $differentHeaders    = '{"Content-Type": "text/html"}';
        $compressedDifferent = $this->compressionService->forceCompress($this->clientName, $differentHeaders, 'request_headers');

        $result = $this->converter->validateCompressedField($compressedDifferent, $originalHeaders, 'headers');
        $this->assertFalse($result);
    }

    public function testValidateRowWithMatchingData(): void
    {
        // Create compressed row with all fields
        $compressedRow                         = new \stdClass();
        $compressedRow->key                    = 'test-key';
        $compressedRow->client                 = $this->clientName;
        $compressedRow->version                = '1.0';
        $compressedRow->endpoint               = 'test-endpoint';
        $compressedRow->base_url               = 'https://api.example.com';
        $compressedRow->full_url               = 'https://api.example.com/test-endpoint';
        $compressedRow->method                 = 'POST';
        $compressedRow->attributes             = null;
        $compressedRow->credits                = 5;
        $compressedRow->cost                   = 0.01;
        $compressedRow->request_params_summary = 'test params';
        $compressedRow->request_headers        = $this->compressionService->forceCompress($this->clientName, '{"Content-Type": "application/json"}', 'request_headers');
        $compressedRow->response_headers       = $this->compressionService->forceCompress($this->clientName, '{"X-Rate-Limit": "100"}', 'response_headers');
        $compressedRow->request_body           = $this->compressionService->forceCompress($this->clientName, '{"query": "test"}', 'request_body');
        $compressedRow->response_body          = $this->compressionService->forceCompress($this->clientName, '{"result": "success"}', 'response_body');
        $compressedRow->response_status_code   = 200;
        $compressedRow->response_size          = strlen($compressedRow->response_body);
        $compressedRow->response_time          = 1.5;
        $compressedRow->expires_at             = '2024-01-01 12:00:00';
        $compressedRow->created_at             = '2024-01-01 10:00:00';
        $compressedRow->updated_at             = '2024-01-01 10:00:00';
        $compressedRow->processed_at           = null;
        $compressedRow->processed_status       = null;

        // Create uncompressed row with same data (except decompressed fields and response_size)
        $uncompressedRow                         = new \stdClass();
        $uncompressedRow->key                    = 'test-key';
        $uncompressedRow->client                 = $this->clientName;
        $uncompressedRow->version                = '1.0';
        $uncompressedRow->endpoint               = 'test-endpoint';
        $uncompressedRow->base_url               = 'https://api.example.com';
        $uncompressedRow->full_url               = 'https://api.example.com/test-endpoint';
        $uncompressedRow->method                 = 'POST';
        $uncompressedRow->attributes             = null;
        $uncompressedRow->credits                = 5;
        $uncompressedRow->cost                   = 0.01;
        $uncompressedRow->request_params_summary = 'test params';
        $uncompressedRow->request_headers        = '{"Content-Type": "application/json"}';
        $uncompressedRow->response_headers       = '{"X-Rate-Limit": "100"}';
        $uncompressedRow->request_body           = '{"query": "test"}';
        $uncompressedRow->response_body          = '{"result": "success"}';
        $uncompressedRow->response_status_code   = 200;
        $uncompressedRow->response_size          = strlen($uncompressedRow->response_body); // Updated to decompressed size
        $uncompressedRow->response_time          = 1.5;
        $uncompressedRow->expires_at             = '2024-01-01 12:00:00';
        $uncompressedRow->created_at             = '2024-01-01 10:00:00';
        $uncompressedRow->updated_at             = '2024-01-01 10:00:00';
        $uncompressedRow->processed_at           = null;
        $uncompressedRow->processed_status       = null;

        $result = $this->converter->validateRow($compressedRow, $uncompressedRow);
        $this->assertTrue($result);
    }

    public function testValidateRowWithMismatchedData(): void
    {
        // Create compressed row with all fields
        $compressedRow                         = new \stdClass();
        $compressedRow->key                    = 'test-key';
        $compressedRow->client                 = $this->clientName;
        $compressedRow->version                = '1.0';
        $compressedRow->endpoint               = 'test-endpoint';
        $compressedRow->base_url               = 'https://api.example.com';
        $compressedRow->full_url               = 'https://api.example.com/test-endpoint';
        $compressedRow->method                 = 'POST';
        $compressedRow->attributes             = null;
        $compressedRow->credits                = 5;
        $compressedRow->cost                   = 0.01;
        $compressedRow->request_params_summary = 'test params';
        $compressedRow->request_headers        = $this->compressionService->forceCompress($this->clientName, '{"Content-Type": "application/json"}', 'request_headers');
        $compressedRow->response_headers       = $this->compressionService->forceCompress($this->clientName, '{"X-Rate-Limit": "100"}', 'response_headers');
        $compressedRow->request_body           = $this->compressionService->forceCompress($this->clientName, '{"query": "test"}', 'request_body');
        $compressedRow->response_body          = $this->compressionService->forceCompress($this->clientName, '{"result": "success"}', 'response_body');
        $compressedRow->response_status_code   = 200;
        $compressedRow->response_size          = strlen($compressedRow->response_body);
        $compressedRow->response_time          = 1.5;
        $compressedRow->expires_at             = '2024-01-01 12:00:00';
        $compressedRow->created_at             = '2024-01-01 10:00:00';
        $compressedRow->updated_at             = '2024-01-01 10:00:00';
        $compressedRow->processed_at           = null;
        $compressedRow->processed_status       = null;

        // Create uncompressed row with different endpoint (should cause mismatch)
        $uncompressedRow                         = new \stdClass();
        $uncompressedRow->key                    = 'test-key';
        $uncompressedRow->client                 = $this->clientName;
        $uncompressedRow->version                = '1.0';
        $uncompressedRow->endpoint               = 'different-endpoint'; // Different value
        $uncompressedRow->base_url               = 'https://api.example.com';
        $uncompressedRow->full_url               = 'https://api.example.com/test-endpoint';
        $uncompressedRow->method                 = 'POST';
        $uncompressedRow->attributes             = null;
        $uncompressedRow->credits                = 5;
        $uncompressedRow->cost                   = 0.01;
        $uncompressedRow->request_params_summary = 'test params';
        $uncompressedRow->request_headers        = '{"Content-Type": "application/json"}';
        $uncompressedRow->response_headers       = '{"X-Rate-Limit": "100"}';
        $uncompressedRow->request_body           = '{"query": "test"}';
        $uncompressedRow->response_body          = '{"result": "success"}';
        $uncompressedRow->response_status_code   = 200;
        $uncompressedRow->response_size          = strlen($uncompressedRow->response_body);
        $uncompressedRow->response_time          = 1.5;
        $uncompressedRow->expires_at             = '2024-01-01 12:00:00';
        $uncompressedRow->created_at             = '2024-01-01 10:00:00';
        $uncompressedRow->updated_at             = '2024-01-01 10:00:00';
        $uncompressedRow->processed_at           = null;
        $uncompressedRow->processed_status       = null;

        $result = $this->converter->validateRow($compressedRow, $uncompressedRow);
        $this->assertFalse($result);
    }

    public function testValidateRowWithNullFields(): void
    {
        // Create rows with null fields
        $compressedRow                         = new \stdClass();
        $compressedRow->key                    = 'test-key';
        $compressedRow->client                 = $this->clientName;
        $compressedRow->version                = null;
        $compressedRow->endpoint               = null;
        $compressedRow->base_url               = null;
        $compressedRow->full_url               = null;
        $compressedRow->method                 = null;
        $compressedRow->attributes             = null;
        $compressedRow->credits                = null;
        $compressedRow->cost                   = null;
        $compressedRow->request_params_summary = null;
        $compressedRow->request_headers        = null;
        $compressedRow->response_headers       = null;
        $compressedRow->request_body           = null;
        $compressedRow->response_body          = null;
        $compressedRow->response_status_code   = 200;
        $compressedRow->response_size          = null;
        $compressedRow->response_time          = null;
        $compressedRow->expires_at             = null;
        $compressedRow->created_at             = '2024-01-01 10:00:00';
        $compressedRow->updated_at             = '2024-01-01 10:00:00';
        $compressedRow->processed_at           = null;
        $compressedRow->processed_status       = null;

        $uncompressedRow                         = new \stdClass();
        $uncompressedRow->key                    = 'test-key';
        $uncompressedRow->client                 = $this->clientName;
        $uncompressedRow->version                = null;
        $uncompressedRow->endpoint               = null;
        $uncompressedRow->base_url               = null;
        $uncompressedRow->full_url               = null;
        $uncompressedRow->method                 = null;
        $uncompressedRow->attributes             = null;
        $uncompressedRow->credits                = null;
        $uncompressedRow->cost                   = null;
        $uncompressedRow->request_params_summary = null;
        $uncompressedRow->request_headers        = null;
        $uncompressedRow->response_headers       = null;
        $uncompressedRow->request_body           = null;
        $uncompressedRow->response_body          = null;
        $uncompressedRow->response_status_code   = 200;
        $uncompressedRow->response_size          = null;
        $uncompressedRow->response_time          = null;
        $uncompressedRow->expires_at             = null;
        $uncompressedRow->created_at             = '2024-01-01 10:00:00';
        $uncompressedRow->updated_at             = '2024-01-01 10:00:00';
        $uncompressedRow->processed_at           = null;
        $uncompressedRow->processed_status       = null;

        $result = $this->converter->validateRow($compressedRow, $uncompressedRow);
        $this->assertTrue($result);
    }

    public function testConvertBatchWithNoData(): void
    {
        $result = $this->converter->convertBatch(10, 0);

        $this->assertArrayHasKey('total_count', $result);
        $this->assertArrayHasKey('processed_count', $result);
        $this->assertArrayHasKey('skipped_count', $result);
        $this->assertArrayHasKey('error_count', $result);
        $this->assertSame(0, $result['total_count']);
        $this->assertSame(0, $result['processed_count']);
        $this->assertSame(0, $result['skipped_count']);
        $this->assertSame(0, $result['error_count']);
    }

    public function testConvertBatchUsesClassBatchSize(): void
    {
        // Set custom batch size on the converter
        $this->converter->setBatchSize(250);

        // Call convertBatch without parameters - should use class batch size
        $result = $this->converter->convertBatch();

        $this->assertArrayHasKey('total_count', $result);
        $this->assertArrayHasKey('processed_count', $result);
        $this->assertArrayHasKey('skipped_count', $result);
        $this->assertArrayHasKey('error_count', $result);
        $this->assertGreaterThanOrEqual(0, $result['total_count']);
        $this->assertGreaterThanOrEqual(0, $result['processed_count']);
        $this->assertGreaterThanOrEqual(0, $result['skipped_count']);
        $this->assertGreaterThanOrEqual(0, $result['error_count']);
    }

    public function testConvertBatchCanOverrideClassBatchSize(): void
    {
        // Set custom batch size on the converter
        $this->converter->setBatchSize(250);

        // Call convertBatch with explicit batch size - should override class batch size
        $result = $this->converter->convertBatch(50);

        $this->assertArrayHasKey('total_count', $result);
        $this->assertArrayHasKey('processed_count', $result);
        $this->assertArrayHasKey('skipped_count', $result);
        $this->assertArrayHasKey('error_count', $result);
        $this->assertGreaterThanOrEqual(0, $result['total_count']);
        $this->assertGreaterThanOrEqual(0, $result['processed_count']);
        $this->assertGreaterThanOrEqual(0, $result['skipped_count']);
        $this->assertGreaterThanOrEqual(0, $result['error_count']);
    }

    public function testConvertBatchActuallyUsesBatchSize(): void
    {
        // First create compressed data to decompress
        // Create 5 test records in uncompressed table
        $testData = [
            'endpoint'             => '/test',
            'method'               => 'GET',
            'response_body'        => 'test response',
            'response_status_code' => 200,
        ];

        for ($i = 1; $i <= 5; $i++) {
            $this->cacheRepository->store($this->clientName, "decomp-batch-key{$i}", $testData);
        }

        // Compress them first using compression converter
        $compressor = new \FOfX\ApiCache\ResponsesTableCompressionConverter($this->clientName);
        $compressor->convertAll();

        // Clear uncompressed table to test decompression
        $uncompressedTable = $this->cacheRepository->getTableName($this->clientName, false);
        DB::table($uncompressedTable)->truncate();

        // Now test decompression with batch size 2
        $this->converter->setBatchSize(2);

        // Call convertBatch() - should process exactly 2 rows
        $result = $this->converter->convertBatch();

        $this->assertSame(2, $result['total_count']);
        $this->assertSame(2, $result['processed_count']);

        // Verify 2 rows exist in uncompressed table
        $uncompressedCount = $this->converter->getUncompressedRowCount();
        $this->assertSame(2, $uncompressedCount);
    }

    public function testConvertBatchActuallyUsesOverrideBatchSize(): void
    {
        // First create compressed data to decompress
        // Create 5 test records in uncompressed table
        $testData = [
            'endpoint'             => '/test',
            'method'               => 'GET',
            'response_body'        => 'test response',
            'response_status_code' => 200,
        ];

        for ($i = 1; $i <= 5; $i++) {
            $this->cacheRepository->store($this->clientName, "decomp-override-key{$i}", $testData);
        }

        // Compress them first using compression converter
        $compressor = new \FOfX\ApiCache\ResponsesTableCompressionConverter($this->clientName);
        $compressor->convertAll();

        // Clear uncompressed table to test decompression
        $uncompressedTable = $this->cacheRepository->getTableName($this->clientName, false);
        DB::table($uncompressedTable)->truncate();

        // Set batch size to 10 (but override with 3)
        $this->converter->setBatchSize(10);

        // Call convertBatch(3) - should process exactly 3 rows, not 10
        $result = $this->converter->convertBatch(3);

        $this->assertSame(3, $result['total_count']);
        $this->assertSame(3, $result['processed_count']);

        // Verify 3 rows exist in uncompressed table
        $uncompressedCount = $this->converter->getUncompressedRowCount();
        $this->assertSame(3, $uncompressedCount);
    }

    public function testConvertAll(): void
    {
        $result = $this->converter->convertAll();

        $this->assertArrayHasKey('total_count', $result);
        $this->assertArrayHasKey('processed_count', $result);
        $this->assertArrayHasKey('skipped_count', $result);
        $this->assertArrayHasKey('error_count', $result);
        $this->assertGreaterThanOrEqual(0, $result['total_count']);
        $this->assertGreaterThanOrEqual(0, $result['processed_count']);
        $this->assertGreaterThanOrEqual(0, $result['skipped_count']);
        $this->assertGreaterThanOrEqual(0, $result['error_count']);
    }

    public function testValidateBatchWithNoData(): void
    {
        $result = $this->converter->validateBatch(10, 0);

        $this->assertArrayHasKey('validated_count', $result);
        $this->assertArrayHasKey('mismatch_count', $result);
        $this->assertArrayHasKey('error_count', $result);
        $this->assertSame(0, $result['validated_count']);
        $this->assertSame(0, $result['mismatch_count']);
        $this->assertSame(0, $result['error_count']);
    }

    public function testValidateBatchUsesClassBatchSize(): void
    {
        // Set custom batch size on the converter
        $this->converter->setBatchSize(250);

        // Call validateBatch without parameters - should use class batch size
        $result = $this->converter->validateBatch();

        $this->assertArrayHasKey('validated_count', $result);
        $this->assertArrayHasKey('mismatch_count', $result);
        $this->assertArrayHasKey('error_count', $result);
        $this->assertGreaterThanOrEqual(0, $result['validated_count']);
        $this->assertGreaterThanOrEqual(0, $result['mismatch_count']);
        $this->assertGreaterThanOrEqual(0, $result['error_count']);
    }

    public function testValidateBatchCanOverrideClassBatchSize(): void
    {
        // Set custom batch size on the converter
        $this->converter->setBatchSize(250);

        // Call validateBatch with explicit batch size - should override class batch size
        $result = $this->converter->validateBatch(50);

        $this->assertArrayHasKey('validated_count', $result);
        $this->assertArrayHasKey('mismatch_count', $result);
        $this->assertArrayHasKey('error_count', $result);
        $this->assertGreaterThanOrEqual(0, $result['validated_count']);
        $this->assertGreaterThanOrEqual(0, $result['mismatch_count']);
        $this->assertGreaterThanOrEqual(0, $result['error_count']);
    }

    public function testValidateBatchActuallyUsesBatchSize(): void
    {
        // Create test data and compress/decompress it first
        $testData = [
            'endpoint'             => '/test',
            'method'               => 'GET',
            'response_body'        => 'test response',
            'response_status_code' => 200,
        ];

        for ($i = 1; $i <= 5; $i++) {
            $this->cacheRepository->store($this->clientName, "validate-decomp-key{$i}", $testData);
        }

        // Compress them first
        $compressor = new \FOfX\ApiCache\ResponsesTableCompressionConverter($this->clientName);
        $compressor->convertAll();

        // Clear uncompressed table and then decompress all records
        $uncompressedTable = $this->cacheRepository->getTableName($this->clientName, false);
        DB::table($uncompressedTable)->truncate();
        $this->converter->convertAll();

        // Set batch size to 2
        $this->converter->setBatchSize(2);

        // Call validateBatch() - should validate exactly 2 rows
        $result = $this->converter->validateBatch();

        $this->assertSame(2, $result['validated_count']);
        $this->assertSame(0, $result['mismatch_count']);
        $this->assertSame(0, $result['error_count']);
    }

    public function testValidateBatchActuallyUsesOverrideBatchSize(): void
    {
        // Create test data and compress/decompress it first
        $testData = [
            'endpoint'             => '/test',
            'method'               => 'GET',
            'response_body'        => 'test response',
            'response_status_code' => 200,
        ];

        for ($i = 1; $i <= 5; $i++) {
            $this->cacheRepository->store($this->clientName, "validate-override-decomp-key{$i}", $testData);
        }

        // Compress them first
        $compressor = new \FOfX\ApiCache\ResponsesTableCompressionConverter($this->clientName);
        $compressor->convertAll();

        // Clear uncompressed table and then decompress all records
        $uncompressedTable = $this->cacheRepository->getTableName($this->clientName, false);
        DB::table($uncompressedTable)->truncate();
        $this->converter->convertAll();

        // Set batch size to 10 (but override with 3)
        $this->converter->setBatchSize(10);

        // Call validateBatch(3) - should validate exactly 3 rows, not 10
        $result = $this->converter->validateBatch(3);

        $this->assertSame(3, $result['validated_count']);
        $this->assertSame(0, $result['mismatch_count']);
        $this->assertSame(0, $result['error_count']);
    }

    public function testValidateAll(): void
    {
        $result = $this->converter->validateAll();

        $this->assertArrayHasKey('validated_count', $result);
        $this->assertArrayHasKey('mismatch_count', $result);
        $this->assertArrayHasKey('error_count', $result);
        $this->assertGreaterThanOrEqual(0, $result['validated_count']);
        $this->assertGreaterThanOrEqual(0, $result['mismatch_count']);
        $this->assertGreaterThanOrEqual(0, $result['error_count']);
    }

    #[DataProvider('batchSizeProvider')]
    public function testConvertBatchWithDifferentBatchSizes(int $batchSize): void
    {
        $result = $this->converter->convertBatch($batchSize, 0);

        $this->assertArrayHasKey('total_count', $result);
        $this->assertArrayHasKey('processed_count', $result);
        $this->assertArrayHasKey('skipped_count', $result);
        $this->assertArrayHasKey('error_count', $result);
    }

    #[DataProvider('batchSizeProvider')]
    public function testValidateBatchWithDifferentBatchSizes(int $batchSize): void
    {
        $result = $this->converter->validateBatch($batchSize, 0);

        $this->assertArrayHasKey('validated_count', $result);
        $this->assertArrayHasKey('mismatch_count', $result);
        $this->assertArrayHasKey('error_count', $result);
    }

    public static function batchSizeProvider(): array
    {
        return [
            'small batch'       => [10],
            'medium batch'      => [50],
            'large batch'       => [100],
            'extra large batch' => [500],
        ];
    }

    #[DataProvider('copyProcessingStateProvider')]
    public function testPrepareUncompressedRowWithDifferentCopyProcessingStates(bool $copyProcessingState): void
    {
        $this->converter->setCopyProcessingState($copyProcessingState);

        $row                   = new stdClass();
        $row->id               = 1;
        $row->key              = 'test-key';
        $row->client           = $this->clientName;
        $row->processed_at     = '2024-01-01 11:00:00';
        $row->processed_status = '{"status": "processed"}';
        // Add other required fields
        $row->version                = '1.0';
        $row->endpoint               = 'test-endpoint';
        $row->base_url               = 'https://api.example.com';
        $row->full_url               = 'https://api.example.com/test-endpoint';
        $row->method                 = 'POST';
        $row->attributes             = null;
        $row->credits                = 5;
        $row->cost                   = 0.01;
        $row->request_params_summary = 'test params';
        $row->request_headers        = null;
        $row->response_headers       = null;
        $row->request_body           = null;
        $row->response_body          = null;
        $row->response_status_code   = 200;
        $row->response_size          = null;
        $row->response_time          = 1.5;
        $row->expires_at             = '2024-01-01 12:00:00';
        $row->created_at             = '2024-01-01 10:00:00';
        $row->updated_at             = '2024-01-01 10:00:00';

        $result = $this->converter->prepareUncompressedRow($row);

        if ($copyProcessingState) {
            $this->assertSame('2024-01-01 11:00:00', $result['processed_at']);
            $this->assertSame('{"status": "processed"}', $result['processed_status']);
        } else {
            $this->assertNull($result['processed_at']);
            $this->assertNull($result['processed_status']);
        }
    }

    public static function copyProcessingStateProvider(): array
    {
        return [
            'copy processing state true'  => [true],
            'copy processing state false' => [false],
        ];
    }
}

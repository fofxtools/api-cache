<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\ApiCacheServiceProvider;
use FOfX\ApiCache\CacheRepository;
use FOfX\ApiCache\CompressionService;
use FOfX\ApiCache\ResponsesTableCompressionConverter;
use FOfX\ApiCache\Tests\TestCase;
use stdClass;

class ResponsesTableCompressionConverterTest extends TestCase
{
    private ResponsesTableCompressionConverter $converter;
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
        $this->converter          = new ResponsesTableCompressionConverter(
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
        $converter = new ResponsesTableCompressionConverter(
            'test-client',
            $this->cacheRepository,
            $this->compressionService
        );

        $this->assertSame('test-client', $converter->getClientName());
    }

    public function testConstructorWithoutDependencyInjection(): void
    {
        $converter = new ResponsesTableCompressionConverter('test-client');

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
        // Store some test data using the real repository
        $testData = [
            'endpoint'             => '/test',
            'method'               => 'GET',
            'response_body'        => 'test response',
            'response_status_code' => 200,
        ];

        $this->cacheRepository->store($this->clientName, 'key1', $testData);
        $this->cacheRepository->store($this->clientName, 'key2', $testData);
        $this->cacheRepository->store($this->clientName, 'key3', $testData);

        $count = $this->converter->getUncompressedRowCount();

        $this->assertSame(3, $count);
    }

    public function testGetCompressedRowCount(): void
    {
        // Initially should be 0
        $count = $this->converter->getCompressedRowCount();
        $this->assertSame(0, $count);
    }

    public function testPrepareCompressedRowWithAllFields(): void
    {
        $row                   = new stdClass();
        $row->key              = 'test-key';
        $row->request_headers  = '{"Content-Type": "application/json"}';
        $row->response_headers = '{"X-Rate-Limit": "100"}';
        $row->request_body     = '{"query": "test"}';
        $row->response_body    = '{"result": "success"}';
        $row->processed_at     = '2023-01-01 12:00:00';
        $row->processed_status = '{"status": "processed"}';

        $result = $this->converter->prepareCompressedRow($row);

        $this->assertSame('test-key', $result['key']);
        $this->assertNotNull($result['request_headers']);
        $this->assertNotNull($result['response_headers']);
        $this->assertNotNull($result['request_body']);
        $this->assertNotNull($result['response_body']);
        $this->assertIsInt($result['response_size']);
        $this->assertNull($result['processed_at']);
        $this->assertNull($result['processed_status']);
    }

    public function testPrepareCompressedRowWithCopyProcessingState(): void
    {
        $this->converter->setCopyProcessingState(true);

        $row                   = new stdClass();
        $row->key              = 'test-key';
        $row->request_headers  = null;
        $row->response_headers = null;
        $row->request_body     = null;
        $row->response_body    = null;
        $row->processed_at     = '2023-01-01 12:00:00';
        $row->processed_status = '{"status": "processed"}';

        $result = $this->converter->prepareCompressedRow($row);

        $this->assertSame('2023-01-01 12:00:00', $result['processed_at']);
        $this->assertSame('{"status": "processed"}', $result['processed_status']);
    }

    public function testPrepareCompressedRowWithNullFields(): void
    {
        $row                   = new stdClass();
        $row->key              = 'test-key';
        $row->request_headers  = null;
        $row->response_headers = null;
        $row->request_body     = null;
        $row->response_body    = null;
        $row->processed_at     = null;
        $row->processed_status = null;

        $result = $this->converter->prepareCompressedRow($row);

        $this->assertSame('test-key', $result['key']);
        $this->assertNull($result['request_headers']);
        $this->assertNull($result['response_headers']);
        $this->assertNull($result['request_body']);
        $this->assertNull($result['response_body']);
        $this->assertNull($result['processed_at']);
        $this->assertNull($result['processed_status']);
    }

    public function testPrepareCompressedRowResponseSizeCalculation(): void
    {
        $row                   = new stdClass();
        $row->key              = 'test-key';
        $row->request_headers  = null;
        $row->response_headers = null;
        $row->request_body     = null;
        $row->response_body    = '{"message": "This is a test response body"}';
        $row->processed_at     = null;
        $row->processed_status = null;

        $result = $this->converter->prepareCompressedRow($row);

        // response_size should be updated to reflect the compressed size
        $this->assertArrayHasKey('response_size', $result);
        $this->assertIsInt($result['response_size']);
        $this->assertGreaterThan(0, $result['response_size']);

        // The compressed size should be the length of the processed response_body
        $this->assertSame(strlen($result['response_body']), $result['response_size']);
    }

    public function testPrepareCompressedRowWithMalformedJson(): void
    {
        $row                   = new stdClass();
        $row->key              = 'test-key';
        $row->request_headers  = '{"invalid": json}'; // Malformed JSON
        $row->response_headers = '{"valid": "json"}';
        $row->request_body     = null;
        $row->response_body    = null;
        $row->processed_at     = null;
        $row->processed_status = null;

        // This should not throw an exception, but may result in null or empty values
        $result = $this->converter->prepareCompressedRow($row);

        $this->assertSame('test-key', $result['key']);
        $this->assertArrayHasKey('request_headers', $result);
        $this->assertArrayHasKey('response_headers', $result);
    }

    public function testConvertBatchBasicFunctionality(): void
    {
        // Store some test data using the real repository
        $testData = [
            'endpoint'             => '/test',
            'method'               => 'GET',
            'response_body'        => 'test response',
            'response_status_code' => 200,
        ];

        $this->cacheRepository->store($this->clientName, 'key1', $testData);

        // Test that convertBatch runs without errors
        $stats = $this->converter->convertBatch(10, 0);
        $this->assertArrayHasKey('total_count', $stats);
        $this->assertArrayHasKey('processed_count', $stats);
        $this->assertArrayHasKey('skipped_count', $stats);
        $this->assertArrayHasKey('error_count', $stats);
        $this->assertSame(1, $stats['total_count']);
    }

    public function testConvertBatchWithOverwriteDisabled(): void
    {
        // Store initial data
        $testData = [
            'endpoint'             => '/test',
            'method'               => 'GET',
            'response_body'        => 'test response',
            'response_status_code' => 200,
        ];

        $this->cacheRepository->store($this->clientName, 'key1', $testData);

        // First conversion should process the row
        $this->converter->setOverwrite(false);
        $stats1 = $this->converter->convertBatch(10, 0);

        $this->assertSame(1, $stats1['total_count']);
        $this->assertSame(1, $stats1['processed_count']);
        $this->assertSame(0, $stats1['skipped_count']);

        // Second conversion should skip the row (already exists)
        $stats2 = $this->converter->convertBatch(10, 0);

        $this->assertSame(1, $stats2['total_count']);
        $this->assertSame(0, $stats2['processed_count']);
        $this->assertSame(1, $stats2['skipped_count']);
    }

    public function testConvertBatchWithOverwriteEnabled(): void
    {
        // Store initial data
        $testData = [
            'endpoint'             => '/test',
            'method'               => 'GET',
            'response_body'        => 'test response',
            'response_status_code' => 200,
        ];

        $this->cacheRepository->store($this->clientName, 'key1', $testData);

        // First conversion
        $this->converter->setOverwrite(true);
        $stats1 = $this->converter->convertBatch(10, 0);

        $this->assertSame(1, $stats1['total_count']);
        $this->assertSame(1, $stats1['processed_count']);
        $this->assertSame(0, $stats1['skipped_count']);

        // Second conversion should still process (overwrite enabled)
        $stats2 = $this->converter->convertBatch(10, 0);

        $this->assertSame(1, $stats2['total_count']);
        $this->assertSame(1, $stats2['processed_count']);
        $this->assertSame(0, $stats2['skipped_count']);
    }

    public function testConvertBatchWithCustomBatchSizeAndOffset(): void
    {
        // Store multiple test records
        $testData = [
            'endpoint'             => '/test',
            'method'               => 'GET',
            'response_body'        => 'test response',
            'response_status_code' => 200,
        ];

        for ($i = 1; $i <= 5; $i++) {
            $this->cacheRepository->store($this->clientName, "key{$i}", $testData);
        }

        // Convert with batch size 2, offset 0 (should get first 2 rows)
        $stats1 = $this->converter->convertBatch(2, 0);
        $this->assertSame(2, $stats1['total_count']);

        // Convert with batch size 2, offset 2 (should get next 2 rows)
        $stats2 = $this->converter->convertBatch(2, 2);
        $this->assertSame(2, $stats2['total_count']);

        // Convert with batch size 2, offset 4 (should get last 1 row)
        $stats3 = $this->converter->convertBatch(2, 4);
        $this->assertSame(1, $stats3['total_count']);

        // Convert with batch size 2, offset 10 (should get 0 rows)
        $stats4 = $this->converter->convertBatch(2, 10);
        $this->assertSame(0, $stats4['total_count']);
    }

    public function testConvertBatchUsesClassBatchSize(): void
    {
        // Set custom batch size on the converter
        $this->converter->setBatchSize(250);

        // Call convertBatch without parameters - should use class batch size
        $stats = $this->converter->convertBatch();

        $this->assertArrayHasKey('total_count', $stats);
        $this->assertArrayHasKey('processed_count', $stats);
        $this->assertArrayHasKey('skipped_count', $stats);
        $this->assertArrayHasKey('error_count', $stats);
        $this->assertGreaterThanOrEqual(0, $stats['total_count']);
        $this->assertGreaterThanOrEqual(0, $stats['processed_count']);
        $this->assertGreaterThanOrEqual(0, $stats['skipped_count']);
        $this->assertGreaterThanOrEqual(0, $stats['error_count']);
    }

    public function testConvertBatchCanOverrideClassBatchSize(): void
    {
        // Set custom batch size on the converter
        $this->converter->setBatchSize(250);

        // Call convertBatch with explicit batch size - should override class batch size
        $stats = $this->converter->convertBatch(50);

        $this->assertArrayHasKey('total_count', $stats);
        $this->assertArrayHasKey('processed_count', $stats);
        $this->assertArrayHasKey('skipped_count', $stats);
        $this->assertArrayHasKey('error_count', $stats);
        $this->assertGreaterThanOrEqual(0, $stats['total_count']);
        $this->assertGreaterThanOrEqual(0, $stats['processed_count']);
        $this->assertGreaterThanOrEqual(0, $stats['skipped_count']);
        $this->assertGreaterThanOrEqual(0, $stats['error_count']);
    }

    public function testConvertBatchActuallyUsesBatchSize(): void
    {
        // Create 5 test records
        $testData = [
            'endpoint'             => '/test',
            'method'               => 'GET',
            'response_body'        => 'test response',
            'response_status_code' => 200,
        ];

        for ($i = 1; $i <= 5; $i++) {
            $this->cacheRepository->store($this->clientName, "batch-test-key{$i}", $testData);
        }

        // Set batch size to 2
        $this->converter->setBatchSize(2);

        // Call convertBatch() - should process exactly 2 rows
        $stats = $this->converter->convertBatch();

        $this->assertSame(2, $stats['total_count']);
        $this->assertSame(2, $stats['processed_count']);

        // Verify 2 rows exist in compressed table
        $compressedCount = $this->converter->getCompressedRowCount();
        $this->assertSame(2, $compressedCount);
    }

    public function testConvertBatchActuallyUsesOverrideBatchSize(): void
    {
        // Create 5 test records
        $testData = [
            'endpoint'             => '/test',
            'method'               => 'GET',
            'response_body'        => 'test response',
            'response_status_code' => 200,
        ];

        for ($i = 1; $i <= 5; $i++) {
            $this->cacheRepository->store($this->clientName, "override-test-key{$i}", $testData);
        }

        // Set batch size to 10 (but override with 3)
        $this->converter->setBatchSize(10);

        // Call convertBatch(3) - should process exactly 3 rows, not 10
        $stats = $this->converter->convertBatch(3);

        $this->assertSame(3, $stats['total_count']);
        $this->assertSame(3, $stats['processed_count']);

        // Verify 3 rows exist in compressed table
        $compressedCount = $this->converter->getCompressedRowCount();
        $this->assertSame(3, $compressedCount);
    }

    public function testConfigurationStateRestoration(): void
    {
        // Set initial compression state to false
        config()->set("api-cache.apis.{$this->clientName}.compression_enabled", false);
        $originalSetting = config("api-cache.apis.{$this->clientName}.compression_enabled");
        $this->assertFalse($originalSetting);

        // Store some test data
        $testData = [
            'endpoint'             => '/test',
            'method'               => 'GET',
            'response_body'        => 'test response',
            'response_status_code' => 200,
        ];
        $this->cacheRepository->store($this->clientName, 'config-test-key', $testData);

        // Run convertBatch which temporarily enables compression
        $this->converter->convertBatch(10, 0);

        // Verify the original setting is restored
        $restoredSetting = config("api-cache.apis.{$this->clientName}.compression_enabled");
        $this->assertSame($originalSetting, $restoredSetting);
    }

    public function testConvertAllBasicFunctionality(): void
    {
        // Store some test data using the real repository
        $testData = [
            'endpoint'             => '/test',
            'method'               => 'GET',
            'response_body'        => 'test response',
            'response_status_code' => 200,
        ];

        $this->cacheRepository->store($this->clientName, 'key1', $testData);
        $this->cacheRepository->store($this->clientName, 'key2', $testData);

        // Test that convertAll runs without errors
        $stats = $this->converter->convertAll();
        $this->assertArrayHasKey('total_count', $stats);
        $this->assertArrayHasKey('processed_count', $stats);
        $this->assertArrayHasKey('skipped_count', $stats);
        $this->assertArrayHasKey('error_count', $stats);
        $this->assertSame(2, $stats['total_count']);
    }

    public function testConvertAllWithEmptyTable(): void
    {
        // Test convertAll with no data
        $stats = $this->converter->convertAll();
        $this->assertSame(0, $stats['total_count']);
        $this->assertSame(0, $stats['processed_count']);
        $this->assertSame(0, $stats['skipped_count']);
        $this->assertSame(0, $stats['error_count']);
    }

    public function testConvertAllWithCustomBatchSize(): void
    {
        // Store multiple test records
        $testData = [
            'endpoint'             => '/test',
            'method'               => 'GET',
            'response_body'        => 'test response',
            'response_status_code' => 200,
        ];

        for ($i = 1; $i <= 7; $i++) {
            $this->cacheRepository->store($this->clientName, "batch-key{$i}", $testData);
        }

        // Set custom batch size
        $this->converter->setBatchSize(3);

        // Convert all should process in batches of 3
        $stats = $this->converter->convertAll();

        $this->assertSame(7, $stats['total_count']);
        $this->assertSame(7, $stats['processed_count']);
        $this->assertSame(0, $stats['skipped_count']);
        $this->assertSame(0, $stats['error_count']);
    }

    public function testValidateCompressedFieldWithMatchingHeaders(): void
    {
        $uncompressedHeaders = '{"Content-Type": "application/json", "Authorization": "Bearer token"}';
        $compressedHeaders   = $this->compressionService->forceCompress($this->clientName, $uncompressedHeaders, 'request_headers');

        $result = $this->converter->validateCompressedField($uncompressedHeaders, $compressedHeaders, 'headers');
        $this->assertTrue($result);
    }

    public function testValidateCompressedFieldWithMatchingBody(): void
    {
        $uncompressedBody = '{"query": "test search", "limit": 10}';
        $compressedBody   = $this->compressionService->forceCompress($this->clientName, $uncompressedBody, 'request_body');

        $result = $this->converter->validateCompressedField($uncompressedBody, $compressedBody, 'body');
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
        $result = $this->converter->validateCompressedField('{"test": "data"}', null, 'headers');
        $this->assertFalse($result);

        $result = $this->converter->validateCompressedField(null, 'compressed-data', 'body');
        $this->assertFalse($result);
    }

    public function testValidateCompressedFieldWithMismatchedData(): void
    {
        $originalHeaders     = '{"Content-Type": "application/json"}';
        $differentHeaders    = '{"Content-Type": "text/html"}';
        $compressedDifferent = $this->compressionService->forceCompress($this->clientName, $differentHeaders, 'request_headers');

        $result = $this->converter->validateCompressedField($originalHeaders, $compressedDifferent, 'headers');
        $this->assertFalse($result);
    }

    public function testValidateRowWithMatchingData(): void
    {
        // Create uncompressed row with all fields
        $uncompressedRow                         = new \stdClass();
        $uncompressedRow->key                    = 'test-key';
        $uncompressedRow->client                 = $this->clientName;
        $uncompressedRow->version                = '1.0';
        $uncompressedRow->endpoint               = 'test-endpoint';
        $uncompressedRow->base_url               = 'https://api.example.com';
        $uncompressedRow->full_url               = 'https://api.example.com/test-endpoint';
        $uncompressedRow->method                 = 'POST';
        $uncompressedRow->attributes             = null;
        $uncompressedRow->attributes2            = null;
        $uncompressedRow->attributes3            = null;
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

        // Create compressed row with same data (except compressed fields and response_size)
        $compressedRow                         = new \stdClass();
        $compressedRow->key                    = 'test-key';
        $compressedRow->client                 = $this->clientName;
        $compressedRow->version                = '1.0';
        $compressedRow->endpoint               = 'test-endpoint';
        $compressedRow->base_url               = 'https://api.example.com';
        $compressedRow->full_url               = 'https://api.example.com/test-endpoint';
        $compressedRow->method                 = 'POST';
        $compressedRow->attributes             = null;
        $compressedRow->attributes2            = null;
        $compressedRow->attributes3            = null;
        $compressedRow->credits                = 5;
        $compressedRow->cost                   = 0.01;
        $compressedRow->request_params_summary = 'test params';
        $compressedRow->request_headers        = $this->compressionService->forceCompress($this->clientName, $uncompressedRow->request_headers, 'request_headers');
        $compressedRow->response_headers       = $this->compressionService->forceCompress($this->clientName, $uncompressedRow->response_headers, 'response_headers');
        $compressedRow->request_body           = $this->compressionService->forceCompress($this->clientName, $uncompressedRow->request_body, 'request_body');
        $compressedRow->response_body          = $this->compressionService->forceCompress($this->clientName, $uncompressedRow->response_body, 'response_body');
        $compressedRow->response_status_code   = 200;
        $compressedRow->response_size          = strlen($compressedRow->response_body); // Updated to compressed size
        $compressedRow->response_time          = 1.5;
        $compressedRow->expires_at             = '2024-01-01 12:00:00';
        $compressedRow->created_at             = '2024-01-01 10:00:00';
        $compressedRow->updated_at             = '2024-01-01 10:00:00';
        $compressedRow->processed_at           = null;
        $compressedRow->processed_status       = null;

        $result = $this->converter->validateRow($uncompressedRow, $compressedRow);
        $this->assertTrue($result);
    }

    public function testValidateRowWithMismatchedData(): void
    {
        // Create uncompressed row with all fields
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
        $uncompressedRow->response_size          = strlen($uncompressedRow->response_body);
        $uncompressedRow->response_time          = 1.5;
        $uncompressedRow->expires_at             = '2024-01-01 12:00:00';
        $uncompressedRow->created_at             = '2024-01-01 10:00:00';
        $uncompressedRow->updated_at             = '2024-01-01 10:00:00';
        $uncompressedRow->processed_at           = null;
        $uncompressedRow->processed_status       = null;

        // Create compressed row with different endpoint (should cause mismatch)
        $compressedRow                         = new \stdClass();
        $compressedRow->key                    = 'test-key';
        $compressedRow->client                 = $this->clientName;
        $compressedRow->version                = '1.0';
        $compressedRow->endpoint               = 'different-endpoint'; // Different value
        $compressedRow->base_url               = 'https://api.example.com';
        $compressedRow->full_url               = 'https://api.example.com/test-endpoint';
        $compressedRow->method                 = 'POST';
        $compressedRow->attributes             = null;
        $compressedRow->credits                = 5;
        $compressedRow->cost                   = 0.01;
        $compressedRow->request_params_summary = 'test params';
        $compressedRow->request_headers        = $this->compressionService->forceCompress($this->clientName, $uncompressedRow->request_headers, 'request_headers');
        $compressedRow->response_headers       = $this->compressionService->forceCompress($this->clientName, $uncompressedRow->response_headers, 'response_headers');
        $compressedRow->request_body           = $this->compressionService->forceCompress($this->clientName, $uncompressedRow->request_body, 'request_body');
        $compressedRow->response_body          = $this->compressionService->forceCompress($this->clientName, $uncompressedRow->response_body, 'response_body');
        $compressedRow->response_status_code   = 200;
        $compressedRow->response_size          = strlen($compressedRow->response_body);
        $compressedRow->response_time          = 1.5;
        $compressedRow->expires_at             = '2024-01-01 12:00:00';
        $compressedRow->created_at             = '2024-01-01 10:00:00';
        $compressedRow->updated_at             = '2024-01-01 10:00:00';
        $compressedRow->processed_at           = null;
        $compressedRow->processed_status       = null;

        $result = $this->converter->validateRow($uncompressedRow, $compressedRow);
        $this->assertFalse($result);
    }

    public function testValidateRowWithNullFields(): void
    {
        // Create rows with null fields
        $uncompressedRow                         = new \stdClass();
        $uncompressedRow->key                    = 'test-key';
        $uncompressedRow->client                 = $this->clientName;
        $uncompressedRow->version                = null;
        $uncompressedRow->endpoint               = null;
        $uncompressedRow->base_url               = null;
        $uncompressedRow->full_url               = null;
        $uncompressedRow->method                 = null;
        $uncompressedRow->attributes             = null;
        $uncompressedRow->attributes2            = null;
        $uncompressedRow->attributes3            = null;
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

        $compressedRow                         = new \stdClass();
        $compressedRow->key                    = 'test-key';
        $compressedRow->client                 = $this->clientName;
        $compressedRow->version                = null;
        $compressedRow->endpoint               = null;
        $compressedRow->base_url               = null;
        $compressedRow->full_url               = null;
        $compressedRow->method                 = null;
        $compressedRow->attributes             = null;
        $compressedRow->attributes2            = null;
        $compressedRow->attributes3            = null;
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

        $result = $this->converter->validateRow($uncompressedRow, $compressedRow);
        $this->assertTrue($result);
    }

    public function testValidateBatchBasicFunctionality(): void
    {
        // Store and convert some test data first
        $testData = [
            'endpoint'             => '/test',
            'method'               => 'GET',
            'response_body'        => 'test response',
            'response_status_code' => 200,
        ];

        $this->cacheRepository->store($this->clientName, 'key1', $testData);
        $this->converter->convertAll();

        // Test that validateBatch runs without errors
        $stats = $this->converter->validateBatch(10, 0);
        $this->assertArrayHasKey('validated_count', $stats);
        $this->assertArrayHasKey('mismatch_count', $stats);
        $this->assertArrayHasKey('error_count', $stats);
    }

    public function testValidateBatchWithEmptyCompressedTable(): void
    {
        // Test validation when there are no compressed rows
        $stats = $this->converter->validateBatch(10, 0);

        $this->assertSame(0, $stats['validated_count']);
        $this->assertSame(0, $stats['mismatch_count']);
        $this->assertSame(0, $stats['error_count']);
    }

    public function testValidateBatchUsesClassBatchSize(): void
    {
        // Set custom batch size on the converter
        $this->converter->setBatchSize(250);

        // Call validateBatch without parameters - should use class batch size
        $stats = $this->converter->validateBatch();

        $this->assertArrayHasKey('validated_count', $stats);
        $this->assertArrayHasKey('mismatch_count', $stats);
        $this->assertArrayHasKey('error_count', $stats);
        $this->assertGreaterThanOrEqual(0, $stats['validated_count']);
        $this->assertGreaterThanOrEqual(0, $stats['mismatch_count']);
        $this->assertGreaterThanOrEqual(0, $stats['error_count']);
    }

    public function testValidateBatchCanOverrideClassBatchSize(): void
    {
        // Set custom batch size on the converter
        $this->converter->setBatchSize(250);

        // Call validateBatch with explicit batch size - should override class batch size
        $stats = $this->converter->validateBatch(50);

        $this->assertArrayHasKey('validated_count', $stats);
        $this->assertArrayHasKey('mismatch_count', $stats);
        $this->assertArrayHasKey('error_count', $stats);
        $this->assertGreaterThanOrEqual(0, $stats['validated_count']);
        $this->assertGreaterThanOrEqual(0, $stats['mismatch_count']);
        $this->assertGreaterThanOrEqual(0, $stats['error_count']);
    }

    public function testValidateBatchActuallyUsesBatchSize(): void
    {
        // Create and convert 5 test records first
        $testData = [
            'endpoint'             => '/test',
            'method'               => 'GET',
            'response_body'        => 'test response',
            'response_status_code' => 200,
        ];

        for ($i = 1; $i <= 5; $i++) {
            $this->cacheRepository->store($this->clientName, "validate-test-key{$i}", $testData);
        }

        // Convert all records first
        $this->converter->convertAll();

        // Set batch size to 2
        $this->converter->setBatchSize(2);

        // Call validateBatch() - should validate exactly 2 rows
        $stats = $this->converter->validateBatch();

        $this->assertSame(2, $stats['validated_count']);
        $this->assertSame(0, $stats['mismatch_count']);
        $this->assertSame(0, $stats['error_count']);
    }

    public function testValidateBatchActuallyUsesOverrideBatchSize(): void
    {
        // Create and convert 5 test records first
        $testData = [
            'endpoint'             => '/test',
            'method'               => 'GET',
            'response_body'        => 'test response',
            'response_status_code' => 200,
        ];

        for ($i = 1; $i <= 5; $i++) {
            $this->cacheRepository->store($this->clientName, "validate-override-key{$i}", $testData);
        }

        // Convert all records first
        $this->converter->convertAll();

        // Set batch size to 10 (but override with 3)
        $this->converter->setBatchSize(10);

        // Call validateBatch(3) - should validate exactly 3 rows, not 10
        $stats = $this->converter->validateBatch(3);

        $this->assertSame(3, $stats['validated_count']);
        $this->assertSame(0, $stats['mismatch_count']);
        $this->assertSame(0, $stats['error_count']);
    }

    public function testValidateAllBasicFunctionality(): void
    {
        // Store and convert some test data first
        $testData = [
            'endpoint'             => '/test',
            'method'               => 'GET',
            'response_body'        => 'test response',
            'response_status_code' => 200,
        ];

        $this->cacheRepository->store($this->clientName, 'key1', $testData);
        $this->converter->convertAll();

        // Test that validateAll runs without errors
        $stats = $this->converter->validateAll();
        $this->assertArrayHasKey('validated_count', $stats);
        $this->assertArrayHasKey('mismatch_count', $stats);
        $this->assertArrayHasKey('error_count', $stats);
    }

    public function testValidationWithActualDataComparison(): void
    {
        // Store data with complex headers and body
        $testData = [
            'endpoint'             => '/test',
            'method'               => 'POST',
            'request_headers'      => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer token'],
            'response_headers'     => ['X-Rate-Limit' => '100', 'Content-Type' => 'application/json'],
            'request_body'         => '{"query": "test search", "limit": 10}',
            'response_body'        => '{"results": [{"id": 1, "name": "test"}], "total": 1}',
            'response_status_code' => 200,
        ];

        $this->cacheRepository->store($this->clientName, 'validation-key', $testData);

        // Convert the data
        $convertStats = $this->converter->convertAll();
        $this->assertSame(1, $convertStats['processed_count']);

        // Validate the converted data
        $validateStats = $this->converter->validateAll();

        // Should have 1 validated row with no mismatches or errors
        $this->assertSame(1, $validateStats['validated_count']);
        $this->assertSame(0, $validateStats['mismatch_count']);
        $this->assertSame(0, $validateStats['error_count']);
    }
}

<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\DataForSeoMerchantAmazonAsinProcessor;
use FOfX\ApiCache\ApiCacheManager;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\DB;

class DataForSeoMerchantAmazonAsinProcessorTest extends TestCase
{
    protected DataForSeoMerchantAmazonAsinProcessor $processor;
    protected ApiCacheManager $cacheManager;
    protected string $responsesTable = 'api_cache_dataforseo_responses';
    protected string $asinItemsTable = 'dataforseo_merchant_amazon_asins';

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheManager = app(ApiCacheManager::class);
        $this->processor    = new DataForSeoMerchantAmazonAsinProcessor($this->cacheManager);
    }

    /**
     * Get service providers to register for testing.
     */
    protected function getPackageProviders($app): array
    {
        return [ApiCacheServiceProvider::class];
    }

    public function test_constructor_with_cache_manager(): void
    {
        $processor = new DataForSeoMerchantAmazonAsinProcessor($this->cacheManager);
        $this->assertInstanceOf(DataForSeoMerchantAmazonAsinProcessor::class, $processor);
    }

    public function test_constructor_without_cache_manager(): void
    {
        $processor = new DataForSeoMerchantAmazonAsinProcessor();
        $this->assertInstanceOf(DataForSeoMerchantAmazonAsinProcessor::class, $processor);
    }

    public function test_set_skip_sandbox_changes_value(): void
    {
        $original = $this->processor->getSkipSandbox();
        $this->processor->setSkipSandbox(!$original);
        $this->assertEquals(!$original, $this->processor->getSkipSandbox());
    }

    public function test_set_update_if_newer_changes_value(): void
    {
        $original = $this->processor->getUpdateIfNewer();
        $this->processor->setUpdateIfNewer(!$original);
        $this->assertEquals(!$original, $this->processor->getUpdateIfNewer());
    }

    public function test_set_skip_reviews_changes_value(): void
    {
        $original = $this->processor->getSkipReviews();
        $this->processor->setSkipReviews(!$original);
        $this->assertEquals(!$original, $this->processor->getSkipReviews());
    }

    public function test_set_skip_product_information_changes_value(): void
    {
        $original = $this->processor->getSkipProductInformation();
        $this->processor->setSkipProductInformation(!$original);
        $this->assertEquals(!$original, $this->processor->getSkipProductInformation());
    }

    public function test_get_responses_table_name(): void
    {
        $tableName = $this->processor->getResponsesTableName();
        $this->assertEquals($this->responsesTable, $tableName);
    }

    public function test_reset_processed(): void
    {
        // Insert processed responses
        DB::table($this->responsesTable)->insert([
            [
                'client'               => 'dataforseo',
                'key'                  => 'processed-key-1',
                'endpoint'             => 'merchant/amazon/asin/task_get/advanced/12345678-1234-1234-1234-123456789001',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'processed_at'         => now(),
                'processed_status'     => json_encode(['status' => 'OK']),
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'               => 'dataforseo',
                'key'                  => 'processed-key-2',
                'endpoint'             => 'merchant/amazon/asin/task_get/advanced/12345678-1234-1234-1234-123456789002',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'processed_at'         => now(),
                'processed_status'     => json_encode(['status' => 'OK']),
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'               => 'dataforseo',
                'key'                  => 'other-endpoint-key',
                'endpoint'             => 'other/endpoint',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'processed_at'         => now(),
                'processed_status'     => json_encode(['status' => 'OK']),
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
        ]);

        $this->processor->resetProcessed();

        // Verify only ASIN responses were reset
        $resetCount = DB::table($this->responsesTable)
            ->whereNull('processed_at')
            ->whereNull('processed_status')
            ->count();
        $this->assertEquals(2, $resetCount);

        // Verify other endpoint was not reset
        $otherResponse = DB::table($this->responsesTable)
            ->where('key', 'other-endpoint-key')
            ->first();
        $this->assertNotNull($otherResponse->processed_at);
    }

    public function test_clear_processed_tables(): void
    {
        // Insert test data
        DB::table($this->asinItemsTable)->insert([
            'asin'          => 'B123456789',
            'se'            => 'amazon',
            'se_type'       => 'asin',
            'location_code' => 2840,
            'language_code' => 'en_US',
            'device'        => 'desktop',
            'result_asin'   => 'B123456789',
            'se_domain'     => 'amazon.com',
            'data_asin'     => 'B123456789',
            'title'         => 'Test Product',
            'task_id'       => 'test-task-123',
            'response_id'   => 456,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $stats = $this->processor->clearProcessedTables();

        // Default behavior returns null
        $this->assertNull($stats['items_cleared']);

        // Verify table is empty
        $this->assertEquals(0, DB::table($this->asinItemsTable)->count());
    }

    public function test_clear_processed_tables_with_count(): void
    {
        // Insert test data
        DB::table($this->asinItemsTable)->insert([
            [
                'asin'          => 'B123456789',
                'se'            => 'amazon',
                'se_type'       => 'asin',
                'location_code' => 2840,
                'language_code' => 'en_US',
                'device'        => 'desktop',
                'result_asin'   => 'B123456789',
                'se_domain'     => 'amazon.com',
                'data_asin'     => 'B123456789',
                'title'         => 'Test Product 1',
                'task_id'       => 'test-task-123',
                'response_id'   => 456,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            [
                'asin'          => 'B987654321',
                'se'            => 'amazon',
                'se_type'       => 'asin',
                'location_code' => 2840,
                'language_code' => 'en_US',
                'device'        => 'desktop',
                'result_asin'   => 'B987654321',
                'se_domain'     => 'amazon.com',
                'data_asin'     => 'B987654321',
                'title'         => 'Test Product 2',
                'task_id'       => 'test-task-456',
                'response_id'   => 789,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
        ]);

        $stats = $this->processor->clearProcessedTables(true);

        // With count enabled, should return actual count
        $this->assertEquals(2, $stats['items_cleared']);

        // Verify table is empty
        $this->assertEquals(0, DB::table($this->asinItemsTable)->count());
    }

    public function test_extract_task_data(): void
    {
        $taskData = [
            'asin'                    => 'B123456789',
            'se'                      => 'amazon',
            'se_type'                 => 'asin',
            'location_code'           => 2840,
            'language_code'           => 'en_US',
            'device'                  => 'desktop',
            'os'                      => 'windows',
            'load_more_local_reviews' => true,
            'local_reviews_sort'      => 'recent',
            'tag'                     => 'test-tag',
        ];

        $result = $this->processor->extractTaskData($taskData);

        $this->assertEquals('B123456789', $result['asin']);
        $this->assertEquals('amazon', $result['se']);
        $this->assertEquals('asin', $result['se_type']);
        $this->assertEquals(2840, $result['location_code']);
        $this->assertEquals('en_US', $result['language_code']);
        $this->assertEquals('desktop', $result['device']);
        $this->assertEquals('windows', $result['os']);
        $this->assertTrue($result['load_more_local_reviews']);
        $this->assertEquals('recent', $result['local_reviews_sort']);
        $this->assertEquals('test-tag', $result['tag']);
    }

    public function test_extract_result_metadata(): void
    {
        $resultData = [
            'asin'      => 'B123456789',
            'se_domain' => 'amazon.com',
            'type'      => 'shopping',
            'check_url' => 'https://www.amazon.com/dp/B123456789',
            'datetime'  => '2024-11-26 14:10:02 +00:00',
        ];

        $result = $this->processor->extractResultMetadata($resultData);

        $this->assertEquals('B123456789', $result['result_asin']);
        $this->assertEquals('amazon.com', $result['se_domain']);
        $this->assertEquals('shopping', $result['type']);
        $this->assertEquals('https://www.amazon.com/dp/B123456789', $result['check_url']);
        $this->assertEquals('2024-11-26 14:10:02 +00:00', $result['result_datetime']);
    }

    public function test_ensure_defaults(): void
    {
        $data = [
            'asin'          => 'B123456789',
            'location_code' => 2840,
            'language_code' => 'en_US',
        ];

        $result = $this->processor->ensureDefaults($data);

        $this->assertEquals('B123456789', $result['asin']);
        $this->assertEquals(2840, $result['location_code']);
        $this->assertEquals('en_US', $result['language_code']);
        $this->assertEquals('desktop', $result['device']); // Default applied
    }

    public function test_ensure_defaults_preserves_existing_device(): void
    {
        $data = [
            'asin'          => 'B123456789',
            'location_code' => 2840,
            'language_code' => 'en_US',
            'device'        => 'mobile',
        ];

        $result = $this->processor->ensureDefaults($data);

        $this->assertEquals('mobile', $result['device']); // Existing value preserved
    }

    public function test_process_responses_with_no_responses(): void
    {
        $stats = $this->processor->processResponses();

        $this->assertEquals(0, $stats['processed_responses']);
        $this->assertEquals(0, $stats['items_processed']);
        $this->assertEquals(0, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);
        $this->assertEquals(0, $stats['total_items']);
        $this->assertEquals(0, $stats['errors']);
    }

    public function test_process_responses_skips_sandbox_by_default(): void
    {
        // Insert sandbox response
        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'sandbox-key',
            'endpoint'             => 'merchant/amazon/asin/task_get/advanced/12345678-1234-1234-1234-123456789003',
            'response_body'        => json_encode(['tasks' => []]),
            'response_status_code' => 200,
            'base_url'             => 'https://sandbox.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        // Insert non-sandbox response
        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'production-key',
            'endpoint'             => 'merchant/amazon/asin/task_get/advanced/12345678-1234-1234-1234-123456789004',
            'response_body'        => json_encode(['tasks' => []]),
            'response_status_code' => 200,
            'base_url'             => 'https://api.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $stats = $this->processor->processResponses();

        // Should process only non-sandbox response
        $this->assertEquals(1, $stats['processed_responses']);
    }

    public function test_process_responses_handles_invalid_json(): void
    {
        // Clear any existing responses first
        DB::table($this->responsesTable)->where('endpoint', 'like', 'merchant/amazon/asin/task_get/advanced%')->delete();

        // Insert response with invalid JSON
        $responseId = DB::table($this->responsesTable)->insertGetId([
            'client'               => 'dataforseo',
            'key'                  => 'invalid-json-key',
            'endpoint'             => 'merchant/amazon/asin/task_get/advanced/12345678-1234-1234-1234-123456789012',
            'response_body'        => 'invalid json',
            'response_status_code' => 200,
            'base_url'             => 'https://api.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        // Verify the response was inserted and matches the endpoint pattern
        $insertedResponse = DB::table($this->responsesTable)->where('id', $responseId)->first();
        $this->assertNotNull($insertedResponse);
        $this->assertStringContainsString('merchant/amazon/asin/task_get/advanced/', $insertedResponse->endpoint);

        // Check how many unprocessed responses match our criteria
        $unprocessedCount = DB::table($this->responsesTable)
            ->whereNull('processed_at')
            ->where('response_status_code', 200)
            ->where('endpoint', 'like', 'merchant/amazon/asin/task_get/advanced%')
            ->count();
        $this->assertEquals(1, $unprocessedCount);

        // Disable sandbox filtering to ensure our response is processed
        $this->processor->setSkipSandbox(false);

        $stats = $this->processor->processResponses();

        // For invalid JSON, the transaction rolls back so processed_responses stays 0
        // but errors is incremented outside the transaction
        $this->assertEquals(0, $stats['processed_responses']);
        $this->assertEquals(1, $stats['errors']);
    }

    public function test_process_responses_handles_missing_tasks(): void
    {
        // Clear any existing responses first
        DB::table($this->responsesTable)->where('endpoint', 'like', 'merchant/amazon/asin/task_get/advanced%')->delete();

        // Insert response without tasks array
        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'no-tasks-key',
            'endpoint'             => 'merchant/amazon/asin/task_get/advanced/12345678-1234-1234-1234-123456789012',
            'response_body'        => json_encode(['status' => 'ok']),
            'response_status_code' => 200,
            'base_url'             => 'https://api.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        // Disable sandbox filtering to ensure our response is processed
        $this->processor->setSkipSandbox(false);

        $stats = $this->processor->processResponses();

        // Missing tasks should cause an exception, rolling back the transaction
        $this->assertEquals(0, $stats['processed_responses']);
        $this->assertEquals(1, $stats['errors']);
    }

    public function test_process_responses_filters_by_endpoint(): void
    {
        // Insert responses with different endpoints
        DB::table($this->responsesTable)->insert([
            [
                'client'               => 'dataforseo',
                'key'                  => 'matching-asin',
                'endpoint'             => 'merchant/amazon/asin/task_get/advanced/12345678-1234-1234-1234-123456789005',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'               => 'dataforseo',
                'key'                  => 'non-matching-endpoint',
                'endpoint'             => 'serp/google/organic/task_get/advanced/test',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
        ]);

        $stats = $this->processor->processResponses();

        // Should process only ASIN endpoint
        $this->assertEquals(1, $stats['processed_responses']);
    }

    public function test_process_responses_filters_by_status_code(): void
    {
        // Insert responses with different status codes
        DB::table($this->responsesTable)->insert([
            [
                'client'               => 'dataforseo',
                'key'                  => 'success-response',
                'endpoint'             => 'merchant/amazon/asin/task_get/advanced/12345678-1234-1234-1234-123456789006',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'               => 'dataforseo',
                'key'                  => 'error-response',
                'endpoint'             => 'merchant/amazon/asin/task_get/advanced/12345678-1234-1234-1234-123456789007',
                'response_body'        => json_encode(['error' => 'API Error']),
                'response_status_code' => 400,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
        ]);

        $stats = $this->processor->processResponses();

        // Should process only successful response
        $this->assertEquals(1, $stats['processed_responses']);
    }

    public function test_process_responses_with_valid_asin_data(): void
    {
        // Clear any existing responses first
        DB::table($this->responsesTable)->where('endpoint', 'like', 'merchant/amazon/asin/task_get/advanced%')->delete();
        DB::table($this->asinItemsTable)->truncate();

        // Insert response with valid ASIN data
        $testData = [
            'tasks' => [
                [
                    'id'   => 'test-task-123',
                    'data' => [
                        'asin'          => 'B123REQUEST',
                        'se'            => 'amazon',
                        'se_type'       => 'asin',
                        'location_code' => 2840,
                        'language_code' => 'en_US',
                        'device'        => 'desktop',
                        'os'            => 'windows',
                    ],
                    'result' => [
                        [
                            'asin'        => 'B123RESPONSE',
                            'se_domain'   => 'amazon.com',
                            'type'        => 'shopping',
                            'check_url'   => 'https://www.amazon.com/dp/B123RESPONSE',
                            'datetime'    => '2024-11-26 14:10:02 +00:00',
                            'items_count' => 2,
                            'items'       => [
                                [
                                    'type'          => 'amazon_product_info',
                                    'rank_absolute' => 1,
                                    'title'         => 'Test Product 1',
                                    'data_asin'     => 'B123ITEM001',
                                    'price_from'    => 29.99,
                                ],
                                [
                                    'type'          => 'amazon_product_info',
                                    'rank_absolute' => 2,
                                    'title'         => 'Test Product 2',
                                    'data_asin'     => 'B123ITEM002',
                                    'price_from'    => 39.99,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'valid-asin-key',
            'endpoint'             => 'merchant/amazon/asin/task_get/advanced/12345678-1234-1234-1234-123456789999',
            'response_body'        => json_encode($testData),
            'response_status_code' => 200,
            'base_url'             => 'https://api.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $this->processor->setSkipSandbox(false);
        $stats = $this->processor->processResponses();

        // Verify processing stats
        $this->assertEquals(1, $stats['processed_responses']);
        $this->assertEquals(2, $stats['items_processed']);
        $this->assertEquals(2, $stats['items_inserted']);
        $this->assertEquals(0, $stats['errors']);

        // Verify data was inserted correctly
        $items = DB::table($this->asinItemsTable)->orderBy('rank_absolute')->get();
        $this->assertCount(2, $items);

        // Verify first item
        $this->assertEquals('B123REQUEST', $items[0]->asin);
        $this->assertEquals('B123RESPONSE', $items[0]->result_asin);
        $this->assertEquals('B123ITEM001', $items[0]->data_asin);
        $this->assertEquals('Test Product 1', $items[0]->title);
        $this->assertEquals(29.99, $items[0]->price_from);

        // Verify second item
        $this->assertEquals('B123REQUEST', $items[1]->asin);
        $this->assertEquals('B123RESPONSE', $items[1]->result_asin);
        $this->assertEquals('B123ITEM002', $items[1]->data_asin);
        $this->assertEquals('Test Product 2', $items[1]->title);
        $this->assertEquals(39.99, $items[1]->price_from);
    }
}

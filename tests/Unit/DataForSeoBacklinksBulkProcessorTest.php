<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\DataForSeoBacklinksBulkProcessor;
use FOfX\ApiCache\ApiCacheManager;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\DB;

class DataForSeoBacklinksBulkProcessorTest extends TestCase
{
    protected DataForSeoBacklinksBulkProcessor $processor;
    protected ApiCacheManager $cacheManager;
    protected string $responsesTable = 'api_cache_dataforseo_responses';
    protected string $bulkItemsTable = 'dataforseo_backlinks_bulk_items';

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheManager = app(ApiCacheManager::class);
        $this->processor    = new DataForSeoBacklinksBulkProcessor($this->cacheManager);
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
        $processor = new DataForSeoBacklinksBulkProcessor($this->cacheManager);
        $this->assertInstanceOf(DataForSeoBacklinksBulkProcessor::class, $processor);
    }

    public function test_constructor_without_cache_manager(): void
    {
        $processor = new DataForSeoBacklinksBulkProcessor();
        $this->assertInstanceOf(DataForSeoBacklinksBulkProcessor::class, $processor);
    }

    public function test_get_skip_sandbox_default(): void
    {
        $this->assertTrue($this->processor->getSkipSandbox());
    }

    public function test_set_skip_sandbox_changes_value(): void
    {
        $original = $this->processor->getSkipSandbox();
        $this->processor->setSkipSandbox(!$original);
        $this->assertEquals(!$original, $this->processor->getSkipSandbox());
    }

    public function test_get_update_if_newer_default(): void
    {
        $this->assertTrue($this->processor->getUpdateIfNewer());
    }

    public function test_set_update_if_newer_changes_value(): void
    {
        $original = $this->processor->getUpdateIfNewer();
        $this->processor->setUpdateIfNewer(!$original);
        $this->assertEquals(!$original, $this->processor->getUpdateIfNewer());
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
                'endpoint'             => 'backlinks/bulk_ranks/live',
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
                'endpoint'             => 'backlinks/bulk_backlinks/live',
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

        // Verify only backlinks bulk responses were reset
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
        DB::table($this->bulkItemsTable)->insert([
            'target'      => 'https://example.com/test',
            'task_id'     => 'test-task-123',
            'response_id' => 456,
            'rank'        => 100,
            'backlinks'   => 50,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $stats = $this->processor->clearProcessedTables();

        // Default behavior returns null
        $this->assertNull($stats['bulk_items_cleared']);

        // Verify table is empty
        $this->assertEquals(0, DB::table($this->bulkItemsTable)->count());
    }

    public function test_clear_processed_tables_with_count_true(): void
    {
        // Insert test data
        DB::table($this->bulkItemsTable)->insert([
            'target'      => 'https://example.com/test1',
            'task_id'     => 'test-task-123',
            'response_id' => 456,
            'rank'        => 100,
            'backlinks'   => 50,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        DB::table($this->bulkItemsTable)->insert([
            'target'      => 'https://example.com/test2',
            'task_id'     => 'test-task-124',
            'response_id' => 457,
            'rank'        => 200,
            'backlinks'   => 75,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $stats = $this->processor->clearProcessedTables(true);

        // With counting enabled, should return actual count
        $this->assertEquals(2, $stats['bulk_items_cleared']);

        // Verify table is empty
        $this->assertEquals(0, DB::table($this->bulkItemsTable)->count());
    }

    public function test_clear_processed_tables_with_count_false(): void
    {
        // Insert test data
        DB::table($this->bulkItemsTable)->insert([
            'target'      => 'https://example.com/test3',
            'task_id'     => 'test-task-125',
            'response_id' => 458,
            'rank'        => 150,
            'backlinks'   => 25,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $stats = $this->processor->clearProcessedTables(false);

        // With counting disabled, should return null
        $this->assertNull($stats['bulk_items_cleared']);

        // Verify table is empty
        $this->assertEquals(0, DB::table($this->bulkItemsTable)->count());
    }

    public function test_extract_metadata_returns_empty_array(): void
    {
        $result = $this->processor->extractMetadata(['total_count' => 10, 'items_count' => 5]);
        $this->assertEquals([], $result);
    }

    public function test_ensure_defaults_returns_data_unchanged(): void
    {
        $data   = ['target' => 'https://example.com', 'rank' => 100];
        $result = $this->processor->ensureDefaults($data);
        $this->assertEquals($data, $result);
    }

    /**
     * Data provider for extractItemIdentifier test
     */
    public static function extractItemIdentifierDataProvider(): array
    {
        return [
            'target_field' => [
                ['target' => 'https://example.com/target'],
                'https://example.com/target',
            ],
            'url_field' => [
                ['url' => 'https://example.com/url'],
                'https://example.com/url',
            ],
            'both_fields_target_priority' => [
                ['target' => 'https://example.com/target', 'url' => 'https://example.com/url'],
                'https://example.com/target',
            ],
            'neither_field' => [
                ['other' => 'value'],
                null,
            ],
            'empty_item' => [
                [],
                null,
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('extractItemIdentifierDataProvider')]
    public function test_extract_item_identifier(array $item, ?string $expected): void
    {
        $result = $this->processor->extractItemIdentifier($item);
        $this->assertEquals($expected, $result);
    }

    public function test_batch_insert_or_update_bulk_items(): void
    {
        $now       = now();
        $bulkItems = [
            [
                'target'      => 'https://example.com/page1',
                'task_id'     => 'task-123',
                'response_id' => 456,
                'rank'        => 100,
                'backlinks'   => 50,
                'spam_score'  => 10,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'target'      => 'https://example.com/page2',
                'task_id'     => 'task-123',
                'response_id' => 456,
                'rank'        => 200,
                'backlinks'   => 25,
                'spam_score'  => 5,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
        ];

        $stats = $this->processor->batchInsertOrUpdateBulkItems($bulkItems);

        $this->assertEquals(2, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);

        // Verify items were inserted
        $insertedItems = DB::table($this->bulkItemsTable)->orderBy('rank')->get();
        $this->assertCount(2, $insertedItems);

        $firstItem = $insertedItems[0];
        $this->assertEquals('https://example.com/page1', $firstItem->target);
        $this->assertEquals(100, $firstItem->rank);
        $this->assertEquals(50, $firstItem->backlinks);
        $this->assertEquals(10, $firstItem->spam_score);

        $secondItem = $insertedItems[1];
        $this->assertEquals('https://example.com/page2', $secondItem->target);
        $this->assertEquals(200, $secondItem->rank);
        $this->assertEquals(25, $secondItem->backlinks);
        $this->assertEquals(5, $secondItem->spam_score);
    }

    public function test_batch_insert_or_update_bulk_items_with_update_if_newer(): void
    {
        $originalTime = now()->subMinutes(10);
        $newerTime    = now();

        // Insert original item
        $originalItem = [
            'target'      => 'https://example.com/update-test',
            'task_id'     => 'task-original',
            'response_id' => 100,
            'rank'        => 100,
            'backlinks'   => null,
            'spam_score'  => 5,
            'created_at'  => $originalTime,
            'updated_at'  => $originalTime,
        ];

        $stats = $this->processor->batchInsertOrUpdateBulkItems([$originalItem]);
        $this->assertEquals(1, $stats['items_inserted']);

        // Update with newer data
        $newerItem = [
            'target'      => 'https://example.com/update-test',
            'task_id'     => 'task-newer',
            'response_id' => 200,
            'rank'        => 150,
            'backlinks'   => 30,
            'spam_score'  => 8,
            'created_at'  => $newerTime,
            'updated_at'  => $newerTime,
        ];

        $stats = $this->processor->batchInsertOrUpdateBulkItems([$newerItem]);
        $this->assertEquals(0, $stats['items_inserted']);
        $this->assertEquals(1, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);

        // Verify item was updated
        $updatedItem = DB::table($this->bulkItemsTable)->where('target', 'https://example.com/update-test')->first();
        $this->assertEquals('task-newer', $updatedItem->task_id);
        $this->assertEquals(150, $updatedItem->rank);
        $this->assertEquals(30, $updatedItem->backlinks);
        $this->assertEquals(8, $updatedItem->spam_score);
    }

    public function test_batch_insert_or_update_bulk_items_with_null_column_update(): void
    {
        // Insert item with some null values
        $originalItem = [
            'target'      => 'https://example.com/null-test',
            'task_id'     => 'task-original',
            'response_id' => 100,
            'rank'        => 100,
            'backlinks'   => null,
            'spam_score'  => null,
            'created_at'  => now(),
            'updated_at'  => now(),
        ];

        $stats = $this->processor->batchInsertOrUpdateBulkItems([$originalItem]);
        $this->assertEquals(1, $stats['items_inserted']);

        // Update with data for null columns
        $updateItem = [
            'target'      => 'https://example.com/null-test',
            'task_id'     => 'task-update',
            'response_id' => 200,
            'rank'        => 100,
            'backlinks'   => 50,
            'spam_score'  => 10,
            'created_at'  => now(),
            'updated_at'  => now(),
        ];

        $stats = $this->processor->batchInsertOrUpdateBulkItems([$updateItem]);
        $this->assertEquals(0, $stats['items_inserted']);
        $this->assertEquals(1, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);

        // Verify null columns were updated
        $updatedItem = DB::table($this->bulkItemsTable)->where('target', 'https://example.com/null-test')->first();
        $this->assertEquals('task-update', $updatedItem->task_id);
        $this->assertEquals(100, $updatedItem->rank);
        $this->assertEquals(50, $updatedItem->backlinks);
        $this->assertEquals(10, $updatedItem->spam_score);
    }

    public function test_batch_insert_or_update_bulk_items_with_update_if_newer_false(): void
    {
        $this->processor->setUpdateIfNewer(false);

        // Insert original item
        $originalItem = [
            'target'      => 'https://example.com/no-update-test',
            'task_id'     => 'task-original',
            'response_id' => 100,
            'rank'        => 100,
            'backlinks'   => null,
            'spam_score'  => 5,
            'created_at'  => now(),
            'updated_at'  => now(),
        ];

        $stats = $this->processor->batchInsertOrUpdateBulkItems([$originalItem]);
        $this->assertEquals(1, $stats['items_inserted']);

        // Try to update with newer data
        $newerItem = [
            'target'      => 'https://example.com/no-update-test',
            'task_id'     => 'task-newer',
            'response_id' => 200,
            'rank'        => 150,
            'backlinks'   => 30,
            'spam_score'  => 8,
            'created_at'  => now(),
            'updated_at'  => now(),
        ];

        $stats = $this->processor->batchInsertOrUpdateBulkItems([$newerItem]);
        $this->assertEquals(0, $stats['items_inserted']);
        $this->assertEquals(1, $stats['items_updated']); // Only null columns should be updated
        $this->assertEquals(0, $stats['items_skipped']);

        // Verify only null columns were updated
        $updatedItem = DB::table($this->bulkItemsTable)->where('target', 'https://example.com/no-update-test')->first();
        $this->assertEquals('task-original', $updatedItem->task_id); // Should not be updated
        $this->assertEquals(100, $updatedItem->rank); // Should not be updated
        $this->assertEquals(30, $updatedItem->backlinks); // Should be updated (was null)
        $this->assertEquals(5, $updatedItem->spam_score); // Should not be updated
    }

    public function test_batch_insert_or_update_bulk_items_with_empty_array(): void
    {
        $stats = $this->processor->batchInsertOrUpdateBulkItems([]);
        $this->assertEquals(0, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);
    }

    public function test_process_bulk_items(): void
    {
        $taskData = [
            'task_id'     => 'task-123',
            'response_id' => 456,
        ];

        $items = [
            [
                'target'            => 'https://example.com/page1',
                'rank'              => 100,
                'backlinks'         => 50,
                'spam_score'        => 10,
                'referring_domains' => 25,
            ],
            [
                'url'               => 'https://example.com/page2',
                'rank'              => 200,
                'backlinks'         => 75,
                'spam_score'        => 5,
                'referring_domains' => 40,
            ],
            [
                'other_field' => 'value', // Should be skipped - no target or url
            ],
        ];

        $result = $this->processor->processBulkItems($items, $taskData);

        $this->assertEquals(2, $result['bulk_items']);
        $this->assertEquals(2, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);

        // Verify items were inserted
        $insertedItems = DB::table($this->bulkItemsTable)->orderBy('rank')->get();
        $this->assertCount(2, $insertedItems);

        $firstItem = $insertedItems[0];
        $this->assertEquals('https://example.com/page1', $firstItem->target);
        $this->assertEquals('task-123', $firstItem->task_id);
        $this->assertEquals(456, $firstItem->response_id);
        $this->assertEquals(100, $firstItem->rank);
        $this->assertEquals(50, $firstItem->backlinks);
        $this->assertEquals(10, $firstItem->spam_score);
        $this->assertEquals(25, $firstItem->referring_domains);

        $secondItem = $insertedItems[1];
        $this->assertEquals('https://example.com/page2', $secondItem->target);
        $this->assertEquals('task-123', $secondItem->task_id);
        $this->assertEquals(456, $secondItem->response_id);
        $this->assertEquals(200, $secondItem->rank);
        $this->assertEquals(75, $secondItem->backlinks);
        $this->assertEquals(5, $secondItem->spam_score);
        $this->assertEquals(40, $secondItem->referring_domains);
    }

    public function test_process_bulk_items_with_empty_array(): void
    {
        $taskData = ['task_id' => 'test'];
        $result   = $this->processor->processBulkItems([], $taskData);

        $this->assertEquals(0, $result['bulk_items']);
        $this->assertEquals(0, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);
    }

    public function test_process_response(): void
    {
        $responseData = [
            'tasks' => [
                [
                    'id'     => 'test-task-123',
                    'result' => [
                        [
                            'total_count' => 2,
                            'items_count' => 2,
                            'items'       => [
                                [
                                    'target'     => 'https://example.com/page1',
                                    'rank'       => 100,
                                    'backlinks'  => 50,
                                    'spam_score' => 10,
                                ],
                                [
                                    'target'     => 'https://example.com/page2',
                                    'rank'       => 200,
                                    'backlinks'  => 75,
                                    'spam_score' => 5,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = (object) [
            'id'            => 123,
            'response_body' => json_encode($responseData),
        ];

        $stats = $this->processor->processResponse($response);

        $this->assertEquals(2, $stats['bulk_items']);
        $this->assertEquals(2, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);
        $this->assertEquals(2, $stats['total_items']);

        // Verify items were inserted
        $bulkItems = DB::table($this->bulkItemsTable)->orderBy('rank')->get();
        $this->assertCount(2, $bulkItems);

        $firstItem = $bulkItems[0];
        $this->assertEquals('https://example.com/page1', $firstItem->target);
        $this->assertEquals('test-task-123', $firstItem->task_id);
        $this->assertEquals(123, $firstItem->response_id);
        $this->assertEquals(100, $firstItem->rank);
        $this->assertEquals(50, $firstItem->backlinks);
        $this->assertEquals(10, $firstItem->spam_score);

        $secondItem = $bulkItems[1];
        $this->assertEquals('https://example.com/page2', $secondItem->target);
        $this->assertEquals('test-task-123', $secondItem->task_id);
        $this->assertEquals(123, $secondItem->response_id);
        $this->assertEquals(200, $secondItem->rank);
        $this->assertEquals(75, $secondItem->backlinks);
        $this->assertEquals(5, $secondItem->spam_score);
    }

    public function test_process_response_with_invalid_json(): void
    {
        $response = (object) [
            'id'            => 789,
            'response_body' => 'invalid json',
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid JSON response or missing tasks array');

        $this->processor->processResponse($response);
    }

    public function test_process_response_with_missing_tasks(): void
    {
        $response = (object) [
            'id'            => 101112,
            'response_body' => json_encode(['status' => 'ok']),
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid JSON response or missing tasks array');

        $this->processor->processResponse($response);
    }

    public function test_process_response_with_empty_tasks(): void
    {
        $response = (object) [
            'id'            => 131415,
            'response_body' => json_encode(['tasks' => []]),
        ];

        $stats = $this->processor->processResponse($response);

        $this->assertEquals(0, $stats['bulk_items']);
        $this->assertEquals(0, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);
        $this->assertEquals(0, $stats['total_items']);
    }

    public function test_process_response_with_task_without_result(): void
    {
        $responseData = [
            'tasks' => [
                [
                    'id' => 'test-task-no-result',
                    // Missing 'result' key
                ],
            ],
        ];

        $response = (object) [
            'id'            => 161718,
            'response_body' => json_encode($responseData),
        ];

        $stats = $this->processor->processResponse($response);

        $this->assertEquals(0, $stats['bulk_items']);
        $this->assertEquals(0, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);
        $this->assertEquals(0, $stats['total_items']);
    }

    public function test_process_responses_with_no_responses(): void
    {
        $stats = $this->processor->processResponses();

        $this->assertEquals(0, $stats['processed_responses']);
        $this->assertEquals(0, $stats['bulk_items']);
        $this->assertEquals(0, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);
        $this->assertEquals(0, $stats['total_items']);
        $this->assertEquals(0, $stats['errors']);
    }

    public function test_process_responses_with_valid_backlinks_bulk_response(): void
    {
        // Insert test response
        $responseData = [
            'tasks' => [
                [
                    'id'     => 'test-task-123',
                    'result' => [
                        [
                            'total_count' => 1,
                            'items_count' => 1,
                            'items'       => [
                                [
                                    'target'            => 'https://example.com/test',
                                    'rank'              => 100,
                                    'backlinks'         => 50,
                                    'spam_score'        => 10,
                                    'referring_domains' => 25,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'test-key',
            'endpoint'             => 'backlinks/bulk_ranks/live',
            'response_body'        => json_encode($responseData),
            'response_status_code' => 200,
            'base_url'             => 'https://api.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $stats = $this->processor->processResponses();

        $this->assertEquals(1, $stats['processed_responses']);
        $this->assertEquals(1, $stats['bulk_items']);
        $this->assertEquals(1, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);
        $this->assertEquals(1, $stats['total_items']);
        $this->assertEquals(0, $stats['errors']);

        // Verify bulk item was inserted
        $bulkItem = DB::table($this->bulkItemsTable)->first();
        $this->assertNotNull($bulkItem);
        $this->assertEquals('https://example.com/test', $bulkItem->target);
        $this->assertEquals('test-task-123', $bulkItem->task_id);
        $this->assertEquals(100, $bulkItem->rank);
        $this->assertEquals(50, $bulkItem->backlinks);
        $this->assertEquals(10, $bulkItem->spam_score);
        $this->assertEquals(25, $bulkItem->referring_domains);
    }

    public function test_process_responses_skips_sandbox_when_configured(): void
    {
        // Insert sandbox response
        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'sandbox-key',
            'endpoint'             => 'backlinks/bulk_ranks/live',
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
            'endpoint'             => 'backlinks/bulk_ranks/live',
            'response_body'        => json_encode(['tasks' => []]),
            'response_status_code' => 200,
            'base_url'             => 'https://api.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $this->processor->setSkipSandbox(true);
        $stats = $this->processor->processResponses();

        // Should only process the non-sandbox response
        $this->assertEquals(1, $stats['processed_responses']);
    }

    public function test_process_responses_handles_invalid_json(): void
    {
        // Insert response with invalid JSON
        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'invalid-json-key',
            'endpoint'             => 'backlinks/bulk_ranks/live',
            'response_body'        => 'invalid json',
            'response_status_code' => 200,
            'base_url'             => 'https://api.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $stats = $this->processor->processResponses();

        $this->assertEquals(0, $stats['processed_responses']);
        $this->assertEquals(1, $stats['errors']);

        // Verify error was logged in processed_status
        $response = DB::table($this->responsesTable)->where('key', 'invalid-json-key')->first();
        $this->assertNotNull($response->processed_at);
        $processedStatus = json_decode($response->processed_status, true);
        $this->assertEquals('ERROR', $processedStatus['status']);
        $this->assertStringContainsString('Invalid JSON', $processedStatus['error']);
    }

    public function test_process_responses_handles_missing_tasks(): void
    {
        // Insert response without tasks array
        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'no-tasks-key',
            'endpoint'             => 'backlinks/bulk_ranks/live',
            'response_body'        => json_encode(['status' => 'ok']),
            'response_status_code' => 200,
            'base_url'             => 'https://api.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $stats = $this->processor->processResponses();

        $this->assertEquals(0, $stats['processed_responses']);
        $this->assertEquals(1, $stats['errors']);

        // Verify error was logged
        $response        = DB::table($this->responsesTable)->where('key', 'no-tasks-key')->first();
        $processedStatus = json_decode($response->processed_status, true);
        $this->assertEquals('ERROR', $processedStatus['status']);
        $this->assertStringContainsString('missing tasks array', $processedStatus['error']);
    }

    public function test_process_responses_filters_by_endpoint_patterns(): void
    {
        // Insert responses with different endpoints
        DB::table($this->responsesTable)->insert([
            [
                'client'               => 'dataforseo',
                'key'                  => 'matching-ranks',
                'endpoint'             => 'backlinks/bulk_ranks/live',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'               => 'dataforseo',
                'key'                  => 'matching-backlinks',
                'endpoint'             => 'backlinks/bulk_backlinks/live',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'               => 'dataforseo',
                'key'                  => 'non-matching',
                'endpoint'             => 'other/endpoint',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
        ]);

        $stats = $this->processor->processResponses();

        // Should only process the 2 matching endpoints
        $this->assertEquals(2, $stats['processed_responses']);

        // Verify non-matching endpoint was not processed
        $nonMatchingResponse = DB::table($this->responsesTable)
            ->where('key', 'non-matching')
            ->first();
        $this->assertNull($nonMatchingResponse->processed_at);
    }

    public function test_process_responses_skips_non_200_status_codes(): void
    {
        // Insert responses with different status codes
        DB::table($this->responsesTable)->insert([
            [
                'client'               => 'dataforseo',
                'key'                  => 'success-response',
                'endpoint'             => 'backlinks/bulk_ranks/live',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'               => 'dataforseo',
                'key'                  => 'error-response',
                'endpoint'             => 'backlinks/bulk_ranks/live',
                'response_body'        => json_encode(['error' => 'API Error']),
                'response_status_code' => 400,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
        ]);

        $stats = $this->processor->processResponses();

        // Should only process the 200 status response
        $this->assertEquals(1, $stats['processed_responses']);

        // Verify error response was not processed
        $errorResponse = DB::table($this->responsesTable)
            ->where('key', 'error-response')
            ->first();
        $this->assertNull($errorResponse->processed_at);
    }
}

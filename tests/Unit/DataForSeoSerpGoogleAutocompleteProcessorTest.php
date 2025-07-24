<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\DataForSeoSerpGoogleAutocompleteProcessor;
use FOfX\ApiCache\ApiCacheManager;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\DB;

class DataForSeoSerpGoogleAutocompleteProcessorTest extends TestCase
{
    protected DataForSeoSerpGoogleAutocompleteProcessor $processor;
    protected ApiCacheManager $cacheManager;
    protected string $responsesTable         = 'api_cache_dataforseo_responses';
    protected string $autocompleteItemsTable = 'dataforseo_serp_google_autocomplete_items';

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheManager = app(ApiCacheManager::class);
        $this->processor    = new DataForSeoSerpGoogleAutocompleteProcessor($this->cacheManager);
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
        $processor = new DataForSeoSerpGoogleAutocompleteProcessor($this->cacheManager);
        $this->assertInstanceOf(DataForSeoSerpGoogleAutocompleteProcessor::class, $processor);
    }

    public function test_constructor_without_cache_manager(): void
    {
        $processor = new DataForSeoSerpGoogleAutocompleteProcessor();
        $this->assertInstanceOf(DataForSeoSerpGoogleAutocompleteProcessor::class, $processor);
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
                'endpoint'             => 'serp/google/autocomplete/task_get/advanced',
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
                'endpoint'             => 'serp/google/autocomplete/live/advanced',
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

        // Verify only autocomplete responses were reset
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
        DB::table($this->autocompleteItemsTable)->insert([
            'keyword'       => 'test',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'rank_absolute' => 1,
            'suggestion'    => 'test suggestion',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $stats = $this->processor->clearProcessedTables();

        // Default behavior returns null
        $this->assertNull($stats['autocomplete_cleared']);

        // Verify table is empty
        $this->assertEquals(0, DB::table($this->autocompleteItemsTable)->count());
    }

    public function test_clear_processed_tables_with_count_true(): void
    {
        // Insert test data
        DB::table($this->autocompleteItemsTable)->insert([
            'keyword'       => 'test1',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'rank_absolute' => 1,
            'suggestion'    => 'test suggestion 1',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table($this->autocompleteItemsTable)->insert([
            'keyword'       => 'test2',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'rank_absolute' => 2,
            'suggestion'    => 'test suggestion 2',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $stats = $this->processor->clearProcessedTables(true);

        // With counting enabled, should return actual count
        $this->assertEquals(2, $stats['autocomplete_cleared']);

        // Verify table is empty
        $this->assertEquals(0, DB::table($this->autocompleteItemsTable)->count());
    }

    public function test_clear_processed_tables_with_count_false(): void
    {
        // Insert test data
        DB::table($this->autocompleteItemsTable)->insert([
            'keyword'       => 'test3',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'rank_absolute' => 1,
            'suggestion'    => 'test suggestion 3',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $stats = $this->processor->clearProcessedTables(false);

        // With counting disabled, should return null
        $this->assertNull($stats['autocomplete_cleared']);

        // Verify table is empty
        $this->assertEquals(0, DB::table($this->autocompleteItemsTable)->count());
    }

    /**
     * Data provider for extractTaskData test
     */
    public static function extractTaskDataDataProvider(): array
    {
        return [
            'complete_data' => [
                [
                    'keyword'        => 'test keyword',
                    'location_code'  => 2840,
                    'language_code'  => 'en',
                    'device'         => 'desktop',
                    'os'             => 'windows',
                    'cursor_pointer' => 16,
                    'tag'            => 'test-tag',
                    'extra_field'    => 'ignored',
                ],
                [
                    'keyword'        => 'test keyword',
                    'location_code'  => 2840,
                    'language_code'  => 'en',
                    'device'         => 'desktop',
                    'os'             => 'windows',
                    'cursor_pointer' => 16,
                    'tag'            => 'test-tag',
                ],
            ],
            'partial_data' => [
                [
                    'keyword' => 'partial keyword',
                    'device'  => 'mobile',
                    'os'      => 'android',
                ],
                [
                    'keyword'        => 'partial keyword',
                    'location_code'  => null,
                    'language_code'  => null,
                    'device'         => 'mobile',
                    'os'             => 'android',
                    'cursor_pointer' => -1,
                    'tag'            => null,
                ],
            ],
            'empty_data' => [
                [],
                [
                    'keyword'        => null,
                    'location_code'  => null,
                    'language_code'  => null,
                    'device'         => null,
                    'os'             => null,
                    'cursor_pointer' => -1,
                    'tag'            => null,
                ],
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('extractTaskDataDataProvider')]
    public function test_extract_task_data(array $input, array $expected): void
    {
        $result = $this->processor->extractTaskData($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Data provider for extractResultMetadata test
     */
    public static function extractResultMetadataDataProvider(): array
    {
        return [
            'complete_data' => [
                [
                    'keyword'       => 'test keyword',
                    'se_domain'     => 'google.com',
                    'location_code' => 2840,
                    'language_code' => 'en',
                    'device'        => 'desktop',
                    'os'            => 'windows',
                    'extra_field'   => 'ignored',
                ],
                [
                    'result_keyword' => 'test keyword',
                    'se_domain'      => 'google.com',
                ],
            ],
            'partial_data' => [
                [
                    'keyword'   => 'partial keyword',
                    'se_domain' => 'google.co.uk',
                ],
                [
                    'result_keyword' => 'partial keyword',
                    'se_domain'      => 'google.co.uk',
                ],
            ],
            'empty_data' => [
                [],
                [
                    'result_keyword' => null,
                    'se_domain'      => null,
                ],
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('extractResultMetadataDataProvider')]
    public function test_extract_result_metadata(array $input, array $expected): void
    {
        $result = $this->processor->extractResultMetadata($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Data provider for extractAutocompleteItemsData test
     */
    public static function extractAutocompleteItemsDataDataProvider(): array
    {
        return [
            'complete_data' => [
                [
                    'response_id'    => 123,
                    'task_id'        => 'task-456',
                    'keyword'        => 'test keyword',
                    'tag'            => 'test-tag',
                    'result_keyword' => 'test result keyword',
                    'se_domain'      => 'google.com',
                    'location_code'  => 2840,
                    'language_code'  => 'en',
                    'device'         => 'desktop',
                    'os'             => 'windows',
                    'cursor_pointer' => 16,
                    'extra_field'    => 'should be filtered out',
                ],
                [
                    'response_id'    => 123,
                    'task_id'        => 'task-456',
                    'keyword'        => 'test keyword',
                    'tag'            => 'test-tag',
                    'result_keyword' => 'test result keyword',
                    'se_domain'      => 'google.com',
                    'location_code'  => 2840,
                    'language_code'  => 'en',
                    'device'         => 'desktop',
                    'os'             => 'windows',
                    'cursor_pointer' => 16,
                ],
            ],
            'partial_data' => [
                [
                    'keyword'   => 'partial keyword',
                    'se_domain' => 'google.co.uk',
                ],
                [
                    'response_id'    => null,
                    'task_id'        => null,
                    'keyword'        => 'partial keyword',
                    'tag'            => null,
                    'result_keyword' => null,
                    'se_domain'      => 'google.co.uk',
                    'location_code'  => null,
                    'language_code'  => null,
                    'device'         => null,
                    'os'             => null,
                    'cursor_pointer' => -1,
                ],
            ],
            'empty_data' => [
                [],
                [
                    'response_id'    => null,
                    'task_id'        => null,
                    'keyword'        => null,
                    'tag'            => null,
                    'result_keyword' => null,
                    'se_domain'      => null,
                    'location_code'  => null,
                    'language_code'  => null,
                    'device'         => null,
                    'os'             => null,
                    'cursor_pointer' => -1,
                ],
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('extractAutocompleteItemsDataDataProvider')]
    public function test_extract_autocomplete_items_data(array $input, array $expected): void
    {
        $result = $this->processor->extractAutocompleteItemsData($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Data provider for ensureDefaults test
     */
    public static function ensureDefaultsDataProvider(): array
    {
        return [
            'no_device_specified' => [
                [
                    'keyword'       => 'test',
                    'se_domain'     => 'google.com',
                    'location_code' => 2840,
                ],
                [
                    'keyword'       => 'test',
                    'se_domain'     => 'google.com',
                    'location_code' => 2840,
                    'device'        => 'desktop',
                ],
            ],
            'device_already_specified' => [
                [
                    'keyword' => 'test',
                    'device'  => 'mobile',
                ],
                [
                    'keyword' => 'test',
                    'device'  => 'mobile',
                ],
            ],
            'empty_data' => [
                [],
                [
                    'device' => 'desktop',
                ],
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ensureDefaultsDataProvider')]
    public function test_ensure_defaults(array $input, array $expected): void
    {
        $result = $this->processor->ensureDefaults($input);
        $this->assertEquals($expected, $result);
    }

    public function test_batch_insert_or_update_autocomplete_items(): void
    {
        $now               = now();
        $autocompleteItems = [
            [
                'keyword'          => 'test autocomplete 1',
                'cursor_pointer'   => -1,
                'location_code'    => 2840,
                'language_code'    => 'en',
                'device'           => 'desktop',
                'rank_absolute'    => 1,
                'se_domain'        => 'google.com',
                'task_id'          => 'task-123',
                'response_id'      => 456,
                'type'             => 'autocomplete',
                'rank_group'       => 1,
                'relevance'        => 100,
                'suggestion'       => 'test autocomplete suggestion 1',
                'suggestion_type'  => 'query',
                'search_query_url' => 'https://google.com/search?q=test+autocomplete+1',
                'thumbnail_url'    => 'https://example.com/thumb1.jpg',
                'highlighted'      => '["test"]',
                'created_at'       => $now,
                'updated_at'       => $now,
            ],
            [
                'keyword'          => 'test autocomplete 2',
                'cursor_pointer'   => 16,
                'location_code'    => 2840,
                'language_code'    => 'en',
                'device'           => 'desktop',
                'rank_absolute'    => 2,
                'se_domain'        => 'google.com',
                'task_id'          => 'task-123',
                'response_id'      => 456,
                'type'             => 'autocomplete',
                'rank_group'       => 2,
                'relevance'        => 90,
                'suggestion'       => 'test autocomplete suggestion 2',
                'suggestion_type'  => 'query',
                'search_query_url' => 'https://google.com/search?q=test+autocomplete+2',
                'thumbnail_url'    => null,
                'highlighted'      => null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ],
        ];

        $this->processor->batchInsertOrUpdateAutocompleteItems($autocompleteItems);

        // Verify items were inserted
        $insertedItems = DB::table($this->autocompleteItemsTable)->orderBy('rank_absolute')->get();
        $this->assertCount(2, $insertedItems);

        $firstItem = $insertedItems[0];
        $this->assertEquals('test autocomplete 1', $firstItem->keyword);
        $this->assertEquals(1, $firstItem->rank_absolute);
        $this->assertEquals('test autocomplete suggestion 1', $firstItem->suggestion);
        $this->assertEquals('query', $firstItem->suggestion_type);
        $this->assertEquals('https://example.com/thumb1.jpg', $firstItem->thumbnail_url);

        $secondItem = $insertedItems[1];
        $this->assertEquals('test autocomplete 2', $secondItem->keyword);
        $this->assertEquals(2, $secondItem->rank_absolute);
        $this->assertEquals('test autocomplete suggestion 2', $secondItem->suggestion);
        $this->assertNull($secondItem->thumbnail_url);
    }

    public function test_batch_insert_or_update_autocomplete_items_with_duplicates(): void
    {
        $originalTime = now()->subMinutes(10);
        $newerTime    = now();

        $originalItem = [
            'keyword'        => 'duplicate autocomplete',
            'cursor_pointer' => -1,
            'location_code'  => 2840,
            'language_code'  => 'en',
            'device'         => 'desktop',
            'rank_absolute'  => 1,
            'se_domain'      => 'google.com',
            'task_id'        => 'task-original',
            'response_id'    => 100,
            'type'           => 'autocomplete',
            'suggestion'     => 'original suggestion',
            'created_at'     => $originalTime,
            'updated_at'     => $originalTime,
        ];

        // Insert original item
        $this->processor->batchInsertOrUpdateAutocompleteItems([$originalItem]);

        // Verify original was inserted
        $this->assertEquals(1, DB::table($this->autocompleteItemsTable)->count());
        $original = DB::table($this->autocompleteItemsTable)->first();
        $this->assertEquals('original suggestion', $original->suggestion);
        $this->assertEquals('task-original', $original->task_id);

        // Insert updated item with same unique constraints but newer timestamp
        $updatedItem = [
            'keyword'        => 'duplicate autocomplete',
            'cursor_pointer' => -1,
            'location_code'  => 2840,
            'language_code'  => 'en',
            'device'         => 'desktop',
            'rank_absolute'  => 2, // Different rank
            'se_domain'      => 'google.com',
            'task_id'        => 'task-updated',
            'response_id'    => 200,
            'type'           => 'autocomplete',
            'suggestion'     => 'original suggestion', // Same suggestion triggers duplicate
            'created_at'     => $newerTime,
            'updated_at'     => $newerTime,
        ];

        $this->processor->batchInsertOrUpdateAutocompleteItems([$updatedItem]);

        // Verify item was updated, not duplicated
        $this->assertEquals(1, DB::table($this->autocompleteItemsTable)->count());
        $updated = DB::table($this->autocompleteItemsTable)->first();
        $this->assertEquals('original suggestion', $updated->suggestion);
        $this->assertEquals('task-updated', $updated->task_id);
    }

    public function test_batch_insert_or_update_autocomplete_items_with_empty_array(): void
    {
        // Test with empty array - should not cause errors
        $this->processor->batchInsertOrUpdateAutocompleteItems([]);
        $this->assertEquals(0, DB::table($this->autocompleteItemsTable)->count());
    }

    public function test_batch_insert_or_update_autocomplete_items_with_update_if_newer_false(): void
    {
        // Set updateIfNewer to false
        $this->processor->setUpdateIfNewer(false);

        $now          = now();
        $originalItem = [
            'keyword'        => 'test autocomplete',
            'cursor_pointer' => 9,
            'location_code'  => 2840,
            'language_code'  => 'en',
            'device'         => 'desktop',
            'rank_absolute'  => 1,
            'se_domain'      => 'google.com',
            'task_id'        => 'task-original',
            'response_id'    => 100,
            'type'           => 'autocomplete',
            'suggestion'     => 'original suggestion',
            'created_at'     => $now,
            'updated_at'     => $now,
        ];

        // Insert original item
        $this->processor->batchInsertOrUpdateAutocompleteItems([$originalItem]);
        $this->assertEquals(1, DB::table($this->autocompleteItemsTable)->count());

        // Try to insert duplicate - should be ignored
        $duplicateItem = [
            'keyword'        => 'test autocomplete',
            'cursor_pointer' => 9, // Same cursor position as original
            'location_code'  => 2840,
            'language_code'  => 'en',
            'device'         => 'desktop',
            'rank_absolute'  => 2, // Different rank
            'se_domain'      => 'google.com',
            'task_id'        => 'task-duplicate',
            'response_id'    => 200,
            'type'           => 'autocomplete',
            'suggestion'     => 'original suggestion', // Same suggestion triggers duplicate
            'created_at'     => $now,
            'updated_at'     => $now,
        ];

        $this->processor->batchInsertOrUpdateAutocompleteItems([$duplicateItem]);

        // Should still have only 1 record with original data
        $this->assertEquals(1, DB::table($this->autocompleteItemsTable)->count());
        $record = DB::table($this->autocompleteItemsTable)->first();
        $this->assertEquals('original suggestion', $record->suggestion);
        $this->assertEquals('task-original', $record->task_id);
    }

    public function test_batch_insert_or_update_autocomplete_items_with_older_timestamp(): void
    {
        $newerTime = now();
        $olderTime = now()->subMinutes(10);

        // Insert newer item first
        $newerItem = [
            'keyword'        => 'timestamp test autocomplete',
            'cursor_pointer' => 0,
            'location_code'  => 2840,
            'language_code'  => 'en',
            'device'         => 'desktop',
            'rank_absolute'  => 1,
            'se_domain'      => 'google.com',
            'task_id'        => 'task-newer',
            'response_id'    => 100,
            'type'           => 'autocomplete',
            'suggestion'     => 'newer suggestion',
            'created_at'     => $newerTime,
            'updated_at'     => $newerTime,
        ];

        $this->processor->batchInsertOrUpdateAutocompleteItems([$newerItem]);
        $this->assertEquals(1, DB::table($this->autocompleteItemsTable)->count());

        // Try to insert older item - should NOT update
        $olderItem = [
            'keyword'        => 'timestamp test autocomplete',
            'cursor_pointer' => 0, // Same cursor position as newer item
            'location_code'  => 2840,
            'language_code'  => 'en',
            'device'         => 'desktop',
            'rank_absolute'  => 2, // Different rank
            'se_domain'      => 'google.com',
            'task_id'        => 'task-older',
            'response_id'    => 200,
            'type'           => 'autocomplete',
            'suggestion'     => 'newer suggestion', // Same suggestion triggers duplicate
            'created_at'     => $olderTime,
            'updated_at'     => $olderTime,
        ];

        $this->processor->batchInsertOrUpdateAutocompleteItems([$olderItem]);

        // Should still have only 1 record with newer data (not updated)
        $this->assertEquals(1, DB::table($this->autocompleteItemsTable)->count());
        $record = DB::table($this->autocompleteItemsTable)->first();
        $this->assertEquals('newer suggestion', $record->suggestion);
        $this->assertEquals('task-newer', $record->task_id);
    }

    public function test_process_autocomplete_items(): void
    {
        $taskData = [
            'keyword'        => 'test autocomplete keyword',
            'cursor_pointer' => -1,
            'se_domain'      => 'google.com',
            'location_code'  => 2840,
            'language_code'  => 'en',
            'device'         => 'desktop',
            'task_id'        => 'task-autocomplete-123',
            'response_id'    => 456,
        ];

        $items = [
            [
                'type'             => 'autocomplete',
                'rank_group'       => 1,
                'rank_absolute'    => 1,
                'relevance'        => 100,
                'suggestion'       => 'test autocomplete suggestion 1',
                'suggestion_type'  => 'query',
                'search_query_url' => 'https://google.com/search?q=test+autocomplete+1',
                'thumbnail_url'    => 'https://example.com/thumb1.jpg',
                'highlighted'      => ['test'],
            ],
            [
                'type'             => 'autocomplete',
                'rank_group'       => 2,
                'rank_absolute'    => 2,
                'relevance'        => 90,
                'suggestion'       => 'test autocomplete suggestion 2',
                'suggestion_type'  => 'query',
                'search_query_url' => 'https://google.com/search?q=test+autocomplete+2',
                'thumbnail_url'    => null,
                'highlighted'      => null,
            ],
            [
                'type'       => 'other', // Should be ignored as it is not an autocomplete item
                'suggestion' => 'Other suggestion',
            ],
        ];

        $result = $this->processor->processAutocompleteItems($items, $taskData);

        // Should return detailed stats with count of autocomplete items processed (2)
        $this->assertEquals(2, $result['autocomplete_items']);
        $this->assertEquals(2, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);

        // Verify items were inserted into database
        $insertedItems = DB::table($this->autocompleteItemsTable)->orderBy('rank_absolute')->get();
        $this->assertCount(2, $insertedItems);

        $firstItem = $insertedItems[0];
        $this->assertEquals('test autocomplete keyword', $firstItem->keyword);
        $this->assertEquals(1, $firstItem->rank_absolute);
        $this->assertEquals('test autocomplete suggestion 1', $firstItem->suggestion);
        $this->assertEquals('query', $firstItem->suggestion_type);
        $this->assertEquals('https://example.com/thumb1.jpg', $firstItem->thumbnail_url);
        $this->assertEquals('["test"]', $firstItem->highlighted);

        $secondItem = $insertedItems[1];
        $this->assertEquals('test autocomplete keyword', $secondItem->keyword);
        $this->assertEquals(2, $secondItem->rank_absolute);
        $this->assertEquals('test autocomplete suggestion 2', $secondItem->suggestion);
        $this->assertNull($secondItem->thumbnail_url);
        $this->assertNull($secondItem->highlighted);
    }

    public function test_process_autocomplete_items_with_no_autocomplete_items(): void
    {
        $taskData = [
            'keyword'       => 'test keyword',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
        ];

        $items = [
            ['type' => 'other'],
            ['type' => 'different'],
        ];

        $result = $this->processor->processAutocompleteItems($items, $taskData);

        $this->assertEquals(0, $result['autocomplete_items']);
        $this->assertEquals(0, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);
        $this->assertEquals(0, DB::table($this->autocompleteItemsTable)->count());
    }

    public function test_process_autocomplete_items_with_empty_array(): void
    {
        $taskData = ['keyword' => 'test'];
        $result   = $this->processor->processAutocompleteItems([], $taskData);

        $this->assertEquals(0, $result['autocomplete_items']);
        $this->assertEquals(0, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);
        $this->assertEquals(0, DB::table($this->autocompleteItemsTable)->count());
    }

    public function test_process_response(): void
    {
        $responseData = [
            'tasks' => [
                [
                    'id'   => 'test-task-123',
                    'data' => [
                        'keyword'       => 'test response keyword',
                        'se_domain'     => 'google.com',
                        'location_code' => 2840,
                        'language_code' => 'en',
                        'device'        => 'desktop',
                    ],
                    'result' => [
                        [
                            'keyword'       => 'test response keyword',
                            'se_domain'     => 'google.com',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'device'        => 'desktop',
                            'items'         => [
                                [
                                    'type'             => 'autocomplete',
                                    'rank_group'       => 1,
                                    'rank_absolute'    => 1,
                                    'relevance'        => 100,
                                    'suggestion'       => 'test response suggestion 1',
                                    'suggestion_type'  => 'query',
                                    'search_query_url' => 'https://google.com/search?q=test+response+1',
                                    'thumbnail_url'    => 'https://example.com/thumb1.jpg',
                                    'highlighted'      => ['test'],
                                ],
                                [
                                    'type'             => 'autocomplete',
                                    'rank_group'       => 2,
                                    'rank_absolute'    => 2,
                                    'relevance'        => 90,
                                    'suggestion'       => 'test response suggestion 2',
                                    'suggestion_type'  => 'query',
                                    'search_query_url' => 'https://google.com/search?q=test+response+2',
                                    'thumbnail_url'    => null,
                                    'highlighted'      => null,
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

        $this->assertEquals(2, $stats['autocomplete_items']);
        $this->assertEquals(2, $stats['total_items']);

        // Verify items were inserted
        $autocompleteItems = DB::table($this->autocompleteItemsTable)->orderBy('rank_absolute')->get();
        $this->assertCount(2, $autocompleteItems);

        $firstItem = $autocompleteItems[0];
        $this->assertEquals('test response keyword', $firstItem->keyword);
        $this->assertEquals('test response suggestion 1', $firstItem->suggestion);
        $this->assertEquals('https://example.com/thumb1.jpg', $firstItem->thumbnail_url);

        $secondItem = $autocompleteItems[1];
        $this->assertEquals('test response keyword', $secondItem->keyword);
        $this->assertEquals('test response suggestion 2', $secondItem->suggestion);
        $this->assertNull($secondItem->thumbnail_url);
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

        $this->assertEquals(0, $stats['autocomplete_items']);
        $this->assertEquals(0, $stats['total_items']);
    }

    public function test_process_response_with_task_without_result(): void
    {
        $responseData = [
            'tasks' => [
                [
                    'id'   => 'test-task-no-result',
                    'data' => ['keyword' => 'test'],
                    // Missing 'result' key
                ],
            ],
        ];

        $response = (object) [
            'id'            => 161718,
            'response_body' => json_encode($responseData),
        ];

        $stats = $this->processor->processResponse($response);

        $this->assertEquals(0, $stats['autocomplete_items']);
        $this->assertEquals(0, $stats['total_items']);
    }

    public function test_process_responses_with_no_responses(): void
    {
        $stats = $this->processor->processResponses();

        $this->assertEquals(0, $stats['processed_responses']);
        $this->assertEquals(0, $stats['autocomplete_items']);
        $this->assertEquals(0, $stats['total_items']);
        $this->assertEquals(0, $stats['errors']);
    }

    public function test_process_responses_with_valid_autocomplete_response(): void
    {
        // Insert test response
        $responseData = [
            'tasks' => [
                [
                    'id'   => 'test-task-123',
                    'data' => [
                        'keyword'       => 'test keyword',
                        'se_domain'     => 'google.com',
                        'location_code' => 2840,
                        'language_code' => 'en',
                        'device'        => 'desktop',
                    ],
                    'result' => [
                        [
                            'keyword'       => 'test keyword',
                            'se_domain'     => 'google.com',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'device'        => 'desktop',
                            'items'         => [
                                [
                                    'type'             => 'autocomplete',
                                    'rank_group'       => 1,
                                    'rank_absolute'    => 1,
                                    'relevance'        => 100,
                                    'suggestion'       => 'test suggestion',
                                    'suggestion_type'  => 'query',
                                    'search_query_url' => 'https://google.com/search?q=test',
                                    'thumbnail_url'    => 'https://example.com/thumb.jpg',
                                    'highlighted'      => ['test'],
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
            'endpoint'             => 'serp/google/autocomplete/task_get/advanced',
            'response_body'        => json_encode($responseData),
            'response_status_code' => 200,
            'base_url'             => 'https://api.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $stats = $this->processor->processResponses();

        $this->assertEquals(1, $stats['processed_responses']);
        $this->assertEquals(1, $stats['autocomplete_items']);
        $this->assertEquals(1, $stats['total_items']);
        $this->assertEquals(0, $stats['errors']);

        // Verify autocomplete item was inserted
        $autocompleteItem = DB::table($this->autocompleteItemsTable)->first();
        $this->assertNotNull($autocompleteItem);
        $this->assertEquals('test keyword', $autocompleteItem->keyword);
        $this->assertEquals('test suggestion', $autocompleteItem->suggestion);
        $this->assertEquals('https://example.com/thumb.jpg', $autocompleteItem->thumbnail_url);
    }

    public function test_process_responses_skips_sandbox_when_configured(): void
    {
        // Insert sandbox response
        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'sandbox-key',
            'endpoint'             => 'serp/google/autocomplete/task_get/advanced',
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
            'endpoint'             => 'serp/google/autocomplete/task_get/advanced',
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
            'endpoint'             => 'serp/google/autocomplete/task_get/advanced',
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
            'endpoint'             => 'serp/google/autocomplete/task_get/advanced',
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

    public function test_process_responses_applies_device_default(): void
    {
        // Insert response without device specified
        $responseData = [
            'tasks' => [
                [
                    'id'   => 'test-task-default-device',
                    'data' => [
                        'keyword'       => 'test keyword',
                        'se_domain'     => 'google.com',
                        'location_code' => 2840,
                        'language_code' => 'en',
                        // No device specified
                    ],
                    'result' => [
                        [
                            'keyword'       => 'test keyword',
                            'se_domain'     => 'google.com',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'items'         => [
                                [
                                    'type'          => 'autocomplete',
                                    'rank_absolute' => 1,
                                    'suggestion'    => 'test suggestion',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'default-device-key',
            'endpoint'             => 'serp/google/autocomplete/task_get/advanced',
            'response_body'        => json_encode($responseData),
            'response_status_code' => 200,
            'base_url'             => 'https://api.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $stats = $this->processor->processResponses();

        $this->assertEquals(1, $stats['processed_responses']);
        $this->assertEquals(1, $stats['autocomplete_items']);

        // Verify device default was applied
        $autocompleteItem = DB::table($this->autocompleteItemsTable)->first();
        $this->assertEquals('desktop', $autocompleteItem->device);
    }

    public function test_process_responses_filters_by_endpoint_patterns(): void
    {
        // Insert responses with different endpoints
        DB::table($this->responsesTable)->insert([
            [
                'client'               => 'dataforseo',
                'key'                  => 'matching-task-get',
                'endpoint'             => 'serp/google/autocomplete/task_get/advanced',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'               => 'dataforseo',
                'key'                  => 'matching-live',
                'endpoint'             => 'serp/google/autocomplete/live/advanced',
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
                'endpoint'             => 'serp/google/autocomplete/task_get/advanced',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'               => 'dataforseo',
                'key'                  => 'error-response',
                'endpoint'             => 'serp/google/autocomplete/task_get/advanced',
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

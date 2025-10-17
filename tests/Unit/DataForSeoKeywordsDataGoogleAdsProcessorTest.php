<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\DataForSeoKeywordsDataGoogleAdsProcessor;
use FOfX\ApiCache\ApiCacheManager;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\DB;

class DataForSeoKeywordsDataGoogleAdsProcessorTest extends TestCase
{
    // Test constants matching the processor class
    public const WORLDWIDE_LOCATION_CODE = DataForSeoKeywordsDataGoogleAdsProcessor::WORLDWIDE_LOCATION_CODE;
    public const WORLDWIDE_LANGUAGE_CODE = DataForSeoKeywordsDataGoogleAdsProcessor::WORLDWIDE_LANGUAGE_CODE;

    protected DataForSeoKeywordsDataGoogleAdsProcessor $processor;
    protected ApiCacheManager $cacheManager;
    protected string $responsesTable      = 'api_cache_dataforseo_responses';
    protected string $googleAdsItemsTable = 'dataforseo_keywords_data_google_ads_items';

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheManager = app(ApiCacheManager::class);
        $this->processor    = new DataForSeoKeywordsDataGoogleAdsProcessor($this->cacheManager);
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
        $processor = new DataForSeoKeywordsDataGoogleAdsProcessor($this->cacheManager);
        $this->assertInstanceOf(DataForSeoKeywordsDataGoogleAdsProcessor::class, $processor);
    }

    public function test_constructor_without_cache_manager(): void
    {
        $processor = new DataForSeoKeywordsDataGoogleAdsProcessor();
        $this->assertInstanceOf(DataForSeoKeywordsDataGoogleAdsProcessor::class, $processor);
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

    public function test_get_skip_monthly_searches_default(): void
    {
        $this->assertFalse($this->processor->getSkipMonthlySearches());
    }

    public function test_set_skip_monthly_searches_changes_value(): void
    {
        $original = $this->processor->getSkipMonthlySearches();
        $this->processor->setSkipMonthlySearches(!$original);
        $this->assertEquals(!$original, $this->processor->getSkipMonthlySearches());
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
                'endpoint'             => 'keywords_data/google_ads/search_volume/live',
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
                'endpoint'             => 'keywords_data/google_ads/keywords_for_keywords/live',
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

        // Verify only keywords data google ads responses were reset
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
        DB::table($this->googleAdsItemsTable)->insert([
            'keyword'       => 'test keyword',
            'location_code' => 2840,
            'language_code' => 'en',
            'se'            => 'google_ads',
            'task_id'       => 'test-task-123',
            'response_id'   => 456,
            'search_volume' => 1000,
            'competition'   => 'HIGH',
            'cpc'           => 2.50,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $stats = $this->processor->clearProcessedTables();

        // Default behavior returns null
        $this->assertNull($stats['items_cleared']);

        // Verify table is empty
        $this->assertEquals(0, DB::table($this->googleAdsItemsTable)->count());
    }

    public function test_clear_processed_tables_with_count_true(): void
    {
        // Insert test data
        DB::table($this->googleAdsItemsTable)->insert([
            'keyword'       => 'test keyword 1',
            'location_code' => 2840,
            'language_code' => 'en',
            'se'            => 'google_ads',
            'task_id'       => 'test-task-123',
            'response_id'   => 456,
            'search_volume' => 1000,
            'competition'   => 'HIGH',
            'cpc'           => 2.50,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table($this->googleAdsItemsTable)->insert([
            'keyword'       => 'test keyword 2',
            'location_code' => 2840,
            'language_code' => 'en',
            'se'            => 'google_ads',
            'task_id'       => 'test-task-124',
            'response_id'   => 457,
            'search_volume' => 2000,
            'competition'   => 'MEDIUM',
            'cpc'           => 1.75,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $stats = $this->processor->clearProcessedTables(true);

        // With counting enabled, should return actual count
        $this->assertEquals(2, $stats['items_cleared']);

        // Verify table is empty
        $this->assertEquals(0, DB::table($this->googleAdsItemsTable)->count());
    }

    public function test_clear_processed_tables_with_count_false(): void
    {
        // Insert test data
        DB::table($this->googleAdsItemsTable)->insert([
            'keyword'       => 'test keyword 3',
            'location_code' => 2840,
            'language_code' => 'en',
            'se'            => 'google_ads',
            'task_id'       => 'test-task-125',
            'response_id'   => 458,
            'search_volume' => 1500,
            'competition'   => 'LOW',
            'cpc'           => 0.95,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $stats = $this->processor->clearProcessedTables(false);

        // With counting disabled, should return null
        $this->assertNull($stats['items_cleared']);

        // Verify table is empty
        $this->assertEquals(0, DB::table($this->googleAdsItemsTable)->count());
    }

    public function test_extract_task_data(): void
    {
        $result = $this->processor->extractTaskData([
            'se'            => 'google_ads',
            'location_code' => 2840,
            'language_code' => 'en',
            'extra_field'   => 'ignored',
        ]);

        $expected = [
            'se'            => 'google_ads',
            'location_code' => 2840,
            'language_code' => 'en',
        ];

        $this->assertEquals($expected, $result);
    }

    public function test_extract_task_data_with_missing_fields(): void
    {
        $result = $this->processor->extractTaskData([
            'se' => 'google_ads',
            // missing location_code and language_code
        ]);

        $expected = [
            'se'            => 'google_ads',
            'location_code' => null,
            'language_code' => null,
        ];

        $this->assertEquals($expected, $result);
    }

    public function test_extract_result_metadata(): void
    {
        $result = $this->processor->extractResultMetadata([
            'keyword'       => 'test keyword',
            'location_code' => 2840,
            'language_code' => 'en',
            'extra_field'   => 'ignored',
        ]);

        // Should return empty array as Keywords Data Google Ads doesn't use result metadata
        $expected = [];

        $this->assertEquals($expected, $result);
    }

    public function test_ensure_defaults(): void
    {
        $data = [
            'keyword' => 'test',
        ];

        $result = $this->processor->ensureDefaults($data);

        $expected = [
            'keyword'       => 'test',
            'se'            => null,
            'location_code' => self::WORLDWIDE_LOCATION_CODE,
            'language_code' => self::WORLDWIDE_LANGUAGE_CODE,
        ];

        $this->assertEquals($expected, $result);
    }

    public function test_ensure_defaults_preserves_existing_se(): void
    {
        $data = [
            'keyword' => 'test',
            'se'      => 'custom_se',
        ];

        $result = $this->processor->ensureDefaults($data);

        $expected = [
            'keyword'       => 'test',
            'se'            => 'custom_se',
            'location_code' => self::WORLDWIDE_LOCATION_CODE,
            'language_code' => self::WORLDWIDE_LANGUAGE_CODE,
        ];

        $this->assertEquals($expected, $result);
    }

    public function test_batch_insert_or_update_items(): void
    {
        $now   = now();
        $items = [
            [
                'keyword'       => 'test keyword 1',
                'location_code' => 2840,
                'language_code' => 'en',
                'se'            => 'google_ads',
                'task_id'       => 'task-123',
                'response_id'   => 456,
                'search_volume' => 1000,
                'competition'   => 'HIGH',
                'cpc'           => 2.50,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'keyword'       => 'test keyword 2',
                'location_code' => 2840,
                'language_code' => 'en',
                'se'            => 'google_ads',
                'task_id'       => 'task-123',
                'response_id'   => 456,
                'search_volume' => 500,
                'competition'   => 'LOW',
                'cpc'           => 1.25,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
        ];

        $stats = $this->processor->batchInsertOrUpdateItems($items);

        $this->assertEquals(2, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);

        // Verify items were inserted
        $insertedItems = DB::table($this->googleAdsItemsTable)->orderBy('search_volume', 'desc')->get();
        $this->assertCount(2, $insertedItems);

        $firstItem = $insertedItems[0];
        $this->assertEquals('test keyword 1', $firstItem->keyword);
        $this->assertEquals(1000, $firstItem->search_volume);
        $this->assertEquals('HIGH', $firstItem->competition);
        $this->assertEquals(2.50, $firstItem->cpc);

        $secondItem = $insertedItems[1];
        $this->assertEquals('test keyword 2', $secondItem->keyword);
        $this->assertEquals(500, $secondItem->search_volume);
        $this->assertEquals('LOW', $secondItem->competition);
        $this->assertEquals(1.25, $secondItem->cpc);
    }

    public function test_batch_insert_or_update_items_with_update_if_newer(): void
    {
        $originalTime = now()->subMinutes(10);
        $newerTime    = now();

        // Insert original item
        $originalItem = [
            'keyword'       => 'update test keyword',
            'location_code' => 2840,
            'language_code' => 'en',
            'se'            => 'google_ads',
            'task_id'       => 'task-original',
            'response_id'   => 100,
            'search_volume' => 1000,
            'competition'   => 'HIGH',
            'cpc'           => null,
            'created_at'    => $originalTime,
            'updated_at'    => $originalTime,
        ];

        $stats = $this->processor->batchInsertOrUpdateItems([$originalItem]);
        $this->assertEquals(1, $stats['items_inserted']);

        // Update with newer data
        $newerItem = [
            'keyword'       => 'update test keyword',
            'location_code' => 2840,
            'language_code' => 'en',
            'se'            => 'google_ads',
            'task_id'       => 'task-newer',
            'response_id'   => 200,
            'search_volume' => 1500,
            'competition'   => 'MEDIUM',
            'cpc'           => 3.00,
            'created_at'    => $newerTime,
            'updated_at'    => $newerTime,
        ];

        $stats = $this->processor->batchInsertOrUpdateItems([$newerItem]);
        $this->assertEquals(0, $stats['items_inserted']);
        $this->assertEquals(1, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);

        // Verify item was updated
        $updatedItem = DB::table($this->googleAdsItemsTable)
            ->where('keyword', 'update test keyword')
            ->where('location_code', 2840)
            ->where('language_code', 'en')
            ->first();
        $this->assertEquals('task-newer', $updatedItem->task_id);
        $this->assertEquals(1500, $updatedItem->search_volume);
        $this->assertEquals('MEDIUM', $updatedItem->competition);
        $this->assertEquals(3.00, $updatedItem->cpc);
    }

    public function test_batch_insert_or_update_items_with_null_column_update(): void
    {
        // Insert item with some null values
        $originalItem = [
            'keyword'          => 'null test keyword',
            'location_code'    => 2840,
            'language_code'    => 'en',
            'se'               => 'google_ads',
            'task_id'          => 'task-original',
            'response_id'      => 100,
            'search_volume'    => 1000,
            'competition'      => 'HIGH',
            'cpc'              => null,
            'monthly_searches' => null,
            'created_at'       => now(),
            'updated_at'       => now(),
        ];

        $stats = $this->processor->batchInsertOrUpdateItems([$originalItem]);
        $this->assertEquals(1, $stats['items_inserted']);

        // Update with data for null columns
        $updateItem = [
            'keyword'          => 'null test keyword',
            'location_code'    => 2840,
            'language_code'    => 'en',
            'se'               => 'google_ads',
            'task_id'          => 'task-update',
            'response_id'      => 200,
            'search_volume'    => 1000,
            'competition'      => 'HIGH',
            'cpc'              => 2.50,
            'monthly_searches' => json_encode([['year' => 2023, 'month' => 10, 'search_volume' => 1000]]),
            'created_at'       => now(),
            'updated_at'       => now(),
        ];

        $stats = $this->processor->batchInsertOrUpdateItems([$updateItem]);
        $this->assertEquals(0, $stats['items_inserted']);
        $this->assertEquals(1, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);

        // Verify null columns were updated
        $updatedItem = DB::table($this->googleAdsItemsTable)
            ->where('keyword', 'null test keyword')
            ->where('location_code', 2840)
            ->where('language_code', 'en')
            ->first();
        $this->assertEquals('task-update', $updatedItem->task_id);
        $this->assertEquals(2.50, $updatedItem->cpc);
        $this->assertNotNull($updatedItem->monthly_searches);
    }

    public function test_batch_insert_or_update_items_with_update_if_newer_false(): void
    {
        $this->processor->setUpdateIfNewer(false);

        // Insert original item
        $originalItem = [
            'keyword'       => 'no update test keyword',
            'location_code' => 2840,
            'language_code' => 'en',
            'se'            => 'google_ads',
            'task_id'       => 'task-original',
            'response_id'   => 100,
            'search_volume' => 1000,
            'competition'   => 'HIGH',
            'cpc'           => null,
            'created_at'    => now(),
            'updated_at'    => now(),
        ];

        $stats = $this->processor->batchInsertOrUpdateItems([$originalItem]);
        $this->assertEquals(1, $stats['items_inserted']);

        // Try to update with newer data
        $newerItem = [
            'keyword'       => 'no update test keyword',
            'location_code' => 2840,
            'language_code' => 'en',
            'se'            => 'google_ads',
            'task_id'       => 'task-newer',
            'response_id'   => 200,
            'search_volume' => 1500,
            'competition'   => 'MEDIUM',
            'cpc'           => 3.00,
            'created_at'    => now(),
            'updated_at'    => now(),
        ];

        $stats = $this->processor->batchInsertOrUpdateItems([$newerItem]);
        $this->assertEquals(0, $stats['items_inserted']);
        $this->assertEquals(1, $stats['items_updated']); // Only null columns should be updated
        $this->assertEquals(0, $stats['items_skipped']);

        // Verify only null columns were updated
        $updatedItem = DB::table($this->googleAdsItemsTable)
            ->where('keyword', 'no update test keyword')
            ->where('location_code', 2840)
            ->where('language_code', 'en')
            ->first();
        $this->assertEquals('task-original', $updatedItem->task_id); // Should not be updated
        $this->assertEquals(1000, $updatedItem->search_volume); // Should not be updated
        $this->assertEquals('HIGH', $updatedItem->competition); // Should not be updated
        $this->assertEquals(3.00, $updatedItem->cpc); // Should be updated (was null)
    }

    public function test_batch_insert_or_update_items_with_empty_array(): void
    {
        $stats = $this->processor->batchInsertOrUpdateItems([]);
        $this->assertEquals(0, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);
    }

    public function test_process_google_ads_items_with_monthly_searches_enabled(): void
    {
        $this->processor->setSkipMonthlySearches(false);

        $mergedData = [
            'se'            => 'google_ads',
            'location_code' => 2840,
            'language_code' => 'en',
            'task_id'       => 'task-123',
            'response_id'   => 456,
        ];

        $items = [
            [
                'keyword'          => 'test keyword 1',
                'search_volume'    => 1000,
                'competition'      => 'HIGH',
                'cpc'              => 2.50,
                'monthly_searches' => [
                    ['year' => 2023, 'month' => 10, 'search_volume' => 1000],
                    ['year' => 2023, 'month' => 9, 'search_volume' => 1200],
                ],
            ],
            [
                'keyword'       => 'test keyword 2',
                'search_volume' => 500,
                'competition'   => 'LOW',
                'cpc'           => 1.25,
                // No monthly_searches
            ],
            [
                // No keyword - should be skipped
                'search_volume' => 100,
            ],
        ];

        $result = $this->processor->processGoogleAdsItems($items, $mergedData);

        $this->assertEquals(2, $result['google_ads_items']);
        $this->assertEquals(2, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);

        // Verify items were inserted
        $insertedItems = DB::table($this->googleAdsItemsTable)->orderBy('search_volume', 'desc')->get();
        $this->assertCount(2, $insertedItems);

        $firstItem = $insertedItems[0];
        $this->assertEquals('test keyword 1', $firstItem->keyword);
        $this->assertEquals(1000, $firstItem->search_volume);
        $this->assertEquals('HIGH', $firstItem->competition);
        $this->assertEquals(2.50, $firstItem->cpc);
        $this->assertNotNull($firstItem->monthly_searches);

        // Verify monthly_searches is pretty-printed JSON
        $monthlySearches = json_decode($firstItem->monthly_searches, true);
        $this->assertCount(2, $monthlySearches);
        $this->assertEquals(2023, $monthlySearches[0]['year']);
        $this->assertEquals(10, $monthlySearches[0]['month']);
        $this->assertEquals(1000, $monthlySearches[0]['search_volume']);

        $secondItem = $insertedItems[1];
        $this->assertEquals('test keyword 2', $secondItem->keyword);
        $this->assertEquals(500, $secondItem->search_volume);
        $this->assertNull($secondItem->monthly_searches);
    }

    public function test_process_google_ads_items_with_monthly_searches_disabled(): void
    {
        $this->processor->setSkipMonthlySearches(true);

        $mergedData = [
            'se'            => 'google_ads',
            'location_code' => 2840,
            'language_code' => 'en',
            'task_id'       => 'task-123',
            'response_id'   => 456,
        ];

        $items = [
            [
                'keyword'          => 'test keyword with monthly searches',
                'search_volume'    => 1000,
                'competition'      => 'HIGH',
                'cpc'              => 2.50,
                'monthly_searches' => [
                    ['year' => 2023, 'month' => 10, 'search_volume' => 1000],
                ],
            ],
        ];

        $result = $this->processor->processGoogleAdsItems($items, $mergedData);

        $this->assertEquals(1, $result['google_ads_items']);
        $this->assertEquals(1, $result['items_inserted']);

        // Verify monthly_searches was not processed
        $insertedItem = DB::table($this->googleAdsItemsTable)->first();
        $this->assertEquals('test keyword with monthly searches', $insertedItem->keyword);
        $this->assertNull($insertedItem->monthly_searches);
    }

    public function test_process_google_ads_items_with_empty_array(): void
    {
        $mergedData = ['task_id' => 'test'];
        $result     = $this->processor->processGoogleAdsItems([], $mergedData);

        $this->assertEquals(0, $result['google_ads_items']);
        $this->assertEquals(0, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);
    }

    public function test_process_response(): void
    {
        $responseData = [
            'tasks' => [
                [
                    'id'   => 'test-task-123',
                    'data' => [
                        'se'            => 'google_ads',
                        'location_code' => 2840,
                        'language_code' => 'en',
                    ],
                    'result' => [
                        [
                            'keyword'       => 'test keyword 1',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'search_volume' => 1000,
                            'competition'   => 'HIGH',
                            'cpc'           => 2.50,
                        ],
                        [
                            'keyword'       => 'test keyword 2',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'search_volume' => 500,
                            'competition'   => 'LOW',
                            'cpc'           => 1.25,
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

        $this->assertEquals(2, $stats['google_ads_items']);
        $this->assertEquals(2, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);
        $this->assertEquals(2, $stats['total_items']);

        // Verify items were inserted
        $googleAdsItems = DB::table($this->googleAdsItemsTable)->orderBy('search_volume', 'desc')->get();
        $this->assertCount(2, $googleAdsItems);

        $firstItem = $googleAdsItems[0];
        $this->assertEquals('test keyword 1', $firstItem->keyword);
        $this->assertEquals('test-task-123', $firstItem->task_id);
        $this->assertEquals(123, $firstItem->response_id);
        $this->assertEquals(2840, $firstItem->location_code);
        $this->assertEquals('en', $firstItem->language_code);
        $this->assertEquals('google_ads', $firstItem->se);
        $this->assertEquals(1000, $firstItem->search_volume);
        $this->assertEquals('HIGH', $firstItem->competition);
        $this->assertEquals(2.50, $firstItem->cpc);

        $secondItem = $googleAdsItems[1];
        $this->assertEquals('test keyword 2', $secondItem->keyword);
        $this->assertEquals('test-task-123', $secondItem->task_id);
        $this->assertEquals(123, $secondItem->response_id);
        $this->assertEquals(500, $secondItem->search_volume);
        $this->assertEquals('LOW', $secondItem->competition);
        $this->assertEquals(1.25, $secondItem->cpc);
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

        $this->assertEquals(0, $stats['google_ads_items']);
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

        $this->assertEquals(0, $stats['google_ads_items']);
        $this->assertEquals(0, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);
        $this->assertEquals(0, $stats['total_items']);
    }

    public function test_process_responses_with_no_responses(): void
    {
        $stats = $this->processor->processResponses();

        $this->assertEquals(0, $stats['processed_responses']);
        $this->assertEquals(0, $stats['google_ads_items']);
        $this->assertEquals(0, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);
        $this->assertEquals(0, $stats['total_items']);
        $this->assertEquals(0, $stats['errors']);
    }

    public function test_process_responses_with_valid_keywords_data_google_ads_response(): void
    {
        // Insert test response
        $responseData = [
            'tasks' => [
                [
                    'id'   => 'test-task-123',
                    'data' => [
                        'se'            => 'google_ads',
                        'location_code' => 2840,
                        'language_code' => 'en',
                    ],
                    'result' => [
                        [
                            'keyword'       => 'test keyword',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'search_volume' => 1000,
                            'competition'   => 'HIGH',
                            'cpc'           => 2.50,
                        ],
                    ],
                ],
            ],
        ];

        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'test-key',
            'endpoint'             => 'keywords_data/google_ads/search_volume/live',
            'response_body'        => json_encode($responseData),
            'response_status_code' => 200,
            'base_url'             => 'https://api.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $stats = $this->processor->processResponses();

        $this->assertEquals(1, $stats['processed_responses']);
        $this->assertEquals(1, $stats['google_ads_items']);
        $this->assertEquals(1, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);
        $this->assertEquals(1, $stats['total_items']);
        $this->assertEquals(0, $stats['errors']);

        // Verify google ads item was inserted
        $googleAdsItem = DB::table($this->googleAdsItemsTable)->first();
        $this->assertNotNull($googleAdsItem);
        $this->assertEquals('test keyword', $googleAdsItem->keyword);
        $this->assertEquals('test-task-123', $googleAdsItem->task_id);
        $this->assertEquals(2840, $googleAdsItem->location_code);
        $this->assertEquals('en', $googleAdsItem->language_code);
        $this->assertEquals('google_ads', $googleAdsItem->se);
        $this->assertEquals(1000, $googleAdsItem->search_volume);
        $this->assertEquals('HIGH', $googleAdsItem->competition);
        $this->assertEquals(2.50, $googleAdsItem->cpc);
    }

    public function test_process_responses_skips_sandbox_when_configured(): void
    {
        // Insert sandbox response
        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'sandbox-key',
            'endpoint'             => 'keywords_data/google_ads/search_volume/live',
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
            'endpoint'             => 'keywords_data/google_ads/search_volume/live',
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
            'endpoint'             => 'keywords_data/google_ads/search_volume/live',
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
            'endpoint'             => 'keywords_data/google_ads/search_volume/live',
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
                'key'                  => 'matching-search-volume',
                'endpoint'             => 'keywords_data/google_ads/search_volume/live',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'               => 'dataforseo',
                'key'                  => 'matching-keywords-for-keywords',
                'endpoint'             => 'keywords_data/google_ads/keywords_for_keywords/live',
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
                'endpoint'             => 'keywords_data/google_ads/search_volume/live',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'               => 'dataforseo',
                'key'                  => 'error-response',
                'endpoint'             => 'keywords_data/google_ads/search_volume/live',
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

    public function test_process_responses_all_processes_all_available(): void
    {
        // Insert 5 unprocessed responses
        for ($i = 1; $i <= 5; $i++) {
            DB::table($this->responsesTable)->insert([
                'client'        => 'dataforseo',
                'key'           => "unprocessed-key-{$i}",
                'endpoint'      => 'keywords_data/google_ads/search_volume/live',
                'response_body' => json_encode([
                    'tasks' => [
                        [
                            'id'   => "task-{$i}",
                            'data' => [
                                'se'            => 'google',
                                'location_code' => 2840,
                                'language_code' => 'en',
                            ],
                            'result' => [
                                [
                                    'keyword'       => "keyword {$i}",
                                    'search_volume' => $i * 1000,
                                    'competition'   => 0.5,
                                    'cpc'           => $i * 0.5,
                                ],
                            ],
                        ],
                    ],
                ]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'processed_at'         => null,
                'processed_status'     => null,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
        }

        // Process all with batch size of 2
        $stats = $this->processor->processResponsesAll(2);

        $this->assertEquals(5, $stats['processed_responses']);
        $this->assertEquals(5, $stats['google_ads_items']);
        $this->assertEquals(5, $stats['items_inserted']);
        $this->assertEquals(3, $stats['batches_processed']); // 2 + 2 + 1 = 3 batches
        $this->assertEquals(0, $stats['errors']);

        // Verify all were processed
        $processedCount = DB::table($this->responsesTable)
            ->whereNotNull('processed_at')
            ->count();
        $this->assertEquals(5, $processedCount);
    }

    public function test_process_responses_all_with_no_responses(): void
    {
        // No unprocessed responses
        $stats = $this->processor->processResponsesAll(10);

        $this->assertEquals(0, $stats['processed_responses']);
        $this->assertEquals(0, $stats['batches_processed']);
    }

    public function test_process_responses_all_accumulates_stats(): void
    {
        // Insert 2 responses with different numbers of items
        DB::table($this->responsesTable)->insert([
            [
                'client'        => 'dataforseo',
                'key'           => 'response-1',
                'endpoint'      => 'keywords_data/google_ads/search_volume/live',
                'response_body' => json_encode([
                    'tasks' => [
                        [
                            'id'   => 'task-1',
                            'data' => [
                                'se'            => 'google',
                                'location_code' => 2840,
                                'language_code' => 'en',
                            ],
                            'result' => [
                                [
                                    'keyword'       => 'keyword 1',
                                    'search_volume' => 1000,
                                    'competition'   => 0.5,
                                    'cpc'           => 1.5,
                                ],
                                [
                                    'keyword'       => 'keyword 2',
                                    'search_volume' => 2000,
                                    'competition'   => 0.6,
                                    'cpc'           => 2.0,
                                ],
                            ],
                        ],
                    ],
                ]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'processed_at'         => null,
                'processed_status'     => null,
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'        => 'dataforseo',
                'key'           => 'response-2',
                'endpoint'      => 'keywords_data/google_ads/keywords_for_keywords/live',
                'response_body' => json_encode([
                    'tasks' => [
                        [
                            'id'   => 'task-2',
                            'data' => [
                                'se'            => 'google',
                                'location_code' => 2840,
                                'language_code' => 'en',
                            ],
                            'result' => [
                                [
                                    'keyword'       => 'keyword 3',
                                    'search_volume' => 3000,
                                    'competition'   => 0.7,
                                    'cpc'           => 2.5,
                                ],
                                [
                                    'keyword'       => 'keyword 4',
                                    'search_volume' => 4000,
                                    'competition'   => 0.8,
                                    'cpc'           => 3.0,
                                ],
                                [
                                    'keyword'       => 'keyword 5',
                                    'search_volume' => 5000,
                                    'competition'   => 0.9,
                                    'cpc'           => 3.5,
                                ],
                            ],
                        ],
                    ],
                ]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'processed_at'         => null,
                'processed_status'     => null,
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
        ]);

        $stats = $this->processor->processResponsesAll(10);

        $this->assertEquals(2, $stats['processed_responses']);
        $this->assertEquals(5, $stats['google_ads_items']);
        $this->assertEquals(5, $stats['items_inserted']);
        $this->assertEquals(5, $stats['total_items']); // 2 + 3 = 5 items total
        $this->assertEquals(1, $stats['batches_processed']);
        $this->assertEquals(0, $stats['errors']);

        // Verify all items were inserted
        $this->assertEquals(5, DB::table($this->googleAdsItemsTable)->count());
    }

    public function test_process_responses_all_handles_errors_and_continues(): void
    {
        // Insert one invalid and one valid response
        DB::table($this->responsesTable)->insert([
            [
                'client'               => 'dataforseo',
                'key'                  => 'invalid-response',
                'endpoint'             => 'keywords_data/google_ads/search_volume/live',
                'response_body'        => 'invalid json',
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'processed_at'         => null,
                'processed_status'     => null,
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'        => 'dataforseo',
                'key'           => 'valid-response',
                'endpoint'      => 'keywords_data/google_ads/search_volume/live',
                'response_body' => json_encode([
                    'tasks' => [
                        [
                            'id'   => 'task-valid',
                            'data' => [
                                'se'            => 'google',
                                'location_code' => 2840,
                                'language_code' => 'en',
                            ],
                            'result' => [
                                [
                                    'keyword'       => 'valid keyword',
                                    'search_volume' => 10000,
                                    'competition'   => 0.75,
                                    'cpc'           => 2.5,
                                ],
                            ],
                        ],
                    ],
                ]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'processed_at'         => null,
                'processed_status'     => null,
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
        ]);

        $stats = $this->processor->processResponsesAll(10);

        $this->assertEquals(1, $stats['processed_responses']); // Only valid one processed
        $this->assertEquals(1, $stats['google_ads_items']);
        $this->assertEquals(1, $stats['items_inserted']);
        $this->assertEquals(1, $stats['errors']);

        // Verify valid item was inserted
        $this->assertEquals(1, DB::table($this->googleAdsItemsTable)->count());
        $item = DB::table($this->googleAdsItemsTable)->first();
        $this->assertEquals('valid keyword', $item->keyword);

        // Verify both responses were marked as processed
        $processedCount = DB::table($this->responsesTable)
            ->whereNotNull('processed_at')
            ->count();
        $this->assertEquals(2, $processedCount);
    }
}

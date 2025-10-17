<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\DataForSeoLabsGoogleKeywordResearchProcessor;
use FOfX\ApiCache\ApiCacheManager;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\DB;

class DataForSeoLabsGoogleKeywordResearchProcessorTest extends TestCase
{
    protected DataForSeoLabsGoogleKeywordResearchProcessor $processor;
    protected ApiCacheManager $cacheManager;
    protected string $responsesTable = 'api_cache_dataforseo_responses';
    protected string $itemsTable     = 'dataforseo_labs_google_keyword_research_items';

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheManager = app(ApiCacheManager::class);
        $this->processor    = new DataForSeoLabsGoogleKeywordResearchProcessor($this->cacheManager);
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
        $processor = new DataForSeoLabsGoogleKeywordResearchProcessor($this->cacheManager);
        $this->assertInstanceOf(DataForSeoLabsGoogleKeywordResearchProcessor::class, $processor);
    }

    public function test_constructor_without_cache_manager(): void
    {
        $processor = new DataForSeoLabsGoogleKeywordResearchProcessor();
        $this->assertInstanceOf(DataForSeoLabsGoogleKeywordResearchProcessor::class, $processor);
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

    public function test_set_skip_keyword_info_monthly_searches_changes_value(): void
    {
        $original = $this->processor->getSkipKeywordInfoMonthlySearches();
        $this->processor->setSkipKeywordInfoMonthlySearches(!$original);
        $this->assertEquals(!$original, $this->processor->getSkipKeywordInfoMonthlySearches());
    }

    public function test_set_skip_keyword_info_normalized_with_bing_monthly_searches_changes_value(): void
    {
        $original = $this->processor->getSkipKeywordInfoNormalizedWithBingMonthlySearches();
        $this->processor->setSkipKeywordInfoNormalizedWithBingMonthlySearches(!$original);
        $this->assertEquals(!$original, $this->processor->getSkipKeywordInfoNormalizedWithBingMonthlySearches());
    }

    public function test_set_skip_keyword_info_normalized_with_clickstream_monthly_searches_changes_value(): void
    {
        $original = $this->processor->getSkipKeywordInfoNormalizedWithClickstreamMonthlySearches();
        $this->processor->setSkipKeywordInfoNormalizedWithClickstreamMonthlySearches(!$original);
        $this->assertEquals(!$original, $this->processor->getSkipKeywordInfoNormalizedWithClickstreamMonthlySearches());
    }

    public function test_set_skip_clickstream_keyword_info_monthly_searches_changes_value(): void
    {
        $original = $this->processor->getSkipClickstreamKeywordInfoMonthlySearches();
        $this->processor->setSkipClickstreamKeywordInfoMonthlySearches(!$original);
        $this->assertEquals(!$original, $this->processor->getSkipClickstreamKeywordInfoMonthlySearches());
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
                'endpoint'             => 'dataforseo_labs/google/keyword_overview/live',
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
                'endpoint'             => 'dataforseo_labs/google/bulk_keyword_difficulty/live',
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

        // Verify only Labs Google responses were reset
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
        // Insert test data into items table
        DB::table($this->itemsTable)->insert([
            'keyword'                    => 'test keyword',
            'location_code'              => 2840,
            'language_code'              => 'en',
            'se_type'                    => 'google',
            'keyword_info_search_volume' => 1000,
            'created_at'                 => now(),
            'updated_at'                 => now(),
        ]);

        $result = $this->processor->clearProcessedTables();

        // Verify table was cleared
        $count = DB::table($this->itemsTable)->count();
        $this->assertEquals(0, $count);

        // Verify return structure
        $this->assertArrayHasKey('items_deleted', $result);
        $this->assertNull($result['items_deleted']);
    }

    public function test_clear_processed_tables_with_count(): void
    {
        // Insert test data
        DB::table($this->itemsTable)->insert([
            [
                'keyword'       => 'test keyword 1',
                'location_code' => 2840,
                'language_code' => 'en',
                'se_type'       => 'google',
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            [
                'keyword'       => 'test keyword 2',
                'location_code' => 2840,
                'language_code' => 'en',
                'se_type'       => 'google',
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
        ]);

        $result = $this->processor->clearProcessedTables(true);

        // Verify return structure with count
        $this->assertArrayHasKey('items_deleted', $result);
        $this->assertEquals(2, $result['items_deleted']);

        // Verify table was cleared
        $count = DB::table($this->itemsTable)->count();
        $this->assertEquals(0, $count);
    }

    public function test_ensure_defaults(): void
    {
        $inputData = ['keyword' => 'test', 'search_volume' => 1000];
        $result    = $this->processor->ensureDefaults($inputData);

        // Labs processor doesn't modify data in ensureDefaults
        $this->assertEquals($inputData, $result);
    }

    public function test_extract_task_data_with_all_fields(): void
    {
        $taskData = [
            'se_type'       => 'google',
            'location_code' => 2840,
            'language_code' => 'en',
        ];

        $result = $this->processor->extractTaskData($taskData);

        $this->assertEquals('google', $result['se_type']);
        $this->assertEquals(2840, $result['location_code']);
        $this->assertEquals('en', $result['language_code']);
    }

    public function test_extract_task_data_with_missing_fields(): void
    {
        $taskData = [
            'se_type' => 'google',
            // location_code and language_code missing
        ];

        $result = $this->processor->extractTaskData($taskData);

        $this->assertEquals('google', $result['se_type']);
        $this->assertArrayNotHasKey('location_code', $result);
        $this->assertArrayNotHasKey('language_code', $result);
    }

    public function test_extract_result_metadata(): void
    {
        $result = $this->processor->extractResultMetadata(['some' => 'data']);

        // Labs processor returns empty array for result metadata
        $this->assertEmpty($result);
    }

    public function test_extract_keyword_fields_basic(): void
    {
        $actualItem = [
            'keyword'      => 'test keyword',
            'keyword_info' => [
                'search_volume' => 1000,
                'competition'   => 0.5,
                'cpc'           => 1.25,
            ],
        ];
        $relatedKeywords = ['related1', 'related2'];
        $mergedData      = ['se_type' => 'google'];
        $now             = now();

        $result = $this->processor->extractKeywordFields($actualItem, $relatedKeywords, $mergedData, $now);

        $this->assertEquals('test keyword', $result['keyword']);
        $this->assertEquals('google', $result['se_type']);
        $this->assertEquals(1000, $result['keyword_info_search_volume']);
        $this->assertEquals(0.5, $result['keyword_info_competition']);
        $this->assertEquals(1.25, $result['keyword_info_cpc']);
        $this->assertEquals(json_encode($relatedKeywords, JSON_PRETTY_PRINT), $result['related_keywords']);
    }

    public function test_extract_keyword_fields_with_nested_data(): void
    {
        $actualItem = [
            'keyword'                           => 'nested test',
            'keyword_info_normalized_with_bing' => [
                'search_volume' => 2000,
                'is_normalized' => true,
            ],
            'keyword_info_normalized_with_clickstream' => [
                'search_volume' => 1800,
                'is_normalized' => false,
            ],
            'clickstream_keyword_info' => [
                'search_volume'       => 1500,
                'gender_distribution' => [
                    'female' => 60,
                    'male'   => 40,
                ],
            ],
        ];
        $relatedKeywords = null;
        $mergedData      = [];
        $now             = now();

        $result = $this->processor->extractKeywordFields($actualItem, $relatedKeywords, $mergedData, $now);

        $this->assertEquals('nested test', $result['keyword']);
        $this->assertEquals(2000, $result['keyword_info_normalized_with_bing_search_volume']);
        $this->assertTrue($result['keyword_info_normalized_with_bing_is_normalized']);
        $this->assertEquals(1800, $result['keyword_info_normalized_with_clickstream_search_volume']);
        $this->assertFalse($result['keyword_info_normalized_with_clickstream_is_normalized']);
        $this->assertEquals(1500, $result['clickstream_keyword_info_search_volume']);
        $this->assertEquals(60, $result['clickstream_keyword_info_gender_distribution_female']);
        $this->assertEquals(40, $result['clickstream_keyword_info_gender_distribution_male']);
        $this->assertNull($result['related_keywords']);
    }

    public function test_extract_keyword_fields_with_secondary_keyword_intents(): void
    {
        $actualItem = [
            'keyword'                   => 'intent test',
            'secondary_keyword_intents' => [
                ['label' => 'informational', 'probability' => 0.6],
                ['label' => 'commercial', 'probability' => 0.3],
                ['label' => 'transactional', 'probability' => 0.1],
                ['label' => 'invalid_intent', 'probability' => 0.5], // Should be ignored
            ],
        ];
        $relatedKeywords = null;
        $mergedData      = [];
        $now             = now();

        $result = $this->processor->extractKeywordFields($actualItem, $relatedKeywords, $mergedData, $now);

        $this->assertEquals('intent test', $result['keyword']);
        $this->assertEquals(0.6, $result['secondary_keyword_intents_probability_informational']);
        $this->assertEquals(0.3, $result['secondary_keyword_intents_probability_commercial']);
        $this->assertEquals(0.1, $result['secondary_keyword_intents_probability_transactional']);
        $this->assertNull($result['secondary_keyword_intents_probability_navigational']);

        // Verify that invalid intent types are not included in the result
        $this->assertArrayNotHasKey('secondary_keyword_intents_probability_invalid_intent', $result);
    }

    public function test_extract_keyword_fields_with_empty_nested_objects(): void
    {
        $actualItem = [
            'keyword'                           => 'empty test',
            'keyword_info'                      => [],
            'keyword_info_normalized_with_bing' => null,
            'clickstream_keyword_info'          => [],
        ];
        $relatedKeywords = null;
        $mergedData      = [];
        $now             = now();

        $result = $this->processor->extractKeywordFields($actualItem, $relatedKeywords, $mergedData, $now);

        $this->assertEquals('empty test', $result['keyword']);
        $this->assertNull($result['keyword_info_search_volume']);
        $this->assertNull($result['keyword_info_normalized_with_bing_search_volume']);
        $this->assertNull($result['clickstream_keyword_info_search_volume']);
    }

    public function test_extract_keyword_fields_with_skip_flags_enabled(): void
    {
        $this->processor->setSkipKeywordInfoMonthlySearches(true);
        $this->processor->setSkipKeywordInfoNormalizedWithBingMonthlySearches(true);
        $this->processor->setSkipKeywordInfoNormalizedWithClickstreamMonthlySearches(true);
        $this->processor->setSkipClickstreamKeywordInfoMonthlySearches(true);

        $actualItem = [
            'keyword'      => 'skip test',
            'keyword_info' => [
                'monthly_searches' => [['year' => 2023, 'month' => 1, 'search_volume' => 1000]],
            ],
            'keyword_info_normalized_with_bing' => [
                'monthly_searches' => [['year' => 2023, 'month' => 1, 'search_volume' => 1100]],
            ],
            'keyword_info_normalized_with_clickstream' => [
                'monthly_searches' => [['year' => 2023, 'month' => 1, 'search_volume' => 1200]],
            ],
            'clickstream_keyword_info' => [
                'monthly_searches' => [['year' => 2023, 'month' => 1, 'search_volume' => 1300]],
            ],
        ];
        $relatedKeywords = null;
        $mergedData      = [];
        $now             = now();

        $result = $this->processor->extractKeywordFields($actualItem, $relatedKeywords, $mergedData, $now);

        $this->assertEquals('skip test', $result['keyword']);
        $this->assertNull($result['keyword_info_monthly_searches']);
        $this->assertNull($result['keyword_info_normalized_with_bing_monthly_searches']);
        $this->assertNull($result['keyword_info_normalized_with_clickstream_monthly_searches']);
        $this->assertNull($result['clickstream_keyword_info_monthly_searches']);
    }

    public function test_extract_keyword_fields_with_skip_flags_disabled(): void
    {
        $this->processor->setSkipKeywordInfoMonthlySearches(false);
        $this->processor->setSkipKeywordInfoNormalizedWithBingMonthlySearches(false);
        $this->processor->setSkipKeywordInfoNormalizedWithClickstreamMonthlySearches(false);
        $this->processor->setSkipClickstreamKeywordInfoMonthlySearches(false);

        $monthlySearches                   = [['year' => 2023, 'month' => 1, 'search_volume' => 1000]];
        $bingMonthlySearches               = [['year' => 2023, 'month' => 1, 'search_volume' => 1100]];
        $clickstreamMonthlySearches        = [['year' => 2023, 'month' => 1, 'search_volume' => 1200]];
        $clickstreamKeywordMonthlySearches = [['year' => 2023, 'month' => 1, 'search_volume' => 1300]];

        $actualItem = [
            'keyword'      => 'include test',
            'keyword_info' => [
                'monthly_searches' => $monthlySearches,
            ],
            'keyword_info_normalized_with_bing' => [
                'monthly_searches' => $bingMonthlySearches,
            ],
            'keyword_info_normalized_with_clickstream' => [
                'monthly_searches' => $clickstreamMonthlySearches,
            ],
            'clickstream_keyword_info' => [
                'monthly_searches' => $clickstreamKeywordMonthlySearches,
            ],
        ];
        $relatedKeywords = null;
        $mergedData      = [];
        $now             = now();

        $result = $this->processor->extractKeywordFields($actualItem, $relatedKeywords, $mergedData, $now);

        $this->assertEquals('include test', $result['keyword']);
        $this->assertEquals(json_encode($monthlySearches, JSON_PRETTY_PRINT), $result['keyword_info_monthly_searches']);
        $this->assertEquals(json_encode($bingMonthlySearches, JSON_PRETTY_PRINT), $result['keyword_info_normalized_with_bing_monthly_searches']);
        $this->assertEquals(json_encode($clickstreamMonthlySearches, JSON_PRETTY_PRINT), $result['keyword_info_normalized_with_clickstream_monthly_searches']);
        $this->assertEquals(json_encode($clickstreamKeywordMonthlySearches, JSON_PRETTY_PRINT), $result['clickstream_keyword_info_monthly_searches']);
    }

    public function test_batch_insert_or_update_items_insert(): void
    {
        $items = [
            [
                'keyword'                    => 'new keyword',
                'location_code'              => 2840,
                'language_code'              => 'en',
                'se_type'                    => 'google',
                'keyword_info_search_volume' => 1000,
                'created_at'                 => now(),
                'updated_at'                 => now(),
            ],
        ];

        $result = $this->processor->batchInsertOrUpdateItems($items);

        $this->assertEquals(1, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);

        // Verify item was inserted
        $count = DB::table($this->itemsTable)->where('keyword', 'new keyword')->count();
        $this->assertEquals(1, $count);
    }

    public function test_batch_insert_or_update_items_update(): void
    {
        $originalTime = '2023-01-01 10:00:00';
        $newTime      = '2023-01-01 11:00:00';

        // Insert existing item
        DB::table($this->itemsTable)->insert([
            'keyword'                    => 'existing keyword',
            'location_code'              => 2840,
            'language_code'              => 'en',
            'se_type'                    => 'google',
            'keyword_info_search_volume' => 1000,
            'created_at'                 => $originalTime,
            'updated_at'                 => $originalTime,
        ]);

        // Update with newer data
        $items = [
            [
                'keyword'                    => 'existing keyword',
                'location_code'              => 2840,
                'language_code'              => 'en',
                'se_type'                    => 'google',
                'keyword_info_search_volume' => 2000,
                'created_at'                 => $newTime,
                'updated_at'                 => $newTime,
            ],
        ];

        $result = $this->processor->batchInsertOrUpdateItems($items);

        $this->assertEquals(0, $result['items_inserted']);
        $this->assertEquals(1, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);

        // Verify item was updated
        $item = DB::table($this->itemsTable)->where('keyword', 'existing keyword')->first();
        $this->assertEquals(2000, $item->keyword_info_search_volume);
    }

    public function test_batch_insert_or_update_items_fast_path(): void
    {
        $this->processor->setUpdateIfNewer(false);

        $items = [
            [
                'keyword'                    => 'fast path keyword',
                'location_code'              => 2840,
                'language_code'              => 'en',
                'se_type'                    => 'google',
                'keyword_info_search_volume' => 1000,
                'created_at'                 => now(),
                'updated_at'                 => now(),
            ],
        ];

        // First insert should succeed
        $result = $this->processor->batchInsertOrUpdateItems($items);

        $this->assertEquals(1, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);

        // Verify item was inserted
        $count = DB::table($this->itemsTable)->where('keyword', 'fast path keyword')->count();
        $this->assertEquals(1, $count);

        // Try to insert duplicate with same unique key (keyword + location_code + language_code)
        // Should be ignored by insertOrIgnore, resulting in 0 inserted and 1 skipped
        $result2 = $this->processor->batchInsertOrUpdateItems($items);

        $this->assertEquals(0, $result2['items_inserted']);
        $this->assertEquals(0, $result2['items_updated']);
        $this->assertEquals(1, $result2['items_skipped']);

        // Verify no additional items were inserted
        $count = DB::table($this->itemsTable)->where('keyword', 'fast path keyword')->count();
        $this->assertEquals(1, $count);
    }

    public function test_batch_insert_or_update_items_skip_older_data(): void
    {
        $originalTime = '2023-01-01 11:00:00';
        $olderTime    = '2023-01-01 10:00:00';

        // Insert existing item
        DB::table($this->itemsTable)->insert([
            'keyword'                    => 'newer existing keyword',
            'location_code'              => 2840,
            'language_code'              => 'en',
            'se_type'                    => 'google',
            'keyword_info_search_volume' => 1000,
            'created_at'                 => $originalTime,
            'updated_at'                 => $originalTime,
        ]);

        // Try to update with older data
        $items = [
            [
                'keyword'                    => 'newer existing keyword',
                'location_code'              => 2840,
                'language_code'              => 'en',
                'se_type'                    => 'google',
                'keyword_info_search_volume' => 2000,
                'created_at'                 => $olderTime,
                'updated_at'                 => $olderTime,
            ],
        ];

        $result = $this->processor->batchInsertOrUpdateItems($items);

        $this->assertEquals(0, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(1, $result['items_skipped']);

        // Verify item was not updated
        $item = DB::table($this->itemsTable)->where('keyword', 'newer existing keyword')->first();
        $this->assertEquals(1000, $item->keyword_info_search_volume);
    }

    public function test_batch_insert_or_update_items_large_batches(): void
    {
        $this->processor->setUpdateIfNewer(false);

        // Create 250 items to test chunking (chunks of 100)
        $items = [];
        for ($i = 1; $i <= 250; $i++) {
            $items[] = [
                'keyword'                    => "batch keyword {$i}",
                'location_code'              => 2840,
                'language_code'              => 'en',
                'se_type'                    => 'google',
                'keyword_info_search_volume' => $i * 100,
                'created_at'                 => now(),
                'updated_at'                 => now(),
            ];
        }

        $result = $this->processor->batchInsertOrUpdateItems($items);

        $this->assertEquals(250, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);

        // Verify all items were inserted
        $count = DB::table($this->itemsTable)->where('keyword', 'like', 'batch keyword %')->count();
        $this->assertEquals(250, $count);
    }

    public function test_batch_insert_or_update_items_database_defaults(): void
    {
        // Test that database defaults are applied when location_code and language_code are missing
        $items = [
            [
                'keyword'                    => 'defaults test keyword',
                'se_type'                    => 'google',
                'keyword_info_search_volume' => 1500,
                // Note: location_code and language_code are intentionally missing
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        $result = $this->processor->batchInsertOrUpdateItems($items);

        $this->assertEquals(1, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);

        // Verify item was inserted with database defaults
        $item = DB::table($this->itemsTable)->where('keyword', 'defaults test keyword')->first();
        $this->assertNotNull($item);
        $this->assertEquals('defaults test keyword', $item->keyword);
        $this->assertEquals('google', $item->se_type);
        $this->assertEquals(1500, $item->keyword_info_search_volume);
        // Verify database defaults were applied
        $this->assertEquals(0, $item->location_code);
        $this->assertEquals('none', $item->language_code);
    }

    public function test_process_labs_keyword_items(): void
    {
        $items = [
            [
                'keyword'      => 'process test 1',
                'keyword_info' => ['search_volume' => 1000],
            ],
            [
                'keyword'      => 'process test 2',
                'keyword_info' => ['search_volume' => 2000],
            ],
        ];
        $mergedData = ['se_type' => 'google'];

        $result = $this->processor->processLabsKeywordItems($items, $mergedData);

        $this->assertEquals(2, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);

        // Verify items were processed
        $count = DB::table($this->itemsTable)->whereIn('keyword', ['process test 1', 'process test 2'])->count();
        $this->assertEquals(2, $count);
    }

    public function test_process_labs_keyword_items_with_related_keywords_structure(): void
    {
        $items = [
            [
                'keyword_data' => [
                    'keyword'      => 'related test keyword',
                    'keyword_info' => ['search_volume' => 1500],
                ],
                'related_keywords' => ['related1', 'related2', 'related3'],
            ],
            [
                // Normal structure without keyword_data
                'keyword'      => 'normal test keyword',
                'keyword_info' => ['search_volume' => 2500],
            ],
        ];
        $mergedData = ['se_type' => 'google'];

        $result = $this->processor->processLabsKeywordItems($items, $mergedData);

        $this->assertEquals(2, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);

        // Verify the related keywords item was processed correctly
        $relatedItem = DB::table($this->itemsTable)->where('keyword', 'related test keyword')->first();
        $this->assertNotNull($relatedItem);
        $this->assertEquals(1500, $relatedItem->keyword_info_search_volume);
        $this->assertEquals(json_encode(['related1', 'related2', 'related3'], JSON_PRETTY_PRINT), $relatedItem->related_keywords);

        // Verify the normal item was processed correctly
        $normalItem = DB::table($this->itemsTable)->where('keyword', 'normal test keyword')->first();
        $this->assertNotNull($normalItem);
        $this->assertEquals(2500, $normalItem->keyword_info_search_volume);
        $this->assertNull($normalItem->related_keywords);
    }

    public function test_process_response_valid(): void
    {
        $responseBody = [
            'tasks' => [
                [
                    'id'             => 'test-task-1',
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '2023-01-01 12:00:00 +00:00',
                    'cost'           => 0.001,
                    'result_count'   => 2,
                    'path'           => ['dataforseo_labs', 'google', 'keyword_overview', 'live'],
                    'data'           => [
                        'se_type'       => 'google',
                        'location_code' => 2840,
                        'language_code' => 'en',
                    ],
                    'result' => [
                        [
                            'items' => [
                                [
                                    'keyword'      => 'response test 1',
                                    'keyword_info' => ['search_volume' => 1000],
                                ],
                                [
                                    'keyword'      => 'response test 2',
                                    'keyword_info' => ['search_volume' => 2000],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = (object) [
            'id'            => 1,
            'response_body' => json_encode($responseBody),
        ];

        $result = $this->processor->processResponse($response);

        $this->assertEquals(2, $result['keyword_items']);
        $this->assertEquals(2, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);
    }

    public function test_process_response_invalid_json(): void
    {
        $response = (object) [
            'id'            => 1,
            'response_body' => 'invalid json',
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid JSON response or missing tasks array');

        $this->processor->processResponse($response);
    }

    public function test_process_response_missing_tasks(): void
    {
        $response = (object) [
            'id'            => 1,
            'response_body' => json_encode(['no_tasks' => 'here']),
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid JSON response or missing tasks array');

        $this->processor->processResponse($response);
    }

    public function test_process_response_malformed_task_structure(): void
    {
        $responseBody = [
            'tasks' => [
                [
                    'id' => 'malformed-task',
                    // Missing 'result' key should be handled gracefully
                    'data' => ['se_type' => 'google'],
                ],
                [
                    'id'     => 'task-with-empty-result',
                    'result' => [
                        [
                            // Missing 'items' key should be handled gracefully
                            'other_data' => 'value',
                        ],
                    ],
                ],
            ],
        ];

        $response = (object) [
            'id'            => 1,
            'response_body' => json_encode($responseBody),
        ];

        $result = $this->processor->processResponse($response);

        // Should complete without errors and return zero items processed
        $this->assertEquals(0, $result['keyword_items']);
        $this->assertEquals(0, $result['items_inserted']);
        $this->assertEquals(0, $result['total_items']);
    }

    public function test_process_response_with_various_malformed_task_data(): void
    {
        $responseBody = [
            'tasks' => [
                // Task with malformed but database-compatible data types
                [
                    'id'   => 'task-with-malformed-data',
                    'data' => [
                        'se_type'       => 'invalid-se-type', // Wrong value but right type
                        'location_code' => -999, // Invalid location but right type
                        'language_code' => 'invalid-lang', // Invalid language but right type
                    ],
                    'result' => [
                        [
                            'items' => [
                                ['keyword' => 'malformed data test', 'keyword_info' => ['search_volume' => 1000]],
                            ],
                        ],
                    ],
                ],
                // Task with missing expected fields but valid structure
                [
                    'id'     => 'task-with-missing-fields',
                    'data'   => [], // Empty data array
                    'result' => [
                        [
                            'items' => [
                                // Item with minimal data
                                [
                                    'keyword' => 'minimal test',
                                    // Missing most expected fields
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = (object) [
            'id'            => 1,
            'response_body' => json_encode($responseBody),
        ];

        $result = $this->processor->processResponse($response);

        // Should complete without errors and process what it can
        // Items should be processed even with malformed data
        $this->assertEquals(2, $result['total_items']);
        $this->assertEquals(2, $result['items_inserted']);

        // Verify the items were actually inserted with the malformed data
        $malformedItem = DB::table($this->itemsTable)->where('keyword', 'malformed data test')->first();
        $this->assertNotNull($malformedItem);
        $this->assertEquals('invalid-se-type', $malformedItem->se_type);
        $this->assertEquals(-999, $malformedItem->location_code);
        $this->assertEquals('invalid-lang', $malformedItem->language_code);

        $minimalItem = DB::table($this->itemsTable)->where('keyword', 'minimal test')->first();
        $this->assertNotNull($minimalItem);
        $this->assertNull($minimalItem->se_type);
    }

    public function test_process_responses_with_limit(): void
    {
        // Insert unprocessed responses
        DB::table($this->responsesTable)->insert([
            [
                'client'        => 'dataforseo',
                'key'           => 'unprocessed-key-1',
                'endpoint'      => 'dataforseo_labs/google/keyword_overview/live',
                'response_body' => json_encode([
                    'tasks' => [
                        [
                            'id'          => 'test-task-1',
                            'status_code' => 20000,
                            'data'        => ['se_type' => 'google'],
                            'result'      => [
                                [
                                    'items' => [
                                        ['keyword' => 'bulk test 1', 'keyword_info' => ['search_volume' => 1000]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'        => 'dataforseo',
                'key'           => 'unprocessed-key-2',
                'endpoint'      => 'dataforseo_labs/google/bulk_keyword_difficulty/live',
                'response_body' => json_encode([
                    'tasks' => [
                        [
                            'id'          => 'test-task-2',
                            'status_code' => 20000,
                            'data'        => ['se_type' => 'google'],
                            'result'      => [
                                [
                                    'items' => [
                                        ['keyword' => 'bulk test 2', 'keyword_info' => ['search_volume' => 2000]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
        ]);

        $result = $this->processor->processResponses(1);

        $this->assertEquals(1, $result['processed_responses']);
        $this->assertEquals(1, $result['keyword_items']);
        $this->assertEquals(1, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);
        $this->assertEquals(0, $result['errors']);

        // Verify one response was processed
        $processedCount = DB::table($this->responsesTable)
            ->whereNotNull('processed_at')
            ->count();
        $this->assertEquals(1, $processedCount);
    }

    public function test_process_responses_no_unprocessed(): void
    {
        $result = $this->processor->processResponses();

        $this->assertEquals(0, $result['processed_responses']);
        $this->assertEquals(0, $result['keyword_items']);
        $this->assertEquals(0, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);
        $this->assertEquals(0, $result['errors']);
    }

    public function test_process_responses_with_skip_sandbox_enabled_by_default(): void
    {
        // Verify that skipSandbox is true by default and sandbox responses are skipped

        // Insert sandbox and production responses
        DB::table($this->responsesTable)->insert([
            [
                'client'        => 'dataforseo',
                'key'           => 'sandbox-key-skipped',
                'endpoint'      => 'dataforseo_labs/google/keyword_overview/live',
                'response_body' => json_encode([
                    'tasks' => [
                        [
                            'id'     => 'sandbox-task',
                            'data'   => ['se_type' => 'google'],
                            'result' => [
                                [
                                    'items' => [
                                        ['keyword' => 'sandbox test skipped', 'keyword_info' => ['search_volume' => 100]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]),
                'response_status_code' => 200,
                'base_url'             => 'https://sandbox.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'        => 'dataforseo',
                'key'           => 'production-key-processed',
                'endpoint'      => 'dataforseo_labs/google/keyword_overview/live',
                'response_body' => json_encode([
                    'tasks' => [
                        [
                            'id'     => 'production-task',
                            'data'   => ['se_type' => 'google'],
                            'result' => [
                                [
                                    'items' => [
                                        ['keyword' => 'production test processed', 'keyword_info' => ['search_volume' => 1000]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
        ]);

        // Verify skipSandbox is true by default
        $this->assertTrue($this->processor->getSkipSandbox());

        $result = $this->processor->processResponses();

        // Should only process production responses, skip sandbox
        $this->assertEquals(1, $result['processed_responses']);
        $this->assertEquals(1, $result['keyword_items']);
        $this->assertEquals(1, $result['items_inserted']);

        // Verify only the production item was inserted
        $productionItem = DB::table($this->itemsTable)->where('keyword', 'production test processed')->first();
        $sandboxItem    = DB::table($this->itemsTable)->where('keyword', 'sandbox test skipped')->first();
        $this->assertNotNull($productionItem);
        $this->assertNull($sandboxItem);

        // Verify sandbox response was not processed
        $sandboxResponse = DB::table($this->responsesTable)->where('key', 'sandbox-key-skipped')->first();
        $this->assertNull($sandboxResponse->processed_at);
    }

    public function test_process_responses_with_skip_sandbox_disabled(): void
    {
        $this->processor->setSkipSandbox(false);

        // Insert sandbox and production responses
        DB::table($this->responsesTable)->insert([
            [
                'client'        => 'dataforseo',
                'key'           => 'sandbox-key',
                'endpoint'      => 'dataforseo_labs/google/keyword_overview/live',
                'response_body' => json_encode([
                    'tasks' => [
                        [
                            'id'     => 'sandbox-task',
                            'data'   => ['se_type' => 'google'],
                            'result' => [
                                [
                                    'items' => [
                                        ['keyword' => 'sandbox test', 'keyword_info' => ['search_volume' => 100]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]),
                'response_status_code' => 200,
                'base_url'             => 'https://sandbox.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'        => 'dataforseo',
                'key'           => 'production-key',
                'endpoint'      => 'dataforseo_labs/google/keyword_overview/live',
                'response_body' => json_encode([
                    'tasks' => [
                        [
                            'id'     => 'production-task',
                            'data'   => ['se_type' => 'google'],
                            'result' => [
                                [
                                    'items' => [
                                        ['keyword' => 'production test', 'keyword_info' => ['search_volume' => 1000]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
        ]);

        $result = $this->processor->processResponses();

        // Should process both sandbox and production responses
        $this->assertEquals(2, $result['processed_responses']);
        $this->assertEquals(2, $result['keyword_items']);
        $this->assertEquals(2, $result['items_inserted']);

        // Verify both items were inserted
        $sandboxItem    = DB::table($this->itemsTable)->where('keyword', 'sandbox test')->first();
        $productionItem = DB::table($this->itemsTable)->where('keyword', 'production test')->first();
        $this->assertNotNull($sandboxItem);
        $this->assertNotNull($productionItem);
    }

    public function test_process_responses_all_processes_all_available(): void
    {
        // Insert 5 unprocessed responses
        for ($i = 1; $i <= 5; $i++) {
            DB::table($this->responsesTable)->insert([
                'client'        => 'dataforseo',
                'key'           => "unprocessed-key-{$i}",
                'endpoint'      => 'dataforseo_labs/google/keyword_overview/live',
                'response_body' => json_encode([
                    'tasks' => [
                        [
                            'id'   => "task-{$i}",
                            'data' => [
                                'se_type'       => 'google',
                                'location_code' => 2840,
                                'language_code' => 'en',
                            ],
                            'result' => [
                                [
                                    'items' => [
                                        [
                                            'keyword'      => "keyword {$i}",
                                            'keyword_info' => [
                                                'search_volume' => $i * 1000,
                                                'competition'   => 0.5,
                                            ],
                                        ],
                                    ],
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
        $this->assertEquals(5, $stats['keyword_items']);
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
                'endpoint'      => 'dataforseo_labs/google/keyword_overview/live',
                'response_body' => json_encode([
                    'tasks' => [
                        [
                            'id'   => 'task-1',
                            'data' => [
                                'se_type'       => 'google',
                                'location_code' => 2840,
                                'language_code' => 'en',
                            ],
                            'result' => [
                                [
                                    'items' => [
                                        [
                                            'keyword'      => 'keyword 1',
                                            'keyword_info' => [
                                                'search_volume' => 1000,
                                                'competition'   => 0.5,
                                            ],
                                        ],
                                        [
                                            'keyword'      => 'keyword 2',
                                            'keyword_info' => [
                                                'search_volume' => 2000,
                                                'competition'   => 0.6,
                                            ],
                                        ],
                                    ],
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
                'endpoint'      => 'dataforseo_labs/google/related_keywords/live',
                'response_body' => json_encode([
                    'tasks' => [
                        [
                            'id'   => 'task-2',
                            'data' => [
                                'se_type'       => 'google',
                                'location_code' => 2840,
                                'language_code' => 'en',
                            ],
                            'result' => [
                                [
                                    'items' => [
                                        [
                                            'keyword'      => 'keyword 3',
                                            'keyword_info' => [
                                                'search_volume' => 3000,
                                                'competition'   => 0.7,
                                            ],
                                        ],
                                        [
                                            'keyword'      => 'keyword 4',
                                            'keyword_info' => [
                                                'search_volume' => 4000,
                                                'competition'   => 0.8,
                                            ],
                                        ],
                                        [
                                            'keyword'      => 'keyword 5',
                                            'keyword_info' => [
                                                'search_volume' => 5000,
                                                'competition'   => 0.9,
                                            ],
                                        ],
                                    ],
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
        $this->assertEquals(5, $stats['keyword_items']);
        $this->assertEquals(5, $stats['items_inserted']);
        $this->assertEquals(5, $stats['total_items']); // 2 + 3 = 5 items total
        $this->assertEquals(1, $stats['batches_processed']);
        $this->assertEquals(0, $stats['errors']);

        // Verify all items were inserted
        $this->assertEquals(5, DB::table($this->itemsTable)->count());
    }

    public function test_process_responses_all_handles_errors_and_continues(): void
    {
        // Insert one invalid and one valid response
        DB::table($this->responsesTable)->insert([
            [
                'client'               => 'dataforseo',
                'key'                  => 'invalid-response',
                'endpoint'             => 'dataforseo_labs/google/keyword_overview/live',
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
                'endpoint'      => 'dataforseo_labs/google/keyword_overview/live',
                'response_body' => json_encode([
                    'tasks' => [
                        [
                            'id'   => 'task-valid',
                            'data' => [
                                'se_type'       => 'google',
                                'location_code' => 2840,
                                'language_code' => 'en',
                            ],
                            'result' => [
                                [
                                    'items' => [
                                        [
                                            'keyword'      => 'valid keyword',
                                            'keyword_info' => [
                                                'search_volume' => 10000,
                                                'competition'   => 0.75,
                                            ],
                                        ],
                                    ],
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
        $this->assertEquals(1, $stats['keyword_items']);
        $this->assertEquals(1, $stats['items_inserted']);
        $this->assertEquals(1, $stats['errors']);

        // Verify valid item was inserted
        $this->assertEquals(1, DB::table($this->itemsTable)->count());
        $item = DB::table($this->itemsTable)->first();
        $this->assertEquals('valid keyword', $item->keyword);

        // Verify both responses were marked as processed
        $processedCount = DB::table($this->responsesTable)
            ->whereNotNull('processed_at')
            ->count();
        $this->assertEquals(2, $processedCount);
    }
}

<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\DataForSeoSerpGoogleOrganicProcessor;
use FOfX\ApiCache\ApiCacheManager;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\DB;

class DataForSeoSerpGoogleOrganicProcessorTest extends TestCase
{
    protected DataForSeoSerpGoogleOrganicProcessor $processor;
    protected ApiCacheManager $cacheManager;
    protected string $responsesTable    = 'api_cache_dataforseo_responses';
    protected string $listingsTable     = 'dataforseo_serp_google_organic_listings';
    protected string $organicItemsTable = 'dataforseo_serp_google_organic_items';
    protected string $paaItemsTable     = 'dataforseo_serp_google_organic_paa_items';

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheManager = app(ApiCacheManager::class);
        $this->processor    = new DataForSeoSerpGoogleOrganicProcessor($this->cacheManager);
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
        $processor = new DataForSeoSerpGoogleOrganicProcessor($this->cacheManager);
        $this->assertInstanceOf(DataForSeoSerpGoogleOrganicProcessor::class, $processor);
    }

    public function test_constructor_without_cache_manager(): void
    {
        $processor = new DataForSeoSerpGoogleOrganicProcessor();
        $this->assertInstanceOf(DataForSeoSerpGoogleOrganicProcessor::class, $processor);
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

    public function test_set_skip_refinement_chips_changes_value(): void
    {
        $original = $this->processor->getSkipRefinementChips();
        $this->processor->setSkipRefinementChips(!$original);
        $this->assertEquals(!$original, $this->processor->getSkipRefinementChips());
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
                'endpoint'             => 'serp/google/organic/task_get/advanced',
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
                'endpoint'             => 'serp/google/organic/live/advanced',
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

        // Verify only SERP Google responses were reset
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
        // Insert test data into all tables
        DB::table($this->listingsTable)->insert([
            'keyword'         => 'test',
            'se_domain'       => 'google.com',
            'location_code'   => 2840,
            'language_code'   => 'en',
            'device'          => 'desktop',
            'se'              => 'google',
            'se_type'         => 'organic',
            'result_keyword'  => 'test',
            'check_url'       => 'https://www.google.com/search?q=test',
            'result_datetime' => '2023-01-01 12:00:00',
            'item_types'      => '[]',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table($this->organicItemsTable)->insert([
            'keyword'       => 'test',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'rank_absolute' => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table($this->paaItemsTable)->insert([
            'keyword'       => 'test',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'item_position' => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $stats = $this->processor->clearProcessedTables();

        // Default behavior returns null
        $this->assertNull($stats['listings_cleared']);
        $this->assertNull($stats['organic_cleared']);
        $this->assertNull($stats['paa_cleared']);

        // Verify tables are empty
        $this->assertEquals(0, DB::table($this->listingsTable)->count());
        $this->assertEquals(0, DB::table($this->organicItemsTable)->count());
        $this->assertEquals(0, DB::table($this->paaItemsTable)->count());
    }

    public function test_clear_processed_tables_exclude_paa(): void
    {
        // Insert test data into all tables
        DB::table($this->listingsTable)->insert([
            'keyword'         => 'test',
            'se_domain'       => 'google.com',
            'location_code'   => 2840,
            'language_code'   => 'en',
            'device'          => 'desktop',
            'se'              => 'google',
            'se_type'         => 'organic',
            'result_keyword'  => 'test',
            'check_url'       => 'https://www.google.com/search?q=test',
            'result_datetime' => '2023-01-01 12:00:00',
            'item_types'      => '[]',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table($this->organicItemsTable)->insert([
            'keyword'       => 'test',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'rank_absolute' => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table($this->paaItemsTable)->insert([
            'keyword'       => 'test',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'item_position' => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $stats = $this->processor->clearProcessedTables(false);

        // Default behavior returns null
        $this->assertNull($stats['listings_cleared']);
        $this->assertNull($stats['organic_cleared']);
        $this->assertNull($stats['paa_cleared']);

        // Verify listings and organic tables are empty, PAA table is not
        $this->assertEquals(0, DB::table($this->listingsTable)->count());
        $this->assertEquals(0, DB::table($this->organicItemsTable)->count());
        $this->assertEquals(1, DB::table($this->paaItemsTable)->count());
    }

    public function test_clear_processed_tables_with_count_true(): void
    {
        // Insert test data into all tables
        DB::table($this->listingsTable)->insert([
            'keyword'         => 'test1',
            'se_domain'       => 'google.com',
            'location_code'   => 2840,
            'language_code'   => 'en',
            'device'          => 'desktop',
            'se'              => 'google',
            'se_type'         => 'organic',
            'result_keyword'  => 'test1',
            'check_url'       => 'https://www.google.com/search?q=test1',
            'result_datetime' => '2023-01-01 12:00:00',
            'item_types'      => '[]',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table($this->organicItemsTable)->insert([
            'keyword'       => 'test1',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'rank_absolute' => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table($this->organicItemsTable)->insert([
            'keyword'       => 'test2',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'rank_absolute' => 2,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table($this->paaItemsTable)->insert([
            'keyword'       => 'test1',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'item_position' => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $stats = $this->processor->clearProcessedTables(true, true);

        // With counting enabled, should return actual counts
        $this->assertEquals(1, $stats['listings_cleared']);
        $this->assertEquals(2, $stats['organic_cleared']);
        $this->assertEquals(1, $stats['paa_cleared']);

        // Verify tables are empty
        $this->assertEquals(0, DB::table($this->listingsTable)->count());
        $this->assertEquals(0, DB::table($this->organicItemsTable)->count());
        $this->assertEquals(0, DB::table($this->paaItemsTable)->count());
    }

    public function test_clear_processed_tables_with_count_false(): void
    {
        // Insert test data into both tables
        DB::table($this->organicItemsTable)->insert([
            'keyword'       => 'test3',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'rank_absolute' => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table($this->paaItemsTable)->insert([
            'keyword'       => 'test3',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'item_position' => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $stats = $this->processor->clearProcessedTables(true, false);

        // With counting disabled, should return null
        $this->assertNull($stats['listings_cleared']);
        $this->assertNull($stats['organic_cleared']);
        $this->assertNull($stats['paa_cleared']);

        // Verify tables are empty
        $this->assertEquals(0, DB::table($this->listingsTable)->count());
        $this->assertEquals(0, DB::table($this->organicItemsTable)->count());
        $this->assertEquals(0, DB::table($this->paaItemsTable)->count());
    }

    public function test_clear_processed_tables_exclude_paa_with_count(): void
    {
        // Insert test data into all tables
        DB::table($this->listingsTable)->insert([
            'keyword'         => 'test4',
            'se_domain'       => 'google.com',
            'location_code'   => 2840,
            'language_code'   => 'en',
            'device'          => 'desktop',
            'se'              => 'google',
            'se_type'         => 'organic',
            'result_keyword'  => 'test4',
            'check_url'       => 'https://www.google.com/search?q=test4',
            'result_datetime' => '2023-01-01 12:00:00',
            'item_types'      => '[]',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table($this->organicItemsTable)->insert([
            'keyword'       => 'test4',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'rank_absolute' => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table($this->paaItemsTable)->insert([
            'keyword'       => 'test4',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'item_position' => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $stats = $this->processor->clearProcessedTables(false, true);

        // Should count listings and organic but not PAA (since PAA is excluded)
        $this->assertEquals(1, $stats['listings_cleared']);
        $this->assertEquals(1, $stats['organic_cleared']);
        $this->assertNull($stats['paa_cleared']);

        // Verify listings and organic tables are empty, PAA table is not
        $this->assertEquals(0, DB::table($this->listingsTable)->count());
        $this->assertEquals(0, DB::table($this->organicItemsTable)->count());
        $this->assertEquals(1, DB::table($this->paaItemsTable)->count());
    }

    /**
     * Data provider for extractMetadata test
     */
    public static function extractMetadataDataProvider(): array
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
                    'keyword'       => 'test keyword',
                    'se_domain'     => 'google.com',
                    'location_code' => 2840,
                    'language_code' => 'en',
                    'device'        => 'desktop',
                    'os'            => 'windows',
                ],
            ],
            'partial_data' => [
                [
                    'keyword'   => 'partial keyword',
                    'se_domain' => 'google.co.uk',
                ],
                [
                    'keyword'       => 'partial keyword',
                    'se_domain'     => 'google.co.uk',
                    'location_code' => null,
                    'language_code' => null,
                    'device'        => null,
                    'os'            => null,
                ],
            ],
            'empty_data' => [
                [],
                [
                    'keyword'       => null,
                    'se_domain'     => null,
                    'location_code' => null,
                    'language_code' => null,
                    'device'        => null,
                    'os'            => null,
                ],
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('extractMetadataDataProvider')]
    public function test_extract_metadata(array $input, array $expected): void
    {
        $result = $this->processor->extractMetadata($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Data provider for extractListingsTaskMetadata test
     */
    public static function extractListingsTaskMetadataDataProvider(): array
    {
        return [
            'complete_data' => [
                [
                    'se'          => 'google',
                    'se_type'     => 'organic',
                    'tag'         => 'test-tag',
                    'keyword'     => 'ignored',
                    'extra_field' => 'ignored',
                ],
                [
                    'se'      => 'google',
                    'se_type' => 'organic',
                    'tag'     => 'test-tag',
                ],
            ],
            'partial_data' => [
                [
                    'se' => 'google',
                ],
                [
                    'se'      => 'google',
                    'se_type' => null,
                    'tag'     => null,
                ],
            ],
            'empty_data' => [
                [],
                [
                    'se'      => null,
                    'se_type' => null,
                    'tag'     => null,
                ],
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('extractListingsTaskMetadataDataProvider')]
    public function test_extract_listings_task_metadata(array $input, array $expected): void
    {
        $result = $this->processor->extractListingsTaskMetadata($input);
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

    public function test_batch_insert_or_update_organic_listings(): void
    {
        $now           = now();
        $listingsItems = [
            [
                'keyword'         => 'test listings keyword 1',
                'location_code'   => 2840,
                'language_code'   => 'en',
                'device'          => 'desktop',
                'se_domain'       => 'google.com',
                'task_id'         => 'task-listings-123',
                'response_id'     => 456,
                'se'              => 'google',
                'se_type'         => 'organic',
                'tag'             => 'test-tag',
                'result_keyword'  => 'test listings keyword 1',
                'type'            => 'organic',
                'check_url'       => 'https://www.google.com/search?q=test',
                'result_datetime' => '2023-01-01 12:00:00',
                'item_types'      => '["organic"]',
                'created_at'      => $now,
                'updated_at'      => $now,
            ],
            [
                'keyword'         => 'test listings keyword 2',
                'location_code'   => 2840,
                'language_code'   => 'en',
                'device'          => 'desktop',
                'se_domain'       => 'google.com',
                'task_id'         => 'task-listings-123',
                'response_id'     => 456,
                'se'              => 'google',
                'se_type'         => 'organic',
                'tag'             => 'test-tag',
                'result_keyword'  => 'test listings keyword 2',
                'type'            => 'organic',
                'check_url'       => 'https://www.google.com/search?q=test2',
                'result_datetime' => '2023-01-01 12:00:00',
                'item_types'      => '["organic"]',
                'created_at'      => $now,
                'updated_at'      => $now,
            ],
        ];

        $stats = $this->processor->batchInsertOrUpdateOrganicListings($listingsItems);

        $this->assertEquals(2, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);

        // Verify items were inserted
        $insertedItems = DB::table($this->listingsTable)->orderBy('keyword')->get();
        $this->assertCount(2, $insertedItems);

        $firstItem = $insertedItems[0];
        $this->assertEquals('test listings keyword 1', $firstItem->keyword);
        $this->assertEquals('google', $firstItem->se);
        $this->assertEquals('organic', $firstItem->se_type);
        $this->assertEquals('test-tag', $firstItem->tag);

        $secondItem = $insertedItems[1];
        $this->assertEquals('test listings keyword 2', $secondItem->keyword);
        $this->assertEquals('google', $secondItem->se);
        $this->assertEquals('organic', $secondItem->se_type);
        $this->assertEquals('test-tag', $secondItem->tag);
    }

    public function test_batch_insert_or_update_organic_listings_with_duplicates(): void
    {
        $originalTime = now()->subMinutes(10);
        $newerTime    = now();

        $originalItem = [
            'keyword'         => 'duplicate listings keyword',
            'location_code'   => 2840,
            'language_code'   => 'en',
            'device'          => 'desktop',
            'se_domain'       => 'google.com',
            'task_id'         => 'task-listings-original',
            'response_id'     => 100,
            'se'              => 'google',
            'se_type'         => 'organic',
            'tag'             => 'original-tag',
            'result_keyword'  => 'duplicate listings keyword',
            'type'            => 'organic',
            'check_url'       => 'https://www.google.com/search?q=original',
            'result_datetime' => '2023-01-01 12:00:00',
            'item_types'      => '["organic"]',
            'created_at'      => $originalTime,
            'updated_at'      => $originalTime,
        ];

        // Insert original item
        $stats = $this->processor->batchInsertOrUpdateOrganicListings([$originalItem]);
        $this->assertEquals(1, $stats['items_inserted']);

        // Verify original was inserted
        $this->assertEquals(1, DB::table($this->listingsTable)->count());
        $original = DB::table($this->listingsTable)->first();
        $this->assertEquals('original-tag', $original->tag);
        $this->assertEquals('https://www.google.com/search?q=original', $original->check_url);

        // Insert updated item with same unique constraints but newer timestamp
        $updatedItem = [
            'keyword'         => 'duplicate listings keyword',
            'location_code'   => 2840,
            'language_code'   => 'en',
            'device'          => 'desktop', // Same unique constraint
            'se_domain'       => 'google.com',
            'task_id'         => 'task-listings-updated',
            'response_id'     => 200,
            'se'              => 'google',
            'se_type'         => 'organic',
            'tag'             => 'updated-tag',
            'result_keyword'  => 'duplicate listings keyword',
            'type'            => 'organic',
            'check_url'       => 'https://www.google.com/search?q=updated',
            'result_datetime' => '2023-01-01 12:00:00',
            'item_types'      => '["organic"]',
            'created_at'      => $newerTime,
            'updated_at'      => $newerTime,
        ];

        $stats = $this->processor->batchInsertOrUpdateOrganicListings([$updatedItem]);
        $this->assertEquals(0, $stats['items_inserted']);
        $this->assertEquals(1, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);

        // Verify item was updated, not duplicated
        $this->assertEquals(1, DB::table($this->listingsTable)->count());
        $updated = DB::table($this->listingsTable)->first();
        $this->assertEquals('updated-tag', $updated->tag);
        $this->assertEquals('https://www.google.com/search?q=updated', $updated->check_url);
        $this->assertEquals('task-listings-updated', $updated->task_id);
    }

    public function test_batch_insert_or_update_organic_listings_with_empty_array(): void
    {
        // Test with empty array - should not cause errors
        $stats = $this->processor->batchInsertOrUpdateOrganicListings([]);
        $this->assertEquals(0, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);
        $this->assertEquals(0, DB::table($this->listingsTable)->count());
    }

    public function test_batch_insert_or_update_organic_listings_with_update_if_newer_false(): void
    {
        // Set updateIfNewer to false
        $this->processor->setUpdateIfNewer(false);

        $now          = now();
        $originalItem = [
            'keyword'         => 'test listings keyword',
            'location_code'   => 2840,
            'language_code'   => 'en',
            'device'          => 'desktop',
            'se_domain'       => 'google.com',
            'task_id'         => 'task-listings-original',
            'response_id'     => 100,
            'se'              => 'google',
            'se_type'         => 'organic',
            'tag'             => 'original-tag',
            'result_keyword'  => 'test listings keyword',
            'type'            => 'organic',
            'check_url'       => 'https://www.google.com/search?q=original',
            'result_datetime' => '2023-01-01 12:00:00',
            'item_types'      => '["organic"]',
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        // Insert original item
        $stats = $this->processor->batchInsertOrUpdateOrganicListings([$originalItem]);
        $this->assertEquals(1, $stats['items_inserted']);
        $this->assertEquals(1, DB::table($this->listingsTable)->count());

        // Try to insert duplicate - should be ignored
        $duplicateItem = [
            'keyword'         => 'test listings keyword',
            'location_code'   => 2840,
            'language_code'   => 'en',
            'device'          => 'desktop', // Same unique constraint
            'se_domain'       => 'google.com',
            'task_id'         => 'task-listings-duplicate',
            'response_id'     => 200,
            'se'              => 'google',
            'se_type'         => 'organic',
            'tag'             => 'duplicate-tag',
            'result_keyword'  => 'test listings keyword',
            'type'            => 'organic',
            'check_url'       => 'https://www.google.com/search?q=duplicate',
            'result_datetime' => '2023-01-01 12:00:00',
            'item_types'      => '["organic"]',
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        $stats = $this->processor->batchInsertOrUpdateOrganicListings([$duplicateItem]);
        $this->assertEquals(0, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(1, $stats['items_skipped']);

        // Should still have only 1 record with original data
        $this->assertEquals(1, DB::table($this->listingsTable)->count());
        $record = DB::table($this->listingsTable)->first();
        $this->assertEquals('original-tag', $record->tag);
        $this->assertEquals('https://www.google.com/search?q=original', $record->check_url);
        $this->assertEquals('task-listings-original', $record->task_id);
    }

    public function test_batch_insert_or_update_organic_items(): void
    {
        $now          = now();
        $organicItems = [
            [
                'keyword'             => 'test keyword 1',
                'location_code'       => 2840,
                'language_code'       => 'en',
                'device'              => 'desktop',
                'rank_absolute'       => 1,
                'se_domain'           => 'google.com',
                'task_id'             => 'task-123',
                'response_id'         => 456,
                'items_type'          => 'organic',
                'rank_group'          => 1,
                'domain'              => 'example.com',
                'title'               => 'Test Title 1',
                'description'         => 'Test Description 1',
                'url'                 => 'https://example.com/test1',
                'is_featured_snippet' => false,
                'created_at'          => $now,
                'updated_at'          => $now,
            ],
            [
                'keyword'             => 'test keyword 2',
                'location_code'       => 2840,
                'language_code'       => 'en',
                'device'              => 'desktop',
                'rank_absolute'       => 2,
                'se_domain'           => 'google.com',
                'task_id'             => 'task-123',
                'response_id'         => 456,
                'items_type'          => 'organic',
                'rank_group'          => 2,
                'domain'              => 'example2.com',
                'title'               => 'Test Title 2',
                'description'         => 'Test Description 2',
                'url'                 => 'https://example2.com/test2',
                'is_featured_snippet' => true,
                'created_at'          => $now,
                'updated_at'          => $now,
            ],
        ];

        $this->processor->batchInsertOrUpdateOrganicItems($organicItems);

        // Verify items were inserted
        $insertedItems = DB::table($this->organicItemsTable)->orderBy('rank_absolute')->get();
        $this->assertCount(2, $insertedItems);

        $firstItem = $insertedItems[0];
        $this->assertEquals('test keyword 1', $firstItem->keyword);
        $this->assertEquals(1, $firstItem->rank_absolute);
        $this->assertEquals('example.com', $firstItem->domain);
        $this->assertEquals('Test Title 1', $firstItem->title);

        $secondItem = $insertedItems[1];
        $this->assertEquals('test keyword 2', $secondItem->keyword);
        $this->assertEquals(2, $secondItem->rank_absolute);
        $this->assertEquals('example2.com', $secondItem->domain);
        $this->assertEquals('Test Title 2', $secondItem->title);
    }

    public function test_batch_insert_or_update_organic_items_with_duplicates(): void
    {
        $originalTime = now()->subMinutes(10);
        $newerTime    = now();

        $originalItem = [
            'keyword'       => 'duplicate keyword',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'rank_absolute' => 1,
            'se_domain'     => 'google.com',
            'task_id'       => 'task-original',
            'response_id'   => 100,
            'items_type'    => 'organic',
            'domain'        => 'original.com',
            'title'         => 'Original Title',
            'created_at'    => $originalTime,
            'updated_at'    => $originalTime,
        ];

        // Insert original item
        $this->processor->batchInsertOrUpdateOrganicItems([$originalItem]);

        // Verify original was inserted
        $this->assertEquals(1, DB::table($this->organicItemsTable)->count());
        $original = DB::table($this->organicItemsTable)->first();
        $this->assertEquals('Original Title', $original->title);
        $this->assertEquals('original.com', $original->domain);

        // Insert updated item with same unique constraints but newer timestamp
        $updatedItem = [
            'keyword'       => 'duplicate keyword',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'rank_absolute' => 1, // Same unique constraint
            'se_domain'     => 'google.com',
            'task_id'       => 'task-updated',
            'response_id'   => 200,
            'items_type'    => 'organic',
            'domain'        => 'updated.com',
            'title'         => 'Updated Title',
            'created_at'    => $newerTime,
            'updated_at'    => $newerTime,
        ];

        $this->processor->batchInsertOrUpdateOrganicItems([$updatedItem]);

        // Verify item was updated, not duplicated
        $this->assertEquals(1, DB::table($this->organicItemsTable)->count());
        $updated = DB::table($this->organicItemsTable)->first();
        $this->assertEquals('Updated Title', $updated->title);
        $this->assertEquals('updated.com', $updated->domain);
        $this->assertEquals('task-updated', $updated->task_id);
    }

    public function test_batch_insert_or_update_organic_items_with_empty_array(): void
    {
        // Test with empty array - should not cause errors
        $this->processor->batchInsertOrUpdateOrganicItems([]);
        $this->assertEquals(0, DB::table($this->organicItemsTable)->count());
    }

    public function test_batch_insert_or_update_organic_items_with_update_if_newer_false(): void
    {
        // Set updateIfNewer to false
        $this->processor->setUpdateIfNewer(false);

        $now          = now();
        $originalItem = [
            'keyword'       => 'test keyword',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'rank_absolute' => 1,
            'se_domain'     => 'google.com',
            'task_id'       => 'task-original',
            'response_id'   => 100,
            'items_type'    => 'organic',
            'domain'        => 'original.com',
            'title'         => 'Original Title',
            'created_at'    => $now,
            'updated_at'    => $now,
        ];

        // Insert original item
        $this->processor->batchInsertOrUpdateOrganicItems([$originalItem]);
        $this->assertEquals(1, DB::table($this->organicItemsTable)->count());

        // Try to insert duplicate - should be ignored
        $duplicateItem = [
            'keyword'       => 'test keyword',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'rank_absolute' => 1, // Same unique constraint
            'se_domain'     => 'google.com',
            'task_id'       => 'task-duplicate',
            'response_id'   => 200,
            'items_type'    => 'organic',
            'domain'        => 'duplicate.com',
            'title'         => 'Duplicate Title',
            'created_at'    => $now,
            'updated_at'    => $now,
        ];

        $this->processor->batchInsertOrUpdateOrganicItems([$duplicateItem]);

        // Should still have only 1 record with original data
        $this->assertEquals(1, DB::table($this->organicItemsTable)->count());
        $record = DB::table($this->organicItemsTable)->first();
        $this->assertEquals('Original Title', $record->title);
        $this->assertEquals('original.com', $record->domain);
        $this->assertEquals('task-original', $record->task_id);
    }

    public function test_batch_insert_or_update_organic_items_with_older_timestamp(): void
    {
        $newerTime = now();
        $olderTime = now()->subMinutes(10);

        // Insert newer item first
        $newerItem = [
            'keyword'       => 'timestamp test',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'rank_absolute' => 1,
            'se_domain'     => 'google.com',
            'task_id'       => 'task-newer',
            'response_id'   => 100,
            'items_type'    => 'organic',
            'domain'        => 'newer.com',
            'title'         => 'Newer Title',
            'created_at'    => $newerTime,
            'updated_at'    => $newerTime,
        ];

        $this->processor->batchInsertOrUpdateOrganicItems([$newerItem]);
        $this->assertEquals(1, DB::table($this->organicItemsTable)->count());

        // Try to insert older item - should NOT update
        $olderItem = [
            'keyword'       => 'timestamp test',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'rank_absolute' => 1, // Same unique constraint
            'se_domain'     => 'google.com',
            'task_id'       => 'task-older',
            'response_id'   => 200,
            'items_type'    => 'organic',
            'domain'        => 'older.com',
            'title'         => 'Older Title',
            'created_at'    => $olderTime,
            'updated_at'    => $olderTime,
        ];

        $this->processor->batchInsertOrUpdateOrganicItems([$olderItem]);

        // Should still have only 1 record with newer data (not updated)
        $this->assertEquals(1, DB::table($this->organicItemsTable)->count());
        $record = DB::table($this->organicItemsTable)->first();
        $this->assertEquals('Newer Title', $record->title);
        $this->assertEquals('newer.com', $record->domain);
        $this->assertEquals('task-newer', $record->task_id);
    }

    public function test_batch_insert_or_update_paa_items(): void
    {
        $now      = now();
        $paaItems = [
            [
                'keyword'               => 'test paa keyword 1',
                'location_code'         => 2840,
                'language_code'         => 'en',
                'device'                => 'desktop',
                'item_position'         => 1,
                'se_domain'             => 'google.com',
                'task_id'               => 'task-paa-123',
                'response_id'           => 789,
                'type'                  => 'people_also_ask_element',
                'title'                 => 'What is test 1?',
                'seed_question'         => 'What is test 1?',
                'xpath'                 => '//*[@id="test1"]',
                'answer_type'           => 'people_also_ask_expanded_element',
                'answer_featured_title' => 'Test Answer 1',
                'answer_url'            => 'https://example.com/answer1',
                'answer_domain'         => 'example.com',
                'answer_title'          => 'Answer Title 1',
                'answer_description'    => 'Answer Description 1',
                'created_at'            => $now,
                'updated_at'            => $now,
            ],
            [
                'keyword'               => 'test paa keyword 2',
                'location_code'         => 2840,
                'language_code'         => 'en',
                'device'                => 'desktop',
                'item_position'         => 2,
                'se_domain'             => 'google.com',
                'task_id'               => 'task-paa-123',
                'response_id'           => 789,
                'type'                  => 'people_also_ask_element',
                'title'                 => 'What is test 2?',
                'seed_question'         => 'What is test 2?',
                'xpath'                 => '//*[@id="test2"]',
                'answer_type'           => 'people_also_ask_expanded_element',
                'answer_featured_title' => 'Test Answer 2',
                'answer_url'            => 'https://example2.com/answer2',
                'answer_domain'         => 'example2.com',
                'answer_title'          => 'Answer Title 2',
                'answer_description'    => 'Answer Description 2',
                'created_at'            => $now,
                'updated_at'            => $now,
            ],
        ];

        $this->processor->batchInsertOrUpdatePaaItems($paaItems);

        // Verify items were inserted
        $insertedItems = DB::table($this->paaItemsTable)->orderBy('item_position')->get();
        $this->assertCount(2, $insertedItems);

        $firstItem = $insertedItems[0];
        $this->assertEquals('test paa keyword 1', $firstItem->keyword);
        $this->assertEquals(1, $firstItem->item_position);
        $this->assertEquals('What is test 1?', $firstItem->title);
        $this->assertEquals('example.com', $firstItem->answer_domain);

        $secondItem = $insertedItems[1];
        $this->assertEquals('test paa keyword 2', $secondItem->keyword);
        $this->assertEquals(2, $secondItem->item_position);
        $this->assertEquals('What is test 2?', $secondItem->title);
        $this->assertEquals('example2.com', $secondItem->answer_domain);
    }

    public function test_batch_insert_or_update_paa_items_with_duplicates(): void
    {
        $originalTime = now()->subMinutes(10);
        $newerTime    = now();

        $originalItem = [
            'keyword'       => 'duplicate paa keyword',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'item_position' => 1,
            'se_domain'     => 'google.com',
            'task_id'       => 'task-paa-original',
            'response_id'   => 100,
            'type'          => 'people_also_ask_element',
            'title'         => 'Original PAA Question?',
            'answer_domain' => 'original.com',
            'answer_title'  => 'Original Answer Title',
            'created_at'    => $originalTime,
            'updated_at'    => $originalTime,
        ];

        // Insert original item
        $this->processor->batchInsertOrUpdatePaaItems([$originalItem]);

        // Verify original was inserted
        $this->assertEquals(1, DB::table($this->paaItemsTable)->count());
        $original = DB::table($this->paaItemsTable)->first();
        $this->assertEquals('Original PAA Question?', $original->title);
        $this->assertEquals('original.com', $original->answer_domain);

        // Insert updated item with same unique constraints but newer timestamp
        $updatedItem = [
            'keyword'       => 'duplicate paa keyword',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'item_position' => 1, // Same unique constraint
            'se_domain'     => 'google.com',
            'task_id'       => 'task-paa-updated',
            'response_id'   => 200,
            'type'          => 'people_also_ask_element',
            'title'         => 'Updated PAA Question?',
            'answer_domain' => 'updated.com',
            'answer_title'  => 'Updated Answer Title',
            'created_at'    => $newerTime,
            'updated_at'    => $newerTime,
        ];

        $this->processor->batchInsertOrUpdatePaaItems([$updatedItem]);

        // Verify item was updated, not duplicated
        $this->assertEquals(1, DB::table($this->paaItemsTable)->count());
        $updated = DB::table($this->paaItemsTable)->first();
        $this->assertEquals('Updated PAA Question?', $updated->title);
        $this->assertEquals('updated.com', $updated->answer_domain);
        $this->assertEquals('task-paa-updated', $updated->task_id);
    }

    public function test_batch_insert_or_update_paa_items_with_empty_array(): void
    {
        // Test with empty array - should not cause errors
        $this->processor->batchInsertOrUpdatePaaItems([]);
        $this->assertEquals(0, DB::table($this->paaItemsTable)->count());
    }

    public function test_batch_insert_or_update_paa_items_with_update_if_newer_false(): void
    {
        // Set updateIfNewer to false
        $this->processor->setUpdateIfNewer(false);

        $now          = now();
        $originalItem = [
            'keyword'       => 'test paa keyword',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'item_position' => 1,
            'se_domain'     => 'google.com',
            'task_id'       => 'task-paa-original',
            'response_id'   => 100,
            'type'          => 'people_also_ask_element',
            'title'         => 'Original PAA Question?',
            'answer_domain' => 'original.com',
            'answer_title'  => 'Original Answer Title',
            'created_at'    => $now,
            'updated_at'    => $now,
        ];

        // Insert original item
        $this->processor->batchInsertOrUpdatePaaItems([$originalItem]);
        $this->assertEquals(1, DB::table($this->paaItemsTable)->count());

        // Try to insert duplicate - should be ignored
        $duplicateItem = [
            'keyword'       => 'test paa keyword',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'item_position' => 1, // Same unique constraint
            'se_domain'     => 'google.com',
            'task_id'       => 'task-paa-duplicate',
            'response_id'   => 200,
            'type'          => 'people_also_ask_element',
            'title'         => 'Duplicate PAA Question?',
            'answer_domain' => 'duplicate.com',
            'answer_title'  => 'Duplicate Answer Title',
            'created_at'    => $now,
            'updated_at'    => $now,
        ];

        $this->processor->batchInsertOrUpdatePaaItems([$duplicateItem]);

        // Should still have only 1 record with original data
        $this->assertEquals(1, DB::table($this->paaItemsTable)->count());
        $record = DB::table($this->paaItemsTable)->first();
        $this->assertEquals('Original PAA Question?', $record->title);
        $this->assertEquals('original.com', $record->answer_domain);
        $this->assertEquals('task-paa-original', $record->task_id);
    }

    public function test_batch_insert_or_update_paa_items_with_older_timestamp(): void
    {
        $newerTime = now();
        $olderTime = now()->subMinutes(10);

        // Insert newer item first
        $newerItem = [
            'keyword'       => 'paa timestamp test',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'item_position' => 1,
            'se_domain'     => 'google.com',
            'task_id'       => 'task-paa-newer',
            'response_id'   => 100,
            'type'          => 'people_also_ask_element',
            'title'         => 'Newer PAA Question?',
            'answer_domain' => 'newer.com',
            'answer_title'  => 'Newer Answer Title',
            'created_at'    => $newerTime,
            'updated_at'    => $newerTime,
        ];

        $this->processor->batchInsertOrUpdatePaaItems([$newerItem]);
        $this->assertEquals(1, DB::table($this->paaItemsTable)->count());

        // Try to insert older item - should NOT update
        $olderItem = [
            'keyword'       => 'paa timestamp test',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'item_position' => 1, // Same unique constraint
            'se_domain'     => 'google.com',
            'task_id'       => 'task-paa-older',
            'response_id'   => 200,
            'type'          => 'people_also_ask_element',
            'title'         => 'Older PAA Question?',
            'answer_domain' => 'older.com',
            'answer_title'  => 'Older Answer Title',
            'created_at'    => $olderTime,
            'updated_at'    => $olderTime,
        ];

        $this->processor->batchInsertOrUpdatePaaItems([$olderItem]);

        // Should still have only 1 record with newer data (not updated)
        $this->assertEquals(1, DB::table($this->paaItemsTable)->count());
        $record = DB::table($this->paaItemsTable)->first();
        $this->assertEquals('Newer PAA Question?', $record->title);
        $this->assertEquals('newer.com', $record->answer_domain);
        $this->assertEquals('task-paa-newer', $record->task_id);
    }

    public function test_process_listings(): void
    {
        $result = [
            'keyword'          => 'test listings keyword',
            'type'             => 'organic',
            'check_url'        => 'https://www.google.com/search?q=test',
            'datetime'         => '2023-01-01 12:00:00',
            'spell'            => ['original' => 'test', 'corrected' => 'test'],
            'refinement_chips' => [['type' => 'refinement', 'title' => 'Images']],
            'item_types'       => ['organic', 'people_also_ask'],
            'se_results_count' => 1000000,
            'items_count'      => 10,
        ];

        $listingsTaskData = [
            'keyword'       => 'test listings keyword',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'task_id'       => 'task-listings-123',
            'response_id'   => 456,
            'se'            => 'google',
            'se_type'       => 'organic',
            'tag'           => 'test-tag',
        ];

        $stats = $this->processor->processListings($result, $listingsTaskData);

        $this->assertEquals(1, $stats['listings_items']);
        $this->assertEquals(1, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);

        // Verify listings was inserted into database
        $insertedListings = DB::table($this->listingsTable)->first();
        $this->assertNotNull($insertedListings);
        $this->assertEquals('test listings keyword', $insertedListings->keyword);
        $this->assertEquals('test listings keyword', $insertedListings->result_keyword);
        $this->assertEquals('organic', $insertedListings->type);
        $this->assertEquals('https://www.google.com/search?q=test', $insertedListings->check_url);
        $this->assertEquals('2023-01-01 12:00:00', $insertedListings->result_datetime);
        $this->assertEquals(1000000, $insertedListings->se_results_count);
        $this->assertEquals(10, $insertedListings->items_count);
        $this->assertEquals('google', $insertedListings->se);
        $this->assertEquals('organic', $insertedListings->se_type);
        $this->assertEquals('test-tag', $insertedListings->tag);
        $this->assertEquals('desktop', $insertedListings->device); // Default applied

        // Verify JSON fields are properly formatted
        $this->assertStringContainsString('original', $insertedListings->spell);
        $this->assertStringContainsString('corrected', $insertedListings->spell);
        $this->assertStringContainsString('refinement', $insertedListings->refinement_chips);
        $this->assertStringContainsString('organic', $insertedListings->item_types);
    }

    public function test_process_listings_with_skip_refinement_chips(): void
    {
        // Enable skipRefinementChips
        $this->processor->setSkipRefinementChips(true);

        $result = [
            'keyword'          => 'test listings keyword',
            'type'             => 'organic',
            'check_url'        => 'https://www.google.com/search?q=test',
            'datetime'         => '2023-01-01 12:00:00',
            'refinement_chips' => [['type' => 'refinement', 'title' => 'Images']],
            'item_types'       => ['organic'],
            'se_results_count' => 1000000,
            'items_count'      => 10,
        ];

        $listingsTaskData = [
            'keyword'       => 'test listings keyword',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'task_id'       => 'task-listings-123',
            'response_id'   => 456,
            'se'            => 'google',
            'se_type'       => 'organic',
            'tag'           => 'test-tag',
        ];

        $stats = $this->processor->processListings($result, $listingsTaskData);

        $this->assertEquals(1, $stats['listings_items']);
        $this->assertEquals(1, $stats['items_inserted']);

        // Verify refinement_chips was skipped (should be null)
        $insertedListings = DB::table($this->listingsTable)->first();
        $this->assertNull($insertedListings->refinement_chips);
    }

    public function test_process_listings_with_null_optional_fields(): void
    {
        $result = [
            'keyword'   => 'test listings keyword',
            'type'      => 'organic',
            'check_url' => 'https://www.google.com/search?q=test',
            'datetime'  => '2023-01-01 12:00:00',
            // spell, refinement_chips are missing, item_types is null
            'item_types'       => null,
            'se_results_count' => 1000000,
            'items_count'      => 10,
        ];

        $listingsTaskData = [
            'keyword'       => 'test listings keyword',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'task_id'       => 'task-listings-123',
            'response_id'   => 456,
            'se'            => 'google',
            'se_type'       => 'organic',
            'tag'           => 'test-tag',
        ];

        $stats = $this->processor->processListings($result, $listingsTaskData);

        $this->assertEquals(1, $stats['listings_items']);
        $this->assertEquals(1, $stats['items_inserted']);

        // Verify optional fields are null when missing, but item_types gets JSON null
        $insertedListings = DB::table($this->listingsTable)->first();
        $this->assertNull($insertedListings->spell);
        $this->assertNull($insertedListings->refinement_chips);
        $this->assertNull($insertedListings->item_types);
    }

    public function test_process_organic_items(): void
    {
        $mergedTaskData = [
            'keyword'       => 'test organic keyword',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'task_id'       => 'task-organic-123',
            'response_id'   => 456,
        ];

        $items = [
            [
                'type'                => 'organic',
                'rank_group'          => 1,
                'rank_absolute'       => 1,
                'domain'              => 'example.com',
                'title'               => 'Test Organic Title 1',
                'description'         => 'Test Organic Description 1',
                'url'                 => 'https://example.com/test1',
                'breadcrumb'          => 'Home > Test',
                'is_image'            => false,
                'is_video'            => false,
                'is_featured_snippet' => false,
                'is_malicious'        => false,
                'is_web_story'        => false,
            ],
            [
                'type'                => 'organic',
                'rank_group'          => 2,
                'rank_absolute'       => 2,
                'domain'              => 'example2.com',
                'title'               => 'Test Organic Title 2',
                'description'         => 'Test Organic Description 2',
                'url'                 => 'https://example2.com/test2',
                'is_featured_snippet' => true,
            ],
            [
                'type'  => 'paid', // Should be ignored
                'title' => 'Paid Ad',
            ],
        ];

        $result = $this->processor->processOrganicItems($items, $mergedTaskData);

        // Should return detailed stats with count of organic items processed (2)
        $this->assertEquals(2, $result['organic_items']);
        $this->assertEquals(2, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);

        // Verify items were inserted into database
        $insertedItems = DB::table($this->organicItemsTable)->orderBy('rank_absolute')->get();
        $this->assertCount(2, $insertedItems);

        $firstItem = $insertedItems[0];
        $this->assertEquals('test organic keyword', $firstItem->keyword);
        $this->assertEquals(1, $firstItem->rank_absolute);
        $this->assertEquals('example.com', $firstItem->domain);
        $this->assertEquals('Test Organic Title 1', $firstItem->title);
        $this->assertEquals('Home > Test', $firstItem->breadcrumb);
        $this->assertEquals(0, $firstItem->is_featured_snippet);

        $secondItem = $insertedItems[1];
        $this->assertEquals('test organic keyword', $secondItem->keyword);
        $this->assertEquals(2, $secondItem->rank_absolute);
        $this->assertEquals('example2.com', $secondItem->domain);
        $this->assertEquals('Test Organic Title 2', $secondItem->title);
        $this->assertEquals(1, $secondItem->is_featured_snippet);
    }

    public function test_process_organic_items_with_no_organic_items(): void
    {
        $mergedTaskData = [
            'keyword'       => 'test keyword',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
        ];

        $items = [
            ['type' => 'paid'],
            ['type' => 'people_also_ask'],
            ['type' => 'featured_snippet'],
        ];

        $result = $this->processor->processOrganicItems($items, $mergedTaskData);

        $this->assertEquals(0, $result['organic_items']);
        $this->assertEquals(0, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);
        $this->assertEquals(0, DB::table($this->organicItemsTable)->count());
    }

    public function test_process_organic_items_with_empty_array(): void
    {
        $mergedTaskData = ['keyword' => 'test'];
        $result         = $this->processor->processOrganicItems([], $mergedTaskData);

        $this->assertEquals(0, $result['organic_items']);
        $this->assertEquals(0, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);
        $this->assertEquals(0, DB::table($this->organicItemsTable)->count());
    }

    public function test_process_paa_items(): void
    {
        $mergedTaskData = [
            'keyword'       => 'test paa keyword',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'task_id'       => 'task-paa-123',
            'response_id'   => 789,
        ];

        $items = [
            [
                'type'  => 'people_also_ask',
                'items' => [
                    [
                        'type'             => 'people_also_ask_element',
                        'title'            => 'What is test question 1?',
                        'seed_question'    => 'What is test question 1?',
                        'xpath'            => '//*[@id="test1"]',
                        'expanded_element' => [
                            [
                                'type'           => 'people_also_ask_expanded_element',
                                'featured_title' => 'Test Answer 1',
                                'url'            => 'https://example.com/answer1',
                                'domain'         => 'example.com',
                                'title'          => 'Answer Title 1',
                                'description'    => 'Answer Description 1',
                                'images'         => [['url' => 'https://example.com/image1.jpg']],
                                'timestamp'      => '2023-01-01',
                                'table'          => [['header' => 'value']],
                            ],
                        ],
                    ],
                    [
                        'type'             => 'people_also_ask_element',
                        'title'            => 'What is test question 2?',
                        'seed_question'    => 'What is test question 2?',
                        'xpath'            => '//*[@id="test2"]',
                        'expanded_element' => [
                            [
                                'type'           => 'people_also_ask_expanded_element',
                                'featured_title' => 'Test Answer 2',
                                'url'            => 'https://example2.com/answer2',
                                'domain'         => 'example2.com',
                                'title'          => 'Answer Title 2',
                                'description'    => 'Answer Description 2',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type'  => 'organic', // Should be ignored
                'title' => 'Organic Result',
            ],
        ];

        $result = $this->processor->processPaaItems($items, $mergedTaskData);

        // Should return detailed stats with count of PAA items processed (2)
        $this->assertEquals(2, $result['paa_items']);
        $this->assertEquals(2, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);

        // Verify items were inserted into database
        $insertedItems = DB::table($this->paaItemsTable)->orderBy('item_position')->get();
        $this->assertCount(2, $insertedItems);

        $firstItem = $insertedItems[0];
        $this->assertEquals('test paa keyword', $firstItem->keyword);
        $this->assertEquals(1, $firstItem->item_position);
        $this->assertEquals('What is test question 1?', $firstItem->title);
        $this->assertEquals('example.com', $firstItem->answer_domain);
        $this->assertEquals('Test Answer 1', $firstItem->answer_featured_title);
        $this->assertStringContainsString('image1.jpg', $firstItem->answer_images);
        $this->assertStringContainsString('header', $firstItem->answer_table);

        $secondItem = $insertedItems[1];
        $this->assertEquals('test paa keyword', $secondItem->keyword);
        $this->assertEquals(2, $secondItem->item_position);
        $this->assertEquals('What is test question 2?', $secondItem->title);
        $this->assertEquals('example2.com', $secondItem->answer_domain);
        $this->assertEquals('Test Answer 2', $secondItem->answer_featured_title);
        $this->assertNull($secondItem->answer_images);
        $this->assertNull($secondItem->answer_table);
    }

    public function test_process_paa_items_with_no_paa_items(): void
    {
        $mergedTaskData = [
            'keyword'       => 'test keyword',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
        ];

        $items = [
            ['type' => 'organic'],
            ['type' => 'paid'],
            ['type' => 'featured_snippet'],
        ];

        $result = $this->processor->processPaaItems($items, $mergedTaskData);

        $this->assertEquals(0, $result['paa_items']);
        $this->assertEquals(0, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);
        $this->assertEquals(0, DB::table($this->paaItemsTable)->count());
    }

    public function test_process_paa_items_with_invalid_structure(): void
    {
        $mergedTaskData = ['keyword' => 'test'];

        $items = [
            [
                'type' => 'people_also_ask',
                // Missing 'items' key
            ],
            [
                'type'  => 'people_also_ask',
                'items' => [
                    [
                        'type' => 'people_also_ask_element',
                        // Missing 'expanded_element' key
                    ],
                ],
            ],
            [
                'type'  => 'people_also_ask',
                'items' => [
                    [
                        'type'             => 'people_also_ask_element',
                        'expanded_element' => [
                            [
                                'type' => 'wrong_type', // Wrong type
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->processor->processPaaItems($items, $mergedTaskData);

        $this->assertEquals(0, $result['paa_items']);
        $this->assertEquals(0, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);
        $this->assertEquals(0, DB::table($this->paaItemsTable)->count());
    }

    public function test_process_paa_items_with_empty_array(): void
    {
        $mergedTaskData = ['keyword' => 'test'];
        $result         = $this->processor->processPaaItems([], $mergedTaskData);

        $this->assertEquals(0, $result['paa_items']);
        $this->assertEquals(0, $result['items_inserted']);
        $this->assertEquals(0, $result['items_updated']);
        $this->assertEquals(0, $result['items_skipped']);
        $this->assertEquals(0, DB::table($this->paaItemsTable)->count());
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
                        'se'            => 'google',
                        'se_type'       => 'organic',
                        'tag'           => null,
                    ],
                    'result' => [
                        [
                            'keyword'       => 'test response keyword',
                            'se_domain'     => 'google.com',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'device'        => 'desktop',
                            'check_url'     => 'https://www.google.com/search?q=test+response+keyword',
                            'datetime'      => '2023-01-01 12:00:00',
                            'item_types'    => ['organic', 'people_also_ask'],
                            'items'         => [
                                [
                                    'type'                => 'organic',
                                    'rank_group'          => 1,
                                    'rank_absolute'       => 1,
                                    'domain'              => 'example.com',
                                    'title'               => 'Test Response Title',
                                    'description'         => 'Test Response Description',
                                    'url'                 => 'https://example.com/test',
                                    'is_featured_snippet' => false,
                                ],
                                [
                                    'type'  => 'people_also_ask',
                                    'items' => [
                                        [
                                            'type'             => 'people_also_ask_element',
                                            'title'            => 'What is test response?',
                                            'seed_question'    => 'What is test response?',
                                            'xpath'            => '//*[@id="test"]',
                                            'expanded_element' => [
                                                [
                                                    'type'           => 'people_also_ask_expanded_element',
                                                    'featured_title' => 'Test Response Answer',
                                                    'url'            => 'https://example.com/answer',
                                                    'domain'         => 'example.com',
                                                    'title'          => 'Answer Title',
                                                    'description'    => 'Answer Description',
                                                ],
                                            ],
                                        ],
                                    ],
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

        $stats = $this->processor->processResponse($response, true);

        // Should return detailed stats including listings statistics
        $this->assertEquals(1, $stats['listings_items']);
        $this->assertEquals(1, $stats['organic_items']);
        $this->assertEquals(1, $stats['paa_items']);
        $this->assertEquals(2, $stats['total_items']);

        // Verify items were inserted into all tables
        $listingsItem = DB::table($this->listingsTable)->first();
        $this->assertNotNull($listingsItem);
        $this->assertEquals('test response keyword', $listingsItem->keyword);
        $this->assertEquals('google', $listingsItem->se);
        $this->assertEquals('organic', $listingsItem->se_type);

        $organicItem = DB::table($this->organicItemsTable)->first();
        $this->assertNotNull($organicItem);
        $this->assertEquals('test response keyword', $organicItem->keyword);
        $this->assertEquals('Test Response Title', $organicItem->title);

        $paaItem = DB::table($this->paaItemsTable)->first();
        $this->assertNotNull($paaItem);
        $this->assertEquals('test response keyword', $paaItem->keyword);
        $this->assertEquals('What is test response?', $paaItem->title);
    }

    public function test_process_response_without_paa_processing(): void
    {
        $responseData = [
            'tasks' => [
                [
                    'id'   => 'test-task-456',
                    'data' => [
                        'keyword'       => 'test no paa keyword',
                        'se_domain'     => 'google.com',
                        'location_code' => 2840,
                        'language_code' => 'en',
                        'device'        => 'desktop',
                        'se'            => 'google',
                        'se_type'       => 'organic',
                        'tag'           => null,
                    ],
                    'result' => [
                        [
                            'keyword'       => 'test no paa keyword',
                            'se_domain'     => 'google.com',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'device'        => 'desktop',
                            'check_url'     => 'https://www.google.com/search?q=test+no+paa+keyword',
                            'datetime'      => '2023-01-01 12:00:00',
                            'item_types'    => ['organic'],
                            'items'         => [
                                [
                                    'type'          => 'organic',
                                    'rank_absolute' => 1,
                                    'domain'        => 'example.com',
                                    'title'         => 'Test Title',
                                ],
                                [
                                    'type'  => 'people_also_ask',
                                    'items' => [
                                        [
                                            'type'             => 'people_also_ask_element',
                                            'title'            => 'What is test?',
                                            'expanded_element' => [
                                                [
                                                    'type'           => 'people_also_ask_expanded_element',
                                                    'featured_title' => 'Test Answer',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = (object) [
            'id'            => 456,
            'response_body' => json_encode($responseData),
        ];

        $stats = $this->processor->processResponse($response, false);

        // Should return detailed stats including listings statistics
        $this->assertEquals(1, $stats['listings_items']);
        $this->assertEquals(1, $stats['organic_items']);
        $this->assertEquals(0, $stats['paa_items']); // PAA processing disabled
        $this->assertEquals(2, $stats['total_items']);

        // Verify listings and organic items were inserted, but no PAA items
        $this->assertEquals(1, DB::table($this->listingsTable)->count());
        $this->assertEquals(1, DB::table($this->organicItemsTable)->count());
        $this->assertEquals(0, DB::table($this->paaItemsTable)->count());
    }

    public function test_process_response_with_invalid_json(): void
    {
        $response = (object) [
            'id'            => 789,
            'response_body' => 'invalid json',
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid JSON response or missing tasks array');

        $this->processor->processResponse($response, true);
    }

    public function test_process_response_with_missing_tasks(): void
    {
        $response = (object) [
            'id'            => 101112,
            'response_body' => json_encode(['status' => 'ok']),
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid JSON response or missing tasks array');

        $this->processor->processResponse($response, true);
    }

    public function test_process_response_with_empty_tasks(): void
    {
        $response = (object) [
            'id'            => 131415,
            'response_body' => json_encode(['tasks' => []]),
        ];

        $stats = $this->processor->processResponse($response, true);

        $this->assertEquals(0, $stats['organic_items']);
        $this->assertEquals(0, $stats['paa_items']);
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

        $stats = $this->processor->processResponse($response, true);

        $this->assertEquals(0, $stats['organic_items']);
        $this->assertEquals(0, $stats['paa_items']);
        $this->assertEquals(0, $stats['total_items']);
    }

    public function test_process_responses_with_no_responses(): void
    {
        $stats = $this->processor->processResponses();

        $this->assertEquals(0, $stats['processed_responses']);
        $this->assertEquals(0, $stats['organic_items']);
        $this->assertEquals(0, $stats['paa_items']);
        $this->assertEquals(0, $stats['total_items']);
        $this->assertEquals(0, $stats['errors']);
    }

    public function test_process_responses_with_valid_organic_response(): void
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
                        'se'            => 'google',
                        'se_type'       => 'organic',
                        'tag'           => null,
                    ],
                    'result' => [
                        [
                            'keyword'       => 'test keyword',
                            'se_domain'     => 'google.com',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'device'        => 'desktop',
                            'check_url'     => 'https://www.google.com/search?q=test+keyword',
                            'datetime'      => '2023-01-01 12:00:00',
                            'item_types'    => ['organic'],
                            'items'         => [
                                [
                                    'type'                => 'organic',
                                    'rank_group'          => 1,
                                    'rank_absolute'       => 1,
                                    'domain'              => 'example.com',
                                    'title'               => 'Test Title',
                                    'description'         => 'Test Description',
                                    'url'                 => 'https://example.com/test',
                                    'is_featured_snippet' => false,
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
            'endpoint'             => 'serp/google/organic/task_get/advanced',
            'response_body'        => json_encode($responseData),
            'response_status_code' => 200,
            'base_url'             => 'https://api.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $stats = $this->processor->processResponses();

        $this->assertEquals(1, $stats['processed_responses']);
        $this->assertEquals(1, $stats['listings_items']);
        $this->assertEquals(1, $stats['organic_items']);
        $this->assertEquals(0, $stats['paa_items']);
        $this->assertEquals(1, $stats['total_items']);
        $this->assertEquals(0, $stats['errors']);

        // Verify organic item was inserted
        $organicItem = DB::table($this->organicItemsTable)->first();
        $this->assertNotNull($organicItem);
        $this->assertEquals('test keyword', $organicItem->keyword);
        $this->assertEquals('example.com', $organicItem->domain);
        $this->assertEquals('Test Title', $organicItem->title);
    }

    public function test_process_responses_with_paa_items(): void
    {
        // Insert test response with PAA items
        $responseData = [
            'tasks' => [
                [
                    'id'   => 'test-task-456',
                    'data' => [
                        'keyword'       => 'test paa keyword',
                        'se_domain'     => 'google.com',
                        'location_code' => 2840,
                        'language_code' => 'en',
                        'device'        => 'desktop',
                        'se'            => 'google',
                        'se_type'       => 'organic',
                        'tag'           => null,
                    ],
                    'result' => [
                        [
                            'keyword'       => 'test paa keyword',
                            'se_domain'     => 'google.com',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'device'        => 'desktop',
                            'check_url'     => 'https://www.google.com/search?q=test+paa+keyword',
                            'datetime'      => '2023-01-01 12:00:00',
                            'item_types'    => ['people_also_ask'],
                            'items'         => [
                                [
                                    'type'  => 'people_also_ask',
                                    'items' => [
                                        [
                                            'type'             => 'people_also_ask_element',
                                            'title'            => 'What is test?',
                                            'seed_question'    => 'What is test?',
                                            'xpath'            => '//*[@id="test"]',
                                            'expanded_element' => [
                                                [
                                                    'type'           => 'people_also_ask_expanded_element',
                                                    'featured_title' => 'Test Answer',
                                                    'url'            => 'https://example.com/answer',
                                                    'domain'         => 'example.com',
                                                    'title'          => 'Answer Title',
                                                    'description'    => 'Answer Description',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'test-paa-key',
            'endpoint'             => 'serp/google/organic/live/advanced',
            'response_body'        => json_encode($responseData),
            'response_status_code' => 200,
            'base_url'             => 'https://api.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $stats = $this->processor->processResponses(100, true);

        $this->assertEquals(1, $stats['processed_responses']);
        $this->assertEquals(1, $stats['listings_items']);
        $this->assertEquals(0, $stats['organic_items']);
        $this->assertEquals(1, $stats['paa_items']);
        $this->assertEquals(1, $stats['total_items']);
        $this->assertEquals(0, $stats['errors']);

        // Verify PAA item was inserted
        $paaItem = DB::table($this->paaItemsTable)->first();
        $this->assertNotNull($paaItem);
        $this->assertEquals('test paa keyword', $paaItem->keyword);
        $this->assertEquals('What is test?', $paaItem->title);
        $this->assertEquals('example.com', $paaItem->answer_domain);
    }

    public function test_process_responses_skips_paa_when_disabled(): void
    {
        // Insert test response with PAA items
        $responseData = [
            'tasks' => [
                [
                    'id'   => 'test-task-789',
                    'data' => [
                        'keyword'       => 'test no paa keyword',
                        'se_domain'     => 'google.com',
                        'location_code' => 2840,
                        'language_code' => 'en',
                        'device'        => 'desktop',
                        'se'            => 'google',
                        'se_type'       => 'organic',
                        'tag'           => null,
                    ],
                    'result' => [
                        [
                            'keyword'       => 'test no paa keyword',
                            'se_domain'     => 'google.com',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'device'        => 'desktop',
                            'check_url'     => 'https://www.google.com/search?q=test+no+paa+keyword',
                            'datetime'      => '2023-01-01 12:00:00',
                            'item_types'    => ['people_also_ask'],
                            'items'         => [
                                [
                                    'type'  => 'people_also_ask',
                                    'items' => [
                                        [
                                            'type'             => 'people_also_ask_element',
                                            'title'            => 'What is test?',
                                            'expanded_element' => [
                                                [
                                                    'type'           => 'people_also_ask_expanded_element',
                                                    'featured_title' => 'Test Answer',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'test-no-paa-key',
            'endpoint'             => 'serp/google/organic/task_get/advanced',
            'response_body'        => json_encode($responseData),
            'response_status_code' => 200,
            'base_url'             => 'https://api.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $stats = $this->processor->processResponses(100, false);

        $this->assertEquals(1, $stats['processed_responses']);
        $this->assertEquals(1, $stats['listings_items']);
        $this->assertEquals(0, $stats['organic_items']);
        $this->assertEquals(0, $stats['paa_items']); // Should be 0 since PAA processing is disabled
        $this->assertEquals(1, $stats['total_items']);
        $this->assertEquals(0, $stats['errors']);

        // Verify no PAA items were inserted
        $paaCount = DB::table($this->paaItemsTable)->count();
        $this->assertEquals(0, $paaCount);
    }

    public function test_process_responses_skips_sandbox_when_configured(): void
    {
        // Insert sandbox response
        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'sandbox-key',
            'endpoint'             => 'serp/google/organic/task_get/advanced',
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
            'endpoint'             => 'serp/google/organic/task_get/advanced',
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
            'endpoint'             => 'serp/google/organic/task_get/advanced',
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
            'endpoint'             => 'serp/google/organic/task_get/advanced',
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
                        'se'            => 'google',
                        'se_type'       => 'organic',
                        'tag'           => null,
                        // No device specified - will default to 'desktop'
                    ],
                    'result' => [
                        [
                            'keyword'          => 'test keyword',
                            'se_domain'        => 'google.com',
                            'location_code'    => 2840,
                            'language_code'    => 'en',
                            'device'           => null, // No device specified - will default to 'desktop'
                            'os'               => null,
                            'check_url'        => 'https://www.google.com/search?q=test+keyword',
                            'datetime'         => '2023-01-01 12:00:00',
                            'type'             => 'organic',
                            'item_types'       => ['organic'],
                            'se_results_count' => 1000000,
                            'items_count'      => 1,
                            'spell'            => null,
                            'refinement_chips' => null,
                            'items'            => [
                                [
                                    'type'          => 'organic',
                                    'rank_absolute' => 1,
                                    'domain'        => 'example.com',
                                    'title'         => 'Test Title',
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
            'endpoint'             => 'serp/google/organic/task_get/advanced',
            'response_body'        => json_encode($responseData),
            'response_status_code' => 200,
            'base_url'             => 'https://api.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $stats = $this->processor->processResponses();

        $this->assertEquals(1, $stats['processed_responses']);
        $this->assertEquals(1, $stats['listings_items']);
        $this->assertEquals(1, $stats['organic_items']);

        // Verify device default was applied
        $organicItem = DB::table($this->organicItemsTable)->first();
        $this->assertEquals('desktop', $organicItem->device);
    }

    public function test_process_responses_filters_by_endpoint_patterns(): void
    {
        // Insert responses with different endpoints
        DB::table($this->responsesTable)->insert([
            [
                'client'               => 'dataforseo',
                'key'                  => 'matching-task-get',
                'endpoint'             => 'serp/google/organic/task_get/advanced',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'               => 'dataforseo',
                'key'                  => 'matching-live',
                'endpoint'             => 'serp/google/organic/live/advanced',
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
                'endpoint'             => 'serp/google/organic/task_get/advanced',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'               => 'dataforseo',
                'key'                  => 'error-response',
                'endpoint'             => 'serp/google/organic/task_get/advanced',
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

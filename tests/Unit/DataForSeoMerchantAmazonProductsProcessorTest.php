<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\DataForSeoMerchantAmazonProductsProcessor;
use FOfX\ApiCache\ApiCacheManager;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\DB;

class DataForSeoMerchantAmazonProductsProcessorTest extends TestCase
{
    protected DataForSeoMerchantAmazonProductsProcessor $processor;
    protected ApiCacheManager $cacheManager;
    protected string $responsesTable = 'api_cache_dataforseo_responses';
    protected string $listingsTable  = 'dataforseo_merchant_amazon_products_listings';
    protected string $itemsTable     = 'dataforseo_merchant_amazon_products_items';

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheManager = app(ApiCacheManager::class);
        $this->processor    = new DataForSeoMerchantAmazonProductsProcessor($this->cacheManager);
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
        $processor = new DataForSeoMerchantAmazonProductsProcessor($this->cacheManager);
        $this->assertInstanceOf(DataForSeoMerchantAmazonProductsProcessor::class, $processor);
    }

    public function test_constructor_without_cache_manager(): void
    {
        $processor = new DataForSeoMerchantAmazonProductsProcessor();
        $this->assertInstanceOf(DataForSeoMerchantAmazonProductsProcessor::class, $processor);
    }

    public function test_get_skip_sandbox_returns_default_true(): void
    {
        $this->assertTrue($this->processor->getSkipSandbox());
    }

    public function test_set_skip_sandbox_changes_value(): void
    {
        $original = $this->processor->getSkipSandbox();
        $this->processor->setSkipSandbox(!$original);
        $this->assertEquals(!$original, $this->processor->getSkipSandbox());
    }

    public function test_get_update_if_newer_returns_default_true(): void
    {
        $this->assertTrue($this->processor->getUpdateIfNewer());
    }

    public function test_set_update_if_newer_changes_value(): void
    {
        $original = $this->processor->getUpdateIfNewer();
        $this->processor->setUpdateIfNewer(!$original);
        $this->assertEquals(!$original, $this->processor->getUpdateIfNewer());
    }

    public function test_get_skip_nested_items_returns_default_false(): void
    {
        $this->assertFalse($this->processor->getSkipNestedItems());
    }

    public function test_set_skip_nested_items_changes_value(): void
    {
        $original = $this->processor->getSkipNestedItems();
        $this->processor->setSkipNestedItems(!$original);
        $this->assertEquals(!$original, $this->processor->getSkipNestedItems());
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
                'endpoint'             => 'merchant/amazon/products/task_get/advanced/task-id-1',
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
                'endpoint'             => 'merchant/amazon/products/task_get/advanced/task-id-2',
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

        // Verify only Amazon Products responses were reset
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
        // Insert test data into both tables
        DB::table($this->listingsTable)->insert([
            'keyword'         => 'test',
            'se_domain'       => 'amazon.com',
            'location_code'   => 2840,
            'language_code'   => 'en_US',
            'device'          => 'desktop',
            'se'              => 'amazon',
            'se_type'         => 'organic',
            'function'        => 'products',
            'result_keyword'  => 'test',
            'check_url'       => 'https://www.amazon.com/s?k=test',
            'result_datetime' => '2023-01-01 12:00:00',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table($this->itemsTable)->insert([
            'keyword'        => 'test',
            'se_domain'      => 'amazon.com',
            'location_code'  => 2840,
            'language_code'  => 'en_US',
            'device'         => 'desktop',
            'result_keyword' => 'test',
            'items_type'     => 'amazon_serp',
            'rank_absolute'  => 1,
            'data_asin'      => 'B123456789',
            'title'          => 'Test Product',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $stats = $this->processor->clearProcessedTables();

        // Verify tables are cleared
        $this->assertEquals(0, DB::table($this->listingsTable)->count());
        $this->assertEquals(0, DB::table($this->itemsTable)->count());

        // Verify stats returned null values (withCount = false by default)
        $this->assertNull($stats['listings_cleared']);
        $this->assertNull($stats['items_cleared']);
    }

    public function test_clear_processed_tables_with_count(): void
    {
        // Insert test data
        DB::table($this->listingsTable)->insert([
            'keyword'         => 'test',
            'se_domain'       => 'amazon.com',
            'location_code'   => 2840,
            'language_code'   => 'en_US',
            'device'          => 'desktop',
            'se'              => 'amazon',
            'se_type'         => 'organic',
            'function'        => 'products',
            'result_keyword'  => 'test',
            'check_url'       => 'https://www.amazon.com/s?k=test',
            'result_datetime' => '2023-01-01 12:00:00',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table($this->itemsTable)->insert([
            'keyword'        => 'test',
            'se_domain'      => 'amazon.com',
            'location_code'  => 2840,
            'language_code'  => 'en_US',
            'device'         => 'desktop',
            'result_keyword' => 'test',
            'items_type'     => 'amazon_serp',
            'rank_absolute'  => 1,
            'data_asin'      => 'B123456789',
            'title'          => 'Test Product',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $stats = $this->processor->clearProcessedTables(true);

        // Verify stats returned actual counts
        $this->assertEquals(1, $stats['listings_cleared']);
        $this->assertEquals(1, $stats['items_cleared']);
    }

    public function test_extract_task_data(): void
    {
        $taskData = [
            'keyword'       => 'test keyword',
            'se'            => 'amazon',
            'se_type'       => 'products',
            'function'      => 'products',
            'location_code' => 2840,
            'language_code' => 'en_US',
            'device'        => 'desktop',
            'os'            => 'windows',
            'tag'           => 'test-tag',
        ];

        $result = $this->processor->extractTaskData($taskData);

        $this->assertEquals('test keyword', $result['keyword']);
        $this->assertEquals('amazon', $result['se']);
        $this->assertEquals('products', $result['se_type']);
        $this->assertEquals('products', $result['function']);
        $this->assertEquals(2840, $result['location_code']);
        $this->assertEquals('en_US', $result['language_code']);
        $this->assertEquals('desktop', $result['device']);
        $this->assertEquals('windows', $result['os']);
        $this->assertEquals('test-tag', $result['tag']);
    }

    public function test_extract_task_data_with_missing_fields(): void
    {
        $taskData = [
            'keyword' => 'test keyword',
            'se'      => 'amazon',
        ];

        $result = $this->processor->extractTaskData($taskData);

        $this->assertEquals('test keyword', $result['keyword']);
        $this->assertEquals('amazon', $result['se']);
        $this->assertNull($result['se_type']);
        $this->assertNull($result['function']);
        $this->assertNull($result['location_code']);
        $this->assertNull($result['language_code']);
        $this->assertNull($result['device']);
        $this->assertNull($result['os']);
        $this->assertNull($result['tag']);
    }

    public function test_extract_result_metadata(): void
    {
        $result = [
            'keyword'          => 'gaming keyboard',
            'type'             => 'organic',
            'se_domain'        => 'amazon.com',
            'check_url'        => 'https://amazon.com/search?q=gaming+keyboard',
            'datetime'         => '2023-01-01 12:00:00',
            'spell'            => ['original' => 'gaming keyboard'],
            'item_types'       => ['amazon_serp'],
            'se_results_count' => 1000,
            'categories'       => ['Electronics'],
            'items_count'      => 50,
        ];

        $metadata = $this->processor->extractResultMetadata($result);

        $this->assertEquals('gaming keyboard', $metadata['result_keyword']);
        $this->assertEquals('organic', $metadata['type']);
        $this->assertEquals('amazon.com', $metadata['se_domain']);
        $this->assertEquals('https://amazon.com/search?q=gaming+keyboard', $metadata['check_url']);
        $this->assertEquals('2023-01-01 12:00:00', $metadata['result_datetime']);
        $this->assertEquals(['original' => 'gaming keyboard'], $metadata['spell']);
        $this->assertEquals(['amazon_serp'], $metadata['item_types']);
        $this->assertEquals(1000, $metadata['se_results_count']);
        $this->assertEquals(['Electronics'], $metadata['categories']);
        $this->assertEquals(50, $metadata['items_count']);
    }

    public function test_extract_result_metadata_with_missing_fields(): void
    {
        $result = [
            'keyword'   => 'gaming keyboard',
            'se_domain' => 'amazon.com',
        ];

        $metadata = $this->processor->extractResultMetadata($result);

        $this->assertEquals('gaming keyboard', $metadata['result_keyword']);
        $this->assertEquals('amazon.com', $metadata['se_domain']);
        $this->assertNull($metadata['type']);
        $this->assertNull($metadata['check_url']);
        $this->assertNull($metadata['result_datetime']);
        $this->assertNull($metadata['spell']);
        $this->assertNull($metadata['item_types']);
        $this->assertNull($metadata['se_results_count']);
        $this->assertNull($metadata['categories']);
        $this->assertNull($metadata['items_count']);
    }

    public function test_extract_products_items_data(): void
    {
        $mergedData = [
            'response_id'     => 123,
            'task_id'         => 'task-456',
            'keyword'         => 'test keyword',
            'se'              => 'amazon',
            'se_type'         => 'products',
            'function'        => 'products',
            'se_domain'       => 'amazon.com',
            'location_code'   => 2840,
            'language_code'   => 'en_US',
            'device'          => 'desktop',
            'os'              => 'windows',
            'tag'             => 'test-tag',
            'result_keyword'  => 'test result keyword',
            'type'            => 'organic',
            'check_url'       => 'https://amazon.com/search',
            'result_datetime' => '2023-01-01 12:00:00',
            'extra_field'     => 'should be filtered out',
        ];

        $result = $this->processor->extractProductsItemsData($mergedData);

        // Should include these fields
        $this->assertEquals(123, $result['response_id']);
        $this->assertEquals('task-456', $result['task_id']);
        $this->assertEquals('test keyword', $result['keyword']);
        $this->assertEquals('amazon.com', $result['se_domain']);
        $this->assertEquals(2840, $result['location_code']);
        $this->assertEquals('en_US', $result['language_code']);
        $this->assertEquals('desktop', $result['device']);
        $this->assertEquals('windows', $result['os']);
        $this->assertEquals('test-tag', $result['tag']);
        $this->assertEquals('test result keyword', $result['result_keyword']);

        // Should NOT include these fields (filtered out for items table)
        $this->assertArrayNotHasKey('se', $result);
        $this->assertArrayNotHasKey('se_type', $result);
        $this->assertArrayNotHasKey('function', $result);
        $this->assertArrayNotHasKey('type', $result);
        $this->assertArrayNotHasKey('check_url', $result);
        $this->assertArrayNotHasKey('result_datetime', $result);
        $this->assertArrayNotHasKey('extra_field', $result);
    }

    public function test_extract_products_items_data_with_missing_fields(): void
    {
        $mergedData = [
            'keyword'   => 'test keyword',
            'se_domain' => 'amazon.com',
        ];

        $result = $this->processor->extractProductsItemsData($mergedData);

        $this->assertEquals('test keyword', $result['keyword']);
        $this->assertEquals('amazon.com', $result['se_domain']);
        $this->assertNull($result['response_id']);
        $this->assertNull($result['task_id']);
        $this->assertNull($result['location_code']);
        $this->assertNull($result['language_code']);
        $this->assertNull($result['device']);
        $this->assertNull($result['os']);
        $this->assertNull($result['tag']);
        $this->assertNull($result['result_keyword']);
    }

    public function test_ensure_defaults(): void
    {
        $data = [
            'keyword'       => 'test',
            'location_code' => 2840,
        ];

        $result = $this->processor->ensureDefaults($data);

        $this->assertEquals('test', $result['keyword']);
        $this->assertEquals(2840, $result['location_code']);
        $this->assertEquals('desktop', $result['device']); // Default applied
    }

    public function test_ensure_defaults_preserves_existing_device(): void
    {
        $data = [
            'keyword' => 'test',
            'device'  => 'mobile',
        ];

        $result = $this->processor->ensureDefaults($data);

        $this->assertEquals('test', $result['keyword']);
        $this->assertEquals('mobile', $result['device']); // Existing value preserved
    }

    public function test_batch_insert_or_update_listings_insert_new(): void
    {
        $listingsItems = [
            [
                'keyword'         => 'gaming keyboard',
                'location_code'   => 2840,
                'language_code'   => 'en_US',
                'device'          => 'desktop',
                'se'              => 'amazon',
                'se_type'         => 'organic',
                'function'        => 'products',
                'se_domain'       => 'amazon.com',
                'result_keyword'  => 'gaming keyboard',
                'check_url'       => 'https://www.amazon.com/s?k=gaming+keyboard',
                'result_datetime' => '2023-01-01 12:00:00',
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
        ];

        $stats = $this->processor->batchInsertOrUpdateListings($listingsItems);

        $this->assertEquals(1, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);

        // Verify data was inserted
        $this->assertEquals(1, DB::table($this->listingsTable)->count());
    }

    public function test_batch_insert_or_update_items_insert_new(): void
    {
        $items = [
            [
                'keyword'        => 'gaming keyboard',
                'location_code'  => 2840,
                'language_code'  => 'en_US',
                'device'         => 'desktop',
                'items_type'     => 'amazon_serp',
                'rank_absolute'  => 1,
                'se_domain'      => 'amazon.com',
                'result_keyword' => 'gaming keyboard',
                'data_asin'      => 'B123456789',
                'title'          => 'Test Gaming Keyboard',
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
        ];

        $stats = $this->processor->batchInsertOrUpdateItems($items);

        $this->assertEquals(1, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);

        // Verify data was inserted
        $this->assertEquals(1, DB::table($this->itemsTable)->count());
    }

    public function test_process_listings(): void
    {
        $result = [
            'keyword'          => 'gaming keyboard',
            'type'             => 'organic',
            'se_domain'        => 'amazon.com',
            'check_url'        => 'https://www.amazon.com/s?k=gaming+keyboard',
            'datetime'         => '2023-01-01 12:00:00',
            'spell'            => ['corrected' => 'gaming keyboard'],
            'item_types'       => ['amazon_serp', 'amazon_paid'],
            'se_results_count' => 1000,
            'categories'       => ['Electronics', 'Computers'],
            'items_count'      => 50,
        ];

        // Merged task data and result metadata (as would be done in processResponse)
        $mergedData = [
            'keyword'          => 'gaming keyboard',
            'se'               => 'amazon',
            'se_type'          => 'products',
            'function'         => 'products',
            'location_code'    => 2840,
            'language_code'    => 'en_US',
            'device'           => 'desktop',
            'os'               => 'windows',
            'tag'              => 'test-tag',
            'task_id'          => 'task-123',
            'response_id'      => 1,
            'result_keyword'   => 'gaming keyboard',
            'type'             => 'organic',
            'se_domain'        => 'amazon.com',
            'check_url'        => 'https://www.amazon.com/s?k=gaming+keyboard',
            'result_datetime'  => '2023-01-01 12:00:00',
            'spell'            => ['corrected' => 'gaming keyboard'],
            'item_types'       => ['amazon_serp', 'amazon_paid'],
            'se_results_count' => 1000,
            'categories'       => ['Electronics', 'Computers'],
            'items_count'      => 50,
        ];

        $stats = $this->processor->processListings($result, $mergedData);

        $this->assertEquals(1, $stats['listings_items']);
        $this->assertEquals(1, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);

        // Verify data was inserted with pretty-printed JSON
        $listing = DB::table($this->listingsTable)->first();
        $this->assertEquals('gaming keyboard', $listing->keyword);
        $this->assertEquals('amazon.com', $listing->se_domain);
        $this->assertStringContainsString('corrected', $listing->spell);
        $this->assertStringContainsString('amazon_serp', $listing->item_types);
        $this->assertStringContainsString('Electronics', $listing->categories);
    }

    public function test_process_items_with_data_asin(): void
    {
        $items = [
            [
                'type'              => 'amazon_serp',
                'rank_group'        => 1,
                'rank_absolute'     => 1,
                'xpath'             => '/html/body/div[1]',
                'domain'            => 'www.amazon.com',
                'title'             => 'Gaming Keyboard RGB',
                'url'               => 'https://www.amazon.com/dp/B123456789',
                'image_url'         => 'https://images.amazon.com/image.jpg',
                'bought_past_month' => 1000,
                'price_from'        => 49.99,
                'price_to'          => 59.99,
                'currency'          => 'USD',
                'special_offers'    => [['type' => 'coupon', 'discount' => '10%']],
                'data_asin'         => 'B123456789',
                'rating'            => [
                    'type'        => 'rating_element',
                    'position'    => 'left',
                    'rating_type' => 'Max5',
                    'value'       => '4.5',
                    'votes_count' => 1234,
                    'rating_max'  => '5',
                ],
                'is_amazon_choice' => true,
                'is_best_seller'   => false,
                'delivery_info'    => [['type' => 'free_shipping']],
            ],
        ];

        $mergedData = [
            'keyword'       => 'gaming keyboard',
            'location_code' => 2840,
            'language_code' => 'en_US',
            'device'        => 'desktop',
            'se_domain'     => 'amazon.com',
            'task_id'       => 'task-123',
            'response_id'   => 1,
        ];

        $stats = $this->processor->processItems($items, $mergedData);

        $this->assertEquals(1, $stats['items_processed']);
        $this->assertEquals(1, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);

        // Verify data was inserted with flattened rating and pretty-printed JSON
        $item = DB::table($this->itemsTable)->first();
        $this->assertEquals('gaming keyboard', $item->keyword);
        $this->assertEquals('gaming keyboard', $item->result_keyword);
        $this->assertEquals('amazon_serp', $item->items_type);
        $this->assertEquals(1, $item->rank_absolute);
        $this->assertEquals('B123456789', $item->data_asin);
        $this->assertEquals('Gaming Keyboard RGB', $item->title);
        $this->assertEquals('4.5', $item->rating_value);
        $this->assertEquals(1234, $item->rating_votes_count);
        $this->assertTrue((bool) $item->is_amazon_choice);
        $this->assertFalse((bool) $item->is_best_seller);
        $this->assertStringContainsString('coupon', $item->special_offers);
        $this->assertStringContainsString('free_shipping', $item->delivery_info);
    }

    public function test_process_items_skips_items_without_data_asin(): void
    {
        $items = [
            [
                'type'          => 'related_searches',
                'rank_absolute' => 35,
                'title'         => 'gaming mouse',
                // No data_asin field
            ],
            [
                'type'          => 'amazon_serp',
                'rank_absolute' => 1,
                'data_asin'     => 'B123456789',
                'title'         => 'Gaming Keyboard',
            ],
        ];

        $mergedData = [
            'keyword'       => 'gaming keyboard',
            'location_code' => 2840,
            'language_code' => 'en_US',
            'device'        => 'desktop',
            'se_domain'     => 'amazon.com',
            'task_id'       => 'task-123',
            'response_id'   => 1,
        ];

        $stats = $this->processor->processItems($items, $mergedData);

        // Only 1 item should be processed (the one with data_asin)
        $this->assertEquals(1, $stats['items_processed']);
        $this->assertEquals(1, $stats['items_inserted']);

        // Verify only the item with data_asin was inserted
        $this->assertEquals(1, DB::table($this->itemsTable)->count());
        $item = DB::table($this->itemsTable)->first();
        $this->assertEquals('B123456789', $item->data_asin);
        $this->assertEquals('Gaming Keyboard', $item->title);
    }
}

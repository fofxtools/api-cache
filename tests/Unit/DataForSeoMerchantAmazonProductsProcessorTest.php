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
                'endpoint'             => 'merchant/amazon/products/task_get/advanced/11111111-1111-1111-1111-111111111111',
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
                'endpoint'             => 'merchant/amazon/products/task_get/advanced/22222222-2222-2222-2222-222222222222',
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
            'keyword'        => 'gaming keyboard',
            'result_keyword' => 'mechanical gaming keyboard', // Different from keyword to test proper extraction
            'location_code'  => 2840,
            'language_code'  => 'en_US',
            'device'         => 'desktop',
            'se_domain'      => 'amazon.com',
            'task_id'        => 'task-123',
            'response_id'    => 1,
        ];

        $stats = $this->processor->processItems($items, $mergedData);

        $this->assertEquals(1, $stats['items_processed']);
        $this->assertEquals(1, $stats['items_inserted']);
        $this->assertEquals(0, $stats['items_updated']);
        $this->assertEquals(0, $stats['items_skipped']);

        // Verify data was inserted with flattened rating and pretty-printed JSON
        $item = DB::table($this->itemsTable)->first();
        $this->assertEquals('gaming keyboard', $item->keyword);
        $this->assertEquals('mechanical gaming keyboard', $item->result_keyword); // Should preserve different result_keyword
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

    public function test_process_response_with_valid_data(): void
    {
        // Create a mock response object
        $responseBody = [
            'tasks' => [
                [
                    'id'   => 'task-123',
                    'data' => [
                        'keyword'       => 'gaming keyboard',
                        'se'            => 'amazon',
                        'se_type'       => 'products',
                        'function'      => 'products',
                        'location_code' => 2840,
                        'language_code' => 'en_US',
                        'device'        => 'desktop',
                    ],
                    'result' => [
                        [
                            'keyword'          => 'gaming keyboard',
                            'type'             => 'organic',
                            'se_domain'        => 'amazon.com',
                            'check_url'        => 'https://www.amazon.com/s?k=gaming+keyboard',
                            'datetime'         => '2023-01-01 12:00:00',
                            'se_results_count' => 1000,
                            'items_count'      => 2,
                            'items'            => [
                                [
                                    'type'          => 'amazon_serp',
                                    'rank_absolute' => 1,
                                    'data_asin'     => 'B123456789',
                                    'title'         => 'Gaming Keyboard RGB',
                                    'price_from'    => 49.99,
                                    'rating'        => [
                                        'value'       => '4.5',
                                        'votes_count' => 1234,
                                    ],
                                ],
                                [
                                    'type'          => 'amazon_serp',
                                    'rank_absolute' => 2,
                                    'data_asin'     => 'B987654321',
                                    'title'         => 'Mechanical Keyboard',
                                    'price_from'    => 79.99,
                                    'rating'        => [
                                        'value'       => '4.7',
                                        'votes_count' => 567,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response                       = new \stdClass();
        $response->id                   = 1;
        $response->response_body        = json_encode($responseBody);
        $response->response_status_code = 200;

        $stats = $this->processor->processResponse($response);

        $this->assertEquals(1, $stats['listings_items']);
        $this->assertEquals(1, $stats['listings_inserted']);
        $this->assertEquals(2, $stats['items_processed']);
        $this->assertEquals(2, $stats['items_inserted']);
        $this->assertEquals(2, $stats['total_items']);

        // Verify data was inserted
        $this->assertEquals(1, DB::table($this->listingsTable)->count());
        $this->assertEquals(2, DB::table($this->itemsTable)->count());
    }

    public function test_process_response_with_invalid_json(): void
    {
        $response                = new \stdClass();
        $response->id            = 1;
        $response->response_body = 'invalid json';

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid JSON response or missing tasks array');

        $this->processor->processResponse($response);
    }

    public function test_process_response_with_missing_tasks(): void
    {
        $response                = new \stdClass();
        $response->id            = 1;
        $response->response_body = json_encode(['status' => 'ok']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid JSON response or missing tasks array');

        $this->processor->processResponse($response);
    }

    public function test_process_responses_processes_unprocessed_responses(): void
    {
        // Insert unprocessed responses
        DB::table($this->responsesTable)->insert([
            [
                'client'        => 'dataforseo',
                'key'           => 'unprocessed-key-1',
                'endpoint'      => 'merchant/amazon/products/task_get/advanced/12345678-1234-1234-1234-123456789012',
                'response_body' => json_encode([
                    'tasks' => [
                        [
                            'id'   => 'task-123',
                            'data' => [
                                'keyword'       => 'test product',
                                'se'            => 'amazon',
                                'se_type'       => 'products',
                                'function'      => 'products',
                                'location_code' => 2840,
                                'language_code' => 'en_US',
                                'device'        => 'desktop',
                            ],
                            'result' => [
                                [
                                    'keyword'     => 'test product',
                                    'se_domain'   => 'amazon.com',
                                    'items_count' => 1,
                                    'items'       => [
                                        [
                                            'type'          => 'amazon_serp',
                                            'rank_absolute' => 1,
                                            'data_asin'     => 'B111111111',
                                            'title'         => 'Test Product 1',
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

        $stats = $this->processor->processResponses(10);

        $this->assertEquals(1, $stats['processed_responses']);
        $this->assertEquals(1, $stats['listings_items']);
        $this->assertEquals(1, $stats['items_processed']);
        $this->assertEquals(0, $stats['errors']);

        // Verify response was marked as processed
        $response = DB::table($this->responsesTable)
            ->where('key', 'unprocessed-key-1')
            ->first();
        $this->assertNotNull($response->processed_at);
        $this->assertNotNull($response->processed_status);

        $processedStatus = json_decode($response->processed_status, true);
        $this->assertEquals('OK', $processedStatus['status']);
    }

    public function test_process_responses_skips_already_processed(): void
    {
        // Insert already processed response
        DB::table($this->responsesTable)->insert([
            [
                'client'               => 'dataforseo',
                'key'                  => 'processed-key',
                'endpoint'             => 'merchant/amazon/products/task_get/advanced/33333333-3333-3333-3333-333333333333',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'processed_at'         => now(),
                'processed_status'     => json_encode(['status' => 'OK']),
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
        ]);

        $stats = $this->processor->processResponses(10);

        $this->assertEquals(0, $stats['processed_responses']);
        $this->assertEquals(0, $stats['errors']);
    }

    public function test_process_responses_skips_sandbox_by_default(): void
    {
        // Insert sandbox response
        DB::table($this->responsesTable)->insert([
            [
                'client'        => 'dataforseo',
                'key'           => 'sandbox-key',
                'endpoint'      => 'merchant/amazon/products/task_get/advanced/12345678-1234-1234-1234-123456789012',
                'response_body' => json_encode([
                    'tasks' => [
                        [
                            'id'   => 'task-123',
                            'data' => [
                                'keyword'       => 'test',
                                'se'            => 'amazon',
                                'se_type'       => 'products',
                                'function'      => 'products',
                                'location_code' => 2840,
                                'language_code' => 'en_US',
                                'device'        => 'desktop',
                            ],
                            'result' => [['keyword' => 'test', 'se_domain' => 'amazon.com', 'items' => []]],
                        ],
                    ],
                ]),
                'response_status_code' => 200,
                'base_url'             => 'https://sandbox.dataforseo.com',
                'processed_at'         => null,
                'processed_status'     => null,
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
        ]);

        $stats = $this->processor->processResponses(10);

        // Should skip sandbox response
        $this->assertEquals(0, $stats['processed_responses']);
    }

    public function test_process_responses_includes_sandbox_when_configured(): void
    {
        $this->processor->setSkipSandbox(false);

        // Insert sandbox response
        DB::table($this->responsesTable)->insert([
            [
                'client'        => 'dataforseo',
                'key'           => 'sandbox-key',
                'endpoint'      => 'merchant/amazon/products/task_get/advanced/44444444-4444-4444-4444-444444444444',
                'response_body' => json_encode([
                    'tasks' => [
                        [
                            'id'   => 'task-123',
                            'data' => [
                                'keyword'       => 'test',
                                'se'            => 'amazon',
                                'se_type'       => 'products',
                                'function'      => 'products',
                                'location_code' => 2840,
                                'language_code' => 'en_US',
                                'device'        => 'desktop',
                            ],
                            'result' => [
                                [
                                    'keyword'     => 'test',
                                    'se_domain'   => 'amazon.com',
                                    'items_count' => 1,
                                    'items'       => [
                                        [
                                            'type'          => 'amazon_serp',
                                            'rank_absolute' => 1,
                                            'data_asin'     => 'B111111111',
                                            'title'         => 'Test Product',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]),
                'response_status_code' => 200,
                'base_url'             => 'https://sandbox.dataforseo.com',
                'processed_at'         => null,
                'processed_status'     => null,
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
        ]);

        $stats = $this->processor->processResponses(10);

        // Should process sandbox response
        $this->assertEquals(1, $stats['processed_responses']);
    }

    public function test_process_responses_handles_errors(): void
    {
        // Insert response with invalid JSON
        DB::table($this->responsesTable)->insert([
            [
                'client'               => 'dataforseo',
                'key'                  => 'invalid-key',
                'endpoint'             => 'merchant/amazon/products/task_get/advanced/55555555-5555-5555-5555-555555555555',
                'response_body'        => 'invalid json',
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'processed_at'         => null,
                'processed_status'     => null,
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
        ]);

        $stats = $this->processor->processResponses(10);

        $this->assertEquals(0, $stats['processed_responses']);
        $this->assertEquals(1, $stats['errors']);

        // Verify response was marked with error
        $response = DB::table($this->responsesTable)
            ->where('key', 'invalid-key')
            ->first();
        $this->assertNotNull($response->processed_at);

        $processedStatus = json_decode($response->processed_status, true);
        $this->assertEquals('ERROR', $processedStatus['status']);
        $this->assertNotNull($processedStatus['error']);
    }

    public function test_process_responses_respects_limit(): void
    {
        // Insert 3 unprocessed responses
        for ($i = 1; $i <= 3; $i++) {
            DB::table($this->responsesTable)->insert([
                'client'        => 'dataforseo',
                'key'           => "unprocessed-key-{$i}",
                'endpoint'      => 'merchant/amazon/products/task_get/advanced/' . str_repeat((string)$i, 8) . '-' . str_repeat((string)$i, 4) . '-' . str_repeat((string)$i, 4) . '-' . str_repeat((string)$i, 4) . '-' . str_repeat((string)$i, 12),
                'response_body' => json_encode([
                    'tasks' => [
                        [
                            'id'   => "task-{$i}",
                            'data' => [
                                'keyword'       => "test {$i}",
                                'se'            => 'amazon',
                                'se_type'       => 'products',
                                'function'      => 'products',
                                'location_code' => 2840,
                                'language_code' => 'en_US',
                                'device'        => 'desktop',
                            ],
                            'result' => [
                                [
                                    'keyword'     => "test {$i}",
                                    'se_domain'   => 'amazon.com',
                                    'items_count' => 1,
                                    'items'       => [
                                        [
                                            'type'          => 'amazon_serp',
                                            'rank_absolute' => 1,
                                            'data_asin'     => "B{$i}{$i}{$i}{$i}{$i}{$i}{$i}{$i}{$i}{$i}",
                                            'title'         => "Test Product {$i}",
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

        // Process with limit of 2
        $stats = $this->processor->processResponses(2);

        $this->assertEquals(2, $stats['processed_responses']);

        // Verify only 2 were processed
        $processedCount = DB::table($this->responsesTable)
            ->whereNotNull('processed_at')
            ->count();
        $this->assertEquals(2, $processedCount);
    }

    public function test_process_responses_all_processes_all_available(): void
    {
        // Insert 5 unprocessed responses
        for ($i = 1; $i <= 5; $i++) {
            DB::table($this->responsesTable)->insert([
                'client'        => 'dataforseo',
                'key'           => "unprocessed-key-{$i}",
                'endpoint'      => 'merchant/amazon/products/task_get/advanced/' . str_repeat((string)$i, 8) . '-' . str_repeat((string)$i, 4) . '-' . str_repeat((string)$i, 4) . '-' . str_repeat((string)$i, 4) . '-' . str_repeat((string)$i, 12),
                'response_body' => json_encode([
                    'tasks' => [
                        [
                            'id'   => "task-{$i}",
                            'data' => [
                                'keyword'       => "test {$i}",
                                'se'            => 'amazon',
                                'se_type'       => 'products',
                                'function'      => 'products',
                                'location_code' => 2840,
                                'language_code' => 'en_US',
                                'device'        => 'desktop',
                            ],
                            'result' => [
                                [
                                    'keyword'     => "test {$i}",
                                    'se_domain'   => 'amazon.com',
                                    'items_count' => 1,
                                    'items'       => [
                                        [
                                            'type'          => 'amazon_serp',
                                            'rank_absolute' => 1,
                                            'data_asin'     => "B{$i}{$i}{$i}{$i}{$i}{$i}{$i}{$i}{$i}{$i}",
                                            'title'         => "Test Product {$i}",
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
        $this->assertEquals(5, $stats['listings_items']);
        $this->assertEquals(5, $stats['items_processed']);
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
        // Insert 3 responses with different numbers of items
        DB::table($this->responsesTable)->insert([
            [
                'client'        => 'dataforseo',
                'key'           => 'response-1',
                'endpoint'      => 'merchant/amazon/products/task_get/advanced/66666666-6666-6666-6666-666666666666',
                'response_body' => json_encode([
                    'tasks' => [
                        [
                            'id'   => 'task-1',
                            'data' => [
                                'keyword'       => 'test 1',
                                'se'            => 'amazon',
                                'se_type'       => 'products',
                                'function'      => 'products',
                                'location_code' => 2840,
                                'language_code' => 'en_US',
                                'device'        => 'desktop',
                            ],
                            'result' => [
                                [
                                    'keyword'     => 'test 1',
                                    'se_domain'   => 'amazon.com',
                                    'items_count' => 2,
                                    'items'       => [
                                        [
                                            'type'          => 'amazon_serp',
                                            'rank_absolute' => 1,
                                            'data_asin'     => 'B111111111',
                                            'title'         => 'Product 1',
                                        ],
                                        [
                                            'type'          => 'amazon_serp',
                                            'rank_absolute' => 2,
                                            'data_asin'     => 'B222222222',
                                            'title'         => 'Product 2',
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
                'endpoint'      => 'merchant/amazon/products/task_get/advanced/77777777-7777-7777-7777-777777777777',
                'response_body' => json_encode([
                    'tasks' => [
                        [
                            'id'   => 'task-2',
                            'data' => [
                                'keyword'       => 'test 2',
                                'se'            => 'amazon',
                                'se_type'       => 'products',
                                'function'      => 'products',
                                'location_code' => 2840,
                                'language_code' => 'en_US',
                                'device'        => 'desktop',
                            ],
                            'result' => [
                                [
                                    'keyword'     => 'test 2',
                                    'se_domain'   => 'amazon.com',
                                    'items_count' => 3,
                                    'items'       => [
                                        [
                                            'type'          => 'amazon_serp',
                                            'rank_absolute' => 1,
                                            'data_asin'     => 'B333333333',
                                            'title'         => 'Product 3',
                                        ],
                                        [
                                            'type'          => 'amazon_serp',
                                            'rank_absolute' => 2,
                                            'data_asin'     => 'B444444444',
                                            'title'         => 'Product 4',
                                        ],
                                        [
                                            'type'          => 'amazon_serp',
                                            'rank_absolute' => 3,
                                            'data_asin'     => 'B555555555',
                                            'title'         => 'Product 5',
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
        $this->assertEquals(2, $stats['listings_items']);
        $this->assertEquals(5, $stats['items_processed']); // 2 + 3 = 5 items total
        $this->assertEquals(1, $stats['batches_processed']);
        $this->assertEquals(0, $stats['errors']);

        // Verify all items were inserted
        $this->assertEquals(5, DB::table($this->itemsTable)->count());
    }

    public function test_process_responses_all_handles_errors_and_continues(): void
    {
        // Insert one valid and one invalid response
        DB::table($this->responsesTable)->insert([
            [
                'client'               => 'dataforseo',
                'key'                  => 'invalid-response',
                'endpoint'             => 'merchant/amazon/products/task_get/advanced/88888888-8888-8888-8888-888888888888',
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
                'endpoint'      => 'merchant/amazon/products/task_get/advanced/99999999-9999-9999-9999-999999999999',
                'response_body' => json_encode([
                    'tasks' => [
                        [
                            'id'   => 'task-2',
                            'data' => [
                                'keyword'       => 'test',
                                'se'            => 'amazon',
                                'se_type'       => 'products',
                                'function'      => 'products',
                                'location_code' => 2840,
                                'language_code' => 'en_US',
                                'device'        => 'desktop',
                            ],
                            'result' => [
                                [
                                    'keyword'     => 'test',
                                    'se_domain'   => 'amazon.com',
                                    'items_count' => 1,
                                    'items'       => [
                                        [
                                            'type'          => 'amazon_serp',
                                            'rank_absolute' => 1,
                                            'data_asin'     => 'B111111111',
                                            'title'         => 'Test Product',
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
        $this->assertEquals(1, $stats['errors']); // One error
        $this->assertEquals(1, $stats['batches_processed']);

        // Verify both were marked as processed (one with error, one with success)
        $processedCount = DB::table($this->responsesTable)
            ->whereNotNull('processed_at')
            ->count();
        $this->assertEquals(2, $processedCount);
    }
}

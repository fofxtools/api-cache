<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

/**
 * Processor for DataForSEO Merchant Amazon Products responses
 *
 * Processes unprocessed DataForSEO responses and extracts Amazon product listings
 * and individual product items into dedicated tables.
 */
class DataForSeoMerchantAmazonProductsProcessor
{
    private ApiCacheManager $cacheManager;
    private bool $skipSandbox         = true;
    private bool $updateIfNewer       = true;
    private bool $skipNestedItems     = false;
    private array $endpointsToProcess = [
        'merchant/amazon/products/task_get/advanced/%',
    ];
    private string $listingsTable = 'dataforseo_merchant_amazon_products_listings';
    private string $itemsTable    = 'dataforseo_merchant_amazon_products_items';

    /**
     * Constructor
     *
     * @param ApiCacheManager|null $cacheManager Optional cache manager instance
     */
    public function __construct(?ApiCacheManager $cacheManager = null)
    {
        $this->cacheManager = resolve_cache_manager($cacheManager);
    }

    /**
     * Get skipSandbox setting
     *
     * @return bool The skipSandbox setting
     */
    public function getSkipSandbox(): bool
    {
        return $this->skipSandbox;
    }

    /**
     * Set skipSandbox setting
     *
     * @param bool $value The skipSandbox setting
     *
     * @return void
     */
    public function setSkipSandbox(bool $value): void
    {
        $this->skipSandbox = $value;
    }

    /**
     * Get updateIfNewer setting
     *
     * @return bool The updateIfNewer setting
     */
    public function getUpdateIfNewer(): bool
    {
        return $this->updateIfNewer;
    }

    /**
     * Set updateIfNewer setting
     *
     * @param bool $value The updateIfNewer setting
     *
     * @return void
     */
    public function setUpdateIfNewer(bool $value): void
    {
        $this->updateIfNewer = $value;
    }

    /**
     * Get skipNestedItems setting
     *
     * @return bool The skipNestedItems setting
     */
    public function getSkipNestedItems(): bool
    {
        return $this->skipNestedItems;
    }

    /**
     * Set skipNestedItems setting
     *
     * @param bool $value The skipNestedItems setting
     *
     * @return void
     */
    public function setSkipNestedItems(bool $value): void
    {
        $this->skipNestedItems = $value;
    }

    /**
     * Get the responses table name (handles compression)
     *
     * @return string The table name
     */
    public function getResponsesTableName(): string
    {
        return $this->cacheManager->getTableName('dataforseo');
    }

    /**
     * Reset processed status for responses
     *
     * @return void
     */
    public function resetProcessed(): void
    {
        $tableName = $this->getResponsesTableName();

        $query = DB::table($tableName);

        // Filter by endpoints
        $query->where(function ($q) {
            foreach ($this->endpointsToProcess as $endpoint) {
                $q->orWhere('endpoint', 'like', $endpoint);
            }
        });

        $updated = $query->update([
            'processed_at'     => null,
            'processed_status' => null,
        ]);

        Log::info('Reset processed status for DataForSEO Merchant Amazon Products responses', [
            'updated_count' => $updated,
        ]);
    }

    /**
     * Clear processed items from database tables
     *
     * @param bool $withCount Whether to count rows before clearing (default: false)
     *
     * @return array Statistics about cleared records, with null values if counting was skipped
     */
    public function clearProcessedTables(bool $withCount = false): array
    {
        $stats = [
            'listings_cleared' => $withCount ? DB::table($this->listingsTable)->count() : null,
            'items_cleared'    => $withCount ? DB::table($this->itemsTable)->count() : null,
        ];

        DB::table($this->listingsTable)->truncate();
        DB::table($this->itemsTable)->truncate();

        Log::info('Cleared DataForSEO Merchant Amazon Products processed tables', [
            'listings_cleared' => $withCount ? $stats['listings_cleared'] : 'not counted',
            'items_cleared'    => $withCount ? $stats['items_cleared'] : 'not counted',
            'with_count'       => $withCount,
        ]);

        return $stats;
    }

    /**
     * Extract metadata from result level (for task_get responses)
     *
     * @param array $result The result data
     *
     * @return array The extracted result metadata
     */
    public function extractMetadata(array $result): array
    {
        return [
            'keyword'       => $result['keyword'] ?? null,
            'se_domain'     => $result['se_domain'] ?? null,
            'location_code' => $result['location_code'] ?? null,
            'language_code' => $result['language_code'] ?? null,
            'device'        => $result['device'] ?? null,
            'os'            => $result['os'] ?? null,
        ];
    }

    /**
     * Extract listings-specific metadata from task data
     *
     * @param array $taskData The task data
     *
     * @return array The extracted listings-specific fields
     */
    public function extractListingsTaskMetadata(array $taskData): array
    {
        return [
            'se'       => $taskData['se'] ?? null,
            'se_type'  => $taskData['se_type'] ?? null,
            'function' => $taskData['function'] ?? null,
            'tag'      => $taskData['tag'] ?? null,
        ];
    }

    /**
     * Ensure required fields have defaults
     *
     * @param array $data The data to check
     *
     * @return array The data with defaults applied
     */
    public function ensureDefaults(array $data): array
    {
        // Provide default for device field, others should remain null if not present
        $data['device'] = $data['device'] ?? 'desktop';

        return $data;
    }

    /**
     * Batch insert or update listings items.
     *
     * @param array $listingsItems The listings items to process
     *
     * @return array Statistics about insert/update operations
     */
    public function batchInsertOrUpdateListings(array $listingsItems): array
    {
        $stats = [
            'items_inserted' => 0,
            'items_updated'  => 0,
            'items_skipped'  => 0,
        ];

        if (!$this->updateIfNewer) {
            // Fast path: accept only brand-new rows, silently skip duplicates
            foreach (array_chunk($listingsItems, 100) as $chunk) {
                $inserted = DB::table($this->listingsTable)->insertOrIgnore($chunk);
                $stats['items_inserted'] += $inserted;
                $stats['items_skipped'] += count($chunk) - $inserted;
            }

            return $stats;
        }

        foreach ($listingsItems as $row) {
            $where = [
                'keyword'       => $row['keyword'],
                'location_code' => $row['location_code'],
                'language_code' => $row['language_code'],
                'device'        => $row['device'],
            ];

            $existingCreatedAt = DB::table($this->listingsTable)
                ->where($where)
                ->value('created_at');

            if ($existingCreatedAt === null) {
                // No clash – insert fresh
                DB::table($this->listingsTable)->insert($row);
                $stats['items_inserted']++;

                continue;
            }

            // Only update if the new row is more recent
            if (Carbon::parse($row['created_at'])
                ->gte(Carbon::parse($existingCreatedAt))) {
                DB::table($this->listingsTable)
                    ->where($where)
                    ->update($row);
                $stats['items_updated']++;
            } else {
                $stats['items_skipped']++;
            }
        }

        return $stats;
    }

    /**
     * Batch insert or update items.
     *
     * @param array $items The items to process
     *
     * @return array Statistics about insert/update operations
     */
    public function batchInsertOrUpdateItems(array $items): array
    {
        $stats = [
            'items_inserted' => 0,
            'items_updated'  => 0,
            'items_skipped'  => 0,
        ];

        if (!$this->updateIfNewer) {
            // Fast path: accept only brand-new rows, silently skip duplicates
            foreach (array_chunk($items, 100) as $chunk) {
                $inserted = DB::table($this->itemsTable)->insertOrIgnore($chunk);
                $stats['items_inserted'] += $inserted;
                $stats['items_skipped'] += count($chunk) - $inserted;
            }

            return $stats;
        }

        foreach ($items as $row) {
            $where = [
                'keyword'       => $row['keyword'],
                'location_code' => $row['location_code'],
                'language_code' => $row['language_code'],
                'device'        => $row['device'],
                'items_type'    => $row['items_type'],
                'rank_absolute' => $row['rank_absolute'],
            ];

            $existingCreatedAt = DB::table($this->itemsTable)
                ->where($where)
                ->value('created_at');

            if ($existingCreatedAt === null) {
                // No clash – insert fresh
                DB::table($this->itemsTable)->insert($row);
                $stats['items_inserted']++;

                continue;
            }

            // Only update if the new row is more recent
            if (Carbon::parse($row['created_at'])
                ->gte(Carbon::parse($existingCreatedAt))) {
                DB::table($this->itemsTable)
                    ->where($where)
                    ->update($row);
                $stats['items_updated']++;
            } else {
                $stats['items_skipped']++;
            }
        }

        return $stats;
    }

    /**
     * Process listings data from response
     *
     * @param array $result           The result data containing listings information
     * @param array $listingsTaskData The task data including listings-specific fields
     *
     * @return array Statistics about processing including item count and insert/update details
     */
    public function processListings(array $result, array $listingsTaskData): array
    {
        $listingsItems = [];
        $now           = now();

        // Extract listings-level data from result and merge with task data
        $listingsData = array_merge($listingsTaskData, [
            'result_keyword'  => $result['keyword'] ?? null,
            'type'            => $result['type'] ?? null,
            'se_domain'       => $result['se_domain'] ?? null,
            'check_url'       => $result['check_url'] ?? null,
            'result_datetime' => $result['datetime'] ?? null,
            'spell'           => !empty($result['spell'])
                ? json_encode($result['spell'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null,
            'item_types' => !empty($result['item_types'])
                ? json_encode($result['item_types'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null,
            'se_results_count' => $result['se_results_count'] ?? null,
            'categories'       => !empty($result['categories'])
                ? json_encode($result['categories'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null,
            'items_count' => $result['items_count'] ?? null,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        // Ensure defaults are applied and add to listings items for batch processing
        $listingsItems[] = $this->ensureDefaults($listingsData);

        // Batch process listings items and get detailed stats
        $batchStats = $this->batchInsertOrUpdateListings($listingsItems);

        return [
            'listings_items' => count($listingsItems),
            'items_inserted' => $batchStats['items_inserted'],
            'items_updated'  => $batchStats['items_updated'],
            'items_skipped'  => $batchStats['items_skipped'],
        ];
    }

    /**
     * Process items from response
     *
     * @param array $items          The items to process
     * @param array $mergedTaskData The task data merged with result-level metadata
     *
     * @return array Statistics about processing including item count and insert/update details
     */
    public function processItems(array $items, array $mergedTaskData): array
    {
        $processedItems = [];
        $now            = now();

        foreach ($items as $item) {
            // Skip items without data_asin (like related_searches items)
            if (empty($item['data_asin'])) {
                continue;
            }

            // Skip items that have nested items if we're processing those separately
            if (isset($item['items']) && !$this->skipNestedItems) {
                // Process nested items recursively
                $nestedStats = $this->processItems($item['items'], $mergedTaskData);
                // Note: We don't accumulate nested stats in this implementation
                // as they would be processed as separate items
            }

            $itemData = array_merge($mergedTaskData, [
                'result_keyword'    => $mergedTaskData['keyword'] ?? null, // Set result_keyword from merged keyword
                'items_type'        => $item['type'] ?? null,
                'rank_group'        => $item['rank_group'] ?? null,
                'rank_absolute'     => $item['rank_absolute'] ?? null,
                'xpath'             => $item['xpath'] ?? null,
                'domain'            => $item['domain'] ?? null,
                'title'             => $item['title'] ?? null,
                'url'               => $item['url'] ?? null,
                'image_url'         => $item['image_url'] ?? null,
                'bought_past_month' => $item['bought_past_month'] ?? null,
                'price_from'        => $item['price_from'] ?? null,
                'price_to'          => $item['price_to'] ?? null,
                'currency'          => $item['currency'] ?? null,
                'special_offers'    => !empty($item['special_offers'])
                    ? json_encode($item['special_offers'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
                'data_asin' => $item['data_asin'] ?? null,
                // Flatten rating object
                'rating_type'        => $item['rating']['type'] ?? null,
                'rating_position'    => $item['rating']['position'] ?? null,
                'rating_rating_type' => $item['rating']['rating_type'] ?? null,
                'rating_value'       => $item['rating']['value'] ?? null,
                'rating_votes_count' => $item['rating']['votes_count'] ?? null,
                'rating_rating_max'  => $item['rating']['rating_max'] ?? null,
                'is_amazon_choice'   => $item['is_amazon_choice'] ?? null,
                'is_best_seller'     => $item['is_best_seller'] ?? null,
                'delivery_info'      => !empty($item['delivery_info'])
                    ? json_encode($item['delivery_info'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
                'nested_items' => (!$this->skipNestedItems && !empty($item['items']))
                    ? json_encode($item['items'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $processedItems[] = $itemData;
        }

        // Batch process items and get detailed stats
        $batchStats = $this->batchInsertOrUpdateItems($processedItems);

        return [
            'items_processed' => count($processedItems),
            'items_inserted'  => $batchStats['items_inserted'],
            'items_updated'   => $batchStats['items_updated'],
            'items_skipped'   => $batchStats['items_skipped'],
        ];
    }

    /**
     * Process a single response
     *
     * @param object $response The response to process
     *
     * @throws \Exception If response is invalid
     *
     * @return array Statistics about processing
     */
    public function processResponse($response): array
    {
        $responseBody = json_decode($response->response_body, true);
        if (!$responseBody || !isset($responseBody['tasks'])) {
            throw new \Exception('Invalid JSON response or missing tasks array');
        }

        $stats = [
            'listings_items'    => 0,
            'listings_inserted' => 0,
            'listings_updated'  => 0,
            'listings_skipped'  => 0,
            'items_processed'   => 0,
            'items_inserted'    => 0,
            'items_updated'     => 0,
            'items_skipped'     => 0,
            'total_items'       => 0,
        ];

        foreach ($responseBody['tasks'] as $task) {
            if (!isset($task['result'])) {
                continue;
            }

            // Extract common task-level fields (keyword, location_code, etc.)
            // And use as base for adding processing metadata
            $baseTaskData                = $this->extractMetadata($task['data'] ?? []);
            $baseTaskData['task_id']     = $task['id'] ?? null;
            $baseTaskData['response_id'] = $response->id;

            foreach ($task['result'] as $result) {
                // For listings: add listings-specific fields (se, se_type, function, tag)
                $listingsTaskData = array_merge($baseTaskData, $this->extractListingsTaskMetadata($task['data']));

                // Process listings data and get detailed stats
                $listingsStats = $this->processListings($result, $listingsTaskData);
                $stats['listings_items'] += $listingsStats['listings_items'];
                $stats['listings_inserted'] += $listingsStats['items_inserted'];
                $stats['listings_updated'] += $listingsStats['items_updated'];
                $stats['listings_skipped'] += $listingsStats['items_skipped'];

                if (!isset($result['items'])) {
                    continue;
                }

                // Count total items available for processing
                $stats['total_items'] += count($result['items']);

                // For items: merge with result-level metadata
                $mergedTaskData = array_merge($baseTaskData, $this->extractMetadata($result));
                $mergedTaskData = $this->ensureDefaults($mergedTaskData);

                // Process items and get detailed stats
                $itemsStats = $this->processItems($result['items'], $mergedTaskData);
                $stats['items_processed'] += $itemsStats['items_processed'];
                $stats['items_inserted'] += $itemsStats['items_inserted'];
                $stats['items_updated'] += $itemsStats['items_updated'];
                $stats['items_skipped'] += $itemsStats['items_skipped'];
            }
        }

        return $stats;
    }

    /**
     * Process unprocessed DataForSEO Merchant Amazon Products responses
     *
     * @param int $limit Maximum number of responses to process (default: 100)
     *
     * @return array Statistics about processing
     */
    public function processResponses(int $limit = 100): array
    {
        $tableName = $this->getResponsesTableName();

        // Build query for unprocessed responses
        $query = DB::table($tableName)
            ->whereNull('processed_at')
            ->where('response_status_code', 200)
            ->select('id', 'key', 'endpoint', 'response_body', 'base_url', 'created_at');

        // Filter by endpoints
        $query->where(function ($q) {
            foreach ($this->endpointsToProcess as $endpoint) {
                $q->orWhere('endpoint', 'like', $endpoint);
            }
        });

        // Skip sandbox if configured
        if ($this->skipSandbox) {
            $query->where('base_url', 'not like', 'https://sandbox.%')
                  ->where('base_url', 'not like', 'http://sandbox.%');
        }

        $query->limit($limit);
        $responses = $query->get();

        Log::debug('Processing DataForSEO Merchant Amazon Products responses', [
            'count' => $responses->count(),
            'limit' => $limit,
        ]);

        $stats = [
            'processed_responses' => 0,
            'listings_items'      => 0,
            'listings_inserted'   => 0,
            'listings_updated'    => 0,
            'listings_skipped'    => 0,
            'items_processed'     => 0,
            'items_inserted'      => 0,
            'items_updated'       => 0,
            'items_skipped'       => 0,
            'total_items'         => 0,
            'errors'              => 0,
        ];

        foreach ($responses as $response) {
            try {
                // Wrap each response processing in a transaction
                DB::transaction(function () use ($response, &$stats, $tableName) {
                    $responseStats = $this->processResponse($response);
                    $stats['listings_items'] += $responseStats['listings_items'];
                    $stats['listings_inserted'] += $responseStats['listings_inserted'];
                    $stats['listings_updated'] += $responseStats['listings_updated'];
                    $stats['listings_skipped'] += $responseStats['listings_skipped'];
                    $stats['items_processed'] += $responseStats['items_processed'];
                    $stats['items_inserted'] += $responseStats['items_inserted'];
                    $stats['items_updated'] += $responseStats['items_updated'];
                    $stats['items_skipped'] += $responseStats['items_skipped'];
                    $stats['total_items'] += $responseStats['total_items'];
                    $stats['processed_responses']++;

                    // Mark response as processed
                    DB::table($tableName)
                        ->where('id', $response->id)
                        ->update([
                            'processed_at'     => now(),
                            'processed_status' => json_encode([
                                'status'            => 'OK',
                                'error'             => null,
                                'listings_items'    => $responseStats['listings_items'],
                                'listings_inserted' => $responseStats['listings_inserted'],
                                'listings_updated'  => $responseStats['listings_updated'],
                                'listings_skipped'  => $responseStats['listings_skipped'],
                                'items_processed'   => $responseStats['items_processed'],
                                'items_inserted'    => $responseStats['items_inserted'],
                                'items_updated'     => $responseStats['items_updated'],
                                'items_skipped'     => $responseStats['items_skipped'],
                                'total_items'       => $responseStats['total_items'],
                            ], JSON_PRETTY_PRINT),
                        ]);
                });
            } catch (\Exception $e) {
                Log::error('Failed to process DataForSEO Merchant Amazon Products response', [
                    'response_id' => $response->id,
                    'error'       => $e->getMessage(),
                ]);

                $stats['errors']++;

                // Mark response with error (outside transaction since it failed)
                DB::table($tableName)
                    ->where('id', $response->id)
                    ->update([
                        'processed_at'     => now(),
                        'processed_status' => json_encode([
                            'status'            => 'ERROR',
                            'error'             => $e->getMessage(),
                            'listings_items'    => 0,
                            'listings_inserted' => 0,
                            'listings_updated'  => 0,
                            'listings_skipped'  => 0,
                            'items_processed'   => 0,
                            'items_inserted'    => 0,
                            'items_updated'     => 0,
                            'items_skipped'     => 0,
                            'total_items'       => 0,
                        ], JSON_PRETTY_PRINT),
                    ]);
            }
        }

        Log::debug('DataForSEO Merchant Amazon Products processing completed', $stats);

        return $stats;
    }
}

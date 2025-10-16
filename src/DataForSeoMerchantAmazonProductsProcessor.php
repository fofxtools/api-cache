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
        'merchant/amazon/products/task_get/advanced%',
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
     * Extract task data from tasks.data (request parameters)
     *
     * @param array $taskData The task data from tasks.data
     *
     * @return array The extracted task-specific fields
     */
    public function extractTaskData(array $taskData): array
    {
        return [
            'keyword'       => $taskData['keyword'] ?? null,
            'se'            => $taskData['se'] ?? null,
            'se_type'       => $taskData['se_type'] ?? null,
            'function'      => $taskData['function'] ?? null,
            'location_code' => $taskData['location_code'] ?? null,
            'language_code' => $taskData['language_code'] ?? null,
            'device'        => $taskData['device'] ?? null,
            'os'            => $taskData['os'] ?? null,
            'tag'           => $taskData['tag'] ?? null,
        ];
    }

    /**
     * Extract result metadata from tasks.result (response metadata)
     *
     * @param array $result The result data from tasks.result
     *
     * @return array The extracted result-specific fields
     */
    public function extractResultMetadata(array $result): array
    {
        return [
            'result_keyword'   => $result['keyword'] ?? null,
            'type'             => $result['type'] ?? null,
            'se_domain'        => $result['se_domain'] ?? null,
            'check_url'        => $result['check_url'] ?? null,
            'result_datetime'  => $result['datetime'] ?? null,
            'spell'            => $result['spell'] ?? null,
            'item_types'       => $result['item_types'] ?? null,
            'se_results_count' => $result['se_results_count'] ?? null,
            'categories'       => $result['categories'] ?? null,
            'items_count'      => $result['items_count'] ?? null,
        ];
    }

    /**
     * Extract data for products items (filters merged data to only include fields that products items table expects)
     *
     * @param array $mergedData The merged task and result data
     *
     * @return array The filtered data for products items processing
     */
    public function extractProductsItemsData(array $mergedData): array
    {
        return [
            'response_id'    => $mergedData['response_id'] ?? null,
            'task_id'        => $mergedData['task_id'] ?? null,
            'keyword'        => $mergedData['keyword'] ?? null,
            'location_code'  => $mergedData['location_code'] ?? null,
            'language_code'  => $mergedData['language_code'] ?? null,
            'device'         => $mergedData['device'] ?? null,
            'os'             => $mergedData['os'] ?? null,
            'tag'            => $mergedData['tag'] ?? null,
            'result_keyword' => $mergedData['result_keyword'] ?? null,
            'se_domain'      => $mergedData['se_domain'] ?? null,
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
     * @param array $result       The result data containing listings information
     * @param array $listingsData The merged task data and result metadata
     *
     * @return array Statistics about processing including item count and insert/update details
     */
    public function processListings(array $result, array $listingsData): array
    {
        $listingsItems = [];
        $now           = now();

        // Override fields that need special processing (JSON formatting) and add additional fields
        $listingsData = array_merge($listingsData, [
            'spell' => !empty($result['spell'])
                ? json_encode($result['spell'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null,
            'item_types' => !empty($result['item_types'])
                ? json_encode($result['item_types'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null,
            'categories' => !empty($result['categories'])
                ? json_encode($result['categories'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null,
            'created_at' => $now,
            'updated_at' => $now,
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
     * @param array $items      The items to process
     * @param array $mergedData The task data merged with result-level metadata
     *
     * @return array Statistics about processing including item count and insert/update details
     */
    public function processItems(array $items, array $mergedData): array
    {
        $processedItems = [];
        $now            = now();

        foreach ($items as $item) {
            // Skip items without data_asin (like related_searches items)
            if (empty($item['data_asin'])) {
                continue;
            }

            $itemData = array_merge($mergedData, [
                'result_keyword'    => $mergedData['result_keyword'] ?? null,
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

            // Extract task data from tasks.data (request parameters) and use as base for adding processing metadata
            $baseTaskData                = $this->extractTaskData($task['data'] ?? []);
            $baseTaskData['task_id']     = $task['id'] ?? null;
            $baseTaskData['response_id'] = $response->id;

            foreach ($task['result'] as $result) {
                // Merge task data with result metadata from tasks.result
                $mergedData = array_merge($baseTaskData, $this->extractResultMetadata($result));
                $mergedData = $this->ensureDefaults($mergedData);

                // Process listings data and get detailed stats
                $listingsStats = $this->processListings($result, $mergedData);
                $stats['listings_items'] += $listingsStats['listings_items'];
                $stats['listings_inserted'] += $listingsStats['items_inserted'];
                $stats['listings_updated'] += $listingsStats['items_updated'];
                $stats['listings_skipped'] += $listingsStats['items_skipped'];

                if (!isset($result['items'])) {
                    continue;
                }

                // Count total items available for processing
                $stats['total_items'] += count($result['items']);

                // Filter merged data for products items (only fields that products items table expects)
                $itemsData = $this->extractProductsItemsData($mergedData);

                // Process items and get detailed stats
                $itemsStats = $this->processItems($result['items'], $itemsData);
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
            $query->where(function ($q) {
                $q->whereNull('base_url')
                  ->orWhere(function ($q2) {
                      $q2->where('base_url', 'not like', 'https://sandbox.%')
                         ->where('base_url', 'not like', 'http://sandbox.%');
                  });
            });
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

    /**
     * Process all available unprocessed DataForSEO Merchant Amazon Products responses
     *
     * Loops over all available responses by repeatedly calling processResponses()
     * until no more unprocessed responses are found.
     *
     * @param int $batchSize Number of responses to process per batch (default: 100)
     *
     * @return array Cumulative statistics about all processing
     */
    public function processResponsesAll(int $batchSize = 100): array
    {
        $cumulativeStats = [
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
            'batches_processed'   => 0,
        ];

        Log::debug('Starting processResponsesAll for DataForSEO Merchant Amazon Products', [
            'batch_size' => $batchSize,
        ]);

        while (true) {
            $batchStats = $this->processResponses($batchSize);

            // Stop if no responses were processed in this batch
            if ($batchStats['processed_responses'] === 0) {
                break;
            }

            // Accumulate stats
            $cumulativeStats['processed_responses'] += $batchStats['processed_responses'];
            $cumulativeStats['listings_items'] += $batchStats['listings_items'];
            $cumulativeStats['listings_inserted'] += $batchStats['listings_inserted'];
            $cumulativeStats['listings_updated'] += $batchStats['listings_updated'];
            $cumulativeStats['listings_skipped'] += $batchStats['listings_skipped'];
            $cumulativeStats['items_processed'] += $batchStats['items_processed'];
            $cumulativeStats['items_inserted'] += $batchStats['items_inserted'];
            $cumulativeStats['items_updated'] += $batchStats['items_updated'];
            $cumulativeStats['items_skipped'] += $batchStats['items_skipped'];
            $cumulativeStats['total_items'] += $batchStats['total_items'];
            $cumulativeStats['errors'] += $batchStats['errors'];
            $cumulativeStats['batches_processed']++;

            Log::debug('Processed batch of DataForSEO Merchant Amazon Products responses', [
                'batch_number'         => $cumulativeStats['batches_processed'],
                'batch_responses'      => $batchStats['processed_responses'],
                'cumulative_responses' => $cumulativeStats['processed_responses'],
            ]);
        }

        Log::debug('Completed processResponsesAll for DataForSEO Merchant Amazon Products', $cumulativeStats);

        return $cumulativeStats;
    }
}

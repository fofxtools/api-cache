<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

/**
 * Processor for DataForSEO Merchant Amazon ASIN responses
 *
 * Processes unprocessed DataForSEO responses and extracts Amazon ASIN product data
 * into the dedicated dataforseo_merchant_amazon_asins table.
 */
class DataForSeoMerchantAmazonAsinProcessor
{
    private ApiCacheManager $cacheManager;
    private bool $skipSandbox            = true;
    private bool $updateIfNewer          = true;
    private bool $skipReviews            = false;
    private bool $skipProductInformation = false;
    private array $endpointsToProcess    = [
        'merchant/amazon/asin/task_get/advanced/%',
    ];
    private string $table = 'dataforseo_merchant_amazon_asins';

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
     * Get skipReviews setting
     *
     * @return bool The skipReviews setting
     */
    public function getSkipReviews(): bool
    {
        return $this->skipReviews;
    }

    /**
     * Set skipReviews setting
     *
     * @param bool $value The skipReviews setting
     *
     * @return void
     */
    public function setSkipReviews(bool $value): void
    {
        $this->skipReviews = $value;
    }

    /**
     * Get skipProductInformation setting
     *
     * @return bool The skipProductInformation setting
     */
    public function getSkipProductInformation(): bool
    {
        return $this->skipProductInformation;
    }

    /**
     * Set skipProductInformation setting
     *
     * @param bool $value The skipProductInformation setting
     *
     * @return void
     */
    public function setSkipProductInformation(bool $value): void
    {
        $this->skipProductInformation = $value;
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

        Log::info('Reset processed status for DataForSEO Merchant Amazon ASIN responses', [
            'updated_count' => $updated,
        ]);
    }

    /**
     * Clear processed items from database table
     *
     * @param bool $withCount Whether to count rows before clearing (default: false)
     *
     * @return array Statistics about cleared records, with null values if counting was skipped
     */
    public function clearProcessedTables(bool $withCount = false): array
    {
        $stats = [
            'items_cleared' => $withCount ? DB::table($this->table)->count() : null,
        ];

        DB::table($this->table)->truncate();

        Log::info('Cleared DataForSEO Merchant Amazon ASIN processed table', [
            'items_cleared' => $withCount ? $stats['items_cleared'] : 'not counted',
            'with_count'    => $withCount,
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
            'asin'                    => $taskData['asin'] ?? null, // User request ASIN
            'se'                      => $taskData['se'] ?? null,
            'se_type'                 => $taskData['se_type'] ?? null,
            'location_code'           => $taskData['location_code'] ?? null,
            'language_code'           => $taskData['language_code'] ?? null,
            'device'                  => $taskData['device'] ?? null,
            'os'                      => $taskData['os'] ?? null,
            'load_more_local_reviews' => $taskData['load_more_local_reviews'] ?? null,
            'local_reviews_sort'      => $taskData['local_reviews_sort'] ?? null,
            'tag'                     => $taskData['tag'] ?? null,
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
            'result_asin'     => $result['asin'] ?? null, // Response ASIN
            'se_domain'       => $result['se_domain'] ?? null,
            'type'            => $result['type'] ?? null,
            'check_url'       => $result['check_url'] ?? null,
            'result_datetime' => $result['datetime'] ?? null,
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
     * Batch insert or update items.
     *
     * @param array $items The items to process
     *
     * @return array Statistics about insert/update operations
     */
    public function batchInsertOrUpdate(array $items): array
    {
        $stats = [
            'items_inserted' => 0,
            'items_updated'  => 0,
            'items_skipped'  => 0,
        ];

        if (!$this->updateIfNewer) {
            // Fast path: accept only brand-new rows, silently skip duplicates
            foreach (array_chunk($items, 100) as $chunk) {
                $inserted = DB::table($this->table)->insertOrIgnore($chunk);
                $stats['items_inserted'] += $inserted;
                $stats['items_skipped'] += count($chunk) - $inserted;
            }

            return $stats;
        }

        foreach ($items as $row) {
            $where = [
                'asin'          => $row['asin'],
                'location_code' => $row['location_code'],
                'language_code' => $row['language_code'],
                'device'        => $row['device'],
                'data_asin'     => $row['data_asin'],
            ];

            $existingCreatedAt = DB::table($this->table)
                ->where($where)
                ->value('created_at');

            if ($existingCreatedAt === null) {
                // No clash – insert fresh
                DB::table($this->table)->insert($row);
                $stats['items_inserted']++;

                continue;
            }

            // Only update if the new row is more recent
            if (Carbon::parse($row['created_at'])
                ->gte(Carbon::parse($existingCreatedAt))) {
                DB::table($this->table)
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
     * Process items from response
     *
     * @param array $items      The items to process
     * @param array $mergedData The merged task and result data
     *
     * @return array Statistics about processing including item count and insert/update details
     */
    public function processItems(array $items, array $mergedData): array
    {
        $processedItems = [];
        $now            = now();

        foreach ($items as $item) {
            $itemData = array_merge($mergedData, [
                'items_type'    => $item['type'] ?? null,
                'rank_group'    => $item['rank_group'] ?? null,
                'rank_absolute' => $item['rank_absolute'] ?? null,
                'position'      => $item['position'] ?? null,
                'xpath'         => $item['xpath'] ?? null,
                'title'         => $item['title'] ?? null,
                'details'       => $item['details'] ?? null,
                'image_url'     => $item['image_url'] ?? null,
                'author'        => $item['author'] ?? null,
                'data_asin'     => $item['data_asin'] ?? null,
                'parent_asin'   => $item['parent_asin'] ?? null,
                'product_asins' => !empty($item['product_asins'])
                    ? json_encode($item['product_asins'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
                'price_from'       => $item['price_from'] ?? null,
                'price_to'         => $item['price_to'] ?? null,
                'currency'         => $item['currency'] ?? null,
                'is_amazon_choice' => $item['is_amazon_choice'] ?? null,
                // Flatten rating object
                'rating_type'              => $item['rating']['type'] ?? null,
                'rating_position'          => $item['rating']['position'] ?? null,
                'rating_rating_type'       => $item['rating']['rating_type'] ?? null,
                'rating_value'             => $item['rating']['value'] ?? null,
                'rating_votes_count'       => $item['rating']['votes_count'] ?? null,
                'rating_rating_max'        => $item['rating']['rating_max'] ?? null,
                'is_newer_model_available' => $item['is_newer_model_available'] ?? null,
                'applicable_vouchers'      => !empty($item['applicable_vouchers'])
                    ? json_encode($item['applicable_vouchers'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
                'newer_model' => !empty($item['newer_model'])
                    ? json_encode($item['newer_model'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
                'categories' => !empty($item['categories'])
                    ? json_encode($item['categories'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
                'product_information' => (!$this->skipProductInformation && !empty($item['product_information']))
                    ? json_encode($item['product_information'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
                'product_images_list' => !empty($item['product_images_list'])
                    ? json_encode($item['product_images_list'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
                'product_videos_list' => !empty($item['product_videos_list'])
                    ? json_encode($item['product_videos_list'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
                'description' => !empty($item['description'])
                    ? json_encode($item['description'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : $item['description'] ?? null,
                'is_available'      => $item['is_available'] ?? null,
                'top_local_reviews' => (!$this->skipReviews && !empty($item['top_local_reviews']))
                    ? json_encode($item['top_local_reviews'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
                'top_global_reviews' => (!$this->skipReviews && !empty($item['top_global_reviews']))
                    ? json_encode($item['top_global_reviews'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $processedItems[] = $itemData;
        }

        // Batch process items and get detailed stats
        $batchStats = $this->batchInsertOrUpdate($processedItems);

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
            'items_processed' => 0,
            'items_inserted'  => 0,
            'items_updated'   => 0,
            'items_skipped'   => 0,
            'total_items'     => 0,
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
                $resultMetadata = $this->extractResultMetadata($result);
                $mergedData     = array_merge($baseTaskData, $resultMetadata);
                $mergedData     = $this->ensureDefaults($mergedData);

                // Add additional result-level fields that need JSON pretty-printing
                $mergedData['spell'] = !empty($result['spell'])
                    ? json_encode($result['spell'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null;
                $mergedData['item_types'] = !empty($result['item_types'])
                    ? json_encode($result['item_types'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null;
                $mergedData['items_count'] = $result['items_count'] ?? null;

                if (!isset($result['items'])) {
                    continue;
                }

                // Count total items available for processing
                $stats['total_items'] += count($result['items']);

                // Process items and get detailed stats
                $itemsStats = $this->processItems($result['items'], $mergedData);
                $stats['items_processed'] += $itemsStats['items_processed'];
                $stats['items_inserted'] += $itemsStats['items_inserted'];
                $stats['items_updated'] += $itemsStats['items_updated'];
                $stats['items_skipped'] += $itemsStats['items_skipped'];
            }
        }

        return $stats;
    }

    /**
     * Process unprocessed DataForSEO Merchant Amazon ASIN responses
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

        Log::debug('Processing DataForSEO Merchant Amazon ASIN responses', [
            'count' => $responses->count(),
            'limit' => $limit,
        ]);

        $stats = [
            'processed_responses' => 0,
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
                                'status'          => 'OK',
                                'error'           => null,
                                'items_processed' => $responseStats['items_processed'],
                                'items_inserted'  => $responseStats['items_inserted'],
                                'items_updated'   => $responseStats['items_updated'],
                                'items_skipped'   => $responseStats['items_skipped'],
                                'total_items'     => $responseStats['total_items'],
                            ], JSON_PRETTY_PRINT),
                        ]);
                });
            } catch (\Exception $e) {
                Log::error('Failed to process DataForSEO Merchant Amazon ASIN response', [
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
                            'status'          => 'ERROR',
                            'error'           => $e->getMessage(),
                            'items_processed' => 0,
                            'items_inserted'  => 0,
                            'items_updated'   => 0,
                            'items_skipped'   => 0,
                            'total_items'     => 0,
                        ], JSON_PRETTY_PRINT),
                    ]);
            }
        }

        Log::debug('DataForSEO Merchant Amazon ASIN processing completed', $stats);

        return $stats;
    }
}

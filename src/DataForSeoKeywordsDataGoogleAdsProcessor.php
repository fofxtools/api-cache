<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

/**
 * Processor for DataForSEO Keywords Data Google Ads responses
 *
 * Processes unprocessed DataForSEO Keywords Data Google Ads responses and extracts items
 * into dedicated table.
 */
class DataForSeoKeywordsDataGoogleAdsProcessor
{
    // Default values for worldwide data (when location/language not specified)
    public const WORLDWIDE_LOCATION_CODE = 0;      // 0 = worldwide/all locations
    public const WORLDWIDE_LANGUAGE_CODE = 'none'; // 'none' = worldwide (no specific language)

    private ApiCacheManager $cacheManager;
    private bool $skipSandbox         = true;
    private bool $skipMonthlySearches = false;
    private bool $updateIfNewer       = true;
    private array $endpointsToProcess = [
        'keywords_data/google_ads/search_volume/task_get',
        'keywords_data/google_ads/search_volume/live',
        'keywords_data/google_ads/keywords_for_site/task_get',
        'keywords_data/google_ads/keywords_for_site/live',
        'keywords_data/google_ads/keywords_for_keywords/task_get',
        'keywords_data/google_ads/keywords_for_keywords/live',
    ];
    private string $itemsTable = 'dataforseo_keywords_data_google_ads_items';

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
     * Get skipMonthlySearches setting
     *
     * @return bool The skipMonthlySearches setting
     */
    public function getSkipMonthlySearches(): bool
    {
        return $this->skipMonthlySearches;
    }

    /**
     * Set skipMonthlySearches setting
     *
     * @param bool $value The skipMonthlySearches setting
     *
     * @return void
     */
    public function setSkipMonthlySearches(bool $value): void
    {
        $this->skipMonthlySearches = $value;
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

        $query->where(function ($q) {
            foreach ($this->endpointsToProcess as $endpoint) {
                $q->orWhere('endpoint', 'like', "%{$endpoint}%");
            }
        });

        $updated = $query->update([
            'processed_at'     => null,
            'processed_status' => null,
        ]);

        Log::info('Reset processed status for DataForSEO Keywords Data Google Ads responses', [
            'updated_count' => $updated,
        ]);
    }

    /**
     * Clear processed items from database table
     *
     * @return array Statistics about cleared records
     */
    public function clearProcessedTables(): array
    {
        $stats = ['items_cleared' => 0];

        $stats['items_cleared'] = DB::table($this->itemsTable)->delete();

        Log::info('Cleared DataForSEO Keywords Data Google Ads processed table', [
            'items_cleared' => $stats['items_cleared'],
        ]);

        return $stats;
    }

    /**
     * Extract metadata from result level
     *
     * @param array $result The result data
     *
     * @return array The extracted result metadata
     */
    public function extractMetadata(array $result): array
    {
        return [
            'se'            => $result['se'] ?? null,
            'location_code' => $result['location_code'] ?? null,
            'language_code' => $result['language_code'] ?? null,
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
        // Provide defaults for required fields
        $data['se']            = $data['se'] ?? 'google_ads';
        $data['location_code'] = $data['location_code'] ?? self::WORLDWIDE_LOCATION_CODE;
        $data['language_code'] = $data['language_code'] ?? self::WORLDWIDE_LANGUAGE_CODE;

        return $data;
    }

    /**
     * Batch insert or update items using "update column if NULL" approach.
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

        foreach ($items as $row) {
            $where = [
                'keyword'       => $row['keyword'],
                'location_code' => $row['location_code'] ?? self::WORLDWIDE_LOCATION_CODE,
                'language_code' => $row['language_code'] ?? self::WORLDWIDE_LANGUAGE_CODE,
            ];

            $existingRow = DB::table($this->itemsTable)
                ->where($where)
                ->first();

            if ($existingRow === null) {
                // No existing row - insert new
                DB::table($this->itemsTable)->insert($row);
                $stats['items_inserted']++;

                continue;
            }

            // Build update array based on "update column if NULL" logic
            $updateData      = [];
            $shouldUpdate    = false;
            $responseIsNewer = Carbon::parse($row['created_at'])->gte(Carbon::parse($existingRow->created_at));

            foreach ($row as $field => $value) {
                if (in_array($field, ['keyword', 'location_code', 'language_code', 'created_at'])) {
                    continue; // Skip key fields and created_at
                }

                if ($value !== null) {
                    $existingValue = $existingRow->{$field} ?? null;

                    // Update if: existing value is NULL OR (updateIfNewer=true AND response is newer)
                    if ($existingValue === null || ($this->updateIfNewer && $responseIsNewer)) {
                        $updateData[$field] = $value;
                        $shouldUpdate       = true;
                    }
                }
            }

            if ($shouldUpdate) {
                DB::table($this->itemsTable)
                    ->where($where)
                    ->update($updateData);
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
     * @param array $items    The items to process
     * @param array $taskData The task data
     *
     * @return array Statistics about processing including item count and insert/update details
     */
    public function processGoogleAdsItems(array $items, array $taskData): array
    {
        $googleAdsItems = [];
        $now            = now();

        // Define all possible fields that can be mapped from API responses
        $possibleFields = [
            'spell', 'search_partners', 'competition', 'competition_index', 'search_volume',
            'low_top_of_page_bid', 'high_top_of_page_bid', 'cpc', 'monthly_searches',
        ];

        foreach ($items as $item) {
            $keyword = $item['keyword'] ?? null;
            if (!$keyword) {
                continue;
            }

            $nextItem = array_merge($taskData, [
                'keyword'    => $keyword,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Map all available fields from the response item
            foreach ($possibleFields as $field) {
                if (isset($item[$field])) {
                    if ($field === 'monthly_searches') {
                        if (!$this->skipMonthlySearches && is_array($item[$field])) {
                            // Store monthly_searches as pretty-printed JSON
                            $nextItem[$field] = json_encode($item[$field], JSON_PRETTY_PRINT);
                        }
                        // Skip if skipMonthlySearches is true
                    } else {
                        $nextItem[$field] = $item[$field];
                    }
                }
            }

            $googleAdsItems[] = $nextItem;
        }

        $batchStats = $this->batchInsertOrUpdateItems($googleAdsItems);

        return [
            'google_ads_items' => count($googleAdsItems),
            'items_inserted'   => $batchStats['items_inserted'],
            'items_updated'    => $batchStats['items_updated'],
            'items_skipped'    => $batchStats['items_skipped'],
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
            'google_ads_items' => 0,
            'items_inserted'   => 0,
            'items_updated'    => 0,
            'items_skipped'    => 0,
            'total_items'      => 0,
        ];

        foreach ($responseBody['tasks'] as $task) {
            if (!isset($task['result'])) {
                continue;
            }

            $taskData                = $this->extractMetadata($task['data'] ?? []);
            $taskData['task_id']     = $task['id'] ?? null;
            $taskData['response_id'] = $response->id;

            foreach ($task['result'] as $result) {
                // Each $result is already a single keyword item
                $stats['total_items']++;

                // Merge task data with result data (result data takes precedence)
                $mergedTaskData = array_merge($taskData, $this->extractMetadata($result));
                $mergedTaskData = $this->ensureDefaults($mergedTaskData);

                $itemStats = $this->processGoogleAdsItems([$result], $mergedTaskData);
                $stats['google_ads_items'] += $itemStats['google_ads_items'];
                $stats['items_inserted'] += $itemStats['items_inserted'];
                $stats['items_updated'] += $itemStats['items_updated'];
                $stats['items_skipped'] += $itemStats['items_skipped'];
            }
        }

        return $stats;
    }

    /**
     * Process unprocessed DataForSEO Keywords Data Google Ads responses
     *
     * @param int $limit Maximum number of responses to process (default: 100)
     *
     * @return array Statistics about processing
     */
    public function processResponses(int $limit = 100): array
    {
        $tableName = $this->getResponsesTableName();

        $query = DB::table($tableName)
            ->whereNull('processed_at')
            ->where('response_status_code', 200)
            ->select('id', 'key', 'endpoint', 'response_body', 'base_url', 'created_at');

        $query->where(function ($q) {
            foreach ($this->endpointsToProcess as $endpoint) {
                $q->orWhere('endpoint', 'like', "%{$endpoint}%");
            }
        });

        if ($this->skipSandbox) {
            $query->where('base_url', 'not like', 'https://sandbox.%')
                  ->where('base_url', 'not like', 'http://sandbox.%');
        }

        $query->limit($limit);
        $responses = $query->get();

        Log::debug('Processing DataForSEO Keywords Data Google Ads responses', [
            'count' => $responses->count(),
            'limit' => $limit,
        ]);

        $stats = [
            'processed_responses' => 0,
            'google_ads_items'    => 0,
            'items_inserted'      => 0,
            'items_updated'       => 0,
            'items_skipped'       => 0,
            'total_items'         => 0,
            'errors'              => 0,
        ];

        foreach ($responses as $response) {
            try {
                DB::transaction(function () use ($response, &$stats, $tableName) {
                    $responseStats = $this->processResponse($response);
                    $stats['google_ads_items'] += $responseStats['google_ads_items'];
                    $stats['items_inserted'] += $responseStats['items_inserted'];
                    $stats['items_updated'] += $responseStats['items_updated'];
                    $stats['items_skipped'] += $responseStats['items_skipped'];
                    $stats['total_items'] += $responseStats['total_items'];
                    $stats['processed_responses']++;

                    DB::table($tableName)
                        ->where('id', $response->id)
                        ->update([
                            'processed_at'     => now(),
                            'processed_status' => json_encode([
                                'status'           => 'OK',
                                'error'            => null,
                                'google_ads_items' => $responseStats['google_ads_items'],
                                'items_inserted'   => $responseStats['items_inserted'],
                                'items_updated'    => $responseStats['items_updated'],
                                'items_skipped'    => $responseStats['items_skipped'],
                                'total_items'      => $responseStats['total_items'],
                            ], JSON_PRETTY_PRINT),
                        ]);
                });
            } catch (\Exception $e) {
                Log::error('Failed to process DataForSEO Keywords Data Google Ads response', [
                    'response_id' => $response->id,
                    'error'       => $e->getMessage(),
                ]);

                $stats['errors']++;

                DB::table($tableName)
                    ->where('id', $response->id)
                    ->update([
                        'processed_at'     => now(),
                        'processed_status' => json_encode([
                            'status'           => 'ERROR',
                            'error'            => $e->getMessage(),
                            'google_ads_items' => 0,
                            'items_inserted'   => 0,
                            'items_updated'    => 0,
                            'items_skipped'    => 0,
                            'total_items'      => 0,
                        ], JSON_PRETTY_PRINT),
                    ]);
            }
        }

        Log::debug('DataForSEO Keywords Data Google Ads processing completed', $stats);

        return $stats;
    }
}

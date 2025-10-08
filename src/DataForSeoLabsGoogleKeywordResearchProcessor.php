<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

/**
 * Processor for DataForSEO Labs Google Keyword Research responses
 *
 * Processes unprocessed DataForSEO responses and extracts keyword research data
 * from all 7 Labs Google endpoints into the keyword research items table.
 */
class DataForSeoLabsGoogleKeywordResearchProcessor
{
    private ApiCacheManager $cacheManager;
    private bool $skipSandbox                                             = true;
    private bool $updateIfNewer                                           = true;
    private bool $skipKeywordInfoMonthlySearches                          = false;
    private bool $skipKeywordInfoNormalizedWithBingMonthlySearches        = false;
    private bool $skipKeywordInfoNormalizedWithClickstreamMonthlySearches = false;
    private bool $skipClickstreamKeywordInfoMonthlySearches               = false;
    private array $endpointsToProcess                                     = [
        'dataforseo_labs/google/keywords_for_site/%',
        'dataforseo_labs/google/related_keywords/%',
        'dataforseo_labs/google/keyword_suggestions/%',
        'dataforseo_labs/google/keyword_ideas/%',
        'dataforseo_labs/google/bulk_keyword_difficulty/%',
        'dataforseo_labs/google/search_intent/%',
        'dataforseo_labs/google/keyword_overview/%',
    ];
    private string $itemsTable = 'dataforseo_labs_google_keyword_research_items';

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
     * Get skipKeywordInfoMonthlySearches setting
     *
     * @return bool The skipKeywordInfoMonthlySearches setting
     */
    public function getSkipKeywordInfoMonthlySearches(): bool
    {
        return $this->skipKeywordInfoMonthlySearches;
    }

    /**
     * Set skipKeywordInfoMonthlySearches setting
     *
     * @param bool $value The skipKeywordInfoMonthlySearches setting
     *
     * @return void
     */
    public function setSkipKeywordInfoMonthlySearches(bool $value): void
    {
        $this->skipKeywordInfoMonthlySearches = $value;
    }

    /**
     * Get skipKeywordInfoNormalizedWithBingMonthlySearches setting
     *
     * @return bool The skipKeywordInfoNormalizedWithBingMonthlySearches setting
     */
    public function getSkipKeywordInfoNormalizedWithBingMonthlySearches(): bool
    {
        return $this->skipKeywordInfoNormalizedWithBingMonthlySearches;
    }

    /**
     * Set skipKeywordInfoNormalizedWithBingMonthlySearches setting
     *
     * @param bool $value The skipKeywordInfoNormalizedWithBingMonthlySearches setting
     *
     * @return void
     */
    public function setSkipKeywordInfoNormalizedWithBingMonthlySearches(bool $value): void
    {
        $this->skipKeywordInfoNormalizedWithBingMonthlySearches = $value;
    }

    /**
     * Get skipKeywordInfoNormalizedWithClickstreamMonthlySearches setting
     *
     * @return bool The skipKeywordInfoNormalizedWithClickstreamMonthlySearches setting
     */
    public function getSkipKeywordInfoNormalizedWithClickstreamMonthlySearches(): bool
    {
        return $this->skipKeywordInfoNormalizedWithClickstreamMonthlySearches;
    }

    /**
     * Set skipKeywordInfoNormalizedWithClickstreamMonthlySearches setting
     *
     * @param bool $value The skipKeywordInfoNormalizedWithClickstreamMonthlySearches setting
     *
     * @return void
     */
    public function setSkipKeywordInfoNormalizedWithClickstreamMonthlySearches(bool $value): void
    {
        $this->skipKeywordInfoNormalizedWithClickstreamMonthlySearches = $value;
    }

    /**
     * Get skipClickstreamKeywordInfoMonthlySearches setting
     *
     * @return bool The skipClickstreamKeywordInfoMonthlySearches setting
     */
    public function getSkipClickstreamKeywordInfoMonthlySearches(): bool
    {
        return $this->skipClickstreamKeywordInfoMonthlySearches;
    }

    /**
     * Set skipClickstreamKeywordInfoMonthlySearches setting
     *
     * @param bool $value The skipClickstreamKeywordInfoMonthlySearches setting
     *
     * @return void
     */
    public function setSkipClickstreamKeywordInfoMonthlySearches(bool $value): void
    {
        $this->skipClickstreamKeywordInfoMonthlySearches = $value;
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

        Log::info('Reset processed status for DataForSEO Labs Google Keyword Research responses', [
            'updated_count' => $updated,
        ]);
    }

    /**
     * Clear processed tables
     *
     * @param bool $withCount Whether to return counts (default: false)
     *
     * @return array Statistics about cleared tables
     */
    public function clearProcessedTables(bool $withCount = false): array
    {
        $stats = [];

        if ($withCount) {
            $stats['items_deleted'] = DB::table($this->itemsTable)->count();
        } else {
            $stats['items_deleted'] = null;
        }

        DB::table($this->itemsTable)->truncate();

        Log::info('Cleared DataForSEO Labs Google Keyword Research processed tables', $stats);

        return $stats;
    }

    /**
     * Apply default values for required fields
     *
     * @param array $data The data to apply defaults to
     *
     * @return array The data with defaults applied
     */
    public function ensureDefaults(array $data): array
    {
        // No defaults needed for Labs Google Keyword Research items
        return $data;
    }

    /**
     * Extract task data from task array
     *
     * @param array $taskData The task data array
     *
     * @return array The extracted task data
     */
    public function extractTaskData(array $taskData): array
    {
        $data = [
            'se_type' => $taskData['se_type'] ?? null,
        ];

        // Only include location_code and language_code if they have values
        // This allows database defaults to take effect when they're missing
        if (isset($taskData['location_code'])) {
            $data['location_code'] = $taskData['location_code'];
        }

        if (isset($taskData['language_code'])) {
            $data['language_code'] = $taskData['language_code'];
        }

        return $data;
    }

    /**
     * Extract result metadata from result array
     *
     * @param array $result The result array
     *
     * @return array The extracted result metadata
     */
    public function extractResultMetadata(array $result): array
    {
        // Labs endpoints don't have meaningful result-level metadata
        // Return empty array for consistency
        return [];
    }

    /**
     * Extract keyword fields from item data
     *
     * @param array      $actualItem      The actual item data
     * @param array|null $relatedKeywords Related keywords array (for related_keywords endpoint)
     * @param array      $mergedData      The merged task and result data
     * @param mixed      $now             The current timestamp
     *
     * @return array The extracted keyword data
     */
    public function extractKeywordFields(array $actualItem, ?array $relatedKeywords, array $mergedData, $now): array
    {
        $extractedData = array_merge($mergedData, [
            'keyword' => $actualItem['keyword'] ?? null,

            // keyword_info fields (flatten nested structure)
            'keyword_info_se_type'              => $actualItem['keyword_info']['se_type'] ?? null,
            'keyword_info_last_updated_time'    => $actualItem['keyword_info']['last_updated_time'] ?? null,
            'keyword_info_competition'          => $actualItem['keyword_info']['competition'] ?? null,
            'keyword_info_competition_level'    => $actualItem['keyword_info']['competition_level'] ?? null,
            'keyword_info_cpc'                  => $actualItem['keyword_info']['cpc'] ?? null,
            'keyword_info_search_volume'        => $actualItem['keyword_info']['search_volume'] ?? null,
            'keyword_info_low_top_of_page_bid'  => $actualItem['keyword_info']['low_top_of_page_bid'] ?? null,
            'keyword_info_high_top_of_page_bid' => $actualItem['keyword_info']['high_top_of_page_bid'] ?? null,
            'keyword_info_categories'           => isset($actualItem['keyword_info']['categories'])
                ? json_encode($actualItem['keyword_info']['categories'], JSON_PRETTY_PRINT) : null,
            'keyword_info_monthly_searches' => (!$this->skipKeywordInfoMonthlySearches && isset($actualItem['keyword_info']['monthly_searches']))
                ? json_encode($actualItem['keyword_info']['monthly_searches'], JSON_PRETTY_PRINT) : null,
            'keyword_info_search_volume_trend_monthly'   => $actualItem['keyword_info']['search_volume_trend']['monthly'] ?? null,
            'keyword_info_search_volume_trend_quarterly' => $actualItem['keyword_info']['search_volume_trend']['quarterly'] ?? null,
            'keyword_info_search_volume_trend_yearly'    => $actualItem['keyword_info']['search_volume_trend']['yearly'] ?? null,

            // keyword_info_normalized_with_bing fields
            'keyword_info_normalized_with_bing_last_updated_time' => $actualItem['keyword_info_normalized_with_bing']['last_updated_time'] ?? null,
            'keyword_info_normalized_with_bing_search_volume'     => $actualItem['keyword_info_normalized_with_bing']['search_volume'] ?? null,
            'keyword_info_normalized_with_bing_is_normalized'     => $actualItem['keyword_info_normalized_with_bing']['is_normalized'] ?? null,
            'keyword_info_normalized_with_bing_monthly_searches'  => (!$this->skipKeywordInfoNormalizedWithBingMonthlySearches && isset($actualItem['keyword_info_normalized_with_bing']['monthly_searches']))
                ? json_encode($actualItem['keyword_info_normalized_with_bing']['monthly_searches'], JSON_PRETTY_PRINT) : null,

            // keyword_info_normalized_with_clickstream fields
            'keyword_info_normalized_with_clickstream_last_updated_time' => $actualItem['keyword_info_normalized_with_clickstream']['last_updated_time'] ?? null,
            'keyword_info_normalized_with_clickstream_search_volume'     => $actualItem['keyword_info_normalized_with_clickstream']['search_volume'] ?? null,
            'keyword_info_normalized_with_clickstream_is_normalized'     => $actualItem['keyword_info_normalized_with_clickstream']['is_normalized'] ?? null,
            'keyword_info_normalized_with_clickstream_monthly_searches'  => (!$this->skipKeywordInfoNormalizedWithClickstreamMonthlySearches && isset($actualItem['keyword_info_normalized_with_clickstream']['monthly_searches']))
                ? json_encode($actualItem['keyword_info_normalized_with_clickstream']['monthly_searches'], JSON_PRETTY_PRINT) : null,

            // clickstream_keyword_info fields
            'clickstream_keyword_info_search_volume'              => $actualItem['clickstream_keyword_info']['search_volume'] ?? null,
            'clickstream_keyword_info_last_updated_time'          => $actualItem['clickstream_keyword_info']['last_updated_time'] ?? null,
            'clickstream_keyword_info_gender_distribution_female' => $actualItem['clickstream_keyword_info']['gender_distribution']['female'] ?? null,
            'clickstream_keyword_info_gender_distribution_male'   => $actualItem['clickstream_keyword_info']['gender_distribution']['male'] ?? null,
            'clickstream_keyword_info_age_distribution_18_24'     => $actualItem['clickstream_keyword_info']['age_distribution']['18-24'] ?? null,
            'clickstream_keyword_info_age_distribution_25_34'     => $actualItem['clickstream_keyword_info']['age_distribution']['25-34'] ?? null,
            'clickstream_keyword_info_age_distribution_35_44'     => $actualItem['clickstream_keyword_info']['age_distribution']['35-44'] ?? null,
            'clickstream_keyword_info_age_distribution_45_54'     => $actualItem['clickstream_keyword_info']['age_distribution']['45-54'] ?? null,
            'clickstream_keyword_info_age_distribution_55_64'     => $actualItem['clickstream_keyword_info']['age_distribution']['55-64'] ?? null,
            'clickstream_keyword_info_monthly_searches'           => (!$this->skipClickstreamKeywordInfoMonthlySearches && isset($actualItem['clickstream_keyword_info']['monthly_searches']))
                ? json_encode($actualItem['clickstream_keyword_info']['monthly_searches'], JSON_PRETTY_PRINT) : null,

            // keyword_properties fields
            'keyword_properties_se_type'                      => $actualItem['keyword_properties']['se_type'] ?? null,
            'keyword_properties_core_keyword'                 => $actualItem['keyword_properties']['core_keyword'] ?? null,
            'keyword_properties_synonym_clustering_algorithm' => $actualItem['keyword_properties']['synonym_clustering_algorithm'] ?? null,
            'keyword_properties_keyword_difficulty'           => $actualItem['keyword_properties']['keyword_difficulty'] ?? null,
            'keyword_properties_detected_language'            => $actualItem['keyword_properties']['detected_language'] ?? null,
            'keyword_properties_is_another_language'          => $actualItem['keyword_properties']['is_another_language'] ?? null,

            // serp_info fields
            'serp_info_se_type'         => $actualItem['serp_info']['se_type'] ?? null,
            'serp_info_check_url'       => $actualItem['serp_info']['check_url'] ?? null,
            'serp_info_serp_item_types' => isset($actualItem['serp_info']['serp_item_types'])
                ? json_encode($actualItem['serp_info']['serp_item_types'], JSON_PRETTY_PRINT) : null,
            'serp_info_se_results_count'      => $actualItem['serp_info']['se_results_count'] ?? null,
            'serp_info_last_updated_time'     => $actualItem['serp_info']['last_updated_time'] ?? null,
            'serp_info_previous_updated_time' => $actualItem['serp_info']['previous_updated_time'] ?? null,

            // avg_backlinks_info fields
            'avg_backlinks_info_se_type'                => $actualItem['avg_backlinks_info']['se_type'] ?? null,
            'avg_backlinks_info_backlinks'              => $actualItem['avg_backlinks_info']['backlinks'] ?? null,
            'avg_backlinks_info_dofollow'               => $actualItem['avg_backlinks_info']['dofollow'] ?? null,
            'avg_backlinks_info_referring_pages'        => $actualItem['avg_backlinks_info']['referring_pages'] ?? null,
            'avg_backlinks_info_referring_domains'      => $actualItem['avg_backlinks_info']['referring_domains'] ?? null,
            'avg_backlinks_info_referring_main_domains' => $actualItem['avg_backlinks_info']['referring_main_domains'] ?? null,
            'avg_backlinks_info_rank'                   => $actualItem['avg_backlinks_info']['rank'] ?? null,
            'avg_backlinks_info_main_domain_rank'       => $actualItem['avg_backlinks_info']['main_domain_rank'] ?? null,
            'avg_backlinks_info_last_updated_time'      => $actualItem['avg_backlinks_info']['last_updated_time'] ?? null,

            // search_intent_info fields
            'search_intent_info_se_type'        => $actualItem['search_intent_info']['se_type'] ?? null,
            'search_intent_info_main_intent'    => $actualItem['search_intent_info']['main_intent'] ?? null,
            'search_intent_info_foreign_intent' => isset($actualItem['search_intent_info']['foreign_intent'])
                ? json_encode($actualItem['search_intent_info']['foreign_intent'], JSON_PRETTY_PRINT) : null,
            'search_intent_info_last_updated_time' => $actualItem['search_intent_info']['last_updated_time'] ?? null,

            // Related Keywords specific field (only for related_keywords endpoint)
            'related_keywords' => $relatedKeywords
                ? json_encode($relatedKeywords, JSON_PRETTY_PRINT) : null,

            // Bulk Keyword Difficulty specific field
            'keyword_difficulty' => $actualItem['keyword_difficulty'] ?? null,

            // Search Intent specific fields
            'keyword_intent_label'       => $actualItem['keyword_intent']['label'] ?? null,
            'keyword_intent_probability' => $actualItem['keyword_intent']['probability'] ?? null,

            // Secondary keyword intents - initialize all to null
            'secondary_keyword_intents_probability_informational' => null,
            'secondary_keyword_intents_probability_navigational'  => null,
            'secondary_keyword_intents_probability_commercial'    => null,
            'secondary_keyword_intents_probability_transactional' => null,

            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Process secondary_keyword_intents array if present
        if (isset($actualItem['secondary_keyword_intents']) && is_array($actualItem['secondary_keyword_intents'])) {
            foreach ($actualItem['secondary_keyword_intents'] as $intent) {
                $label       = $intent['label'] ?? '';
                $probability = $intent['probability'] ?? null;
                $fieldName   = "secondary_keyword_intents_probability_{$label}";

                // Only set if it's a valid intent type with a field in our schema
                if (in_array($label, ['informational', 'navigational', 'commercial', 'transactional']) && $probability !== null) {
                    $extractedData[$fieldName] = $probability;
                }
            }
        }

        return $extractedData;
    }

    /**
     * Batch insert or update keyword items
     *
     * @param array $items The keyword items to process
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
            // Fast path: insertOrIgnore for new items only
            foreach (array_chunk($items, 100) as $chunk) {
                $inserted = DB::table($this->itemsTable)->insertOrIgnore($chunk);
                $stats['items_inserted'] += $inserted;
                $stats['items_skipped'] += count($chunk) - $inserted;
            }

            return $stats;
        }

        // Update if newer logic
        foreach ($items as $row) {
            $where = [
                'keyword'       => $row['keyword'],
                'location_code' => $row['location_code'] ?? 0, // Use default if not present
                'language_code' => $row['language_code'] ?? 'none', // Use default if not present
            ];

            $existingCreatedAt = DB::table($this->itemsTable)
                ->where($where)
                ->value('created_at');

            if ($existingCreatedAt === null) {
                DB::table($this->itemsTable)->insert($row);
                $stats['items_inserted']++;
            } elseif (Carbon::parse($row['created_at'])->gte(Carbon::parse($existingCreatedAt))) {
                DB::table($this->itemsTable)->where($where)->update($row);
                $stats['items_updated']++;
            } else {
                $stats['items_skipped']++;
            }
        }

        return $stats;
    }

    /**
     * Process Labs keyword items
     *
     * @param array $items      The items array from the response
     * @param array $mergedData The merged task and result data
     *
     * @return array Statistics about processing
     */
    public function processLabsKeywordItems(array $items, array $mergedData): array
    {
        $keywordItems = [];
        $now          = now();

        foreach ($items as $item) {
            // Handle Related Keywords special structure
            if (isset($item['keyword_data'])) {
                $actualItem      = $item['keyword_data'];
                $relatedKeywords = $item['related_keywords'] ?? null;
            } else {
                $actualItem      = $item;
                $relatedKeywords = null;
            }

            $keywordItems[] = $this->extractKeywordFields($actualItem, $relatedKeywords, $mergedData, $now);
        }

        $itemStats                  = $this->batchInsertOrUpdateItems($keywordItems);
        $itemStats['keyword_items'] = count($keywordItems);

        return $itemStats;
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
            'keyword_items'  => 0,
            'items_inserted' => 0,
            'items_updated'  => 0,
            'items_skipped'  => 0,
            'total_items'    => 0,
        ];

        foreach ($responseBody['tasks'] as $task) {
            if (!isset($task['result'])) {
                continue;
            }

            // Extract task data and add processing metadata
            $baseTaskData                = $this->extractTaskData($task['data'] ?? []);
            $baseTaskData['task_id']     = $task['id'] ?? null;
            $baseTaskData['response_id'] = $response->id;

            foreach ($task['result'] as $result) {
                if (!isset($result['items'])) {
                    continue;
                }

                // Merge task data with result metadata
                $mergedData = array_merge($baseTaskData, $this->extractResultMetadata($result));
                $mergedData = $this->ensureDefaults($mergedData);

                // Count total items
                $stats['total_items'] += count($result['items']);

                // Process keyword items
                $itemStats = $this->processLabsKeywordItems($result['items'], $mergedData);
                $stats['keyword_items'] += $itemStats['keyword_items'];
                $stats['items_inserted'] += $itemStats['items_inserted'];
                $stats['items_updated'] += $itemStats['items_updated'];
                $stats['items_skipped'] += $itemStats['items_skipped'];
            }
        }

        return $stats;
    }

    /**
     * Process unprocessed DataForSEO Labs Google Keyword Research responses
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

        $stats = [
            'processed_responses' => 0,
            'keyword_items'       => 0,
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

                    // Accumulate stats
                    $stats['keyword_items'] += $responseStats['keyword_items'];
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
                                'status'         => 'OK',
                                'error'          => null,
                                'keyword_items'  => $responseStats['keyword_items'],
                                'items_inserted' => $responseStats['items_inserted'],
                                'items_updated'  => $responseStats['items_updated'],
                                'items_skipped'  => $responseStats['items_skipped'],
                                'total_items'    => $responseStats['total_items'],
                            ], JSON_PRETTY_PRINT),
                        ]);
                });
            } catch (\Exception $e) {
                Log::error('Failed to process DataForSEO Labs response', [
                    'response_id' => $response->id,
                    'error'       => $e->getMessage(),
                ]);

                $stats['errors']++;

                // Mark response with error
                DB::table($tableName)
                    ->where('id', $response->id)
                    ->update([
                        'processed_at'     => now(),
                        'processed_status' => json_encode([
                            'status'         => 'ERROR',
                            'error'          => $e->getMessage(),
                            'keyword_items'  => 0,
                            'items_inserted' => 0,
                            'items_updated'  => 0,
                            'items_skipped'  => 0,
                            'total_items'    => 0,
                        ], JSON_PRETTY_PRINT),
                    ]);
            }
        }

        Log::debug('DataForSEO Labs Google Keyword Research processing completed', $stats);

        return $stats;
    }
}

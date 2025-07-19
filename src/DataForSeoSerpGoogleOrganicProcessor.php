<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

/**
 * Processor for DataForSEO SERP Google organic responses
 *
 * Processes unprocessed DataForSEO responses and extracts organic search results
 * and People Also Ask (PAA) items into dedicated tables.
 */
class DataForSeoSerpGoogleOrganicProcessor
{
    private ApiCacheManager $cacheManager;
    private bool $skipSandbox         = true;
    private bool $updateIfNewer       = true;
    private array $endpointsToProcess = [
        'serp/google/organic/task_get/%',
        'serp/google/organic/live/%',
    ];
    private string $organicItemsTable = 'dataforseo_serp_google_organic_items';
    private string $paaItemsTable     = 'dataforseo_serp_google_organic_paa_items';

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

        Log::info('Reset processed status for DataForSEO SERP Google responses', [
            'updated_count' => $updated,
        ]);
    }

    /**
     * Clear processed items from database tables
     *
     * @param bool $includePaa Whether to also clear PAA items table
     * @param bool $withCount  Whether to count rows before clearing (default: false)
     *
     * @return array Statistics about cleared records, with null values if counting was skipped
     */
    public function clearProcessedTables(bool $includePaa = true, bool $withCount = false): array
    {
        $stats = [
            'organic_cleared' => $withCount ? DB::table($this->organicItemsTable)->count() : null,
            'paa_cleared'     => null,
        ];

        if ($includePaa) {
            $stats['paa_cleared'] = $withCount ? DB::table($this->paaItemsTable)->count() : null;
        }

        DB::table($this->organicItemsTable)->truncate();

        if ($includePaa) {
            DB::table($this->paaItemsTable)->truncate();
        }

        Log::info('Cleared DataForSEO SERP Google processed tables', [
            'organic_cleared' => $withCount ? $stats['organic_cleared'] : 'not counted',
            'paa_cleared'     => $withCount ? $stats['paa_cleared'] : 'not counted',
            'include_paa'     => $includePaa,
            'with_count'      => $withCount,
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
     * Batch insert or update organic items.
     *
     * @param array $organicItems The organic items to process
     *
     * @return array Statistics about insert/update operations
     */
    public function batchInsertOrUpdateOrganicItems(array $organicItems): array
    {
        $stats = [
            'items_inserted' => 0,
            'items_updated'  => 0,
            'items_skipped'  => 0,
        ];

        if (!$this->updateIfNewer) {
            // Fast path: accept only brand-new rows, silently skip duplicates
            foreach (array_chunk($organicItems, 100) as $chunk) {
                $inserted = DB::table($this->organicItemsTable)->insertOrIgnore($chunk);
                $stats['items_inserted'] += $inserted;
                $stats['items_skipped'] += count($chunk) - $inserted;
            }

            return $stats;
        }

        foreach ($organicItems as $row) {
            $where = [
                'keyword'       => $row['keyword'],
                'location_code' => $row['location_code'],
                'language_code' => $row['language_code'],
                'device'        => $row['device'],
                'rank_absolute' => $row['rank_absolute'],
            ];

            $existingCreatedAt = DB::table($this->organicItemsTable)
                ->where($where)
                ->value('created_at');

            if ($existingCreatedAt === null) {
                // No clash – insert fresh
                DB::table($this->organicItemsTable)->insert($row);
                $stats['items_inserted']++;

                continue;
            }

            // Only update if the new row is more recent
            if (Carbon::parse($row['created_at'])
                ->gte(Carbon::parse($existingCreatedAt))) {
                DB::table($this->organicItemsTable)
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
     * Batch insert or update PAA items.
     *
     * @param array $paaItems The PAA items to process
     *
     * @return array Statistics about insert/update operations
     */
    public function batchInsertOrUpdatePaaItems(array $paaItems): array
    {
        $stats = [
            'items_inserted' => 0,
            'items_updated'  => 0,
            'items_skipped'  => 0,
        ];

        if (!$this->updateIfNewer) {
            // Fast path: accept only brand-new rows, silently skip duplicates
            foreach (array_chunk($paaItems, 100) as $chunk) {
                $inserted = DB::table($this->paaItemsTable)->insertOrIgnore($chunk);
                $stats['items_inserted'] += $inserted;
                $stats['items_skipped'] += count($chunk) - $inserted;
            }

            return $stats;
        }

        foreach ($paaItems as $row) {
            $where = [
                'keyword'       => $row['keyword'],
                'location_code' => $row['location_code'],
                'language_code' => $row['language_code'],
                'device'        => $row['device'],
                'item_position' => $row['item_position'],
            ];

            $existingCreatedAt = DB::table($this->paaItemsTable)
                ->where($where)
                ->value('created_at');

            if ($existingCreatedAt === null) {
                // No clash – insert fresh
                DB::table($this->paaItemsTable)->insert($row);
                $stats['items_inserted']++;

                continue;
            }

            // Only update if the new row is more recent
            if (Carbon::parse($row['created_at'])
                ->gte(Carbon::parse($existingCreatedAt))) {
                DB::table($this->paaItemsTable)
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
     * Process organic items from response
     *
     * @param array $items    The items to process
     * @param array $taskData The task data
     *
     * @return array Statistics about processing including item count and insert/update details
     */
    public function processOrganicItems(array $items, array $taskData): array
    {
        $organicItems = [];
        $now          = now();

        foreach ($items as $item) {
            if (($item['type'] ?? null) !== 'organic') {
                continue;
            }

            $organicItems[] = array_merge($taskData, [
                'type'                => $item['type'] ?? null,
                'rank_group'          => $item['rank_group'] ?? null,
                'rank_absolute'       => $item['rank_absolute'] ?? null,
                'domain'              => $item['domain'] ?? null,
                'title'               => $item['title'] ?? null,
                'description'         => $item['description'] ?? null,
                'url'                 => $item['url'] ?? null,
                'breadcrumb'          => $item['breadcrumb'] ?? null,
                'is_image'            => $item['is_image'] ?? null,
                'is_video'            => $item['is_video'] ?? null,
                'is_featured_snippet' => $item['is_featured_snippet'] ?? null,
                'is_malicious'        => $item['is_malicious'] ?? null,
                'is_web_story'        => $item['is_web_story'] ?? null,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
        }

        // Batch process organic items and get detailed stats
        $batchStats = $this->batchInsertOrUpdateOrganicItems($organicItems);

        return [
            'organic_items'  => count($organicItems),
            'items_inserted' => $batchStats['items_inserted'],
            'items_updated'  => $batchStats['items_updated'],
            'items_skipped'  => $batchStats['items_skipped'],
        ];
    }

    /**
     * Process PAA items from response
     *
     * @param array $items    The items to process
     * @param array $taskData The task data
     *
     * @return array Statistics about processing including item count and insert/update details
     */
    public function processPaaItems(array $items, array $taskData): array
    {
        $paaItems = [];
        $now      = now();

        foreach ($items as $item) {
            if (($item['type'] ?? null) !== 'people_also_ask') {
                continue;
            }

            if (!isset($item['items'])) {
                continue;
            }

            $itemPosition = 0;
            foreach ($item['items'] as $paaElement) {
                if (($paaElement['type'] ?? null) !== 'people_also_ask_element') {
                    continue;
                }

                $itemPosition++;

                if (!isset($paaElement['expanded_element'])) {
                    continue;
                }

                foreach ($paaElement['expanded_element'] as $expandedElement) {
                    if (($expandedElement['type'] ?? null) !== 'people_also_ask_expanded_element') {
                        continue;
                    }

                    $paaItems[] = array_merge($taskData, [
                        'item_position'         => $itemPosition,
                        'type'                  => $paaElement['type'] ?? null,
                        'title'                 => $paaElement['title'] ?? null,
                        'seed_question'         => $paaElement['seed_question'] ?? null,
                        'xpath'                 => $paaElement['xpath'] ?? null,
                        'answer_type'           => $expandedElement['type'] ?? null,
                        'answer_featured_title' => $expandedElement['featured_title'] ?? null,
                        'answer_url'            => $expandedElement['url'] ?? null,
                        'answer_domain'         => $expandedElement['domain'] ?? null,
                        'answer_title'          => $expandedElement['title'] ?? null,
                        'answer_description'    => $expandedElement['description'] ?? null,
                        'answer_images'         => isset($expandedElement['images']) ? json_encode($expandedElement['images']) : null,
                        'answer_timestamp'      => $expandedElement['timestamp'] ?? null,
                        'answer_table'          => isset($expandedElement['table']) ? json_encode($expandedElement['table']) : null,
                        'created_at'            => $now,
                        'updated_at'            => $now,
                    ]);
                }
            }
        }

        // Batch process PAA items and get detailed stats
        $batchStats = $this->batchInsertOrUpdatePaaItems($paaItems);

        return [
            'paa_items'      => count($paaItems),
            'items_inserted' => $batchStats['items_inserted'],
            'items_updated'  => $batchStats['items_updated'],
            'items_skipped'  => $batchStats['items_skipped'],
        ];
    }

    /**
     * Process a single response
     *
     * @param object $response    The response to process
     * @param bool   $processPaas Whether to process People Also Ask items
     *
     * @throws \Exception If response is invalid
     *
     * @return array Statistics about processing
     */
    public function processResponse($response, bool $processPaas): array
    {
        $responseBody = json_decode($response->response_body, true);
        if (!$responseBody || !isset($responseBody['tasks'])) {
            throw new \Exception('Invalid JSON response or missing tasks array');
        }

        $stats = [
            'organic_items'          => 0,
            'organic_items_inserted' => 0,
            'organic_items_updated'  => 0,
            'organic_items_skipped'  => 0,
            'paa_items'              => 0,
            'paa_items_inserted'     => 0,
            'paa_items_updated'      => 0,
            'paa_items_skipped'      => 0,
            'total_items'            => 0,
        ];

        foreach ($responseBody['tasks'] as $task) {
            if (!isset($task['result'])) {
                continue;
            }

            $taskData                = $this->extractMetadata($task['data'] ?? []);
            $taskData['task_id']     = $task['id'] ?? null;
            $taskData['response_id'] = $response->id;

            foreach ($task['result'] as $result) {
                if (!isset($result['items'])) {
                    continue;
                }

                // Count total items available for processing
                $stats['total_items'] += count($result['items']);

                // Merge task data with result data (result data takes precedence)
                $mergedTaskData = array_merge($taskData, $this->extractMetadata($result));
                $mergedTaskData = $this->ensureDefaults($mergedTaskData);

                // Process organic items and get detailed stats
                $organicStats = $this->processOrganicItems($result['items'], $mergedTaskData);
                $stats['organic_items'] += $organicStats['organic_items'];
                $stats['organic_items_inserted'] += $organicStats['items_inserted'];
                $stats['organic_items_updated'] += $organicStats['items_updated'];
                $stats['organic_items_skipped'] += $organicStats['items_skipped'];

                // Process PAA items if enabled
                if ($processPaas) {
                    $paaStats = $this->processPaaItems($result['items'], $mergedTaskData);
                    $stats['paa_items'] += $paaStats['paa_items'];
                    $stats['paa_items_inserted'] += $paaStats['items_inserted'];
                    $stats['paa_items_updated'] += $paaStats['items_updated'];
                    $stats['paa_items_skipped'] += $paaStats['items_skipped'];
                }
            }
        }

        return $stats;
    }

    /**
     * Process unprocessed DataForSEO SERP Google organic responses
     *
     * @param int  $limit       Maximum number of responses to process (default: 100)
     * @param bool $processPaas Whether to process People Also Ask items (default: true)
     *
     * @return array Statistics about processing
     */
    public function processResponses(int $limit = 100, bool $processPaas = true): array
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

        Log::debug('Processing DataForSEO SERP Google responses', [
            'count'        => $responses->count(),
            'limit'        => $limit,
            'process_paas' => $processPaas,
        ]);

        $stats = [
            'processed_responses'    => 0,
            'organic_items'          => 0,
            'organic_items_inserted' => 0,
            'organic_items_updated'  => 0,
            'organic_items_skipped'  => 0,
            'paa_items'              => 0,
            'paa_items_inserted'     => 0,
            'paa_items_updated'      => 0,
            'paa_items_skipped'      => 0,
            'total_items'            => 0,
            'errors'                 => 0,
        ];

        foreach ($responses as $response) {
            try {
                // Wrap each response processing in a transaction
                DB::transaction(function () use ($response, $processPaas, &$stats, $tableName) {
                    $responseStats = $this->processResponse($response, $processPaas);
                    $stats['organic_items'] += $responseStats['organic_items'];
                    $stats['organic_items_inserted'] += $responseStats['organic_items_inserted'];
                    $stats['organic_items_updated'] += $responseStats['organic_items_updated'];
                    $stats['organic_items_skipped'] += $responseStats['organic_items_skipped'];
                    $stats['paa_items'] += $responseStats['paa_items'];
                    $stats['paa_items_inserted'] += $responseStats['paa_items_inserted'];
                    $stats['paa_items_updated'] += $responseStats['paa_items_updated'];
                    $stats['paa_items_skipped'] += $responseStats['paa_items_skipped'];
                    $stats['total_items'] += $responseStats['total_items'];
                    $stats['processed_responses']++;

                    // Mark response as processed
                    DB::table($tableName)
                        ->where('id', $response->id)
                        ->update([
                            'processed_at'     => now(),
                            'processed_status' => json_encode([
                                'status'                 => 'OK',
                                'error'                  => null,
                                'organic_items'          => $responseStats['organic_items'],
                                'organic_items_inserted' => $responseStats['organic_items_inserted'],
                                'organic_items_updated'  => $responseStats['organic_items_updated'],
                                'organic_items_skipped'  => $responseStats['organic_items_skipped'],
                                'paa_items'              => $responseStats['paa_items'],
                                'paa_items_inserted'     => $responseStats['paa_items_inserted'],
                                'paa_items_updated'      => $responseStats['paa_items_updated'],
                                'paa_items_skipped'      => $responseStats['paa_items_skipped'],
                                'total_items'            => $responseStats['total_items'],
                            ], JSON_PRETTY_PRINT),
                        ]);
                });
            } catch (\Exception $e) {
                Log::error('Failed to process DataForSEO response', [
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
                            'status'                 => 'ERROR',
                            'error'                  => $e->getMessage(),
                            'organic_items'          => 0,
                            'organic_items_inserted' => 0,
                            'organic_items_updated'  => 0,
                            'organic_items_skipped'  => 0,
                            'paa_items'              => 0,
                            'paa_items_inserted'     => 0,
                            'paa_items_updated'      => 0,
                            'paa_items_skipped'      => 0,
                            'total_items'            => 0,
                        ], JSON_PRETTY_PRINT),
                    ]);
            }
        }

        Log::debug('DataForSEO SERP Google processing completed', $stats);

        return $stats;
    }
}

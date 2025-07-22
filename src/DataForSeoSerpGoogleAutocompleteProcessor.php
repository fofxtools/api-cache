<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

/**
 * Processor for DataForSEO SERP Google autocomplete responses
 *
 * Processes unprocessed DataForSEO responses and extracts autocomplete suggestions
 * into dedicated table.
 */
class DataForSeoSerpGoogleAutocompleteProcessor
{
    private ApiCacheManager $cacheManager;
    private bool $skipSandbox         = true;
    private bool $updateIfNewer       = true;
    private array $endpointsToProcess = [
        'serp/google/autocomplete/task_get/%',
        'serp/google/autocomplete/live/%',
    ];
    private string $autocompleteItemsTable = 'dataforseo_serp_google_autocomplete_items';

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

        Log::info('Reset processed status for DataForSEO SERP Google autocomplete responses', [
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
            'autocomplete_cleared' => $withCount ? DB::table($this->autocompleteItemsTable)->count() : null,
        ];

        DB::table($this->autocompleteItemsTable)->truncate();

        Log::info('Cleared DataForSEO SERP Google autocomplete processed table', [
            'autocomplete_cleared' => $withCount ? $stats['autocomplete_cleared'] : 'not counted',
            'with_count'           => $withCount,
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
        ];
    }

    /**
     * Extract task-level metadata (device, os, cursor_pointer)
     *
     * @param array $taskData The task data
     *
     * @return array The extracted task metadata
     */
    public function extractTaskMetadata(array $taskData): array
    {
        return [
            'device'         => $taskData['device'] ?? null,
            'os'             => $taskData['os'] ?? null,
            'cursor_pointer' => $taskData['cursor_pointer'] ?? -1, // Default to -1 if not specified
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
     * Batch insert or update autocomplete items.
     *
     * @param array $autocompleteItems The autocomplete items to process
     *
     * @return array Statistics about insert/update operations
     */
    public function batchInsertOrUpdateAutocompleteItems(array $autocompleteItems): array
    {
        $stats = [
            'items_inserted' => 0,
            'items_updated'  => 0,
            'items_skipped'  => 0,
        ];

        if (!$this->updateIfNewer) {
            // Fast path: accept only brand-new rows, silently skip duplicates
            foreach (array_chunk($autocompleteItems, 100) as $chunk) {
                $inserted = DB::table($this->autocompleteItemsTable)->insertOrIgnore($chunk);
                $stats['items_inserted'] += $inserted;
                $stats['items_skipped'] += count($chunk) - $inserted;
            }

            return $stats;
        }

        foreach ($autocompleteItems as $row) {
            $where = [
                'keyword'        => $row['keyword'],
                'cursor_pointer' => $row['cursor_pointer'],
                'suggestion'     => $row['suggestion'],
                'location_code'  => $row['location_code'],
                'language_code'  => $row['language_code'],
                'device'         => $row['device'],
            ];

            $existingCreatedAt = DB::table($this->autocompleteItemsTable)
                ->where($where)
                ->value('created_at');

            if ($existingCreatedAt === null) {
                // No clash – insert fresh
                DB::table($this->autocompleteItemsTable)->insert($row);
                $stats['items_inserted']++;

                continue;
            }

            // Only update if the new row is more recent
            if (Carbon::parse($row['created_at'])
                ->gte(Carbon::parse($existingCreatedAt))) {
                DB::table($this->autocompleteItemsTable)
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
     * Process autocomplete items from response
     *
     * @param array $items    The items to process
     * @param array $taskData The task data
     *
     * @return array Statistics about processing including item count and insert/update details
     */
    public function processAutocompleteItems(array $items, array $taskData): array
    {
        $autocompleteItems = [];
        $now               = now();

        foreach ($items as $item) {
            if (($item['type'] ?? null) !== 'autocomplete') {
                continue;
            }

            $autocompleteItems[] = array_merge($taskData, [
                'type'             => $item['type'] ?? null,
                'rank_group'       => $item['rank_group'] ?? null,
                'rank_absolute'    => $item['rank_absolute'] ?? null,
                'relevance'        => $item['relevance'] ?? null,
                'suggestion'       => $item['suggestion'] ?? null,
                'suggestion_type'  => $item['suggestion_type'] ?? null,
                'search_query_url' => $item['search_query_url'] ?? null,
                'thumbnail_url'    => $item['thumbnail_url'] ?? null,
                'highlighted'      => isset($item['highlighted']) ? json_encode($item['highlighted']) : null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }

        // Batch process autocomplete items and get detailed stats
        $batchStats = $this->batchInsertOrUpdateAutocompleteItems($autocompleteItems);

        return [
            'autocomplete_items' => count($autocompleteItems),
            'items_inserted'     => $batchStats['items_inserted'],
            'items_updated'      => $batchStats['items_updated'],
            'items_skipped'      => $batchStats['items_skipped'],
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
            'autocomplete_items' => 0,
            'items_inserted'     => 0,
            'items_updated'      => 0,
            'items_skipped'      => 0,
            'total_items'        => 0,
        ];

        foreach ($responseBody['tasks'] as $task) {
            if (!isset($task['result'])) {
                continue;
            }

            // Extract base task data (device, os, cursor_pointer from task.data)
            $baseTaskData                = $this->extractTaskMetadata($task['data'] ?? []);
            $baseTaskData['task_id']     = $task['id'] ?? null;
            $baseTaskData['response_id'] = $response->id;

            foreach ($task['result'] as $result) {
                if (!isset($result['items'])) {
                    continue;
                }

                // Count total items available for processing
                $stats['total_items'] += count($result['items']);

                // Merge base task data with result data (result data takes precedence)
                $mergedTaskData = array_merge($baseTaskData, $this->extractMetadata($result));
                $mergedTaskData = $this->ensureDefaults($mergedTaskData);

                // Process autocomplete items and get detailed stats
                $itemStats = $this->processAutocompleteItems($result['items'], $mergedTaskData);
                $stats['autocomplete_items'] += $itemStats['autocomplete_items'];
                $stats['items_inserted'] += $itemStats['items_inserted'];
                $stats['items_updated'] += $itemStats['items_updated'];
                $stats['items_skipped'] += $itemStats['items_skipped'];
            }
        }

        return $stats;
    }

    /**
     * Process unprocessed DataForSEO SERP Google autocomplete responses
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

        Log::debug('Processing DataForSEO SERP Google autocomplete responses', [
            'count' => $responses->count(),
            'limit' => $limit,
        ]);

        $stats = [
            'processed_responses' => 0,
            'autocomplete_items'  => 0,
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
                    $stats['autocomplete_items'] += $responseStats['autocomplete_items'];
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
                                'status'             => 'OK',
                                'error'              => null,
                                'autocomplete_items' => $responseStats['autocomplete_items'],
                                'items_inserted'     => $responseStats['items_inserted'],
                                'items_updated'      => $responseStats['items_updated'],
                                'items_skipped'      => $responseStats['items_skipped'],
                                'total_items'        => $responseStats['total_items'],
                            ], JSON_PRETTY_PRINT),
                        ]);
                });
            } catch (\Exception $e) {
                Log::error('Failed to process DataForSEO autocomplete response', [
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
                            'status'             => 'ERROR',
                            'error'              => $e->getMessage(),
                            'autocomplete_items' => 0,
                            'items_inserted'     => 0,
                            'items_updated'      => 0,
                            'items_skipped'      => 0,
                            'total_items'        => 0,
                        ], JSON_PRETTY_PRINT),
                    ]);
            }
        }

        Log::debug('DataForSEO SERP Google autocomplete processing completed', $stats);

        return $stats;
    }
}

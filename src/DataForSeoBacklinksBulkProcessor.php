<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

/**
 * Processor for DataForSEO Backlinks Bulk responses
 *
 * Processes unprocessed DataForSEO backlinks bulk responses and extracts bulk items
 * into dedicated table.
 */
class DataForSeoBacklinksBulkProcessor
{
    private ApiCacheManager $cacheManager;
    private bool $skipSandbox         = true;
    private bool $updateIfNewer       = true;
    private array $endpointsToProcess = [
        'backlinks/bulk_ranks/live',
        'backlinks/bulk_backlinks/live',
        'backlinks/bulk_spam_score/live',
        'backlinks/bulk_referring_domains/live',
        'backlinks/bulk_new_lost_backlinks/live',
        'backlinks/bulk_new_lost_referring_domains/live',
        'backlinks/bulk_pages_summary/live',
    ];
    private string $bulkItemsTable = 'dataforseo_backlinks_bulk_items';

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

        $query->where(function ($q) {
            foreach ($this->endpointsToProcess as $endpoint) {
                $q->orWhere('endpoint', 'like', "%{$endpoint}%");
            }
        });

        $updated = $query->update([
            'processed_at'     => null,
            'processed_status' => null,
        ]);

        Log::info('Reset processed status for DataForSEO backlinks bulk responses', [
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
        $stats = ['bulk_items_cleared' => 0];

        $stats['bulk_items_cleared'] = DB::table($this->bulkItemsTable)->delete();

        Log::info('Cleared DataForSEO backlinks bulk processed table', [
            'bulk_items_cleared' => $stats['bulk_items_cleared'],
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
        return [];
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
        return $data;
    }

    /**
     * Extract target identifier from item array
     *
     * @param array $item The item data
     *
     * @return string|null The item identifier (target or url)
     */
    public function extractItemIdentifier(array $item): ?string
    {
        return $item['target'] ?? $item['url'] ?? null;
    }

    /**
     * Batch insert or update bulk items using "update column if NULL" approach.
     *
     * @param array $bulkItems The bulk items to process
     *
     * @return array Statistics about insert/update operations
     */
    public function batchInsertOrUpdateBulkItems(array $bulkItems): array
    {
        $stats = [
            'items_inserted' => 0,
            'items_updated'  => 0,
            'items_skipped'  => 0,
        ];

        foreach ($bulkItems as $row) {
            $where = [
                'target' => $row['target'],
            ];

            $existingRow = DB::table($this->bulkItemsTable)
                ->where($where)
                ->first();

            if ($existingRow === null) {
                // No existing row - insert new
                DB::table($this->bulkItemsTable)->insert($row);
                $stats['items_inserted']++;

                continue;
            }

            // Build update array based on "update column if NULL" logic
            $updateData      = [];
            $shouldUpdate    = false;
            $responseIsNewer = Carbon::parse($row['created_at'])->gte(Carbon::parse($existingRow->created_at));

            foreach ($row as $field => $value) {
                if ($field === 'target' || $field === 'created_at') {
                    continue; // Skip key field and created_at
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
                DB::table($this->bulkItemsTable)
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
     * Process bulk items from response
     *
     * @param array $items    The items to process
     * @param array $taskData The task data
     *
     * @return array Statistics about processing including item count and insert/update details
     */
    public function processBulkItems(array $items, array $taskData): array
    {
        $bulkItems = [];
        $now       = now();

        // Define all possible fields that can be mapped from API responses
        $possibleFields = [
            'rank', 'main_domain_rank', 'backlinks', 'new_backlinks', 'lost_backlinks',
            'broken_backlinks', 'broken_pages', 'spam_score', 'backlinks_spam_score',
            'referring_domains', 'referring_domains_nofollow', 'referring_main_domains',
            'referring_main_domains_nofollow', 'new_referring_domains', 'lost_referring_domains',
            'new_referring_main_domains', 'lost_referring_main_domains', 'first_seen',
            'lost_date', 'referring_ips', 'referring_subnets', 'referring_pages',
            'referring_pages_nofollow',
        ];

        foreach ($items as $item) {
            $target = $this->extractItemIdentifier($item);
            if (!$target) {
                continue;
            }

            $bulkItem = array_merge($taskData, [
                'target'     => $target,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Map all available fields from the response item
            foreach ($possibleFields as $field) {
                if (isset($item[$field])) {
                    $bulkItem[$field] = $item[$field];
                }
            }

            $bulkItems[] = $bulkItem;
        }

        $batchStats = $this->batchInsertOrUpdateBulkItems($bulkItems);

        return [
            'bulk_items'     => count($bulkItems),
            'items_inserted' => $batchStats['items_inserted'],
            'items_updated'  => $batchStats['items_updated'],
            'items_skipped'  => $batchStats['items_skipped'],
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
            'bulk_items'     => 0,
            'items_inserted' => 0,
            'items_updated'  => 0,
            'items_skipped'  => 0,
            'total_items'    => 0,
        ];

        foreach ($responseBody['tasks'] as $task) {
            if (!isset($task['result'])) {
                continue;
            }

            $taskData                = [];
            $taskData['task_id']     = $task['id'] ?? null;
            $taskData['response_id'] = $response->id;

            foreach ($task['result'] as $result) {
                if (!isset($result['items'])) {
                    continue;
                }

                $stats['total_items'] += count($result['items']);

                $mergedTaskData = array_merge($taskData, $this->extractMetadata($result));
                $mergedTaskData = $this->ensureDefaults($mergedTaskData);

                $itemStats = $this->processBulkItems($result['items'], $mergedTaskData);
                $stats['bulk_items'] += $itemStats['bulk_items'];
                $stats['items_inserted'] += $itemStats['items_inserted'];
                $stats['items_updated'] += $itemStats['items_updated'];
                $stats['items_skipped'] += $itemStats['items_skipped'];
            }
        }

        return $stats;
    }

    /**
     * Process unprocessed DataForSEO backlinks bulk responses
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

        Log::debug('Processing DataForSEO backlinks bulk responses', [
            'count' => $responses->count(),
            'limit' => $limit,
        ]);

        $stats = [
            'processed_responses' => 0,
            'bulk_items'          => 0,
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
                    $stats['bulk_items'] += $responseStats['bulk_items'];
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
                                'status'         => 'OK',
                                'error'          => null,
                                'bulk_items'     => $responseStats['bulk_items'],
                                'items_inserted' => $responseStats['items_inserted'],
                                'items_updated'  => $responseStats['items_updated'],
                                'items_skipped'  => $responseStats['items_skipped'],
                                'total_items'    => $responseStats['total_items'],
                            ], JSON_PRETTY_PRINT),
                        ]);
                });
            } catch (\Exception $e) {
                Log::error('Failed to process DataForSEO backlinks bulk response', [
                    'response_id' => $response->id,
                    'error'       => $e->getMessage(),
                ]);

                $stats['errors']++;

                DB::table($tableName)
                    ->where('id', $response->id)
                    ->update([
                        'processed_at'     => now(),
                        'processed_status' => json_encode([
                            'status'         => 'ERROR',
                            'error'          => $e->getMessage(),
                            'bulk_items'     => 0,
                            'items_inserted' => 0,
                            'items_updated'  => 0,
                            'items_skipped'  => 0,
                            'total_items'    => 0,
                        ], JSON_PRETTY_PRINT),
                    ]);
            }
        }

        Log::debug('DataForSEO backlinks bulk processing completed', $stats);

        return $stats;
    }
}

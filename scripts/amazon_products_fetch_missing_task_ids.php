<?php

/**
 * Amazon Products Fetch Missing Task IDs Script
 *
 * There was some issue with webhook postsbacks not being received.
 *
 * scripts/amazon_browse_nodes_search.php was supposed to send requests to the DataForSEO API. WHich would
 * then trigger a postback to our webhook endpoint. However, the large majority of the postbacks were not received.
 *
 * 'merchant/amazon/products/task_get/advanced%' had 956 rows. While 'merchant/amazon/products/task_post%' had 34647.
 *
 * This script fetches the missing task IDs.
 */

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/../examples/bootstrap.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$start = microtime(true);

// Configuration
$limit = 5;

echo "=== Amazon Products Fetch Missing Task IDs Script ===\n";
echo "Limit: {$limit} tasks per execution\n\n";

// Initialize DataForSEO client
$dfs = new DataForSeoApiClient();
$dfs->setTimeout(120);

// Statistics
$stats = [
    'fetched' => 0,
    'cached'  => 0,
    'failed'  => 0,
];

// Get table name
$tableName = $dfs->getTableName();

// Query for task_post entries to process
$taskPosts = DB::table($tableName)
    ->where('endpoint', 'merchant/amazon/products/task_post')
    ->whereNull('processed_at')
    ->orderBy('id')
    ->limit($limit)
    ->get(['id', 'response_body', 'attributes']);

if ($taskPosts->isEmpty()) {
    echo "No task_post entries to process.\n";
    echo "\n=== Script Complete ===\n";
    exit(0);
}

echo "Processing {$taskPosts->count()} task_post entries...\n\n";

// Process each task_post entry
foreach ($taskPosts as $index => $taskPost) {
    $num   = $index + 1;
    $rowId = $taskPost->id;

    // Extract task ID from response_body JSON
    $responseData = json_decode($taskPost->response_body, true);
    $taskId       = $responseData['tasks'][0]['id'] ?? null;

    if (!$taskId) {
        echo "[{$num}/{$limit}] Row ID: {$rowId} - ✗ No task ID found\n\n";
        $stats['failed']++;

        continue;
    }

    echo "[{$num}/{$limit}] Row ID: {$rowId}\n";
    echo "  Task ID: {$taskId}\n";
    echo "  Keyword: {$taskPost->attributes}\n";

    // Check if task ID already exists in the table
    $existingTaskGet = DB::table($tableName)
        ->where('endpoint', 'like', 'merchant/amazon/products/task_get/advanced%')
        ->where('attributes', $taskId)
        ->first(['id']);

    if ($existingTaskGet) {
        echo "  → Already fetched (existing row ID: {$existingTaskGet->id})\n";
        $stats['cached']++;

        // Mark task_post row as processed
        DB::table($tableName)
            ->where('id', $rowId)
            ->update([
                'processed_at'     => now(),
                'processed_status' => json_encode(['status' => "Already fetched: {$taskId}", 'existing_row_id' => $existingTaskGet->id]),
            ]);

        echo "  ✓ Marked task_post row as processed\n\n";

        continue;
    }

    try {
        $result = $dfs->merchantAmazonProductsTaskGetAdvanced($taskId);

        // Check if result contains actual data
        $response = $result['response'];
        $json     = $response->json();

        if (isset($json['tasks'][0]['result']) && $json['tasks'][0]['result'] !== null) {
            echo "  → Task result fetched\n";
            $processedStatus = "Task result fetched: {$taskId}";
            $stats['fetched']++;
        } else {
            echo "  → Already cached or no result\n";
            $processedStatus = "Already cached: {$taskId}";
            $stats['cached']++;
        }

        // Mark task_post row as processed
        DB::table($tableName)
            ->where('id', $rowId)
            ->update([
                'processed_at'     => now(),
                'processed_status' => json_encode(['status' => $processedStatus]),
            ]);

        echo "  ✓ Marked task_post row as processed\n";
    } catch (\Exception $e) {
        echo "  ✗ Error: {$e->getMessage()}\n";
        Log::error('Failed to fetch Amazon products task result', [
            'row_id'  => $rowId,
            'task_id' => $taskId,
            'error'   => $e->getMessage(),
        ]);

        // Mark task_post row with error status
        DB::table($tableName)
            ->where('id', $rowId)
            ->update([
                'processed_at'     => now(),
                'processed_status' => json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]),
            ]);

        $stats['failed']++;
    }

    echo "\n";
}

echo "=== Processing Summary ===\n";
print_r($stats);
echo "Total: {$taskPosts->count()}\n";

echo "\n=== Script Complete ===\n";

$end = microtime(true);
echo 'Time taken: ' . ($end - $start) . " seconds\n";

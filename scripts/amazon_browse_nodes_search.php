<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/../examples/bootstrap.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$start = microtime(true);

// Configuration
$limit = 20;

echo "=== Amazon Browse Nodes Search Script ===\n";
echo "Limit: {$limit} searches per execution\n\n";

// Initialize DataForSEO client
$dfs = new DataForSeoApiClient();

// Statistics
$stats = [
    'posted' => 0,
    'cached' => 0,
    'failed' => 0,
];

// Query for browse nodes to process
$browseNodes = DB::table('amazon_browse_nodes')
    ->whereNull('processed_at')
    ->orderBy('id')
    ->limit($limit)
    ->get(['id', 'browse_node_id', 'name', 'parent_id', 'level']);

if ($browseNodes->isEmpty()) {
    echo "No browse nodes to process.\n";
    echo "\n=== Script Complete ===\n";
    exit(0);
}

echo "Processing {$browseNodes->count()} browse nodes...\n\n";

// Process each browse node
foreach ($browseNodes as $index => $node) {
    $num          = $index + 1;
    $rowId        = $node->id;
    $browseNodeId = $node->browse_node_id;
    $keyword      = $node->name;
    $parentId     = $node->parent_id;
    $level        = $node->level;
    echo "[{$num}/{$limit}] Processing: {$keyword} (Row ID: {$rowId}, Browse Node ID: {$browseNodeId}, Parent: {$parentId}, Level: {$level})\n";

    // Build the URL
    $url = "https://www.amazon.com/s/?field-keywords={$keyword}&language=en_US&node={$parentId}";

    echo "  Keyword: {$keyword}\n";
    echo "  URL: {$url}\n";

    try {
        $result = $dfs->merchantAmazonProductsStandardAdvanced(
            keyword: $keyword,
            url: $url,
            usePostback: true,
            postTaskIfNotCached: true
        );

        // Result is always an array when postTaskIfNotCached is true
        // Check if it's a task post response or cached data
        $response = $result['response'];
        $json     = $response->json();

        // Task post responses have tasks[0].result === null and result_count === 0
        // Cached responses have tasks[0].result as array with actual data
        if ($json['tasks'][0]['result'] === null) {
            $taskId = $json['tasks'][0]['id'];
            echo "  → Task posted: {$taskId}\n";
            $processedStatus = "Task posted: {$taskId}";
            $stats['posted']++;
        } else {
            echo "  → Already cached\n";
            $processedStatus = 'Already cached';
            $stats['cached']++;
        }

        // Update the browse node record
        DB::table('amazon_browse_nodes')
            ->where('id', $node->id)
            ->update([
                'processed_at'     => now(),
                'processed_status' => json_encode(['status' => $processedStatus]),
            ]);

        echo "  ✓ Updated browse node record\n";
    } catch (\Exception $e) {
        echo "  ✗ Error: {$e->getMessage()}\n";
        Log::error('Failed to post Amazon browse node search', [
            'row_id'         => $node->id,
            'browse_node_id' => $browseNodeId,
            'name'           => $keyword,
            'parent_id'      => $parentId,
            'error'          => $e->getMessage(),
        ]);

        // Update the browse node record with error status
        DB::table('amazon_browse_nodes')
            ->where('id', $node->id)
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
echo "Total: {$browseNodes->count()}\n";

echo "\n=== Script Complete ===\n";

$end = microtime(true);
echo 'Time taken: ' . ($end - $start) . " seconds\n";

<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/../examples/bootstrap.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use FOfX\Utility\AmazonProductPageParser;
use FOfX\Helper;

ini_set('memory_limit', -1);

$start = microtime(true);

// Configuration
$maxRank      = 3;
$batchSize    = 5;
$numBatches   = 2;
$delaySeconds = 3;

echo "=== Amazon ASIN Download and Parse Script (Parallel) ===\n";
echo "Max Rank: {$maxRank}\n";
echo "Configured: batchSize={$batchSize}, numBatches={$numBatches}, delaySeconds={$delaySeconds}s\n\n";

// Initialize clients
$zyte   = new ZyteApiClient();
$parser = new AmazonProductPageParser();

// Statistics
$stats = [
    'downloaded' => 0,
    'cached'     => 0,
    'parsed'     => 0,
    'inserted'   => 0,
    'skipped'    => 0,
    'errors'     => 0,
];

// Process in batches
for ($batch = 1; $batch <= $numBatches; $batch++) {
    echo "\n=== Batch {$batch}/{$numBatches} ===\n";

    // Query for ASINs to process
    $asins = DB::table('dataforseo_merchant_amazon_products_items')
        ->whereNull('processed_at')
        ->where('rank_absolute', '<=', $maxRank)
        ->whereNotNull('data_asin')
        ->orderBy('id')
        ->limit($batchSize)
        ->get(['id', 'data_asin', 'rank_absolute', 'keyword']);

    if ($asins->isEmpty()) {
        echo "No more ASINs to process. Stopping at batch {$batch}/{$numBatches}.\n";

        break;
    }

    echo "Processing {$asins->count()} ASINs in parallel...\n";

    // Build jobs and URL→rows map (handles duplicate ASINs)
    $jobs      = [];
    $urlToRows = [];
    foreach ($asins as $row) {
        $asin   = $row->data_asin;
        $url    = "https://www.amazon.com/dp/{$asin}";
        $jobs[] = ['url' => $url];

        // Store as array to handle duplicate ASINs
        if (!isset($urlToRows[$url])) {
            $urlToRows[$url] = [];
        }
        $urlToRows[$url][] = $row;

        echo "  Queued ASIN {$asin} (Rank: {$row->rank_absolute}, Keyword: {$row->keyword})\n";
    }

    try {
        $results = $zyte->extractHttpResponseBodyParallel($jobs);
    } catch (\Throwable $e) {
        // Catastrophic failure for the whole batch; mark all as error
        echo "Batch request error: {$e->getMessage()}\n";
        foreach ($asins as $row) {
            DB::table('dataforseo_merchant_amazon_products_items')
                ->where('id', $row->id)
                ->update([
                    'processed_at'     => now(),
                    'processed_status' => 'ERROR (Batch request error)',
                ]);
            $stats['errors']++;
        }

        // Continue to next batch after delay
        if ($batch < $numBatches) {
            echo "Sleeping up to {$delaySeconds}s before next batch...\n\n";
            Helper\rand_sleep($delaySeconds);
        }

        continue;
    }

    // Handle per-result processing
    foreach ($results as $res) {
        // Get URL from attributes
        $url  = $res['request']['attributes'] ?? null;
        $rows = $urlToRows[$url] ?? [];

        if (empty($rows)) {
            echo "\n  Warning: No rows found for URL {$url}\n";

            continue;
        }

        $statusCode = $res['response_status_code'] ?? (method_exists($res['response'] ?? null, 'status') ? $res['response']->status() : null);
        $isCached   = $res['is_cached'] ?? false;

        // Process all rows that share this URL (handles duplicate ASINs)
        foreach ($rows as $row) {
            $asin = $row->data_asin;

            echo "\n  Processing ASIN {$asin}: {$url}\n";

            if ((int)$statusCode === 200) {
                try {
                    $json        = $res['response']->json();
                    $attributes3 = $res['request']['attributes3'] ?? null;

                    // Handle both httpResponseBody (base64-encoded) and browserHtml (plain) responses
                    if ($attributes3 === 'httpResponseBody') {
                        $html = isset($json['httpResponseBody']) ? base64_decode($json['httpResponseBody']) : null;
                    } else {
                        // Fallback to browserHtml for cached responses from before the change
                        $html = $json['browserHtml'] ?? null;
                    }

                    if ($isCached) {
                        echo "    → Cached\n";
                        $stats['cached']++;
                    } else {
                        echo "    → Downloaded\n";
                        $stats['downloaded']++;
                    }

                    if ($html === null) {
                        echo "    → Error: No HTML in response\n";
                        Log::error('No HTML in Zyte response', [
                            'asin'        => $asin,
                            'url'         => $url,
                            'attributes3' => $attributes3,
                        ]);
                        $stats['errors']++;

                        DB::table('dataforseo_merchant_amazon_products_items')
                            ->where('id', $row->id)
                            ->update([
                                'processed_at'     => now(),
                                'processed_status' => 'ERROR (No HTML in response)',
                            ]);

                        continue;
                    }

                    // Parse the HTML
                    echo "    → Parsing...\n";
                    $parsedData = $parser->parseAll($html);
                    $stats['parsed']++;

                    // Insert into amazon_products table
                    echo "    → Inserting...\n";
                    $insertResult = $parser->insertProduct($parsedData);

                    if ($insertResult['inserted'] > 0) {
                        echo "    → Inserted: {$insertResult['asin']}\n";
                        $stats['inserted']++;
                    } else {
                        echo "    → Skipped: {$insertResult['reason']}\n";
                        $stats['skipped']++;
                    }

                    // Mark as processed
                    DB::table('dataforseo_merchant_amazon_products_items')
                        ->where('id', $row->id)
                        ->update([
                            'processed_at'     => now(),
                            'processed_status' => 'Success - insertProduct()',
                        ]);

                    echo "    ✓ Complete\n";
                } catch (\Throwable $e) {
                    echo "    ✗ Error: {$e->getMessage()}\n";
                    Log::error('Failed to process ASIN', [
                        'asin'  => $asin,
                        'url'   => $url,
                        'error' => $e->getMessage(),
                    ]);
                    $stats['errors']++;

                    DB::table('dataforseo_merchant_amazon_products_items')
                        ->where('id', $row->id)
                        ->update([
                            'processed_at'     => now(),
                            'processed_status' => 'ERROR (Failed to process ASIN)',
                        ]);
                }
            } else {
                echo "    ✗ HTTP {$statusCode}\n";
                Log::error('HTTP error for ASIN', [
                    'asin'        => $asin,
                    'url'         => $url,
                    'status_code' => $statusCode,
                ]);
                $stats['errors']++;

                DB::table('dataforseo_merchant_amazon_products_items')
                    ->where('id', $row->id)
                    ->update([
                        'processed_at'     => now(),
                        'processed_status' => 'ERROR (HTTP error for ASIN)',
                    ]);
            }
        }
    }

    // Delay before next batch
    if ($batch < $numBatches) {
        echo "\nSleeping up to {$delaySeconds}s before next batch...\n";
        Helper\rand_sleep($delaySeconds);
    }
}

echo "\n=== Processing Complete ===\n";
echo "Downloaded: {$stats['downloaded']}\n";
echo "Cached: {$stats['cached']}\n";
echo "Parsed: {$stats['parsed']}\n";
echo "Inserted: {$stats['inserted']}\n";
echo "Skipped: {$stats['skipped']}\n";
echo "Errors: {$stats['errors']}\n";

$end = microtime(true);
echo 'Time taken: ' . ($end - $start) . " seconds\n";

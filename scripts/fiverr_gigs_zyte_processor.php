<?php

/**
 * Fiverr Gigs Zyte Downloader (parallel batches)
 *
 * - Reads unprocessed gig URLs from fiverr_listings_gigs
 * - Downloads via ZyteApiClient::extractHttpResponseBodyParallel() in batches
 * - Updates processed_at and processed_status
 * - Defaults: batchSize=5, numBatches=3 (15 requests per run), delaySeconds=5
 */

declare(strict_types=1);

require_once __DIR__ . '/../examples/bootstrap.php';

use FOfX\ApiCache\ZyteApiClient;
use FOfX\Utility\FiverrJsonImporter;
use Illuminate\Support\Facades\DB;
use FOfX\Utility;
use FOfX\Helper;

use function FOfX\Utility\ensure_table_exists;

ini_set('memory_limit', -1);

/**
 * Safely encode data to JSON, handling UTF-8 errors
 *
 * @param array $data   Data to encode
 * @param bool  $pretty Whether to use JSON_PRETTY_PRINT
 *
 * @return string JSON string (never returns false)
 */
function safe_json_encode(array $data, bool $pretty = true): string
{
    $flags = JSON_UNESCAPED_SLASHES
           | JSON_INVALID_UTF8_SUBSTITUTE  // Auto-substitute invalid UTF-8 with \ufffd
           | JSON_THROW_ON_ERROR;          // Throw instead of returning false

    if ($pretty) {
        $flags |= JSON_PRETTY_PRINT;
    }

    try {
        return json_encode($data, $flags);
    } catch (\JsonException $e) {
        // Fallback: create error JSON with safe data only
        $errorData = [
            'status'          => 'ERROR',
            'error_type'      => 'JSON_ENCODE_ERROR',
            'error'           => $e->getMessage(),
            'original_status' => $data['status'] ?? 'UNKNOWN',
        ];

        try {
            // Try without pretty print (simpler, less likely to fail)
            return json_encode($errorData, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Absolute last resort: known-good literal
            return '{"status":"ENCODE_ERROR","error":"Critical JSON encoding failure"}';
        }
    }
}

$start = microtime(true);

echo "=== Fiverr Gigs Zyte Downloader (Parallel) ===\n";

$batchSize    = 5;
$numBatches   = 2;
$delaySeconds = 3;
$lowPos       = 0;
$highPos      = 7;

echo "Configured: batchSize={$batchSize}, numBatches={$numBatches}, delaySeconds={$delaySeconds}s\n\n";

// Ensure Zyte cache tables exist (lightweight safety)
ensure_table_exists('api_cache_zyte_responses', __DIR__ . '/../database/migrations/2025_08_23_000033_create_api_cache_zyte_responses_table.php');
ensure_table_exists('api_cache_zyte_responses_compressed', __DIR__ . '/../database/migrations/2025_08_23_000034_create_api_cache_zyte_responses_table_compressed.php');

$client = new ZyteApiClient();
$client->setTimeout(120);

$jsonImporter = new FiverrJsonImporter();

// Ensure required tables exist
ensure_table_exists('fiverr_gigs', $jsonImporter->getFiverrGigsMigrationPath());
ensure_table_exists('fiverr_listings_gigs', $jsonImporter->getFiverrListingsGigsMigrationPath());

$stats = ['processed' => 0, 'errors' => 0, 'is_cached' => 0];

for ($batch = 1; $batch <= $numBatches; $batch++) {
    // Pull next batch of unprocessed gigs
    $gigs = DB::table('fiverr_listings_gigs')
        ->whereNull('processed_at')
        ->whereNotNull('gig_url')
        ->whereBetween('pos', [$lowPos, $highPos])
        ->distinct() // Deduplicate to avoid parallel processing errors due to duplicate gig_url values
        ->limit($batchSize)
        ->get(['gigId', 'gig_url', 'seller_name', 'cached_slug']);

    if ($gigs->isEmpty()) {
        echo "No more gigs to process. Stopping at batch {$batch}/{$numBatches}.\n";

        break;
    }

    echo "\nBatch {$batch}/{$numBatches}: processing {$gigs->count()} gigs in parallel...\n";

    // Build jobs and URL→gig map
    $jobs     = [];
    $urlToGig = [];
    foreach ($gigs as $gig) {
        $url            = 'https://www.fiverr.com' . $gig->gig_url;
        $jobs[]         = ['url' => $url];
        $urlToGig[$url] = $gig;
        echo "  Queued gig {$gig->gigId}: {$url}\n";
    }

    try {
        $results = $client->extractHttpResponseBodyParallel($jobs);
    } catch (\Throwable $e) {
        // Catastrophic failure for the whole batch; mark all as error
        echo "Batch error: {$e->getMessage()}\n";
        foreach ($gigs as $gig) {
            $url = 'https://www.fiverr.com' . $gig->gig_url;
            DB::table('fiverr_listings_gigs')
                ->where('gigId', $gig->gigId)
                ->update([
                    'processed_at'     => now(),
                    'processed_status' => safe_json_encode([
                        'status' => 'ERROR',
                        'error'  => 'Batch request failed: ' . $e->getMessage(),
                        'url'    => $url,
                    ]),
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
        $url        = $res['request']['attributes'] ?? null;
        $gig        = $urlToGig[$url];
        $id         = $gig->gigId;
        $statusCode = $res['response_status_code'] ?? (method_exists($res['response'] ?? null, 'status') ? $res['response']->status() : null);
        $isCached   = $res['is_cached'] ?? null;

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

                echo "  Processing gig {$id}: {$url}\n";
                echo "    Attempting to import JSON data...\n";

                // Extract embedded JSON. extract_embedded_json_blocks() expects string input.
                $html        = $html ?? '';
                $blocks      = Utility\extract_embedded_json_blocks($html);
                $filtered    = Utility\filter_json_blocks_by_selector($blocks, 'perseus-initial-props', true);
                $data        = $filtered[0] ?? [];
                $importStats = $jsonImporter->importGigFromArray($data);
                print_r($importStats);

                DB::table('fiverr_listings_gigs')
                    ->where('gigId', $id)
                    ->update([
                        'processed_at'     => now(),
                        'processed_status' => safe_json_encode([
                            'status'      => 'OK',
                            'status_code' => $statusCode,
                            'is_cached'   => $isCached,
                            'url'         => $url,
                        ]),
                    ]);

                echo "    ✓ Done\n";
                $stats['processed']++;
                if ($isCached) {
                    $stats['is_cached']++;
                }
            } catch (\Throwable $e) {
                echo "    ✗ Error: {$e->getMessage()}\n";
                DB::table('fiverr_listings_gigs')
                    ->where('gigId', $id)
                    ->update([
                        'processed_at'     => now(),
                        'processed_status' => safe_json_encode([
                            'status'      => 'ERROR',
                            'error'       => $e->getMessage(),
                            'status_code' => $statusCode,
                            'is_cached'   => $isCached,
                            'url'         => $url,
                        ]),
                    ]);
                $stats['errors']++;
            }
        } else {
            echo "  ✗ HTTP {$statusCode} for gig {$id}: {$url}\n";
            DB::table('fiverr_listings_gigs')
                ->where('gigId', $id)
                ->update([
                    'processed_at'     => now(),
                    'processed_status' => safe_json_encode([
                        'status'      => 'ERROR',
                        'status_code' => $statusCode,
                        'is_cached'   => $isCached,
                        'url'         => $url,
                    ]),
                ]);
            $stats['errors']++;
        }
    }

    if ($batch < $numBatches) {
        echo "Sleeping up to {$delaySeconds}s before next batch...\n\n";
        Helper\rand_sleep($delaySeconds);
    }
}

echo "\n=== Complete ===\n";
echo "Processed: {$stats['processed']}\n";
echo "Cached: {$stats['is_cached']}\n";
echo "Errors: {$stats['errors']}\n";

echo 'Time taken: ' . (microtime(true) - $start) . " seconds\n";

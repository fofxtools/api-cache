<?php

declare(strict_types=1);

/**
 * Fiverr Gigs Zyte Downloader (minimal)
 *
 * - Reads unprocessed gig URLs from fiverr_listings_gigs
 * - Downloads via ZyteApiClient::extractBrowserHtml()
 * - Updates processed_at and processed_status
 * - Default limit = 2
 * - Prints elapsed time
 */

require_once __DIR__ . '/../examples/bootstrap.php';

use FOfX\ApiCache\ZyteApiClient;
use FOfX\Utility\FiverrJsonImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use FOfX\Utility;

use function FOfX\Utility\ensure_table_exists;

$start = microtime(true);

echo "=== Fiverr Gigs Zyte Downloader ===\n";

$limit = 3;

// Ensure Zyte cache tables exist (lightweight safety)
ensure_table_exists('api_cache_zyte_responses', __DIR__ . '/../database/migrations/2025_08_23_000033_create_api_cache_zyte_responses_table.php');
ensure_table_exists('api_cache_zyte_responses_compressed', __DIR__ . '/../database/migrations/2025_08_23_000034_create_api_cache_zyte_responses_table_compressed.php');

$client = new ZyteApiClient();
$client->setTimeout(120);

$jsonImporter = new FiverrJsonImporter();

// Ensure required tables exist
ensure_table_exists('fiverr_gigs', $jsonImporter->getFiverrGigsMigrationPath());
ensure_table_exists('fiverr_listings_gigs', $jsonImporter->getFiverrListingsGigsMigrationPath());

// Pull unprocessed gigs
$gigs = DB::table('fiverr_listings_gigs')
    ->whereNull('processed_at')
    ->whereNotNull('gig_url')
    ->limit($limit)
    ->get(['gigId', 'gig_url', 'seller_name', 'cached_slug']);

echo "Found {$gigs->count()} gig URLs to download.\n\n";

$stats = ['processed' => 0, 'errors' => 0, 'is_cached' => 0];

foreach ($gigs as $gig) {
    $id  = $gig->gigId;
    $url = 'https://www.fiverr.com' . $gig->gig_url;
    echo "Processing gig {$id}: {$url}\n\n";

    try {
        $response   = $client->extractBrowserHtml($url);
        $statusCode = $response['response_status_code'] ?? (method_exists($response['response'] ?? null, 'status') ? $response['response']->status() : null);
        $isCached   = $response['is_cached'] ?? null;

        $html = $response['response']->json()['browserHtml'];

        // Save filtered JSON to file
        /*
        $folder = 'fiverr_gigs_embedded_json';
        $filename = "{$id}_{$gig->seller_name}-{$gig->cached_slug}.json";
        $filePath = $folder . '/' . $filename;
        $savedFile = Utility\save_json_blocks_to_file($html, $filePath, 'perseus-initial-props');
        $fullPath = Storage::disk('local')->path($savedFile);
        */

        // Try to import the JSON data
        echo "  Attempting to import JSON data...\n";

        //$importStats = $jsonImporter->importGigFromFile($fullPath);
        $blocks      = Utility\extract_embedded_json_blocks($html);
        $filtered    = Utility\filter_json_blocks_by_selector($blocks, 'perseus-initial-props', true);
        $data        = $filtered[0] ?? [];
        $importStats = $jsonImporter->importGigFromArray($data);
        print_r($importStats);

        DB::table('fiverr_listings_gigs')
            ->where('gigId', $id)
            ->update([
                'processed_at'     => now(),
                'processed_status' => json_encode([
                    'status'      => 'OK',
                    'status_code' => $statusCode,
                    'is_cached'   => $isCached,
                    'url'         => $url,
                ], JSON_PRETTY_PRINT),
            ]);

        echo "  ✓ Done\n\n";
        $stats['processed']++;
        if ($isCached) {
            $stats['is_cached']++;
        }
    } catch (\Throwable $e) {
        echo '  ✗ Error: ' . $e->getMessage() . "\n\n";
        $stats['errors']++;

        DB::table('fiverr_listings_gigs')
            ->where('gigId', $id)
            ->update([
                'processed_at'     => now(),
                'processed_status' => json_encode([
                    'status' => 'ERROR',
                    'error'  => $e->getMessage(),
                    'url'    => $url,
                ], JSON_PRETTY_PRINT),
            ]);
    }
}

echo "=== Complete ===\n";
echo "Processed: {$stats['processed']}\n";
echo "Cached: {$stats['is_cached']}\n";
echo "Errors: {$stats['errors']}\n";

echo 'Time taken: ' . (microtime(true) - $start) . " seconds\n";

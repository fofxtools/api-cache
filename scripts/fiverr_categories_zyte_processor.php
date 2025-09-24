<?php

declare(strict_types=1);

/**
 * Fiverr Zyte Processor Script
 *
 * Downloads URLs from fiverr_sitemap_categories table using Zyte API,
 * then processes the downloaded content using FiverrJsonImporter.
 */

require_once __DIR__ . '/../examples/bootstrap.php';

use FOfX\ApiCache\ZyteApiClient;
use FOfX\Utility\FiverrJsonImporter;
use FOfX\Utility\FiverrSitemapImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use FOfX\Utility;

use function FOfX\Utility\ensure_table_exists;

$start = microtime(true);

echo "=== Fiverr Zyte Processor ===\n";

$importer = new FiverrSitemapImporter();
$importer->setBatchSize(500);
$limit = 2;
$delay = 500000; // 0.5 seconds

$sitemapImporter = new FiverrSitemapImporter();

// Ensure required tables exist
ensure_table_exists('fiverr_sitemap_categories', $sitemapImporter->getCategoriesMigrationPath());
ensure_table_exists('fiverr_sitemap_tags', $sitemapImporter->getTagsMigrationPath());

// Populate fiverr_sitemap_categories if empty
if (DB::table('fiverr_sitemap_categories')->count() === 0) {
    echo "Populating fiverr_sitemap_categories...\n\n";
    $importer->setCategoriesSitemapPath(__DIR__ . '/../vendor/fofx/utility/resources/sitemap_categories.xml');
    $stats = $importer->importCategories();
    print_r($stats);
    echo "\nDone.\n\n";
}

// Populate fiverr_sitemap_tags if empty
if (DB::table('fiverr_sitemap_tags')->count() === 0) {
    echo "Populating fiverr_sitemap_tags...\n\n";
    $importer->setTagsSitemapPath(__DIR__ . '/../vendor/fofx/utility/resources/sitemap_tags.xml');
    $stats = $importer->importTags();
    print_r($stats);
    echo "\nDone.\n\n";
}

// Initialize clients
$zyteClient = new ZyteApiClient();
$zyteClient->setTimeout(120);

// Ensure Zyte tables exist
ensure_table_exists('api_cache_zyte_responses', __DIR__ . '/../database/migrations/2025_08_23_000033_create_api_cache_zyte_responses_table.php');
ensure_table_exists('api_cache_zyte_responses_compressed', __DIR__ . '/../database/migrations/2025_08_23_000034_create_api_cache_zyte_responses_table_compressed.php');

$jsonImporter = new FiverrJsonImporter();

// Ensure required tables exist
ensure_table_exists('fiverr_listings', $jsonImporter->getFiverrListingsMigrationPath());
ensure_table_exists('fiverr_gigs', $jsonImporter->getFiverrGigsMigrationPath());
ensure_table_exists('fiverr_seller_profiles', $jsonImporter->getFiverrSellerProfilesMigrationPath());
ensure_table_exists('fiverr_listings_gigs', $jsonImporter->getFiverrListingsGigsMigrationPath());
ensure_table_exists('fiverr_listings_stats', $jsonImporter->getFiverrListingsStatsMigrationPath());

// Get unprocessed URLs from fiverr_sitemap_categories where slug is not null and category_id is null
$categories = DB::table('fiverr_sitemap_categories')
    ->whereNull('processed_at')
    ->whereNotNull('slug')
    ->whereNull('category_id')
    ->limit($limit)
    ->get(['id', 'url', 'slug']);

if ($categories->isEmpty()) {
    echo "No unprocessed URLs found in fiverr_sitemap_categories table.\n";
    exit(0);
}

echo "Found {$categories->count()} unprocessed URLs to download.\n\n";

$stats = [
    'processed'  => 0,
    'downloaded' => 0,
    'imported'   => 0,
    'errors'     => 0,
];

foreach ($categories as $urlRecord) {
    echo "Processing URL {$urlRecord->id}: {$urlRecord->url}\n";

    try {
        // Download content using Zyte API
        echo "  Downloading with Zyte API...\n";
        $response = $zyteClient->extractBrowserHtml(
            $urlRecord->url
        );

        $html = $response['response']->json()['browserHtml'];

        // Save filtered JSON to file
        /*
        $folder = 'fiverr_categories_embedded_json';
        $filename = "{$urlRecord->id}_{$urlRecord->slug}.json";
        $filePath = $folder . '/' . $filename;
        $savedFile = Utility\save_json_blocks_to_file($html, $filePath, 'perseus-initial-props');
        $fullPath = Storage::disk('local')->path($savedFile);
        $stats['downloaded']++;
        */

        // Try to import the JSON data
        echo "  Attempting to import JSON data...\n";

        //$importStats = $jsonImporter->importListingsFromFile($fullPath);
        $blocks      = Utility\extract_embedded_json_blocks($html);
        $filtered    = Utility\filter_json_blocks_by_selector($blocks, 'perseus-initial-props', true);
        $data        = $filtered[0] ?? [];
        $importStats = $jsonImporter->importListingsFromArray($data);
        print_r($importStats);

        // Mark as processed
        DB::table('fiverr_sitemap_categories')
            ->where('id', $urlRecord->id)
            ->update([
                'processed_at'     => now(),
                'processed_status' => json_encode([
                    'status'       => 'OK',
                    'import_stats' => $importStats,
                ], JSON_PRETTY_PRINT),
            ]);

        echo "  ✓ Completed successfully\n\n";
        $stats['processed']++;
    } catch (Exception $e) {
        echo '  ✗ Error: ' . $e->getMessage() . "\n\n";
        $stats['errors']++;

        // Mark as processed with error
        DB::table('fiverr_sitemap_categories')
            ->where('id', $urlRecord->id)
            ->update([
                'processed_at'     => now(),
                'processed_status' => json_encode([
                    'status'       => 'ERROR',
                    'error'        => $e->getMessage(),
                    'processed_at' => now()->toISOString(),
                ], JSON_PRETTY_PRINT),
            ]);
    }

    // Delay to be respectful
    usleep($delay);
}

echo "=== Processing Complete ===\n";
echo "Processed: {$stats['processed']}\n";
echo "Downloaded: {$stats['downloaded']}\n";
echo "Imported: {$stats['imported']}\n";
echo "Errors: {$stats['errors']}\n";

$end = microtime(true);
echo 'Time taken: ' . ($end - $start) . " seconds\n";

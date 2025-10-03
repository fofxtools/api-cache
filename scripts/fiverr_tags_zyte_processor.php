<?php

declare(strict_types=1);

/**
 * Fiverr Tags Listings Zyte Processor (parallel batches)
 *
 * - Reads unprocessed listing URLs from fiverr_sitemap_tags
 * - Filters by slug LIKE terms list
 * - Downloads via ZyteApiClient::extractBrowserHtmlParallel() in batches
 * - Imports listings JSON via FiverrJsonImporter::importListingsFromArray()
 * - Updates processed_at and processed_status
 * - Defaults: batchSize=5, numBatches=2, delaySeconds=3
 */

require_once __DIR__ . '/../examples/bootstrap.php';

use FOfX\ApiCache\ZyteApiClient;
use FOfX\Utility\FiverrJsonImporter;
use FOfX\Utility\FiverrSitemapImporter;
use Illuminate\Support\Facades\DB;
use FOfX\Utility;
use FOfX\Helper;

use function FOfX\Utility\ensure_table_exists;
use function FOfX\ApiCache\createClientTables;

ini_set('memory_limit', -1);

$start = microtime(true);

echo "=== Fiverr Tags Listings Zyte Processor (Parallel) ===\n";

$batchSize    = 5;
$numBatches   = 2;
$delaySeconds = 3;

$terms = [
    '%children%',
    '%coloring%',
    '%figma%',
    '%godot%',
    '%jrpg%',
    '%kdp%',
    '%keyword%',
    '%kid%',
    '%laravel%',
    '%next%',
    '%phaser%',
    '%php%',
    '%react%',
    '%rpg%maker%',
    '%seo%',
    '%strapi%',
    '%tailwind%',
    '%unity%',
    '%unreal%',
    '%vue%',
    '%wordpress%',
    'grav',
];

echo "Configured: batchSize={$batchSize}, numBatches={$numBatches}, delaySeconds={$delaySeconds}s\n";

echo 'Terms: ' . implode(', ', $terms) . "\n\n";

// Ensure required tables exist
$sitemapImporter = new FiverrSitemapImporter();
ensure_table_exists('fiverr_sitemap_tags', $sitemapImporter->getTagsMigrationPath());

// Ensure Zyte cache tables exist
$clientName = 'zyte';
createClientTables($clientName);

// Ensure import target tables exist
$jsonImporter = new FiverrJsonImporter();
$jsonImporter->setDefaultSourceFormat('tag'); // Set default source_format to tag

ensure_table_exists('fiverr_listings', $jsonImporter->getFiverrListingsMigrationPath());
ensure_table_exists('fiverr_gigs', $jsonImporter->getFiverrGigsMigrationPath());
ensure_table_exists('fiverr_seller_profiles', $jsonImporter->getFiverrSellerProfilesMigrationPath());
ensure_table_exists('fiverr_listings_gigs', $jsonImporter->getFiverrListingsGigsMigrationPath());
ensure_table_exists('fiverr_listings_stats', $jsonImporter->getFiverrListingsStatsMigrationPath());

$client = new ZyteApiClient();
$client->setTimeout(120);

$stats = ['processed' => 0, 'errors' => 0, 'is_cached' => 0];

for ($batch = 1; $batch <= $numBatches; $batch++) {
    // Pull next batch of unprocessed tag URLs matching terms
    $query = DB::table('fiverr_sitemap_tags')
        ->whereNull('processed_at')
        ->whereNotNull('url')
        ->where(function ($q) use ($terms) {
            foreach ($terms as $term) {
                $q->orWhere('slug', 'like', $term);
            }
        })
        ->limit($batchSize);

    $tags = $query->get(['id', 'url', 'slug']);

    if ($tags->isEmpty()) {
        echo "No more tags to process. Stopping at batch {$batch}/{$numBatches}.\n";

        break;
    }

    echo "Batch {$batch}/{$numBatches}: processing {$tags->count()} tag listing URLs in parallel...\n";

    // Build jobs and URL→tag map
    $jobs     = [];
    $urlToTag = [];
    foreach ($tags as $tag) {
        $url            = $tag->url;
        $jobs[]         = ['url' => $url];
        $urlToTag[$url] = $tag;
        echo "  Queued tag {$tag->id} ({$tag->slug}): {$url}\n";
    }

    try {
        $results = $client->extractBrowserHtmlParallel($jobs);
    } catch (\Throwable $e) {
        // Catastrophic failure for the whole batch; mark all as error
        echo "Batch error: {$e->getMessage()}\n";

        foreach ($tags as $tag) {
            DB::table('fiverr_sitemap_tags')
                ->where('id', $tag->id)
                ->update([
                    'processed_at'     => now(),
                    'processed_status' => json_encode([
                        'status' => 'ERROR',
                        'error'  => 'Batch request failed: ' . $e->getMessage(),
                        'url'    => $tag->url,
                        'slug'   => $tag->slug,
                    ], JSON_PRETTY_PRINT),
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
        $url = $res['request']['attributes'] ?? null;
        if (!$url || !isset($urlToTag[$url])) {
            echo "  ✗ Result URL not found in mapping, skipping.\n";
            $stats['errors']++;

            continue;
        }

        $tag        = $urlToTag[$url];
        $id         = $tag->id;
        $statusCode = $res['response_status_code'] ?? (method_exists($res['response'] ?? null, 'status') ? $res['response']->status() : null);
        $isCached   = $res['is_cached'] ?? null;

        if ((int)$statusCode === 200) {
            try {
                $json = $res['response']->json();
                $html = $json['browserHtml'] ?? '';

                echo "  Processing tag {$id} ({$tag->slug}): {$url}\n";
                echo "    Attempting to import JSON data...\n";

                $blocks   = Utility\extract_embedded_json_blocks($html);
                $filtered = Utility\filter_json_blocks_by_selector($blocks, 'perseus-initial-props', true);
                $data     = $filtered[0] ?? [];

                // Transform tag data into listings format and import
                $transformedData        = $jsonImporter->transformTagsPageForImport($data);
                $transformedData['url'] = $url;
                $importStats            = $jsonImporter->importListingsFromArray($transformedData);
                print_r($importStats);

                DB::table('fiverr_sitemap_tags')
                    ->where('id', $id)
                    ->update([
                        'processed_at'     => now(),
                        'processed_status' => json_encode([
                            'status'       => 'OK',
                            'status_code'  => $statusCode,
                            'is_cached'    => $isCached,
                            'url'          => $url,
                            'slug'         => $tag->slug,
                            'import_stats' => $importStats,
                        ], JSON_PRETTY_PRINT),
                    ]);

                echo "    ✓ Done\n";
                $stats['processed']++;
                if ($isCached) {
                    $stats['is_cached']++;
                }
            } catch (\Throwable $e) {
                echo "    ✗ Error: {$e->getMessage()}\n";
                DB::table('fiverr_sitemap_tags')
                    ->where('id', $id)
                    ->update([
                        'processed_at'     => now(),
                        'processed_status' => json_encode([
                            'status'      => 'ERROR',
                            'error'       => $e->getMessage(),
                            'status_code' => $statusCode,
                            'is_cached'   => $isCached,
                            'url'         => $url,
                            'slug'        => $tag->slug,
                        ], JSON_PRETTY_PRINT),
                    ]);
                $stats['errors']++;
            }
        } else {
            echo "  ✗ HTTP {$statusCode} for tag {$id} ({$tag->slug}): {$url}\n";
            DB::table('fiverr_sitemap_tags')
                ->where('id', $id)
                ->update([
                    'processed_at'     => now(),
                    'processed_status' => json_encode([
                        'status'      => 'ERROR',
                        'status_code' => $statusCode,
                        'is_cached'   => $isCached,
                        'url'         => $url,
                        'slug'        => $tag->slug,
                    ], JSON_PRETTY_PRINT),
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

$end = microtime(true);
echo 'Time taken: ' . ($end - $start) . " seconds\n";

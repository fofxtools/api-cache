<?php

declare(strict_types=1);

/**
 * Fiverr Listings URL Backfill Script
 *
 * Backfills the url column in fiverr_listings table by:
 * - Reading processed URLs from fiverr_sitemap_categories and fiverr_sitemap_tags
 * - Extracting listing IDs from cached Zyte responses
 * - Updating fiverr_listings.url where it is currently NULL
 *
 * Process:
 * - Fetch processed sitemap URLs in batches
 * - Extract listing ID from cached HTML response
 * - Update fiverr_listings WHERE listingAttributes__id matches AND url IS NULL
 *
 * MySQL query to update fiverr_listings_stats after running this script:
 *
 * UPDATE fiverr_listings_stats
 * JOIN fiverr_listings ON fiverr_listings_stats.listingAttributes__id = fiverr_listings.listingAttributes__id
 * SET fiverr_listings_stats.url = fiverr_listings.url
 * WHERE fiverr_listings_stats.url IS NULL AND fiverr_listings.url IS NOT NULL;
 */

require_once __DIR__ . '/../examples/bootstrap.php';

use FOfX\ApiCache\ZyteApiClient;
use Illuminate\Support\Facades\DB;
use FOfX\Utility;

ini_set('memory_limit', '-1');

$start = microtime(true);

echo "=== Fiverr Listings URL Backfill ===\n\n";

// Configuration
$batchSize           = 10;
$startCategoryOffset = 0;  // Change this to resume from a specific offset
$startTagOffset      = 0;       // Change this to resume from a specific offset

// Initialize client
$zyteClient = new ZyteApiClient();
$zyteClient->setTimeout(120);

// Stats tracking
$stats = [
    'total_processed'    => 0,
    'categories_updated' => 0,
    'tags_updated'       => 0,
    'already_has_url'    => 0,
    'not_found'          => 0,
    'cache_hits'         => 0,
    'cache_misses'       => 0,
    'errors'             => 0,
];

// Count total rows to process
$totalCategories = DB::table('fiverr_sitemap_categories')
    ->whereNotNull('processed_at')
    ->count();

$totalTags = DB::table('fiverr_sitemap_tags')
    ->whereNotNull('processed_at')
    ->count();

echo "Found {$totalCategories} processed categories and {$totalTags} processed tags\n";
echo "Batch size: {$batchSize}\n\n";

// Process categories in batches
echo "=== Processing Categories ===\n";
if ($startCategoryOffset > 0) {
    echo "Starting from offset {$startCategoryOffset}\n";
}
$categoryBatch       = 0;
$categoriesProcessed = 0;

while (true) {
    $categoryBatch++;
    $offset = $startCategoryOffset + (($categoryBatch - 1) * $batchSize);

    $categories = DB::table('fiverr_sitemap_categories')
        ->whereNotNull('processed_at')
        ->offset($offset)
        ->limit($batchSize)
        ->get(['id', 'url', 'slug']);

    if ($categories->isEmpty()) {
        echo "No more categories to process.\n\n";

        break;
    }

    echo "Batch {$categoryBatch}: Processing {$categories->count()} categories (offset {$offset})...\n";

    foreach ($categories as $category) {
        echo "  Category {$category->id}: {$category->slug}\n";
        $categoriesProcessed++;
        $stats['total_processed']++;

        try {
            // Fetch HTML (should be cached)
            $response    = $zyteClient->extractHttpResponseBody($category->url);
            $isCached    = $response['is_cached'] ?? false;
            $attributes3 = $response['request']['attributes3'] ?? null;

            if ($isCached) {
                $stats['cache_hits']++;
            } else {
                $stats['cache_misses']++;
                echo "    ⚠ Cache miss - making API call\n";
            }

            $json = $response['response']->json();

            // Handle both httpResponseBody (base64-encoded) and browserHtml (plain) responses
            if ($attributes3 === 'httpResponseBody') {
                $html = isset($json['httpResponseBody']) ? base64_decode($json['httpResponseBody']) : null;
            } else {
                // Fallback to browserHtml for cached responses from before the change
                $html = $json['browserHtml'] ?? null;
            }

            // Extract embedded JSON. extract_embedded_json_blocks() expects string input.
            $html     = $html ?? '';
            $blocks   = Utility\extract_embedded_json_blocks($html);
            $filtered = Utility\filter_json_blocks_by_selector($blocks, 'perseus-initial-props', true);
            $data     = $filtered[0] ?? [];

            // Get listing ID from category structure
            $listingId = $data['listingAttributes']['id'] ?? null;

            if (!$listingId) {
                echo "    ✗ No listing ID found in JSON\n";
                $stats['not_found']++;

                continue;
            }

            // Check if listing exists and needs update
            $listing = DB::table('fiverr_listings')
                ->where('listingAttributes__id', $listingId)
                ->first(['id', 'url']);

            if (!$listing) {
                echo "    ✗ No matching listing found (listingAttributes__id={$listingId})\n";
                $stats['not_found']++;
            } elseif ($listing->url !== null) {
                echo "    ℹ Already has URL\n";
                $stats['already_has_url']++;
            } else {
                // Update the listing
                DB::table('fiverr_listings')
                    ->where('listingAttributes__id', $listingId)
                    ->whereNull('url')
                    ->update(['url' => $category->url]);

                echo "    ✓ Updated fiverr_listings.id={$listing->id}\n";
                $stats['categories_updated']++;
            }
        } catch (\Throwable $e) {
            echo "    ✗ Error: {$e->getMessage()}\n";
            $stats['errors']++;
        }
    }

    echo "\n";
}

echo "Processed {$categoriesProcessed} categories total.\n\n";

// Process tags in batches
echo "=== Processing Tags ===\n";
if ($startTagOffset > 0) {
    echo "Starting from offset {$startTagOffset}\n";
}
$tagBatch      = 0;
$tagsProcessed = 0;

while (true) {
    $tagBatch++;
    $offset = $startTagOffset + (($tagBatch - 1) * $batchSize);

    $tags = DB::table('fiverr_sitemap_tags')
        ->whereNotNull('processed_at')
        ->offset($offset)
        ->limit($batchSize)
        ->get(['id', 'url', 'slug']);

    if ($tags->isEmpty()) {
        echo "No more tags to process.\n\n";

        break;
    }

    echo "Batch {$tagBatch}: Processing {$tags->count()} tags (offset {$offset})...\n";

    foreach ($tags as $tag) {
        echo "  Tag {$tag->id}: {$tag->slug}\n";
        $tagsProcessed++;
        $stats['total_processed']++;

        try {
            // Fetch HTML (should be cached)
            $response    = $zyteClient->extractHttpResponseBody($tag->url);
            $isCached    = $response['is_cached'] ?? false;
            $attributes3 = $response['request']['attributes3'] ?? null;

            if ($isCached) {
                $stats['cache_hits']++;
            } else {
                $stats['cache_misses']++;
                echo "    ⚠ Cache miss - making API call\n";
            }

            $json = $response['response']->json();

            // Handle both httpResponseBody (base64-encoded) and browserHtml (plain) responses
            if ($attributes3 === 'httpResponseBody') {
                $html = isset($json['httpResponseBody']) ? base64_decode($json['httpResponseBody']) : null;
            } else {
                // Fallback to browserHtml for cached responses from before the change
                $html = $json['browserHtml'] ?? null;
            }

            // Extract embedded JSON. extract_embedded_json_blocks() expects string input.
            $html     = $html ?? '';
            $blocks   = Utility\extract_embedded_json_blocks($html);
            $filtered = Utility\filter_json_blocks_by_selector($blocks, 'perseus-initial-props', true);
            $data     = $filtered[0] ?? [];

            // Get listing ID from tag structure (at root level, not nested)
            $listingId = $data['id'] ?? null;

            if (!$listingId) {
                echo "    ✗ No listing ID found in JSON\n";
                $stats['not_found']++;

                continue;
            }

            // Check if listing exists and needs update
            $listing = DB::table('fiverr_listings')
                ->where('listingAttributes__id', $listingId)
                ->first(['id', 'url']);

            if (!$listing) {
                echo "    ✗ No matching listing found (listingAttributes__id={$listingId})\n";
                $stats['not_found']++;
            } elseif ($listing->url !== null) {
                echo "    ℹ Already has URL\n";
                $stats['already_has_url']++;
            } else {
                // Update the listing
                DB::table('fiverr_listings')
                    ->where('listingAttributes__id', $listingId)
                    ->whereNull('url')
                    ->update(['url' => $tag->url]);

                echo "    ✓ Updated fiverr_listings.id={$listing->id}\n";
                $stats['tags_updated']++;
            }
        } catch (\Throwable $e) {
            echo "    ✗ Error: {$e->getMessage()}\n";
            $stats['errors']++;
        }
    }

    echo "\n";
}

echo "Processed {$tagsProcessed} tags total.\n\n";

// Summary
echo "=== SUMMARY ===\n";
echo "Total processed: {$stats['total_processed']}\n";
echo "Categories updated: {$stats['categories_updated']}\n";
echo "Tags updated: {$stats['tags_updated']}\n";
echo "Already has URL: {$stats['already_has_url']}\n";
echo "Not found: {$stats['not_found']}\n";
echo "Cache hits: {$stats['cache_hits']}\n";
echo "Cache misses: {$stats['cache_misses']}\n";
echo "Errors: {$stats['errors']}\n";
echo "\n";

$totalUpdated = $stats['categories_updated'] + $stats['tags_updated'];
echo "Total URLs backfilled: {$totalUpdated}\n";

$end = microtime(true);
echo 'Time taken: ' . round($end - $start, 2) . " seconds\n";

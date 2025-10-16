<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/../examples/bootstrap.php';

use Illuminate\Support\Facades\Log;

$start = microtime(true);

// Delay before processing using DataForSeoMerchantAmazonProductsProcessor
$delay   = 0;
$process = true;

// Amazon search keywords
$keywords = [
    'amazon gift cards',
    'blood pressure monitor',
    's\'mores cereal',
    'children\'s books ages 3-5',
    'children\'s action & adventure books',
    'comics & graphic novels',
    'fairy tales, folk tales, & myths',
    'history books',
    'kids coloring books',
    'children\'s coloring books',
    'coloring books for kids ages 4-8',
    'coloring books for kids ages 8-12',
    'kids coloring book animals',
    'kids coloring book princesses',
    'dinosaur coloring books',
    'space coloring book',
    'unicorn coloring books',
    'fairy tale coloring books',
    'educational coloring books',
    'cowboy coloring books',
    'dogs and cats',
    'dogs & cats',
    'kids toys',
    'kid\'s toys',
];

echo "=== Amazon Products Search Script ===\n";
echo "Delay before processing: {$delay} seconds\n";
echo 'Total keywords: ' . count($keywords) . "\n\n";

// Initialize DataForSEO client with 120 second timeout
$dfs = new DataForSeoApiClient();
$dfs->setTimeout(120);

// Post searches
echo "=== Posting Amazon Product Searches ===\n";
$posted = 0;
$cached = 0;
$failed = 0;

foreach ($keywords as $index => $keyword) {
    $num = $index + 1;
    echo "[{$num}/" . count($keywords) . "] Searching: {$keyword}\n";

    try {
        $result = $dfs->merchantAmazonProductsStandardAdvanced(
            keyword: $keyword,
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
            $posted++;
        } else {
            echo "  → Already cached\n";
            $cached++;
        }
    } catch (\Exception $e) {
        echo '  → Error: ' . $e->getMessage() . "\n";
        Log::error('Failed to post Amazon search', [
            'keyword' => $keyword,
            'error'   => $e->getMessage(),
        ]);
        $failed++;
    }

    // Small delay between requests to avoid rate limiting
    usleep(100000); // 0.1 seconds
}

echo "\n=== Posting Summary ===\n";
echo "Posted: {$posted}\n";
echo "Cached: {$cached}\n";
echo "Failed: {$failed}\n";
echo 'Total: ' . count($keywords) . "\n";

// Process responses
if ($process) {
    echo "\n=== Waiting {$delay} seconds for tasks to complete ===\n";
    sleep($delay);

    echo "\n=== Processing Amazon Product Responses ===\n";

    $processor = new DataForSeoMerchantAmazonProductsProcessor();

    // Process all available responses
    $stats = $processor->processResponses();

    echo "\n=== Processing Results ===\n";
    print_r($stats);
}

echo "\n=== Script Complete ===\n";

$end = microtime(true);
echo 'Time taken: ' . ($end - $start) . " seconds\n";

<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

/**
 * Run YouTube API client tests
 *
 * @param bool $compressionEnabled Whether to enable compression for the test
 * @param bool $requestInfo        Whether to enable request info
 * @param bool $responseInfo       Whether to enable response info
 * @param bool $testRateLimiting   Whether to test rate limiting
 *
 * @return void
 */
function runYouTubeTests(bool $compressionEnabled, bool $requestInfo = true, bool $responseInfo = true, bool $testRateLimiting = true): void
{
    echo "\nRunning YouTube API tests with compression " . ($compressionEnabled ? 'enabled' : 'disabled') . "...\n";
    echo str_repeat('-', 80) . "\n";

    // Test client configuration
    $clientName = 'youtube';
    config()->set("api-cache.apis.{$clientName}.rate_limit_max_attempts", 5);
    config()->set("api-cache.apis.{$clientName}.rate_limit_decay_seconds", 60);
    config()->set("api-cache.apis.{$clientName}.compression_enabled", $compressionEnabled);

    // Create response tables for the test client
    $dropExisting = false;
    createClientTables($clientName, $dropExisting);

    // Create client instance. Clear rate limit since we might have run tests before.
    $client = new YouTubeApiClient();
    $client->setTimeout(30);
    $client->clearRateLimit();

    // Test search endpoint
    echo "\nTesting search endpoint...\n";
    $query = 'How to boil an egg';

    try {
        echo "Query: {$query}\n";
        $result = $client->search($query);
        echo format_api_response($result, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing search: {$e->getMessage()}\n";
    }

    // Test videos endpoint by ID
    echo "\nTesting videos endpoint by ID...\n";

    try {
        // Use a known YouTube video ID (first video ever uploaded to YouTube)
        $videoId = 'jNQXAC9IVRw';
        echo "Video ID: {$videoId}\n";
        $result = $client->videos($videoId);
        echo format_api_response($result, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing videos by ID: {$e->getMessage()}\n";
    }

    // Test videos endpoint by chart
    echo "\nTesting videos endpoint by chart...\n";

    try {
        $chart  = 'mostPopular';
        $result = $client->videos(null, $chart);
        echo format_api_response($result, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing videos by chart: {$e->getMessage()}\n";
    }

    // Test cached request
    echo "\nTesting cached request...\n";

    try {
        echo "\nSearching again for (should be cached): {$query}\n";
        $result2 = $client->search($query);
        echo format_api_response($result2, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing caching: {$e->getMessage()}\n";
    }

    // Test rate limiting with caching disabled
    if ($testRateLimiting) {
        echo "\nTesting rate limiting with caching disabled...\n";
        $client->setUseCache(false);

        try {
            for ($i = 1; $i <= 6; $i++) {
                echo "Request {$i}...\n";
                $result = $client->search($query);
                echo format_api_response($result, $requestInfo, $responseInfo);
            }
        } catch (RateLimitException $e) {
            echo "Successfully hit rate limit: {$e->getMessage()}\n";
            echo "Available in: {$e->getAvailableInSeconds()} seconds\n";
        }
    }
}

$start = microtime(true);

$requestInfo      = false;
$responseInfo     = false;
$testRateLimiting = false;

// Run tests without compression
runYouTubeTests(false, $requestInfo, $responseInfo, $testRateLimiting);

// Run tests with compression
runYouTubeTests(true, $requestInfo, $responseInfo, $testRateLimiting);

$end = microtime(true);

echo 'Time taken: ' . ($end - $start) . " seconds\n";

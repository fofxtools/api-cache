<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

/**
 * Run Pixabay API client tests
 *
 * @param bool $compressionEnabled Whether to enable compression for the test
 * @param bool $verbose            Whether to enable verbose output
 *
 * @return void
 */
function runPixabayTests(bool $compressionEnabled, bool $verbose = true): void
{
    echo "\nRunning Pixabay API tests with compression " . ($compressionEnabled ? 'enabled' : 'disabled') . "...\n";
    echo str_repeat('-', 80) . "\n";

    // Test client configuration
    $clientName = 'pixabay';
    config()->set("api-cache.apis.{$clientName}.rate_limit_max_attempts", 10);
    config()->set("api-cache.apis.{$clientName}.rate_limit_decay_seconds", 60);
    config()->set("api-cache.apis.{$clientName}.compression_enabled", $compressionEnabled);

    // Create response tables for the test client
    $dropExisting = false;
    createClientTables($clientName, $dropExisting);

    // Create client instance. Clear rate limit since we might have run tests before.
    $client = new PixabayApiClient();
    $client->setTimeout(30);
    $client->clearRateLimit();

    // Test image search endpoint
    echo "\nTesting image search endpoint...\n";

    try {
        // Test basic search
        echo "Basic image search...\n";
        $result = $client->searchImages('yellow flowers');
        echo format_api_response($result, $verbose);

        // Test search with filters
        echo "\nFiltered image search...\n";
        $result = $client->searchImages(
            'yellow flowers',
            'en',
            null,
            'photo',
            'horizontal',
            'nature',
            800,
            600,
            'yellow',
            true,
            true,
            'latest',
            1,
            5
        );
        echo format_api_response($result, $verbose);
    } catch (\Exception $e) {
        echo "Error testing image search: {$e->getMessage()}\n";
    }

    // Test video search endpoint
    echo "\nTesting video search endpoint...\n";

    try {
        // Test basic video search
        echo "Basic video search...\n";
        $result = $client->searchVideos('sunset');
        echo format_api_response($result, $verbose);

        // Test search with filters
        echo "\nFiltered video search...\n";
        $result = $client->searchVideos(
            'sunset',
            'en',
            null,
            'film',
            'nature',
            1920,
            1080,
            true,
            true,
            'latest',
            1,
            5
        );
        echo format_api_response($result, $verbose);
    } catch (\Exception $e) {
        echo "Error testing video search: {$e->getMessage()}\n";
    }

    // Test cached request
    echo "\nTesting cached request...\n";

    try {
        $query = 'red roses';
        echo "First request...\n";
        $result1 = $client->searchImages($query);
        echo format_api_response($result1, $verbose);

        echo "\nSecond request (should be cached)...\n";
        $result2 = $client->searchImages($query);
        echo format_api_response($result2, $verbose);
    } catch (\Exception $e) {
        echo "Error testing caching: {$e->getMessage()}\n";
    }

    // Test rate limiting with caching disabled
    echo "\nTesting rate limiting with caching disabled...\n";
    $client->setUseCache(false);

    try {
        for ($i = 1; $i <= 10; $i++) {
            echo "Request {$i}...\n";
            $result = $client->searchImages('yellow flowers');
            echo format_api_response($result, $verbose);
        }
    } catch (RateLimitException $e) {
        echo "Successfully hit rate limit: {$e->getMessage()}\n";
        echo "Available in: {$e->getAvailableInSeconds()} seconds\n";
    }
}

// Run tests without compression
runPixabayTests(false);

// Run tests with compression
runPixabayTests(true);

<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

/**
 * Run DataForSEO API client tests
 *
 * @param bool $compressionEnabled Whether to enable compression for the test
 * @param bool $verbose            Whether to enable verbose output
 *
 * @return void
 */
function runDataForSeoTests(bool $compressionEnabled, bool $verbose = true): void
{
    echo "\nRunning DataForSEO API tests with compression " . ($compressionEnabled ? 'enabled' : 'disabled') . "...\n";
    echo str_repeat('-', 80) . "\n";

    // Test client configuration
    $clientName = 'dataforseo';
    config()->set("api-cache.apis.{$clientName}.rate_limit_max_attempts", 10);
    config()->set("api-cache.apis.{$clientName}.rate_limit_decay_seconds", 60);
    config()->set("api-cache.apis.{$clientName}.compression_enabled", $compressionEnabled);

    // Create response tables for the test client
    $dropExisting = false;
    createClientTables($clientName, $dropExisting);

    // Create client instance. Clear rate limit since we might have run tests before.
    $client = new DataForSeoApiClient();
    $client->setTimeout(30);
    $client->clearRateLimit();

    $keyword = 'laravel framework';

    // Test Google SERP regular endpoint
    echo "\nTesting Google SERP regular endpoint...\n";

    try {
        // Test basic search
        echo "Basic SERP search...\n";
        $result = $client->serpGoogleOrganicLiveRegular($keyword);
        echo format_api_response($result, $verbose);

        // Test search with more parameters
        echo "\nDetailed SERP search...\n";
        $result = $client->serpGoogleOrganicLiveRegular(
            $keyword,
            'United States',
            null,
            null,
            'English',
            null,
            'desktop',
            'windows',
            null,
            50,
            null,
            true,
            2,
            null,
            'test-regular-serp'
        );
        echo format_api_response($result, $verbose);
    } catch (\Exception $e) {
        echo "Error testing SERP regular endpoint: {$e->getMessage()}\n";
    }

    // Test Google SERP advanced endpoint
    echo "\nTesting Google SERP advanced endpoint...\n";

    try {
        // Test basic advanced search
        echo "Basic advanced SERP search...\n";
        $result = $client->serpGoogleOrganicLiveAdvanced($keyword);
        echo format_api_response($result, $verbose);

        // Test advanced search with more parameters
        echo "\nDetailed advanced SERP search...\n";
        $result = $client->serpGoogleOrganicLiveAdvanced(
            $keyword,
            null,
            50,
            2,
            'United States',
            null,
            null,
            'English',
            null,
            null,
            'desktop',
            'windows',
            null,
            null,
            null,
            null,
            null,
            null,
            2,
            true,
            null,
            null,
            'dfs-test-tag'
        );
        echo format_api_response($result, $verbose);
    } catch (\Exception $e) {
        echo "Error testing SERP advanced endpoint: {$e->getMessage()}\n";
    }

    // Test Google Autocomplete endpoint
    echo "\nTesting Google Autocomplete endpoint...\n";

    try {
        // Test basic autocomplete search
        echo "Basic autocomplete search...\n";
        $result = $client->serpGoogleAutocompleteLiveAdvanced($keyword);
        echo format_api_response($result, $verbose);

        // Test autocomplete search with more parameters
        echo "\nDetailed autocomplete search...\n";
        $result = $client->serpGoogleAutocompleteLiveAdvanced(keyword: $keyword, cursorPointer: 0, client: 'gws-wiz-serp');

        // Test autocomplete for YouTube
        echo "\nAutocomplete search for YouTube...\n";
        $result = $client->serpGoogleAutocompleteLiveAdvanced(keyword: $keyword, client: 'youtube');
        echo format_api_response($result, $verbose);
    } catch (\Exception $e) {
        echo "Error testing autocomplete endpoint: {$e->getMessage()}\n";
    }

    // Test cached request
    echo "\nTesting cached request...\n";

    try {
        echo "\nSearching again for (should be cached): {$keyword}\n";
        $result = $client->serpGoogleOrganicLiveRegular($keyword);
        echo format_api_response($result, $verbose);
    } catch (\Exception $e) {
        echo "Error testing caching: {$e->getMessage()}\n";
    }

    // Test rate limiting with caching disabled
    echo "\nTesting rate limiting with caching disabled...\n";
    $client->setUseCache(false);

    try {
        for ($i = 1; $i <= 10; $i++) {
            echo "Request {$i}...\n";
            $result = $client->serpGoogleOrganicLiveRegular("php framework {$i}");
            echo format_api_response($result, $verbose);
        }
    } catch (RateLimitException $e) {
        echo "Successfully hit rate limit: {$e->getMessage()}\n";
        echo "Available in: {$e->getAvailableInSeconds()} seconds\n";
    }
}

$start = microtime(true);

// Run tests without compression
runDataForSeoTests(false);

// Run tests with compression
runDataForSeoTests(true);

$end = microtime(true);

echo 'Time taken: ' . ($end - $start) . " seconds\n";

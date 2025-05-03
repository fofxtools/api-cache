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

    // Test Google SERP regular endpoint
    echo "\nTesting Google SERP regular endpoint...\n";

    try {
        // Test basic search
        echo "Basic SERP search...\n";
        $result = $client->serpGoogleOrganicLiveRegular('laravel framework');
        echo format_api_response($result, $verbose);

        // Test search with more parameters
        echo "\nDetailed SERP search...\n";
        $result = $client->serpGoogleOrganicLiveRegular(
            'laravel framework',
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
        $result = $client->serpGoogleOrganicLiveAdvanced('php composer');
        echo format_api_response($result, $verbose);

        // Test advanced search with more parameters
        echo "\nDetailed advanced SERP search...\n";
        $result = $client->serpGoogleOrganicLiveAdvanced(
            'php composer',
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

    // Test cached request
    echo "\nTesting cached request...\n";

    try {
        $query = 'api caching laravel';
        echo "First request...\n";
        $result1 = $client->serpGoogleOrganicLiveRegular($query);
        echo format_api_response($result1, $verbose);

        echo "\nSecond request (should be cached)...\n";
        $result2 = $client->serpGoogleOrganicLiveRegular($query);
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

<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

/**
 * Run OpenPageRank API client tests
 *
 * @param bool $compressionEnabled Whether to enable compression for the test
 * @param bool $requestInfo        Whether to enable request info
 * @param bool $responseInfo       Whether to enable response info
 * @param bool $testRateLimiting   Whether to test rate limiting
 *
 * @return void
 */
function runOpenPageRankTests(bool $compressionEnabled, bool $requestInfo = true, bool $responseInfo = true, bool $testRateLimiting = true): void
{
    echo "\nRunning OpenPageRank API tests with compression " . ($compressionEnabled ? 'enabled' : 'disabled') . "...\n";
    echo str_repeat('-', 80) . "\n";

    // Test client configuration
    $clientName = 'openpagerank';
    config()->set("api-cache.apis.{$clientName}.rate_limit_max_attempts", 5);
    config()->set("api-cache.apis.{$clientName}.rate_limit_decay_seconds", 60);
    config()->set("api-cache.apis.{$clientName}.compression_enabled", $compressionEnabled);

    // Create response tables for the test client
    $dropExisting = false;
    createClientTables($clientName, $dropExisting);

    // Create client instance. Clear rate limit since we might have run tests before.
    $client = new OpenPageRankApiClient();
    $client->setTimeout(30);
    $client->clearRateLimit();

    // Test getPageRank endpoint with a single domain
    echo "\nTesting getPageRank endpoint with a single domain...\n";

    try {
        $result = $client->getPageRank(['google.com']);
        echo format_api_response($result, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing getPageRank with single domain: {$e->getMessage()}\n";
    }

    // Test getPageRank endpoint with multiple domains
    echo "\nTesting getPageRank endpoint with multiple domains...\n";

    try {
        $result = $client->getPageRank(['google.com', 'apple.com', 'example.com']);
        echo format_api_response($result, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing getPageRank with multiple domains: {$e->getMessage()}\n";
    }

    // Test error handling with empty domains array
    echo "\nTesting error handling with empty domains array...\n";

    try {
        $result = $client->getPageRank([]);
        echo "This should not be reached\n";
    } catch (\InvalidArgumentException $e) {
        echo "Successfully caught empty domains array: {$e->getMessage()}\n";
    }

    // Test error handling with too many domains
    echo "\nTesting error handling with too many domains...\n";

    try {
        // Create an array with 101 domains (over the 100 limit)
        $domains = array_map(
            fn ($i) => "domain{$i}.com",
            range(1, 101)
        );

        $result = $client->getPageRank($domains);
        echo "This should not be reached\n";
    } catch (\InvalidArgumentException $e) {
        echo "Successfully caught too many domains: {$e->getMessage()}\n";
    }

    // Test cached request
    echo "\nTesting cached request...\n";

    try {
        $domains = ['example.com'];
        echo "First request...\n";
        $result1 = $client->getPageRank($domains);
        echo format_api_response($result1, $requestInfo, $responseInfo);

        echo "\nSecond request (should be cached)...\n";
        $result2 = $client->getPageRank($domains);
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
                // Use a different domain for each request to avoid caching effects
                $result = $client->getPageRank(["test{$i}.com"]);
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
runOpenPageRankTests(false, $requestInfo, $responseInfo, $testRateLimiting);

// Run tests with compression
runOpenPageRankTests(true, $requestInfo, $responseInfo, $testRateLimiting);

$end = microtime(true);

echo 'Time taken: ' . ($end - $start) . " seconds\n";

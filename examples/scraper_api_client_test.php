<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

/**
 * Run tests for ScraperApiClient
 *
 * @param bool $compressionEnabled Whether to enable compression
 * @param bool $requestInfo        Whether to enable request info
 * @param bool $responseInfo       Whether to enable response info
 * @param bool $testRateLimiting   Whether to test rate limiting
 */
function runScraperApiTests(bool $compressionEnabled, bool $requestInfo = true, bool $responseInfo = true, bool $testRateLimiting = true): void
{
    echo "\nRunning ScraperAPI API tests with compression " . ($compressionEnabled ? 'enabled' : 'disabled') . "...\n";
    echo str_repeat('-', 80) . "\n";

    // Test client configuration
    $clientName = 'scraperapi';
    config()->set("api-cache.apis.{$clientName}.rate_limit_max_attempts", 10);
    config()->set("api-cache.apis.{$clientName}.rate_limit_decay_seconds", 60);
    config()->set("api-cache.apis.{$clientName}.compression_enabled", $compressionEnabled);

    // Create response tables for the test client
    $dropExisting = false;
    createClientTables($clientName, $dropExisting);

    // Create client instance. Clear rate limit since we might have run tests before.
    $client = new ScraperApiClient();
    $client->setTimeout(120);
    $client->clearRateLimit();

    // Get ApiCacheManager instance
    $cacheManager = app(ApiCacheManager::class);

    // Test URLs with different credit costs
    $testUrls = [
        'https://www.example.com'         => 1,
        'https://www.httpbin.org/headers' => 1,
        'https://www.amazon.com'          => 5,
    ];

    $options = [];

    foreach ($testUrls as $url => $expectedCredits) {
        echo "\nTesting URL: {$url}\n";
        echo "Expected credits: {$expectedCredits}\n";
        $remainingAttemptsBefore = $cacheManager->getRemainingAttempts($clientName);
        echo "Remaining attempts before request: {$remainingAttemptsBefore}\n";

        try {
            $result = $client->scrape($url, false, null, $options);

            $remainingAttemptsAfter = $cacheManager->getRemainingAttempts($clientName);
            echo "Remaining attempts after request: {$remainingAttemptsAfter}\n";

            $remainingAttemptsDiff = $remainingAttemptsBefore - $remainingAttemptsAfter;
            $expectedDiff          = $result['is_cached'] ? 0 : $expectedCredits;
            if ($remainingAttemptsDiff !== $expectedDiff) {
                echo "Error: Remaining attempts difference ({$remainingAttemptsDiff}) is not equal to expected difference ({$expectedDiff})\n";
            } else {
                echo "Success: Remaining attempts difference ({$remainingAttemptsDiff}) matches expected ({$expectedDiff})\n";
            }

            echo format_api_response($result, $requestInfo, $responseInfo);
        } catch (\Exception $e) {
            echo "Status: Failed\n";
            echo 'Error: ' . $e->getMessage() . "\n";
        }
    }
}

$start = microtime(true);

$requestInfo      = false;
$responseInfo     = false;
$testRateLimiting = false;

// Run tests without compression
runScraperApiTests(false, $requestInfo, $responseInfo, $testRateLimiting);

// Run tests with compression
runScraperApiTests(true, $requestInfo, $responseInfo, $testRateLimiting);

$end = microtime(true);

echo 'Time taken: ' . ($end - $start) . " seconds\n";

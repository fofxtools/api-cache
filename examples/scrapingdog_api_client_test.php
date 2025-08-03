<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

/**
 * Run Scrapingdog API client tests
 *
 * @param bool $compressionEnabled Whether to enable compression for the test
 * @param bool $requestInfo        Whether to enable request info
 * @param bool $responseInfo       Whether to enable response info
 * @param bool $testRateLimiting   Whether to test rate limiting
 *
 * @return void
 */
function runScrapingdogTests(bool $compressionEnabled, bool $requestInfo = true, bool $responseInfo = true, bool $testRateLimiting = true): void
{
    echo "\nRunning Scrapingdog API tests with compression " . ($compressionEnabled ? 'enabled' : 'disabled') . "...\n";
    echo str_repeat('-', 80) . "\n";

    // Test client configuration
    $clientName           = 'scrapingdog';
    $rateLimitMaxAttempts = 20;
    config()->set("api-cache.apis.{$clientName}.rate_limit_max_attempts", $rateLimitMaxAttempts);
    config()->set("api-cache.apis.{$clientName}.rate_limit_decay_seconds", 60);
    config()->set("api-cache.apis.{$clientName}.compression_enabled", $compressionEnabled);

    // Create response tables for the test client
    $dropExisting = false;
    createClientTables($clientName, $dropExisting);

    // Create client instance. Clear rate limit since we might have run tests before.
    $client = new ScrapingdogApiClient();
    $client->setTimeout(120);
    $client->clearRateLimit();

    // Get ApiCacheManager instance
    $cacheManager = app(ApiCacheManager::class);

    $url = 'https://example.com';

    // Define test cases with named arguments
    $testCases = [
        'basic_scraping' => [
            'name' => 'Basic scraping',
            'args' => [
                'url' => $url,
            ],
        ],
        'advanced_scraping' => [
            'name' => 'Advanced scraping options',
            'args' => [
                'url'            => $url,
                'dynamic'        => false,
                'premium'        => false,
                'custom_headers' => false,
                'wait'           => 5000,
                'country'        => 'us',
                'session_number' => '12345',
                'image'          => false,
                'markdown'       => true,
            ],
        ],
        'dynamic_scraping' => [
            'name' => 'Dynamic scraping',
            'args' => [
                'url'     => $url,
                'dynamic' => true,
            ],
        ],
        'ai_query' => [
            'name' => 'Scraping with AI query',
            'args' => [
                'url'      => $url,
                'ai_query' => 'Extract the main heading and description',
            ],
        ],
        'ai_extract_rules' => [
            'name' => 'Scraping with AI extract rules',
            'args' => [
                'url'              => $url,
                'ai_extract_rules' => [
                    'header'                 => 'Extract the main heading',
                    'first_link_url'         => 'Extract the first link URL',
                    'first_link_anchor_text' => 'Extract the first link anchor text',
                ],
            ],
        ],
    ];

    // Test scraping endpoints
    echo "\nTesting scraping endpoints for {$url}...\n";

    try {
        // Run all test cases
        foreach ($testCases as $key => $testCase) {
            echo "\n{$testCase['name']}...\n";
            $remainingAttemptsBefore = $cacheManager->getRemainingAttempts($clientName);
            echo 'Remaining attempts before request: ' . $remainingAttemptsBefore . "\n";

            // Call scrape() with named arguments
            $args   = $testCase['args'];
            $result = $client->scrape(...$args);

            $remainingAttemptsAfter = $cacheManager->getRemainingAttempts($clientName);
            echo 'Remaining attempts after request: ' . $remainingAttemptsAfter . "\n";

            // Credit verification for all test cases
            $remainingAttemptsDiff = $remainingAttemptsBefore - $remainingAttemptsAfter;

            // Extract all possible parameters for calculateCredits from the test case
            $dynamic        = isset($testCase['args']['dynamic']) ? $testCase['args']['dynamic'] : null;
            $premium        = isset($testCase['args']['premium']) ? $testCase['args']['premium'] : null;
            $aiQuery        = isset($testCase['args']['ai_query']) ? $testCase['args']['ai_query'] : null;
            $aiExtractRules = isset($testCase['args']['ai_extract_rules']) ? $testCase['args']['ai_extract_rules'] : null;

            // Calculate expected credits based on the test case arguments
            $expectedCredits = $client->calculateCredits($dynamic, $premium, $aiQuery, $aiExtractRules);
            $expectedDiff    = $result['is_cached'] ? 0 : $expectedCredits;

            if ($remainingAttemptsDiff !== $expectedDiff) {
                echo "Error: Remaining attempts difference ({$remainingAttemptsDiff}) is not equal to expected difference ({$expectedDiff})\n";
            } else {
                echo "Success: Remaining attempts difference ({$remainingAttemptsDiff}) matches expected ({$expectedDiff})\n";
            }

            echo format_api_response($result, $requestInfo, $responseInfo);
        }
    } catch (\Exception $e) {
        echo "Error testing scraping: {$e->getMessage()}\n";
    }

    // Test cached request
    echo "\nTesting cached request...\n";
    echo 'Remaining attempts before request: ' . $cacheManager->getRemainingAttempts($clientName) . "\n";

    try {
        echo "\nSearching again for (should be cached): {$url}\n";
        $result = $client->scrape($url);
        echo 'Remaining attempts after cached request: ' . $cacheManager->getRemainingAttempts($clientName) . "\n";
        echo format_api_response($result, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing caching: {$e->getMessage()}\n";
    }

    // Test rate limiting with caching disabled
    if ($testRateLimiting) {
        echo "\nTesting rate limiting with caching disabled...\n";
        $client->setUseCache(false);
        echo 'Remaining attempts before disabling cache: ' . $cacheManager->getRemainingAttempts($clientName) . "\n";

        try {
            for ($i = 1; $i <= $rateLimitMaxAttempts; $i++) {
                echo "Request {$i}...\n";
                echo "Remaining attempts before request {$i}: " . $cacheManager->getRemainingAttempts($clientName) . "\n";
                $result = $client->scrape($url);
                echo "Remaining attempts after request {$i}: " . $cacheManager->getRemainingAttempts($clientName) . "\n";
                echo format_api_response($result, $requestInfo, $responseInfo);
            }
        } catch (RateLimitException $e) {
            echo "Successfully hit rate limit: {$e->getMessage()}\n";
            echo "Available in: {$e->getAvailableInSeconds()} seconds\n";
            echo 'Final remaining attempts: ' . $cacheManager->getRemainingAttempts($clientName) . "\n";
        }
    }
}

$start = microtime(true);

$requestInfo      = false;
$responseInfo     = false;
$testRateLimiting = false;

// Run tests without compression
runScrapingdogTests(false, $requestInfo, $responseInfo, $testRateLimiting);

// Run tests with compression
runScrapingdogTests(true, $requestInfo, $responseInfo, $testRateLimiting);

$end = microtime(true);

echo 'Time taken: ' . ($end - $start) . " seconds\n";

<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

/**
 * Run Jina API client tests
 *
 * @param bool $compressionEnabled Whether to enable compression for the test
 * @param bool $verbose            Whether to enable verbose output
 *
 * @return void
 */
function runJinaTests(bool $compressionEnabled, bool $verbose = true): void
{
    echo "\nRunning Jina API tests with compression " . ($compressionEnabled ? 'enabled' : 'disabled') . "...\n";
    echo str_repeat('-', 80) . "\n";

    // Test client configuration
    $clientName = 'jina';
    config()->set("api-cache.apis.{$clientName}.rate_limit_max_attempts", 5);
    config()->set("api-cache.apis.{$clientName}.rate_limit_decay_seconds", 60);
    config()->set("api-cache.apis.{$clientName}.compression_enabled", $compressionEnabled);

    // Create response tables for the test client
    $dropExisting = false;
    createClientTables($clientName, $dropExisting);

    // Create client instance. Clear rate limit since we might have run tests before.
    $client = new JinaApiClient();
    $client->setTimeout(30);
    $client->clearRateLimit();

    // Get initial token balance
    $balance = $client->getTokenBalance();
    echo "\nInitial token balance: {$balance}\n";

    $url = 'https://en.wikipedia.org/wiki/Laravel';

    // Test reader endpoint
    echo "\nTesting reader endpoint...\n";

    try {
        $result = $client->reader($url);
        echo format_api_response($result, $verbose);
    } catch (\Exception $e) {
        echo "Error testing reader: {$e->getMessage()}\n";
    }

    // Test serp endpoint
    echo "\nTesting serp endpoint...\n";

    try {
        $query  = 'Laravel PHP framework';
        $result = $client->serp($query);
        echo format_api_response($result, $verbose);
    } catch (\Exception $e) {
        echo "Error testing serp: {$e->getMessage()}\n";
    }

    // Test rerank endpoint
    echo "\nTesting rerank endpoint...\n";
    $query     = 'What is Laravel?';
    $documents = [
        'Laravel is a web application framework with expressive, elegant syntax.',
        'Symfony is another PHP framework.',
        'React is a JavaScript library for building user interfaces.',
    ];

    try {
        $result = $client->rerank($query, $documents);
        echo format_api_response($result, $verbose);
    } catch (\Exception $e) {
        echo "Error testing rerank: {$e->getMessage()}\n";
    }

    // Test cached request
    echo "\nTesting cached request...\n";

    try {
        echo "First request...\n";
        $result1 = $client->reader($url);
        echo format_api_response($result1, $verbose);

        echo "\nSecond request (should be cached)...\n";
        $result2 = $client->reader($url);
        echo format_api_response($result2, $verbose);
    } catch (\Exception $e) {
        echo "Error testing caching: {$e->getMessage()}\n";
    }

    // Test rate limiting with caching disabled
    echo "\nTesting rate limiting with caching disabled...\n";

    $client->setUseCache(false);

    try {
        // Use rerank endpoint to test rate limiting
        for ($i = 1; $i <= 6; $i++) {
            echo "Request {$i}...\n";
            $result = $client->rerank($query, $documents);
            echo format_api_response($result, $verbose);
        }
    } catch (RateLimitException $e) {
        echo "Successfully hit rate limit: {$e->getMessage()}\n";
        echo "Available in: {$e->getAvailableInSeconds()} seconds\n";
    }

    // Get final token balance
    $balance = $client->getTokenBalance();
    echo "\nFinal token balance: {$balance}\n";
}

// Run tests without compression
runJinaTests(false);

// Run tests with compression
runJinaTests(true);

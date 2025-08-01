<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

// Get manager instance from container
$manager = app(ApiCacheManager::class);

// Test client configuration
$clientName = 'test-client';
config()->set("api-cache.apis.{$clientName}.rate_limit_max_attempts", 3);
config()->set("api-cache.apis.{$clientName}.rate_limit_decay_seconds", 5);
config()->set("api-cache.apis.{$clientName}.compression_enabled", false);

// Create response tables for the client if not existing
createClientTables($clientName);

// Clear response table for the client, in case table exists from previous tests
$manager->clearTable($clientName);

echo "Testing ApiCacheManager...\n";
echo "------------------------\n";

// Test rate limiting
echo "Testing rate limiting...\n";
echo 'Checking if request is allowed: ' . ($manager->allowRequest($clientName) ? 'Yes' : 'No') . "\n";
echo 'Remaining attempts: ' . $manager->getRemainingAttempts($clientName) . "\n";
echo 'Time until reset: ' . $manager->getAvailableIn($clientName) . " seconds\n";

// Test request tracking
echo "\nTracking API request...\n";
$manager->incrementAttempts($clientName);
echo 'Checking if request is allowed: ' . ($manager->allowRequest($clientName) ? 'Yes' : 'No') . "\n";
echo 'After increment, remaining attempts: ' . $manager->getRemainingAttempts($clientName) . "\n";
echo 'Time until reset: ' . $manager->getAvailableIn($clientName) . " seconds\n";

// Test incrementing attempts with amount
$amount = 2;
echo "\nIncrementing attempts with amount {$amount}...\n";
$manager->incrementAttempts($clientName, $amount);
echo 'Checking if request is allowed: ' . ($manager->allowRequest($clientName) ? 'Yes' : 'No') . "\n";
echo 'After increment, remaining attempts: ' . $manager->getRemainingAttempts($clientName) . "\n";
echo 'Time until reset: ' . $manager->getAvailableIn($clientName) . " seconds\n";

// Test cache key generation
echo "\nTesting cache key generation...\n";
$params1 = ['name' => 'John', 'age' => 30];
$params2 = ['age' => 30, 'name' => 'John'];  // Same params, different order

$key1 = $manager->generateCacheKey($clientName, '/users', $params1);
$key2 = $manager->generateCacheKey($clientName, '/users', $params2);

echo "Key 1: {$key1}\n";
echo "Key 2: {$key2}\n";
echo 'Keys match: ' . ($key1 === $key2 ? 'Yes' : 'No') . "\n";

// Test response caching
echo "\nTesting response caching...\n";
$apiResult = [
    'request' => [
        'base_url' => 'https://api.test',
        'full_url' => 'https://api.test/endpoint',
        'method'   => 'GET',
        'headers'  => ['Accept' => 'application/json'],
        'body'     => '{"query":"test"}',
    ],
    'response' => new \Illuminate\Http\Client\Response(
        new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'application/json'],
            '{"test":"data"}'
        )
    ),
    'response_time' => 0.5,
];

$manager->storeResponse($clientName, $key1, $params1, $apiResult, '/users');

// Retrieve cached response
$cached = $manager->getCachedResponse($clientName, $key1);
if ($cached) {
    echo "Retrieved cached response:\n";
    echo "- Status code: {$cached['response']->status()}\n";
    echo "- Body: {$cached['response']->body()}\n";
    echo "- Response time: {$cached['response_time']}s\n";
} else {
    echo "No cached response found\n";
}

// Test parameter normalization
echo "\nTesting parameter normalization...\n";
$params = [
    'filters' => [
        'status' => 'active',
        'type'   => null,
    ],
    'sort' => 'name',
    'page' => 1,
];

$normalized = normalize_params($params);
echo "Normalized parameters:\n";
print_r($normalized);

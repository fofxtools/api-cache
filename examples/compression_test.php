<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

// Test client configuration
$clientName = 'demo';
config()->set("api-cache.apis.{$clientName}.compression_enabled", true);

// Get repository instance from container
$repository = app(CacheRepository::class);

// Create response tables for the client if not existing
createClientTables($clientName);

// Clear response table for the client, in case table exists from previous tests
$repository->clearTable($clientName);

echo "\nTesting Compression...\n";
echo "-------------------\n";

// Test data with different characteristics
$testCases = [
    'small-text' => [
        'data'        => 'Small piece of text',
        'description' => 'Small text (no compression benefit)',
    ],
    'large-text' => [
        'data'        => str_repeat('This is a repeating text that should compress well. ', 100),
        'description' => 'Large repeating text (good compression)',
    ],
    'json-data' => [
        'data'        => json_encode(array_fill(0, 100, ['test' => 'data', 'numbers' => range(1, 10)])),
        'description' => 'JSON data (moderate compression)',
    ],
];

foreach ($testCases as $name => $test) {
    echo "\nTesting {$name}...\n";
    echo "Description: {$test['description']}\n";

    $metadata = [
        'endpoint'             => '/test',
        'response_body'        => $test['data'],
        'response_status_code' => 200,
    ];

    // Store the data
    $key = "compression-test-{$name}";
    $repository->store($clientName, $key, $metadata);

    // Retrieve and verify
    $retrieved = $repository->get($clientName, $key);

    // Calculate compression stats
    $originalSize = strlen($test['data']);
    $storedSize   = $retrieved['response_size'];
    $ratio        = $storedSize > 0 ? round(($originalSize / $storedSize) * 100) / 100 : 0;

    echo 'Original size: ' . $originalSize . " bytes\n";
    echo 'Stored size: ' . $storedSize . " bytes\n";
    echo "Compression ratio: {$ratio}:1\n";

    // Verify data integrity
    // For JSON data, compare the parsed structure rather than raw strings
    // since pretty printing changes formatting but preserves data
    $originalJson  = json_decode($test['data'], true);
    $retrievedJson = json_decode($retrieved['response_body'], true);

    if ($originalJson !== null && $retrievedJson !== null) {
        // Both are valid JSON - compare data structure
        $matches = $originalJson === $retrievedJson;
    } else {
        // Not JSON or invalid JSON - compare raw strings
        $matches = $test['data'] === $retrieved['response_body'];
    }

    echo 'Data integrity check: ' . ($matches ? 'PASSED' : 'FAILED') . "\n";
}

// Test with compression disabled
echo "\n\nTesting with compression disabled...\n";
config()->set("api-cache.apis.{$clientName}.compression_enabled", false);

// We are using a different table after disabling compression, so call clearTable() again
$repository->clearTable($clientName);

$key      = 'no-compression-test';
$data     = str_repeat('This should not be compressed. ', 50);
$metadata = [
    'endpoint'             => '/test',
    'response_body'        => $data,
    'response_status_code' => 200,
];

$repository->store($clientName, $key, $metadata);
$retrieved = $repository->get($clientName, $key);

echo 'Original size: ' . strlen($data) . " bytes\n";
echo 'Stored size: ' . $retrieved['response_size'] . " bytes\n";
echo 'Data integrity check: ' . ($data === $retrieved['response_body'] ? 'PASSED' : 'FAILED') . "\n";

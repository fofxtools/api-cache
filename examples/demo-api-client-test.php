<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

/**
 * Run demo API client tests with given compression setting
 *
 * @param bool $compression Whether to enable compression
 */
function runDemoApiClientTest(bool $compression): void
{
    $clientName = 'demo';

    echo "\nTesting DemoApiClient " . ($compression ? 'with' : 'without') . " compression...\n";
    echo str_repeat('-', 50) . "\n";

    // Configure client
    config()->set("api-cache.apis.{$clientName}.compression_enabled", $compression);

    // Create response tables
    createClientTables($clientName);

    // Create client instance
    $client = new DemoApiClient();
    $client->setTimeout(2);
    $client->setWslEnabled(true);

    // Test predictions endpoint
    echo "\nTesting predictions endpoint...\n";
    $result = $client->predictions('test query', 5);
    echo "Status code: {$result['response']->status()}\n";
    echo "Response time: {$result['response_time']}s\n";
    echo 'Is cached: ' . ($result['is_cached'] ? 'Yes' : 'No') . "\n\n";
    echo json_encode(json_decode($result['response']->body()), JSON_PRETTY_PRINT);
    echo "\n";

    // Test cached predictions with same parameters
    echo "\nTesting cached request (same parameters)...\n";
    $result           = $client->predictions('test query', 5);
    $responseSize     = $result['response_size'];
    $uncompressedSize = strlen($result['response']->body());
    echo "Status code: {$result['response']->status()}\n";
    echo "Response time: {$result['response_time']}s\n";
    echo 'Is cached: ' . ($result['is_cached'] ? 'Yes' : 'No') . "\n";
    echo "Stored size: {$responseSize} bytes\n";
    echo "Uncompressed size: {$uncompressedSize} bytes\n";
    echo 'Compression ratio: ' . round(($responseSize / $uncompressedSize), 2) . ":1\n\n";
    echo json_encode(json_decode($result['response']->body()), JSON_PRETTY_PRINT);
    echo "\n";

    // Test reports endpoint
    echo "\nTesting reports endpoint...\n";
    $result = $client->reports('monthly', 'sales');
    echo "Status code: {$result['response']->status()}\n";
    echo "Response time: {$result['response_time']}s\n";
    echo 'Is cached: ' . ($result['is_cached'] ? 'Yes' : 'No') . "\n\n";
    echo json_encode(json_decode($result['response']->body()), JSON_PRETTY_PRINT);
    echo "\n";

    // Test error handling
    echo "\nTesting error handling...\n";
    $result = $client->predictions('', 0); // Invalid parameters
    echo "Status code: {$result['response']->status()}\n";
    $body = json_decode($result['response']->body(), true);
    echo "Error message: {$body['error']}\n";
}

// Run tests for both compression scenarios
runDemoApiClientTest(false);
runDemoApiClientTest(true);

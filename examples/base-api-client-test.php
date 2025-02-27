<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

use FOfX\ApiCache\Tests\Traits\ApiCacheTestTrait;

/**
 * Create a concrete implementation for testing
 * Note: While BaseApiClient is no longer abstract, we keep this test class
 * to include the ApiCacheTestTrait for testing utilities
 */
class TestApiClient extends BaseApiClient
{
    use ApiCacheTestTrait;
}

// Test client configuration
$clientName = 'demo';
config()->set("api-cache.apis.{$clientName}.rate_limit_max_attempts", 3);
config()->set("api-cache.apis.{$clientName}.rate_limit_decay_seconds", 5);

// Create response tables for the test client
createClientTables($clientName);

// Create client instance
$client = new TestApiClient(
    $clientName
);
$client->setTimeout(2);

echo "Testing BaseApiClient...\n";
echo "---------------------\n";

// Test health endpoint
echo "Testing health endpoint...\n";
$result = $client->getHealth();
echo "Status code: {$result['response']->status()}\n";
echo "Response time: {$result['response_time']}s\n";
echo "Response body:\n";
echo json_encode(json_decode($result['response']->body()), JSON_PRETTY_PRINT) . "\n\n";

// Test basic request
echo "Testing basic request...\n";
$result = $client->sendRequest('predictions', ['query' => 'test']);
echo "Status code: {$result['response']->status()}\n";
echo "Response time: {$result['response_time']}s\n\n";

// Test cached request with same parameters
echo "Testing cached request...\n";
$result = $client->sendCachedRequest('predictions', ['query' => 'test']);
echo "Status code: {$result['response']->status()}\n";
echo "Response time: {$result['response_time']}s\n\n";

// Test POST request
echo "Testing POST request...\n";
$postData = [
    'report_type' => 'monthly',
    'data_source' => 'sales',
];
$result = $client->sendRequest('reports', $postData, 'POST');
echo "Status code: {$result['response']->status()}\n";
echo "Response time: {$result['response_time']}s\n\n";

// Test error handling
echo "Testing error handling...\n";
$result = $client->sendRequest('nonexistent', ['query' => 'test']);
echo "Status code: {$result['response']->status()}\n";
echo 'Error message: ' . json_decode($result['response']->body(), true)['error'] . "\n";

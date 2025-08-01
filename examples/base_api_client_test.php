<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

// Test client configuration
$clientName = 'demo';
config()->set("api-cache.apis.{$clientName}.rate_limit_max_attempts", 3);
config()->set("api-cache.apis.{$clientName}.rate_limit_decay_seconds", 60);
config()->set("api-cache.apis.{$clientName}.compression_enabled", false);

// Create client instance directly using BaseApiClient
$client = new BaseApiClient($clientName);
$client->setTimeout(2);
// Enable WSL for local testing
$client->setWslEnabled(true);

// Create response tables for the client if not existing
createClientTables($clientName);

// Clear response table for the client, in case table exists from previous tests
$client->clearTable();

echo "Testing BaseApiClient...\n";
echo "---------------------\n";

// Test health endpoint
echo "Testing health endpoint...\n";
echo 'Current time: ' . date('Y-m-d H:i:s') . "\n";
$result = $client->getHealth();
echo "Status code: {$result['response']->status()}\n";
echo "Response time: {$result['response_time']}s\n";
echo "Response body:\n";
print_r($result['response']->json());
echo "\n";

// Test basic request
echo "Testing basic request...\n";
echo 'Current time: ' . date('Y-m-d H:i:s') . "\n";
$result = $client->sendRequest('predictions', ['query' => 'test']);
echo "Status code: {$result['response']->status()}\n";
echo "Response time: {$result['response_time']}s\n\n";
echo "Response body:\n";
print_r($result['response']->json());
echo "\n";

// Sleep to ensure timestamp is different
usleep(1500000);

// Test cached request
echo "Testing cached request...\n";
echo 'Current time: ' . date('Y-m-d H:i:s') . "\n";
$result = $client->sendCachedRequest('predictions', ['query' => 'test']);
echo "Status code: {$result['response']->status()}\n";
echo "Response time: {$result['response_time']}s\n\n";
echo "Response body:\n";
print_r($result['response']->json());
echo "\n";

// Sleep again, but this time response_time should be the same as the previous cached request
usleep(1500000);

// Try cached request again
echo "Testing cached request again...\n";
echo 'Current time: ' . date('Y-m-d H:i:s') . "\n";
$result = $client->sendCachedRequest('predictions', ['query' => 'test']);
echo "Status code: {$result['response']->status()}\n";
echo "Response time: {$result['response_time']}s\n\n";
echo "Response body:\n";
print_r($result['response']->json());
echo "\n";

// Test POST request
echo "Testing POST request...\n";
echo 'Current time: ' . date('Y-m-d H:i:s') . "\n";
$postData = [
    'report_type' => 'monthly',
    'data_source' => 'sales',
];
$result = $client->sendRequest('reports', $postData, 'POST');
echo "Status code: {$result['response']->status()}\n";
echo "Response time: {$result['response_time']}s\n\n";
echo "Response body:\n";
print_r($result['response']->json());
echo "\n";

// Test error handling
echo "Testing error handling...\n";
echo 'Current time: ' . date('Y-m-d H:i:s') . "\n";
$result = $client->sendRequest('nonexistent', ['query' => 'test']);
echo "Status code: {$result['response']->status()}\n";
echo 'Error message: ' . $result['response']->json()['error'] . "\n";

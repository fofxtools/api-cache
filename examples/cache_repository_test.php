<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

// Test client configuration
$clientName = 'demo';
config()->set("api-cache.apis.{$clientName}.compression_enabled", false);

// Get repository instance from container
$repository = app(CacheRepository::class);

// Create response tables for the client if not existing
createClientTables($clientName);

// Clear response table for the client, in case table exists from previous tests
$repository->clearTable($clientName);

echo "\nTesting CacheRepository...\n";
echo "----------------------\n";

// Test storing and retrieving data
echo "\nTesting store and get...\n";
$key      = 'test-key';
$metadata = [
    'endpoint'             => '/test',
    'version'              => 'v1',
    'base_url'             => 'http://localhost:8000',
    'full_url'             => 'http://localhost:8000/test',
    'method'               => 'GET',
    'request_headers'      => ['Content-Type' => 'application/json'],
    'request_body'         => '{"test":"data"}',
    'response_headers'     => ['Content-Type' => 'application/json'],
    'response_body'        => '{"status":"success"}',
    'response_status_code' => 200,
    'response_size'        => 123,
    'response_time'        => 0.456,
];

// Store data
$repository->store($clientName, $key, $metadata);
echo "Data stored\n";

// Retrieve data
$retrieved = $repository->get($clientName, $key);
echo "\nData retrieved: " . ($retrieved ? 'Yes' : 'No') . "\n";
echo 'Response body: ' . $retrieved['response_body'] . "\n";

// Test response counts before expiry
echo "\nTesting response counts (before expiry)...\n";
echo 'Total responses: ' . $repository->countTotalResponses($clientName) . "\n";
echo 'Active responses: ' . $repository->countActiveResponses($clientName) . "\n";
echo 'Expired responses: ' . $repository->countExpiredResponses($clientName) . "\n";

// Store data with TTL
$expiredKey = 'expired-key';
$ttl        = 1;
$repository->store($clientName, $expiredKey, $metadata, $ttl);
echo "\nStored additional data with {$ttl} second TTL\n";

// Test response counts after adding TTL record
echo "\nTesting response counts (after adding TTL record)...\n";
echo 'Total responses: ' . $repository->countTotalResponses($clientName) . "\n";
echo 'Active responses: ' . $repository->countActiveResponses($clientName) . "\n";
echo 'Expired responses: ' . $repository->countExpiredResponses($clientName) . "\n";

// Wait for expiry
echo "\nWaiting for TTL record to expire...\n";
sleep(2);

// Test response counts after expiry
echo "\nTesting response counts (after expiry)...\n";
echo 'Total responses: ' . $repository->countTotalResponses($clientName) . "\n";
echo 'Active responses: ' . $repository->countActiveResponses($clientName) . "\n";
echo 'Expired responses: ' . $repository->countExpiredResponses($clientName) . "\n";

// Delete expired responses
echo "\nDeleting expired responses...\n";
$repository->deleteExpired($clientName);

// Test final response counts
echo "\nTesting response counts (after cleanup)...\n";
echo 'Total responses: ' . $repository->countTotalResponses($clientName) . "\n";
echo 'Active responses: ' . $repository->countActiveResponses($clientName) . "\n";
echo 'Expired responses: ' . $repository->countExpiredResponses($clientName) . "\n";

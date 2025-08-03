<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

// Override database configuration to use MySQL
$databaseConnection = 'mysql';

// Use global to avoid PHPStan error
global $capsule;

$capsule->addConnection(
    config("database.connections.{$databaseConnection}")
);

// Create response tables for the test client without dropping existing tables
createClientTables('dataforseo', false);

// Drop existing bulk items table if it exists
create_dataforseo_backlinks_bulk_items_table($capsule->schema(), dropExisting: true);

$processor = new DataForSeoBacklinksBulkProcessor();

// Include sandbox responses for testing
$processor->setSkipSandbox(false);

// Test setUpdateIfNewer functionality
$processor->setUpdateIfNewer(false);

// Reset processed status for responses and clear processed tables
$processor->resetProcessed();
$clearedStats = $processor->clearProcessedTables();
print_r($clearedStats);

$stats = $processor->processResponses(100);
print_r($stats);

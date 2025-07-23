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

// Drop existing Amazon ASIN table to test fresh processing
create_dataforseo_merchant_amazon_asins_table($capsule->schema(), dropExisting: true);

$processor = new DataForSeoMerchantAmazonAsinProcessor();

// Include sandbox responses for testing
$processor->setSkipSandbox(false);

// Test setUpdateIfNewer functionality
$processor->setUpdateIfNewer(false);

// Test skipReviews functionality
$processor->setSkipReviews(false);

// Test skipProductInformation functionality
$processor->setSkipProductInformation(false);

// Reset processed status for responses and clear processed table
$processor->resetProcessed();
$clearedStats = $processor->clearProcessedTables(true);
print_r($clearedStats);

$stats = $processor->processResponses(100);
print_r($stats);

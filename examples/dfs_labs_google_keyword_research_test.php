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

// Drop existing Labs Google Keyword Research items table to test fresh
create_dataforseo_labs_google_keyword_research_items_table($capsule->schema(), dropExisting: true);

$processor = new DataForSeoLabsGoogleKeywordResearchProcessor();

// Include sandbox responses for testing
$processor->setSkipSandbox(false);

// Test setUpdateIfNewer functionality
$processor->setUpdateIfNewer(true);

// Test monthly searches skip functionality - enable all for testing
$processor->setSkipKeywordInfoMonthlySearches(false);
$processor->setSkipKeywordInfoNormalizedWithBingMonthlySearches(false);
$processor->setSkipKeywordInfoNormalizedWithClickstreamMonthlySearches(false);
$processor->setSkipClickstreamKeywordInfoMonthlySearches(false);

// Reset processed status for responses and clear processed tables
$processor->resetProcessed();
$clearedStats = $processor->clearProcessedTables();
print_r($clearedStats);

$stats = $processor->processResponses(100);
print_r($stats);

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

createClientTables('dataforseo', false);
createProcessedResponseTables($capsule->schema(), true);

$processor = new DataForSeoSerpGoogleProcessor();

// Include sandbox responses for testing
$processor->setSkipSandbox(false);

// Reset processed status for responses and clear processed tables
$processor->resetProcessed();
$clearedStats = $processor->clearProcessedTables();
print_r($clearedStats);

$stats = $processor->processResponses(100, true);
print_r($stats);

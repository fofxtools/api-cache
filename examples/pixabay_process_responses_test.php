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

$dropExisting = false;
$clientName   = 'pixabay';

// Enable compression
//config(["api-cache.apis.{$clientName}.compression_enabled" => true]);

createClientTables($clientName, $dropExisting);
createProcessedResponseTables(schema: $capsule->schema(), dropExisting: $dropExisting);

$pixabay = new PixabayApiClient();

$pixabay->resetProcessed('api');
$pixabay->clearProcessedTable();

$result = $pixabay->searchImages('yellow flowers');
$result = $pixabay->searchImages('sunset');
$stats  = $pixabay->processResponses(null);
print_r($stats);

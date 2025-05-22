<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

use Illuminate\Support\Facades\DB;

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
create_pixabay_images_table(schema: $capsule->schema(), dropExisting: $dropExisting);

// Whether to reset processed_at and processed_status columns
// Use ternary operator to avoid PHPStan error
$resetProcessed = getenv('RESET_PROCESSED') ?: false;
if ($resetProcessed) {
    DB::table('api_cache_' . $clientName . '_responses')->update([
        'processed_at'     => null,
        'processed_status' => null,
    ]);
}

$pixabay = new PixabayApiClient();
$result  = $pixabay->searchImages('yellow flowers');
$result  = $pixabay->searchImages('sunset');
$stats   = $pixabay->processResponses(null);
print_r($stats);

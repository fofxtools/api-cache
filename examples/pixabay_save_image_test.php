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

// Whether to reset storage_filepath_* columns
// Use ternary operator to avoid PHPStan error
$resetStorageFilepaths = getenv('RESET_STORAGE_FILEPATHS') ?: false;
if ($resetStorageFilepaths) {
    DB::table('api_cache_' . $clientName . '_images')->update([
        'storage_filepath_preview'    => null,
        'storage_filepath_webformat'  => null,
        'storage_filepath_largeImage' => null,
    ]);
}

// Initialize client
$client = new PixabayApiClient();

// Test image IDs
$imageIds = [4384750, 6353123, null];

// Process each image
foreach ($imageIds as $id) {
    try {
        $savedCount = $client->saveImageToFile($id);
        echo sprintf(
            "Saved %d images for ID: %s\n",
            $savedCount,
            $id ?? 'next unsaved'
        );
    } catch (\Exception $e) {
        echo sprintf(
            "Error saving images for ID: %s: %s\n",
            $id ?? 'next unsaved',
            $e->getMessage()
        );
    }
}

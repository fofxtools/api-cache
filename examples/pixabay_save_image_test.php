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
createProcessedResponseTables(schema: $capsule->schema(), dropExisting: $dropExisting);

// Initialize client
$client = new PixabayApiClient();

$client->resetStorageFilepaths();
$client->resetImagesFolder();

// Get image ID of first and second rows
$firstImageId  = DB::table('pixabay_images')->first()->id;
$secondImageId = DB::table('pixabay_images')->skip(1)->first()->id;
$imageIds      = [$firstImageId, $secondImageId, null];

// Process each image
foreach ($imageIds as $id) {
    try {
        $savedCount = $client->saveImageToFile($id);
        echo sprintf(
            "\nSaved %d images for ID: %s\n",
            $savedCount,
            $id ?? 'next unsaved'
        );
    } catch (\Exception $e) {
        echo sprintf(
            "\nError saving images for ID: %s: %s\n",
            $id ?? 'next unsaved',
            $e->getMessage()
        );
    }
}

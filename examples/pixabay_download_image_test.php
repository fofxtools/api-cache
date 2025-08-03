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

$client = new PixabayApiClient();

// Get image IDs
$firstImageId  = DB::table('pixabay_images')->first()->id;
$secondImageId = DB::table('pixabay_images')->skip(1)->first()->id;
$thirdImageId  = DB::table('pixabay_images')->skip(2)->first()->id;

// Optional: 'http://proxy.example.com:8080'
$proxy = null;

$client->resetFileInfo();

// Test downloading first image, preview only
echo "Testing download of preview for image ID: $firstImageId\n";
$downloadedCount = $client->downloadImage($firstImageId, 'preview', $proxy);
echo "Downloaded $downloadedCount images\n\n";

// Test downloading next undownloaded image, webformat only
echo "Testing download of next webformat image\n";
$downloadedCount = $client->downloadImage(null, 'webformat', $proxy);
echo "Downloaded $downloadedCount images\n\n";

// Test downloading of next undownloaded image, all types
echo "Testing download of next image of all types\n";
$downloadedCount = $client->downloadImage(null, 'all', $proxy);
echo "Downloaded $downloadedCount images\n\n";

// Test downloading second image ID, all types
echo "Testing download of all types for image ID: $secondImageId\n";
$downloadedCount = $client->downloadImage($secondImageId, 'all', $proxy);
echo "Downloaded $downloadedCount images\n\n";

// Test downloading third image ID, all types
echo "Testing download of all types for image ID: $thirdImageId\n";
$downloadedCount = $client->downloadImage($thirdImageId, 'all', $proxy);
echo "Downloaded $downloadedCount images\n\n";

// Test invalid image type
try {
    echo "Testing invalid image type\n";
    $client->downloadImage($firstImageId, 'invalid_type', $proxy);
} catch (\Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}

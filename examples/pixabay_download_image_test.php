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

// Use a real Pixabay image ID
$imageId    = 4384750;
$imageIdAll = 6353123;
// Optional: 'http://proxy.example.com:8080'
$proxy = null;

// Whether to reset file_contents_* and filesize_* columns
// Use ternary operator to avoid PHPStan error
$resetImages = getenv('RESET_IMAGES') ?: false;
if ($resetImages) {
    DB::table('api_cache_' . $clientName . '_images')->update([
        'file_contents_preview'    => null,
        'file_contents_webformat'  => null,
        'file_contents_largeImage' => null,
        'filesize_preview'         => null,
        'filesize_webformat'       => null,
        'filesize_largeImage'      => null,
    ]);
}

// Test downloading a specific image
echo "Testing download of preview for image ID: $imageId\n";
$downloadedCount = $client->downloadImage($imageId, 'preview', $proxy);
echo "Downloaded $downloadedCount images\n\n";

// Test downloading next undownloaded image
echo "Testing download of next webformat image\n";
$downloadedCount = $client->downloadImage(null, 'webformat', $proxy);
echo "Downloaded $downloadedCount images\n\n";

// Test downloading of next image of all types
echo "Testing download of next image of all types\n";
$downloadedCount = $client->downloadImage(null, 'all', $proxy);
echo "Downloaded $downloadedCount images\n\n";

// Test downloading all types for other image ID
echo "Testing download of all types for image ID: $imageIdAll\n";
$downloadedCount = $client->downloadImage($imageIdAll, 'all', $proxy);
echo "Downloaded $downloadedCount images\n\n";

// Test invalid image type
try {
    echo "Testing invalid image type\n";
    $client->downloadImage($imageId, 'invalid_type', $proxy);
} catch (\Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}

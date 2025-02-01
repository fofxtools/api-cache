<?php

require_once __DIR__ . '/../vendor/autoload.php';

use FOfX\ApiCache\CompressionService;
use Illuminate\Support\Facades\Facade;
use Illuminate\Foundation\Application;

// Bootstrap Laravel
$app = new Application(dirname(__DIR__));

// Core bootstrappers needed
$app->bootstrapWith([
    \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
    \Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
]);

// Register base bindings
$app->singleton('config', fn () => new \Illuminate\Config\Repository([
    'app'     => require __DIR__ . '/../config/app.php',
    'logging' => require __DIR__ . '/../config/logging.php',
]));

// Register logging service
$app->singleton('log', function ($app) {
    return new \Illuminate\Log\LogManager($app);
});

// Set Facade root
Facade::setFacadeApplication($app);

// Test compression
$service = new CompressionService(true);

// Test valid compression
$data = "Hello, this is a test string that we'll compress and decompress";

try {
    echo "Original data: {$data}\n";

    $compressed = $service->compress($data);
    echo 'Compressed (base64): ' . base64_encode($compressed) . "\n";

    $decompressed = $service->decompress($compressed);
    echo "Decompressed: {$decompressed}\n";

    // Verify
    echo 'Compression successful: ' . ($data === $decompressed ? 'Yes' : 'No') . "\n";
} catch (\Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}

// Test invalid data
try {
    echo "\nTesting invalid data...\n";
    $service->decompress('not compressed data');
} catch (\Exception $e) {
    echo 'Expected error: ' . $e->getMessage() . "\n";
}

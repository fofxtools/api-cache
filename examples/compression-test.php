<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use FOfX\ApiCache\CompressionService;
use Illuminate\Support\Facades\Facade;
use Illuminate\Foundation\Application;

// Bootstrap Laravel
$app = new Application(dirname(__DIR__));
$app->bootstrapWith([
    \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
    \Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
]);

// Set Facade root
Facade::setFacadeApplication($app);

// Register base bindings
$app->singleton('config', fn () => new \Illuminate\Config\Repository([
    'api-cache' => require __DIR__ . '/../config/api-cache.php',
    'app'       => require __DIR__ . '/../config/app.php',
    'logging'   => require __DIR__ . '/../config/logging.php',
]));

// Register logging service
$app->singleton('log', function ($app) {
    return new \Illuminate\Log\LogManager($app);
});

// Override settings for testing
$app['config']->set('api-cache.apis.test-client', [
    'compression_enabled' => true,
]);

// Create compression service using config
$service = new CompressionService(
    config('api-cache.apis.test-client.compression_enabled')
);

// Test valid compression
$data = "Hello, this is a test string that we'll compress and decompress";

try {
    echo "Original data: {$data}\n";

    // Test with context
    $compressed = $service->compress($data, 'test-data');
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

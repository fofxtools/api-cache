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

// Register bindings
$app->singleton('config', fn () => new \Illuminate\Config\Repository([
    'api-cache' => require __DIR__ . '/../config/api-cache.php',
    'app'       => require __DIR__ . '/../config/app.php',
    'cache'     => require __DIR__ . '/../config/cache.php',
    'database'  => require __DIR__ . '/../config/database.php',
    'logging'   => require __DIR__ . '/../config/logging.php',
]));
$app->singleton('cache', fn ($app) => new \Illuminate\Cache\CacheManager($app));
$app->singleton('log', fn ($app) => new \Illuminate\Log\LogManager($app));

$clientName = 'test-client';

// Override settings for testing
$app['config']->set("api-cache.apis.{$clientName}.compression_enabled", true);

// Create compression service using config
$service = new CompressionService();

// Test valid compression
$data = "Hello, this is a test string that we'll compress and decompress";

try {
    echo "Original data: {$data}\n";

    // Test with context
    $compressed = $service->compress($data, 'test-data');
    echo 'Compressed (base64): ' . base64_encode($compressed) . "\n";

    $decompressed = $service->decompress($clientName, $compressed);
    echo "Decompressed: {$decompressed}\n";

    // Verify
    echo 'Compression successful: ' . ($data === $decompressed ? 'Yes' : 'No') . "\n";
} catch (\Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}

// Test invalid data
try {
    echo "\nTesting invalid data...\n";
    $service->decompress($clientName, 'not compressed data');
} catch (\Exception $e) {
    echo 'Expected error: ' . $e->getMessage() . "\n";
}

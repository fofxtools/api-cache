<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use FOfX\ApiCache\CompressionService;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Foundation\Application;

date_default_timezone_set('UTC');

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

// Register our services
$app->register(ApiCacheServiceProvider::class);

// Set up test client config
// We are not sending requests so most of these are not actually used
$app['config']->set('api-cache.apis.test-client', [
    'base_url'                 => 'http://test.local',
    'api_key'                  => 'test-key',
    'version'                  => 'v1',
    'cache_ttl'                => null,
    'compression_enabled'      => true,
    'rate_limit_max_attempts'  => 1000,
    'rate_limit_decay_seconds' => 60,
]);

// Get compression service from container
$service = app(CompressionService::class);

$clientName = 'test-client';

echo "Testing CompressionService...\n";
echo "--------------------------\n";

// Test valid compression
$data = "Hello, this is a test string that we'll compress and decompress";

echo "Original data: {$data}\n";
echo 'Original size: ' . strlen($data) . " bytes\n\n";

// Test with context
$compressed = $service->compress($clientName, $data, 'test-data');
echo 'Compressed size: ' . strlen($compressed) . " bytes\n";
echo 'Compressed (base64): ' . base64_encode($compressed) . "\n\n";

$decompressed = $service->decompress($clientName, $compressed);
echo "Decompressed: {$decompressed}\n";
echo 'Decompressed size: ' . strlen($decompressed) . " bytes\n\n";

// Verify
echo 'Compression successful: ' . ($data === $decompressed ? 'Yes' : 'No') . "\n";

// Test invalid data
try {
    echo "\nTesting invalid data...\n";
    $service->decompress($clientName, 'not compressed data');
} catch (\Exception $e) {
    echo 'Expected error: ' . $e->getMessage() . "\n";
}

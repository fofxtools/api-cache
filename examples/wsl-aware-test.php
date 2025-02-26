<?php

require_once __DIR__ . '/../vendor/autoload.php';

use FOfX\ApiCache\BaseApiClient;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use FOfX\Helper;

// Bootstrap Laravel
$app = new Application(dirname(__DIR__));
$app->bootstrapWith([
    \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
    \Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
]);

// Set up facades
Facade::setFacadeApplication($app);

// Register configs
$app->singleton('config', fn () => new \Illuminate\Config\Repository([
    'api-cache' => require __DIR__ . '/../config/api-cache.php',
    'app'       => require __DIR__ . '/../config/app.php',
    'cache'     => require __DIR__ . '/../config/cache.php',
    'database'  => require __DIR__ . '/../config/database.php',
    'logging'   => require __DIR__ . '/../config/logging.php',
]));

// Register cache
$app->singleton('cache', fn ($app) => new \Illuminate\Cache\CacheManager($app));

// Register our service provider
$app->register(ApiCacheServiceProvider::class);

// Setup database
$capsule = new Capsule();
$capsule->addConnection([
    'driver'   => 'sqlite',
    'database' => ':memory:',
]);

/** @var ApiCacheServiceProvider $provider */
$provider = $app->getProvider(ApiCacheServiceProvider::class);
$provider->registerDatabase($capsule);

// Test URLs
$urls = [
    'http://localhost/api',
    'http://localhost:8000/api',
    'http://localhost:8001/api/v1',
    'http://api.example.com/v1',
];

// Create client
$client = new BaseApiClient();

echo "Testing WSL URL conversion:\n";
echo "-------------------------\n";
echo 'OS : ' . PHP_OS_FAMILY . "\n";
echo 'WSL: ' . (getenv('WSL_DISTRO_NAME') ?: 'No') . "\n\n";

foreach ($urls as $url) {
    $wslUrl = Helper\wsl_url($url);
    echo "Original : {$url}\n";
    echo "WSL-aware: {$wslUrl}\n\n";
}

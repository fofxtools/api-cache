<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\Facade;
use Illuminate\Foundation\Application;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Http;
use FOfX\ApiCache\BaseApiClient;

date_default_timezone_set('UTC');

// Bootstrap Laravel
$app = new Application(dirname(__DIR__));
$app->bootstrapWith([
    \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
    \Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
]);

// Set up facades
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

// Setup database
$capsule = new Capsule();
$capsule->addConnection(
    config('database.connections.sqlite_memory')
);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$baseApiClient = new BaseApiClient('default');
$baseUrl       = $baseApiClient->getWslAwareBaseUrl();

// HTTP request for (on Windows) localhost:8000/demo-api-server.php/health
$response = Http::get("{$baseUrl}/health");

// Print the response
echo $response->body();

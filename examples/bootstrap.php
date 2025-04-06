<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

date_default_timezone_set('UTC');

require_once __DIR__ . '/../vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Log;
use Illuminate\Redis\RedisServiceProvider;

/**
 * Create both compressed and uncompressed tables for a client
 *
 * @param string $clientName   The API client identifier
 * @param bool   $dropExisting Whether to drop existing tables
 * @param bool   $verify       Whether to verify the table creation with detailed structure checks
 */
function createClientTables(string $clientName, bool $dropExisting = false, bool $verify = false): void
{
    $repository = app(CacheRepository::class);
    $schema     = app('db')->connection()->getSchemaBuilder();

    $originalCompression = config("api-cache.apis.{$clientName}.compression_enabled");

    // Create uncompressed table
    config(["api-cache.apis.{$clientName}.compression_enabled" => false]);
    $uncompressedTable = $repository->getTableName($clientName);
    create_responses_table($schema, $uncompressedTable, false, $dropExisting, $verify);

    // Create compressed table
    config(["api-cache.apis.{$clientName}.compression_enabled" => true]);
    $compressedTable = $repository->getTableName($clientName);
    create_responses_table($schema, $compressedTable, true, $dropExisting, $verify);

    // Reset compression
    config(["api-cache.apis.{$clientName}.compression_enabled" => $originalCompression]);

    Log::debug('Created tables for client (if not already present)', [
        'client'             => $clientName,
        'uncompressed_table' => $uncompressedTable,
        'compressed_table'   => $compressedTable,
    ]);
}

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
$databaseConnection = 'sqlite_memory';
$capsule            = new Capsule();
$capsule->addConnection(
    config("database.connections.{$databaseConnection}")
);
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Register our services
$provider = new ApiCacheServiceProvider($app);
$provider->registerCache($app);
$provider->registerDatabase($capsule);
$app->register(ApiCacheServiceProvider::class);
$app->register(RedisServiceProvider::class);

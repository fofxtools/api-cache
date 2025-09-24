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
use Illuminate\Database\Schema\Builder;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Cache\CacheManager;
use Illuminate\Log\LogManager;
use Illuminate\Filesystem\FilesystemServiceProvider;

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

    // Create errors table
    create_errors_table($schema, 'api_cache_errors', $dropExisting, $verify);

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

function createProcessedResponseTables(Builder $schema, bool $dropExisting = false, bool $verify = false): void
{
    // Create pixabay_images table
    create_pixabay_images_table($schema, dropExisting: $dropExisting, verify: $verify);

    // Create dataforseo_serp_google_organic_listings table
    create_dataforseo_serp_google_organic_listings_table($schema, dropExisting: $dropExisting, verify: $verify);

    // Create dataforseo_serp_google_organic_items table
    create_dataforseo_serp_google_organic_items_table($schema, dropExisting: $dropExisting, verify: $verify);

    // Create dataforseo_serp_google_organic_paa_items table
    create_dataforseo_serp_google_organic_paa_items_table($schema, dropExisting: $dropExisting, verify: $verify);

    // Create dataforseo_serp_google_autocomplete_items table
    create_dataforseo_serp_google_autocomplete_items_table($schema, dropExisting: $dropExisting, verify: $verify);

    // Create dataforseo_keywords_data_google_ads_items table
    create_dataforseo_keywords_data_google_ads_items_table($schema, dropExisting: $dropExisting, verify: $verify);

    // Create dataforseo_backlinks_bulk_items table
    create_dataforseo_backlinks_bulk_items_table($schema, dropExisting: $dropExisting, verify: $verify);
}

// Bootstrap Laravel
$app = new Application(dirname(__DIR__));
$app->bootstrapWith([
    LoadEnvironmentVariables::class,
    LoadConfiguration::class,
]);

// Set up facades
Facade::setFacadeApplication($app);

$app->singleton('cache', fn ($app) => new CacheManager($app));
$app->singleton('log', fn ($app) => new LogManager($app));

// Setup database
$databaseConnection = 'mysql';
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
$app->register(FilesystemServiceProvider::class);

// Set up basic paths
$app->useStoragePath($app->basePath('storage'));

// Create storage directories if they don't exist
$storagePath          = $app->storagePath();
$storageAppPath       = $app->storagePath('app');
$storageAppPublicPath = $app->storagePath('app/public');
$storageLogsPath      = $app->storagePath('logs');

if (!is_dir($storagePath)) {
    mkdir($storagePath, 0755, true);
}

if (!is_dir($storageAppPath)) {
    mkdir($storageAppPath, 0755, true);
}

if (!is_dir($storageAppPublicPath)) {
    mkdir($storageAppPublicPath, 0755, true);
}

if (!is_dir($storageLogsPath)) {
    mkdir($storageLogsPath, 0755, true);
}

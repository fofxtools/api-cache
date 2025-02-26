<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

require_once __DIR__ . '/../vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Database\Capsule\Manager as Capsule;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\Log;
use FOfX\ApiCache\Tests\Traits\ApiCacheTestTrait;
use FOfX\ApiCache\CacheRepository;

/**
 * Create both compressed and uncompressed tables for a client
 *
 * @param string $clientName The API client identifier
 * @param bool   $verify     Whether to verify the table creation with detailed structure checks
 */
function createClientTables(string $clientName, bool $verify = false): void
{
    // Get trait instance
    $trait = new class () {
        use ApiCacheTestTrait;
    };

    $repository = app(CacheRepository::class);
    $schema     = app('db')->connection()->getSchemaBuilder();

    $originalCompression = config("api-cache.apis.{$clientName}.compression_enabled");

    // Create uncompressed table
    config(["api-cache.apis.{$clientName}.compression_enabled" => false]);
    $uncompressedTable = $repository->getTableName($clientName);
    $trait->createResponseTable($schema, $uncompressedTable, false);

    // Create compressed table
    config(["api-cache.apis.{$clientName}.compression_enabled" => true]);
    $compressedTable = $repository->getTableName($clientName);
    $trait->createResponseTable($schema, $compressedTable, true);

    // Reset compression
    config(["api-cache.apis.{$clientName}.compression_enabled" => $originalCompression]);

    Log::debug('Created tables for client', [
        'client'             => $clientName,
        'uncompressed_table' => $uncompressedTable,
        'compressed_table'   => $compressedTable,
    ]);

    // Verify tables were created if requested
    if ($verify) {
        foreach ([$uncompressedTable, $compressedTable] as $table) {
            if (!$schema->hasTable($table)) {
                throw new \RuntimeException("Table {$table} was not created successfully");
            }

            $columns = $schema->getColumnListing($table);

            // Get table structure including indexes - handle different databases
            $pdo    = $schema->getConnection()->getPdo();
            $driver = $schema->getConnection()->getDriverName();

            $tableInfo = [];
            $indexInfo = [];

            if ($driver === 'mysql') {
                $result    = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
                $tableInfo = $result['Create Table'] ?? null;
            } elseif ($driver === 'sqlite') {
                $tableInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetch(\PDO::FETCH_ASSOC);
                $indexInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='{$table}'")->fetchAll(\PDO::FETCH_COLUMN);
            }

            Log::debug('Table structure', [
                'table'      => $table,
                'columns'    => $columns,
                'compressed' => str_ends_with($table, '_compressed'),
                'structure'  => $tableInfo,
                'indexes'    => $indexInfo,
            ]);
        }
    }
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
$capsule = new Capsule();
$capsule->addConnection(
    config('database.connections.sqlite_memory')
);
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Register our services
$provider = new ApiCacheServiceProvider($app);
$provider->registerCache($app);
$provider->registerDatabase($capsule);
$app->register(ApiCacheServiceProvider::class);

<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\Facade;
use Illuminate\Foundation\Application;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use FOfX\ApiCache\CacheRepository;
use FOfX\ApiCache\ApiCacheServiceProvider;

/**
 * Create the response table with standard schema
 */
function createResponseTable(Illuminate\Database\Schema\Builder $schema, string $tableName): void
{
    // Drop the table if it exists to ensure a clean state
    $schema->dropIfExists($tableName);

    $schema->create($tableName, function (Blueprint $table) {
        $table->id();
        $table->string('key')->unique();
        $table->string('client');
        $table->string('version')->nullable();
        $table->string('endpoint');
        $table->string('base_url')->nullable();
        $table->string('full_url')->nullable();
        $table->string('method')->nullable();
        $table->mediumText('request_headers')->nullable();
        $table->mediumText('request_body')->nullable();
        $table->integer('response_status_code')->nullable();
        $table->mediumText('response_headers')->nullable();
        $table->mediumText('response_body')->nullable();
        $table->integer('response_size')->nullable();
        $table->double('response_time')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });
}

/**
 * Run cache repository tests with the given compression setting
 */
function runCacheRepositoryTests(
    Capsule $capsule,
    CacheRepository $repository,
    string $client,
    string $key,
    array $metadata,
    bool $compressionEnabled,
    int $ttl = 2
): void {
    // Create fresh table for this test run
    $tableName = $repository->getTableName($client);
    createResponseTable($capsule->schema(), $tableName);

    echo 'Testing with compression ' . ($compressionEnabled ? 'enabled' : 'disabled') . "...\n";
    echo "------------------------------------------------\n";

    // Test storing with TTL
    echo "Storing test data with {$ttl} second TTL...\n";
    $repository->store($client, $key, $metadata, $ttl);
    echo "Store successful\n\n";

    // Test retrieval
    echo "Retrieving stored data...\n";
    $retrieved = $repository->get($client, $key);
    if ($retrieved) {
        echo "Retrieved successfully:\n";
        echo "- Endpoint: {$retrieved['endpoint']}\n";
        echo "- Method: {$retrieved['method']}\n";
        echo "- Response body: {$retrieved['response_body']}\n";

        // Format the expiry date nicely
        if ($retrieved['expires_at']) {
            // Use 'e' for timezone identifier
            $expiresAt = date('Y-m-d H:i:s e', strtotime($retrieved['expires_at']));
        } else {
            $expiresAt = 'never';
        }

        echo "- Expires at: {$expiresAt}\n\n";
    } else {
        echo "Data not found in cache\n\n";
    }

    // Wait for expiration
    $waitTime = $ttl + 1;
    echo "Waiting {$waitTime} seconds for data to expire...\n";
    sleep($waitTime);

    // Test deleteExpired
    echo "Testing deleteExpired...\n";
    // Get initial count
    $beforeCount = $capsule->getDatabaseManager()->connection()->table($tableName)
        ->count();
    echo "Before deleteExpired, row count: {$beforeCount}\n";

    // Run deleteExpired
    $repository->deleteExpired($client);

    // Get final count
    $afterCount = $capsule->getDatabaseManager()->connection()->table($tableName)
        ->count();
    echo "After deleteExpired, row count: {$afterCount}\n";

    // Show what happened
    if ($afterCount < $beforeCount) {
        echo 'deleteExpired removed ' . ($beforeCount - $afterCount) . " expired rows\n\n";
    } else {
        echo "deleteExpired did not remove any rows (none were expired)\n\n";
    }
}

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

// Register database connection
$app->singleton('db', function () use ($capsule) {
    return $capsule->getDatabaseManager();
});
$app->singleton('db.connection', function () use ($capsule) {
    return $capsule->getDatabaseManager()->connection();
});

// Register our services
$app->register(ApiCacheServiceProvider::class);

// Set up test client config
// We are using store() and get() directly and not sendRequest() or sendCachedRequest()
// so base_url etc. are not actually used here
$app['config']->set('api-cache.apis.test-client', [
    'base_url'                 => 'http://test.local',
    'api_key'                  => 'test-key',
    'version'                  => 'v1',
    'cache_ttl'                => null,
    'compression_enabled'      => false,
    'rate_limit_max_attempts'  => 1000,
    'rate_limit_decay_seconds' => 60,
]);

$repository = app(CacheRepository::class);

// Test data
$clientName = 'test-client';
$key        = 'test-key';
$metadata   = [
    'endpoint'         => '/api/test',
    'response_body'    => 'This is the response body for the test. It may or may not be stored compressed.',
    'response_headers' => ['Content-Type' => 'application/json'],
    'method'           => 'GET',
];

// Test with compression disabled
$app['config']->set("api-cache.apis.{$clientName}.compression_enabled", false);
runCacheRepositoryTests(
    $capsule,
    $repository,
    $clientName,
    $key,
    $metadata,
    false
);

echo "\n";
// Test with compression enabled
$app['config']->set("api-cache.apis.{$clientName}.compression_enabled", true);
runCacheRepositoryTests(
    $capsule,
    $repository,
    $clientName,
    $key,
    $metadata,
    true
);

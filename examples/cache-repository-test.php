<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use FOfX\ApiCache\CacheRepository;
use FOfX\ApiCache\CompressionService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Illuminate\Foundation\Application;

date_default_timezone_set('UTC');

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
 * Run the cache repository tests
 */
function runCacheTests(CacheRepository $repository, string $client, string $key, array $metadata, int $ttl = 2): void
{
    try {
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

        // Test cleanup
        echo "Testing cleanup...\n";
        $repository->cleanup($client);
        echo "Cleanup completed\n\n";

        // Verify cleanup
        $retrieved = $repository->get($client, $key);
        if ($retrieved) {
            echo "After cleanup, data exists: Yes\n";
        } else {
            echo "After cleanup, data exists: No\n";
        }
    } catch (\Exception $e) {
        echo 'Error: ' . $e->getMessage() . "\n";
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

// Register services
$app->singleton('config', fn () => new \Illuminate\Config\Repository([
    'api-cache' => require __DIR__ . '/../config/api-cache.php',
    'app'       => require __DIR__ . '/../config/app.php',
    'cache'     => require __DIR__ . '/../config/cache.php',
    'database'  => require __DIR__ . '/../config/database.php',
    'logging'   => require __DIR__ . '/../config/logging.php',
]));
$app->singleton('log', fn ($app) => new \Illuminate\Log\LogManager($app));

// Override test client settings
$app['config']->set('api-cache.apis.test-client', [
    'compression_enabled'      => true,
    'rate_limit_max_attempts'  => 1000,
    'rate_limit_decay_seconds' => 60,
    'cache_ttl'                => null,
]);

// Setup database
$capsule = new Capsule();
$capsule->addConnection(
    config('database.connections.sqlite_memory')
);
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Test data
$client   = 'test-client';
$key      = 'test-key';
$metadata = [
    'endpoint'         => '/api/test',
    'response_body'    => 'This is the response body for the test. It may or may not be stored compressed.',
    'response_headers' => ['Content-Type' => 'application/json'],
    'method'           => 'GET',
];

// Run tests with compression enabled
echo "\nTesting CacheRepository with compression enabled:\n";
echo "------------------------------------------------\n";
$repository = new CacheRepository(
    $capsule->getDatabaseManager()->connection(),
    new CompressionService(true)
);
createResponseTable($capsule->schema(), $repository->getTableName($client));
runCacheTests($repository, $client, $key, $metadata);

// Run tests with compression disabled
echo "\n\nTesting CacheRepository with compression disabled:\n";
echo "------------------------------------------------\n";
$repository = new CacheRepository(
    $capsule->getDatabaseManager()->connection(),
    new CompressionService(false)
);
createResponseTable($capsule->schema(), $repository->getTableName($client));
runCacheTests($repository, $client, $key, $metadata);

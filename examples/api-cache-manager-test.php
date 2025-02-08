<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use FOfX\ApiCache\ApiCacheManager;
use FOfX\ApiCache\CacheRepository;
use FOfX\ApiCache\RateLimitService;
use FOfX\ApiCache\CompressionService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Application;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

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
 * Run basic tests for the API Cache Manager
 */
function runApiCacheTests(ApiCacheManager $manager, string $client): void
{
    try {
        // Test rate limiting
        echo "Testing rate limiting...\n";
        echo 'Checking if request is allowed: ' . ($manager->allowRequest($client) ? 'Yes' : 'No') . "\n";
        echo 'Remaining attempts: ' . $manager->getRemainingAttempts($client) . "\n";
        echo 'Time until reset: ' . $manager->getAvailableIn($client) . " seconds\n";

        // Test request tracking
        echo "\nTracking API request...\n";
        $manager->incrementAttempts($client);
        echo 'Checking if request is allowed: ' . ($manager->allowRequest($client) ? 'Yes' : 'No') . "\n";
        echo 'After increment, remaining attempts: ' . $manager->getRemainingAttempts($client) . "\n";
        echo 'Time until reset: ' . $manager->getAvailableIn($client) . " seconds\n";

        // Test incrementing attempts with amount
        $amount = 2;
        echo "\nIncrementing attempts with amount {$amount}...\n";
        $manager->incrementAttempts($client, $amount);
        echo 'Checking if request is allowed: ' . ($manager->allowRequest($client) ? 'Yes' : 'No') . "\n";
        echo 'After increment, remaining attempts: ' . $manager->getRemainingAttempts($client) . "\n";
        echo 'Time until reset: ' . $manager->getAvailableIn($client) . " seconds\n";

        // Test cache key generation
        echo "\nTesting cache key generation...\n";
        $params1 = ['name' => 'John', 'age' => 30];
        $params2 = ['age' => 30, 'name' => 'John'];  // Same params, different order

        $key1 = $manager->generateCacheKey($client, '/users', $params1);
        $key2 = $manager->generateCacheKey($client, '/users', $params2);

        echo "Key 1: {$key1}\n";
        echo "Key 2: {$key2}\n";
        echo 'Keys match: ' . ($key1 === $key2 ? 'Yes' : 'No') . "\n";

        // Test response caching
        echo "\nTesting response caching...\n";
        $apiResult = [
            'request' => [
                'base_url' => 'https://api.test',
                'full_url' => 'https://api.test/endpoint',
                'method'   => 'GET',
                'headers'  => ['Accept' => 'application/json'],
                'body'     => '{"query":"test"}',
            ],
            'response' => new \Illuminate\Http\Client\Response(
                new \GuzzleHttp\Psr7\Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    '{"test":"data"}'
                )
            ),
            'response_time' => 0.5,
        ];

        $manager->storeResponse($client, $key1, $apiResult, '/users');

        // Retrieve cached response
        $cached = $manager->getCachedResponse($client, $key1);
        if ($cached) {
            echo "Retrieved cached response:\n";
            echo "- Status code: {$cached['response_status_code']}\n";
            echo "- Body: {$cached['response_body']}\n";
            echo "- Response time: {$cached['response_time']}s\n";
        } else {
            echo "No cached response found\n";
        }

        // Test parameter normalization
        echo "\nTesting parameter normalization...\n";
        $params = [
            'filters' => [
                'status' => 'active',
                'type'   => null,
            ],
            'sort' => 'name',
            'page' => 1,
        ];

        $normalized = $manager->normalizeParams($params);
        echo "Normalized parameters:\n";
        print_r($normalized);
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

// Register services
$app->singleton('config', fn () => new \Illuminate\Config\Repository([
    'app'       => require __DIR__ . '/../config/app.php',
    'logging'   => require __DIR__ . '/../config/logging.php',
    'cache'     => require __DIR__ . '/../config/cache.php',
    'api-cache' => [
        'apis' => [
            'test-client' => [
                'rate_limit_max_attempts'  => 3,
                'rate_limit_decay_seconds' => 5,
            ],
        ],
    ],
]));

$app->singleton('cache', fn ($app) => new \Illuminate\Cache\CacheManager($app));
$app->singleton('log', fn ($app) => new \Illuminate\Log\LogManager($app));
Facade::setFacadeApplication($app);

// Setup database
$capsule = new Capsule();
$capsule->addConnection([
    'driver'   => 'sqlite',
    'database' => ':memory:',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Create services
$rateLimiter      = new RateLimiter(Cache::driver());
$rateLimitService = new RateLimitService($rateLimiter);
$compression      = new CompressionService(true);
$repository       = new CacheRepository(
    $capsule->getDatabaseManager()->connection(),
    $compression
);

// Create table
createResponseTable($capsule->schema(), $repository->getTableName('test-client'));

// Create manager and run tests
$manager = new ApiCacheManager($repository, $rateLimitService);
echo "Testing ApiCacheManager...\n";
echo "------------------------\n";
runApiCacheTests($manager, 'test-client');

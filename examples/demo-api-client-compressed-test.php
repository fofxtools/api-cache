<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use FOfX\ApiCache\DemoApiClient;
use FOfX\ApiCache\ApiCacheServiceProvider;
use FOfX\ApiCache\ApiCacheManager;
use FOfX\ApiCache\CacheRepository;
use FOfX\ApiCache\CompressionService;
use FOfX\ApiCache\RateLimitService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Application;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

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

// Register our service provider
$app->register(ApiCacheServiceProvider::class);

// Get the appropriate host based on environment
if (PHP_OS_FAMILY === 'Linux' && getenv('WSL_DISTRO_NAME')) {
    // In WSL2, /etc/resolv.conf's nameserver points to the Windows host
    $nameserver = trim(shell_exec("grep nameserver /etc/resolv.conf | awk '{print $2}'"));
} else {
    $nameserver = 'localhost';
}

$configBaseUrl = config('api-cache.apis.demo.base_url');

// Replace localhost with appropriate nameserver for Ubuntu WSL
$modifiedBaseUrl = preg_replace(
    '#localhost(:\d+)?#',
    $nameserver . '$1',
    $configBaseUrl
);

// Update config with WSL-adjusted URL
$app['config']->set('api-cache.apis.demo.base_url', $modifiedBaseUrl);

// Enable compression
$app['config']->set('api-cache.apis.demo.compression_enabled', true);

// Setup database
$capsule = new Capsule();
$capsule->addConnection(
    config('database.connections.sqlite_memory')
);
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Register database in container
$app->singleton('db', function () use ($capsule) {
    return $capsule->getDatabaseManager();
});

// Create services
$rateLimiter      = new RateLimiter(Cache::driver());
$rateLimitService = new RateLimitService($rateLimiter);
$compression      = new CompressionService();
$repository       = new CacheRepository(
    $capsule->getDatabaseManager()->connection(),
    $compression
);

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

    // Alter the request and response headers and body columns
    // For MySQL use MEDIUMBLOB, for SQL Server use VARBINARY(MAX)
    $driver = $schema->getConnection()->getDriverName();
    if ($driver === 'mysql') {
        $schema->getConnection()->statement('
                ALTER TABLE api_cache_demo_responses_compressed
                MODIFY request_headers MEDIUMBLOB,
                MODIFY request_body MEDIUMBLOB,
                MODIFY response_headers MEDIUMBLOB,
                MODIFY response_body MEDIUMBLOB
            ');
    } elseif ($driver === 'sqlsrv') {
        $schema->getConnection()->statement('
                ALTER TABLE api_cache_demo_responses_compressed
                ALTER COLUMN request_headers VARBINARY(MAX),
                ALTER COLUMN request_body VARBINARY(MAX),
                ALTER COLUMN response_headers VARBINARY(MAX),
                ALTER COLUMN response_body VARBINARY(MAX)
            ');
    }
}

// Create response table
$tableName = $repository->getTableName('demo');
echo "Creating table: {$tableName}\n";
createResponseTable($capsule->schema(), $tableName);

// Register ApiCacheManager
$app->singleton(ApiCacheManager::class, fn () => new ApiCacheManager($repository, $rateLimitService));

// Create client instance
$client = new DemoApiClient();

// Set shorter timeout for testing
$client->setTimeout(2);

echo "\n";

try {
    // Test predictions endpoint
    echo "Testing predictions endpoint...\n";
    $result = $client->predictions('test query', 5);
    echo "Status code: {$result['response']->status()}\n";
    echo "Response time: {$result['response_time']}s\n\n";
    echo json_encode(json_decode($result['response']->body()), JSON_PRETTY_PRINT);
    echo "\n\n";

    // Test cached predictions with same parameters
    echo "Testing cached predictions...\n";
    $result = $client->predictions('test query', 5);
    echo "Status code: {$result['response']->status()}\n";
    echo "Response time: {$result['response_time']}s\n\n";
    echo json_encode(json_decode($result['response']->body()), JSON_PRETTY_PRINT);
    echo "\n\n";

    // Test reports endpoint
    echo "Testing reports endpoint...\n";
    $result = $client->reports('monthly', 'sales');
    echo "Status code: {$result['response']->status()}\n";
    echo "Response time: {$result['response_time']}s\n\n";
    echo json_encode(json_decode($result['response']->body()), JSON_PRETTY_PRINT);
    echo "\n\n";

    // Test error handling
    echo "Testing error handling...\n";

    try {
        $result = $client->predictions('', 0); // Invalid parameters
        echo "Status code: {$result['response']->status()}\n";
        $body = json_decode($result['response']->body(), true);
        if (isset($body['error'])) {
            echo "Error message: {$body['error']}\n";
        } else {
            echo 'Response body: ' . $result['response']->body() . "\n";
        }
    } catch (\Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        echo "Make sure the demo server is running with: php -S 0.0.0.0:8000 -t public\n";
    }
} catch (\Exception $e) {
    echo "Error: {$e->getMessage()}\n";
    echo "Make sure the demo server is running with: php -S 0.0.0.0:8000 -t public\n";
}

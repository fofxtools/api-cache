<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use FOfX\ApiCache\DemoApiClient;
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

// Get the appropriate host based on environment
if (PHP_OS_FAMILY === 'Linux' && getenv('WSL_DISTRO_NAME')) {
    // In WSL2, /etc/resolv.conf's nameserver points to the Windows host
    $nameserver = trim(shell_exec("grep nameserver /etc/resolv.conf | awk '{print $2}'"));
} else {
    $nameserver = 'localhost';
}
$baseUrl = 'http://' . $nameserver . ':8000';

// Register services
$app->singleton('config', fn () => new \Illuminate\Config\Repository([
    'app'       => require __DIR__ . '/../config/app.php',
    'logging'   => require __DIR__ . '/../config/logging.php',
    'cache'     => require __DIR__ . '/../config/cache.php',
    'api-cache' => [
        'apis' => [
            'demo' => [
                'base_url'                 => $baseUrl,
                'api_key'                  => 'demo-api-key',
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
$compression      = new CompressionService(false);
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
}

// Create response table
createResponseTable($capsule->schema(), $repository->getTableName('demo'));

// Register ApiCacheManager
$app->singleton(ApiCacheManager::class, fn () => new ApiCacheManager($repository, $rateLimitService));

// Create client instance
$client = new DemoApiClient();

// Set shorter timeout for testing
$client->setTimeout(2);

try {
    // Test prediction endpoint
    echo "Testing prediction endpoint...\n";
    $result = $client->prediction('test query', 5);
    echo "Status code: {$result['response']->status()}\n";
    echo "Response time: {$result['response_time']}s\n\n";

    // Test cached prediction with same parameters
    echo "Testing cached prediction...\n";
    $result = $client->prediction('test query', 5);
    echo "Status code: {$result['response']->status()}\n";
    echo "Response time: {$result['response_time']}s\n\n";

    // Test report endpoint
    echo "Testing report endpoint...\n";
    $result = $client->report('monthly', 'sales');
    echo "Status code: {$result['response']->status()}\n";
    echo "Response time: {$result['response_time']}s\n\n";

    // Test error handling
    echo "Testing error handling...\n";

    try {
        $result = $client->prediction('', 0); // Invalid parameters
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

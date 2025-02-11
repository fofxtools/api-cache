<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use FOfX\ApiCache\BaseApiClient;
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

// Create a concrete implementation for testing
class TestApiClient extends BaseApiClient
{
    public function buildUrl(string $endpoint): string
    {
        return $this->baseUrl . '/v1/' . ltrim($endpoint, '/');
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
            'demo-client' => [
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
createResponseTable($capsule->schema(), $repository->getTableName('demo-client'));

// Register ApiCacheManager
$app->singleton(ApiCacheManager::class, fn () => new ApiCacheManager($repository, $rateLimitService));

// Get the appropriate host based on environment
if (PHP_OS_FAMILY === 'Linux' && getenv('WSL_DISTRO_NAME')) {
    // In WSL2, /etc/resolv.conf's nameserver points to the Windows host
    $nameserver = trim(shell_exec("grep nameserver /etc/resolv.conf | awk '{print $2}'"));
} else {
    $nameserver = 'localhost';
}
$baseUrl = 'http://' . $nameserver . ':8000/demo-api-server.php';

// Create client instance
$client = new TestApiClient(
    'demo-client',
    $baseUrl,
    'demo-api-key',
    'v1'
);

// Set shorter timeout for testing
$client->setTimeout(2);

try {
    // Test basic request
    echo "Testing basic request...\n";
    $result = $client->sendRequest('predictions', ['query' => 'test']);
    echo "Status code: {$result['response']->status()}\n";
    echo "Response time: {$result['response_time']}s\n\n";

    // Test cached request with same parameters
    echo "Testing cached request...\n";
    $result = $client->sendCachedRequest('predictions', ['query' => 'test']);
    echo "Status code: {$result['response']->status()}\n";
    echo "Response time: {$result['response_time']}s\n\n";

    // Test POST request
    echo "Testing POST request...\n";
    $postData = [
        'report_type' => 'monthly',
        'data_source' => 'sales',
    ];
    $result = $client->sendRequest('reports', $postData, 'POST');
    echo "Status code: {$result['response']->status()}\n";
    echo "Response time: {$result['response_time']}s\n\n";

    // Test error handling
    echo "Testing error handling...\n";
    $result = $client->sendRequest('nonexistent', ['query' => 'test']);
    echo "Status code: {$result['response']->status()}\n";
    echo 'Error message: ' . json_decode($result['response']->body(), true)['error'] . "\n";
} catch (\Exception $e) {
    echo "Error: {$e->getMessage()}\n";
    echo "Make sure the demo server is running with: php -S 0.0.0.0:8000 -t public\n";
}

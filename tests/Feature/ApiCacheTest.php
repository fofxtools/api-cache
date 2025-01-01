<?php

namespace FOfX\ApiCache\Tests\Feature;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\Support\RequestHandler;
use FOfX\ApiCache\ApiClients\DemoApiClient;
use Illuminate\Support\Facades\DB;
use Monolog\Logger;
use Illuminate\Support\Facades\Schema;

class ApiCacheTest extends TestCase
{
    protected DemoApiClient $client;
    protected RequestHandler $handler;
    protected array $config;

    protected function setUp(): void
    {
        parent::setUp();

        // Verify demo API is accessible
        $ch = curl_init('http://localhost:8000/demo-api.php/success');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response   = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($statusCode !== 200) {
            $this->markTestSkipped(
                'Demo API server not running. Start with: php -S localhost:8000 -t public'
            );
        }

        // Run migrations first
        $migrationPath = __DIR__ . '/../../src/Database/migrations';
        $migrator      = new \Illuminate\Database\Migrations\Migrator(
            new \Illuminate\Database\Migrations\DatabaseMigrationRepository(
                $this->app['db'],
                'migrations'
            ),
            $this->app['db'],
            new \Illuminate\Filesystem\Filesystem()
        );

        // Create migrations table if it doesn't exist
        if (!Schema::hasTable('migrations')) {
            Schema::create('migrations', function ($table) {
                $table->increments('id');
                $table->string('migration');
                $table->integer('batch');
            });
        }

        // Run pending migrations
        $migrator->run($migrationPath);

        $this->config = [
            'debug'   => true,
            'clients' => [
                'demo_api' => [
                    'cache_ttl'   => 3600,
                    'rate_limits' => [
                        'window_size'  => 60,
                        'max_requests' => 5,
                    ],
                ],
            ],
        ];

        $this->client  = new DemoApiClient(new Logger('api-cache'));
        $this->handler = new RequestHandler($this->client, $this->config);
    }

    public function test_it_can_cache_responses(): void
    {
        $client  = new DemoApiClient(new Logger('api-cache'));
        $handler = new RequestHandler($client, [
            'timeout' => 30,
            'clients' => [
                'demo_api' => [
                    'cache_ttl'   => 3600,
                    'rate_limits' => [
                        'window_size'  => 60,
                        'max_requests' => 60,
                    ],
                ],
            ],
        ]);

        // Make a request that should be cached
        $response = $handler->send('demo_api', 'GET', '/success');

        // Debug response
        $this->assertArrayHasKey('statusCode', $response['response'], 'Response should have statusCode');
        $this->assertEquals(200, $response['response']['statusCode'], 'Response should be successful');

        // Check if it was cached in database
        $cached = DB::table('api_cache_demo_api_responses')
            ->where('client', 'demo_api')
            ->where('endpoint', '/success')
            ->first();

        // Debug cache lookup
        if ($cached === null) {
            // Get all records from the table
            $allRecords = DB::table('api_cache_demo_api_responses')->get();
            $this->fail(sprintf(
                'Cache entry not found. Table has %d records. First record: %s',
                count($allRecords),
                json_encode($allRecords->first() ?? 'none')
            ));
        }

        $this->assertEquals('/success', $cached->endpoint);
        $this->assertEquals('http://localhost:8000/demo-api.php', $cached->base_url);
    }
}

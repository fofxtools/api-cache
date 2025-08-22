<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\DemoApiClient;
use FOfX\ApiCache\ApiCacheManager;
use FOfX\ApiCache\ApiCacheServiceProvider;
use FOfX\ApiCache\Tests\TestCase;
use Mockery;

use function FOfX\ApiCache\check_server_status;

class DemoApiClientTest extends TestCase
{
    protected DemoApiClient $client;

    /** @var ApiCacheManager&Mockery\MockInterface */
    protected ApiCacheManager $cacheManager;

    protected string $version = 'v1';

    /**
     * Get service providers to register for testing.
     * Called implicitly by Orchestra TestCase to register providers before tests run.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array
     */
    protected function getPackageProviders($app): array
    {
        return [ApiCacheServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Get base URL from config
        $baseUrl = config('api-cache.apis.demo.base_url');

        // Set up cache manager mock
        $this->cacheManager = Mockery::mock(ApiCacheManager::class);

        // Create client
        $this->client = new DemoApiClient($this->cacheManager);

        // Enable WSL URL conversion
        $this->client->setWslEnabled(true);

        // Skip test if server is not accessible
        $serverStatus = check_server_status($this->client->getBaseUrl());
        if (!$serverStatus) {
            $this->markTestSkipped('API server is not accessible at: ' . $this->client->getBaseUrl());
        }

        // Set up mock expectations
        // Use byDefault() to allow being overridden in specific tests
        $this->cacheManager->shouldReceive('getCachedResponse')
            ->andReturnNull();
        $this->cacheManager->shouldReceive('allowRequest')
            ->andReturnTrue();
        $this->cacheManager->shouldReceive('generateCacheKey')
            ->byDefault()
            ->andReturn('demo.get.predictions.test-hash.v1');
        $this->cacheManager->shouldReceive('incrementAttempts');
        $this->cacheManager->shouldReceive('storeResponse')
            ->byDefault();
    }

    public function test_predictions_sends_correct_request(): void
    {
        $query      = 'test query';
        $maxResults = 5;

        $result = $this->client->predictions($query, $maxResults);

        $this->assertEquals(200, $result['response']->status());
        $responseData = $result['response']->json();
        $this->assertTrue($responseData['success']);
        $this->assertNotEmpty($responseData['data']);
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('response_time', $result);
    }

    public function test_predictions_merges_additional_params(): void
    {
        $additionalParams = ['filter' => 'test'];

        // Override the default mock
        $capturedArgs = [];
        $this->cacheManager->shouldReceive('generateCacheKey')
            ->andReturnUsing(function (...$args) use (&$capturedArgs) {
                $capturedArgs = $args;

                return 'demo.get.predictions.test-hash.' . config('api-cache.apis.demo.version');
            });

        $result = $this->client->predictions('test', 10, $additionalParams);

        $this->assertEquals(200, $result['response']->status());
        $responseData = $result['response']->json();
        $this->assertTrue($responseData['success']);
        $this->assertNotEmpty($responseData['data']);

        // Debug the captured args
        $this->assertCount(5, $capturedArgs, 'Expected 5 arguments passed to generateCacheKey');
        $this->assertEquals('demo', $capturedArgs[0], 'First arg should be client name');
        $this->assertEquals('predictions', $capturedArgs[1], 'Second arg should be endpoint');
        $this->assertArrayHasKey('filter', $capturedArgs[2], 'Third arg should be params array');
        $this->assertEquals('test', $capturedArgs[2]['filter'], 'Filter param should be "test"');
        $this->assertEquals('GET', $capturedArgs[3], 'Fourth arg should be HTTP method');
        $this->assertEquals('v1', $capturedArgs[4], 'Fifth arg should be API version');

        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('response_time', $result);
    }

    public function test_predictions_with_attributes(): void
    {
        $query        = 'test query';
        $maxResults   = 5;
        $amount       = 1;
        $attributes   = 'test-attributes';
        $capturedArgs = [];

        // Override the default mock to capture storeResponse arguments
        $this->cacheManager->shouldReceive('storeResponse')
            ->andReturnUsing(function (...$args) use (&$capturedArgs) {
                $capturedArgs = $args;
            });

        $result = $this->client->predictions($query, $maxResults, [], $attributes, $amount);

        $this->assertEquals(200, $result['response']->status());
        $responseData = $result['response']->json();
        $this->assertTrue($responseData['success']);
        $this->assertNotEmpty($responseData['data']);
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('response_time', $result);

        // Verify attributes were passed to storeResponse
        $this->assertCount(11, $capturedArgs, 'Expected 11 arguments passed to storeResponse');
        $this->assertEquals('demo', $capturedArgs[0], 'First arg should be client name');
        $this->assertEquals('predictions', $capturedArgs[4], 'Fifth arg should be endpoint');
        $this->assertEquals($attributes, $capturedArgs[7], 'Eighth arg should be attributes');
        $this->assertNull($capturedArgs[8], 'Ninth arg should be attributes2 (null)');
        $this->assertNull($capturedArgs[9], 'Tenth arg should be attributes3 (null)');
        $this->assertEquals($amount, $capturedArgs[10], 'Eleventh arg should be amount');
    }

    public function test_reports_sends_correct_request(): void
    {
        $reportType = 'monthly';
        $dataSource = 'sales';

        $result = $this->client->reports($reportType, $dataSource);

        $this->assertEquals(200, $result['response']->status());
        $responseData = $result['response']->json();
        $this->assertTrue($responseData['success']);
        $this->assertNotEmpty($responseData['data']);
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('response_time', $result);
    }

    public function test_reports_with_attributes(): void
    {
        $reportType   = 'monthly';
        $dataSource   = 'sales';
        $amount       = 1;
        $attributes   = 'report-attributes';
        $capturedArgs = [];

        // Override the default mock to capture storeResponse arguments
        $this->cacheManager->shouldReceive('storeResponse')
            ->andReturnUsing(function (...$args) use (&$capturedArgs) {
                $capturedArgs = $args;
            });

        $result = $this->client->reports($reportType, $dataSource, [], $attributes, $amount);

        $this->assertEquals(200, $result['response']->status());
        $responseData = $result['response']->json();
        $this->assertTrue($responseData['success']);
        $this->assertNotEmpty($responseData['data']);
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('response_time', $result);

        // Verify attributes were passed to storeResponse
        $this->assertCount(11, $capturedArgs, 'Expected 11 arguments passed to storeResponse');
        $this->assertEquals('demo', $capturedArgs[0], 'First arg should be client name');
        $this->assertEquals('reports', $capturedArgs[4], 'Fifth arg should be endpoint');
        $this->assertEquals($attributes, $capturedArgs[7], 'Eighth arg should be attributes');
        $this->assertNull($capturedArgs[8], 'Ninth arg should be attributes2 (null)');
        $this->assertNull($capturedArgs[9], 'Tenth arg should be attributes3 (null)');
        $this->assertEquals($amount, $capturedArgs[10], 'Eleventh arg should be amount');
    }
}

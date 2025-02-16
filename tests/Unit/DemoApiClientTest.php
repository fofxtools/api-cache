<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\DemoApiClient;
use FOfX\ApiCache\ApiCacheManager;
use FOfX\ApiCache\Tests\Traits\ApiServerTestTrait;
use Orchestra\Testbench\TestCase;
use Mockery;
use Illuminate\Support\Facades\Config;

class DemoApiClientTest extends TestCase
{
    use ApiServerTestTrait;

    protected DemoApiClient $client;

    /** @var ApiCacheManager&Mockery\MockInterface */
    protected ApiCacheManager $cacheManager;

    protected string $version = 'v1';

    /**
     * Get package providers.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return ['FOfX\ApiCache\ApiCacheServiceProvider'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $baseUrl = config('api-cache.apis.demo.base_url');
        $baseUrl = $this->getWslAwareBaseUrl($baseUrl);

        $this->checkServerStatus($baseUrl);

        $this->cacheManager = Mockery::mock(ApiCacheManager::class);

        // Set WSL-aware base URL in config
        Config::set('api-cache.apis.demo.base_url', $baseUrl);

        $this->client = new DemoApiClient($this->cacheManager);

        $this->cacheManager->shouldReceive('getCachedResponse')
            ->andReturnNull();
        $this->cacheManager->shouldReceive('allowRequest')
            ->andReturnTrue();
        $this->cacheManager->shouldReceive('generateCacheKey')
            ->byDefault()
            ->andReturn('demo.get.predictions.test-hash.v1');
        $this->cacheManager->shouldReceive('incrementAttempts');
        $this->cacheManager->shouldReceive('storeResponse');
    }

    public function test_builds_url_without_leading_slash(): void
    {
        $url = $this->client->buildUrl('predictions');
        $this->assertEquals(
            config('api-cache.apis.demo.base_url') . '/predictions',
            $url
        );
    }

    public function test_builds_url_with_leading_slash(): void
    {
        $url = $this->client->buildUrl('/predictions');
        $this->assertEquals(
            config('api-cache.apis.demo.base_url') . '/predictions',
            $url
        );
    }

    public function test_predictions_method_sends_correct_request(): void
    {
        $query      = 'test query';
        $maxResults = 5;

        $result = $this->client->predictions($query, $maxResults);

        $this->assertEquals(200, $result['response']->status());
        $responseData = json_decode($result['response']->body(), true);
        $this->assertTrue($responseData['success']);
        $this->assertNotEmpty($responseData['data']);
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('response_time', $result);
    }

    public function test_reports_method_sends_correct_request(): void
    {
        $reportType = 'monthly';
        $dataSource = 'sales';

        $result = $this->client->reports($reportType, $dataSource);

        $this->assertEquals(200, $result['response']->status());
        $responseData = json_decode($result['response']->body(), true);
        $this->assertTrue($responseData['success']);
        $this->assertNotEmpty($responseData['data']);
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('response_time', $result);
    }

    public function test_predictions_method_merges_additional_params(): void
    {
        $additionalParams = ['filter' => 'test'];

        // Override the default mock
        $capturedArgs = [];
        $this->cacheManager->shouldReceive('generateCacheKey')
            ->andReturnUsing(function (...$args) use (&$capturedArgs) {
                $capturedArgs = $args;

                return 'demo.get.predictions.test-hash.v1';
            });

        $result = $this->client->predictions('test', 10, $additionalParams);

        $this->assertEquals(200, $result['response']->status());
        $responseData = json_decode($result['response']->body(), true);
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
}

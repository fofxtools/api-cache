<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\DemoApiClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Config;
use Orchestra\Testbench\TestCase;
use Mockery;

class DemoApiClientTest extends TestCase
{
    /** @var DemoApiClient&\Mockery\MockInterface */
    private DemoApiClient $client;
    private string $apiBaseUrl = 'https://api.test';
    private string $apiKey     = 'test-key';

    protected function getEnvironmentSetUp($app): void
    {
        // Set up test config
        $app['config']->set('api-cache.apis.demo.base_url', $this->apiBaseUrl);
        $app['config']->set('api-cache.apis.demo.api_key', $this->apiKey);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Mockery::mock(DemoApiClient::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $this->client->__construct();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return \Mockery\MockInterface&\Illuminate\Http\Client\Response
     */
    private function mockResponse(int $status)
    {
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('status')->andReturn($status);

        return $response;
    }

    public function test_builds_url_without_leading_slash(): void
    {
        $url = $this->client->buildUrl('predictions');
        $this->assertEquals(
            $this->apiBaseUrl . '/demo-api-server.php/v1/predictions',
            $url
        );
    }

    public function test_builds_url_with_leading_slash(): void
    {
        $url = $this->client->buildUrl('/predictions');
        $this->assertEquals(
            $this->apiBaseUrl . '/demo-api-server.php/v1/predictions',
            $url
        );
    }

    public function test_prediction_method_sends_correct_request(): void
    {
        $query          = 'test query';
        $maxResults     = 5;
        $expectedParams = [
            'query'       => $query,
            'max_results' => $maxResults,
        ];

        $this->client->shouldReceive('sendCachedRequest')
            ->once()
            ->with('predictions', $expectedParams, 'GET')
            ->andReturn([
                'response'      => $this->mockResponse(200),
                'response_time' => 0.5,
            ]);

        $result = $this->client->prediction($query, $maxResults);

        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('response_time', $result);
    }

    public function test_report_method_sends_correct_request(): void
    {
        $reportType     = 'monthly';
        $dataSource     = 'sales';
        $expectedParams = [
            'report_type' => $reportType,
            'data_source' => $dataSource,
        ];

        $this->client->shouldReceive('sendCachedRequest')
            ->once()
            ->with('reports', $expectedParams, 'POST')
            ->andReturn([
                'response'      => $this->mockResponse(200),
                'response_time' => 0.5,
            ]);

        $result = $this->client->report($reportType, $dataSource);

        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('response_time', $result);
    }

    public function test_prediction_method_merges_additional_params(): void
    {
        $additionalParams = ['filter' => 'test'];
        $expectedParams   = [
            'query'       => 'test',
            'max_results' => 10,
            'filter'      => 'test',
        ];

        $this->client->shouldReceive('sendCachedRequest')
            ->once()
            ->with('predictions', $expectedParams, 'GET')
            ->andReturn([
                'response'      => $this->mockResponse(200),
                'response_time' => 0.5,
            ]);

        $result = $this->client->prediction('test', 10, $additionalParams);

        // Assert response structure
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('response_time', $result);
    }
}

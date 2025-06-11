<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\DataForSeoApiClient;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;

class DataForSeoApiClientBacklinksTest extends TestCase
{
    protected DataForSeoApiClient $client;
    protected string $apiBaseUrl = 'https://api.dataforseo.com/v3';
    protected string $version    = 'v3';
    protected string $login      = 'test-login';
    protected string $password   = 'test-password';
    protected array $apiDefaultHeaders;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure test environment
        Config::set('api-cache.apis.dataforseo.base_url', $this->apiBaseUrl);
        Config::set('api-cache.apis.dataforseo.version', $this->version);
        Config::set('api-cache.apis.dataforseo.DATAFORSEO_LOGIN', $this->login);
        Config::set('api-cache.apis.dataforseo.DATAFORSEO_PASSWORD', $this->password);
        Config::set('api-cache.apis.dataforseo.rate_limit_max_attempts', 5);
        Config::set('api-cache.apis.dataforseo.rate_limit_decay_seconds', 10);

        $credentials             = base64_encode("{$this->login}:{$this->password}");
        $this->apiDefaultHeaders = [
            'Authorization' => "Basic {$credentials}",
            'Content-Type'  => 'application/json',
        ];

        $this->client = new DataForSeoApiClient();
        $this->client->setTimeout(10);
        $this->client->clearRateLimit();
    }

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

    public function test_backlinks_summary_live_successful_request()
    {
        $fakeResponse = [
            'version'        => '0.1.20241101',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.5678 sec.',
            'cost'           => 0.012,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => 'test-task-id-12345',
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.4567 sec.',
                    'cost'           => 0.012,
                    'result_count'   => 1,
                    'path'           => ['v3', 'backlinks', 'summary', 'live'],
                    'data'           => [
                        'api'      => 'backlinks',
                        'function' => 'summary',
                        'target'   => 'example.com',
                    ],
                    'result' => [
                        [
                            'target'                 => 'example.com',
                            'total_count'            => 1500,
                            'referring_domains'      => 250,
                            'referring_main_domains' => 200,
                            'backlinks_spam_score'   => 15,
                            'rank'                   => 85.5,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            '*' => Http::response($fakeResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->backlinksSummaryLive('example.com');

        // Verify the fake response was received
        $responseData = $result['response']->json();
        $this->assertEquals('test-task-id-12345', $responseData['tasks'][0]['id']);

        // Check that the request was made to the correct endpoint
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.dataforseo.com/v3/backlinks/summary/live';
        });

        // Verify request structure
        Http::assertSent(function ($request) {
            $requestData = $request->data();
            $this->assertIsArray($requestData);
            $this->assertCount(1, $requestData);

            $taskData = $requestData[0];
            $this->assertEquals('example.com', $taskData['target']);
            $this->assertTrue($taskData['include_subdomains']);
            $this->assertTrue($taskData['include_indirect_links']);
            $this->assertTrue($taskData['exclude_internal_backlinks']);
            $this->assertEquals(10, $taskData['internal_list_limit']);
            $this->assertEquals('live', $taskData['backlinks_status_type']);
            $this->assertEquals('one_thousand', $taskData['rank_scale']);

            return true;
        });

        // Verify response structure
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('request', $result);
        $this->assertArrayHasKey('params', $result);
        $this->assertEquals(0.012, $result['request']['cost']);
    }

    public function test_backlinks_summary_live_validates_empty_target()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target cannot be empty');

        $this->client->backlinksSummaryLive('');
    }

    public function test_backlinks_summary_live_validates_internal_list_limit_parameter()
    {
        // Test minimum boundary
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('internal_list_limit must be between 1 and 1000');

        $this->client->backlinksSummaryLive('example.com', internalListLimit: 0);
    }

    public function test_backlinks_summary_live_validates_internal_list_limit_maximum()
    {
        // Test maximum boundary
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('internal_list_limit must be between 1 and 1000');

        $this->client->backlinksSummaryLive('example.com', internalListLimit: 1001);
    }

    public function test_backlinks_summary_live_validates_backlinks_status_type()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('backlinks_status_type must be one of: all, live, lost');

        $this->client->backlinksSummaryLive('example.com', backlinksStatusType: 'invalid');
    }

    public function test_backlinks_summary_live_validates_rank_scale()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rank_scale must be one of: one_hundred, one_thousand');

        $this->client->backlinksSummaryLive('example.com', rankScale: 'invalid');
    }

    public function test_backlinks_summary_live_validates_tag_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');

        $longTag = str_repeat('a', 256);
        $this->client->backlinksSummaryLive('example.com', tag: $longTag);
    }

    public static function backlinksParametersProvider()
    {
        return [
            'basic parameters' => [
                [
                    'target'                   => 'example.com',
                    'includeSubdomains'        => false,
                    'includeIndirectLinks'     => false,
                    'excludeInternalBacklinks' => false,
                    'internalListLimit'        => 50,
                    'backlinksStatusType'      => 'all',
                    'rankScale'                => 'one_hundred',
                    'tag'                      => 'test-tag',
                ],
                [
                    'target'                     => 'example.com',
                    'include_subdomains'         => false,
                    'include_indirect_links'     => false,
                    'exclude_internal_backlinks' => false,
                    'internal_list_limit'        => 50,
                    'backlinks_status_type'      => 'all',
                    'rank_scale'                 => 'one_hundred',
                    'tag'                        => 'test-tag',
                ],
            ],
            'with backlinks filters' => [
                [
                    'target'           => 'test-domain.com',
                    'backlinksFilters' => [
                        ['dofollow', '=', true],
                        ['page_from_rank', '>', 50],
                    ],
                    'tag' => 'filtered-test',
                ],
                [
                    'target'                     => 'test-domain.com',
                    'include_subdomains'         => true,
                    'include_indirect_links'     => true,
                    'exclude_internal_backlinks' => true,
                    'internal_list_limit'        => 10,
                    'backlinks_status_type'      => 'live',
                    'backlinks_filters'          => [
                        ['dofollow', '=', true],
                        ['page_from_rank', '>', 50],
                    ],
                    'rank_scale' => 'one_thousand',
                    'tag'        => 'filtered-test',
                ],
            ],
            'subdomain target' => [
                [
                    'target'              => 'blog.example.com',
                    'includeSubdomains'   => true,
                    'backlinksStatusType' => 'lost',
                    'internalListLimit'   => 100,
                ],
                [
                    'target'                     => 'blog.example.com',
                    'include_subdomains'         => true,
                    'include_indirect_links'     => true,
                    'exclude_internal_backlinks' => true,
                    'internal_list_limit'        => 100,
                    'backlinks_status_type'      => 'lost',
                    'rank_scale'                 => 'one_thousand',
                ],
            ],
            'webpage target' => [
                [
                    'target'               => 'https://example.com/page',
                    'includeSubdomains'    => false,
                    'includeIndirectLinks' => false,
                    'internalListLimit'    => 1000,
                    'rankScale'            => 'one_hundred',
                ],
                [
                    'target'                     => 'https://example.com/page',
                    'include_subdomains'         => false,
                    'include_indirect_links'     => false,
                    'exclude_internal_backlinks' => true,
                    'internal_list_limit'        => 1000,
                    'backlinks_status_type'      => 'live',
                    'rank_scale'                 => 'one_hundred',
                ],
            ],
            'minimum parameters' => [
                [
                    'target' => 'minimal.com',
                ],
                [
                    'target'                     => 'minimal.com',
                    'include_subdomains'         => true,
                    'include_indirect_links'     => true,
                    'exclude_internal_backlinks' => true,
                    'internal_list_limit'        => 10,
                    'backlinks_status_type'      => 'live',
                    'rank_scale'                 => 'one_thousand',
                ],
            ],
        ];
    }

    #[DataProvider('backlinksParametersProvider')]
    public function test_backlinks_summary_live_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $fakeResponse = [
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'          => 'test-task-id-12345',
                    'status_code' => 20000,
                    'result'      => [[
                        'target'      => $parameters['target'],
                        'total_count' => 500,
                    ]],
                ],
            ],
        ];

        Http::fake([
            '*' => Http::response($fakeResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        // Call the method with the test parameters
        $result = $this->client->backlinksSummaryLive(...$parameters);

        // Verify the fake response was received
        $responseData = $result['response']->json();
        $this->assertEquals('test-task-id-12345', $responseData['tasks'][0]['id']);

        // Verify the request parameters
        Http::assertSent(function ($request) use ($expectedParams) {
            $requestData = $request->data();
            $this->assertIsArray($requestData);
            $this->assertCount(1, $requestData);

            $taskData = $requestData[0];
            foreach ($expectedParams as $key => $value) {
                $this->assertEquals($value, $taskData[$key], "Parameter {$key} does not match expected value");
            }

            return true;
        });
    }

    public function test_backlinks_summary_live_handles_api_errors()
    {
        $errorResponse = [
            'version'        => '0.1.20241101',
            'status_code'    => 40000,
            'status_message' => 'API error occurred',
            'time'           => '0.0012 sec.',
            'cost'           => 0,
            'tasks_count'    => 0,
            'tasks_error'    => 1,
            'tasks'          => [],
        ];

        Http::fake([
            '*' => Http::response($errorResponse, 400),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->backlinksSummaryLive('example.com');

        // Check that the error response is received properly
        $this->assertEquals(400, $result['response']->status());
        $this->assertEquals($errorResponse, $result['response']->json());
    }

    public function test_backlinks_summary_live_with_additional_params()
    {
        $fakeResponse = [
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'          => 'test-task-id-12345',
                    'status_code' => 20000,
                    'result'      => [[
                        'target'      => 'example.com',
                        'total_count' => 750,
                    ]],
                ],
            ],
        ];

        Http::fake([
            '*' => Http::response($fakeResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $additionalParams = [
            'custom_field'  => 'custom_value',
            'another_param' => 123,
        ];

        $result = $this->client->backlinksSummaryLive(
            'example.com',
            additionalParams: $additionalParams
        );

        // Verify the fake response was received
        $responseData = $result['response']->json();
        $this->assertEquals('test-task-id-12345', $responseData['tasks'][0]['id']);

        // Verify additional parameters were included
        Http::assertSent(function ($request) use ($additionalParams) {
            $requestData = $request->data()[0];
            foreach ($additionalParams as $key => $value) {
                $this->assertEquals($value, $requestData[$key]);
            }

            return true;
        });
    }

    public function test_backlinks_history_live_successful_request()
    {
        $fakeResponse = [
            'version'        => '0.1.20241101',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2341 sec.',
            'cost'           => 0.05,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => 'test-task-id-history-67890',
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2341 sec.',
                    'cost'           => 0.05,
                    'result_count'   => 1,
                    'path'           => ['v3', 'backlinks', 'history', 'live'],
                    'data'           => [
                        'api'        => 'backlinks',
                        'function'   => 'history',
                        'se_type'    => 'backlinks',
                        'target'     => 'example.com',
                        'date_from'  => '2019-01-01',
                        'date_to'    => '2024-01-01',
                        'rank_scale' => 'one_thousand',
                    ],
                    'result' => [[
                        'target'  => 'example.com',
                        'history' => [
                            [
                                'date'           => '2019-01-01',
                                'backlinks'      => 150,
                                'new_backlinks'  => 10,
                                'lost_backlinks' => 5,
                            ],
                            [
                                'date'           => '2024-01-01',
                                'backlinks'      => 300,
                                'new_backlinks'  => 25,
                                'lost_backlinks' => 8,
                            ],
                        ],
                    ]],
                ],
            ],
        ];

        Http::fake([
            '*' => Http::response($fakeResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->backlinksHistoryLive('example.com');

        // Verify endpoint was called correctly
        Http::assertSent(function ($request) {
            $this->assertEquals('POST', $request->method());
            $this->assertStringContainsString('backlinks/history/live', $request->url());

            return true;
        });

        // Verify the fake response was received
        $responseData = $result['response']->json();
        $this->assertEquals('test-task-id-history-67890', $responseData['tasks'][0]['id']);
        $this->assertEquals('example.com', $responseData['tasks'][0]['result'][0]['target']);

        // Make sure we used the Http::fake() response
        $this->assertArrayHasKey('history', $responseData['tasks'][0]['result'][0]);
    }

    public function test_backlinks_history_live_validates_empty_target()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target cannot be empty');

        $this->client->backlinksHistoryLive('');
    }

    public function test_backlinks_history_live_validates_date_from_format()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('date_from must be in yyyy-mm-dd format');

        $this->client->backlinksHistoryLive('example.com', dateFrom: '2024-1-1');
    }

    public function test_backlinks_history_live_validates_date_from_minimum()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('date_from must be 2019-01-01 or later');

        $this->client->backlinksHistoryLive('example.com', dateFrom: '2018-12-31');
    }

    public function test_backlinks_history_live_validates_date_to_format()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('date_to must be in yyyy-mm-dd format');

        $this->client->backlinksHistoryLive('example.com', dateTo: '2024/01/01');
    }

    public function test_backlinks_history_live_validates_rank_scale()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rank_scale must be one of: one_hundred, one_thousand');

        $this->client->backlinksHistoryLive('example.com', rankScale: 'invalid');
    }

    public function test_backlinks_history_live_validates_tag_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');

        $longTag = str_repeat('a', 256);
        $this->client->backlinksHistoryLive('example.com', tag: $longTag);
    }

    public static function backlinksHistoryParametersProvider()
    {
        return [
            'basic parameters' => [
                [
                    'target'    => 'example.com',
                    'dateFrom'  => '2022-01-01',
                    'dateTo'    => '2024-01-01',
                    'rankScale' => 'one_hundred',
                    'tag'       => 'test-tag',
                ],
                [
                    'target'     => 'example.com',
                    'date_from'  => '2022-01-01',
                    'date_to'    => '2024-01-01',
                    'rank_scale' => 'one_hundred',
                    'tag'        => 'test-tag',
                ],
            ],
            'subdomain target with date range' => [
                [
                    'target'    => 'blog.example.com',
                    'dateFrom'  => '2020-06-15',
                    'dateTo'    => '2023-12-31',
                    'rankScale' => 'one_thousand',
                ],
                [
                    'target'     => 'blog.example.com',
                    'date_from'  => '2020-06-15',
                    'date_to'    => '2023-12-31',
                    'rank_scale' => 'one_thousand',
                ],
            ],
            'webpage target with minimum date' => [
                [
                    'target'    => 'https://example.com/page',
                    'dateFrom'  => '2019-01-01',
                    'rankScale' => 'one_hundred',
                    'tag'       => 'webpage-history',
                ],
                [
                    'target'     => 'https://example.com/page',
                    'date_from'  => '2019-01-01',
                    'rank_scale' => 'one_hundred',
                    'tag'        => 'webpage-history',
                ],
            ],
            'only date_to specified' => [
                [
                    'target' => 'test-domain.com',
                    'dateTo' => '2023-06-30',
                    'tag'    => 'historical-data',
                ],
                [
                    'target'     => 'test-domain.com',
                    'date_to'    => '2023-06-30',
                    'rank_scale' => 'one_thousand',
                    'tag'        => 'historical-data',
                ],
            ],
            'minimum parameters' => [
                [
                    'target' => 'minimal.com',
                ],
                [
                    'target'     => 'minimal.com',
                    'rank_scale' => 'one_thousand',
                ],
            ],
        ];
    }

    #[DataProvider('backlinksHistoryParametersProvider')]
    public function test_backlinks_history_live_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $fakeResponse = [
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'          => 'test-history-task-id-12345',
                    'status_code' => 20000,
                    'result'      => [[
                        'target'  => $parameters['target'],
                        'history' => [],
                    ]],
                ],
            ],
        ];

        Http::fake([
            '*' => Http::response($fakeResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        // Call the method with the test parameters
        $result = $this->client->backlinksHistoryLive(...$parameters);

        // Verify the fake response was received
        $responseData = $result['response']->json();
        $this->assertEquals('test-history-task-id-12345', $responseData['tasks'][0]['id']);

        // Verify the request parameters
        Http::assertSent(function ($request) use ($expectedParams) {
            $requestData = $request->data();
            $this->assertIsArray($requestData);
            $this->assertCount(1, $requestData);

            $taskData = $requestData[0];
            foreach ($expectedParams as $key => $value) {
                $this->assertEquals($value, $taskData[$key], "Parameter {$key} does not match expected value");
            }

            return true;
        });
    }

    public function test_backlinks_history_live_handles_api_errors()
    {
        $errorResponse = [
            'version'        => '0.1.20241101',
            'status_code'    => 40000,
            'status_message' => 'API error occurred',
            'time'           => '0.0012 sec.',
            'cost'           => 0,
            'tasks_count'    => 0,
            'tasks_error'    => 1,
            'tasks'          => [],
        ];

        Http::fake([
            '*' => Http::response($errorResponse, 400),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->backlinksHistoryLive('example.com');

        // Check that the error response is received properly
        $this->assertEquals(400, $result['response']->status());
        $this->assertEquals($errorResponse, $result['response']->json());
    }

    public function test_backlinks_history_live_with_additional_params()
    {
        $fakeResponse = [
            'status_code' => 20000,
            'tasks'       => [
                [
                    'id'          => 'test-history-task-id-12345',
                    'status_code' => 20000,
                    'result'      => [[
                        'target'  => 'example.com',
                        'history' => [],
                    ]],
                ],
            ],
        ];

        Http::fake([
            '*' => Http::response($fakeResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $additionalParams = [
            'custom_field'  => 'custom_value',
            'another_param' => 456,
        ];

        $result = $this->client->backlinksHistoryLive(
            'example.com',
            additionalParams: $additionalParams
        );

        // Verify the fake response was received
        $responseData = $result['response']->json();
        $this->assertEquals('test-history-task-id-12345', $responseData['tasks'][0]['id']);

        // Verify additional parameters were included
        Http::assertSent(function ($request) use ($additionalParams) {
            $requestData = $request->data()[0];
            foreach ($additionalParams as $key => $value) {
                $this->assertEquals($value, $requestData[$key]);
            }

            return true;
        });
    }
}

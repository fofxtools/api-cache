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
            'domain target with minimum date' => [
                [
                    'target'    => 'example-page.com',
                    'dateFrom'  => '2019-01-01',
                    'rankScale' => 'one_hundred',
                    'tag'       => 'domain-history',
                ],
                [
                    'target'     => 'example-page.com',
                    'date_from'  => '2019-01-01',
                    'rank_scale' => 'one_hundred',
                    'tag'        => 'domain-history',
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

    // Test methods for backlinksBacklinksLive
    public function test_backlinks_backlinks_live_successful_request()
    {
        $id           = 'test-backlinks-id-12345';
        $fakeResponse = [
            'version'        => '0.1.20241101',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '1.2345 sec.',
            'cost'           => 0.025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '1.1234 sec.',
                    'cost'           => 0.025,
                    'result_count'   => 1,
                    'path'           => ['v3', 'backlinks', 'backlinks', 'live'],
                    'data'           => [
                        'api'      => 'backlinks',
                        'function' => 'backlinks',
                        'target'   => 'example.com',
                    ],
                    'result' => [
                        [
                            'target'      => 'example.com',
                            'domain_from' => 'referring-site.com',
                            'url_from'    => 'https://referring-site.com/page',
                            'url_to'      => 'https://example.com/target-page',
                            'anchor'      => 'example link',
                            'dofollow'    => true,
                            'rank'        => 45.6,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            '*' => Http::response($fakeResponse, 200),
        ]);

        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->backlinksBacklinksLive('example.com');

        // Verify the fake response was received
        $responseData = $result['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);

        // Check that the request was made to the correct endpoint
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.dataforseo.com/v3/backlinks/backlinks/live';
        });

        // Verify request structure
        Http::assertSent(function ($request) {
            $requestData = $request->data();
            $this->assertIsArray($requestData);
            $this->assertCount(1, $requestData);

            $taskData = $requestData[0];
            $this->assertEquals('example.com', $taskData['target']);
            $this->assertEquals('as_is', $taskData['mode']);
            $this->assertEquals(0, $taskData['offset']);
            $this->assertEquals(100, $taskData['limit']);
            $this->assertEquals('live', $taskData['backlinks_status_type']);
            $this->assertTrue($taskData['include_subdomains']);
            $this->assertTrue($taskData['include_indirect_links']);
            $this->assertTrue($taskData['exclude_internal_backlinks']);
            $this->assertEquals('one_thousand', $taskData['rank_scale']);

            return true;
        });

        // Verify response structure
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('request', $result);
        $this->assertArrayHasKey('params', $result);
        $this->assertEquals(0.025, $result['request']['cost']);
    }

    public function test_backlinks_backlinks_live_validates_empty_target()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target cannot be empty');

        $this->client->backlinksBacklinksLive('');
    }

    public function test_backlinks_backlinks_live_validates_mode_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mode must be one of: as_is, one_per_domain, one_per_anchor');

        $this->client->backlinksBacklinksLive('example.com', mode: 'invalid');
    }

    public function test_backlinks_backlinks_live_validates_custom_mode_structure()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('custom_mode must be an array with field and value keys');

        $this->client->backlinksBacklinksLive('example.com', customMode: ['invalid' => 'structure']);
    }

    public function test_backlinks_backlinks_live_validates_custom_mode_field()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('custom_mode field must be one of: anchor, domain_from, domain_from_country, tld_from, page_from_encoding, page_from_language, item_type, page_from_status_code, semantic_location');

        $this->client->backlinksBacklinksLive('example.com', customMode: ['field' => 'invalid_field', 'value' => 10]);
    }

    public function test_backlinks_backlinks_live_validates_custom_mode_value()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('custom_mode value must be between 1 and 1000');

        $this->client->backlinksBacklinksLive('example.com', customMode: ['field' => 'anchor', 'value' => 1001]);
    }

    public function test_backlinks_backlinks_live_validates_offset_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('offset must be between 0 and 20,000');

        $this->client->backlinksBacklinksLive('example.com', offset: 20001);
    }

    public function test_backlinks_backlinks_live_validates_limit_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('limit must be between 1 and 1000');

        $this->client->backlinksBacklinksLive('example.com', limit: 1001);
    }

    // Test methods for backlinksAnchorsLive
    public function test_backlinks_anchors_live_successful_request()
    {
        $id           = 'test-anchors-id-12345';
        $fakeResponse = [
            'version'        => '0.1.20241101',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.8765 sec.',
            'cost'           => 0.018,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.7654 sec.',
                    'cost'           => 0.018,
                    'result_count'   => 1,
                    'path'           => ['v3', 'backlinks', 'anchors', 'live'],
                    'data'           => [
                        'api'      => 'backlinks',
                        'function' => 'anchors',
                        'target'   => 'example.com',
                    ],
                    'result' => [
                        [
                            'anchor'            => 'example link',
                            'backlinks'         => 125,
                            'referring_domains' => 45,
                            'rank'              => 67.8,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            '*' => Http::response($fakeResponse, 200),
        ]);

        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->backlinksAnchorsLive('example.com');

        // Verify the fake response was received
        $responseData = $result['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);

        // Check that the request was made to the correct endpoint
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.dataforseo.com/v3/backlinks/anchors/live';
        });

        // Verify request structure
        Http::assertSent(function ($request) {
            $requestData = $request->data();
            $this->assertIsArray($requestData);
            $this->assertCount(1, $requestData);

            $taskData = $requestData[0];
            $this->assertEquals('example.com', $taskData['target']);
            $this->assertEquals(100, $taskData['limit']);
            $this->assertEquals(0, $taskData['offset']);
            $this->assertEquals(10, $taskData['internal_list_limit']);
            $this->assertEquals('live', $taskData['backlinks_status_type']);
            $this->assertTrue($taskData['include_subdomains']);
            $this->assertTrue($taskData['include_indirect_links']);
            $this->assertTrue($taskData['exclude_internal_backlinks']);
            $this->assertEquals('one_thousand', $taskData['rank_scale']);

            return true;
        });

        // Verify response structure
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('request', $result);
        $this->assertArrayHasKey('params', $result);
        $this->assertEquals(0.018, $result['request']['cost']);
    }

    public function test_backlinks_anchors_live_validates_empty_target()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target cannot be empty');

        $this->client->backlinksAnchorsLive('');
    }

    public function test_backlinks_anchors_live_validates_limit_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('limit must be between 1 and 1000');

        $this->client->backlinksAnchorsLive('example.com', limit: 0);
    }

    public function test_backlinks_anchors_live_validates_offset_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('offset must be non-negative');

        $this->client->backlinksAnchorsLive('example.com', offset: -1);
    }

    public function test_backlinks_anchors_live_validates_internal_list_limit_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('internal_list_limit must be between 1 and 1000');

        $this->client->backlinksAnchorsLive('example.com', internalListLimit: 0);
    }

    // Test methods for backlinksDomainPagesLive
    public function test_backlinks_domain_pages_live_successful_request()
    {
        $id           = 'test-domain-pages-id-12345';
        $fakeResponse = [
            'version'        => '0.1.20241101',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.9876 sec.',
            'cost'           => 0.022,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.8765 sec.',
                    'cost'           => 0.022,
                    'result_count'   => 1,
                    'path'           => ['v3', 'backlinks', 'domain_pages', 'live'],
                    'data'           => [
                        'api'      => 'backlinks',
                        'function' => 'domain_pages',
                        'target'   => 'example.com',
                    ],
                    'result' => [
                        [
                            'page'              => 'https://example.com/page1',
                            'backlinks'         => 85,
                            'referring_domains' => 32,
                            'rank'              => 54.7,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            '*' => Http::response($fakeResponse, 200),
        ]);

        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->backlinksDomainPagesLive('example.com');

        // Verify the fake response was received
        $responseData = $result['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);

        // Check that the request was made to the correct endpoint
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.dataforseo.com/v3/backlinks/domain_pages/live';
        });

        // Verify request structure
        Http::assertSent(function ($request) {
            $requestData = $request->data();
            $this->assertIsArray($requestData);
            $this->assertCount(1, $requestData);

            $taskData = $requestData[0];
            $this->assertEquals('example.com', $taskData['target']);
            $this->assertEquals(100, $taskData['limit']);
            $this->assertEquals(0, $taskData['offset']);
            $this->assertEquals(10, $taskData['internal_list_limit']);
            $this->assertEquals('live', $taskData['backlinks_status_type']);
            $this->assertTrue($taskData['include_subdomains']);
            $this->assertTrue($taskData['exclude_internal_backlinks']);
            $this->assertEquals('one_thousand', $taskData['rank_scale']);

            return true;
        });

        // Verify response structure
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('request', $result);
        $this->assertArrayHasKey('params', $result);
        $this->assertEquals(0.022, $result['request']['cost']);
    }

    public function test_backlinks_domain_pages_live_validates_empty_target()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target cannot be empty');

        $this->client->backlinksDomainPagesLive('');
    }

    // Test methods for backlinksDomainPagesSummaryLive
    public function test_backlinks_domain_pages_summary_live_successful_request()
    {
        $id           = 'test-domain-pages-summary-id-12345';
        $fakeResponse = [
            'version'        => '0.1.20241101',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.7654 sec.',
            'cost'           => 0.016,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.6543 sec.',
                    'cost'           => 0.016,
                    'result_count'   => 1,
                    'path'           => ['v3', 'backlinks', 'domain_pages_summary', 'live'],
                    'data'           => [
                        'api'      => 'backlinks',
                        'function' => 'domain_pages_summary',
                        'target'   => 'example.com',
                    ],
                    'result' => [
                        [
                            'target'               => 'example.com',
                            'total_pages'          => 450,
                            'pages_with_backlinks' => 125,
                            'total_backlinks'      => 2500,
                            'referring_domains'    => 185,
                            'rank'                 => 72.3,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            '*' => Http::response($fakeResponse, 200),
        ]);

        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->backlinksDomainPagesSummaryLive('example.com');

        // Verify the fake response was received
        $responseData = $result['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);

        // Check that the request was made to the correct endpoint
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.dataforseo.com/v3/backlinks/domain_pages_summary/live';
        });

        // Verify request structure
        Http::assertSent(function ($request) {
            $requestData = $request->data();
            $this->assertIsArray($requestData);
            $this->assertCount(1, $requestData);

            $taskData = $requestData[0];
            $this->assertEquals('example.com', $taskData['target']);
            $this->assertEquals(100, $taskData['limit']);
            $this->assertEquals(0, $taskData['offset']);
            $this->assertEquals(10, $taskData['internal_list_limit']);
            $this->assertEquals('live', $taskData['backlinks_status_type']);
            $this->assertTrue($taskData['include_subdomains']);
            $this->assertTrue($taskData['include_indirect_links']);
            $this->assertTrue($taskData['exclude_internal_backlinks']);
            $this->assertEquals('one_thousand', $taskData['rank_scale']);

            return true;
        });

        // Verify response structure
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('request', $result);
        $this->assertArrayHasKey('params', $result);
        $this->assertEquals(0.016, $result['request']['cost']);
    }

    public function test_backlinks_domain_pages_summary_live_validates_empty_target()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target cannot be empty');

        $this->client->backlinksDomainPagesSummaryLive('');
    }

    // Test methods for backlinksReferringDomainsLive
    public function test_backlinks_referring_domains_live_successful_request()
    {
        $id           = 'test-referring-domains-id-12345';
        $fakeResponse = [
            'version'        => '0.1.20241101',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '1.1234 sec.',
            'cost'           => 0.028,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '1.0123 sec.',
                    'cost'           => 0.028,
                    'result_count'   => 1,
                    'path'           => ['v3', 'backlinks', 'referring_domains', 'live'],
                    'data'           => [
                        'api'      => 'backlinks',
                        'function' => 'referring_domains',
                        'target'   => 'example.com',
                    ],
                    'result' => [
                        [
                            'domain'             => 'referring-site.com',
                            'backlinks'          => 45,
                            'referring_pages'    => 12,
                            'dofollow_backlinks' => 38,
                            'rank'               => 61.4,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            '*' => Http::response($fakeResponse, 200),
        ]);

        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->backlinksReferringDomainsLive('example.com');

        // Verify the fake response was received
        $responseData = $result['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);

        // Check that the request was made to the correct endpoint
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.dataforseo.com/v3/backlinks/referring_domains/live';
        });

        // Verify request structure
        Http::assertSent(function ($request) {
            $requestData = $request->data();
            $this->assertIsArray($requestData);
            $this->assertCount(1, $requestData);

            $taskData = $requestData[0];
            $this->assertEquals('example.com', $taskData['target']);
            $this->assertEquals(100, $taskData['limit']);
            $this->assertEquals(0, $taskData['offset']);
            $this->assertEquals(10, $taskData['internal_list_limit']);
            $this->assertEquals('live', $taskData['backlinks_status_type']);
            $this->assertTrue($taskData['include_subdomains']);
            $this->assertTrue($taskData['include_indirect_links']);
            $this->assertTrue($taskData['exclude_internal_backlinks']);
            $this->assertEquals('one_thousand', $taskData['rank_scale']);

            return true;
        });

        // Verify response structure
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('request', $result);
        $this->assertArrayHasKey('params', $result);
        $this->assertEquals(0.028, $result['request']['cost']);
    }

    public function test_backlinks_referring_domains_live_validates_empty_target()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target cannot be empty');

        $this->client->backlinksReferringDomainsLive('');
    }

    // Test methods for backlinksReferringNetworksLive
    public function test_backlinks_referring_networks_live_successful_request()
    {
        $id           = 'test-referring-networks-id-12345';
        $fakeResponse = [
            'version'        => '0.1.20241101',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.9876 sec.',
            'cost'           => 0.021,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.8765 sec.',
                    'cost'           => 0.021,
                    'result_count'   => 1,
                    'path'           => ['v3', 'backlinks', 'referring_networks', 'live'],
                    'data'           => [
                        'api'      => 'backlinks',
                        'function' => 'referring_networks',
                        'target'   => 'example.com',
                    ],
                    'result' => [
                        [
                            'network_address'   => '192.168.1.0',
                            'backlinks'         => 28,
                            'referring_domains' => 8,
                            'referring_ips'     => 15,
                            'rank'              => 45.6,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            '*' => Http::response($fakeResponse, 200),
        ]);

        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->backlinksReferringNetworksLive('example.com');

        // Verify the fake response was received
        $responseData = $result['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);

        // Check that the request was made to the correct endpoint
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.dataforseo.com/v3/backlinks/referring_networks/live';
        });

        // Verify request structure
        Http::assertSent(function ($request) {
            $requestData = $request->data();
            $this->assertIsArray($requestData);
            $this->assertCount(1, $requestData);

            $taskData = $requestData[0];
            $this->assertEquals('example.com', $taskData['target']);
            $this->assertEquals('ip', $taskData['network_address_type']);
            $this->assertEquals(100, $taskData['limit']);
            $this->assertEquals(0, $taskData['offset']);
            $this->assertEquals(10, $taskData['internal_list_limit']);
            $this->assertEquals('live', $taskData['backlinks_status_type']);
            $this->assertTrue($taskData['include_subdomains']);
            $this->assertTrue($taskData['include_indirect_links']);
            $this->assertTrue($taskData['exclude_internal_backlinks']);
            $this->assertEquals('one_thousand', $taskData['rank_scale']);

            return true;
        });

        // Verify response structure
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('request', $result);
        $this->assertArrayHasKey('params', $result);
        $this->assertEquals(0.021, $result['request']['cost']);
    }

    public function test_backlinks_referring_networks_live_validates_empty_target()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target cannot be empty');

        $this->client->backlinksReferringNetworksLive('');
    }

    public function test_backlinks_referring_networks_live_validates_network_address_type()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('network_address_type must be one of: ip, subnet');

        $this->client->backlinksReferringNetworksLive('example.com', networkAddressType: 'invalid');
    }

    // Additional validation tests for common parameters
    public function test_backlinks_methods_validate_backlinks_status_type()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('backlinks_status_type must be one of: all, live, lost');

        $this->client->backlinksAnchorsLive('example.com', backlinksStatusType: 'invalid');
    }

    public function test_backlinks_methods_validate_rank_scale()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rank_scale must be one of: one_hundred, one_thousand');

        $this->client->backlinksDomainPagesLive('example.com', rankScale: 'invalid');
    }

    public function test_backlinks_methods_validate_tag_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');

        $longTag = str_repeat('a', 256);
        $this->client->backlinksReferringDomainsLive('example.com', tag: $longTag);
    }

    public function test_backlinks_methods_with_additional_params()
    {
        $id           = 'test-additional-params-id';
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
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.4567 sec.',
                    'cost'           => 0.012,
                    'result_count'   => 1,
                    'path'           => ['v3', 'backlinks', 'backlinks', 'live'],
                    'data'           => [
                        'api'      => 'backlinks',
                        'function' => 'backlinks',
                        'target'   => 'example.com',
                    ],
                    'result' => [[]],
                ],
            ],
        ];

        Http::fake([
            '*' => Http::response($fakeResponse, 200),
        ]);

        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $additionalParams = [
            'custom_param'  => 'custom_value',
            'another_param' => 123,
        ];

        $result = $this->client->backlinksBacklinksLive(
            'example.com',
            additionalParams: $additionalParams
        );

        $responseData = $result['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);

        // Verify request structure includes additional params
        Http::assertSent(function ($request) {
            $requestData = $request->data();
            $taskData    = $requestData[0];

            $this->assertEquals('custom_value', $taskData['custom_param']);
            $this->assertEquals(123, $taskData['another_param']);

            return true;
        });

        // Verify response structure
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('request', $result);
        $this->assertArrayHasKey('params', $result);
    }

    /**
     * Test target validation for domain-only methods
     */
    public function test_domain_only_methods_accept_valid_domains()
    {
        $fakeResponse = ['status_code' => 20000, 'tasks' => [['id' => 'test-id', 'result' => []]]];
        Http::fake(['*' => Http::response($fakeResponse, 200)]);

        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        // Test valid domain target - just test one to verify validation passes
        $result = $this->client->backlinksDomainPagesLive('example.com');

        $this->assertArrayHasKey('response', $result);
    }

    public function test_domain_only_methods_reject_www()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target domain must be specified without www.');
        $this->client->backlinksDomainPagesLive('www.example.com');
    }

    public function test_domain_only_methods_reject_http()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target domain must be specified without https:// or http://');
        $this->client->backlinksHistoryLive('http://example.com');
    }

    public function test_domain_only_methods_reject_invalid_domain()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target must be a valid domain or subdomain');
        $this->client->backlinksDomainPagesLive('invalid..domain');
    }

    /**
     * Test target validation for domain-or-page methods
     */
    public function test_domain_or_page_methods_accept_valid_domains()
    {
        $fakeResponse = ['status_code' => 20000, 'tasks' => [['id' => 'test-id', 'result' => []]]];
        Http::fake(['*' => Http::response($fakeResponse, 200)]);

        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        // Test valid domain target - just test one to verify validation passes
        $result = $this->client->backlinksSummaryLive('example.com');

        $this->assertArrayHasKey('response', $result);
    }

    public function test_domain_or_page_methods_accept_valid_urls()
    {
        $fakeResponse = ['status_code' => 20000, 'tasks' => [['id' => 'test-id', 'result' => []]]];
        Http::fake(['*' => Http::response($fakeResponse, 200)]);

        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        // Test valid URL target - just test one to verify validation passes
        $result = $this->client->backlinksSummaryLive('https://example.com/page');

        $this->assertArrayHasKey('response', $result);
    }

    public function test_domain_or_page_methods_reject_domain_with_www()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target domain must be specified without www. (for domains) or as absolute URL (for pages)');
        $this->client->backlinksSummaryLive('www.example.com');
    }

    public function test_domain_or_page_methods_reject_invalid_url()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target URL must be a valid absolute URL');
        $this->client->backlinksBacklinksLive('https://invalid..url');
    }

    public function test_domain_or_page_methods_reject_invalid_domain_format()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target must be a valid domain/subdomain (without https:// and www.) or absolute URL (with http:// or https://)');
        $this->client->backlinksAnchorsLive('invalid-domain-format!@#');
    }

    public function test_all_domain_or_page_methods_reject_www_domain()
    {
        $methods = [
            'backlinksSummaryLive',
            'backlinksBacklinksLive',
            'backlinksAnchorsLive',
            'backlinksDomainPagesSummaryLive',
            'backlinksReferringDomainsLive',
            'backlinksReferringNetworksLive',
        ];

        foreach ($methods as $method) {
            try {
                $this->client->$method('www.example.com');
                $this->fail("Method $method should have thrown an exception for www.example.com");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Target domain must be specified without www.', $e->getMessage());
            }
        }
    }

    public function test_all_domain_only_methods_reject_https()
    {
        $methods = [
            'backlinksHistoryLive',
            'backlinksDomainPagesLive',
        ];

        foreach ($methods as $method) {
            try {
                $this->client->$method('https://example.com');
                $this->fail("Method $method should have thrown an exception for https://example.com");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Target domain must be specified without https:// or http://', $e->getMessage());
            }
        }
    }

    public function test_target_validation_preserves_other_validations()
    {
        // Test that target validation doesn't break other parameter validations
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('limit must be between 1 and 1000');
        $this->client->backlinksAnchorsLive('example.com', 2000); // Invalid limit
    }

    /**
     * Test backlinks bulk ranks live successful request
     */
    public function test_backlinks_bulk_ranks_live_successful_request()
    {
        $id           = 'test-id-' . uniqid();
        $fakeResponse = [
            'version'        => '0.1.20241101',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.5678 sec.',
            'cost'           => 0.025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.4567 sec.',
                    'cost'           => 0.025,
                    'result_count'   => 1,
                    'path'           => ['v3', 'backlinks', 'bulk_ranks', 'live'],
                    'data'           => [
                        'api'      => 'backlinks',
                        'function' => 'bulk_ranks',
                        'targets'  => ['example.com', 'test.com'],
                    ],
                    'result' => [[]],
                ],
            ],
        ];

        Http::fake([
            '*' => Http::response($fakeResponse, 200),
        ]);

        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $targets = ['example.com', 'test.com'];
        $result  = $this->client->backlinksBulkRanksLive($targets);

        $responseData = $result['response']->json();

        $this->assertEquals(20000, $responseData['status_code']);
        $this->assertEquals('Ok.', $responseData['status_message']);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);

        // Verify request structure
        Http::assertSent(function ($request) {
            $this->assertStringContainsString('backlinks/bulk_ranks/live', $request->url());
            $this->assertEquals('POST', $request->method());

            $requestData = $request->data();
            $this->assertIsArray($requestData);
            $this->assertNotEmpty($requestData);

            $taskData = $requestData[0];
            $this->assertEquals(['example.com', 'test.com'], $taskData['targets']);
            $this->assertEquals('one_thousand', $taskData['rank_scale']);

            return true;
        });

        // Verify response structure
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('request', $result);
        $this->assertArrayHasKey('params', $result);
    }

    public function test_backlinks_bulk_ranks_live_validates_empty_targets()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Targets array cannot be empty');
        $this->client->backlinksBulkRanksLive([]);
    }

    public function test_backlinks_bulk_ranks_live_validates_targets_limit()
    {
        $targets = array_fill(0, 1001, 'example.com');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of targets is 1000');
        $this->client->backlinksBulkRanksLive($targets);
    }

    public function test_backlinks_bulk_ranks_live_validates_empty_target()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target cannot be empty');
        $this->client->backlinksBulkRanksLive(['example.com', '']);
    }

    public function test_backlinks_bulk_ranks_live_validates_rank_scale()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rank_scale must be one of: one_hundred, one_thousand');
        $this->client->backlinksBulkRanksLive(['example.com'], 'invalid_scale');
    }

    public function test_backlinks_bulk_ranks_live_validates_tag_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');
        $this->client->backlinksBulkRanksLive(['example.com'], tag: str_repeat('a', 256));
    }

    public function test_backlinks_bulk_ranks_live_with_parameters()
    {
        $id           = 'test-id';
        $fakeResponse = ['status_code' => 20000, 'tasks' => [['id' => $id, 'result' => []]]];
        Http::fake(['*' => Http::response($fakeResponse, 200)]);

        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $targets = ['example.com', 'https://test.com/page'];
        $result  = $this->client->backlinksBulkRanksLive(
            $targets,
            'one_hundred',
            'test-tag'
        );

        $responseData = $result['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);

        Http::assertSent(function ($request) {
            $taskData = $request->data()[0];
            $this->assertEquals(['example.com', 'https://test.com/page'], $taskData['targets']);
            $this->assertEquals('one_hundred', $taskData['rank_scale']);
            $this->assertEquals('test-tag', $taskData['tag']);

            return true;
        });

        $this->assertArrayHasKey('response', $result);
    }

    /**
     * Test backlinks bulk backlinks live successful request
     */
    public function test_backlinks_bulk_backlinks_live_successful_request()
    {
        $id           = 'test-id-' . uniqid();
        $fakeResponse = [
            'version'        => '0.1.20241101',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.5678 sec.',
            'cost'           => 0.025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.4567 sec.',
                    'cost'           => 0.025,
                    'result_count'   => 1,
                    'path'           => ['v3', 'backlinks', 'bulk_backlinks', 'live'],
                    'data'           => [
                        'api'      => 'backlinks',
                        'function' => 'bulk_backlinks',
                        'targets'  => ['example.com', 'test.com'],
                    ],
                    'result' => [[]],
                ],
            ],
        ];

        Http::fake([
            '*' => Http::response($fakeResponse, 200),
        ]);

        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $targets = ['example.com', 'test.com'];
        $result  = $this->client->backlinksBulkBacklinksLive($targets);

        $responseData = $result['response']->json();

        $this->assertEquals(20000, $responseData['status_code']);
        $this->assertEquals('Ok.', $responseData['status_message']);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);

        // Verify request structure
        Http::assertSent(function ($request) {
            $this->assertStringContainsString('backlinks/bulk_backlinks/live', $request->url());
            $this->assertEquals('POST', $request->method());

            $requestData = $request->data();
            $this->assertIsArray($requestData);
            $this->assertNotEmpty($requestData);

            $taskData = $requestData[0];
            $this->assertEquals(['example.com', 'test.com'], $taskData['targets']);

            return true;
        });

        // Verify response structure
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('request', $result);
        $this->assertArrayHasKey('params', $result);
    }

    public function test_backlinks_bulk_backlinks_live_validates_empty_targets()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Targets array cannot be empty');
        $this->client->backlinksBulkBacklinksLive([]);
    }

    public function test_backlinks_bulk_backlinks_live_validates_targets_limit()
    {
        $targets = array_fill(0, 1001, 'example.com');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of targets is 1000');
        $this->client->backlinksBulkBacklinksLive($targets);
    }

    public function test_backlinks_bulk_backlinks_live_validates_empty_target()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target cannot be empty');
        $this->client->backlinksBulkBacklinksLive(['example.com', '']);
    }

    public function test_backlinks_bulk_backlinks_live_validates_tag_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');
        $this->client->backlinksBulkBacklinksLive(['example.com'], str_repeat('a', 256));
    }

    public function test_backlinks_bulk_backlinks_live_with_parameters()
    {
        $id           = 'test-id';
        $fakeResponse = ['status_code' => 20000, 'tasks' => [['id' => $id, 'result' => []]]];
        Http::fake(['*' => Http::response($fakeResponse, 200)]);

        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $targets = ['example.com', 'https://test.com/page'];
        $result  = $this->client->backlinksBulkBacklinksLive($targets, 'test-tag');

        $responseData = $result['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);

        Http::assertSent(function ($request) {
            $taskData = $request->data()[0];
            $this->assertEquals(['example.com', 'https://test.com/page'], $taskData['targets']);
            $this->assertEquals('test-tag', $taskData['tag']);

            return true;
        });

        $this->assertArrayHasKey('response', $result);
    }

    /**
     * Test backlinks bulk spam score live successful request
     */
    public function test_backlinks_bulk_spam_score_live_successful_request()
    {
        $id           = 'test-id-' . uniqid();
        $fakeResponse = [
            'version'        => '0.1.20241101',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.5678 sec.',
            'cost'           => 0.025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.4567 sec.',
                    'cost'           => 0.025,
                    'result_count'   => 1,
                    'path'           => ['v3', 'backlinks', 'bulk_spam_score', 'live'],
                    'data'           => [
                        'api'      => 'backlinks',
                        'function' => 'bulk_spam_score',
                        'targets'  => ['example.com', 'test.com'],
                    ],
                    'result' => [[]],
                ],
            ],
        ];

        Http::fake([
            '*' => Http::response($fakeResponse, 200),
        ]);

        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $targets = ['example.com', 'test.com'];
        $result  = $this->client->backlinksBulkSpamScoreLive($targets);

        $responseData = $result['response']->json();

        $this->assertEquals(20000, $responseData['status_code']);
        $this->assertEquals('Ok.', $responseData['status_message']);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);

        // Verify request structure
        Http::assertSent(function ($request) {
            $this->assertStringContainsString('backlinks/bulk_spam_score/live', $request->url());
            $this->assertEquals('POST', $request->method());

            $requestData = $request->data();
            $this->assertIsArray($requestData);
            $this->assertNotEmpty($requestData);

            $taskData = $requestData[0];
            $this->assertEquals(['example.com', 'test.com'], $taskData['targets']);

            return true;
        });

        // Verify response structure
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('request', $result);
        $this->assertArrayHasKey('params', $result);
    }

    public function test_backlinks_bulk_spam_score_live_validates_empty_targets()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Targets array cannot be empty');
        $this->client->backlinksBulkSpamScoreLive([]);
    }

    public function test_backlinks_bulk_spam_score_live_validates_targets_limit()
    {
        $targets = array_fill(0, 1001, 'example.com');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of targets is 1000');
        $this->client->backlinksBulkSpamScoreLive($targets);
    }

    public function test_backlinks_bulk_spam_score_live_validates_empty_target()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target cannot be empty');
        $this->client->backlinksBulkSpamScoreLive(['example.com', '']);
    }

    public function test_backlinks_bulk_spam_score_live_validates_tag_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');
        $this->client->backlinksBulkSpamScoreLive(['example.com'], str_repeat('a', 256));
    }

    public function test_backlinks_bulk_spam_score_live_with_parameters()
    {
        $id           = 'test-id';
        $fakeResponse = ['status_code' => 20000, 'tasks' => [['id' => $id, 'result' => []]]];
        Http::fake(['*' => Http::response($fakeResponse, 200)]);

        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $targets = ['example.com', 'https://test.com/page'];
        $result  = $this->client->backlinksBulkSpamScoreLive($targets, 'test-tag');

        $responseData = $result['response']->json();
        $this->assertEquals($id, $responseData['tasks'][0]['id']);

        Http::assertSent(function ($request) {
            $taskData = $request->data()[0];
            $this->assertEquals(['example.com', 'https://test.com/page'], $taskData['targets']);
            $this->assertEquals('test-tag', $taskData['tag']);

            return true;
        });

        $this->assertArrayHasKey('response', $result);
    }

    /**
     * Test bulk methods handle target validation
     */
    public function test_bulk_methods_validate_target_format()
    {
        // Test invalid domain format with www
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target domain must be specified without www.');
        $this->client->backlinksBulkRanksLive(['example.com', 'www.invalid.com']);
    }

    public function test_bulk_methods_accept_mixed_targets()
    {
        $fakeResponse = ['status_code' => 20000, 'tasks' => [['id' => 'test-id', 'result' => []]]];
        Http::fake(['*' => Http::response($fakeResponse, 200)]);

        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $targets = [
            'example.com',
            'subdomain.test.com',
            'https://www.site.com/page',
            'http://another.com/path',
        ];

        // Test all three bulk methods accept mixed targets
        $this->client->backlinksBulkRanksLive($targets);
        $this->client->backlinksBulkBacklinksLive($targets);
        $this->client->backlinksBulkSpamScoreLive($targets);

        // Verify all requests were sent successfully
        Http::assertSentCount(3);
    }

    public function test_bulk_methods_with_additional_params()
    {
        $fakeResponse = ['status_code' => 20000, 'tasks' => [['id' => 'test-id', 'result' => []]]];
        Http::fake(['*' => Http::response($fakeResponse, 200)]);

        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $targets          = ['example.com', 'test.com'];
        $additionalParams = [
            'custom_param'  => 'custom_value',
            'another_param' => 123,
        ];

        $result = $this->client->backlinksBulkRanksLive(
            $targets,
            additionalParams: $additionalParams
        );

        Http::assertSent(function ($request) {
            $taskData = $request->data()[0];
            $this->assertEquals('custom_value', $taskData['custom_param']);
            $this->assertEquals(123, $taskData['another_param']);

            return true;
        });

        $this->assertArrayHasKey('response', $result);
    }
}

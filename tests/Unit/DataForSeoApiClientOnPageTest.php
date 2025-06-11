<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\DataForSeoApiClient;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;

class DataForSeoApiClientOnPageTest extends TestCase
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

    public function test_onpage_instant_pages_successful_request()
    {
        $id              = '12345678-1234-1234-1234-123456789014';
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.3497 sec.',
            'cost'           => 0.03,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.3397 sec.',
                    'cost'           => 0.03,
                    'result_count'   => 1,
                    'path'           => [
                        'on_page',
                        'instant_pages',
                    ],
                    'data' => [
                        'api'            => 'on_page',
                        'function'       => 'instant_pages',
                        'url'            => 'https://example.com',
                        'browser_preset' => 'desktop',
                    ],
                    'result' => [
                        [
                            'url'          => 'https://example.com',
                            'status_code'  => 200,
                            'page_content' => '<html><body>Example content</body></html>',
                            'page_metrics' => [
                                'word_count'         => 2,
                                'text_to_html_ratio' => 0.1,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/instant_pages" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPageInstantPages('https://example.com', null, 'desktop');

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/on_page/instant_pages" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['url']) &&
                   $request->data()[0]['url'] === 'https://example.com' &&
                   isset($request->data()[0]['browser_preset']) &&
                   $request->data()[0]['browser_preset'] === 'desktop';
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public static function onPageInstantPagesParametersProvider()
    {
        return [
            'basic request' => [
                [
                    'https://example.com',
                    null, // customUserAgent
                    null, // browserPreset
                    null, // browserScreenWidth
                    null, // browserScreenHeight
                    null, // browserScreenScaleFactor
                    null, // storeRawHtml
                    null, // acceptLanguage
                    null, // loadResources
                    null, // enableJavascript
                    null, // enableBrowserRendering
                    null, // disableCookiePopup
                    null, // returnDespiteTimeout
                    null, // enableXhr
                    null, // customJs
                    null, // validateMicromarkup
                    null, // checkSpell
                    null, // checksThreshold
                    null, // switchPool
                    null, // ipPoolForScan
                    [], // additionalParams
                    null, // attributes
                    1, // amount
                ],
                [
                    'url' => 'https://example.com',
                ],
            ],
            'with browser preset' => [
                [
                    'https://example.com',
                    null,
                    'mobile',
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    [],
                    null,
                    1,
                ],
                [
                    'url'            => 'https://example.com',
                    'browser_preset' => 'mobile',
                ],
            ],
            'with custom user agent' => [
                [
                    'https://example.com',
                    'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X)',
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    [],
                    null,
                    1,
                ],
                [
                    'url'               => 'https://example.com',
                    'custom_user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X)',
                ],
            ],
            'with browser dimensions' => [
                [
                    'https://example.com',
                    null,
                    null,
                    390,
                    844,
                    3.0,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    [],
                    null,
                    1,
                ],
                [
                    'url'                         => 'https://example.com',
                    'browser_screen_width'        => 390,
                    'browser_screen_height'       => 844,
                    'browser_screen_scale_factor' => 3.0,
                ],
            ],
            'with resource loading' => [
                [
                    'https://example.com',
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    true, // loadResources
                    true, // enableJavascript
                    true, // enableBrowserRendering
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    [],
                    null,
                    1,
                ],
                [
                    'url'                      => 'https://example.com',
                    'load_resources'           => true,
                    'enable_javascript'        => true,
                    'enable_browser_rendering' => true,
                ],
            ],
        ];
    }

    #[DataProvider('onPageInstantPagesParametersProvider')]
    public function test_onpage_instant_pages_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        Http::fake([
            "{$this->apiBaseUrl}/on_page/instant_pages" => Http::response(['status_code' => 20000], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $this->client->onPageInstantPages(...$parameters);

        Http::assertSent(function ($request) use ($expectedParams) {
            $requestData = $request->data()[0];

            foreach ($expectedParams as $key => $value) {
                if (!isset($requestData[$key]) || $requestData[$key] !== $value) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_onpage_task_post_successful_request()
    {
        $responseJson = json_encode([
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => 'test-onpage-task-12345',
                    'status_code'    => 20000,
                    'status_message' => 'Task Created',
                    'time'           => '0.0012 sec.',
                    'cost'           => 0.025,
                    'result_count'   => 0,
                    'path'           => ['v3', 'on_page', 'task_post'],
                    'data'           => [
                        'api'               => 'on_page',
                        'function'          => 'task_post',
                        'target'            => 'example.com',
                        'max_crawl_pages'   => 100,
                        'enable_javascript' => true,
                        'browser_preset'    => 'desktop',
                        'tag'               => 'test-audit-task',
                    ],
                    'result' => null,
                ],
            ],
        ]);

        Http::fake([
            'api.dataforseo.com/v3/on_page/task_post' => Http::response($responseJson, 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->onPageTaskPost(
            target: 'example.com',
            maxCrawlPages: 100,
            enableJavascript: true,
            browserPreset: 'desktop',
            tag: 'test-audit-task'
        );

        // Verify fake response was received by checking task ID
        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertCount(1, $responseData['tasks']);
        $this->assertEquals('test-onpage-task-12345', $responseData['tasks'][0]['id']);
        $this->assertEquals('example.com', $responseData['tasks'][0]['data']['target']);
        $this->assertEquals(100, $responseData['tasks'][0]['data']['max_crawl_pages']);
        $this->assertEquals(true, $responseData['tasks'][0]['data']['enable_javascript']);
        $this->assertEquals('desktop', $responseData['tasks'][0]['data']['browser_preset']);
        $this->assertEquals('test-audit-task', $responseData['tasks'][0]['data']['tag']);
    }

    public function test_onpage_task_post_validates_empty_target()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target domain cannot be empty');

        $this->client->onPageTaskPost(
            target: '',
            maxCrawlPages: 100
        );
    }

    public function test_onpage_task_post_validates_max_crawl_pages()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max_crawl_pages must be a positive integer');

        $this->client->onPageTaskPost(
            target: 'example.com',
            maxCrawlPages: 0
        );
    }

    public function test_onpage_task_post_validates_browser_preset()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('browser_preset must be one of: desktop, mobile, tablet');

        $this->client->onPageTaskPost(
            target: 'example.com',
            maxCrawlPages: 100,
            browserPreset: 'invalid'
        );
    }

    public function test_onpage_task_post_validates_browser_screen_dimensions()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('browser_screen_width must be between 240 and 9999 pixels');

        $this->client->onPageTaskPost(
            target: 'example.com',
            maxCrawlPages: 100,
            browserScreenWidth: 200
        );
    }

    public function test_onpage_task_post_validates_xhr_javascript_dependency()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('enable_javascript must be set to true when enable_xhr is true');

        $this->client->onPageTaskPost(
            target: 'example.com',
            maxCrawlPages: 100,
            enableXhr: true,
            enableJavascript: false
        );
    }

    public function test_onpage_task_post_validates_browser_rendering_dependencies()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('enable_javascript and load_resources must be set to true when enable_browser_rendering is true');

        $this->client->onPageTaskPost(
            target: 'example.com',
            maxCrawlPages: 100,
            enableBrowserRendering: true,
            enableJavascript: true,
            loadResources: false
        );
    }

    public function test_onpage_task_post_validates_custom_js_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('custom_js must be 2000 characters or less');

        $longJs = str_repeat('a', 2001);

        $this->client->onPageTaskPost(
            target: 'example.com',
            maxCrawlPages: 100,
            customJs: $longJs
        );
    }

    public function test_onpage_task_post_validates_tag_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');

        $longTag = str_repeat('a', 256);

        $this->client->onPageTaskPost(
            target: 'example.com',
            maxCrawlPages: 100,
            tag: $longTag
        );
    }

    public function test_onpage_task_post_validates_spell_check_language()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('check_spell_language must be a supported language code');

        $this->client->onPageTaskPost(
            target: 'example.com',
            maxCrawlPages: 100,
            checkSpellLanguage: 'invalid'
        );
    }

    public static function onPageTaskPostParametersProvider(): array
    {
        return [
            'minimal_parameters' => [
                ['target' => 'example.com', 'maxCrawlPages' => 50],
                ['target' => 'example.com', 'max_crawl_pages' => 50],
            ],
            'with_start_url' => [
                ['target' => 'example.com', 'maxCrawlPages' => 100, 'startUrl' => 'https://example.com/start'],
                ['target' => 'example.com', 'max_crawl_pages' => 100, 'start_url' => 'https://example.com/start'],
            ],
            'with_browser_settings' => [
                [
                    'target'           => 'test.com',
                    'maxCrawlPages'    => 75,
                    'browserPreset'    => 'mobile',
                    'enableJavascript' => true,
                    'loadResources'    => true,
                ],
                [
                    'target'            => 'test.com',
                    'max_crawl_pages'   => 75,
                    'browser_preset'    => 'mobile',
                    'enable_javascript' => true,
                    'load_resources'    => true,
                ],
            ],
            'with_seo_settings' => [
                [
                    'target'                  => 'seo-test.com',
                    'maxCrawlPages'           => 200,
                    'checkSpell'              => true,
                    'checkSpellLanguage'      => 'en',
                    'calculateKeywordDensity' => true,
                    'validateMicromarkup'     => true,
                ],
                [
                    'target'                    => 'seo-test.com',
                    'max_crawl_pages'           => 200,
                    'check_spell'               => true,
                    'check_spell_language'      => 'en',
                    'calculate_keyword_density' => true,
                    'validate_micromarkup'      => true,
                ],
            ],
        ];
    }

    #[DataProvider('onPageTaskPostParametersProvider')]
    public function test_onpage_task_post_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $responseJson = json_encode([
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => 'test-onpage-task-67890',
                    'status_code'    => 20000,
                    'status_message' => 'Task Created',
                    'time'           => '0.0012 sec.',
                    'cost'           => 0.025,
                    'result_count'   => 0,
                    'path'           => ['v3', 'on_page', 'task_post'],
                    'data'           => $expectedParams,
                    'result'         => null,
                ],
            ],
        ]);

        Http::fake([
            'api.dataforseo.com/v3/on_page/task_post' => Http::response($responseJson, 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->onPageTaskPost(...$parameters);

        // Verify fake response was received by checking task ID
        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals('test-onpage-task-67890', $responseData['tasks'][0]['id']);

        // Verify expected parameters were properly converted and sent
        foreach ($expectedParams as $key => $value) {
            $this->assertEquals($value, $responseData['tasks'][0]['data'][$key], "Parameter $key does not match expected value");
        }
    }

    public function test_onpage_task_post_handles_api_errors()
    {
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40501,
            'status_message' => 'Requested functionality is not available.',
            'time'           => '0.0012 sec.',
            'cost'           => 0,
            'tasks_count'    => 0,
            'tasks_error'    => 0,
            'tasks'          => [],
        ];

        Http::fake([
            'api.dataforseo.com/v3/on_page/task_post' => Http::response($errorResponse, 400, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->onPageTaskPost(
            target: 'example.com',
            maxCrawlPages: 100
        );

        // Make sure we used the Http::fake() response
        $this->assertEquals(400, $result['response']->status());
        $responseData = $result['response']->json();
        $this->assertEquals(40501, $responseData['status_code']);
        $this->assertEquals('Requested functionality is not available.', $responseData['status_message']);
    }

    public function test_onpage_summary_successful_request()
    {
        $responseJson = json_encode([
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1845 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => 'test-onpage-summary-12345',
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.1845 sec.',
                    'cost'           => 0,
                    'result_count'   => 1,
                    'path'           => ['v3', 'on_page', 'summary'],
                    'data'           => [
                        'api'      => 'on_page',
                        'function' => 'summary',
                        'se'       => 'dataforseo',
                    ],
                    'result' => [
                        [
                            'crawl_progress' => 'finished',
                            'crawl_status'   => [
                                'max_crawl_pages' => 100,
                                'pages_in_queue'  => 0,
                                'pages_crawled'   => 45,
                            ],
                            'total_pages'          => 45,
                            'pages_by_status_code' => [
                                '200' => 42,
                                '404' => 3,
                            ],
                            'checks_summary' => [
                                'page_checks'     => ['total' => 150, 'passed' => 125, 'failed' => 25],
                                'sitewide_checks' => ['total' => 10, 'passed' => 8, 'failed' => 2],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        Http::fake([
            'api.dataforseo.com/v3/on_page/summary/test-onpage-summary-12345' => Http::response($responseJson, 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->onPageSummary('test-onpage-summary-12345');

        // Verify fake response was received by checking task ID
        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertCount(1, $responseData['tasks']);
        $this->assertEquals('test-onpage-summary-12345', $responseData['tasks'][0]['id']);
        $this->assertEquals('finished', $responseData['tasks'][0]['result'][0]['crawl_progress']);
        $this->assertEquals(45, $responseData['tasks'][0]['result'][0]['total_pages']);
        $this->assertArrayHasKey('pages_by_status_code', $responseData['tasks'][0]['result'][0]);
        $this->assertArrayHasKey('checks_summary', $responseData['tasks'][0]['result'][0]);
    }

    public function test_onpage_summary_validates_empty_task_id()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task ID cannot be empty');

        $this->client->onPageSummary('');
    }

    public function test_onpage_summary_passes_attributes_and_amount()
    {
        $responseJson = json_encode([
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1845 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => 'test-custom-attributes-12345',
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.1845 sec.',
                    'cost'           => 0,
                    'result_count'   => 1,
                    'path'           => ['v3', 'on_page', 'summary'],
                    'data'           => [
                        'api'      => 'on_page',
                        'function' => 'summary',
                        'se'       => 'dataforseo',
                    ],
                    'result' => [
                        [
                            'crawl_progress' => 'in_progress',
                            'total_pages'    => 25,
                        ],
                    ],
                ],
            ],
        ]);

        Http::fake([
            'api.dataforseo.com/v3/on_page/summary/test-custom-attributes-12345' => Http::response($responseJson, 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->onPageSummary(
            id: 'test-custom-attributes-12345',
            attributes: 'custom-attributes',
            amount: 5
        );

        // Verify fake response was received by checking task ID
        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals('test-custom-attributes-12345', $responseData['tasks'][0]['id']);
        $this->assertEquals('in_progress', $responseData['tasks'][0]['result'][0]['crawl_progress']);
    }

    public function test_onpage_summary_handles_api_errors()
    {
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40401,
            'status_message' => 'Task not found.',
            'time'           => '0.0012 sec.',
            'cost'           => 0,
            'tasks_count'    => 0,
            'tasks_error'    => 0,
            'tasks'          => [],
        ];

        Http::fake([
            'api.dataforseo.com/v3/on_page/summary/invalid-task-id' => Http::response($errorResponse, 404, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->onPageSummary('invalid-task-id');

        // Make sure we used the Http::fake() response
        $this->assertEquals(404, $result['response']->status());
        $responseData = $result['response']->json();
        $this->assertEquals(40401, $responseData['status_code']);
        $this->assertEquals('Task not found.', $responseData['status_message']);
    }

    public function test_onpage_pages_post_successful_request()
    {
        $id              = '12345678-1234-1234-1234-123456789030';
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.02,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.02,
                    'result_count'   => 50,
                    'path'           => [
                        'on_page',
                        'pages',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'pages',
                        'id'       => $id,
                    ],
                    'result' => [
                        [
                            'crawl_progress' => 'finished',
                            'crawl_status'   => [
                                'max_crawl_pages' => 50,
                                'pages_in_queue'  => 0,
                                'pages_crawled'   => 50,
                            ],
                            'total_items_count' => 50,
                            'items_count'       => 50,
                            'items'             => [
                                [
                                    'page_timing' => [
                                        'time_to_interactive'       => 2500,
                                        'dom_complete'              => 1800,
                                        'largest_contentful_paint'  => 1200,
                                        'first_input_delay'         => 50,
                                        'cumulative_layout_shift'   => 0.1,
                                        'speed_index'               => 1500,
                                        'time_to_secure_connection' => 300,
                                        'time_to_first_byte'        => 200,
                                        'first_contentful_paint'    => 800,
                                        'first_meaningful_paint'    => 900,
                                        'connection_time'           => 100,
                                        'duration_time'             => 3000,
                                        'fetch_start'               => 0,
                                        'fetch_end'                 => 2800,
                                    ],
                                    'onpage_score'       => 85,
                                    'total_dom_size'     => 1500,
                                    'custom_js_response' => null,
                                    'resource_errors'    => null,
                                    'status_code'        => 200,
                                    'location'           => 'https://example.com/page1.html',
                                    'url'                => 'https://example.com/page1.html',
                                    'meta'               => [
                                        'title'       => 'Example Page 1',
                                        'charset'     => 'utf-8',
                                        'description' => 'This is an example page',
                                        'follow'      => true,
                                        'generator'   => null,
                                        'htags'       => [
                                            'h1' => ['Example Page 1'],
                                            'h2' => ['Section 1', 'Section 2'],
                                        ],
                                        'images'                => 3,
                                        'images_alt'            => 2,
                                        'images_without_alt'    => 1,
                                        'internal_links_count'  => 15,
                                        'external_links_count'  => 5,
                                        'inbound_links_count'   => 8,
                                        'canonical'             => 'https://example.com/page1.html',
                                        'duplicate_title'       => false,
                                        'duplicate_description' => false,
                                        'duplicate_content'     => false,
                                        'click_depth'           => 1,
                                    ],
                                    'page_metrics' => [
                                        'linkspower'                    => 45.2,
                                        'dofollow_links_count'          => 18,
                                        'nofollow_links_count'          => 2,
                                        'internal_dofollow_links_count' => 14,
                                        'internal_nofollow_links_count' => 1,
                                        'external_dofollow_links_count' => 4,
                                        'external_nofollow_links_count' => 1,
                                        'meta_keywords_count'           => 0,
                                        'meta_keywords'                 => null,
                                        'domain_inlink_rank'            => 92,
                                        'domain_rank'                   => 85,
                                        'page_rank'                     => 78,
                                    ],
                                    'checks' => [
                                        'no_content_issues'             => true,
                                        'high_loading_time'             => false,
                                        'is_redirect'                   => false,
                                        'is_4xx_code'                   => false,
                                        'is_5xx_code'                   => false,
                                        'is_broken'                     => false,
                                        'is_www'                        => false,
                                        'is_https'                      => true,
                                        'is_http'                       => false,
                                        'high_waiting_time'             => false,
                                        'no_doctype'                    => false,
                                        'canonical'                     => false,
                                        'no_encoding_meta_tag'          => false,
                                        'no_h1_tag'                     => false,
                                        'https_to_http_links'           => false,
                                        'size_greater_than_3mb'         => false,
                                        'meta_charset_consistency'      => true,
                                        'has_meta_refresh_redirect'     => false,
                                        'has_render_blocking_resources' => false,
                                        'redirect_chain'                => false,
                                        'recursive_canonical'           => false,
                                    ],
                                    'content_encoding' => 'gzip',
                                    'media_type'       => 'text/html',
                                    'server'           => 'nginx/1.18.0',
                                    'cache_control'    => [
                                        'cachable' => true,
                                        'ttl'      => 3600,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/pages" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPagePagesPost($id);

        Http::assertSent(function ($request) use ($id) {
            $this->assertEquals("{$this->apiBaseUrl}/on_page/pages", $request->url());
            $this->assertEquals('POST', $request->method());
            $this->assertArrayHasKey(0, $request->data());
            $this->assertEquals($id, $request->data()[0]['id']);

            return true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_onpage_pages_post_validates_empty_task_id()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task ID cannot be empty');

        $this->client->onPagePagesPost('');
    }

    public function test_onpage_pages_post_validates_limit_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be between 1 and 1000');

        $this->client->onPagePagesPost('valid-id', limit: 1001);
    }

    public function test_onpage_pages_post_validates_offset_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Offset must be greater than or equal to 0');

        $this->client->onPagePagesPost('valid-id', offset: -1);
    }

    public function test_onpage_pages_post_validates_filters_count()
    {
        $filters = array_fill(0, 9, ['status_code', '=', 200]); // 9 filters (exceeds max)

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum 8 filters are allowed');

        $this->client->onPagePagesPost('valid-id', filters: $filters);
    }

    public function test_onpage_pages_post_validates_order_by_count()
    {
        $orderBy = ['onpage_score,desc', 'status_code,asc', 'meta.title,desc', 'extra,asc']; // 4 rules (exceeds max)

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum 3 sorting rules are allowed');

        $this->client->onPagePagesPost('valid-id', orderBy: $orderBy);
    }

    public function test_onpage_pages_post_validates_tag_length()
    {
        $longTag = str_repeat('a', 256); // 256 characters (exceeds max)

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');

        $this->client->onPagePagesPost('valid-id', tag: $longTag);
    }

    public static function onPagePagesPostParametersProvider(): array
    {
        return [
            'with_limit_and_offset' => [
                ['12345678-1234-1234-1234-123456789031', 100, 20],
                ['id' => '12345678-1234-1234-1234-123456789031', 'limit' => 100, 'offset' => 20],
            ],
            'with_filters' => [
                ['12345678-1234-1234-1234-123456789032', null, null, [['status_code', '=', 200]]],
                ['id' => '12345678-1234-1234-1234-123456789032', 'filters' => [['status_code', '=', 200]]],
            ],
            'with_order_by' => [
                ['12345678-1234-1234-1234-123456789033', null, null, null, ['onpage_score,desc']],
                ['id' => '12345678-1234-1234-1234-123456789033', 'order_by' => ['onpage_score,desc']],
            ],
            'with_search_after_token' => [
                ['12345678-1234-1234-1234-123456789034', null, null, null, null, 'search_token_456'],
                ['id' => '12345678-1234-1234-1234-123456789034', 'search_after_token' => 'search_token_456'],
            ],
            'with_tag' => [
                ['12345678-1234-1234-1234-123456789035', null, null, null, null, null, 'pages-test-tag'],
                ['id' => '12345678-1234-1234-1234-123456789035', 'tag' => 'pages-test-tag'],
            ],
            'with_all_parameters' => [
                [
                    '12345678-1234-1234-1234-123456789036',
                    50,
                    10,
                    [['meta.title', 'like', '%example%']],
                    ['onpage_score,desc', 'status_code,asc'],
                    'search_token_789',
                    'full-pages-test-tag',
                ],
                [
                    'id'                 => '12345678-1234-1234-1234-123456789036',
                    'limit'              => 50,
                    'offset'             => 10,
                    'filters'            => [['meta.title', 'like', '%example%']],
                    'order_by'           => ['onpage_score,desc', 'status_code,asc'],
                    'search_after_token' => 'search_token_789',
                    'tag'                => 'full-pages-test-tag',
                ],
            ],
        ];
    }

    #[DataProvider('onPagePagesPostParametersProvider')]
    public function test_onpage_pages_post_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.1500 sec.',
            'cost'           => 0.02,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $parameters[0],
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.1400 sec.',
                    'cost'           => 0.02,
                    'result_count'   => 25,
                    'path'           => [
                        'on_page',
                        'pages',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'pages',
                        'id'       => $parameters[0],
                    ],
                    'result' => [
                        [
                            'crawl_progress'    => 'finished',
                            'total_items_count' => 25,
                            'items_count'       => 25,
                            'items'             => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/pages" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPagePagesPost(...$parameters);

        Http::assertSent(function ($request) use ($expectedParams) {
            $this->assertEquals("{$this->apiBaseUrl}/on_page/pages", $request->url());
            $this->assertEquals('POST', $request->method());

            $requestData = $request->data()[0];
            foreach ($expectedParams as $key => $value) {
                $this->assertArrayHasKey($key, $requestData);
                $this->assertEquals($value, $requestData[$key]);
            }

            return true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals($parameters[0], $responseData['tasks'][0]['id']);
    }

    public function test_onpage_pages_post_handles_api_errors()
    {
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40401,
            'status_message' => 'Task not found.',
            'time'           => '0.0012 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 1,
            'tasks'          => [
                [
                    'id'             => '12345678-1234-1234-1234-123456789037',
                    'status_code'    => 40401,
                    'status_message' => 'Task not found.',
                    'time'           => '0.0012 sec.',
                    'cost'           => 0,
                    'result_count'   => 0,
                    'path'           => [
                        'on_page',
                        'pages',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'pages',
                        'id'       => '12345678-1234-1234-1234-123456789037',
                    ],
                    'result' => null,
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/pages" => Http::response($errorResponse, 404),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->onPagePagesPost('12345678-1234-1234-1234-123456789037');

        // Make sure we used the Http::fake() response
        $this->assertEquals(404, $result['response']->status());
        $responseData = $result['response']->json();
        $this->assertEquals(40401, $responseData['status_code']);
        $this->assertEquals('Task not found.', $responseData['status_message']);
    }

    public function test_onpage_resources_post_successful_request()
    {
        $id              = '12345678-1234-1234-1234-123456789020';
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.025,
                    'result_count'   => 100,
                    'path'           => [
                        'on_page',
                        'resources',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'resources',
                        'id'       => $id,
                    ],
                    'result' => [
                        [
                            'resource_type'       => 'image',
                            'status_code'         => 200,
                            'location'            => 'https://example.com/image.jpg',
                            'url'                 => 'https://example.com/page.html',
                            'size'                => 15420,
                            'encoded_size'        => 15420,
                            'total_transfer_size' => 15644,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/resources" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPageResourcesPost($id);

        Http::assertSent(function ($request) use ($id) {
            $this->assertEquals("{$this->apiBaseUrl}/on_page/resources", $request->url());
            $this->assertEquals('POST', $request->method());
            $this->assertArrayHasKey(0, $request->data());
            $this->assertEquals($id, $request->data()[0]['id']);

            return true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_onpage_resources_post_validates_empty_task_id()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task ID cannot be empty');

        $this->client->onPageResourcesPost('');
    }

    public function test_onpage_resources_post_validates_limit_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be between 1 and 1000');

        $this->client->onPageResourcesPost('valid-id', limit: 1001);
    }

    public function test_onpage_resources_post_validates_offset_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Offset must be greater than or equal to 0');

        $this->client->onPageResourcesPost('valid-id', offset: -1);
    }

    public function test_onpage_resources_post_validates_filters_count()
    {
        $filters = array_fill(0, 9, ['resource_type', '=', 'image']); // 9 filters (exceeds max)

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum 8 filters are allowed');

        $this->client->onPageResourcesPost('valid-id', filters: $filters);
    }

    public function test_onpage_resources_post_validates_relevant_pages_filters_count()
    {
        $filters = array_fill(0, 9, ['status_code', '=', 200]); // 9 filters (exceeds max)

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum 8 relevant pages filters are allowed');

        $this->client->onPageResourcesPost('valid-id', relevantPagesFilters: $filters);
    }

    public function test_onpage_resources_post_validates_order_by_count()
    {
        $orderBy = ['size,desc', 'status_code,asc', 'resource_type,desc', 'extra,asc']; // 4 rules (exceeds max)

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum 3 sorting rules are allowed');

        $this->client->onPageResourcesPost('valid-id', orderBy: $orderBy);
    }

    public function test_onpage_resources_post_validates_tag_length()
    {
        $longTag = str_repeat('a', 256); // 256 characters (exceeds max)

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');

        $this->client->onPageResourcesPost('valid-id', tag: $longTag);
    }

    public static function onPageResourcesPostParametersProvider(): array
    {
        return [
            'with_url_parameter' => [
                ['12345678-1234-1234-1234-123456789021', 'https://example.com/page.html'],
                ['id' => '12345678-1234-1234-1234-123456789021', 'url' => 'https://example.com/page.html'],
            ],
            'with_limit_and_offset' => [
                ['12345678-1234-1234-1234-123456789022', null, 50, 10],
                ['id' => '12345678-1234-1234-1234-123456789022', 'limit' => 50, 'offset' => 10],
            ],
            'with_filters' => [
                ['12345678-1234-1234-1234-123456789023', null, null, null, [['resource_type', '=', 'stylesheet']]],
                ['id' => '12345678-1234-1234-1234-123456789023', 'filters' => [['resource_type', '=', 'stylesheet']]],
            ],
            'with_relevant_pages_filters' => [
                ['12345678-1234-1234-1234-123456789024', null, null, null, null, [['status_code', '=', 200]]],
                ['id' => '12345678-1234-1234-1234-123456789024', 'relevant_pages_filters' => [['status_code', '=', 200]]],
            ],
            'with_order_by' => [
                ['12345678-1234-1234-1234-123456789025', null, null, null, null, null, ['size,desc']],
                ['id' => '12345678-1234-1234-1234-123456789025', 'order_by' => ['size,desc']],
            ],
            'with_search_after_token' => [
                ['12345678-1234-1234-1234-123456789026', null, null, null, null, null, null, 'search_token_123'],
                ['id' => '12345678-1234-1234-1234-123456789026', 'search_after_token' => 'search_token_123'],
            ],
            'with_tag' => [
                ['12345678-1234-1234-1234-123456789027', null, null, null, null, null, null, null, 'test-tag'],
                ['id' => '12345678-1234-1234-1234-123456789027', 'tag' => 'test-tag'],
            ],
            'with_all_parameters' => [
                [
                    '12345678-1234-1234-1234-123456789028',
                    'https://example.com/page.html',
                    100,
                    20,
                    [['resource_type', '=', 'image']],
                    [['status_code', '=', 200]],
                    ['size,desc', 'status_code,asc'],
                    'search_token_456',
                    'full-test-tag',
                ],
                [
                    'id'                     => '12345678-1234-1234-1234-123456789028',
                    'url'                    => 'https://example.com/page.html',
                    'limit'                  => 100,
                    'offset'                 => 20,
                    'filters'                => [['resource_type', '=', 'image']],
                    'relevant_pages_filters' => [['status_code', '=', 200]],
                    'order_by'               => ['size,desc', 'status_code,asc'],
                    'search_after_token'     => 'search_token_456',
                    'tag'                    => 'full-test-tag',
                ],
            ],
        ];
    }

    #[DataProvider('onPageResourcesPostParametersProvider')]
    public function test_onpage_resources_post_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => 'test-onpage-resources-67890',
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.025,
                    'result_count'   => 50,
                    'path'           => [
                        'on_page',
                        'resources',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'resources',
                    ],
                    'result' => [],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/resources" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->onPageResourcesPost(...$parameters);

        Http::assertSent(function ($request) use ($expectedParams) {
            $this->assertEquals("{$this->apiBaseUrl}/on_page/resources", $request->url());
            $this->assertEquals('POST', $request->method());
            $this->assertArrayHasKey(0, $request->data());

            foreach ($expectedParams as $key => $value) {
                $this->assertArrayHasKey($key, $request->data()[0]);
                $this->assertEquals($value, $request->data()[0][$key]);
            }

            return true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $result['response_status_code']);
        $responseData = $result['response']->json();
        $this->assertEquals('test-onpage-resources-67890', $responseData['tasks'][0]['id']);
    }

    public function test_onpage_resources_post_handles_api_errors()
    {
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40000,
            'status_message' => 'Bad Request.',
            'time'           => '0.0497 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 1,
            'tasks'          => [
                [
                    'id'             => '12345678-1234-1234-1234-123456789029',
                    'status_code'    => 40000,
                    'status_message' => 'Bad Request.',
                    'time'           => '0.0397 sec.',
                    'cost'           => 0,
                    'result_count'   => 0,
                    'path'           => [
                        'on_page',
                        'resources',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'resources',
                        'id'       => 'invalid-id',
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/resources" => Http::response($errorResponse, 400),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPageResourcesPost('invalid-id');

        $this->assertEquals(400, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals(40000, $responseData['status_code']);
        $this->assertEquals('Bad Request.', $responseData['status_message']);
    }

    public function test_onpage_waterfall_post_successful_request()
    {
        $id              = '12345678-1234-1234-1234-123456789040';
        $url             = 'https://example.com/test-page.html';
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.3497 sec.',
            'cost'           => 0.01,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.3397 sec.',
                    'cost'           => 0.01,
                    'result_count'   => 1,
                    'path'           => [
                        'on_page',
                        'waterfall',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'waterfall',
                        'id'       => $id,
                        'url'      => $url,
                    ],
                    'result' => [
                        [
                            'url'     => $url,
                            'entries' => [
                                [
                                    'name'    => 'https://example.com/test-page.html',
                                    'request' => [
                                        'method'  => 'GET',
                                        'url'     => 'https://example.com/test-page.html',
                                        'headers' => [
                                            'User-Agent' => 'Mozilla/5.0...',
                                            'Accept'     => 'text/html,application/xhtml+xml...',
                                        ],
                                        'body_size' => 0,
                                    ],
                                    'response' => [
                                        'status'      => 200,
                                        'status_text' => 'OK',
                                        'headers'     => [
                                            'Content-Type'   => 'text/html; charset=utf-8',
                                            'Content-Length' => '15420',
                                            'Server'         => 'nginx/1.18.0',
                                        ],
                                        'body_size' => 15420,
                                        'content'   => [
                                            'size'      => 15420,
                                            'mime_type' => 'text/html',
                                            'encoding'  => 'gzip',
                                        ],
                                    ],
                                    'timings' => [
                                        'blocked' => 0.5,
                                        'dns'     => 2.1,
                                        'connect' => 8.4,
                                        'send'    => 0.2,
                                        'wait'    => 157.3,
                                        'receive' => 12.8,
                                        'ssl'     => 5.2,
                                        'total'   => 186.5,
                                    ],
                                    'started_date_time' => '2023-08-07T10:30:15.123Z',
                                    'time'              => 186.5,
                                    'cache'             => [
                                        'before_request' => [
                                            'last_access' => null,
                                            'e_tag'       => null,
                                            'hit_count'   => 0,
                                        ],
                                        'after_request' => [
                                            'last_access' => '2023-08-07T10:30:15.309Z',
                                            'e_tag'       => 'W/"3c2c-188f5e5e5e5"',
                                            'hit_count'   => 0,
                                        ],
                                    ],
                                    'server_ip_address' => '93.184.216.34',
                                    'connection'        => '12345',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/waterfall" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPageWaterfallPost($id, $url);

        Http::assertSent(function ($request) use ($id, $url) {
            $this->assertEquals("{$this->apiBaseUrl}/on_page/waterfall", $request->url());
            $this->assertEquals('POST', $request->method());
            $this->assertArrayHasKey(0, $request->data());
            $requestData = $request->data()[0];
            $this->assertEquals($id, $requestData['id']);
            $this->assertEquals($url, $requestData['url']);

            return true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
        $this->assertEquals($url, $responseData['tasks'][0]['data']['url']);
    }

    public function test_onpage_waterfall_post_validates_empty_task_id()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task ID cannot be empty');

        $this->client->onPageWaterfallPost('', 'https://example.com');
    }

    public function test_onpage_waterfall_post_validates_empty_url()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('URL cannot be empty');

        $this->client->onPageWaterfallPost('12345678-1234-1234-1234-123456789040', '');
    }

    public function test_onpage_waterfall_post_validates_tag_length()
    {
        $longTag = str_repeat('a', 256); // 256 characters (exceeds max)

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');

        $this->client->onPageWaterfallPost('12345678-1234-1234-1234-123456789040', 'https://example.com', $longTag);
    }

    public static function onPageWaterfallPostParametersProvider(): array
    {
        return [
            'with_basic_parameters' => [
                ['12345678-1234-1234-1234-123456789041', 'https://example.com/basic.html'],
                ['id' => '12345678-1234-1234-1234-123456789041', 'url' => 'https://example.com/basic.html'],
            ],
            'with_tag' => [
                ['12345678-1234-1234-1234-123456789042', 'https://example.com/tagged.html', 'waterfall-test-tag'],
                ['id' => '12345678-1234-1234-1234-123456789042', 'url' => 'https://example.com/tagged.html', 'tag' => 'waterfall-test-tag'],
            ],
            'with_complex_url' => [
                ['12345678-1234-1234-1234-123456789043', 'https://example.com/complex/path?param=value&other=123'],
                ['id' => '12345678-1234-1234-1234-123456789043', 'url' => 'https://example.com/complex/path?param=value&other=123'],
            ],
        ];
    }

    #[DataProvider('onPageWaterfallPostParametersProvider')]
    public function test_onpage_waterfall_post_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2500 sec.',
            'cost'           => 0.01,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $parameters[0],
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2400 sec.',
                    'cost'           => 0.01,
                    'result_count'   => 1,
                    'path'           => [
                        'on_page',
                        'waterfall',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'waterfall',
                        'id'       => $parameters[0],
                        'url'      => $parameters[1],
                    ],
                    'result' => [
                        [
                            'url'     => $parameters[1],
                            'entries' => [],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/waterfall" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPageWaterfallPost(...$parameters);

        Http::assertSent(function ($request) use ($expectedParams) {
            $this->assertEquals("{$this->apiBaseUrl}/on_page/waterfall", $request->url());
            $this->assertEquals('POST', $request->method());

            $requestData = $request->data()[0];
            foreach ($expectedParams as $key => $value) {
                $this->assertArrayHasKey($key, $requestData);
                $this->assertEquals($value, $requestData[$key]);
            }

            return true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals($parameters[0], $responseData['tasks'][0]['id']);
        $this->assertEquals($parameters[1], $responseData['tasks'][0]['data']['url']);
    }

    public function test_onpage_waterfall_post_handles_api_errors()
    {
        $id            = '12345678-1234-1234-1234-123456789044';
        $url           = 'https://example.com/invalid-page.html';
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40401,
            'status_message' => 'Task not found.',
            'time'           => '0.0012 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 1,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 40401,
                    'status_message' => 'Task not found.',
                    'time'           => '0.0012 sec.',
                    'cost'           => 0,
                    'result_count'   => 0,
                    'path'           => [
                        'on_page',
                        'waterfall',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'waterfall',
                        'id'       => $id,
                        'url'      => $url,
                    ],
                    'result' => null,
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/waterfall" => Http::response($errorResponse, 404),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->onPageWaterfallPost($id, $url);

        // Make sure we used the Http::fake() response
        $this->assertEquals(404, $result['response']->status());
        $responseData = $result['response']->json();
        $this->assertEquals(40401, $responseData['status_code']);
        $this->assertEquals('Task not found.', $responseData['status_message']);
    }

    public function test_onpage_keyword_density_post_successful_request()
    {
        $id              = '12345678-1234-1234-1234-123456789020';
        $keywordLength   = 2;
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.025,
                    'result_count'   => 1,
                    'path'           => [
                        'on_page',
                        'keyword_density',
                    ],
                    'data' => [
                        'api'            => 'on_page',
                        'function'       => 'keyword_density',
                        'id'             => $id,
                        'keyword_length' => $keywordLength,
                    ],
                    'result' => [
                        [
                            'id'          => $id,
                            'total_count' => 150,
                            'items_count' => 20,
                            'items'       => [
                                [
                                    'keyword'   => 'seo tools',
                                    'frequency' => 12,
                                    'density'   => 0.08,
                                ],
                                [
                                    'keyword'   => 'data analysis',
                                    'frequency' => 8,
                                    'density'   => 0.05,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/keyword_density" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPageKeywordDensityPost($id, $keywordLength);

        Http::assertSent(function ($request) use ($id, $keywordLength) {
            return $request->url() === "{$this->apiBaseUrl}/on_page/keyword_density" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['id']) &&
                   $request->data()[0]['id'] === $id &&
                   isset($request->data()[0]['keyword_length']) &&
                   $request->data()[0]['keyword_length'] === $keywordLength;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_onpage_keyword_density_post_validates_empty_task_id()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task ID cannot be empty');

        $this->client->onPageKeywordDensityPost('', 2);
    }

    public function test_onpage_keyword_density_post_validates_keyword_length()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keyword length must be 1, 2, 3, 4, or 5');

        $this->client->onPageKeywordDensityPost('12345678-1234-1234-1234-123456789021', 6);
    }

    public function test_onpage_keyword_density_post_validates_limit_parameter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be between 1 and 1000');

        $this->client->onPageKeywordDensityPost('12345678-1234-1234-1234-123456789022', 2, null, 1500);
    }

    public function test_onpage_keyword_density_post_validates_filters_count()
    {
        $tooManyFilters = array_fill(0, 9, ['keyword', '=', 'test']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum 8 filters are allowed');

        $this->client->onPageKeywordDensityPost('12345678-1234-1234-1234-123456789023', 2, null, null, $tooManyFilters);
    }

    public function test_onpage_keyword_density_post_validates_order_by_count()
    {
        $tooManyOrderBy = ['keyword,asc', 'frequency,desc', 'density,asc', 'extra,desc'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum 3 sorting rules are allowed');

        $this->client->onPageKeywordDensityPost('12345678-1234-1234-1234-123456789024', 2, null, null, null, $tooManyOrderBy);
    }

    public function test_onpage_keyword_density_post_validates_tag_length()
    {
        $longTag = str_repeat('a', 256);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag must be 255 characters or less');

        $this->client->onPageKeywordDensityPost('12345678-1234-1234-1234-123456789025', 2, null, null, null, null, $longTag);
    }

    public static function onPageKeywordDensityPostParametersProvider(): array
    {
        return [
            'basic_parameters' => [
                [
                    '12345678-1234-1234-1234-123456789026',
                    3,
                    'https://example.com/page',
                    50,
                    [['keyword', 'like', '%test%']],
                    ['frequency,desc'],
                    'test-tag',
                ],
                [
                    'id'             => '12345678-1234-1234-1234-123456789026',
                    'keyword_length' => 3,
                    'url'            => 'https://example.com/page',
                    'limit'          => 50,
                    'filters'        => [['keyword', 'like', '%test%']],
                    'order_by'       => ['frequency,desc'],
                    'tag'            => 'test-tag',
                ],
            ],
            'minimal_parameters' => [
                [
                    '12345678-1234-1234-1234-123456789027',
                    1,
                ],
                [
                    'id'             => '12345678-1234-1234-1234-123456789027',
                    'keyword_length' => 1,
                ],
            ],
        ];
    }

    #[DataProvider('onPageKeywordDensityPostParametersProvider')]
    public function test_onpage_keyword_density_post_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.025,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $parameters[0],
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.025,
                    'result_count'   => 1,
                    'path'           => [
                        'on_page',
                        'keyword_density',
                    ],
                    'data'   => $expectedParams,
                    'result' => [
                        [
                            'id'          => $parameters[0],
                            'total_count' => 100,
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/keyword_density" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        // Call with variable number of parameters
        $response = $this->client->onPageKeywordDensityPost(...$parameters);

        // Verify request was made correctly
        Http::assertSent(function ($request) use ($expectedParams) {
            $data = $request->data()[0];
            foreach ($expectedParams as $key => $value) {
                if (!isset($data[$key]) || $data[$key] !== $value) {
                    return false;
                }
            }

            return true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals($parameters[0], $responseData['tasks'][0]['id']);
    }

    public function test_onpage_keyword_density_post_handles_api_errors()
    {
        $id            = '12345678-1234-1234-1234-123456789028';
        $keywordLength = 2;
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40401,
            'status_message' => 'Task not found.',
            'time'           => '0.0012 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 1,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 40401,
                    'status_message' => 'Task not found.',
                    'time'           => '0.0012 sec.',
                    'cost'           => 0,
                    'result_count'   => 0,
                    'path'           => [
                        'on_page',
                        'keyword_density',
                    ],
                    'data' => [
                        'api'            => 'on_page',
                        'function'       => 'keyword_density',
                        'id'             => $id,
                        'keyword_length' => $keywordLength,
                    ],
                    'result' => null,
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/keyword_density" => Http::response($errorResponse, 404),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->onPageKeywordDensityPost($id, $keywordLength);

        // Make sure we used the Http::fake() response
        $this->assertEquals(404, $result['response']->status());
        $responseData = $result['response']->json();
        $this->assertEquals(40401, $responseData['status_code']);
        $this->assertEquals('Task not found.', $responseData['status_message']);
    }

    public function test_onpage_raw_html_post_successful_request()
    {
        $id              = '12345678-1234-1234-1234-123456789015';
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.015,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.015,
                    'result_count'   => 1,
                    'path'           => [
                        'on_page',
                        'raw_html',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'raw_html',
                        'id'       => $id,
                    ],
                    'result' => [
                        [
                            'id'   => $id,
                            'url'  => 'https://example.com',
                            'html' => '<html><body>Example content</body></html>',
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/raw_html" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPageRawHtmlPost($id);

        Http::assertSent(function ($request) use ($id) {
            return $request->url() === "{$this->apiBaseUrl}/on_page/raw_html" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['id']) &&
                   $request->data()[0]['id'] === $id;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_onpage_raw_html_post_with_url_parameter_successful_request()
    {
        $id              = '12345678-1234-1234-1234-123456789016';
        $url             = 'https://example.com';
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.015,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.015,
                    'result_count'   => 1,
                    'path'           => [
                        'on_page',
                        'raw_html',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'raw_html',
                        'id'       => $id,
                        'url'      => $url,
                    ],
                    'result' => [
                        [
                            'id'   => $id,
                            'url'  => $url,
                            'html' => '<html><body>Example content</body></html>',
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/raw_html" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPageRawHtmlPost($id, $url);

        Http::assertSent(function ($request) use ($id, $url) {
            return $request->url() === "{$this->apiBaseUrl}/on_page/raw_html" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['id']) &&
                   $request->data()[0]['id'] === $id &&
                   isset($request->data()[0]['url']) &&
                   $request->data()[0]['url'] === $url;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_onpage_raw_html_post_handles_api_errors()
    {
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40000,
            'status_message' => 'Bad Request.',
            'time'           => '0.0497 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 1,
            'tasks'          => [
                [
                    'id'             => '12345678-1234-1234-1234-123456789017',
                    'status_code'    => 40000,
                    'status_message' => 'Bad Request.',
                    'time'           => '0.0397 sec.',
                    'cost'           => 0,
                    'result_count'   => 0,
                    'path'           => [
                        'on_page',
                        'raw_html',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'raw_html',
                        'id'       => 'invalid-id',
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/raw_html" => Http::response($errorResponse, 400),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPageRawHtmlPost('invalid-id');

        $this->assertEquals(400, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals(40000, $responseData['status_code']);
        $this->assertEquals('Bad Request.', $responseData['status_message']);
    }

    public function test_onpage_content_parsing_post_successful_request()
    {
        $url             = 'https://example.com/article';
        $id              = '12345678-1234-1234-1234-123456789030';
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.02,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.02,
                    'result_count'   => 1,
                    'path'           => [
                        'on_page',
                        'content_parsing',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'content_parsing',
                        'url'      => $url,
                        'id'       => $id,
                    ],
                    'result' => [
                        [
                            'id'               => $id,
                            'url'              => $url,
                            'content'          => 'This is the parsed content of the page',
                            'page_as_markdown' => '# Title\n\nThis is the parsed content of the page',
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/content_parsing" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPageContentParsingPost($url, $id);

        Http::assertSent(function ($request) use ($url, $id) {
            return $request->url() === "{$this->apiBaseUrl}/on_page/content_parsing" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['url']) &&
                   $request->data()[0]['url'] === $url &&
                   isset($request->data()[0]['id']) &&
                   $request->data()[0]['id'] === $id;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_onpage_content_parsing_post_with_markdown_view()
    {
        $url             = 'https://example.com/article-markdown';
        $id              = '12345678-1234-1234-1234-123456789031';
        $markdownView    = true;
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.02,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.02,
                    'result_count'   => 1,
                    'path'           => [
                        'on_page',
                        'content_parsing',
                    ],
                    'data' => [
                        'api'           => 'on_page',
                        'function'      => 'content_parsing',
                        'url'           => $url,
                        'id'            => $id,
                        'markdown_view' => $markdownView,
                    ],
                    'result' => [
                        [
                            'id'               => $id,
                            'url'              => $url,
                            'content'          => 'This is the parsed content of the page',
                            'page_as_markdown' => '# Title\n\nThis is the parsed content of the page',
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/content_parsing" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->onPageContentParsingPost($url, $id, $markdownView);

        Http::assertSent(function ($request) use ($url, $id, $markdownView) {
            return $request->url() === "{$this->apiBaseUrl}/on_page/content_parsing" &&
                   $request->method() === 'POST' &&
                   isset($request->data()[0]['url']) &&
                   $request->data()[0]['url'] === $url &&
                   isset($request->data()[0]['id']) &&
                   $request->data()[0]['id'] === $id &&
                   isset($request->data()[0]['markdown_view']) &&
                   $request->data()[0]['markdown_view'] === $markdownView;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertArrayHasKey('tasks', $responseData);
        $this->assertEquals($id, $responseData['tasks'][0]['id']);
    }

    public function test_onpage_content_parsing_post_validates_empty_url()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('URL cannot be empty');

        $this->client->onPageContentParsingPost('', '12345678-1234-1234-1234-123456789032');
    }

    public function test_onpage_content_parsing_post_validates_empty_task_id()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task ID cannot be empty');

        $this->client->onPageContentParsingPost('https://example.com', '');
    }

    public static function onPageContentParsingPostParametersProvider(): array
    {
        return [
            'basic_parameters' => [
                [
                    'https://example.com/content',
                    '12345678-1234-1234-1234-123456789033',
                    true,
                ],
                [
                    'url'           => 'https://example.com/content',
                    'id'            => '12345678-1234-1234-1234-123456789033',
                    'markdown_view' => true,
                ],
            ],
            'minimal_parameters' => [
                [
                    'https://example.com/minimal',
                    '12345678-1234-1234-1234-123456789034',
                ],
                [
                    'url' => 'https://example.com/minimal',
                    'id'  => '12345678-1234-1234-1234-123456789034',
                ],
            ],
        ];
    }

    #[DataProvider('onPageContentParsingPostParametersProvider')]
    public function test_onpage_content_parsing_post_builds_request_with_correct_parameters($parameters, $expectedParams)
    {
        $successResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 20000,
            'status_message' => 'Ok.',
            'time'           => '0.2497 sec.',
            'cost'           => 0.02,
            'tasks_count'    => 1,
            'tasks_error'    => 0,
            'tasks'          => [
                [
                    'id'             => $parameters[1],
                    'status_code'    => 20000,
                    'status_message' => 'Ok.',
                    'time'           => '0.2397 sec.',
                    'cost'           => 0.02,
                    'result_count'   => 1,
                    'path'           => [
                        'on_page',
                        'content_parsing',
                    ],
                    'data'   => $expectedParams,
                    'result' => [
                        [
                            'id'      => $parameters[1],
                            'url'     => $parameters[0],
                            'content' => 'Sample content',
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/content_parsing" => Http::response($successResponse, 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        // Call with variable number of parameters
        $response = $this->client->onPageContentParsingPost(...$parameters);

        // Verify request was made correctly
        Http::assertSent(function ($request) use ($expectedParams) {
            $data = $request->data()[0];
            foreach ($expectedParams as $key => $value) {
                if (!isset($data[$key]) || $data[$key] !== $value) {
                    return false;
                }
            }

            return true;
        });

        // Make sure we used the Http::fake() response
        $this->assertEquals(200, $response['response_status_code']);
        $responseData = $response['response']->json();
        $this->assertEquals($parameters[1], $responseData['tasks'][0]['id']);
    }

    public function test_onpage_content_parsing_post_handles_api_errors()
    {
        $url           = 'https://example.com/invalid';
        $id            = '12345678-1234-1234-1234-123456789035';
        $errorResponse = [
            'version'        => '0.1.20230807',
            'status_code'    => 40404,
            'status_message' => 'Content not found.',
            'time'           => '0.0012 sec.',
            'cost'           => 0,
            'tasks_count'    => 1,
            'tasks_error'    => 1,
            'tasks'          => [
                [
                    'id'             => $id,
                    'status_code'    => 40404,
                    'status_message' => 'Content not found.',
                    'time'           => '0.0012 sec.',
                    'cost'           => 0,
                    'result_count'   => 0,
                    'path'           => [
                        'on_page',
                        'content_parsing',
                    ],
                    'data' => [
                        'api'      => 'on_page',
                        'function' => 'content_parsing',
                        'url'      => $url,
                        'id'       => $id,
                    ],
                    'result' => null,
                ],
            ],
        ];

        Http::fake([
            "{$this->apiBaseUrl}/on_page/content_parsing" => Http::response($errorResponse, 404),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new DataForSeoApiClient();
        $this->client->clearRateLimit();

        $result = $this->client->onPageContentParsingPost($url, $id);

        // Make sure we used the Http::fake() response
        $this->assertEquals(404, $result['response']->status());
        $responseData = $result['response']->json();
        $this->assertEquals(40404, $responseData['status_code']);
        $this->assertEquals('Content not found.', $responseData['status_message']);
    }
}

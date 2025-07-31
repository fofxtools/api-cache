<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\ApiCacheManager;
use FOfX\ApiCache\ApiCacheServiceProvider;
use FOfX\ApiCache\Tests\TestCase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use FOfX\Helper;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

use function FOfX\ApiCache\check_server_status;
use function FOfX\ApiCache\resolve_cache_manager;
use function FOfX\ApiCache\create_responses_table;
use function FOfX\ApiCache\format_api_response;
use function FOfX\ApiCache\get_tables;
use function FOfX\ApiCache\create_pixabay_images_table;
use function FOfX\ApiCache\normalize_params;
use function FOfX\ApiCache\summarize_params;
use function FOfX\ApiCache\download_public_suffix_list;
use function FOfX\ApiCache\extract_registrable_domain;
use function FOfX\ApiCache\create_errors_table;
use function FOfX\ApiCache\create_dataforseo_serp_google_organic_listings_table;
use function FOfX\ApiCache\create_dataforseo_serp_google_organic_items_table;
use function FOfX\ApiCache\create_dataforseo_serp_google_organic_paa_items_table;
use function FOfX\ApiCache\create_dataforseo_serp_google_autocomplete_items_table;
use function FOfX\ApiCache\create_dataforseo_keywords_data_google_ads_items_table;
use function FOfX\ApiCache\create_dataforseo_backlinks_bulk_items_table;
use function FOfX\ApiCache\create_dataforseo_merchant_amazon_products_listings_table;
use function FOfX\ApiCache\create_dataforseo_merchant_amazon_products_items_table;
use function FOfX\ApiCache\create_dataforseo_merchant_amazon_asins_table;
use function FOfX\ApiCache\create_dataforseo_labs_google_keyword_research_items_table;

class FunctionsTest extends TestCase
{
    /** @var ApiCacheManager&Mockery\MockInterface */
    protected ApiCacheManager $cacheManager;

    protected string $testResponsesTable = 'api_cache_responses_test';
    protected string $testErrorsTable    = 'api_cache_errors_test';
    protected string $testImagesTable    = 'pixabay_images_test';

    protected string $testGoogleOrganicListingsTable  = 'dataforseo_serp_google_organic_listings_test';
    protected string $testGoogleOrganicTable          = 'dataforseo_serp_google_organic_items_test';
    protected string $testGoogleOrganicPaaTable       = 'dataforseo_serp_google_organic_paa_items_test';
    protected string $testGoogleAutocompleteTable     = 'dataforseo_serp_google_autocomplete_items_test';
    protected string $testGoogleAdsTable              = 'dataforseo_keywords_data_google_ads_items_test';
    protected string $testBacklinksBulkTable          = 'dataforseo_backlinks_bulk_items_test';
    protected string $testAmazonProductsListingsTable = 'dataforseo_merchant_amazon_products_listings_test';
    protected string $testAmazonProductsItemsTable    = 'dataforseo_merchant_amazon_products_items_test';
    protected string $testAmazonAsinsTable            = 'dataforseo_merchant_amazon_asins_test';
    protected string $testKeywordResearchTable        = 'dataforseo_labs_google_keyword_research_items_test';

    protected string $clientName = 'demo';
    protected string $apiBaseUrl;
    protected array $mockApiResponse;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        // Set up cache manager mock
        $this->cacheManager = Mockery::mock(ApiCacheManager::class);

        // Append unique ID to test table names
        $this->testResponsesTable .= '_' . uniqid();
        $this->testErrorsTable .= '_' . uniqid();
        $this->testImagesTable .= '_' . uniqid();

        $this->testGoogleOrganicListingsTable .= '_' . uniqid();
        $this->testGoogleOrganicTable .= '_' . uniqid();
        $this->testGoogleOrganicPaaTable .= '_' . uniqid();
        $this->testGoogleAutocompleteTable .= '_' . uniqid();
        $this->testGoogleAdsTable .= '_' . uniqid();
        $this->testBacklinksBulkTable .= '_' . uniqid();
        $this->testAmazonProductsListingsTable .= '_' . uniqid();
        $this->testAmazonProductsItemsTable .= '_' . uniqid();
        $this->testAmazonAsinsTable .= '_' . uniqid();
        $this->testKeywordResearchTable .= '_' . uniqid();

        // Get base URL from config
        $baseUrl = config("api-cache.apis.{$this->clientName}.base_url");

        // Store the base URL (will be WSL-aware if needed)
        $this->apiBaseUrl = Helper\wsl_url($baseUrl);

        // Create mock response for API response formatting tests
        $response = new Response(
            new \GuzzleHttp\Psr7\Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode(['result' => 'success'])
            )
        );
        $this->mockApiResponse = [
            'request' => [
                'full_url' => 'https://api.example.com/test',
                'method'   => 'POST',
                'headers'  => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer test-token',
                ],
                'body' => json_encode(['test' => 'data']),
            ],
            'response'             => $response,
            'response_status_code' => $response->status(),
            'response_size'        => strlen($response->body()),
            'response_time'        => 0.5,
            'is_cached'            => true,
        ];
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

    /**
     * Generate a nested array of specified depth
     */
    private static function generateNestedArray(int $depth): array
    {
        if ($depth === 0) {
            return ['value' => true];
        }

        return ['next' => self::generateNestedArray($depth - 1)];
    }

    /**
     * Tests for check_server_status function
     */
    public function test_check_server_status_returns_true_for_healthy_server(): void
    {
        // Skip test if server is not accessible
        if (!check_server_status($this->apiBaseUrl)) {
            $this->markTestSkipped('API server is not accessible at: ' . $this->apiBaseUrl);
        }

        $this->assertTrue(check_server_status($this->apiBaseUrl));
    }

    public function test_check_server_status_respects_timeout_parameter(): void
    {
        // Skip test if server is not accessible
        if (!check_server_status($this->apiBaseUrl)) {
            $this->markTestSkipped('API server is not accessible at: ' . $this->apiBaseUrl);
        }

        $timeout = 1;
        $result  = check_server_status($this->apiBaseUrl, $timeout);
        $this->assertTrue($result);
    }

    public function test_check_server_status_returns_false_for_server_error(): void
    {
        $url = $this->apiBaseUrl . '/500';
        $this->assertFalse(check_server_status($url));
    }

    public function test_check_server_status_returns_false_for_invalid_json(): void
    {
        $url = $this->apiBaseUrl . '/404';
        $this->assertFalse(check_server_status($url));
    }

    /**
     * Tests for resolve_cache_manager function
     */
    public function test_resolve_cache_manager_returns_injected_manager(): void
    {
        $resolvedManager = resolve_cache_manager($this->cacheManager);

        $this->assertSame($this->cacheManager, $resolvedManager);
    }

    public function test_resolve_cache_manager_returns_singleton_when_not_injected(): void
    {
        $this->app->instance(ApiCacheManager::class, $this->cacheManager);

        // Test with null to simulate no injection
        $resolvedManager = resolve_cache_manager(null);

        $this->assertSame($this->cacheManager, $resolvedManager);
    }

    /**
     * Tests for create_responses_table function
     */
    public function test_create_responses_table_creates_uncompressed_table(): void
    {
        $schema = Schema::connection(null);
        create_responses_table($schema, $this->testResponsesTable);

        $this->assertTrue($schema->hasTable($this->testResponsesTable));

        // Verify columns
        $columns = $schema->getColumnListing($this->testResponsesTable);
        $this->assertContains('id', $columns);
        $this->assertContains('key', $columns);
        $this->assertContains('client', $columns);
        $this->assertContains('version', $columns);
        $this->assertContains('endpoint', $columns);
        $this->assertContains('request_headers', $columns);
        $this->assertContains('request_body', $columns);
        $this->assertContains('response_headers', $columns);
        $this->assertContains('response_body', $columns);
        $this->assertContains('response_status_code', $columns);
        $this->assertContains('response_size', $columns);
        $this->assertContains('response_time', $columns);
        $this->assertContains('expires_at', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
    }

    public function test_create_responses_table_creates_compressed_table(): void
    {
        $schema = Schema::connection(null);
        create_responses_table($schema, $this->testResponsesTable . '_compressed', true);

        $this->assertTrue($schema->hasTable($this->testResponsesTable . '_compressed'));

        // Verify columns
        $columns = $schema->getColumnListing($this->testResponsesTable . '_compressed');
        $this->assertContains('id', $columns);
        $this->assertContains('key', $columns);
        $this->assertContains('client', $columns);
        $this->assertContains('version', $columns);
        $this->assertContains('endpoint', $columns);
        $this->assertContains('request_headers', $columns);
        $this->assertContains('request_body', $columns);
        $this->assertContains('response_headers', $columns);
        $this->assertContains('response_body', $columns);
        $this->assertContains('response_status_code', $columns);
        $this->assertContains('response_size', $columns);
        $this->assertContains('response_time', $columns);
        $this->assertContains('expires_at', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
    }

    public function test_create_responses_table_respects_drop_existing_parameter(): void
    {
        $schema = Schema::connection(null);

        // Create table first time
        create_responses_table($schema, $this->testResponsesTable);

        // Insert a record
        DB::table($this->testResponsesTable)->insert([
            'key'                  => 'test-key',
            'client'               => 'test-client',
            'endpoint'             => 'test-endpoint',
            'response_status_code' => 200,
        ]);

        // Create table again without drop
        create_responses_table($schema, $this->testResponsesTable, false, false);

        // Record should still exist
        $this->assertEquals(1, DB::table($this->testResponsesTable)->count());

        // Create table again with drop
        create_responses_table($schema, $this->testResponsesTable, false, true);

        // Table should be empty
        $this->assertEquals(0, DB::table($this->testResponsesTable)->count());
    }

    /**
     * Tests for create_pixabay_images_table function
     */
    public function test_create_pixabay_images_table_creates_table(): void
    {
        $schema = Schema::connection(null);
        create_pixabay_images_table($schema, $this->testImagesTable);

        $this->assertTrue($schema->hasTable($this->testImagesTable));

        // Verify essential columns
        $columns = $schema->getColumnListing($this->testImagesTable);
        $this->assertContains('row_id', $columns);
        $this->assertContains('id', $columns);
        $this->assertContains('pageURL', $columns);
        $this->assertContains('type', $columns);
        $this->assertContains('tags', $columns);
        $this->assertContains('previewURL', $columns);
        $this->assertContains('webformatURL', $columns);
        $this->assertContains('largeImageURL', $columns);
        $this->assertContains('file_contents_preview', $columns);
        $this->assertContains('file_contents_webformat', $columns);
        $this->assertContains('file_contents_largeImage', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
    }

    public function test_create_pixabay_images_table_respects_drop_existing_parameter(): void
    {
        $schema = Schema::connection(null);

        // Create table first time
        create_pixabay_images_table($schema, $this->testImagesTable);

        // Insert a record
        DB::table($this->testImagesTable)->insert([
            'id'      => 123456,
            'pageURL' => 'https://example.com/image',
            'type'    => 'photo',
        ]);

        // Create table again without drop
        create_pixabay_images_table($schema, $this->testImagesTable, false, false);

        // Record should still exist
        $this->assertEquals(1, DB::table($this->testImagesTable)->count());

        // Create table again with drop
        create_pixabay_images_table($schema, $this->testImagesTable, true, false);

        // Table should be empty
        $this->assertEquals(0, DB::table($this->testImagesTable)->count());
    }

    /**
     * Tests for create_errors_table function
     */
    public function test_create_errors_table_creates_table(): void
    {
        $schema = Schema::connection(null);
        create_errors_table($schema, $this->testErrorsTable);

        $this->assertTrue($schema->hasTable($this->testErrorsTable));

        // Verify essential columns
        $columns = $schema->getColumnListing($this->testErrorsTable);
        $this->assertContains('id', $columns);
        $this->assertContains('api_client', $columns);
        $this->assertContains('error_type', $columns);
        $this->assertContains('log_level', $columns);
        $this->assertContains('error_message', $columns);
        $this->assertContains('api_message', $columns);
        $this->assertContains('response_preview', $columns);
        $this->assertContains('context_data', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
    }

    public function test_create_errors_table_respects_drop_existing_parameter(): void
    {
        $schema = Schema::connection(null);

        // Create table first time
        create_errors_table($schema, $this->testErrorsTable);

        // Insert a record
        DB::table($this->testErrorsTable)->insert([
            'api_client'    => 'test-client',
            'error_type'    => 'http_error',
            'log_level'     => 'error',
            'error_message' => 'Test error message',
            'created_at'    => now(),
        ]);

        // Create table again without drop
        create_errors_table($schema, $this->testErrorsTable, false);

        // Record should still exist
        $this->assertEquals(1, DB::table($this->testErrorsTable)->count());

        // Create table again with drop
        create_errors_table($schema, $this->testErrorsTable, true);

        // Table should be empty
        $this->assertEquals(0, DB::table($this->testErrorsTable)->count());
    }

    public function test_create_errors_table_with_verify_parameter(): void
    {
        $schema = Schema::connection(null);

        // Test with verify = true, should not throw an exception
        create_errors_table($schema, $this->testErrorsTable, false, true);

        $this->assertTrue($schema->hasTable($this->testErrorsTable));

        // Drop and recreate to test verification
        $schema->dropIfExists($this->testErrorsTable);
        create_errors_table($schema, $this->testErrorsTable, false, true);

        // Insert a record to verify table is functional
        DB::table($this->testErrorsTable)->insert([
            'api_client'    => 'test-client',
            'error_type'    => 'cache_rejected',
            'log_level'     => 'warning',
            'error_message' => 'Test warning message',
            'api_message'   => 'API specific error message',
            'created_at'    => now(),
        ]);

        $this->assertEquals(1, DB::table($this->testErrorsTable)->count());

        // Verify api_message column can store data
        $record = DB::table($this->testErrorsTable)->first();
        $this->assertEquals('API specific error message', $record->api_message);
    }

    public function test_create_errors_table_api_message_nullable(): void
    {
        $schema = Schema::connection(null);
        create_errors_table($schema, $this->testErrorsTable);

        // Insert a record without api_message to verify it's nullable
        DB::table($this->testErrorsTable)->insert([
            'api_client'    => 'test-client',
            'error_type'    => 'http_error',
            'log_level'     => 'error',
            'error_message' => 'Test error without api_message',
            'created_at'    => now(),
        ]);

        $this->assertEquals(1, DB::table($this->testErrorsTable)->count());

        // Verify api_message is null when not provided
        $record = DB::table($this->testErrorsTable)->first();
        $this->assertNull($record->api_message);
    }

    public static function normalize_params_provider(): array
    {
        return [
            // Happy Paths
            'simple scalars' => [
                'input'    => ['a' => 1, 'b' => 'string', 'c' => true, 'd' => 1.5],
                'expected' => ['a' => 1, 'b' => 'string', 'c' => true, 'd' => 1.5],
            ],
            'nested arrays' => [
                'input'    => ['a' => ['b' => 2, 'a' => 1], 'c' => 3],
                'expected' => ['a' => ['a' => 1, 'b' => 2], 'c' => 3],
            ],
            'empty arrays' => [
                'input'    => ['a' => [], 'b' => [[], []]],
                'expected' => ['a' => [], 'b' => [[], []]],
            ],
            'zero and false values' => [
                'input'    => ['zero' => 0, 'false' => false, 'empty_string' => '', 'space' => ' '],
                'expected' => ['zero' => 0, 'false' => false, 'empty_string' => '', 'space' => ' '],
            ],
            'special characters in keys' => [
                'input'    => ['key-with-dash' => 1, 'key_with_underscore' => 2, 'key.with.dots' => 3],
                'expected' => ['key-with-dash' => 1, 'key_with_underscore' => 2, 'key.with.dots' => 3],
            ],
            'unicode strings' => [
                'input'    => ['utf8' => 'Hello 世界', 'emoji' => '👋 🌍'],
                'expected' => ['utf8' => 'Hello 世界', 'emoji' => '👋 🌍'],
            ],
            'maximum depth' => [
                'input'    => self::generateNestedArray(19),
                'expected' => self::generateNestedArray(19),
            ],
            'mixed numeric and string keys' => [
                'input'    => [0 => 'zero', '1' => 'one', 'two' => 2],
                'expected' => [0 => 'zero', 1 => 'one', 'two' => 2],
            ],
            'null values removed' => [
                'input'    => ['a' => 1, 'b' => null, 'c' => ['d' => null, 'e' => 2]],
                'expected' => ['a' => 1, 'c' => ['e' => 2]],
            ],
            'numeric string keys' => [
                'input'    => ['123' => 'value'],
                'expected' => [123 => 'value'],
            ],
        ];
    }

    #[DataProvider('normalize_params_provider')]
    public function test_normalize_params_handles_various_inputs(array $input, array $expected): void
    {
        $this->assertEquals($expected, normalize_params($input));
    }

    public function test_normalize_params_throws_on_object(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        normalize_params(['obj' => new \stdClass()]);
    }

    public function test_normalize_params_throws_on_resource(): void
    {
        $resource = fopen('php://memory', 'r');
        $this->expectException(\InvalidArgumentException::class);
        normalize_params(['resource' => $resource]);
        fclose($resource);
    }

    public function test_normalize_params_throws_on_closure(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        normalize_params(['closure' => function () {}]);
    }

    public function test_normalize_params_throws_on_deep_nesting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        normalize_params(self::generateNestedArray(21));
    }

    public static function summarize_params_provider(): array
    {
        return [
            'empty array' => [
                'input'    => [],
                'expected' => '[]',
            ],
            'simple string truncation' => [
                'input' => [
                    'query' => str_repeat('a', 200),
                ],
                'expected' => '{"query":"' . str_repeat('a', 100) . '..."}',
            ],
            'nested array' => [
                'input' => [
                    'filters' => [
                        'type'   => str_repeat('b', 200),
                        'status' => 'active',
                    ],
                ],
                'expected' => json_encode([
                    'filters' => mb_substr(
                        json_encode(normalize_params([
                            'type'   => str_repeat('b', 200),
                            'status' => 'active',
                        ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        0,
                        100
                    ) . '..."}',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
            'string values only' => [
                'input' => [
                    'string1' => str_repeat('c', 200),
                    'string2' => 'short',
                ],
                'expected' => '{"string1":"' . str_repeat('c', 100) . '...","string2":"short"}',
            ],
            'utf-8 characters' => [
                'input' => [
                    'text' => str_repeat('测试', 60),
                ],
                'expected' => '{"text":"' . str_repeat('测试', 50) . '..."}',
            ],
            'special characters' => [
                'input' => [
                    'url' => 'https://example.com/path?param=' . str_repeat('x', 200),
                ],
                'expected' => '{"url":"' . mb_substr('https://example.com/path?param=' . str_repeat('x', 200), 0, 100) . '..."}',
            ],
            'mixed types with type preservation' => [
                'input' => [
                    'string' => 'test',
                    'int'    => 123,
                    'float'  => 45.67,
                    'bool'   => true,
                    'null'   => null,
                    'array'  => ['key' => 'value'],
                ],
                'expected' => '{"array":"{\"key\":\"value\"}","bool":true,"float":45.67,"int":123,"string":"test"}',
            ],
            'boolean values preserved' => [
                'input' => [
                    'enabled'  => true,
                    'disabled' => false,
                ],
                'expected' => '{"disabled":false,"enabled":true}',
            ],
            'numeric values preserved' => [
                'input' => [
                    'count'      => 42,
                    'percentage' => 85.5,
                    'zero'       => 0,
                ],
                'expected' => '{"count":42,"percentage":85.5,"zero":0}',
            ],
            'null values preserved' => [
                'input' => [
                    'optional_field' => null,
                    'required_field' => 'value',
                ],
                'expected' => '{"required_field":"value"}',
            ],
            'pretty print' => [
                'input' => [
                    'key' => 'value',
                ],
                'expected'    => "{\n    \"key\": \"value\"\n}",
                'prettyPrint' => true,
            ],

            // Task array detection tests
            'single task array - should flatten' => [
                'input' => [
                    [
                        'keyword'       => 'test',
                        'location_code' => 2840,
                        'language_code' => 'en',
                    ],
                ],
                'expected' => '{"keyword":"test","language_code":"en","location_code":2840}',
            ],

            'multi task array - should not flatten' => [
                'input' => [
                    ['keyword' => 'first'],
                    ['keyword' => 'second'],
                ],
                'expected' => '["{\\"keyword\\":\\"first\\"}","{\\"keyword\\":\\"second\\"}"]',
            ],

            'single non-array element - should not flatten' => [
                'input'    => [0 => 'just a string'],
                'expected' => '["just a string"]',
            ],

            'mixed keys with numeric 0 - should not flatten' => [
                'input' => [
                    0       => ['param' => 'value'],
                    'other' => 'data',
                ],
                'expected' => '{"0":"{\\"param\\":\\"value\\"}","other":"data"}',
            ],
        ];
    }

    #[DataProvider('summarize_params_provider')]
    public function test_summarize_params_truncates_values(array $input, string $expected, bool $prettyPrint = false): void
    {
        $result = summarize_params($input, true, $prettyPrint);
        $this->assertEquals($expected, $result);
    }

    public function test_summarize_params_respects_custom_character_limit(): void
    {
        $input = [
            'long_string'  => str_repeat('x', 200),
            'short_string' => 'short',
            'number'       => 42,
            'boolean'      => true,
            'array'        => ['key' => str_repeat('y', 100)],
        ];

        // Test with character limit of 50
        $characterLimit = 50;
        $result         = summarize_params($input, true, false, $characterLimit);
        $decoded        = json_decode($result, true);

        $this->assertLessThanOrEqual($characterLimit + 3, mb_strlen($decoded['long_string'])); // Add 3 for the ellipsis
        $this->assertEquals('short', $decoded['short_string']); // Short strings unchanged
        $this->assertEquals(42, $decoded['number']); // Numbers preserved as-is
        $this->assertEquals(true, $decoded['boolean']); // Booleans preserved as-is
        $this->assertLessThanOrEqual($characterLimit + 5, mb_strlen($decoded['array'])); // Add 5 for the ellipsis, quote, and closing brace

        // Test with character limit of 20
        $characterLimit = 20;
        $result         = summarize_params($input, true, false, $characterLimit);
        $decoded        = json_decode($result, true);

        $this->assertLessThanOrEqual($characterLimit + 3, mb_strlen($decoded['long_string'])); // Add 3 for the ellipsis
        $this->assertEquals('short', $decoded['short_string']); // Short strings unchanged
        $this->assertEquals(42, $decoded['number']); // Numbers preserved as-is
        $this->assertEquals(true, $decoded['boolean']); // Booleans preserved as-is
        $this->assertLessThanOrEqual($characterLimit + 5, mb_strlen($decoded['array'])); // Add 5 for the ellipsis, quote, and closing brace
    }

    public function test_summarize_params_task_array_detection_enabled(): void
    {
        $taskArray = [
            [
                'keyword'       => 'test keyword',
                'location_code' => 2840,
                'language_code' => 'en',
                'amount'        => 1,
            ],
        ];

        // Test with detection enabled (default)
        $result  = summarize_params($taskArray);
        $decoded = json_decode($result, true);

        // Should flatten the task array and show individual parameters
        $this->assertArrayHasKey('keyword', $decoded);
        $this->assertArrayHasKey('location_code', $decoded);
        $this->assertArrayHasKey('language_code', $decoded);
        $this->assertArrayHasKey('amount', $decoded);
        $this->assertEquals('test keyword', $decoded['keyword']);
        $this->assertEquals(2840, $decoded['location_code']);
        $this->assertEquals('en', $decoded['language_code']);
        $this->assertEquals(1, $decoded['amount']);
    }

    public function test_summarize_params_task_array_detection_disabled(): void
    {
        $taskArray = [
            [
                'keyword'       => 'test keyword',
                'location_code' => 2840,
                'language_code' => 'en',
                'amount'        => 1,
            ],
        ];

        // Test with detection disabled
        $result  = summarize_params($taskArray, true, true, 100, false);
        $decoded = json_decode($result, true);

        // Should treat as array with one element (not flattened)
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        $this->assertIsString($decoded[0]);
        $this->assertStringContainsString('keyword', $decoded[0]);
        $this->assertStringContainsString('location_code', $decoded[0]);
        $this->assertStringContainsString('test keyword', $decoded[0]);
    }

    /**
     * Tests for format_api_response function
     */
    public function test_format_api_response_formats_basic_info(): void
    {
        $output = format_api_response($this->mockApiResponse);

        $this->assertStringContainsString('Status code: 200', $output);
        $this->assertStringContainsString('Response time (seconds): 0.5000', $output);
        $this->assertStringContainsString('Response size (bytes): 20', $output);
        $this->assertStringContainsString('Is cached: Yes', $output);
    }

    public function test_format_api_response_formats_request_info_without_response_info(): void
    {
        $output = format_api_response($this->mockApiResponse, true, false);

        // Basic info
        $this->assertStringContainsString('Status code: 200', $output);
        $this->assertStringContainsString('Response time (seconds): 0.5000', $output);

        // Request details
        $this->assertStringContainsString('Request details:', $output);
        $this->assertStringContainsString('URL: https://api.example.com/test', $output);
        $this->assertStringContainsString('Method: POST', $output);

        // Headers
        $this->assertStringContainsString('Request headers:', $output);
        $this->assertStringContainsString('Content-Type: application/json', $output);
        $this->assertStringContainsString('Authorization: Bearer test-token', $output);

        // Body
        $this->assertStringContainsString('Request body:', $output);
        $this->assertStringContainsString('"test": "data"', $output);

        // Response details should not be included
        $this->assertStringNotContainsString('Response headers:', $output);
        $this->assertStringNotContainsString('Response body:', $output);
    }

    public function test_format_api_response_formats_response_info_without_request_info(): void
    {
        $output = format_api_response($this->mockApiResponse, false, true);

        // Basic info
        $this->assertStringContainsString('Status code: 200', $output);
        $this->assertStringContainsString('Response time (seconds): 0.5000', $output);

        // Request details should not be included
        $this->assertStringNotContainsString('Request details:', $output);
        $this->assertStringNotContainsString('Request headers:', $output);
        $this->assertStringNotContainsString('Request body:', $output);

        // Response details
        $this->assertStringContainsString('Response headers:', $output);
        $this->assertStringContainsString('Response body:', $output);
        $this->assertStringContainsString('"result": "success"', $output);
    }

    public function test_format_api_response_formats_both_request_and_response_info(): void
    {
        $output = format_api_response($this->mockApiResponse, true, true);

        // Basic info
        $this->assertStringContainsString('Status code: 200', $output);
        $this->assertStringContainsString('Response time (seconds): 0.5000', $output);

        // Request details
        $this->assertStringContainsString('Request details:', $output);
        $this->assertStringContainsString('URL: https://api.example.com/test', $output);
        $this->assertStringContainsString('Method: POST', $output);

        // Headers
        $this->assertStringContainsString('Request headers:', $output);
        $this->assertStringContainsString('Content-Type: application/json', $output);
        $this->assertStringContainsString('Authorization: Bearer test-token', $output);

        // Body
        $this->assertStringContainsString('Request body:', $output);
        $this->assertStringContainsString('"test": "data"', $output);

        // Response
        $this->assertStringContainsString('Response headers:', $output);
        $this->assertStringContainsString('Response body:', $output);
        $this->assertStringContainsString('"result": "success"', $output);
    }

    public function test_format_api_response_handles_missing_fields(): void
    {
        $minimalResponse = [
            'response_status_code' => 200,
        ];

        $output = format_api_response($minimalResponse);

        $this->assertStringContainsString('Status code: 200', $output);
        $this->assertStringContainsString('Response time (seconds): N/A', $output);
        $this->assertStringContainsString('Response size (bytes): N/A', $output);
        $this->assertStringContainsString('Is cached: N/A', $output);
    }

    public function test_format_api_response_with_both_request_and_response_info_handles_plain_text(): void
    {
        // Here the request body and response body are both plain text, instead of JSON.
        $this->mockApiResponse['request']['body'] = 'plain text data';
        $this->mockApiResponse['response']        = new Response(
            new \GuzzleHttp\Psr7\Response(
                200,
                ['Content-Type' => 'text/plain'],
                'plain text response'
            )
        );

        $output = format_api_response($this->mockApiResponse, true, true);

        $this->assertStringContainsString('plain text data', $output);
        $this->assertStringContainsString('plain text response', $output);
    }

    public function test_format_api_response_handles_null_values(): void
    {
        $responseWithNulls = [
            'response_status_code' => null,
            'response_time'        => null,
            'response_size'        => null,
            'is_cached'            => null,
        ];

        $output = format_api_response($responseWithNulls);

        $this->assertStringContainsString('Status code: N/A', $output);
        $this->assertStringContainsString('Response time (seconds): N/A', $output);
        $this->assertStringContainsString('Response size (bytes): N/A', $output);
        $this->assertStringContainsString('Is cached: N/A', $output);
    }

    public function test_get_tables_returns_array_of_tables(): void
    {
        // Create some test tables
        Schema::create('test_table1', function ($table) {
            $table->id();
        });
        Schema::create('test_table2', function ($table) {
            $table->id();
        });

        $tables = get_tables();

        $this->assertContains('test_table1', $tables);
        $this->assertContains('test_table2', $tables);
    }

    public function test_get_tables_throws_exception_for_unsupported_driver(): void
    {
        // Create a mock connection that will return an unsupported driver name
        $mockConnection = \Mockery::mock();
        $mockConnection->shouldReceive('getDriverName')
            ->once()
            ->andReturn('unsupportedDriverName');

        // Ensure getSchemaBuilder() exists, even if not used
        $mockConnection->shouldReceive('getSchemaBuilder')->andReturnSelf();

        // Mock Schema facade to prevent errors related to dropIfExists()
        Schema::shouldReceive('dropIfExists')->andReturnTrue();

        // Mock DB facade to return the mock connection
        DB::shouldReceive('connection')
            ->andReturn($mockConnection);

        // Ensure DB::select() is never called since the exception should occur first
        $mockConnection->shouldNotReceive('select');

        // Expect an exception when calling get_tables()
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unsupported database driver: unsupported');

        get_tables();
    }

    public function test_download_public_suffix_list_returns_path_when_file_exists(): void
    {
        // Create a test file
        $expectedPath    = storage_path('app/public_suffix_list.dat');
        $expectedContent = 'public suffix list content';
        file_put_contents($expectedPath, $expectedContent);

        $path = download_public_suffix_list();

        $this->assertSame($expectedPath, $path);
        $this->assertFileExists($path);
        $this->assertSame($expectedContent, file_get_contents($path));
    }

    public function test_download_public_suffix_list_downloads_when_file_does_not_exist(): void
    {
        // Create a test file
        $expectedPath    = storage_path('app/public_suffix_list.dat');
        $expectedContent = 'public suffix list content';

        // Ensure file doesn't exist
        if (file_exists($expectedPath)) {
            unlink($expectedPath);
        }

        Http::fake([
            'publicsuffix.org/list/public_suffix_list.dat' => Http::response($expectedContent),
        ]);

        $path = download_public_suffix_list();

        $this->assertSame($expectedPath, $path);
        $this->assertFileExists($path);
        $this->assertSame($expectedContent, file_get_contents($path));
    }

    public function test_download_public_suffix_list_throws_exception_on_download_failure(): void
    {
        $expectedPath = storage_path('app/public_suffix_list.dat');

        // Ensure file doesn't exist
        if (file_exists($expectedPath)) {
            unlink($expectedPath);
        }

        Http::fake([
            'publicsuffix.org/list/public_suffix_list.dat' => Http::response('', 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to download public suffix list');

        download_public_suffix_list();
    }

    public static function provideExtractRegistrableDomainTestCases(): array
    {
        return [
            // php-domain-parser already strips www for example.com
            'simple domain' => [
                'url'      => 'example.com',
                'expected' => 'example.com',
            ],
            'domain with www' => [
                'url'      => 'www.example.com',
                'expected' => 'example.com',
            ],
            'domain with www and stripWww false' => [
                'url'      => 'www.example.com',
                'expected' => 'example.com',
                'stripWww' => false,
            ],

            // php-domain-parser keeps www for httpbin.org
            'www.httpbin.org' => [
                'url'      => 'www.httpbin.org',
                'expected' => 'httpbin.org',
            ],
            'www.httpbin.org with stripWww false' => [
                'url'      => 'www.httpbin.org',
                'expected' => 'www.httpbin.org',
                'stripWww' => false,
            ],

            // URLs with protocols
            'http url' => [
                'url'      => 'http://example.com',
                'expected' => 'example.com',
            ],
            'https url' => [
                'url'      => 'https://example.com',
                'expected' => 'example.com',
            ],
            'https url with www' => [
                'url'      => 'https://www.example.com',
                'expected' => 'example.com',
            ],
            'https httpbin.org with www' => [
                'url'      => 'https://www.httpbin.org',
                'expected' => 'httpbin.org',
            ],

            // URLs with paths and queries
            'url with path' => [
                'url'      => 'https://example.com/path/to/page',
                'expected' => 'example.com',
            ],
            'url with query' => [
                'url'      => 'https://example.com?param=value',
                'expected' => 'example.com',
            ],
            'url with path and query' => [
                'url'      => 'https://example.com/path?param=value',
                'expected' => 'example.com',
            ],
            'httpbin.org with path' => [
                'url'      => 'https://www.httpbin.org/path',
                'expected' => 'httpbin.org',
            ],
        ];
    }

    #[DataProvider('provideExtractRegistrableDomainTestCases')]
    public function testExtractRegistrableDomain(string $url, string $expected, bool $stripWww = true): void
    {
        $actual = extract_registrable_domain($url, $stripWww);
        $this->assertEquals($expected, $actual);
    }

    public function test_create_dataforseo_serp_google_organic_listings_table_creates_table(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testGoogleOrganicListingsTable;
        create_dataforseo_serp_google_organic_listings_table($schema, $table, true, true);
        $this->assertTrue($schema->hasTable($table));
        $columns = $schema->getColumnListing($table);
        $this->assertContains('id', $columns);
        $this->assertContains('response_id', $columns);
        $this->assertContains('task_id', $columns);
        $this->assertContains('keyword', $columns);
        $this->assertContains('result_keyword', $columns);
        $this->assertContains('se_domain', $columns);
        $this->assertContains('check_url', $columns);
        $this->assertContains('result_datetime', $columns);
        $this->assertContains('spell', $columns);
        $this->assertContains('refinement_chips', $columns);
        $this->assertContains('item_types', $columns);
        $this->assertContains('se_results_count', $columns);
        $this->assertContains('items_count', $columns);
        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_serp_google_organic_listings_table_respects_drop_existing_parameter(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testGoogleOrganicListingsTable;
        create_dataforseo_serp_google_organic_listings_table($schema, $table, true, false);
        \Illuminate\Support\Facades\DB::table($table)->insert([
            'keyword'         => 'test-keyword',
            'se'              => 'google',
            'se_type'         => 'web',
            'location_code'   => 2840,
            'language_code'   => 'en',
            'device'          => 'desktop',
            'result_keyword'  => 'test-keyword',
            'se_domain'       => 'google.com',
            'check_url'       => 'https://google.com/search?q=test',
            'result_datetime' => '2024-01-01 12:00:00 +00:00',
            'item_types'      => '["organic"]',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        create_dataforseo_serp_google_organic_listings_table($schema, $table, false, false);
        $this->assertEquals(1, \Illuminate\Support\Facades\DB::table($table)->count());
        create_dataforseo_serp_google_organic_listings_table($schema, $table, true, false);
        $this->assertEquals(0, \Illuminate\Support\Facades\DB::table($table)->count());
        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_serp_google_organic_items_table_creates_table(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testGoogleOrganicTable;
        create_dataforseo_serp_google_organic_items_table($schema, $table, true, true);
        $this->assertTrue($schema->hasTable($table));
        $columns = $schema->getColumnListing($table);
        $this->assertContains('id', $columns);
        $this->assertContains('response_id', $columns);
        $this->assertContains('task_id', $columns);
        $this->assertContains('keyword', $columns);
        $this->assertContains('tag', $columns);
        $this->assertContains('result_keyword', $columns);
        $this->assertContains('items_type', $columns);
        $this->assertContains('is_featured_snippet', $columns);
        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_serp_google_organic_items_table_respects_drop_existing_parameter(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testGoogleOrganicTable;
        create_dataforseo_serp_google_organic_items_table($schema, $table, true, false);
        \Illuminate\Support\Facades\DB::table($table)->insert([
            'keyword'        => 'test-keyword',
            'tag'            => 'test-tag',
            'result_keyword' => 'test-result-keyword',
            'se_domain'      => 'google.com',
            'location_code'  => 2840,
            'language_code'  => 'en',
            'device'         => 'desktop',
            'os'             => 'windows',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        create_dataforseo_serp_google_organic_items_table($schema, $table, false, false);
        $this->assertEquals(1, \Illuminate\Support\Facades\DB::table($table)->count());
        create_dataforseo_serp_google_organic_items_table($schema, $table, true, false);
        $this->assertEquals(0, \Illuminate\Support\Facades\DB::table($table)->count());
        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_serp_google_organic_paa_items_table_creates_table(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testGoogleOrganicPaaTable;
        create_dataforseo_serp_google_organic_paa_items_table($schema, $table, true, true);
        $this->assertTrue($schema->hasTable($table));
        $columns = $schema->getColumnListing($table);
        $this->assertContains('id', $columns);
        $this->assertContains('response_id', $columns);
        $this->assertContains('task_id', $columns);
        $this->assertContains('organic_items_id', $columns);
        $this->assertContains('keyword', $columns);
        $this->assertContains('tag', $columns);
        $this->assertContains('result_keyword', $columns);
        $this->assertContains('se_domain', $columns);
        $this->assertContains('location_code', $columns);
        $this->assertContains('language_code', $columns);
        $this->assertContains('device', $columns);
        $this->assertContains('os', $columns);
        $this->assertContains('paa_sequence', $columns);
        $this->assertContains('type', $columns);
        $this->assertContains('title', $columns);
        $this->assertContains('seed_question', $columns);
        $this->assertContains('xpath', $columns);
        $this->assertContains('answer_type', $columns);
        $this->assertContains('answer_featured_title', $columns);
        $this->assertContains('answer_url', $columns);
        $this->assertContains('answer_domain', $columns);
        $this->assertContains('answer_title', $columns);
        $this->assertContains('answer_description', $columns);
        $this->assertContains('answer_images', $columns);
        $this->assertContains('answer_timestamp', $columns);
        $this->assertContains('answer_table', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
        $this->assertContains('processed_at', $columns);
        $this->assertContains('processed_status', $columns);
        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_serp_google_organic_paa_items_table_respects_drop_existing_parameter(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testGoogleOrganicPaaTable;
        create_dataforseo_serp_google_organic_paa_items_table($schema, $table, true, false);
        \Illuminate\Support\Facades\DB::table($table)->insert([
            'keyword'        => 'test-keyword',
            'tag'            => 'test-tag',
            'result_keyword' => 'test-result-keyword',
            'se_domain'      => 'google.com',
            'location_code'  => 2840,
            'language_code'  => 'en',
            'device'         => 'desktop',
            'os'             => 'windows',
            'paa_sequence'   => 1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        create_dataforseo_serp_google_organic_paa_items_table($schema, $table, false, false);
        $this->assertEquals(1, \Illuminate\Support\Facades\DB::table($table)->count());
        create_dataforseo_serp_google_organic_paa_items_table($schema, $table, true, false);
        $this->assertEquals(0, \Illuminate\Support\Facades\DB::table($table)->count());
        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_serp_google_autocomplete_items_table_creates_table(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testGoogleAutocompleteTable;

        create_dataforseo_serp_google_autocomplete_items_table($schema, $table, false, true);

        $this->assertTrue($schema->hasTable($table));

        // Verify essential columns exist
        $columns = $schema->getColumnListing($table);
        $this->assertContains('id', $columns);
        $this->assertContains('response_id', $columns);
        $this->assertContains('task_id', $columns);
        $this->assertContains('keyword', $columns);
        $this->assertContains('se_domain', $columns);
        $this->assertContains('location_code', $columns);
        $this->assertContains('language_code', $columns);
        $this->assertContains('device', $columns);
        $this->assertContains('os', $columns);
        $this->assertContains('tag', $columns);
        $this->assertContains('result_keyword', $columns);
        $this->assertContains('cursor_pointer', $columns);
        $this->assertContains('type', $columns);
        $this->assertContains('rank_group', $columns);
        $this->assertContains('rank_absolute', $columns);
        $this->assertContains('relevance', $columns);
        $this->assertContains('suggestion', $columns);
        $this->assertContains('suggestion_type', $columns);
        $this->assertContains('highlighted', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
        $this->assertContains('processed_at', $columns);
        $this->assertContains('processed_status', $columns);

        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_serp_google_autocomplete_items_table_respects_drop_existing_parameter(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testGoogleAutocompleteTable;

        // Create table first time
        create_dataforseo_serp_google_autocomplete_items_table($schema, $table, false, false);

        // Insert a test record
        \Illuminate\Support\Facades\DB::table($table)->insert([
            'keyword'        => 'test keyword',
            'tag'            => 'test-tag',
            'result_keyword' => 'test result keyword',
            'se_domain'      => 'google.com',
            'location_code'  => 2840,
            'language_code'  => 'en',
            'device'         => 'desktop',
            'os'             => 'windows',
            'cursor_pointer' => -1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // Create table again without drop - record should still exist
        create_dataforseo_serp_google_autocomplete_items_table($schema, $table, false, false);
        $this->assertEquals(1, \Illuminate\Support\Facades\DB::table($table)->count());

        // Create table again with drop - table should be empty
        create_dataforseo_serp_google_autocomplete_items_table($schema, $table, true, false);
        $this->assertEquals(0, \Illuminate\Support\Facades\DB::table($table)->count());

        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_serp_google_autocomplete_items_table_with_verify_parameter(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testGoogleAutocompleteTable;

        // Test with verify = true, should not throw an exception
        create_dataforseo_serp_google_autocomplete_items_table($schema, $table, false, true);

        $this->assertTrue($schema->hasTable($table));

        // Insert a record to verify table is functional
        \Illuminate\Support\Facades\DB::table($table)->insert([
            'keyword'         => 'test autocomplete',
            'se_domain'       => 'google.com',
            'location_code'   => 2840,
            'language_code'   => 'en',
            'device'          => 'desktop',
            'os'              => 'windows',
            'cursor_pointer'  => -1,
            'type'            => 'autocomplete',
            'rank_group'      => 1,
            'rank_absolute'   => 1,
            'relevance'       => 100,
            'suggestion'      => 'test autocomplete suggestion',
            'suggestion_type' => 'query',
            'highlighted'     => '<b>test</b> autocomplete suggestion',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->assertEquals(1, \Illuminate\Support\Facades\DB::table($table)->count());

        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_keywords_data_google_ads_items_table_creates_table(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testGoogleAdsTable;
        create_dataforseo_keywords_data_google_ads_items_table($schema, $table, true, true);
        $this->assertTrue($schema->hasTable($table));
        $columns = $schema->getColumnListing($table);
        $this->assertContains('id', $columns);
        $this->assertContains('response_id', $columns);
        $this->assertContains('task_id', $columns);
        $this->assertContains('keyword', $columns);
        $this->assertContains('se', $columns);
        $this->assertContains('location_code', $columns);
        $this->assertContains('language_code', $columns);
        $this->assertContains('spell', $columns);
        $this->assertContains('search_partners', $columns);
        $this->assertContains('competition', $columns);
        $this->assertContains('competition_index', $columns);
        $this->assertContains('search_volume', $columns);
        $this->assertContains('low_top_of_page_bid', $columns);
        $this->assertContains('high_top_of_page_bid', $columns);
        $this->assertContains('cpc', $columns);
        $this->assertContains('monthly_searches', $columns);
        $this->assertContains('processed_at', $columns);
        $this->assertContains('processed_status', $columns);
        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_keywords_data_google_ads_items_table_respects_drop_existing_parameter(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testGoogleAdsTable;
        create_dataforseo_keywords_data_google_ads_items_table($schema, $table, true, false);
        \Illuminate\Support\Facades\DB::table($table)->insert([
            'keyword'       => 'test-keyword',
            'se'            => 'google',
            'location_code' => 2840,
            'language_code' => 'en',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        create_dataforseo_keywords_data_google_ads_items_table($schema, $table, false, false);
        $this->assertEquals(1, \Illuminate\Support\Facades\DB::table($table)->count());
        create_dataforseo_keywords_data_google_ads_items_table($schema, $table, true, false);
        $this->assertEquals(0, \Illuminate\Support\Facades\DB::table($table)->count());
        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_backlinks_bulk_items_table_creates_table(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testBacklinksBulkTable;

        create_dataforseo_backlinks_bulk_items_table($schema, $table, false, true);

        $this->assertTrue($schema->hasTable($table));

        // Verify essential columns exist
        $columns = $schema->getColumnListing($table);
        $this->assertContains('id', $columns);
        $this->assertContains('response_id', $columns);
        $this->assertContains('task_id', $columns);
        $this->assertContains('target', $columns);
        $this->assertContains('rank', $columns);
        $this->assertContains('main_domain_rank', $columns);
        $this->assertContains('backlinks', $columns);
        $this->assertContains('new_backlinks', $columns);
        $this->assertContains('lost_backlinks', $columns);
        $this->assertContains('broken_backlinks', $columns);
        $this->assertContains('broken_pages', $columns);
        $this->assertContains('spam_score', $columns);
        $this->assertContains('backlinks_spam_score', $columns);
        $this->assertContains('referring_domains', $columns);
        $this->assertContains('referring_domains_nofollow', $columns);
        $this->assertContains('referring_main_domains', $columns);
        $this->assertContains('referring_main_domains_nofollow', $columns);
        $this->assertContains('new_referring_domains', $columns);
        $this->assertContains('lost_referring_domains', $columns);
        $this->assertContains('new_referring_main_domains', $columns);
        $this->assertContains('lost_referring_main_domains', $columns);
        $this->assertContains('first_seen', $columns);
        $this->assertContains('lost_date', $columns);
        $this->assertContains('referring_ips', $columns);
        $this->assertContains('referring_subnets', $columns);
        $this->assertContains('referring_pages', $columns);
        $this->assertContains('referring_pages_nofollow', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
        $this->assertContains('processed_at', $columns);
        $this->assertContains('processed_status', $columns);

        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_backlinks_bulk_items_table_respects_drop_existing_parameter(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testBacklinksBulkTable;

        // Create table first time
        create_dataforseo_backlinks_bulk_items_table($schema, $table, false, false);

        // Insert a test record
        \Illuminate\Support\Facades\DB::table($table)->insert([
            'target'     => 'example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create table again without drop - record should still exist
        create_dataforseo_backlinks_bulk_items_table($schema, $table, false, false);
        $this->assertEquals(1, \Illuminate\Support\Facades\DB::table($table)->count());

        // Create table again with drop - table should be empty
        create_dataforseo_backlinks_bulk_items_table($schema, $table, true, false);
        $this->assertEquals(0, \Illuminate\Support\Facades\DB::table($table)->count());

        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_backlinks_bulk_items_table_with_verify_parameter(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testBacklinksBulkTable;

        // Test with verify = true, should not throw an exception
        create_dataforseo_backlinks_bulk_items_table($schema, $table, false, true);

        $this->assertTrue($schema->hasTable($table));

        // Insert a record to verify table is functional
        \Illuminate\Support\Facades\DB::table($table)->insert([
            'target'            => 'test-domain.com',
            'rank'              => 100,
            'backlinks'         => 500,
            'referring_domains' => 50,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $this->assertEquals(1, \Illuminate\Support\Facades\DB::table($table)->count());

        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_backlinks_bulk_items_table_with_custom_table_name(): void
    {
        $schema          = \Illuminate\Support\Facades\Schema::connection(null);
        $customTableName = 'custom_backlinks_table_' . uniqid();

        create_dataforseo_backlinks_bulk_items_table($schema, $customTableName, false, false);

        $this->assertTrue($schema->hasTable($customTableName));

        // Verify it's the correct table by checking columns
        $columns = $schema->getColumnListing($customTableName);
        $this->assertContains('target', $columns);
        $this->assertContains('backlinks', $columns);

        $schema->dropIfExists($customTableName);
    }

    public function test_create_dataforseo_backlinks_bulk_items_table_handles_different_database_drivers(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testBacklinksBulkTable;

        // Create table - should work regardless of driver (sqlite, mysql, pgsql)
        create_dataforseo_backlinks_bulk_items_table($schema, $table, false, false);

        $this->assertTrue($schema->hasTable($table));

        // Test that we can insert data regardless of driver
        \Illuminate\Support\Facades\DB::table($table)->insert([
            'target'                          => 'driver-test.com',
            'rank'                            => 1,
            'main_domain_rank'                => 2,
            'backlinks'                       => 1000,
            'new_backlinks'                   => 50,
            'lost_backlinks'                  => 10,
            'broken_backlinks'                => 5,
            'broken_pages'                    => 2,
            'spam_score'                      => 15,
            'backlinks_spam_score'            => 20,
            'referring_domains'               => 100,
            'referring_domains_nofollow'      => 25,
            'referring_main_domains'          => 80,
            'referring_main_domains_nofollow' => 20,
            'new_referring_domains'           => 10,
            'lost_referring_domains'          => 5,
            'new_referring_main_domains'      => 8,
            'lost_referring_main_domains'     => 3,
            'first_seen'                      => '2023-01-01',
            'lost_date'                       => '2023-12-31',
            'referring_ips'                   => 75,
            'referring_subnets'               => 60,
            'referring_pages'                 => 150,
            'referring_pages_nofollow'        => 40,
            'created_at'                      => now(),
            'updated_at'                      => now(),
        ]);

        $this->assertEquals(1, \Illuminate\Support\Facades\DB::table($table)->count());

        // Verify the inserted data
        $record = \Illuminate\Support\Facades\DB::table($table)->first();
        $this->assertEquals('driver-test.com', $record->target);
        $this->assertEquals(1000, $record->backlinks);

        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_merchant_amazon_products_listings_table_creates_table(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testAmazonProductsListingsTable;
        create_dataforseo_merchant_amazon_products_listings_table($schema, $table, true, true);
        $this->assertTrue($schema->hasTable($table));
        $columns = $schema->getColumnListing($table);
        $this->assertContains('id', $columns);
        $this->assertContains('response_id', $columns);
        $this->assertContains('task_id', $columns);
        $this->assertContains('keyword', $columns);
        $this->assertContains('se', $columns);
        $this->assertContains('se_type', $columns);
        $this->assertContains('function', $columns);
        $this->assertContains('location_code', $columns);
        $this->assertContains('language_code', $columns);
        $this->assertContains('device', $columns);
        $this->assertContains('os', $columns);
        $this->assertContains('tag', $columns);
        $this->assertContains('result_keyword', $columns);
        $this->assertContains('type', $columns);
        $this->assertContains('se_domain', $columns);
        $this->assertContains('check_url', $columns);
        $this->assertContains('result_datetime', $columns);
        $this->assertContains('spell', $columns);
        $this->assertContains('item_types', $columns);
        $this->assertContains('se_results_count', $columns);
        $this->assertContains('categories', $columns);
        $this->assertContains('items_count', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
        $this->assertContains('processed_at', $columns);
        $this->assertContains('processed_status', $columns);
        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_merchant_amazon_products_listings_table_respects_drop_existing_parameter(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testAmazonProductsListingsTable;
        create_dataforseo_merchant_amazon_products_listings_table($schema, $table, true, false);
        \Illuminate\Support\Facades\DB::table($table)->insert([
            'keyword'         => 'gaming keyboard',
            'se'              => 'amazon',
            'se_type'         => 'organic',
            'function'        => 'products',
            'location_code'   => 2840,
            'language_code'   => 'en_US',
            'device'          => 'desktop',
            'result_keyword'  => 'gaming keyboard',
            'se_domain'       => 'amazon.com',
            'check_url'       => 'https://www.amazon.com/s/?field-keywords=gaming%20keyboard',
            'result_datetime' => '2020-09-25 12:55:55 +00:00',
            'item_types'      => '["amazon_paid", "amazon_serp"]',
            'categories'      => '["PC Gaming Keyboards/All"]',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        create_dataforseo_merchant_amazon_products_listings_table($schema, $table, false, false);
        $this->assertEquals(1, \Illuminate\Support\Facades\DB::table($table)->count());
        create_dataforseo_merchant_amazon_products_listings_table($schema, $table, true, false);
        $this->assertEquals(0, \Illuminate\Support\Facades\DB::table($table)->count());
        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_merchant_amazon_products_items_table_creates_table(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testAmazonProductsItemsTable;
        create_dataforseo_merchant_amazon_products_items_table($schema, $table, true, true);
        $this->assertTrue($schema->hasTable($table));
        $columns = $schema->getColumnListing($table);
        $this->assertContains('id', $columns);
        $this->assertContains('response_id', $columns);
        $this->assertContains('task_id', $columns);
        $this->assertContains('keyword', $columns);
        $this->assertContains('se_domain', $columns);
        $this->assertContains('location_code', $columns);
        $this->assertContains('language_code', $columns);
        $this->assertContains('device', $columns);
        $this->assertContains('os', $columns);
        $this->assertContains('tag', $columns);
        $this->assertContains('result_keyword', $columns);
        $this->assertContains('items_type', $columns);
        $this->assertContains('rank_group', $columns);
        $this->assertContains('rank_absolute', $columns);
        $this->assertContains('xpath', $columns);
        $this->assertContains('domain', $columns);
        $this->assertContains('title', $columns);
        $this->assertContains('url', $columns);
        $this->assertContains('image_url', $columns);
        $this->assertContains('bought_past_month', $columns);
        $this->assertContains('price_from', $columns);
        $this->assertContains('price_to', $columns);
        $this->assertContains('currency', $columns);
        $this->assertContains('special_offers', $columns);
        $this->assertContains('data_asin', $columns);
        $this->assertContains('rating_type', $columns);
        $this->assertContains('rating_position', $columns);
        $this->assertContains('rating_rating_type', $columns);
        $this->assertContains('rating_value', $columns);
        $this->assertContains('rating_votes_count', $columns);
        $this->assertContains('rating_rating_max', $columns);
        $this->assertContains('is_amazon_choice', $columns);
        $this->assertContains('is_best_seller', $columns);
        $this->assertContains('delivery_info', $columns);
        $this->assertContains('nested_items', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
        $this->assertContains('processed_at', $columns);
        $this->assertContains('processed_status', $columns);
        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_merchant_amazon_products_items_table_respects_drop_existing_parameter(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testAmazonProductsItemsTable;
        create_dataforseo_merchant_amazon_products_items_table($schema, $table, true, false);
        \Illuminate\Support\Facades\DB::table($table)->insert([
            'keyword'          => 'gaming keyboard',
            'se_domain'        => 'amazon.com',
            'location_code'    => 2840,
            'language_code'    => 'en_US',
            'device'           => 'desktop',
            'result_keyword'   => 'gaming keyboard',
            'items_type'       => 'amazon_paid',
            'rank_group'       => 1,
            'rank_absolute'    => 1,
            'domain'           => 'www.amazon.com',
            'title'            => 'Logitech Gaming Keyboard',
            'url'              => 'https://www.amazon.com/product',
            'price_from'       => 229.99,
            'currency'         => 'USD',
            'data_asin'        => 'B085RFFC9Q',
            'is_amazon_choice' => false,
            'is_best_seller'   => false,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
        create_dataforseo_merchant_amazon_products_items_table($schema, $table, false, false);
        $this->assertEquals(1, \Illuminate\Support\Facades\DB::table($table)->count());
        create_dataforseo_merchant_amazon_products_items_table($schema, $table, true, false);
        $this->assertEquals(0, \Illuminate\Support\Facades\DB::table($table)->count());
        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_merchant_amazon_asins_table_creates_table(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testAmazonAsinsTable;
        create_dataforseo_merchant_amazon_asins_table($schema, $table, true, true);
        $this->assertTrue($schema->hasTable($table));
        $columns = $schema->getColumnListing($table);
        $this->assertContains('id', $columns);
        $this->assertContains('response_id', $columns);
        $this->assertContains('task_id', $columns);
        $this->assertContains('asin', $columns);
        $this->assertContains('se', $columns);
        $this->assertContains('se_type', $columns);
        $this->assertContains('location_code', $columns);
        $this->assertContains('language_code', $columns);
        $this->assertContains('device', $columns);
        $this->assertContains('os', $columns);
        $this->assertContains('load_more_local_reviews', $columns);
        $this->assertContains('local_reviews_sort', $columns);
        $this->assertContains('tag', $columns);
        $this->assertContains('result_asin', $columns);
        $this->assertContains('type', $columns);
        $this->assertContains('se_domain', $columns);
        $this->assertContains('check_url', $columns);
        $this->assertContains('result_datetime', $columns);
        $this->assertContains('spell', $columns);
        $this->assertContains('item_types', $columns);
        $this->assertContains('items_count', $columns);
        $this->assertContains('items_type', $columns);
        $this->assertContains('rank_group', $columns);
        $this->assertContains('rank_absolute', $columns);
        $this->assertContains('position', $columns);
        $this->assertContains('xpath', $columns);
        $this->assertContains('title', $columns);
        $this->assertContains('details', $columns);
        $this->assertContains('image_url', $columns);
        $this->assertContains('author', $columns);
        $this->assertContains('data_asin', $columns);
        $this->assertContains('parent_asin', $columns);
        $this->assertContains('product_asins', $columns);
        $this->assertContains('price_from', $columns);
        $this->assertContains('price_to', $columns);
        $this->assertContains('currency', $columns);
        $this->assertContains('is_amazon_choice', $columns);
        $this->assertContains('rating_type', $columns);
        $this->assertContains('rating_position', $columns);
        $this->assertContains('rating_rating_type', $columns);
        $this->assertContains('rating_value', $columns);
        $this->assertContains('rating_votes_count', $columns);
        $this->assertContains('rating_rating_max', $columns);
        $this->assertContains('is_newer_model_available', $columns);
        $this->assertContains('applicable_vouchers', $columns);
        $this->assertContains('newer_model', $columns);
        $this->assertContains('categories', $columns);
        $this->assertContains('product_information', $columns);
        $this->assertContains('product_images_list', $columns);
        $this->assertContains('product_videos_list', $columns);
        $this->assertContains('description', $columns);
        $this->assertContains('is_available', $columns);
        $this->assertContains('top_local_reviews', $columns);
        $this->assertContains('top_global_reviews', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
        $this->assertContains('processed_at', $columns);
        $this->assertContains('processed_status', $columns);
        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_merchant_amazon_asins_table_respects_drop_existing_parameter(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testAmazonAsinsTable;
        create_dataforseo_merchant_amazon_asins_table($schema, $table, true, false);
        \Illuminate\Support\Facades\DB::table($table)->insert([
            'asin'             => 'B016MAK38U',
            'se'               => 'amazon',
            'se_type'          => 'asin',
            'location_code'    => 2840,
            'language_code'    => 'en_US',
            'device'           => 'desktop',
            'os'               => 'windows',
            'result_asin'      => 'B016MAK38U',
            'type'             => 'shopping',
            'se_domain'        => 'amazon.com',
            'check_url'        => 'https://www.amazon.com/dp/B016MAK38U?language=en_US',
            'result_datetime'  => '2024-11-26 14:10:02 +00:00',
            'item_types'       => '["amazon_product_info"]',
            'items_count'      => 1,
            'items_type'       => 'amazon_product_info',
            'rank_group'       => 1,
            'rank_absolute'    => 1,
            'position'         => 'right',
            'title'            => 'Redragon K552 Mechanical Gaming Keyboard',
            'author'           => 'Visit the Redragon Store',
            'data_asin'        => 'B016MAK38U',
            'price_from'       => 29.59,
            'currency'         => 'USD',
            'is_amazon_choice' => true,
            'is_available'     => true,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
        create_dataforseo_merchant_amazon_asins_table($schema, $table, false, false);
        $this->assertEquals(1, \Illuminate\Support\Facades\DB::table($table)->count());
        create_dataforseo_merchant_amazon_asins_table($schema, $table, true, false);
        $this->assertEquals(0, \Illuminate\Support\Facades\DB::table($table)->count());
        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_labs_google_keyword_research_items_table_creates_table(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testKeywordResearchTable;
        create_dataforseo_labs_google_keyword_research_items_table($schema, $table, true, true);
        $this->assertTrue($schema->hasTable($table));

        $columns = $schema->getColumnListing($table);
        $this->assertContains('id', $columns);
        $this->assertContains('response_id', $columns);
        $this->assertContains('task_id', $columns);
        $this->assertContains('se_type', $columns);
        $this->assertContains('keyword', $columns);
        $this->assertContains('location_code', $columns);
        $this->assertContains('language_code', $columns);
        $this->assertContains('keyword_info_se_type', $columns);
        $this->assertContains('keyword_info_last_updated_time', $columns);
        $this->assertContains('keyword_info_competition', $columns);
        $this->assertContains('keyword_info_competition_level', $columns);
        $this->assertContains('keyword_info_cpc', $columns);
        $this->assertContains('keyword_info_search_volume', $columns);
        $this->assertContains('keyword_info_low_top_of_page_bid', $columns);
        $this->assertContains('keyword_info_high_top_of_page_bid', $columns);
        $this->assertContains('keyword_info_categories', $columns);
        $this->assertContains('keyword_info_monthly_searches', $columns);
        $this->assertContains('keyword_info_search_volume_trend_monthly', $columns);
        $this->assertContains('keyword_info_search_volume_trend_quarterly', $columns);
        $this->assertContains('keyword_info_search_volume_trend_yearly', $columns);
        $this->assertContains('keyword_info_normalized_with_bing_last_updated_time', $columns);
        $this->assertContains('keyword_info_normalized_with_bing_search_volume', $columns);
        $this->assertContains('keyword_info_normalized_with_bing_is_normalized', $columns);
        $this->assertContains('keyword_info_normalized_with_bing_monthly_searches', $columns);
        $this->assertContains('keyword_info_normalized_with_clickstream_last_updated_time', $columns);
        $this->assertContains('keyword_info_normalized_with_clickstream_search_volume', $columns);
        $this->assertContains('keyword_info_normalized_with_clickstream_is_normalized', $columns);
        $this->assertContains('keyword_info_normalized_with_clickstream_monthly_searches', $columns);
        $this->assertContains('clickstream_keyword_info_search_volume', $columns);
        $this->assertContains('clickstream_keyword_info_last_updated_time', $columns);
        $this->assertContains('clickstream_keyword_info_gender_distribution_female', $columns);
        $this->assertContains('clickstream_keyword_info_gender_distribution_male', $columns);
        $this->assertContains('clickstream_keyword_info_age_distribution_18_24', $columns);
        $this->assertContains('clickstream_keyword_info_age_distribution_25_34', $columns);
        $this->assertContains('clickstream_keyword_info_age_distribution_35_44', $columns);
        $this->assertContains('clickstream_keyword_info_age_distribution_45_54', $columns);
        $this->assertContains('clickstream_keyword_info_age_distribution_55_64', $columns);
        $this->assertContains('clickstream_keyword_info_monthly_searches', $columns);
        $this->assertContains('keyword_properties_se_type', $columns);
        $this->assertContains('keyword_properties_core_keyword', $columns);
        $this->assertContains('keyword_properties_synonym_clustering_algorithm', $columns);
        $this->assertContains('keyword_properties_keyword_difficulty', $columns);
        $this->assertContains('keyword_properties_detected_language', $columns);
        $this->assertContains('keyword_properties_is_another_language', $columns);
        $this->assertContains('serp_info_se_type', $columns);
        $this->assertContains('serp_info_check_url', $columns);
        $this->assertContains('serp_info_serp_item_types', $columns);
        $this->assertContains('serp_info_se_results_count', $columns);
        $this->assertContains('serp_info_last_updated_time', $columns);
        $this->assertContains('serp_info_previous_updated_time', $columns);
        $this->assertContains('avg_backlinks_info_se_type', $columns);
        $this->assertContains('avg_backlinks_info_backlinks', $columns);
        $this->assertContains('avg_backlinks_info_dofollow', $columns);
        $this->assertContains('avg_backlinks_info_referring_pages', $columns);
        $this->assertContains('avg_backlinks_info_referring_domains', $columns);
        $this->assertContains('avg_backlinks_info_referring_main_domains', $columns);
        $this->assertContains('avg_backlinks_info_rank', $columns);
        $this->assertContains('avg_backlinks_info_main_domain_rank', $columns);
        $this->assertContains('avg_backlinks_info_last_updated_time', $columns);
        $this->assertContains('search_intent_info_se_type', $columns);
        $this->assertContains('search_intent_info_main_intent', $columns);
        $this->assertContains('search_intent_info_foreign_intent', $columns);
        $this->assertContains('search_intent_info_last_updated_time', $columns);
        $this->assertContains('related_keywords', $columns);
        $this->assertContains('keyword_difficulty', $columns);
        $this->assertContains('keyword_intent_label', $columns);
        $this->assertContains('keyword_intent_probability', $columns);
        $this->assertContains('secondary_keyword_intents_probability_informational', $columns);
        $this->assertContains('secondary_keyword_intents_probability_navigational', $columns);
        $this->assertContains('secondary_keyword_intents_probability_commercial', $columns);
        $this->assertContains('secondary_keyword_intents_probability_transactional', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
        $this->assertContains('processed_at', $columns);
        $this->assertContains('processed_status', $columns);
        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_labs_google_keyword_research_items_table_respects_drop_existing_parameter(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = $this->testKeywordResearchTable;
        create_dataforseo_labs_google_keyword_research_items_table($schema, $table, true, false);
        \Illuminate\Support\Facades\DB::table($table)->insert([
            'keyword'       => 'test keyword',
            'location_code' => 2840,
            'language_code' => 'en',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        $this->assertEquals(1, \Illuminate\Support\Facades\DB::table($table)->count());
        create_dataforseo_labs_google_keyword_research_items_table($schema, $table, false, false);
        $this->assertEquals(1, \Illuminate\Support\Facades\DB::table($table)->count());
        create_dataforseo_labs_google_keyword_research_items_table($schema, $table, true, false);
        $this->assertEquals(0, \Illuminate\Support\Facades\DB::table($table)->count());
        $schema->dropIfExists($table);
    }
}

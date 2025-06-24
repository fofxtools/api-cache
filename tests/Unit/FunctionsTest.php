<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\ApiCacheServiceProvider;
use FOfX\ApiCache\Tests\TestCase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use FOfX\Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

use function FOfX\ApiCache\check_server_status;
use function FOfX\ApiCache\create_responses_table;
use function FOfX\ApiCache\format_api_response;
use function FOfX\ApiCache\get_tables;
use function FOfX\ApiCache\create_pixabay_images_table;
use function FOfX\ApiCache\normalize_params;
use function FOfX\ApiCache\summarize_params;
use function FOfX\ApiCache\download_public_suffix_list;
use function FOfX\ApiCache\extract_registrable_domain;
use function FOfX\ApiCache\create_errors_table;
use function FOfX\ApiCache\create_dataforseo_serp_google_organic_items_table;
use function FOfX\ApiCache\create_dataforseo_serp_google_organic_paa_items_table;
use function FOfX\ApiCache\create_dataforseo_keywords_data_google_ads_keywords_items_table;

class FunctionsTest extends TestCase
{
    protected string $testResponsesTable = 'api_cache_responses_test';
    protected string $testErrorsTable    = 'api_cache_errors_test';
    protected string $testImagesTable    = 'pixabay_images_test';
    protected string $clientName         = 'demo';
    protected string $apiBaseUrl;
    protected array $mockApiResponse;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        // Append unique ID to test table names
        $this->testResponsesTable .= '_' . uniqid();
        $this->testErrorsTable .= '_' . uniqid();
        $this->testImagesTable .= '_' . uniqid();

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
            'created_at'    => now(),
        ]);

        $this->assertEquals(1, DB::table($this->testErrorsTable)->count());
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

    public function test_create_dataforseo_serp_google_organic_items_table_creates_table(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = 'test_serp_google_organic_items_' . uniqid();
        create_dataforseo_serp_google_organic_items_table($schema, $table, true, true);
        $this->assertTrue($schema->hasTable($table));
        $columns = $schema->getColumnListing($table);
        $this->assertContains('id', $columns);
        $this->assertContains('response_id', $columns);
        $this->assertContains('task_id', $columns);
        $this->assertContains('keyword', $columns);
        $this->assertContains('is_featured_snippet', $columns);
        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_serp_google_organic_items_table_respects_drop_existing_parameter(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = 'test_serp_google_organic_items_' . uniqid();
        create_dataforseo_serp_google_organic_items_table($schema, $table, true, false);
        \Illuminate\Support\Facades\DB::table($table)->insert([
            'keyword'    => 'test-keyword',
            'created_at' => now(),
            'updated_at' => now(),
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
        $table  = 'test_serp_google_organic_paa_items_' . uniqid();
        create_dataforseo_serp_google_organic_paa_items_table($schema, $table, true, true);
        $this->assertTrue($schema->hasTable($table));
        $columns = $schema->getColumnListing($table);
        $this->assertContains('id', $columns);
        $this->assertContains('response_id', $columns);
        $this->assertContains('task_id', $columns);
        $this->assertContains('organic_items_id', $columns);
        $this->assertContains('keyword', $columns);
        $this->assertContains('answer_type', $columns);
        $this->assertContains('answer_images', $columns);
        $this->assertContains('answer_table', $columns);
        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_serp_google_organic_paa_items_table_respects_drop_existing_parameter(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = 'test_serp_google_organic_paa_items_' . uniqid();
        create_dataforseo_serp_google_organic_paa_items_table($schema, $table, true, false);
        \Illuminate\Support\Facades\DB::table($table)->insert([
            'keyword'    => 'test-keyword',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        create_dataforseo_serp_google_organic_paa_items_table($schema, $table, false, false);
        $this->assertEquals(1, \Illuminate\Support\Facades\DB::table($table)->count());
        create_dataforseo_serp_google_organic_paa_items_table($schema, $table, true, false);
        $this->assertEquals(0, \Illuminate\Support\Facades\DB::table($table)->count());
        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_keywords_data_google_ads_keywords_items_table_creates_table(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = 'test_keywords_data_google_ads_keywords_items_' . uniqid();
        create_dataforseo_keywords_data_google_ads_keywords_items_table($schema, $table, true, true);
        $this->assertTrue($schema->hasTable($table));
        $columns = $schema->getColumnListing($table);
        $this->assertContains('id', $columns);
        $this->assertContains('response_id', $columns);
        $this->assertContains('task_id', $columns);
        $this->assertContains('keyword', $columns);
        $this->assertContains('se', $columns);
        $this->assertContains('location_code', $columns);
        $this->assertContains('language_code', $columns);
        $this->assertContains('search_partners', $columns);
        $this->assertContains('competition', $columns);
        $this->assertContains('competition_index', $columns);
        $this->assertContains('search_volume', $columns);
        $this->assertContains('low_top_of_page_bid', $columns);
        $this->assertContains('high_top_of_page_bid', $columns);
        $this->assertContains('cpc', $columns);
        $this->assertContains('monthly_searches', $columns);
        $this->assertContains('bid', $columns);
        $this->assertContains('match', $columns);
        $this->assertContains('impressions', $columns);
        $this->assertContains('ctr', $columns);
        $this->assertContains('average_cpc', $columns);
        $this->assertContains('cost', $columns);
        $this->assertContains('clicks', $columns);
        $this->assertContains('processed_at', $columns);
        $this->assertContains('processed_status', $columns);
        $schema->dropIfExists($table);
    }

    public function test_create_dataforseo_keywords_data_google_ads_keywords_items_table_respects_drop_existing_parameter(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(null);
        $table  = 'test_keywords_data_google_ads_keywords_items_' . uniqid();
        create_dataforseo_keywords_data_google_ads_keywords_items_table($schema, $table, true, false);
        \Illuminate\Support\Facades\DB::table($table)->insert([
            'keyword'    => 'test-keyword',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        create_dataforseo_keywords_data_google_ads_keywords_items_table($schema, $table, false, false);
        $this->assertEquals(1, \Illuminate\Support\Facades\DB::table($table)->count());
        create_dataforseo_keywords_data_google_ads_keywords_items_table($schema, $table, true, false);
        $this->assertEquals(0, \Illuminate\Support\Facades\DB::table($table)->count());
        $schema->dropIfExists($table);
    }
}

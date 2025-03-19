<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\ApiCacheServiceProvider;
use FOfX\ApiCache\Tests\TestCase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use FOfX\Helper;

use function FOfX\ApiCache\check_server_status;
use function FOfX\ApiCache\create_response_table;
use function FOfX\ApiCache\format_api_response;
use function FOfX\ApiCache\get_tables;
use function FOfX\ApiCache\create_pixabay_images_table;

class FunctionsTest extends TestCase
{
    protected string $testTable  = 'api_cache_test_responses';
    protected string $clientName = 'demo';
    protected string $apiBaseUrl;
    protected array $mockApiResponse;

    protected function setUp(): void
    {
        parent::setUp();

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
     * Tests for check_server_status function
     */
    public function test_check_server_status_returns_true_for_healthy_server(): void
    {
        $this->assertTrue(check_server_status($this->apiBaseUrl));
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

    public function test_check_server_status_respects_timeout_parameter(): void
    {
        $timeout = 1;
        $result  = check_server_status($this->apiBaseUrl, $timeout);
        $this->assertTrue($result);
    }

    /**
     * Tests for create_response_table function
     */
    public function test_create_response_table_creates_uncompressed_table(): void
    {
        $schema = Schema::connection(null);
        create_response_table($schema, $this->testTable);

        $this->assertTrue($schema->hasTable($this->testTable));

        // Verify columns
        $columns = $schema->getColumnListing($this->testTable);
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

    public function test_create_response_table_creates_compressed_table(): void
    {
        $schema = Schema::connection(null);
        create_response_table($schema, $this->testTable . '_compressed', true);

        $this->assertTrue($schema->hasTable($this->testTable . '_compressed'));

        // Verify columns
        $columns = $schema->getColumnListing($this->testTable . '_compressed');
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

    public function test_create_response_table_respects_drop_existing_parameter(): void
    {
        $schema = Schema::connection(null);

        // Create table first time
        create_response_table($schema, $this->testTable);

        // Insert a record
        DB::table($this->testTable)->insert([
            'key'      => 'test-key',
            'client'   => 'test-client',
            'endpoint' => 'test-endpoint',
        ]);

        // Create table again without drop
        create_response_table($schema, $this->testTable, false, false);

        // Record should still exist
        $this->assertEquals(1, DB::table($this->testTable)->count());

        // Create table again with drop
        create_response_table($schema, $this->testTable, false, true);

        // Table should be empty
        $this->assertEquals(0, DB::table($this->testTable)->count());
    }

    /**
     * Tests for create_pixabay_images_table function
     */
    public function test_create_pixabay_images_table_creates_table(): void
    {
        $schema    = Schema::connection(null);
        $testTable = 'api_cache_test_pixabay_images';
        create_pixabay_images_table($schema, $testTable);

        $this->assertTrue($schema->hasTable($testTable));

        // Verify essential columns
        $columns = $schema->getColumnListing($testTable);
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
        $schema    = Schema::connection(null);
        $testTable = 'api_cache_test_pixabay_images';

        // Create table first time
        create_pixabay_images_table($schema, $testTable);

        // Insert a record
        DB::table($testTable)->insert([
            'id'      => 123456,
            'pageURL' => 'https://example.com/image',
            'type'    => 'photo',
        ]);

        // Create table again without drop
        create_pixabay_images_table($schema, $testTable, false, false);

        // Record should still exist
        $this->assertEquals(1, DB::table($testTable)->count());

        // Create table again with drop
        create_pixabay_images_table($schema, $testTable, true, false);

        // Table should be empty
        $this->assertEquals(0, DB::table($testTable)->count());
    }

    /**
     * Tests for format_api_response function
     */
    public function test_format_api_response_formats_basic_info(): void
    {
        $output = format_api_response($this->mockApiResponse, false);

        $this->assertStringContainsString('Status code: 200', $output);
        $this->assertStringContainsString('Response time (seconds): 0.5000', $output);
        $this->assertStringContainsString('Response size (bytes): 20', $output);
        $this->assertStringContainsString('Is cached: Yes', $output);
    }

    public function test_format_api_response_formats_verbose_output(): void
    {
        $output = format_api_response($this->mockApiResponse, true);

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

    public function test_format_api_response_handles_plain_text(): void
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

        $output = format_api_response($this->mockApiResponse, true);

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
}

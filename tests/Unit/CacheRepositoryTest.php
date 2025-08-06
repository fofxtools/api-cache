<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\ApiCacheServiceProvider;
use FOfX\ApiCache\CacheRepository;
use FOfX\ApiCache\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class CacheRepositoryTest extends TestCase
{
    protected CacheRepository $repository;

    // Use constant so it can be used in static method data providers
    protected const COMPRESSED_CLIENT    = 'demo';
    protected const UNCOMPRESSED_CLIENT  = 'openai';
    protected string $compressedClient   = self::COMPRESSED_CLIENT;
    protected string $uncompressedClient = self::UNCOMPRESSED_CLIENT;
    protected string $key                = 'test-key';
    protected array $testData;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure compression for different clients
        config()->set("api-cache.apis.{$this->compressedClient}.compression_enabled", true);
        config()->set("api-cache.apis.{$this->uncompressedClient}.compression_enabled", false);

        // Let Laravel handle DI
        $this->repository = app(CacheRepository::class);

        // Test data
        $this->testData = [
            'endpoint'             => '/test',
            'method'               => 'GET',
            'response_body'        => 'This is the response body for the test. It may or may not be stored compressed.',
            'response_headers'     => ['Content-Type' => 'application/json'],
            'response_status_code' => 200,
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

    public function test_getTableName_without_compression(): void
    {
        $tableName = $this->repository->getTableName($this->uncompressedClient);
        $this->assertEquals('api_cache_' . $this->uncompressedClient . '_responses', $tableName);
    }

    public function test_getTableName_with_compression(): void
    {
        $tableName = $this->repository->getTableName($this->compressedClient);
        $this->assertEquals('api_cache_' . $this->compressedClient . '_responses_compressed', $tableName);
    }

    public function test_getTableName_with_compression_override_true(): void
    {
        $tableName = $this->repository->getTableName($this->uncompressedClient, true);
        $this->assertEquals('api_cache_' . $this->uncompressedClient . '_responses_compressed', $tableName);
    }

    public function test_getTableName_with_compression_override_false(): void
    {
        $tableName = $this->repository->getTableName($this->compressedClient, false);
        $this->assertEquals('api_cache_' . $this->compressedClient . '_responses', $tableName);
    }

    public function test_getTableName_with_compression_override_null(): void
    {
        // Should behave the same as without the parameter
        $tableNameWithNull     = $this->repository->getTableName($this->compressedClient, null);
        $tableNameWithoutParam = $this->repository->getTableName($this->compressedClient);
        $this->assertEquals($tableNameWithoutParam, $tableNameWithNull);
        $this->assertEquals('api_cache_' . $this->compressedClient . '_responses_compressed', $tableNameWithNull);
    }

    public static function tableNameVariationsProvider(): array
    {
        return [
            'simple name compressed' => [
                'clientName'   => 'demo',
                'isCompressed' => true,
                'expected'     => 'api_cache_demo_responses_compressed',
            ],
            'simple name uncompressed' => [
                'clientName'   => 'demo',
                'isCompressed' => false,
                'expected'     => 'api_cache_demo_responses',
            ],
            'long name compressed' => [
                'clientName'   => str_repeat('a', 64),
                'isCompressed' => true,
                'expected'     => 'api_cache_' . substr(str_repeat('a', 64), 0, 33) . '_responses_compressed',
            ],
            'long name uncompressed' => [
                'clientName'   => str_repeat('a', 64),
                'isCompressed' => false,
                'expected'     => 'api_cache_' . substr(str_repeat('a', 64), 0, 33) . '_responses',
            ],
            'name with dashes compressed' => [
                'clientName'   => 'data-for-seo',
                'isCompressed' => true,
                'expected'     => 'api_cache_data_for_seo_responses_compressed',
            ],
            'name with dashes uncompressed' => [
                'clientName'   => 'data-for-seo',
                'isCompressed' => false,
                'expected'     => 'api_cache_data_for_seo_responses',
            ],
            'numbers only compressed' => [
                'clientName'   => '1234567890',
                'isCompressed' => true,
                'expected'     => 'api_cache_1234567890_responses_compressed',
            ],
            'numbers only uncompressed' => [
                'clientName'   => '1234567890',
                'isCompressed' => false,
                'expected'     => 'api_cache_1234567890_responses',
            ],
        ];
    }

    #[DataProvider('tableNameVariationsProvider')]
    public function test_get_table_name_handles_variations(
        string $clientName,
        bool $isCompressed,
        string $expected
    ): void {
        // Configure compression for this client
        config()->set("api-cache.apis.{$clientName}.compression_enabled", $isCompressed);

        // Use existing repository
        $this->assertEquals($expected, $this->repository->getTableName($clientName));
    }

    public static function compressionOverrideProvider(): array
    {
        return [
            'uncompressed client with compression override true' => [
                'clientName'          => self::UNCOMPRESSED_CLIENT,
                'compressionOverride' => true,
                'expected'            => 'api_cache_' . self::UNCOMPRESSED_CLIENT . '_responses_compressed',
            ],
            'compressed client with compression override false' => [
                'clientName'          => self::COMPRESSED_CLIENT,
                'compressionOverride' => false,
                'expected'            => 'api_cache_' . self::COMPRESSED_CLIENT . '_responses',
            ],
            'uncompressed client with compression override false' => [
                'clientName'          => self::UNCOMPRESSED_CLIENT,
                'compressionOverride' => false,
                'expected'            => 'api_cache_' . self::UNCOMPRESSED_CLIENT . '_responses',
            ],
            'compressed client with compression override true' => [
                'clientName'          => self::COMPRESSED_CLIENT,
                'compressionOverride' => true,
                'expected'            => 'api_cache_' . self::COMPRESSED_CLIENT . '_responses_compressed',
            ],
        ];
    }

    #[DataProvider('compressionOverrideProvider')]
    public function test_get_table_name_with_compression_override(
        string $clientName,
        bool $compressionOverride,
        string $expected
    ): void {
        $this->assertEquals($expected, $this->repository->getTableName($clientName, $compressionOverride));
    }

    public static function invalidTableNameProvider(): array
    {
        return [
            'name with dots' => [
                'clientName' => 'api.client.v1',
            ],
            'name with spaces' => [
                'clientName' => 'open ai',
            ],
            'chinese characters' => [
                'clientName' => 'chinese-天气-api',
            ],
            'unicode characters' => [
                'clientName' => 'über-api',
            ],
            'special characters' => [
                'clientName' => '!@#$%^&*()',
            ],
            'empty string' => [
                'clientName' => '',
            ],
        ];
    }

    #[DataProvider('invalidTableNameProvider')]
    public function test_get_table_name_throws_exception_for_invalid_names(string $clientName): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repository->getTableName($clientName);
    }

    public function test_prepareHeaders_pretty_prints_json_by_default(): void
    {
        $clientName     = $this->uncompressedClient;
        $headers        = ['Content-Type' => 'application/json', 'Authorization' => 'Bearer token123'];
        $expectedPretty = "{\n    \"Content-Type\": \"application/json\",\n    \"Authorization\": \"Bearer token123\"\n}";

        $result = $this->repository->prepareHeaders($clientName, $headers);

        $this->assertEquals($expectedPretty, $result);
    }

    public function test_prepareHeaders_can_disable_pretty_printing(): void
    {
        $clientName = $this->uncompressedClient;
        $headers    = ['Content-Type' => 'application/json'];

        $result = $this->repository->prepareHeaders($clientName, $headers, null, false);

        // Parse the result as JSON to verify it's valid and contains expected data
        $decodedResult = json_decode($result, true);
        $this->assertEquals($headers, $decodedResult);

        // Verify it's compact (no pretty printing) by checking it doesn't contain newlines
        $this->assertStringNotContainsString("\n", $result);
    }

    public function test_retrieveHeaders_returns_null_for_null_input(): void
    {
        $result = $this->repository->retrieveHeaders($this->uncompressedClient, null);

        $this->assertNull($result);
    }

    public static function clientNamesProvider(): array
    {
        return [
            'uncompressed client' => [self::UNCOMPRESSED_CLIENT],
            'compressed client'   => [self::COMPRESSED_CLIENT],
        ];
    }

    #[DataProvider('clientNamesProvider')]
    public function test_retrieveHeaders_decodes_json_headers_correctly(string $clientName): void
    {
        $headers = ['Content-Type' => 'application/json', 'Authorization' => 'Bearer token123'];

        // Prepare headers using the repository method
        $prepared = $this->repository->prepareHeaders($clientName, $headers);

        // Retrieve them back
        $retrieved = $this->repository->retrieveHeaders($clientName, $prepared);

        $this->assertEquals($headers, $retrieved);
    }

    public function test_retrieveHeaders_throws_JsonException_for_invalid_json(): void
    {
        $this->expectException(\JsonException::class);

        $invalidJson = '{invalid json content';
        $this->repository->retrieveHeaders($this->uncompressedClient, $invalidJson);
    }

    public function test_retrieveHeaders_throws_RuntimeException_for_non_array_result(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Decoded headers must be an array');

        $nonArrayJson = '"just a string"';
        $this->repository->retrieveHeaders($this->uncompressedClient, $nonArrayJson);
    }

    public function test_retrieveHeaders_throws_RuntimeException_for_null_json_result(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Decoded headers must be an array');

        $nullJson = 'null';
        $this->repository->retrieveHeaders($this->uncompressedClient, $nullJson);
    }

    public function test_retrieveHeaders_handles_context_parameter(): void
    {
        $headers  = ['X-Test' => 'value'];
        $prepared = $this->repository->prepareHeaders($this->uncompressedClient, $headers);

        // This should not throw an exception and should work with context
        $retrieved = $this->repository->retrieveHeaders($this->uncompressedClient, $prepared, 'test-context');

        $this->assertEquals($headers, $retrieved);
    }

    public function test_retrieveHeaders_handles_compressed_data(): void
    {
        $headers = ['Content-Type' => 'application/json', 'X-Large-Header' => str_repeat('data', 100)];

        // Prepare with compressed client
        $prepared = $this->repository->prepareHeaders($this->compressedClient, $headers);

        // Retrieve with compressed client
        $retrieved = $this->repository->retrieveHeaders($this->compressedClient, $prepared);

        $this->assertEquals($headers, $retrieved);
    }

    public function test_prepareBody_pretty_prints_json_by_default(): void
    {
        $clientName     = $this->uncompressedClient;
        $jsonData       = '{"name":"test","data":{"value":123,"array":[1,2,3]}}';
        $expectedPretty = "{\n    \"name\": \"test\",\n    \"data\": {\n        \"value\": 123,\n        \"array\": [\n            1,\n            2,\n            3\n        ]\n    }\n}";

        $result = $this->repository->prepareBody($clientName, $jsonData);

        $this->assertEquals($expectedPretty, $result);
    }

    public function test_prepareBody_preserves_non_json_content(): void
    {
        $clientName = $this->uncompressedClient;
        $htmlData   = '<html><body>Hello World</body></html>';

        $result = $this->repository->prepareBody($clientName, $htmlData);

        $this->assertEquals($htmlData, $result);
    }

    public function test_prepareBody_can_disable_pretty_printing(): void
    {
        $clientName = $this->uncompressedClient;
        $jsonData   = '{"name":"test","value":123}';

        $result = $this->repository->prepareBody($clientName, $jsonData, null, false);

        $this->assertEquals($jsonData, $result);
    }

    public function test_retrieveBody_returns_null_for_null_input(): void
    {
        $result = $this->repository->retrieveBody($this->uncompressedClient, null);

        $this->assertNull($result);
    }

    #[DataProvider('clientNamesProvider')]
    public function test_retrieveBody_returns_body_unchanged_for_uncompressed(string $clientName): void
    {
        $body = '{"message": "Hello World", "data": [1, 2, 3]}';

        // Prepare body using the repository method
        $prepared = $this->repository->prepareBody($clientName, $body, null, false); // disable pretty print for exact match

        // Retrieve it back
        $retrieved = $this->repository->retrieveBody($clientName, $prepared);

        $this->assertEquals($body, $retrieved);
    }

    public function test_retrieveBody_handles_compressed_data(): void
    {
        $body = str_repeat('This is a long body content that should compress well. ', 50);

        // Prepare with compressed client
        $prepared = $this->repository->prepareBody($this->compressedClient, $body, null, false);

        // Retrieve with compressed client
        $retrieved = $this->repository->retrieveBody($this->compressedClient, $prepared);

        $this->assertEquals($body, $retrieved);
    }

    public function test_retrieveBody_handles_context_parameter(): void
    {
        $body     = 'Simple text content';
        $prepared = $this->repository->prepareBody($this->uncompressedClient, $body, null, false);

        // This should not throw an exception and should work with context
        $retrieved = $this->repository->retrieveBody($this->uncompressedClient, $prepared, 'test-context');

        $this->assertEquals($body, $retrieved);
    }

    public function test_retrieveBody_preserves_binary_data(): void
    {
        // Create some binary-like data
        $binaryData = chr(0) . chr(255) . chr(128) . 'mixed' . chr(1) . chr(254);

        $prepared  = $this->repository->prepareBody($this->uncompressedClient, $binaryData, null, false);
        $retrieved = $this->repository->retrieveBody($this->uncompressedClient, $prepared);

        $this->assertEquals($binaryData, $retrieved);
    }

    public function test_retrieveBody_handles_large_content(): void
    {
        // Create a large body (1MB)
        $largeBody = str_repeat('Large content data with various characters: áéíóú çñü 中文 🚀 ', 10000);

        $prepared  = $this->repository->prepareBody($this->compressedClient, $largeBody, null, false);
        $retrieved = $this->repository->retrieveBody($this->compressedClient, $prepared);

        $this->assertEquals($largeBody, $retrieved);
    }

    public function test_retrieveBody_handles_empty_string(): void
    {
        $emptyBody = '';

        $prepared  = $this->repository->prepareBody($this->uncompressedClient, $emptyBody, null, false);
        $retrieved = $this->repository->retrieveBody($this->uncompressedClient, $prepared);

        $this->assertEquals($emptyBody, $retrieved);
    }

    public function test_retrieveBody_handles_json_content(): void
    {
        $jsonBody = '{"status":"success","data":{"items":[{"id":1,"name":"test"}],"total":1}}';

        $prepared  = $this->repository->prepareBody($this->uncompressedClient, $jsonBody, null, false);
        $retrieved = $this->repository->retrieveBody($this->uncompressedClient, $prepared);

        $this->assertEquals($jsonBody, $retrieved);
    }

    #[DataProvider('clientNamesProvider')]
    public function test_clearTable_removes_all_data(string $clientName): void
    {
        // Store some test data first
        $this->repository->store($clientName, 'key1', $this->testData);
        $this->repository->store($clientName, 'key2', $this->testData);

        // Verify data exists
        $this->assertNotNull($this->repository->get($clientName, 'key1'));
        $this->assertNotNull($this->repository->get($clientName, 'key2'));
        $this->assertEquals(2, $this->repository->countTotalResponses($clientName));

        // Clear the table
        $this->repository->clearTable($clientName);

        // Verify all data is gone
        $this->assertNull($this->repository->get($clientName, 'key1'));
        $this->assertNull($this->repository->get($clientName, 'key2'));
        $this->assertEquals(0, $this->repository->countTotalResponses($clientName));
    }

    public function test_store_validates_required_fields_without_compression(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $invalidData = [
            'method' => 'GET',
            // Deliberately missing required field 'response_body'
        ];

        $this->repository->store($this->uncompressedClient, $this->key, $invalidData);
    }

    public function test_store_validates_required_fields_with_compression(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $invalidData = [
            'method' => 'GET',
            // Deliberately missing required field 'response_body'
        ];

        $this->repository->store($this->compressedClient, $this->key, $invalidData);
    }

    #[DataProvider('clientNamesProvider')]
    public function test_get_respects_ttl(string $clientName): void
    {
        $this->repository->store($clientName, $this->key, $this->testData, 1);

        usleep(1100000);

        $this->assertNull($this->repository->get($clientName, $this->key));
    }

    #[DataProvider('clientNamesProvider')]
    public function test_store_and_get_roundtrip(string $clientName): void
    {
        $this->repository->store($clientName, $this->key, $this->testData);
        $retrieved = $this->repository->get($clientName, $this->key);

        $this->assertNotNull($retrieved);
        $this->assertEquals($this->testData['endpoint'], $retrieved['endpoint']);
        $this->assertEquals($this->testData['response_body'], $retrieved['response_body']);
        $this->assertEquals($this->testData['method'], $retrieved['method']);
    }

    /**
     * Test countTotalResponses returns correct count with no records
     */
    public function test_countTotalResponses_returns_zero_for_empty_table(): void
    {
        $count = $this->repository->countTotalResponses($this->uncompressedClient);

        $this->assertEquals(0, $count);
    }

    /**
     * Test countTotalResponses returns correct count with multiple records
     */
    public function test_countTotalResponses_returns_correct_count(): void
    {
        // Store some test data
        $metadata = [
            'endpoint'             => '/test',
            'response_body'        => 'Test response body',
            'response_status_code' => 200,
        ];

        // Store multiple records
        $this->repository->store($this->uncompressedClient, 'key1', $metadata);
        $this->repository->store($this->uncompressedClient, 'key2', $metadata);
        $this->repository->store($this->uncompressedClient, 'key3', $metadata);

        $count = $this->repository->countTotalResponses($this->uncompressedClient);

        $this->assertEquals(3, $count);
    }

    /**
     * Test countTotalResponses works with compressed tables
     */
    public function test_countTotalResponses_works_with_compressed_table(): void
    {
        $metadata = [
            'endpoint'             => '/test',
            'response_body'        => 'Test response body',
            'response_status_code' => 200,
        ];

        // Store in compressed table
        $this->repository->store($this->compressedClient, 'key1', $metadata);
        $this->repository->store($this->compressedClient, 'key2', $metadata);

        $count = $this->repository->countTotalResponses($this->compressedClient);

        $this->assertEquals(2, $count);
    }

    /**
     * Test countTotalResponses includes expired records
     */
    public function test_countTotalResponses_includes_expired_records(): void
    {
        $metadata = [
            'endpoint'             => '/test',
            'response_body'        => 'Test response body',
            'response_status_code' => 200,
        ];

        // Store one regular record
        $this->repository->store($this->uncompressedClient, 'active-key', $metadata);

        // Store one expired record
        $this->repository->store($this->uncompressedClient, 'expired-key', $metadata, 1);

        // Wait for expiry
        sleep(2);

        $count = $this->repository->countTotalResponses($this->uncompressedClient);

        // Should count both active and expired records
        $this->assertEquals(2, $count);
    }

    /**
     * Test countActiveResponses returns only non-expired records
     */
    public function test_countActiveResponses_returns_only_non_expired_records(): void
    {
        $metadata = [
            'endpoint'             => '/test',
            'response_body'        => 'Test response body',
            'response_status_code' => 200,
        ];

        // Store one regular record
        $this->repository->store($this->uncompressedClient, 'active-key', $metadata);

        // Store one expired record
        $this->repository->store($this->uncompressedClient, 'expired-key', $metadata, 1);

        // Wait for expiry
        sleep(2);

        $count = $this->repository->countActiveResponses($this->uncompressedClient);

        // Should only count active record
        $this->assertEquals(1, $count);
    }

    /**
     * Test countExpiredResponses returns only expired records
     */
    public function test_countExpiredResponses_returns_only_expired_records(): void
    {
        $metadata = [
            'endpoint'             => '/test',
            'response_body'        => 'Test response body',
            'response_status_code' => 200,
        ];

        // Store one regular record
        $this->repository->store($this->uncompressedClient, 'active-key', $metadata);

        // Store one expired record
        $this->repository->store($this->uncompressedClient, 'expired-key', $metadata, 1);

        // Wait for expiry
        sleep(2);

        $count = $this->repository->countExpiredResponses($this->uncompressedClient);

        // Should only count expired record
        $this->assertEquals(1, $count);
    }

    #[DataProvider('clientNamesProvider')]
    public function test_deleteExpired_removes_expired_data(string $clientName): void
    {
        $ttl = 2;
        // Store data with TTL
        $this->repository->store($clientName, $this->key, $this->testData, $ttl);

        // Verify row exists and is active before expiry
        $this->assertEquals(
            1,
            $this->repository->countTotalResponses($clientName),
            'Should have one total response before expiry'
        );
        $this->assertEquals(
            1,
            $this->repository->countActiveResponses($clientName),
            'Response should be active before expiry'
        );
        $this->assertEquals(
            0,
            $this->repository->countExpiredResponses($clientName),
            'Should have no expired responses before expiry'
        );

        // Wait for expiry with buffer
        usleep($ttl * 1000000 + 100000);

        // Verify row is now expired but still exists
        $this->assertEquals(
            1,
            $this->repository->countTotalResponses($clientName),
            'Should still have one total response after expiry'
        );
        $this->assertEquals(
            0,
            $this->repository->countActiveResponses($clientName),
            'Should have no active responses after expiry'
        );
        $this->assertEquals(
            1,
            $this->repository->countExpiredResponses($clientName),
            'Response should be expired after expiry'
        );

        // Run deleteExpired
        $this->repository->deleteExpired($clientName);

        // Verify row was actually deleted
        $this->assertEquals(
            0,
            $this->repository->countTotalResponses($clientName),
            'Should have no responses after deleteExpired'
        );
        $this->assertEquals(
            0,
            $this->repository->countActiveResponses($clientName),
            'Should have no active responses after deleteExpired'
        );
        $this->assertEquals(
            0,
            $this->repository->countExpiredResponses($clientName),
            'Should have no expired responses after deleteExpired'
        );
    }
}

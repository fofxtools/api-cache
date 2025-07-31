<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Pdp\Rules;
use Pdp\Domain;
use FOfX\Helper;

/**
 * Check if a server is accessible and healthy
 *
 * @param string $baseUrl The base URL to check
 * @param int    $timeout Timeout in seconds
 *
 * @return bool True if server is accessible and healthy
 */
function check_server_status(string $baseUrl, int $timeout = 2): bool
{
    $healthCheckUrl = $baseUrl . '/health';

    $ch = curl_init($healthCheckUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
    ]);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    Log::debug('Server status checked', [
        'healthCheckUrl' => $healthCheckUrl,
        'timeout'        => $timeout,
        'result'         => $response,
        'error'          => $error,
    ]);

    if ($response === false) {
        return false;
    }

    // Try to decode response as JSON
    $data = json_decode($response, true);

    if (isset($data['status']) && $data['status'] === 'OK') {
        return true;
    }

    return false;
}

/**
 * Resolve the cache manager instance
 *
 * @param ApiCacheManager|null $cacheManager Optional cache manager instance
 *
 * @return ApiCacheManager The resolved cache manager
 */
function resolve_cache_manager(?ApiCacheManager $cacheManager = null): ApiCacheManager
{
    if ($cacheManager !== null) {
        return $cacheManager;
    }

    // Instead of using factory, resolve from container
    return app(ApiCacheManager::class);
}

/**
 * Create responses table for testing
 *
 * @param Builder $schema       Schema builder instance
 * @param string  $table        Table name
 * @param bool    $compressed   Whether to create compressed table
 * @param bool    $dropExisting Whether to drop existing table
 * @param bool    $verify       Whether to verify table structure
 *
 * @throws \RuntimeException When table creation fails
 */
function create_responses_table(
    Builder $schema,
    string $table,
    bool $compressed = false,
    bool $dropExisting = false,
    bool $verify = false
): void {
    if ($dropExisting && $schema->hasTable($table)) {
        Log::debug('Dropping existing table', [
            'table' => $table,
        ]);

        $schema->dropIfExists($table);
    }

    $driver = $schema->getConnection()->getDriverName();

    // Create table if it doesn't exist
    if (!$schema->hasTable($table)) {
        Log::debug('Creating response table for testing', [
            'table'      => $table,
            'compressed' => $compressed,
        ]);

        $schema->create($table, function (Blueprint $table) use ($compressed, $driver) {
            $table->id();
            $table->string('key')->unique();
            $table->string('client');
            $table->string('version')->nullable();
            $table->string('endpoint')->nullable();
            $table->string('base_url')->nullable();
            $table->text('full_url')->nullable();
            $table->string('method')->nullable();
            $table->string('attributes')->nullable();
            $table->integer('credits')->nullable();
            $table->float('cost')->nullable();
            $table->text('request_params_summary')->nullable();

            // Use binary/blob for compressed tables
            if ($compressed) {
                $table->binary('request_headers')->nullable();
                $table->binary('request_body')->nullable();
                $table->binary('response_headers')->nullable();
                $table->binary('response_body')->nullable();
            } else {
                $table->mediumText('request_headers')->nullable();
                $table->mediumText('request_body')->nullable();
                $table->mediumText('response_headers')->nullable();
                $table->longText('response_body')->nullable();
            }

            $table->integer('response_status_code');
            $table->integer('response_size')->nullable();
            $table->double('response_time')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->timestamp('processed_at')->nullable();
            $table->text('processed_status')->nullable();

            // Add indexes for better performance
            // MySQL (64) and PostgreSQL (63) have character limits for index names, so we manually set them.
            // For SQLite, we let Laravel auto-generate unique names since index names must be unique across all tables.
            if ($driver === 'mysql' || $driver === 'pgsql') {
                $table->index(['client', 'endpoint', 'version'], 'client_endpoint_version_idx');
            } else {
                $table->index(['client', 'endpoint', 'version']);
            }
            $table->index('attributes');
            $table->index('credits');
            $table->index('cost');
            $table->index('expires_at');
            $table->index('processed_at');
        });

        // For compressed tables, modify column types based on database driver
        if ($compressed) {
            if ($driver === 'mysql') {
                Log::debug('Altering table columns for MySQL MEDIUMBLOB', [
                    'table' => $table,
                ]);

                $schema->getConnection()->statement("
                    ALTER TABLE {$table}
                    MODIFY request_headers MEDIUMBLOB,
                    MODIFY request_body MEDIUMBLOB,
                    MODIFY response_headers MEDIUMBLOB,
                    MODIFY response_body LONGBLOB
                ");
            } elseif ($driver === 'sqlsrv') {
                Log::debug('Altering table columns for SQL Server VARBINARY(MAX)', [
                    'table' => $table,
                ]);

                $schema->getConnection()->statement("
                    ALTER TABLE {$table}
                    ALTER COLUMN request_headers VARBINARY(MAX),
                    ALTER COLUMN request_body VARBINARY(MAX),
                    ALTER COLUMN response_headers VARBINARY(MAX),
                    ALTER COLUMN response_body VARBINARY(MAX)
                ");
            }
        }

        Log::debug('Response table created successfully', [
            'table'      => $table,
            'compressed' => $compressed,
        ]);
    }

    // Verify table structure if requested
    if ($verify) {
        if (!$schema->hasTable($table)) {
            throw new \RuntimeException("Table {$table} was not created successfully");
        }

        // Get table structure including indexes
        $pdo    = $schema->getConnection()->getPdo();
        $driver = $schema->getConnection()->getDriverName();

        $tableInfo = [];
        $indexInfo = [];

        if ($driver === 'mysql') {
            $result    = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
            $tableInfo = $result['Create Table'] ?? null;
        } elseif ($driver === 'sqlite') {
            $tableInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetch(\PDO::FETCH_ASSOC);
            $indexInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='{$table}'")->fetchAll(\PDO::FETCH_COLUMN);
        }

        Log::debug('Table verified', [
            'table'      => $table,
            'compressed' => $compressed,
            'structure'  => $tableInfo,
            'indexes'    => $indexInfo,
        ]);
    }
}

/**
 * Create Pixabay images table for testing
 *
 * @param Builder $schema       Schema builder instance
 * @param string  $table        Table name
 * @param bool    $dropExisting Whether to drop existing table
 * @param bool    $verify       Whether to verify table structure
 *
 * @throws \RuntimeException When table creation fails
 */
function create_pixabay_images_table(
    Builder $schema,
    string $table = 'pixabay_images',
    bool $dropExisting = false,
    bool $verify = false
): void {
    if ($dropExisting && $schema->hasTable($table)) {
        Log::debug('Dropping existing Pixabay images table', [
            'table' => $table,
        ]);

        $schema->dropIfExists($table);
    }

    $driver = $schema->getConnection()->getDriverName();

    // Create table if it doesn't exist
    if (!$schema->hasTable($table)) {
        Log::debug('Creating Pixabay images table for testing', [
            'table' => $table,
        ]);

        $schema->create($table, function (Blueprint $table) use ($driver) {
            // Primary field to auto increment the row number
            $table->id('row_id');

            // The Pixabay API response ID
            $table->unsignedBigInteger('id')->unique();

            // Other API response fields
            $table->string('pageURL')->nullable();
            $table->string('type')->nullable()->index();
            $table->text('tags')->nullable();

            // Preview image data
            $table->string('previewURL')->nullable();
            $table->unsignedInteger('previewWidth')->nullable();
            $table->unsignedInteger('previewHeight')->nullable();

            // Web format image data
            $table->string('webformatURL')->nullable();
            $table->unsignedInteger('webformatWidth')->nullable();
            $table->unsignedInteger('webformatHeight')->nullable();

            // Large image data
            $table->string('largeImageURL')->nullable();

            // Full API access fields
            $table->string('fullHDURL')->nullable();
            $table->string('imageURL')->nullable();
            $table->string('vectorURL')->nullable();

            // Image dimensions and size
            $table->unsignedInteger('imageWidth')->nullable();
            $table->unsignedInteger('imageHeight')->nullable();
            $table->unsignedInteger('imageSize')->nullable()->index();

            // Statistics
            $table->unsignedBigInteger('views')->nullable()->index();
            $table->unsignedBigInteger('downloads')->nullable()->index();
            $table->unsignedBigInteger('collections')->nullable()->index();
            $table->unsignedBigInteger('likes')->nullable()->index();
            $table->unsignedBigInteger('comments')->nullable()->index();

            // User information
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user')->nullable()->index();
            $table->string('userImageURL')->nullable();

            // Local storage fields
            $table->binary('file_contents_preview')->nullable();
            $table->binary('file_contents_webformat')->nullable();
            $table->binary('file_contents_largeImage')->nullable();

            // File metadata
            $table->unsignedInteger('filesize_preview')->nullable()->index();
            $table->unsignedInteger('filesize_webformat')->nullable()->index();
            $table->unsignedInteger('filesize_largeImage')->nullable()->index();

            // Local storage paths
            $table->string('storage_filepath_preview')->nullable();
            $table->string('storage_filepath_webformat')->nullable();
            $table->string('storage_filepath_largeImage')->nullable();

            // Timestamps
            $table->timestamps();

            // Processing information
            $table->timestamp('processed_at')->nullable()->index();
            $table->text('processed_status')->nullable();

            // Add fulltext indexes
            if ($driver === 'mysql' || $driver === 'pgsql') {
                $table->fullText('tags');
            }
        });

        // Modify binary columns based on database driver
        if ($driver === 'mysql') {
            Log::debug('Altering table columns for MySQL MEDIUMBLOB/LONGBLOB', [
                'table' => $table,
            ]);

            $schema->getConnection()->statement("
                ALTER TABLE {$table}
                MODIFY file_contents_preview MEDIUMBLOB,
                MODIFY file_contents_webformat MEDIUMBLOB,
                MODIFY file_contents_largeImage LONGBLOB
            ");
        } elseif ($driver === 'sqlsrv') {
            Log::debug('Altering table columns for SQL Server VARBINARY(MAX)', [
                'table' => $table,
            ]);

            $schema->getConnection()->statement("
                ALTER TABLE {$table}
                ALTER COLUMN file_contents_preview VARBINARY(MAX),
                ALTER COLUMN file_contents_webformat VARBINARY(MAX),
                ALTER COLUMN file_contents_largeImage VARBINARY(MAX)
            ");
        }

        Log::debug('Pixabay images table created successfully', [
            'table' => $table,
        ]);
    }

    // Verify table structure if requested
    if ($verify) {
        if (!$schema->hasTable($table)) {
            throw new \RuntimeException("Table {$table} was not created successfully");
        }

        // Get table structure including indexes
        $pdo    = $schema->getConnection()->getPdo();
        $driver = $schema->getConnection()->getDriverName();

        $tableInfo = [];
        $indexInfo = [];

        if ($driver === 'mysql') {
            $result    = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
            $tableInfo = $result['Create Table'] ?? null;
        } elseif ($driver === 'sqlite') {
            $tableInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetch(\PDO::FETCH_ASSOC);
            $indexInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='{$table}'")->fetchAll(\PDO::FETCH_COLUMN);
        }

        Log::debug('Table verified', [
            'table'     => $table,
            'structure' => $tableInfo,
            'indexes'   => $indexInfo,
        ]);
    }
}

/**
 * Create errors table for API error logging
 *
 * @param Builder $schema       Schema builder instance
 * @param string  $table        Table name
 * @param bool    $dropExisting Whether to drop existing table
 * @param bool    $verify       Whether to verify table structure
 *
 * @throws \RuntimeException When table creation fails
 */
function create_errors_table(
    Builder $schema,
    string $table = 'api_cache_errors',
    bool $dropExisting = false,
    bool $verify = false
): void {
    if ($dropExisting && $schema->hasTable($table)) {
        Log::debug('Dropping existing errors table', [
            'table' => $table,
        ]);

        $schema->dropIfExists($table);
    }

    // Create table if it doesn't exist
    if (!$schema->hasTable($table)) {
        Log::debug('Creating errors table for API error logging', [
            'table' => $table,
        ]);

        $schema->create($table, function (Blueprint $table) {
            $table->id();
            $table->string('api_client')->index();      // API client name
            $table->string('error_type')->index();      // http_error, cache_rejected, etc.
            $table->string('log_level')->index();                // error, warning, info, etc.
            $table->text('error_message')->nullable();              // Error message
            $table->string('api_message')->nullable();  // API-specific error message
            $table->text('response_preview')->nullable(); // First 500 chars of response
            $table->text('context_data')->nullable();   // Additional context
            $table->timestamps();
        });

        Log::debug('Errors table created successfully', [
            'table' => $table,
        ]);
    }

    // Verify table structure if requested
    if ($verify) {
        if (!$schema->hasTable($table)) {
            throw new \RuntimeException("Table {$table} was not created successfully");
        }

        // Get table structure including indexes
        $pdo    = $schema->getConnection()->getPdo();
        $driver = $schema->getConnection()->getDriverName();

        $tableInfo = [];
        $indexInfo = [];

        if ($driver === 'mysql') {
            $result    = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
            $tableInfo = $result['Create Table'] ?? null;
        } elseif ($driver === 'sqlite') {
            $tableInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetch(\PDO::FETCH_ASSOC);
            $indexInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='{$table}'")->fetchAll(\PDO::FETCH_COLUMN);
        }

        Log::debug('Table verified', [
            'table'     => $table,
            'structure' => $tableInfo,
            'indexes'   => $indexInfo,
        ]);
    }
}

/**
 * Normalize parameters for consistent cache keys.
 *
 * Rules:
 *  - Remove null values
 *  - Recursively handle arrays (keeping empty arrays in case they matter)
 *  - Sort keys for consistent ordering
 *  - Forbid objects/resources (throw exception)
 *  - Include a depth check to prevent infinite recursion
 *
 * @param array $params Parameters to normalize
 * @param int   $depth  Current recursion depth
 *
 * @throws \InvalidArgumentException When encountering unsupported types or exceeding max depth
 *
 * @return array Normalized parameters
 */
function normalize_params(array $params, int $depth = 0): array
{
    $maxDepth = 20;

    if ($depth > $maxDepth) {
        throw new \InvalidArgumentException(
            "Maximum recursion depth ({$maxDepth}) exceeded in parameters: {$depth}"
        );
    }

    // Filter out nulls first
    $filtered = [];
    foreach ($params as $key => $value) {
        if ($value !== null) {
            $filtered[$key] = $value;
        }
    }

    // Sort keys for stable ordering
    ksort($filtered);

    $normalized = [];
    foreach ($filtered as $key => $value) {
        if (is_array($value)) {
            // Recurse
            $normalized[$key] = normalize_params($value, $depth + 1);
        } elseif (is_scalar($value)) {
            // Keep scalars as-is: bool, int, float, string
            $normalized[$key] = $value;
        } else {
            // Throw on objects, resources, closures, etc.
            $type = gettype($value);
            Log::warning('Unsupported parameter type', [
                'type' => $type,
                'key'  => $key,
            ]);

            throw new \InvalidArgumentException("Unsupported parameter type: {$type}");
        }
    }

    return $normalized;
}

/**
 * Summarize parameters by truncating each parameter to a configurable character limit. Return as JSON key-value pairs.
 * Arrays will be single-line JSON, truncated at the specified character limit.
 *
 * @param array $params           Parameters to summarize
 * @param bool  $normalize        Whether to normalize the parameters first (optional)
 * @param bool  $prettyPrint      Whether to pretty print the JSON (optional)
 * @param int   $characterLimit   The character limit for truncating values (default: 100)
 * @param bool  $detectTaskArrays Whether to detect and flatten single-element task arrays (default: true)
 *
 * @throws \InvalidArgumentException If JSON encoding fails
 *
 * @return string Summarized parameters as JSON string with proper key-value structure
 */
function summarize_params(array $params, bool $normalize = true, bool $prettyPrint = true, int $characterLimit = 100, bool $detectTaskArrays = true): string
{
    // Detect single-element numeric array containing parameters (e.g., DataForSEO task arrays)
    if ($detectTaskArrays && count($params) === 1 && isset($params[0]) && is_array($params[0])) {
        $params = $params[0];
    }

    // Optionally normalize (remove nulls, sort keys, etc.)
    if ($normalize) {
        $params = normalize_params($params);
    }

    $summary = [];

    foreach ($params as $key => $value) {
        if (is_array($value)) {
            // For arrays, convert to single-line JSON then truncate
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // Throw an exception if the array cannot be encoded to JSON
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('Failed to encode array to JSON.');
            }

            // Truncate the JSON string to specified character limit
            $summary[$key] = mb_substr($json, 0, $characterLimit);

            // If the JSON string is longer than the character limit, add an ellipsis, closing quote, and a closing brace
            if (mb_strlen($json) > $characterLimit) {
                $summary[$key] .= '..."}';
            }
        } elseif (is_string($value)) {
            // Truncate strings only
            $summary[$key] = mb_substr($value, 0, $characterLimit);

            // If the string is longer than the character limit, add an ellipsis
            if (mb_strlen($value) > $characterLimit) {
                $summary[$key] .= '...';
            }
        } else {
            // Leave numeric, boolean, or null values untouched to preserve types
            $summary[$key] = $value;
        }
    }

    // Convert the final summary array to JSON with proper key-value structure
    $options = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if ($prettyPrint) {
        $options |= JSON_PRETTY_PRINT;
    }

    $encoded = json_encode($summary, $options);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \InvalidArgumentException('Failed to encode summary as JSON: ' . json_last_error_msg());
    }

    return $encoded;
}

/**
 * Format an API response in a clean, readable way
 *
 * @param array $result       The API response array from BaseApiClient or its children
 * @param bool  $requestInfo  Whether to include detailed information like headers and request details
 * @param bool  $responseInfo Whether to include detailed information like headers and response body
 *
 * @return string Formatted response details
 */
function format_api_response(array $result, bool $requestInfo = false, bool $responseInfo = true): string
{
    $output = [];

    // Show N/A if the value is null
    $statusCodeString   = $result['response_status_code'] ?? 'N/A';
    $responseTimeString = array_key_exists('response_time', $result) && $result['response_time'] !== null
        ? number_format($result['response_time'], 4)
        : 'N/A';
    $responseSizeString = $result['response_size'] ?? 'N/A';
    $isCachedString     = array_key_exists('is_cached', $result) && $result['is_cached'] !== null
        ? ($result['is_cached'] ? 'Yes' : 'No')
        : 'N/A';

    // Basic info (always shown)
    $output[] = 'Status code: ' . $statusCodeString;
    $output[] = 'Response time (seconds): ' . $responseTimeString;
    $output[] = 'Response size (bytes): ' . $responseSizeString;
    $output[] = 'Is cached: ' . $isCachedString;

    // Detailed request info (only if requestInfo is true)
    if ($requestInfo) {
        // Request details
        if (isset($result['request'])) {
            $output[] = "\nRequest details:";
            $output[] = 'URL: ' . ($result['request']['full_url'] ?? 'N/A');
            $output[] = 'Method: ' . ($result['request']['method'] ?? 'N/A');

            if (!empty($result['request']['headers'])) {
                $output[] = "\nRequest headers:";
                foreach ($result['request']['headers'] as $key => $value) {
                    $output[] = "$key: " . (is_array($value) ? implode(', ', $value) : $value);
                }
            }

            $output[] = "\nRequest body:";
            if (!empty($result['request']['body'])) {
                $body = $result['request']['body'];

                if (json_validate($body)) {
                    $decodedRequestBody = json_decode($body);
                    $encodedRequestBody = json_encode($decodedRequestBody, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $output[]           = $encodedRequestBody;
                } else {
                    $output[] = $body;
                }
            } else {
                $output[] = 'N/A';
            }
        }
    }

    // Detailed response info (only if responseInfo is true)
    if ($responseInfo && isset($result['response']) && ($result['response'] instanceof \Illuminate\Http\Client\Response)) {
        // Response headers
        $output[] = "\nResponse headers:";
        foreach ($result['response']->headers() as $key => $values) {
            $output[] = "$key: " . implode(', ', (array) $values);
        }

        // Response body
        $output[] = "\nResponse body:";
        $body     = $result['response']->body();

        if (json_validate($body)) {
            $decodedResponseBody = json_decode($body);
            $encodedResponseBody = json_encode(
                $decodedResponseBody,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            $output[] = $encodedResponseBody;
        } else {
            $output[] = $body;
        }
    }

    // Add leading and trailing newlines for cleaner output formatting
    return "\n" . implode("\n", $output) . "\n";
}

/**
 * List all tables in the database in driver agnostic way
 *
 * @throws \Exception If the database driver is not supported
 *
 * @return array List of table names
 */
function get_tables(): array
{
    $connection = DB::connection();
    $driver     = $connection->getDriverName();

    switch ($driver) {
        case 'sqlite':
            $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");

            return array_map(fn ($table) => $table->name, $tables);
        case 'mysql':
            $tables = DB::select('SHOW TABLES');

            return array_map(fn ($table) => array_values((array) $table)[0], $tables);
        case 'pgsql':
            $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public'");

            return array_map(fn ($table) => $table->tablename, $tables);
        case 'sqlsrv':
            $tables = DB::select("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'");

            return array_map(fn ($table) => $table->TABLE_NAME, $tables);
        default:
            throw new \Exception("Unsupported database driver: $driver");
    }
}

/**
 * Downloads the public suffix list for jeremykendall/php-domain-parser if it doesn't exist.
 *
 * @throws \RuntimeException If the file cannot be downloaded or saved
 *
 * @return string The path to the public suffix list file
 */
function download_public_suffix_list(): string
{
    $path = storage_path('app/public_suffix_list.dat');

    if (file_exists($path)) {
        return $path;
    }

    $response = Http::get('https://publicsuffix.org/list/public_suffix_list.dat');

    if (!$response->successful()) {
        throw new \RuntimeException('Failed to download public suffix list');
    }

    if (!file_put_contents($path, $response->body())) {
        throw new \RuntimeException('Failed to save public suffix list');
    }

    return $path;
}

/**
 * Extract the registrable domain from a URL
 *
 * @param string $url      The URL to extract domain from
 * @param bool   $stripWww Whether to strip www prefix (default: true)
 *
 * @return string The registrable domain
 */
function extract_registrable_domain(string $url, bool $stripWww = true): string
{
    // Use php-domain-parser to get the registrable domain
    $pslPath          = download_public_suffix_list();
    $publicSuffixList = Rules::fromPath($pslPath);

    // Extract hostname from URL if it contains a protocol
    $hostname = parse_url($url, PHP_URL_HOST) ?? $url;
    $domain   = Domain::fromIDNA2008($hostname);

    // Use registrableDomain() to get the registrable domain
    $result            = $publicSuffixList->resolve($domain);
    $registrableDomain = $result->registrableDomain()->toString();

    // Strip the www if requested
    if ($stripWww) {
        $registrableDomain = Helper\strip_www($registrableDomain);
    }

    return $registrableDomain;
}

/**
 * Generate a UUID version 4
 *
 * @param string|null $data The data to use to generate the UUID. If null, random data will be used.
 *
 * @return string The UUID
 *
 * @see https://www.uuidgenerator.net/dev-corner/php
 */
function uuid_v4(?string $data = null): string
{
    // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
    $data = $data ?? random_bytes(16);

    if (strlen($data) !== 16) {
        throw new \InvalidArgumentException('UUID data must be exactly 16 bytes.');
    }

    // Set version to 0100
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    // Set bits 6-7 to 10
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    // Output the 36 character UUID.
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Create DataForSEO SERP Google Organic Listings table
 *
 * @param Builder $schema       Schema builder instance
 * @param string  $table        Table name
 * @param bool    $dropExisting Whether to drop existing table
 * @param bool    $verify       Whether to verify table structure
 *
 * @throws \RuntimeException When table creation fails
 */
function create_dataforseo_serp_google_organic_listings_table(
    Builder $schema,
    string $table = 'dataforseo_serp_google_organic_listings',
    bool $dropExisting = false,
    bool $verify = false
): void {
    if ($dropExisting && $schema->hasTable($table)) {
        Log::debug('Dropping existing DataForSEO SERP Google Organic Listings table', [
            'table' => $table,
        ]);
        $schema->dropIfExists($table);
    }

    $driver = $schema->getConnection()->getDriverName();

    if (!$schema->hasTable($table)) {
        Log::debug('Creating DataForSEO SERP Google Organic Listings table', [
            'table' => $table,
        ]);

        $schema->create($table, function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('response_id')->nullable()->index();
            $table->string('task_id')->nullable()->index();

            // Fields passed
            $table->string('keyword')->index(); // From tasks.data.keyword
            $table->string('se')->nullable()->index();
            $table->string('se_type')->index();
            $table->integer('location_code')->index();
            $table->string('language_code', 20)->index();
            $table->string('device', 20)->index();
            $table->string('os')->nullable()->index();
            $table->string('tag')->nullable()->index();

            // Fields returned
            $table->string('result_keyword')->nullable()->index(); // From tasks.result.keyword
            $table->string('type')->nullable()->index();
            $table->string('se_domain')->nullable()->index();
            $table->string('check_url')->nullable()->index();
            $table->string('result_datetime')->nullable()->index(); // From tasks.result.datetime
            $table->text('spell')->nullable(); // JSON, should be pretty printed
            $table->text('refinement_chips')->nullable(); // JSON, should be pretty printed
            $table->text('item_types')->nullable(); // JSON, should be pretty printed
            $table->unsignedBigInteger('se_results_count')->nullable()->index();
            $table->integer('items_count')->nullable()->index();

            $table->timestamps();
            $table->timestamp('processed_at')->nullable()->index();
            $table->text('processed_status')->nullable();

            // Add unique index for keyword, location_code, language_code, device
            // MySQL (64) and PostgreSQL (63) have character limits for index names, so we manually set them.
            // For SQLite, we let Laravel auto-generate unique names since index names must be unique across all tables.
            if ($driver === 'mysql' || $driver === 'pgsql') {
                $table->unique(['keyword', 'location_code', 'language_code', 'device'], 'dsgol_keyword_location_language_device_unique');
            } else {
                $table->unique(['keyword', 'location_code', 'language_code', 'device']);
            }
        });

        Log::debug('DataForSEO SERP Google Organic Listings table created successfully', [
            'table' => $table,
        ]);
    }

    // Verify table structure if requested
    if ($verify) {
        if (!$schema->hasTable($table)) {
            throw new \RuntimeException("Table {$table} was not created successfully");
        }

        $pdo       = $schema->getConnection()->getPdo();
        $driver    = $schema->getConnection()->getDriverName();
        $tableInfo = [];
        $indexInfo = [];

        if ($driver === 'mysql') {
            $result    = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
            $tableInfo = $result['Create Table'] ?? null;
        } elseif ($driver === 'sqlite') {
            $tableInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetch(\PDO::FETCH_ASSOC);
            $indexInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='{$table}'")->fetchAll(\PDO::FETCH_COLUMN);
        }

        Log::debug('Table verified', [
            'table'     => $table,
            'structure' => $tableInfo,
            'indexes'   => $indexInfo,
        ]);
    }
}

/**
 * Create DataForSEO SERP Google Organic Items table
 *
 * @param Builder $schema       Schema builder instance
 * @param string  $table        Table name
 * @param bool    $dropExisting Whether to drop existing table
 * @param bool    $verify       Whether to verify table structure
 *
 * @throws \RuntimeException When table creation fails
 */
function create_dataforseo_serp_google_organic_items_table(
    Builder $schema,
    string $table = 'dataforseo_serp_google_organic_items',
    bool $dropExisting = false,
    bool $verify = false
): void {
    if ($dropExisting && $schema->hasTable($table)) {
        Log::debug('Dropping existing DataForSEO SERP Google Organic Items table', [
            'table' => $table,
        ]);
        $schema->dropIfExists($table);
    }

    $driver = $schema->getConnection()->getDriverName();

    if (!$schema->hasTable($table)) {
        Log::debug('Creating DataForSEO SERP Google Organic Items table', [
            'table' => $table,
        ]);

        $schema->create($table, function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('response_id')->nullable()->index();
            $table->string('task_id')->nullable()->index();

            // Fields passed
            $table->string('keyword')->index();
            $table->integer('location_code')->index();
            $table->string('language_code', 20)->index();
            $table->string('device', 20)->index();
            $table->string('os')->nullable()->index();
            $table->string('tag')->nullable()->index();

            // Fields returned
            $table->string('result_keyword')->nullable()->index(); // From tasks.result.keyword
            $table->string('items_type')->nullable()->index(); // From tasks.result.items.type
            $table->string('se_domain')->nullable()->index();
            $table->integer('rank_group')->nullable()->index();
            $table->integer('rank_absolute')->nullable()->index();
            $table->string('domain')->nullable()->index();
            $table->text('title')->nullable();
            $table->text('description')->nullable();
            $table->text('url')->nullable();
            $table->text('breadcrumb')->nullable();

            // Advanced
            $table->boolean('is_image')->nullable()->index();
            $table->boolean('is_video')->nullable()->index();
            $table->boolean('is_featured_snippet')->nullable()->index();
            $table->boolean('is_malicious')->nullable()->index();
            $table->boolean('is_web_story')->nullable()->index();

            $table->timestamps();
            $table->timestamp('processed_at')->nullable()->index();
            $table->text('processed_status')->nullable();

            // Add unique index for keyword, location_code, language_code, device, rank_absolute
            // MySQL (64) and PostgreSQL (63) have character limits for index names, so we manually set them.
            // For SQLite, we let Laravel auto-generate unique names since index names must be unique across all tables.
            if ($driver === 'mysql' || $driver === 'pgsql') {
                $table->unique(['keyword', 'location_code', 'language_code', 'device', 'rank_absolute'], 'dsgoi_keyword_location_language_device_rankabs_unique');
            } else {
                $table->unique(['keyword', 'location_code', 'language_code', 'device', 'rank_absolute']);
            }
        });

        Log::debug('DataForSEO SERP Google Organic Items table created successfully', [
            'table' => $table,
        ]);
    }

    // Verify table structure if requested
    if ($verify) {
        if (!$schema->hasTable($table)) {
            throw new \RuntimeException("Table {$table} was not created successfully");
        }

        $pdo       = $schema->getConnection()->getPdo();
        $driver    = $schema->getConnection()->getDriverName();
        $tableInfo = [];
        $indexInfo = [];

        if ($driver === 'mysql') {
            $result    = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
            $tableInfo = $result['Create Table'] ?? null;
        } elseif ($driver === 'sqlite') {
            $tableInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetch(\PDO::FETCH_ASSOC);
            $indexInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='{$table}'")->fetchAll(\PDO::FETCH_COLUMN);
        }

        Log::debug('Table verified', [
            'table'     => $table,
            'structure' => $tableInfo,
            'indexes'   => $indexInfo,
        ]);
    }
}

/**
 * Create DataForSEO SERP Google Organic PAA Items table
 *
 * @param Builder $schema       Schema builder instance
 * @param string  $table        Table name
 * @param bool    $dropExisting Whether to drop existing table
 * @param bool    $verify       Whether to verify table structure
 *
 * @throws \RuntimeException When table creation fails
 */
function create_dataforseo_serp_google_organic_paa_items_table(
    Builder $schema,
    string $table = 'dataforseo_serp_google_organic_paa_items',
    bool $dropExisting = false,
    bool $verify = false
): void {
    if ($dropExisting && $schema->hasTable($table)) {
        Log::debug('Dropping existing DataForSEO SERP Google Organic PAA Items table', [
            'table' => $table,
        ]);
        $schema->dropIfExists($table);
    }

    $driver = $schema->getConnection()->getDriverName();

    if (!$schema->hasTable($table)) {
        Log::debug('Creating DataForSEO SERP Google Organic PAA Items table', [
            'table' => $table,
        ]);

        $schema->create($table, function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('response_id')->nullable()->index();
            $table->string('task_id')->nullable()->index();
            $table->unsignedBigInteger('organic_items_id')->nullable()->index();

            // Fields passed
            $table->string('keyword')->index();
            $table->integer('location_code')->index();
            $table->string('language_code', 20)->index();
            $table->string('device', 20)->index();
            $table->string('os')->nullable()->index();
            $table->string('tag')->nullable()->index();

            // Fields returned
            $table->string('result_keyword')->nullable()->index(); // From tasks.result.keyword
            $table->string('se_domain')->nullable()->index();

            // Added (not from API)
            $table->integer('paa_sequence')->index();

            // From people_also_ask_element
            $table->string('type')->nullable()->index();
            $table->text('title')->nullable();
            $table->text('seed_question')->nullable();
            $table->text('xpath')->nullable();

            // From people_also_ask_expanded_element
            $table->string('answer_type')->nullable()->index();
            $table->text('answer_featured_title')->nullable();
            $table->text('answer_url')->nullable();
            $table->string('answer_domain')->nullable();
            $table->text('answer_title')->nullable();
            $table->text('answer_description')->nullable();
            $table->text('answer_images')->nullable();  // JSON, should be pretty printed
            $table->string('answer_timestamp')->nullable();
            $table->text('answer_table')->nullable();  // JSON, should be pretty printed

            $table->timestamps();
            $table->timestamp('processed_at')->nullable()->index();
            $table->text('processed_status')->nullable();

            // Add unique index for keyword, location_code, language_code, device, paa_sequence
            // MySQL (64) and PostgreSQL (63) have character limits for index names, so we manually set them.
            // For SQLite, we let Laravel auto-generate unique names since index names must be unique across all tables.
            if ($driver === 'mysql' || $driver === 'pgsql') {
                $table->unique(['keyword', 'location_code', 'language_code', 'device', 'paa_sequence'], 'dsgopi_keyword_location_language_device_rankabs_unique');
            } else {
                $table->unique(['keyword', 'location_code', 'language_code', 'device', 'paa_sequence']);
            }
        });

        Log::debug('DataForSEO SERP Google Organic PAA Items table created successfully', [
            'table' => $table,
        ]);
    }

    // Verify table structure if requested
    if ($verify) {
        if (!$schema->hasTable($table)) {
            throw new \RuntimeException("Table {$table} was not created successfully");
        }

        $pdo       = $schema->getConnection()->getPdo();
        $driver    = $schema->getConnection()->getDriverName();
        $tableInfo = [];
        $indexInfo = [];

        if ($driver === 'mysql') {
            $result    = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
            $tableInfo = $result['Create Table'] ?? null;
        } elseif ($driver === 'sqlite') {
            $tableInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetch(\PDO::FETCH_ASSOC);
            $indexInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='{$table}'")->fetchAll(\PDO::FETCH_COLUMN);
        }

        Log::debug('Table verified', [
            'table'     => $table,
            'structure' => $tableInfo,
            'indexes'   => $indexInfo,
        ]);
    }
}

/**
 * Create DataForSEO SERP Google Autocomplete Items table
 *
 * @param Builder $schema       Schema builder instance
 * @param string  $table        Table name
 * @param bool    $dropExisting Whether to drop existing table
 * @param bool    $verify       Whether to verify table structure
 *
 * @throws \RuntimeException When table creation fails
 */
function create_dataforseo_serp_google_autocomplete_items_table(
    Builder $schema,
    string $table = 'dataforseo_serp_google_autocomplete_items',
    bool $dropExisting = false,
    bool $verify = false
): void {
    if ($dropExisting && $schema->hasTable($table)) {
        Log::debug('Dropping existing DataForSEO SERP Google Autocomplete Items table', [
            'table' => $table,
        ]);
        $schema->dropIfExists($table);
    }

    $driver = $schema->getConnection()->getDriverName();

    if (!$schema->hasTable($table)) {
        Log::debug('Creating DataForSEO SERP Google Autocomplete Items table', [
            'table' => $table,
        ]);

        $schema->create($table, function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('response_id')->nullable()->index();
            $table->string('task_id')->nullable()->index();

            // Fields passed
            $table->string('keyword')->index();
            // Add default value to avoid null in unique index for optional fields
            $table->integer('cursor_pointer')->default(-1)->index(); // -1 = not specified
            $table->integer('location_code')->index();
            $table->string('language_code', 20)->index();
            $table->string('device', 20)->index();
            $table->string('os')->nullable()->index();
            $table->string('tag')->nullable()->index();

            // Fields returned
            $table->string('result_keyword')->nullable()->index(); // From tasks.result.keyword
            $table->string('type')->nullable()->index();
            $table->string('se_domain')->nullable()->index();
            $table->integer('rank_group')->nullable()->index();
            $table->integer('rank_absolute')->nullable()->index();
            $table->integer('relevance')->nullable();
            $table->string('suggestion')->nullable();
            $table->string('suggestion_type')->nullable();
            $table->text('search_query_url')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->text('highlighted')->nullable();

            $table->timestamps();
            $table->timestamp('processed_at')->nullable()->index();
            $table->text('processed_status')->nullable();

            // Add unique index for keyword, cursor_pointer, suggestion, location_code, language_code, device
            // MySQL (64) and PostgreSQL (63) have character limits for index names, so we manually set them.
            // For SQLite, we let Laravel auto-generate unique names since index names must be unique across all tables.
            if ($driver === 'mysql' || $driver === 'pgsql') {
                $table->unique(['keyword', 'cursor_pointer', 'suggestion', 'location_code', 'language_code', 'device'], 'dsgai_keyword_cursor_suggestion_location_language_device_unique');
            } else {
                $table->unique(['keyword', 'cursor_pointer', 'suggestion', 'location_code', 'language_code', 'device']);
            }
        });

        Log::debug('DataForSEO SERP Google Autocomplete Items table created successfully', [
            'table' => $table,
        ]);
    }

    // Verify table structure if requested
    if ($verify) {
        if (!$schema->hasTable($table)) {
            throw new \RuntimeException("Table {$table} was not created successfully");
        }

        $pdo       = $schema->getConnection()->getPdo();
        $driver    = $schema->getConnection()->getDriverName();
        $tableInfo = [];
        $indexInfo = [];

        if ($driver === 'mysql') {
            $result    = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
            $tableInfo = $result['Create Table'] ?? null;
        } elseif ($driver === 'sqlite') {
            $tableInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetch(\PDO::FETCH_ASSOC);
            $indexInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='{$table}'")->fetchAll(\PDO::FETCH_COLUMN);
        }

        Log::debug('Table verified', [
            'table'     => $table,
            'structure' => $tableInfo,
            'indexes'   => $indexInfo,
        ]);
    }
}

/**
 * Create DataForSEO Keywords Data Google Ads Items table
 *
 * @param Builder $schema       Schema builder instance
 * @param string  $table        Table name
 * @param bool    $dropExisting Whether to drop existing table
 * @param bool    $verify       Whether to verify table structure
 *
 * @throws \RuntimeException When table creation fails
 */
function create_dataforseo_keywords_data_google_ads_items_table(
    Builder $schema,
    string $table = 'dataforseo_keywords_data_google_ads_items',
    bool $dropExisting = false,
    bool $verify = false
): void {
    if ($dropExisting && $schema->hasTable($table)) {
        Log::debug('Dropping existing DataForSEO Keywords Data Google Ads Items table', [
            'table' => $table,
        ]);
        $schema->dropIfExists($table);
    }

    $driver = $schema->getConnection()->getDriverName();

    if (!$schema->hasTable($table)) {
        Log::debug('Creating DataForSEO Keywords Data Google Ads Items table', [
            'table' => $table,
        ]);

        $schema->create($table, function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('response_id')->nullable();
            $table->string('task_id')->nullable();

            // Fields passed
            $table->string('keyword');
            $table->string('se')->nullable();
            // Add default value to avoid null in unique index for optional fields
            $table->integer('location_code')->default(0); // 0 = worldwide/all locations
            $table->string('language_code')->default('none'); // 'none' = no specific language (worldwide)

            // Fields returned
            $table->string('spell')->nullable();
            $table->boolean('search_partners')->nullable();
            $table->string('competition')->nullable();
            $table->integer('competition_index')->nullable();
            $table->integer('search_volume')->nullable();
            $table->float('low_top_of_page_bid')->nullable();
            $table->float('high_top_of_page_bid')->nullable();
            $table->float('cpc')->nullable();
            $table->text('monthly_searches')->nullable();

            $table->timestamps();
            $table->timestamp('processed_at')->nullable();
            $table->text('processed_status')->nullable();

            // Add indexes for better performance
            // MySQL (64) and PostgreSQL (63) have character limits for index names, so we manually set them.
            // For SQLite, we let Laravel auto-generate unique names since index names must be unique across all tables.
            if ($driver === 'mysql' || $driver === 'pgsql') {
                $table->index('response_id', 'dkdgai_response_id_idx');
                $table->index('task_id', 'dkdgai_task_id_idx');

                $table->index('keyword', 'dkdgai_keyword_idx');
                $table->index('se', 'dkdgai_se_idx');
                $table->index('location_code', 'dkdgai_location_code_idx');
                $table->index('language_code', 'dkdgai_language_code_idx');

                $table->index('spell', 'dkdgai_spell_idx');
                $table->index('search_partners', 'dkdgai_search_partners_idx');
                $table->index('competition', 'dkdgai_competition_idx');
                $table->index('competition_index', 'dkdgai_competition_index_idx');
                $table->index('search_volume', 'dkdgai_search_volume_idx');
                $table->index('low_top_of_page_bid', 'dkdgai_low_top_of_page_bid_idx');
                $table->index('high_top_of_page_bid', 'dkdgai_high_top_of_page_bid_idx');
                $table->index('cpc', 'dkdgai_cpc_idx');

                $table->index('processed_at', 'dkdgai_processed_at_idx');

                $table->unique(['keyword', 'location_code', 'language_code'], 'dkdgai_keyword_location_language_unique');
            } else {
                $table->index('response_id');
                $table->index('task_id');

                $table->index('keyword');
                $table->index('se');
                $table->index('location_code');
                $table->index('language_code');

                $table->index('spell');
                $table->index('search_partners');
                $table->index('competition');
                $table->index('competition_index');
                $table->index('search_volume');
                $table->index('low_top_of_page_bid');
                $table->index('high_top_of_page_bid');
                $table->index('cpc');

                $table->index('processed_at');

                $table->unique(['keyword', 'location_code', 'language_code']);
            }
        });

        Log::debug('DataForSEO Keywords Data Google Ads Items table created successfully', [
            'table' => $table,
        ]);
    }

    // Verify table structure if requested
    if ($verify) {
        if (!$schema->hasTable($table)) {
            throw new \RuntimeException("Table {$table} was not created successfully");
        }

        $pdo       = $schema->getConnection()->getPdo();
        $driver    = $schema->getConnection()->getDriverName();
        $tableInfo = [];
        $indexInfo = [];

        if ($driver === 'mysql') {
            $result    = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
            $tableInfo = $result['Create Table'] ?? null;
        } elseif ($driver === 'sqlite') {
            $tableInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetch(\PDO::FETCH_ASSOC);
            $indexInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='{$table}'")->fetchAll(\PDO::FETCH_COLUMN);
        }

        Log::debug('Table verified', [
            'table'     => $table,
            'structure' => $tableInfo,
            'indexes'   => $indexInfo,
        ]);
    }
}

/**
 * Create DataForSEO Backlinks Bulk Items table
 *
 * @param Builder $schema       Schema builder instance
 * @param string  $table        Table name
 * @param bool    $dropExisting Whether to drop existing table
 * @param bool    $verify       Whether to verify table structure
 *
 * @throws \RuntimeException When table creation fails
 */
function create_dataforseo_backlinks_bulk_items_table(
    Builder $schema,
    string $table = 'dataforseo_backlinks_bulk_items',
    bool $dropExisting = false,
    bool $verify = false
): void {
    if ($dropExisting && $schema->hasTable($table)) {
        Log::debug('Dropping existing DataForSEO Backlinks Bulk Items table', [
            'table' => $table,
        ]);
        $schema->dropIfExists($table);
    }

    $driver = $schema->getConnection()->getDriverName();

    if (!$schema->hasTable($table)) {
        Log::debug('Creating DataForSEO Backlinks Bulk Items table', [
            'table' => $table,
        ]);

        $schema->create($table, function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('response_id')->nullable();
            $table->string('task_id')->nullable();

            // Primary identifier field (target/url)
            $table->string('target');

            // Ranking metrics
            $table->integer('rank')->nullable();
            $table->integer('main_domain_rank')->nullable();

            // Backlink counts
            $table->unsignedBigInteger('backlinks')->nullable();
            $table->unsignedBigInteger('new_backlinks')->nullable();
            $table->unsignedBigInteger('lost_backlinks')->nullable();
            $table->unsignedBigInteger('broken_backlinks')->nullable();
            $table->unsignedBigInteger('broken_pages')->nullable();

            // Quality metrics
            $table->integer('spam_score')->nullable();
            $table->integer('backlinks_spam_score')->nullable();

            // Domain metrics (shared across multiple endpoints)
            $table->unsignedBigInteger('referring_domains')->nullable();
            $table->unsignedBigInteger('referring_domains_nofollow')->nullable();
            $table->unsignedBigInteger('referring_main_domains')->nullable();
            $table->unsignedBigInteger('referring_main_domains_nofollow')->nullable();

            // New/Lost domain metrics
            $table->unsignedBigInteger('new_referring_domains')->nullable();
            $table->unsignedBigInteger('lost_referring_domains')->nullable();
            $table->unsignedBigInteger('new_referring_main_domains')->nullable();
            $table->unsignedBigInteger('lost_referring_main_domains')->nullable();

            // Pages Summary specific metrics
            $table->string('first_seen')->nullable();
            $table->string('lost_date')->nullable();
            $table->unsignedBigInteger('referring_ips')->nullable();
            $table->unsignedBigInteger('referring_subnets')->nullable();
            $table->unsignedBigInteger('referring_pages')->nullable();
            $table->unsignedBigInteger('referring_pages_nofollow')->nullable();

            $table->timestamps();
            $table->timestamp('processed_at')->nullable();
            $table->text('processed_status')->nullable();

            // Add indexes for better performance
            // MySQL (64) and PostgreSQL (63) have character limits for index names, so we manually set them.
            // For SQLite, we let Laravel auto-generate unique names since index names must be unique across all tables.
            if ($driver === 'mysql' || $driver === 'pgsql') {
                $table->index('response_id', 'dbbi_response_id_idx');
                $table->index('task_id', 'dbbi_task_id_idx');
                $table->index('rank', 'dbbi_rank_idx');
                $table->index('backlinks', 'dbbi_backlinks_idx');
                $table->index('spam_score', 'dbbi_spam_score_idx');
                $table->index('referring_domains', 'dbbi_referring_domains_idx');
                $table->index('processed_at', 'dbbi_processed_at_idx');
                $table->unique('target', 'dbbi_target_unique');
            } else {
                $table->index('response_id');
                $table->index('task_id');
                $table->index('rank');
                $table->index('backlinks');
                $table->index('spam_score');
                $table->index('referring_domains');
                $table->index('processed_at');
                $table->unique('target');
            }
        });

        Log::debug('DataForSEO Backlinks Bulk Items table created successfully', [
            'table' => $table,
        ]);
    }

    // Verify table structure if requested
    if ($verify) {
        if (!$schema->hasTable($table)) {
            throw new \RuntimeException("Table {$table} was not created successfully");
        }

        $pdo       = $schema->getConnection()->getPdo();
        $driver    = $schema->getConnection()->getDriverName();
        $tableInfo = [];
        $indexInfo = [];

        if ($driver === 'mysql') {
            $result    = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
            $tableInfo = $result['Create Table'] ?? null;
        } elseif ($driver === 'sqlite') {
            $tableInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetch(\PDO::FETCH_ASSOC);
            $indexInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='{$table}'")->fetchAll(\PDO::FETCH_COLUMN);
        }

        Log::debug('Table verified', [
            'table'     => $table,
            'structure' => $tableInfo,
            'indexes'   => $indexInfo,
        ]);
    }
}

/**
 * Create DataForSEO Merchant Amazon Products Listings table
 *
 * @param Builder $schema       Database schema builder
 * @param string  $table        Table name
 * @param bool    $dropExisting Whether to drop existing table
 * @param bool    $verify       Whether to verify table structure
 *
 * @throws \RuntimeException When table creation fails
 */
function create_dataforseo_merchant_amazon_products_listings_table(
    Builder $schema,
    string $table = 'dataforseo_merchant_amazon_products_listings',
    bool $dropExisting = false,
    bool $verify = false
): void {
    if ($dropExisting && $schema->hasTable($table)) {
        Log::debug('Dropping existing DataForSEO Merchant Amazon Products Listings table', [
            'table' => $table,
        ]);
        $schema->dropIfExists($table);
    }

    $driver = $schema->getConnection()->getDriverName();

    if (!$schema->hasTable($table)) {
        Log::debug('Creating DataForSEO Merchant Amazon Products Listings table', [
            'table' => $table,
        ]);

        $schema->create($table, function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('response_id')->nullable();
            $table->string('task_id')->nullable();

            // Fields passed
            $table->string('keyword'); // From tasks.data.keyword
            $table->string('se')->nullable();
            $table->string('se_type');
            $table->string('function');
            $table->integer('location_code');
            $table->string('language_code', 20);
            $table->string('device', 20);
            $table->string('os')->nullable();
            $table->string('tag')->nullable();

            // Fields returned
            $table->string('result_keyword')->nullable(); // From tasks.result.keyword
            $table->string('type')->nullable();
            $table->string('se_domain')->nullable();
            $table->string('check_url')->nullable();
            $table->string('result_datetime')->nullable(); // From tasks.result.datetime
            $table->text('spell')->nullable(); // JSON, should be pretty printed
            $table->text('item_types')->nullable(); // JSON, should be pretty printed
            $table->unsignedBigInteger('se_results_count')->nullable();
            $table->text('categories')->nullable(); // JSON, should be pretty printed
            $table->integer('items_count')->nullable();

            $table->timestamps();
            $table->timestamp('processed_at')->nullable();
            $table->text('processed_status')->nullable();

            // Add indexes with custom names for MySQL/PostgreSQL to avoid auto-generated names being too long
            // Add unique index for keyword, location_code, language_code, device
            // MySQL (64) and PostgreSQL (63) have character limits for index names, so we manually set them.
            // For SQLite, we let Laravel auto-generate unique names since index names must be unique across all tables.
            if ($driver === 'mysql' || $driver === 'pgsql') {
                $table->index('response_id', 'dmapl_response_id_idx');
                $table->index('task_id', 'dmapl_task_id_idx');

                $table->index('keyword', 'dmapl_keyword_idx');
                $table->index('se', 'dmapl_se_idx');
                $table->index('se_type', 'dmapl_se_type_idx');
                $table->index('function', 'dmapl_function_idx');
                $table->index('location_code', 'dmapl_location_code_idx');
                $table->index('language_code', 'dmapl_language_code_idx');
                $table->index('device', 'dmapl_device_idx');
                $table->index('os', 'dmapl_os_idx');
                $table->index('tag', 'dmapl_tag_idx');

                $table->index('result_keyword', 'dmapl_result_keyword_idx');
                $table->index('type', 'dmapl_type_idx');
                $table->index('se_domain', 'dmapl_se_domain_idx');
                $table->index('check_url', 'dmapl_check_url_idx');
                $table->index('result_datetime', 'dmapl_result_datetime_idx');
                $table->index('se_results_count', 'dmapl_se_results_count_idx');
                $table->index('items_count', 'dmapl_items_count_idx');
                $table->index('processed_at', 'dmapl_processed_at_idx');

                $table->unique(['keyword', 'location_code', 'language_code', 'device'], 'dmapl_keyword_location_language_device_unique');
            } else {
                $table->index('response_id');
                $table->index('task_id');

                $table->index('keyword');
                $table->index('se');
                $table->index('se_type');
                $table->index('function');
                $table->index('location_code');
                $table->index('language_code');
                $table->index('device');
                $table->index('os');
                $table->index('tag');

                $table->index('result_keyword');
                $table->index('type');
                $table->index('se_domain');
                $table->index('check_url');
                $table->index('result_datetime');
                $table->index('se_results_count');
                $table->index('items_count');
                $table->index('processed_at');

                $table->unique(['keyword', 'location_code', 'language_code', 'device']);
            }
        });

        Log::debug('DataForSEO Merchant Amazon Products Listings table created successfully', [
            'table' => $table,
        ]);
    }

    // Verify table structure if requested
    if ($verify) {
        if (!$schema->hasTable($table)) {
            throw new \RuntimeException("Table {$table} was not created successfully");
        }

        $pdo       = $schema->getConnection()->getPdo();
        $driver    = $schema->getConnection()->getDriverName();
        $tableInfo = [];
        $indexInfo = [];

        if ($driver === 'mysql') {
            $result    = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
            $tableInfo = $result['Create Table'] ?? null;
        } elseif ($driver === 'sqlite') {
            $tableInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetch(\PDO::FETCH_ASSOC);
            $indexInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='{$table}'")->fetchAll(\PDO::FETCH_COLUMN);
        }

        Log::debug('Table verified', [
            'table'     => $table,
            'structure' => $tableInfo,
            'indexes'   => $indexInfo,
        ]);
    }
}

/**
 * Create DataForSEO Merchant Amazon Products Items table
 *
 * @param Builder $schema       Schema builder instance
 * @param string  $table        Table name
 * @param bool    $dropExisting Whether to drop existing table
 * @param bool    $verify       Whether to verify table structure
 *
 * @throws \RuntimeException When table creation fails
 */
function create_dataforseo_merchant_amazon_products_items_table(
    Builder $schema,
    string $table = 'dataforseo_merchant_amazon_products_items',
    bool $dropExisting = false,
    bool $verify = false
): void {
    if ($dropExisting && $schema->hasTable($table)) {
        Log::debug('Dropping existing DataForSEO Merchant Amazon Products Items table', [
            'table' => $table,
        ]);
        $schema->dropIfExists($table);
    }

    $driver = $schema->getConnection()->getDriverName();

    if (!$schema->hasTable($table)) {
        Log::debug('Creating DataForSEO Merchant Amazon Products Items table', [
            'table' => $table,
        ]);

        $schema->create($table, function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('response_id')->nullable();
            $table->string('task_id')->nullable();

            // Fields passed
            $table->string('keyword');
            $table->integer('location_code');
            $table->string('language_code', 20);
            $table->string('device', 20);
            $table->string('os')->nullable();
            $table->string('tag')->nullable();

            // Fields returned
            $table->string('result_keyword')->nullable(); // From tasks.result.keyword
            $table->string('items_type')->nullable(); // From tasks.result.items.type
            $table->string('se_domain')->nullable();
            $table->integer('rank_group')->nullable();
            $table->integer('rank_absolute')->nullable();
            $table->text('xpath')->nullable();
            $table->string('domain')->nullable();
            $table->text('title')->nullable();
            $table->text('url')->nullable();
            $table->text('image_url')->nullable();
            $table->integer('bought_past_month')->nullable();
            $table->float('price_from')->nullable();
            $table->float('price_to')->nullable();
            $table->string('currency')->nullable();
            $table->text('special_offers')->nullable(); // JSON, should be pretty printed
            $table->string('data_asin')->nullable();

            // Rating fields (flattened from rating object)
            $table->string('rating_type')->nullable(); // From tasks.result.items.rating.type
            $table->string('rating_position')->nullable(); // From tasks.result.items.rating.position
            $table->string('rating_rating_type')->nullable(); // From tasks.result.items.rating.rating_type
            $table->string('rating_value')->nullable(); // From tasks.result.items.rating.value
            $table->integer('rating_votes_count')->nullable(); // From tasks.result.items.rating.votes_count
            $table->string('rating_rating_max')->nullable(); // From tasks.result.items.rating.rating_max

            // More fields
            $table->boolean('is_amazon_choice')->nullable();
            $table->boolean('is_best_seller')->nullable();
            $table->text('delivery_info')->nullable(); // JSON, should be pretty printed
            $table->text('nested_items')->nullable(); // JSON, should be pretty printed. From tasks.result.items.items

            $table->timestamps();
            $table->timestamp('processed_at')->nullable();
            $table->text('processed_status')->nullable();

            // Add indexes with custom names for MySQL/PostgreSQL to avoid auto-generated names being too long
            // Add unique index for keyword, location_code, language_code, device, items_type, rank_absolute
            // MySQL (64) and PostgreSQL (63) have character limits for index names, so we manually set them.
            // For SQLite, we let Laravel auto-generate unique names since index names must be unique across all tables.
            if ($driver === 'mysql' || $driver === 'pgsql') {
                $table->index('response_id', 'dmapi_response_id_idx');
                $table->index('task_id', 'dmapi_task_id_idx');
                $table->index('keyword', 'dmapi_keyword_idx');
                $table->index('location_code', 'dmapi_location_code_idx');
                $table->index('language_code', 'dmapi_language_code_idx');
                $table->index('device', 'dmapi_device_idx');
                $table->index('os', 'dmapi_os_idx');
                $table->index('tag', 'dmapi_tag_idx');

                $table->index('result_keyword', 'dmapi_result_keyword_idx');
                $table->index('items_type', 'dmapi_items_type_idx');
                $table->index('se_domain', 'dmapi_se_domain_idx');
                $table->index('rank_group', 'dmapi_rank_group_idx');
                $table->index('rank_absolute', 'dmapi_rank_absolute_idx');
                $table->index('domain', 'dmapi_domain_idx');
                $table->index('bought_past_month', 'dmapi_bought_past_month_idx');
                $table->index('price_from', 'dmapi_price_from_idx');
                $table->index('price_to', 'dmapi_price_to_idx');
                $table->index('data_asin', 'dmapi_data_asin_idx');

                $table->index('rating_value', 'dmapi_rating_value_idx');
                $table->index('rating_votes_count', 'dmapi_rating_votes_count_idx');
                $table->index('rating_rating_max', 'dmapi_rating_rating_max_idx');

                $table->index('is_amazon_choice', 'dmapi_is_amazon_choice_idx');
                $table->index('is_best_seller', 'dmapi_is_best_seller_idx');

                $table->index('processed_at', 'dmapi_processed_at_idx');

                $table->unique(['keyword', 'location_code', 'language_code', 'device', 'items_type', 'rank_absolute'], 'dmapi_keyword_location_language_device_type_rankabs_unique');
            } else {
                $table->index('response_id');
                $table->index('task_id');
                $table->index('keyword');
                $table->index('location_code');
                $table->index('language_code');
                $table->index('device');
                $table->index('os');
                $table->index('tag');

                $table->index('result_keyword');
                $table->index('items_type');
                $table->index('se_domain');
                $table->index('rank_group');
                $table->index('rank_absolute');
                $table->index('domain');
                $table->index('bought_past_month');
                $table->index('price_from');
                $table->index('price_to');
                $table->index('data_asin');

                $table->index('rating_value');
                $table->index('rating_votes_count');
                $table->index('rating_rating_max');

                $table->index('is_amazon_choice');
                $table->index('is_best_seller');

                $table->index('processed_at');

                $table->unique(['keyword', 'location_code', 'language_code', 'device', 'items_type', 'rank_absolute']);
            }
        });

        Log::debug('DataForSEO Merchant Amazon Products Items table created successfully', [
            'table' => $table,
        ]);
    }

    // Verify table structure if requested
    if ($verify) {
        if (!$schema->hasTable($table)) {
            throw new \RuntimeException("Table {$table} was not created successfully");
        }

        $pdo       = $schema->getConnection()->getPdo();
        $driver    = $schema->getConnection()->getDriverName();
        $tableInfo = [];
        $indexInfo = [];

        if ($driver === 'mysql') {
            $result    = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
            $tableInfo = $result['Create Table'] ?? null;
        } elseif ($driver === 'sqlite') {
            $tableInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetch(\PDO::FETCH_ASSOC);
            $indexInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='{$table}'")->fetchAll(\PDO::FETCH_COLUMN);
        }

        Log::debug('Table verified', [
            'table'     => $table,
            'structure' => $tableInfo,
            'indexes'   => $indexInfo,
        ]);
    }
}

/**
 * Create the DataForSEO Merchant Amazon ASINs table for processing individual ASIN items.
 *
 * @param Builder $schema       The database schema builder
 * @param string  $table        The table name
 * @param bool    $dropExisting Whether to drop existing table
 * @param bool    $verify       Whether to verify table structure
 *
 * @throws \RuntimeException When table creation fails
 */
function create_dataforseo_merchant_amazon_asins_table(
    Builder $schema,
    string $table = 'dataforseo_merchant_amazon_asins',
    bool $dropExisting = false,
    bool $verify = false
): void {
    if ($dropExisting && $schema->hasTable($table)) {
        Log::debug('Dropping existing DataForSEO Merchant Amazon ASINs table', [
            'table' => $table,
        ]);
        $schema->dropIfExists($table);
    }

    $driver = $schema->getConnection()->getDriverName();

    if (!$schema->hasTable($table)) {
        Log::debug('Creating DataForSEO Merchant Amazon ASINs table', [
            'table' => $table,
        ]);

        $schema->create($table, function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('response_id')->nullable()->index();
            $table->string('task_id')->nullable()->index();

            // Fields passed
            $table->string('asin')->index();
            $table->string('se')->nullable()->index();
            $table->string('se_type')->index();
            $table->integer('location_code')->index();
            $table->string('language_code', 20)->index();
            $table->string('device', 20)->index();
            $table->string('os')->nullable()->index();
            $table->boolean('load_more_local_reviews')->nullable();
            $table->string('local_reviews_sort')->nullable();
            $table->string('tag')->nullable()->index();

            // Fields returned
            $table->string('result_asin')->index(); // From tasks.result.asin
            $table->string('type')->nullable()->index();
            $table->string('se_domain')->index();
            $table->text('check_url')->nullable();
            $table->string('result_datetime')->nullable()->index(); // From tasks.result.datetime
            $table->text('spell')->nullable(); // JSON, should be pretty printed
            $table->text('item_types')->nullable(); // JSON, should be pretty printed
            $table->integer('items_count')->nullable()->index();

            // Item expanded
            $table->string('items_type')->nullable()->index(); // From tasks.result.items.type
            $table->integer('rank_group')->nullable()->index();
            $table->integer('rank_absolute')->nullable()->index();
            $table->string('position')->nullable();
            $table->text('xpath')->nullable();
            $table->text('title')->nullable();
            $table->text('details')->nullable();
            $table->text('image_url')->nullable();
            $table->string('author')->nullable()->index();
            $table->string('data_asin')->nullable()->index();
            $table->string('parent_asin')->nullable()->index();
            $table->text('product_asins')->nullable(); // JSON, should be pretty printed. From result.items.product_asins
            $table->float('price_from')->nullable()->index();
            $table->float('price_to')->nullable()->index();
            $table->string('currency')->nullable();
            $table->boolean('is_amazon_choice')->nullable()->index();

            // Rating fields (flattened from rating object)
            $table->string('rating_type')->nullable(); // From tasks.result.items.rating.type
            $table->string('rating_position')->nullable(); // From tasks.result.items.rating.position
            $table->string('rating_rating_type')->nullable(); // From tasks.result.items.rating.rating_type
            $table->string('rating_value')->nullable(); // From tasks.result.items.rating.value
            $table->integer('rating_votes_count')->nullable(); // From tasks.result.items.rating.votes_count
            $table->string('rating_rating_max')->nullable(); // From tasks.result.items.rating.rating_max

            // More fields
            $table->boolean('is_newer_model_available')->nullable()->index();
            $table->text('applicable_vouchers')->nullable(); // JSON, should be pretty printed. From result.items.applicable_vouchers
            $table->text('newer_model')->nullable(); // JSON, should be pretty printed. From result.items.newer_model
            $table->text('categories')->nullable(); // JSON, should be pretty printed. From result.items.categories
            $table->text('product_information')->nullable(); // JSON, should be pretty printed. From result.items.product_information

            $table->text('product_images_list')->nullable(); // JSON, should be pretty printed. From result.items.product_images_list
            $table->text('product_videos_list')->nullable(); // JSON, should be pretty printed. From result.items.product_videos_list
            $table->text('description')->nullable(); // JSON, should be pretty printed. From result.items.description
            $table->boolean('is_available')->nullable()->index();
            $table->text('top_local_reviews')->nullable(); // JSON, should be pretty printed. From result.items.top_local_reviews
            $table->text('top_global_reviews')->nullable(); // JSON, should be pretty printed. From result.items.top_global_reviews

            $table->timestamps();
            $table->timestamp('processed_at')->nullable()->index();
            $table->text('processed_status')->nullable();

            // Add unique index for asin, location_code, language_code, device, data_asin
            // MySQL (64) and PostgreSQL (63) have character limits for index names, so we manually set them.
            // For SQLite, we let Laravel auto-generate unique names since index names must be unique across all tables.
            if ($driver === 'mysql' || $driver === 'pgsql') {
                $table->unique(['asin', 'location_code', 'language_code', 'device', 'data_asin'], 'dmaa_asin_location_language_device_data_asin_unique');
            } else {
                $table->unique(['asin', 'location_code', 'language_code', 'device', 'data_asin']);
            }
        });

        Log::debug('DataForSEO Merchant Amazon ASINs table created successfully', [
            'table' => $table,
        ]);
    }

    // Verify table structure if requested
    if ($verify) {
        if (!$schema->hasTable($table)) {
            throw new \RuntimeException("Table {$table} was not created successfully");
        }

        $pdo       = $schema->getConnection()->getPdo();
        $driver    = $schema->getConnection()->getDriverName();
        $tableInfo = [];
        $indexInfo = [];

        if ($driver === 'mysql') {
            $result    = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
            $tableInfo = $result['Create Table'] ?? null;
        } elseif ($driver === 'sqlite') {
            $tableInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetch(\PDO::FETCH_ASSOC);
            $indexInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='{$table}'")->fetchAll(\PDO::FETCH_COLUMN);
        }

        Log::debug('Table verified', [
            'table'     => $table,
            'structure' => $tableInfo,
            'indexes'   => $indexInfo,
        ]);
    }
}

/**
 * Create DataForSEO Labs Google Keyword Research Items table
 *
 * @param Builder $schema       Schema builder instance
 * @param string  $table        Table name
 * @param bool    $dropExisting Whether to drop existing table
 * @param bool    $verify       Whether to verify table structure
 *
 * @throws \RuntimeException When table creation fails
 */
function create_dataforseo_labs_google_keyword_research_items_table(
    Builder $schema,
    string $table = 'dataforseo_labs_google_keyword_research_items',
    bool $dropExisting = false,
    bool $verify = false
): void {
    if ($dropExisting && $schema->hasTable($table)) {
        Log::debug('Dropping existing DataForSEO Labs Google Keyword Research Items table', [
            'table' => $table,
        ]);
        $schema->dropIfExists($table);
    }

    $driver = $schema->getConnection()->getDriverName();

    if (!$schema->hasTable($table)) {
        Log::debug('Creating DataForSEO Labs Google Keyword Research Items table', [
            'table' => $table,
        ]);

        $schema->create($table, function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('response_id')->nullable();
            $table->string('task_id')->nullable();

            // Core fields (unique index fields)
            $table->string('keyword');
            $table->string('se_type')->nullable();
            // Add default value to avoid null in unique index for optional fields
            $table->integer('location_code')->default(0); // 0 = worldwide/all locations
            $table->string('language_code', 20)->default('none'); // 'none' = no specific language (worldwide)

            // keyword_info fields
            $table->string('keyword_info_se_type')->nullable();
            $table->string('keyword_info_last_updated_time')->nullable();
            $table->float('keyword_info_competition')->nullable();
            $table->string('keyword_info_competition_level')->nullable();
            $table->float('keyword_info_cpc')->nullable();
            $table->unsignedBigInteger('keyword_info_search_volume')->nullable();
            $table->float('keyword_info_low_top_of_page_bid')->nullable();
            $table->float('keyword_info_high_top_of_page_bid')->nullable();
            $table->text('keyword_info_categories')->nullable(); // JSON, should be pretty printed
            $table->text('keyword_info_monthly_searches')->nullable(); // JSON, should be pretty printed
            $table->integer('keyword_info_search_volume_trend_monthly')->nullable();
            $table->integer('keyword_info_search_volume_trend_quarterly')->nullable();
            $table->integer('keyword_info_search_volume_trend_yearly')->nullable();

            // keyword_info_normalized_with_bing fields
            $table->string('keyword_info_normalized_with_bing_last_updated_time')->nullable();
            $table->unsignedBigInteger('keyword_info_normalized_with_bing_search_volume')->nullable();
            $table->boolean('keyword_info_normalized_with_bing_is_normalized')->nullable();
            $table->text('keyword_info_normalized_with_bing_monthly_searches')->nullable(); // JSON, should be pretty printed

            // keyword_info_normalized_with_clickstream fields
            $table->string('keyword_info_normalized_with_clickstream_last_updated_time')->nullable();
            $table->unsignedBigInteger('keyword_info_normalized_with_clickstream_search_volume')->nullable();
            $table->boolean('keyword_info_normalized_with_clickstream_is_normalized')->nullable();
            $table->text('keyword_info_normalized_with_clickstream_monthly_searches')->nullable(); // JSON, should be pretty printed

            // clickstream_keyword_info fields
            $table->unsignedBigInteger('clickstream_keyword_info_search_volume')->nullable();
            $table->string('clickstream_keyword_info_last_updated_time')->nullable();
            $table->integer('clickstream_keyword_info_gender_distribution_female')->nullable();
            $table->integer('clickstream_keyword_info_gender_distribution_male')->nullable();
            $table->integer('clickstream_keyword_info_age_distribution_18_24')->nullable();
            $table->integer('clickstream_keyword_info_age_distribution_25_34')->nullable();
            $table->integer('clickstream_keyword_info_age_distribution_35_44')->nullable();
            $table->integer('clickstream_keyword_info_age_distribution_45_54')->nullable();
            $table->integer('clickstream_keyword_info_age_distribution_55_64')->nullable();
            $table->text('clickstream_keyword_info_monthly_searches')->nullable(); // JSON, should be pretty printed

            // keyword_properties fields
            $table->string('keyword_properties_se_type')->nullable();
            $table->string('keyword_properties_core_keyword')->nullable();
            $table->string('keyword_properties_synonym_clustering_algorithm')->nullable();
            $table->integer('keyword_properties_keyword_difficulty')->nullable();
            $table->string('keyword_properties_detected_language')->nullable();
            $table->boolean('keyword_properties_is_another_language')->nullable();

            // serp_info fields
            $table->string('serp_info_se_type')->nullable();
            $table->text('serp_info_check_url')->nullable();
            $table->text('serp_info_serp_item_types')->nullable(); // JSON, should be pretty printed
            $table->unsignedBigInteger('serp_info_se_results_count')->nullable();
            $table->string('serp_info_last_updated_time')->nullable();
            $table->string('serp_info_previous_updated_time')->nullable();

            // avg_backlinks_info fields
            $table->string('avg_backlinks_info_se_type')->nullable();
            $table->float('avg_backlinks_info_backlinks')->nullable();
            $table->float('avg_backlinks_info_dofollow')->nullable();
            $table->float('avg_backlinks_info_referring_pages')->nullable();
            $table->float('avg_backlinks_info_referring_domains')->nullable();
            $table->float('avg_backlinks_info_referring_main_domains')->nullable();
            $table->float('avg_backlinks_info_rank')->nullable();
            $table->float('avg_backlinks_info_main_domain_rank')->nullable();
            $table->string('avg_backlinks_info_last_updated_time')->nullable();

            // search_intent_info fields
            $table->string('search_intent_info_se_type')->nullable();
            $table->string('search_intent_info_main_intent')->nullable();
            $table->text('search_intent_info_foreign_intent')->nullable(); // JSON, should be pretty printed
            $table->string('search_intent_info_last_updated_time')->nullable();

            // Additional fields
            $table->text('related_keywords')->nullable(); // JSON, should be pretty printed
            $table->integer('keyword_difficulty')->nullable();

            // keyword_intent fields
            $table->string('keyword_intent_label')->nullable();
            $table->float('keyword_intent_probability')->nullable();

            // secondary_keyword_intents fields
            $table->float('secondary_keyword_intents_probability_informational')->nullable();
            $table->float('secondary_keyword_intents_probability_navigational')->nullable();
            $table->float('secondary_keyword_intents_probability_commercial')->nullable();
            $table->float('secondary_keyword_intents_probability_transactional')->nullable();

            $table->timestamps();
            $table->timestamp('processed_at')->nullable();
            $table->text('processed_status')->nullable();

            // Add indexes for better performance
            // MySQL (64) and PostgreSQL (63) have character limits for index names, so we manually set them.
            // For SQLite, we let Laravel auto-generate unique names since index names must be unique across all tables.
            if ($driver === 'mysql' || $driver === 'pgsql') {
                $table->index('response_id', 'dlgkri_response_id_idx');
                $table->index('task_id', 'dlgkri_task_id_idx');

                $table->index('keyword', 'dlgkri_keyword_idx');
                $table->index('se_type', 'dlgkri_se_type_idx');
                $table->index('location_code', 'dlgkri_location_code_idx');
                $table->index('language_code', 'dlgkri_language_code_idx');

                // keyword_info indexes
                $table->index('keyword_info_competition', 'dlgkri_ki_competition_idx');
                $table->index('keyword_info_competition_level', 'dlgkri_ki_comp_level_idx');
                $table->index('keyword_info_cpc', 'dlgkri_ki_cpc_idx');
                $table->index('keyword_info_search_volume', 'dlgkri_ki_search_vol_idx');
                $table->index('keyword_info_low_top_of_page_bid', 'dlgkri_ki_low_bid_idx');
                $table->index('keyword_info_high_top_of_page_bid', 'dlgkri_ki_high_bid_idx');
                $table->index('keyword_info_search_volume_trend_monthly', 'dlgkri_ki_trend_m_idx');
                $table->index('keyword_info_search_volume_trend_quarterly', 'dlgkri_ki_trend_q_idx');
                $table->index('keyword_info_search_volume_trend_yearly', 'dlgkri_ki_trend_y_idx');

                // keyword_info_normalized_with_bing indexes
                $table->index('keyword_info_normalized_with_bing_last_updated_time', 'dlgkri_kinb_updated_idx');
                $table->index('keyword_info_normalized_with_bing_search_volume', 'dlgkri_kinb_vol_idx');
                $table->index('keyword_info_normalized_with_bing_is_normalized', 'dlgkri_kinb_norm_idx');

                // keyword_info_normalized_with_clickstream indexes
                $table->index('keyword_info_normalized_with_clickstream_last_updated_time', 'dlgkri_kinc_updated_idx');
                $table->index('keyword_info_normalized_with_clickstream_search_volume', 'dlgkri_kinc_vol_idx');
                $table->index('keyword_info_normalized_with_clickstream_is_normalized', 'dlgkri_kinc_norm_idx');

                // clickstream_keyword_info indexes
                $table->index('clickstream_keyword_info_search_volume', 'dlgkri_cki_vol_idx');
                $table->index('clickstream_keyword_info_last_updated_time', 'dlgkri_cki_updated_idx');
                $table->index('clickstream_keyword_info_gender_distribution_female', 'dlgkri_cki_gender_f_idx');
                $table->index('clickstream_keyword_info_gender_distribution_male', 'dlgkri_cki_gender_m_idx');
                $table->index('clickstream_keyword_info_age_distribution_18_24', 'dlgkri_cki_age_18_24_idx');
                $table->index('clickstream_keyword_info_age_distribution_25_34', 'dlgkri_cki_age_25_34_idx');
                $table->index('clickstream_keyword_info_age_distribution_35_44', 'dlgkri_cki_age_35_44_idx');
                $table->index('clickstream_keyword_info_age_distribution_45_54', 'dlgkri_cki_age_45_54_idx');
                $table->index('clickstream_keyword_info_age_distribution_55_64', 'dlgkri_cki_age_55_64_idx');

                // keyword_properties indexes
                $table->index('keyword_properties_keyword_difficulty', 'dlgkri_kp_difficulty_idx');

                // serp_info indexes
                $table->index('serp_info_se_results_count', 'dlgkri_si_results_idx');
                $table->index('serp_info_last_updated_time', 'dlgkri_si_updated_idx');
                $table->index('serp_info_previous_updated_time', 'dlgkri_si_prev_upd_idx');

                // avg_backlinks_info indexes
                $table->index('avg_backlinks_info_backlinks', 'dlgkri_abi_backlinks_idx');
                $table->index('avg_backlinks_info_dofollow', 'dlgkri_abi_dofollow_idx');
                $table->index('avg_backlinks_info_referring_pages', 'dlgkri_abi_ref_pages_idx');
                $table->index('avg_backlinks_info_referring_domains', 'dlgkri_abi_ref_dom_idx');
                $table->index('avg_backlinks_info_referring_main_domains', 'dlgkri_abi_ref_main_idx');
                $table->index('avg_backlinks_info_rank', 'dlgkri_abi_rank_idx');
                $table->index('avg_backlinks_info_main_domain_rank', 'dlgkri_abi_main_rank_idx');
                $table->index('avg_backlinks_info_last_updated_time', 'dlgkri_abi_updated_idx');

                // search_intent_info indexes
                $table->index('search_intent_info_main_intent', 'dlgkri_sii_main_idx');
                $table->index('search_intent_info_last_updated_time', 'dlgkri_sii_updated_idx');

                // Additional field indexes
                $table->index('keyword_difficulty', 'dlgkri_kw_difficulty_idx');
                $table->index('keyword_intent_label', 'dlgkri_ki_label_idx');
                $table->index('keyword_intent_probability', 'dlgkri_ki_prob_idx');
                $table->index('secondary_keyword_intents_probability_informational', 'dlgkri_ski_prob_info_idx');
                $table->index('secondary_keyword_intents_probability_navigational', 'dlgkri_ski_prob_nav_idx');
                $table->index('secondary_keyword_intents_probability_commercial', 'dlgkri_ski_prob_comm_idx');
                $table->index('secondary_keyword_intents_probability_transactional', 'dlgkri_ski_prob_trans_idx');

                $table->index('processed_at', 'dlgkri_processed_at_idx');

                $table->unique(['keyword', 'location_code', 'language_code'], 'dlgkri_keyword_location_language_unique');
            } else {
                $table->index('response_id');
                $table->index('task_id');

                $table->index('keyword');
                $table->index('se_type');
                $table->index('location_code');
                $table->index('language_code');

                // keyword_info indexes
                $table->index('keyword_info_competition');
                $table->index('keyword_info_competition_level');
                $table->index('keyword_info_cpc');
                $table->index('keyword_info_search_volume');
                $table->index('keyword_info_low_top_of_page_bid');
                $table->index('keyword_info_high_top_of_page_bid');
                $table->index('keyword_info_search_volume_trend_monthly');
                $table->index('keyword_info_search_volume_trend_quarterly');
                $table->index('keyword_info_search_volume_trend_yearly');

                // keyword_info_normalized_with_bing indexes
                $table->index('keyword_info_normalized_with_bing_last_updated_time');
                $table->index('keyword_info_normalized_with_bing_search_volume');
                $table->index('keyword_info_normalized_with_bing_is_normalized');
                $table->index('keyword_info_normalized_with_bing_monthly_searches');

                // keyword_info_normalized_with_clickstream indexes
                $table->index('keyword_info_normalized_with_clickstream_last_updated_time');
                $table->index('keyword_info_normalized_with_clickstream_search_volume');
                $table->index('keyword_info_normalized_with_clickstream_is_normalized');
                $table->index('keyword_info_normalized_with_clickstream_monthly_searches');

                // clickstream_keyword_info indexes
                $table->index('clickstream_keyword_info_search_volume');
                $table->index('clickstream_keyword_info_last_updated_time');
                $table->index('clickstream_keyword_info_gender_distribution_female');
                $table->index('clickstream_keyword_info_gender_distribution_male');
                $table->index('clickstream_keyword_info_age_distribution_18_24');
                $table->index('clickstream_keyword_info_age_distribution_25_34');
                $table->index('clickstream_keyword_info_age_distribution_35_44');
                $table->index('clickstream_keyword_info_age_distribution_45_54');
                $table->index('clickstream_keyword_info_age_distribution_55_64');

                // keyword_properties indexes
                $table->index('keyword_properties_keyword_difficulty');

                // serp_info indexes
                $table->index('serp_info_se_results_count');
                $table->index('serp_info_last_updated_time');
                $table->index('serp_info_previous_updated_time');

                // avg_backlinks_info indexes
                $table->index('avg_backlinks_info_backlinks');
                $table->index('avg_backlinks_info_dofollow');
                $table->index('avg_backlinks_info_referring_pages');
                $table->index('avg_backlinks_info_referring_domains');
                $table->index('avg_backlinks_info_referring_main_domains');
                $table->index('avg_backlinks_info_rank');
                $table->index('avg_backlinks_info_main_domain_rank');
                $table->index('avg_backlinks_info_last_updated_time');

                // search_intent_info indexes
                $table->index('search_intent_info_main_intent');
                $table->index('search_intent_info_last_updated_time');

                // Additional field indexes
                $table->index('keyword_difficulty');
                $table->index('keyword_intent_label');
                $table->index('keyword_intent_probability');
                $table->index('secondary_keyword_intents_probability_informational');
                $table->index('secondary_keyword_intents_probability_navigational');
                $table->index('secondary_keyword_intents_probability_commercial');
                $table->index('secondary_keyword_intents_probability_transactional');

                $table->index('processed_at');

                $table->unique(['keyword', 'location_code', 'language_code']);
            }
        });

        Log::debug('DataForSEO Labs Google Keyword Research Items table created successfully', [
            'table' => $table,
        ]);
    }

    // Verify table structure if requested
    if ($verify) {
        if (!$schema->hasTable($table)) {
            throw new \RuntimeException("Table {$table} was not created successfully");
        }

        $pdo       = $schema->getConnection()->getPdo();
        $driver    = $schema->getConnection()->getDriverName();
        $tableInfo = [];
        $indexInfo = [];

        if ($driver === 'mysql') {
            $result    = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
            $tableInfo = $result['Create Table'] ?? null;
        } elseif ($driver === 'sqlite') {
            $tableInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetch(\PDO::FETCH_ASSOC);
            $indexInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='{$table}'")->fetchAll(\PDO::FETCH_COLUMN);
        }

        Log::debug('Table verified', [
            'table'     => $table,
            'structure' => $tableInfo,
            'indexes'   => $indexInfo,
        ]);
    }
}

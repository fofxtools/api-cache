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
 * @param array $params         Parameters to summarize
 * @param bool  $normalize      Whether to normalize the parameters first (optional)
 * @param bool  $prettyPrint    Whether to pretty print the JSON (optional)
 * @param int   $characterLimit The character limit for truncating values (default: 100)
 *
 * @throws \InvalidArgumentException If JSON encoding fails
 *
 * @return string Summarized parameters as JSON string with proper key-value structure
 */
function summarize_params(array $params, bool $normalize = true, bool $prettyPrint = true, int $characterLimit = 100): string
{
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
            $table->string('se_domain')->index();
            $table->integer('location_code')->index();
            $table->string('language_code', 20)->index();
            $table->string('device', 20)->index();
            $table->string('os')->index()->nullable();

            // Fields returned
            $table->string('type')->nullable()->index();
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
            $table->string('se_domain')->index();
            $table->integer('location_code')->index();
            $table->string('language_code', 20)->index();
            $table->string('device', 20)->index();
            $table->string('os')->index()->nullable();

            // Added (not from API)
            $table->integer('item_position')->index();

            // Fields returned
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
            $table->json('answer_images')->nullable();
            $table->string('answer_timestamp')->nullable();
            $table->json('answer_table')->nullable();

            $table->timestamps();
            $table->timestamp('processed_at')->nullable()->index();
            $table->text('processed_status')->nullable();

            // Add unique index for keyword, location_code, language_code, device, item_position
            // MySQL (64) and PostgreSQL (63) have character limits for index names, so we manually set them.
            // For SQLite, we let Laravel auto-generate unique names since index names must be unique across all tables.
            if ($driver === 'mysql' || $driver === 'pgsql') {
                $table->unique(['keyword', 'location_code', 'language_code', 'device', 'item_position'], 'dsgopi_keyword_location_language_device_rankabs_unique');
            } else {
                $table->unique(['keyword', 'location_code', 'language_code', 'device', 'item_position']);
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
            $table->string('se_domain')->index();
            $table->integer('location_code')->index();
            $table->string('language_code', 20)->index();
            $table->string('device', 20)->index();
            $table->string('os')->index()->nullable();

            // Fields returned
            $table->string('type')->nullable()->index();
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

            // Add unique index for keyword, suggestion, location_code, language_code, device
            // MySQL (64) and PostgreSQL (63) have character limits for index names, so we manually set them.
            // For SQLite, we let Laravel auto-generate unique names since index names must be unique across all tables.
            if ($driver === 'mysql' || $driver === 'pgsql') {
                $table->unique(['keyword', 'suggestion', 'location_code', 'language_code', 'device'], 'dsgai_keyword_suggestion_location_language_device_unique');
            } else {
                $table->unique(['keyword', 'suggestion', 'location_code', 'language_code', 'device']);
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
            $table->string('se');
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

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
            $table->string('full_url')->nullable();
            $table->string('method')->nullable();
            $table->string('attributes')->nullable();
            $table->json('request_params_summary')->nullable();

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
                $table->mediumText('response_body')->nullable();
            }

            $table->integer('response_status_code');
            $table->integer('response_size')->nullable();
            $table->double('response_time')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->timestamp('processed_at')->nullable();
            $table->json('processed_status')->nullable();

            // Add indexes for better performance
            // MySQL (64) and PostgreSQL (63) have character limits for index names, so we manually set them.
            // For SQLite, we let Laravel auto-generate unique names since index names must be unique across all tables.
            if ($driver === 'mysql' || $driver === 'pgsql') {
                $table->index(['client', 'endpoint', 'version'], 'client_endpoint_version_index');
            } else {
                $table->index(['client', 'endpoint', 'version']);
            }
            $table->index('attributes');
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
                    MODIFY response_body MEDIUMBLOB
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
    string $table = 'api_cache_pixabay_images',
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
            $table->json('processed_status')->nullable();

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
 * Summarize parameters by truncating each parameter to 50 characters. Return as JSON string.
 * Arrays will be single-line JSON, truncated at 50 characters.
 *
 * @param array $params      Parameters to summarize
 * @param bool  $normalize   Whether to normalize the parameters first (optional)
 * @param bool  $prettyPrint Whether to pretty print the JSON (optional)
 *
 * @throws \InvalidArgumentException If JSON encoding fails
 *
 * @return string Summarized parameters as JSON string
 */
function summarize_params(array $params, bool $normalize = true, bool $prettyPrint = false): string
{
    // Optionally normalize (remove nulls, sort keys, etc.)
    if ($normalize) {
        $params = normalize_params($params);
    }

    $summary = [];

    foreach ($params as $key => $value) {
        // For arrays, convert to single-line JSON then truncate
        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // Throw an exception if the array cannot be encoded to JSON
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('Failed to encode array to JSON.');
            }

            // Truncate the JSON string to 50 characters
            $truncated = mb_substr($json, 0, 50);
            $summary[] = $key . ': ' . $truncated;
        } else {
            // Cast scalars to string, then truncate
            $stringVal = (string) $value;
            $truncated = mb_substr($stringVal, 0, 50);
            $summary[] = $key . ': ' . $truncated;
        }
    }

    // Convert the final summary array
    if ($prettyPrint) {
        $encoded = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } else {
        $encoded = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \InvalidArgumentException('Failed to encode summary as JSON: ' . json_last_error_msg());
    }

    return $encoded;
}

/**
 * Format an API response in a clean, readable way
 *
 * @param array $result  The API response array from BaseApiClient or its children
 * @param bool  $verbose Whether to include detailed information like headers and request details
 *
 * @return string Formatted response details
 */
function format_api_response(array $result, bool $verbose = false): string
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

    // Detailed info (only if verbose)
    if ($verbose) {
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
                $decodedRequestBody = json_decode($result['request']['body']);
                $encodedRequestBody = json_encode($decodedRequestBody, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                // json_encode() returns 'null', as a string, if decodedRequestBody is null.
                // If encodedRequestBody is not 'null', add it. Else add the original request body.
                if ($encodedRequestBody !== 'null') {
                    $output[] = $encodedRequestBody;
                } else {
                    $output[] = $result['request']['body'];
                }
            } else {
                $output[] = 'N/A';
            }
        }
    }

    if (isset($result['response']) && ($result['response'] instanceof \Illuminate\Http\Client\Response)) {
        // Response headers
        $output[] = "\nResponse headers:";
        foreach ($result['response']->headers() as $key => $values) {
            $output[] = "$key: " . implode(', ', (array) $values);
        }

        // Response body
        $output[]            = "\nResponse body:";
        $decodedResponseBody = json_decode($result['response']->body());
        $encodedResponseBody = json_encode(
            $decodedResponseBody,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        // json_encode() returns 'null', as a string, if decodedResponseBody is null.
        // If encodedResponseBody is not 'null', add it. Else add the original response body.
        if ($encodedResponseBody !== 'null') {
            $output[] = $encodedResponseBody;
        } else {
            $output[] = $result['response']->body();
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

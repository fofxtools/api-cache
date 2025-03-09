<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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
 * Create response table for testing
 *
 * @param Builder $schema       Schema builder instance
 * @param string  $table        Table name
 * @param bool    $compressed   Whether to create compressed table
 * @param bool    $dropExisting Whether to drop existing table
 * @param bool    $verify       Whether to verify table structure
 *
 * @throws \RuntimeException When table creation fails
 */
function create_response_table(
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
            $table->string('endpoint');
            $table->string('base_url')->nullable();
            $table->string('full_url')->nullable();
            $table->string('method')->nullable();

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

            $table->integer('response_status_code')->nullable();
            $table->integer('response_size')->nullable();
            $table->double('response_time')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Add indexes for better performance
            // MySQL (64) and PostgreSQL (63) have character limits for index names, so we manually set them.
            // For SQLite, we let Laravel auto-generate unique names since index names must be unique across all tables.
            if ($driver === 'mysql' || $driver === 'pgsql') {
                $table->index(['client', 'endpoint', 'version'], 'client_endpoint_version_index');
            } else {
                $table->index(['client', 'endpoint', 'version']);
            }
            $table->index('expires_at');
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

        Log::debug('Table structure verified', [
            'table'      => $table,
            'compressed' => $compressed,
            'structure'  => $tableInfo,
            'indexes'    => $indexInfo,
        ]);
    }
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

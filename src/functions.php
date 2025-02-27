<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Log;

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

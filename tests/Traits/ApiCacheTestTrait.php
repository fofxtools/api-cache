<?php

namespace FOfX\ApiCache\Tests\Traits;

use Illuminate\Support\Facades\Log;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;

/**
 * Trait for API Cache testing utilities
 *
 * Provides helper methods for testing API clients and services.
 *
 * Basic usage:
 * ```php
 * class YourTest extends TestCase
 * {
 *     use ApiCacheTestTrait;
 *
 *     protected function setUp(): void
 *     {
 *         parent::setUp();
 *
 *         // Check if API server is accessible
 *         $baseUrl = config('api-cache.apis.demo.base_url');
 *         $this->checkServerStatus($baseUrl);
 *     }
 * }
 * ```
 */
trait ApiCacheTestTrait
{
    private array $mockedFunctions = [];

    public function checkServerStatus(string $url): void
    {
        $healthUrl = $url . '/health';
        $ch        = curl_init($healthUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        Log::debug('API server status check', [
            'url'      => $healthUrl,
            'response' => $response,
            'error'    => $error,
        ]);

        if ($response === false) {
            static::markTestSkipped('API server not accessible: ' . $error);
        }

        $data = json_decode($response, true);
        if (!isset($data['status']) || $data['status'] !== 'OK') {
            static::markTestSkipped('API server health check failed');
        }
    }

    /**
     * Create the response table with standard schema for testing
     *
     * This method creates a fresh table for storing API responses during testing.
     * It will drop any existing table with the same name before creating the new one.
     *
     * @param Builder $schema     The database schema builder
     * @param string  $tableName  The name of the table to create
     * @param bool    $compressed Whether to use compressed binary columns for request/response data
     */
    public function createResponseTable(Builder $schema, string $tableName, bool $compressed = false): void
    {
        // Check if table exists before dropping
        if ($schema->hasTable($tableName)) {
            Log::debug('Dropping existing table', [
                'table' => $tableName,
            ]);
            $schema->dropIfExists($tableName);
        }

        Log::debug('Creating response table for testing', [
            'table'      => $tableName,
            'compressed' => $compressed,
        ]);

        $driver = $schema->getConnection()->getDriverName();

        $schema->create($tableName, function (Blueprint $table) use ($compressed, $driver) {
            $table->id();
            $table->string('key')->unique();
            $table->string('client');
            $table->string('version')->nullable();
            $table->string('endpoint');
            $table->string('base_url')->nullable();
            $table->string('full_url')->nullable();
            $table->string('method')->nullable();

            // Use binary for compressed tables, mediumText for uncompressed
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

        // For compressed tables, modify column types for MySQL and SQL Server
        if ($compressed) {
            if ($driver === 'mysql') {
                Log::debug('Altering table columns for MySQL MEDIUMBLOB', [
                    'table' => $tableName,
                ]);

                $schema->getConnection()->statement("
                    ALTER TABLE {$tableName}
                    MODIFY request_headers MEDIUMBLOB,
                    MODIFY request_body MEDIUMBLOB,
                    MODIFY response_headers MEDIUMBLOB,
                    MODIFY response_body MEDIUMBLOB
                ");
            } elseif ($driver === 'sqlsrv') {
                Log::debug('Altering table columns for SQL Server VARBINARY(MAX)', [
                    'table' => $tableName,
                ]);

                $schema->getConnection()->statement("
                    ALTER TABLE {$tableName}
                    ALTER COLUMN request_headers VARBINARY(MAX),
                    ALTER COLUMN request_body VARBINARY(MAX),
                    ALTER COLUMN response_headers VARBINARY(MAX),
                    ALTER COLUMN response_body VARBINARY(MAX)
                ");
            }
        }

        Log::debug('Response table created successfully', [
            'table'      => $tableName,
            'compressed' => $compressed,
        ]);
    }
}

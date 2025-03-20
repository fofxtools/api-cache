<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('api_cache_openai_responses_compressed', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->string('key')->unique();
            $table->string('client');
            $table->string('version')->nullable();
            $table->string('endpoint');
            $table->string('base_url')->nullable();
            $table->string('full_url')->nullable();
            $table->string('method')->nullable();
            $table->binary('request_headers')->nullable();
            $table->binary('request_body')->nullable();
            $table->binary('response_headers')->nullable();
            $table->binary('response_body')->nullable();
            $table->integer('response_status_code')->nullable();
            $table->integer('response_size')->nullable();
            $table->double('response_time')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->timestamp('processed_at')->nullable();

            // Add indexes for better performance
            // MySQL (64) and PostgreSQL (63) have character limits for index names, so we manually set them.
            // For SQLite, we let Laravel auto-generate unique names since index names must be unique across all tables.
            if ($driver === 'mysql' || $driver === 'pgsql') {
                $table->index(['client', 'endpoint', 'version'], 'client_endpoint_version_index');
            } else {
                $table->index(['client', 'endpoint', 'version']);
            }
            $table->index('expires_at');
            $table->index('processed_at');
        });

        // Alter the request and response headers and body columns
        // For MySQL use MEDIUMBLOB, for SQL Server use VARBINARY(MAX)
        if ($driver === 'mysql') {
            Schema::getConnection()->statement('
                ALTER TABLE api_cache_openai_responses_compressed
                MODIFY request_headers MEDIUMBLOB,
                MODIFY request_body MEDIUMBLOB,
                MODIFY response_headers MEDIUMBLOB,
                MODIFY response_body MEDIUMBLOB
            ');
        } elseif ($driver === 'sqlsrv') {
            Schema::getConnection()->statement('
                ALTER TABLE api_cache_openai_responses_compressed
                ALTER COLUMN request_headers VARBINARY(MAX),
                ALTER COLUMN request_body VARBINARY(MAX),
                ALTER COLUMN response_headers VARBINARY(MAX),
                ALTER COLUMN response_body VARBINARY(MAX)
            ');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_cache_openai_responses_compressed');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('api_cache_demo_responses', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->string('key')->unique();
            $table->string('client');
            $table->string('version')->nullable();
            $table->string('endpoint');
            $table->string('base_url')->nullable();
            $table->string('full_url')->nullable();
            $table->string('method')->nullable();
            $table->mediumText('request_headers')->charset('binary')->nullable();
            $table->mediumText('request_body')->charset('binary')->nullable();
            $table->mediumText('response_headers')->charset('binary')->nullable();
            $table->mediumText('response_body')->charset('binary')->nullable();
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
    }

    public function down(): void
    {
        Schema::dropIfExists('api_cache_demo_responses');
    }
};

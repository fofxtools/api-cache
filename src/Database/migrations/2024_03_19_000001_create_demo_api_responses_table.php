<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('api_cache_demo_api_responses', function (Blueprint $table) {
            $table->id();
            $table->string('client', 50)->index();
            $table->string('key')->index();
            $table->string('endpoint');
            $table->string('base_url');
            $table->text('full_url');
            $table->string('method', 10);
            $table->text('request_headers')->nullable();
            $table->text('request_body')->nullable();
            $table->smallInteger('response_status_code')->unsigned()->nullable();
            $table->text('response_headers')->nullable();
            $table->mediumText('response_body_raw')->nullable();
            $table->binary('response_body_compressed')->nullable();
            $table->integer('response_raw_size')->unsigned()->nullable();
            $table->integer('response_compressed_size')->unsigned()->nullable();
            $table->float('response_time')->nullable();
            $table->timestamp('expires_at')->nullable()->index();

            // Client specific fields
            $table->string('response_format', 10)->nullable();
            $table->text('input_value')->nullable();
            $table->string('input_type', 50)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_cache_demo_api_responses');
    }
};

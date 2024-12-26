<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_cache_rate_limits', function (Blueprint $table) {
            $table->id();
            $table->string('client', 50)->index();  // openai, pixabay, etc.
            $table->string('endpoint');
            $table->timestamp('window_start')->index();
            $table->integer('window_request_count')->unsigned()->default(0);
            $table->timestamps();

            // Composite index for faster rate limit checks
            $table->index(['client', 'endpoint', 'window_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_cache_rate_limits');
    }
}; 
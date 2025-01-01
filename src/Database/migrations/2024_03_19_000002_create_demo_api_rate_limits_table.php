<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('api_cache_demo_api_rate_limits', function (Blueprint $table) {
            $table->id();
            $table->string('client', 50)->index();
            $table->string('endpoint');
            $table->timestamp('window_start')->index();
            $table->integer('window_request_count')->unsigned()->default(0);
            $table->enum('status', ['active', 'archived'])->default('active')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_cache_demo_api_rate_limits');
    }
};

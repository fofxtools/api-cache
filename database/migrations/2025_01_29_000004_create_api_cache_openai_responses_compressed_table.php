<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

use function FOfX\ApiCache\create_responses_table;

return new class () extends Migration {
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        create_responses_table($schema, 'api_cache_openai_responses_compressed', true);
    }

    public function down(): void
    {
        Schema::dropIfExists('api_cache_openai_responses_compressed');
    }
};

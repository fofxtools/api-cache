<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

use function FOfX\ApiCache\create_errors_table;

return new class () extends Migration {
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        create_errors_table($schema, 'api_cache_errors', false);
    }

    public function down(): void
    {
        Schema::dropIfExists('api_cache_errors');
    }
};

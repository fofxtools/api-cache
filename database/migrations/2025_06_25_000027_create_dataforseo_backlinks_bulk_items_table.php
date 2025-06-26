<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

use function FOfX\ApiCache\create_dataforseo_backlinks_bulk_items_table;

return new class () extends Migration {
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        create_dataforseo_backlinks_bulk_items_table($schema, 'dataforseo_backlinks_bulk_items', false);
    }

    public function down(): void
    {
        Schema::dropIfExists('dataforseo_backlinks_bulk_items');
    }
};

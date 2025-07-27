<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

use function FOfX\ApiCache\create_dataforseo_labs_google_keyword_research_items_table;

return new class () extends Migration {
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        create_dataforseo_labs_google_keyword_research_items_table($schema, 'dataforseo_labs_google_keyword_research_items', false);
    }

    public function down(): void
    {
        Schema::dropIfExists('dataforseo_labs_google_keyword_research_items');
    }
};

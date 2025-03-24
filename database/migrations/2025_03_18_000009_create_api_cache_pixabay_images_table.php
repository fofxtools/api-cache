<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

use function FOfX\ApiCache\create_pixabay_images_table;

return new class () extends Migration {
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        create_pixabay_images_table($schema, 'api_cache_pixabay_images', false);
    }

    public function down(): void
    {
        Schema::dropIfExists('api_cache_pixabay_images');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('api_cache_pixabay_images', function (Blueprint $table) use ($driver) {
            // Primary field to auto increment the row number
            $table->id('row_id');

            // The Pixabay API response ID
            $table->unsignedBigInteger('id')->unique();

            // Other API response fields
            $table->string('pageURL')->nullable();
            $table->string('type')->nullable()->index();
            $table->text('tags')->nullable();

            // Preview image data
            $table->string('previewURL')->nullable();
            $table->unsignedInteger('previewWidth')->nullable();
            $table->unsignedInteger('previewHeight')->nullable();

            // Web format image data
            $table->string('webformatURL')->nullable();
            $table->unsignedInteger('webformatWidth')->nullable();
            $table->unsignedInteger('webformatHeight')->nullable();

            // Large image data
            $table->string('largeImageURL')->nullable();

            // The following response key/value pairs are only available if your account has been approved for full API access
            $table->string('fullHDURL')->nullable();
            $table->string('imageURL')->nullable();
            $table->string('vectorURL')->nullable();

            // Image dimensions and size
            $table->unsignedInteger('imageWidth')->nullable();
            $table->unsignedInteger('imageHeight')->nullable();
            $table->unsignedInteger('imageSize')->nullable()->index();

            // Statistics
            $table->unsignedBigInteger('views')->nullable()->index();
            $table->unsignedBigInteger('downloads')->nullable()->index();
            $table->unsignedBigInteger('collections')->nullable()->index();
            $table->unsignedBigInteger('likes')->nullable()->index();
            $table->unsignedBigInteger('comments')->nullable()->index();

            // User information
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user')->nullable()->index();
            $table->string('userImageURL')->nullable();

            // Timestamps
            $table->timestamps();

            // Local storage fields (will be modified to MEDIUMBLOB/LONGBLOB later)
            $table->binary('file_contents_preview')->nullable();
            $table->binary('file_contents_webformat')->nullable();
            $table->binary('file_contents_largeImage')->nullable();

            // File metadata
            $table->unsignedInteger('filesize_preview')->nullable()->index();
            $table->unsignedInteger('filesize_webformat')->nullable()->index();
            $table->unsignedInteger('filesize_largeImage')->nullable()->index();
            $table->string('mime_type')->nullable();

            // Local storage paths
            $table->string('storage_filepath_preview')->nullable();
            $table->string('storage_filepath_webformat')->nullable();
            $table->string('storage_filepath_largeImage')->nullable();

            // Processing information
            $table->timestamp('processed_at')->nullable()->index();
            $table->json('processed_status')->nullable()->index();

            // Add fulltext indexes
            if ($driver === 'mysql' || $driver === 'pgsql') {
                $table->fullText('tags');
            }
        });

        // Modify binary columns based on database driver
        if ($driver === 'mysql') {
            Schema::getConnection()->statement('
                ALTER TABLE api_cache_pixabay_images
                MODIFY file_contents_preview MEDIUMBLOB,
                MODIFY file_contents_webformat MEDIUMBLOB,
                MODIFY file_contents_largeImage LONGBLOB
            ');
        } elseif ($driver === 'sqlsrv') {
            Schema::getConnection()->statement('
                ALTER TABLE api_cache_pixabay_images
                ALTER COLUMN file_contents_preview VARBINARY(MAX),
                ALTER COLUMN file_contents_webformat VARBINARY(MAX),
                ALTER COLUMN file_contents_largeImage VARBINARY(MAX)
            ');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_cache_pixabay_images');
    }
};

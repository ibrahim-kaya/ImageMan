<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the imageman_images table which stores metadata for every
     * uploaded image including its variants, disk location, and processing info.
     */
    public function up(): void
    {
        Schema::create('imageman_images', function (Blueprint $table) {
            $table->id();

            // Polymorphic relationship — links the image to any Eloquent model
            // (e.g. User, Post, Product). Nullable for standalone uploads.
            $table->nullableMorphs('imageable');

            // Logical grouping of images within a model (e.g. 'avatar', 'gallery').
            // Allows a single model to have multiple image collections.
            $table->string('collection')->default('default');

            // The filesystem disk where the image is stored.
            // Corresponds to a disk defined in config/filesystems.php.
            $table->string('disk');

            // The directory path within the disk (e.g. 'images/550e8400-e29b').
            $table->string('directory');

            // The stored filename (UUID-based, e.g. '550e8400-e29b.webp').
            $table->string('filename');

            // The original filename as provided by the uploader before processing.
            $table->string('original_filename');

            // MIME type of the stored file (after conversion, e.g. 'image/webp').
            $table->string('mime_type');

            // File size in bytes of the main stored file (not the original).
            $table->unsignedInteger('size')->default(0);

            // Dimensions of the main stored image (not the original).
            $table->unsignedSmallInteger('width')->default(0);
            $table->unsignedSmallInteger('height')->default(0);

            // SHA-256 hash of the original uploaded file contents.
            // Used for duplicate detection — see config('imageman.detect_duplicates').
            $table->string('hash', 64)->nullable()->index();

            // Base64-encoded low-quality image placeholder (LQIP).
            // Tiny blurred version for smooth lazy-loading transitions on the frontend.
            $table->text('lqip')->nullable();

            // JSON map of generated size variants.
            // Structure: { "thumbnail": { "path": "…", "width": 150, "height": 150, "size": 12345 }, … }
            $table->json('variants')->nullable();

            // Whether EXIF metadata was stripped from this image during processing.
            $table->boolean('exif_stripped')->default(true);

            // Arbitrary metadata attached at upload time (alt text, title, custom fields, etc.).
            // Structure: { "alt": "…", "title": "…" }
            $table->json('meta')->nullable();

            $table->timestamps();

            // Composite index for fast lookup by model + collection (most common query).
            $table->index(['imageable_type', 'imageable_id', 'collection'], 'imageman_model_collection');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imageman_images');
    }
};

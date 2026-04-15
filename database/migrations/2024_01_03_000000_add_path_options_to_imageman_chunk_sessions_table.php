<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds path-customisation columns to imageman_chunk_sessions.
 *
 * These columns mirror the fluent ImageUploader methods so that
 * ->inDirectory(), ->filename(), and ->noUuid() can be forwarded to the
 * assembler after all chunks have arrived.
 *
 * Added columns:
 *   target_directory  — forwarded to ->inDirectory()
 *   target_filename   — forwarded to ->filename()
 *   target_no_uuid    — forwarded to ->noUuid() when true
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imageman_chunk_sessions', function (Blueprint $table) {
            // Custom subdirectory between the base path and the UUID folder.
            // Equivalent to ImageUploader::inDirectory(). Each segment is slugified.
            // Example: 'products/phones' → images/products/phones/{uuid}/file.webp
            $table->string('target_directory')->nullable()->after('target_meta');

            // Custom filename stem (without extension) for the assembled image.
            // Equivalent to ImageUploader::filename().
            // When null, a UUID is used as the stem (default behaviour).
            $table->string('target_filename')->nullable()->after('target_directory');

            // When true, the UUID subfolder is omitted from the storage path.
            // Equivalent to ImageUploader::noUuid().
            // Useful for fully deterministic, stable URLs (e.g. user avatars).
            $table->boolean('target_no_uuid')->default(false)->after('target_filename');
        });
    }

    public function down(): void
    {
        Schema::table('imageman_chunk_sessions', function (Blueprint $table) {
            $table->dropColumn(['target_directory', 'target_filename', 'target_no_uuid']);
        });
    }
};

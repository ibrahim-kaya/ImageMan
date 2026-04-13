<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the imageman_chunk_sessions table.
 *
 * This table tracks browser-initiated chunked upload sessions. Each row
 * represents one multi-part upload in progress (or completed/failed).
 * Chunk files are stored on the local disk under chunk_directory and are
 * cleaned up after assembly. Stale sessions are removed by the
 * imageman:clean-chunks Artisan command.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imageman_chunk_sessions', function (Blueprint $table) {
            // UUID primary key — generated at initiation and used in every
            // subsequent chunk request URL. String type for URL safety.
            $table->uuid('id')->primary();

            // Original file information sent by the client at initiation.
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('total_size');    // Full file size in bytes
            $table->unsignedInteger('total_chunks');     // How many chunks the file was split into

            // JSON array of integer chunk indices already received: [0, 1, 3, …]
            // Used to detect completion and to support resumable uploads.
            $table->json('received_chunks');

            // Temporary chunk storage location on the local disk.
            // Pattern: imageman_chunks/{upload_id}
            $table->string('chunk_directory');

            // Context forwarded to ImageUploader after all chunks arrive.
            $table->string('target_disk')->nullable();
            $table->string('target_collection')->default('default');
            $table->json('target_meta')->nullable();

            // Polymorphic model association (optional).
            $table->string('imageable_type')->nullable();
            $table->unsignedBigInteger('imageable_id')->nullable();

            // Upload lifecycle status.
            // uploading  → chunks are being received
            // assembling → all chunks received; merge in progress
            // processing → merged file dispatched to ImageUploader / queue
            // complete   → Image record created; image_id is populated
            // failed     → assembly or processing failed; see error_message
            $table->string('status')->default('uploading');

            // Populated once ImageUploader::save() returns successfully.
            $table->unsignedBigInteger('image_id')->nullable();

            // Populated when status = 'failed'.
            $table->text('error_message')->nullable();

            // Updated on every received chunk. Used by clean-chunks to identify
            // stalled sessions without touching created_at (which stays fixed).
            $table->timestamp('last_chunk_at')->nullable();

            $table->timestamps();

            // Index for the clean-chunks command (finds stale sessions quickly).
            $table->index('status');
            $table->index('last_chunk_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imageman_chunk_sessions');
    }
};

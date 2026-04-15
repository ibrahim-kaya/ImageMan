<?php

namespace IbrahimKaya\ImageMan\Chunk;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use IbrahimKaya\ImageMan\ImageManager;
use IbrahimKaya\ImageMan\Models\ChunkSession;

/**
 * Core service for the chunked upload system.
 *
 * Responsibilities:
 *   1. Store individual received chunks to the local disk.
 *   2. Assemble all chunks into a single file and feed it through the
 *      standard ImageUploader pipeline.
 *   3. Clean up temporary chunk files after assembly (or on abort).
 *
 * This class has no HTTP coupling — it operates on ChunkSession model
 * instances and can be called from both a controller (sync) and a queue
 * job (async assembly).
 */
class ChunkAssembler
{
    public function __construct(private ImageManager $imageManager) {}

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Persist a single received chunk to the local chunk directory.
     *
     * The chunk is stored as:  {chunk_directory}/{index}.part
     *
     * The received_chunks JSON array and last_chunk_at timestamp are updated
     * atomically in a single DB write.
     *
     * @param  ChunkSession $session    The active upload session.
     * @param  int          $index      0-based chunk index.
     * @param  UploadedFile $chunkFile  The raw chunk uploaded by the client.
     */
    public function storeChunk(ChunkSession $session, int $index, UploadedFile $chunkFile): void
    {
        $path = $session->chunk_directory . '/' . $index . '.part';

        Storage::disk('local')->put($path, $chunkFile->get());

        $received   = $session->received_chunks ?? [];
        $received[] = $index;
        sort($received); // Keep sorted for gap-detection convenience.

        $session->update([
            'received_chunks' => $received,
            'last_chunk_at'   => now(),
        ]);
    }

    /**
     * Assemble all stored chunks into a single file and run it through the
     * ImageUploader pipeline.
     *
     * Steps:
     *   1. Set status = 'assembling'.
     *   2. Validate all .part files exist on disk.
     *   3. Stream-merge parts in order into a temp file (O(1) memory).
     *   4. Wrap the temp file in an UploadedFile instance.
     *   5. Build an ImageUploader with the session's stored context.
     *   6. Call ->save(), which handles both sync and queue paths internally.
     *   7. Update session status to 'processing' or 'complete'.
     *   8. Delete chunk files.
     *
     * @param  ChunkSession $session  The session with all chunks received.
     * @throws \RuntimeException  If a chunk file is missing or temp file fails.
     */
    public function assemble(ChunkSession $session): void
    {
        $session->update(['status' => 'assembling']);

        // --- Validate all parts exist before starting ---
        for ($i = 0; $i < $session->total_chunks; $i++) {
            $partPath = $session->chunk_directory . '/' . $i . '.part';
            if (!Storage::disk('local')->exists($partPath)) {
                $session->update([
                    'status'        => 'failed',
                    'error_message' => "Chunk {$i} is missing from disk. Upload may be corrupted.",
                ]);
                return;
            }
        }

        // --- Stream all .part files into a single temp file ---
        // stream_copy_to_stream() uses an 8 KB read buffer, so memory usage
        // is O(1) regardless of the total assembled file size.
        $tmpPath = tempnam(sys_get_temp_dir(), 'imageman_assembled_');

        try {
            $out = fopen($tmpPath, 'wb');

            for ($i = 0; $i < $session->total_chunks; $i++) {
                $partAbsPath = Storage::disk('local')->path(
                    $session->chunk_directory . '/' . $i . '.part'
                );
                $in = fopen($partAbsPath, 'rb');
                stream_copy_to_stream($in, $out);
                fclose($in);
            }

            fclose($out);

            // --- Wrap in UploadedFile ---
            // test = true skips PHP's is_uploaded_file() check, which only
            // passes for files that arrived via an actual HTTP file upload.
            // This is the same pattern used by ProcessImageJob and UrlImageFetcher.
            $uploadedFile = new UploadedFile(
                $tmpPath,
                $session->original_filename,
                $session->mime_type,
                null,
                true, // test mode
            );

            // --- Build and execute the uploader ---
            $uploader = $this->imageManager->upload($uploadedFile)
                ->collection($session->target_collection ?? 'default')
                ->skipValidation(); // Chunk system already enforced size/type gates.

            if ($session->target_disk) {
                $uploader->disk($session->target_disk);
            }

            if ($session->target_meta) {
                $uploader->meta($session->target_meta);
            }

            // Forward path-customisation options stored at initiation time.
            // These mirror the fluent ImageUploader methods so the assembled
            // image lands at exactly the same path as a regular upload would.
            if ($session->target_directory) {
                $uploader->inDirectory($session->target_directory);
            }

            if ($session->target_filename) {
                $uploader->filename($session->target_filename);
            }

            if ($session->target_no_uuid) {
                $uploader->noUuid();
            }

            // Reconstruct the polymorphic model association if provided.
            if ($session->imageable_type && $session->imageable_id) {
                $modelClass = $session->imageable_type;
                if (class_exists($modelClass)) {
                    $model = $modelClass::find($session->imageable_id);
                    if ($model) {
                        $uploader->for($model);
                    }
                }
            }

            $image = $uploader->save();

            // When config('imageman.queue') = true, save() returns a placeholder
            // Image with empty directory/filename — processing is not yet done.
            // The ImageProcessed event listener in the ServiceProvider will flip
            // the session status to 'complete' once the job finishes.
            $isQueued = empty($image->directory) && empty($image->filename);

            $session->update([
                'image_id' => $image->id,
                'status'   => $isQueued ? 'processing' : 'complete',
            ]);

        } catch (\Throwable $e) {
            $session->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        } finally {
            // Remove the assembled temp file regardless of success or failure.
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }

            // Remove .part files — they are no longer needed after assembly.
            $this->cleanup($session);
        }
    }

    /**
     * Delete all chunk files and the session's chunk directory.
     *
     * Does NOT delete the ChunkSession DB record — the caller (controller
     * abort action or clean-chunks command) is responsible for that.
     *
     * @param  ChunkSession $session
     */
    public function cleanup(ChunkSession $session): void
    {
        try {
            Storage::disk('local')->deleteDirectory($session->chunk_directory);
        } catch (\Throwable) {
            // Non-fatal — best-effort cleanup.
        }
    }
}

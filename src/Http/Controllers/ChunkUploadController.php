<?php

namespace IbrahimKaya\ImageMan\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use IbrahimKaya\ImageMan\Chunk\ChunkAssembler;
use IbrahimKaya\ImageMan\Jobs\AssembleChunksJob;
use IbrahimKaya\ImageMan\Models\ChunkSession;

/**
 * Handles all HTTP endpoints for the chunked upload system.
 *
 * Endpoints:
 *   POST   /imageman/chunks/initiate          → initiate()
 *   POST   /imageman/chunks/{uploadId}        → upload()
 *   GET    /imageman/chunks/{uploadId}/status → status()
 *   DELETE /imageman/chunks/{uploadId}        → abort()
 *
 * No Eloquent model association context is accepted at chunk-upload time
 * (only at initiation). The controller has no direct I/O coupling with the
 * ImageUploader — all assembly logic lives in ChunkAssembler.
 */
class ChunkUploadController extends Controller
{
    public function __construct(private ChunkAssembler $assembler) {}

    // -----------------------------------------------------------------------
    // POST /imageman/chunks/initiate
    // -----------------------------------------------------------------------

    /**
     * Start a new chunked upload session.
     *
     * The client provides metadata about the full file and optionally the
     * ImageUploader context (disk, collection, meta, model association).
     * The server assigns a UUID upload_id and returns the expected chunk_size
     * so the client knows how to split the file.
     *
     * Request body (JSON or form-data):
     *   filename        string   required  Original file name (e.g. "photo.jpg")
     *   mime_type       string   required  MIME type (e.g. "image/jpeg")
     *   total_size      integer  required  Total file size in bytes
     *   total_chunks    integer  required  Number of chunks the file is split into
     *   collection      string   optional  Target collection (default: "default")
     *   disk            string   optional  Target storage disk
     *   meta            object   optional  Arbitrary metadata
     *   directory       string   optional  Forwarded to ->inDirectory() — custom subdirectory
     *   filename        string   optional  Forwarded to ->filename()    — custom filename stem
     *   no_uuid         bool     optional  Forwarded to ->noUuid()      — omit UUID subfolder
     *   imageable_type  string   optional  Eloquent model FQCN for polymorphic link
     *   imageable_id    integer  optional  Eloquent model PK for polymorphic link
     *
     * Response 201:
     *   upload_id     string   UUID to use in subsequent chunk requests
     *   chunk_size    integer  Recommended chunk size in bytes (from config)
     *   total_chunks  integer  Echo of the requested total_chunks
     */
    public function initiate(Request $request): JsonResponse
    {
        $chunksConfig = config('imageman.chunks', []);

        if (!($chunksConfig['enabled'] ?? true)) {
            return response()->json(['message' => 'Chunked uploads are disabled.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'filename'       => ['required', 'string', 'max:255'],
            'mime_type'      => ['required', 'string', 'in:' . implode(',', config('imageman.validation.allowed_mimes', [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif',
            ]))],
            'total_size'     => ['required', 'integer', 'min:1', 'max:' . ($chunksConfig['max_total_size'] ?? 512 * 1024 * 1024)],
            'total_chunks'   => ['required', 'integer', 'min:1', 'max:' . ($chunksConfig['max_chunks'] ?? 500)],
            'collection'     => ['sometimes', 'string', 'max:100'],
            'disk'           => ['sometimes', 'string', 'max:50'],
            'meta'           => ['sometimes', 'array'],
            'directory'      => ['sometimes', 'nullable', 'string', 'max:500'],
            'filename'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'no_uuid'        => ['sometimes', 'boolean'],
            'imageable_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'imageable_id'   => ['sometimes', 'nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $uploadId  = (string) Str::uuid();
        $chunkDir  = 'imageman_chunks/' . $uploadId;

        $session = ChunkSession::create([
            'id'                => $uploadId,
            'original_filename' => $request->input('filename'),
            'mime_type'         => $request->input('mime_type'),
            'total_size'        => (int) $request->input('total_size'),
            'total_chunks'      => (int) $request->input('total_chunks'),
            'received_chunks'   => [],
            'chunk_directory'   => $chunkDir,
            'target_disk'       => $request->input('disk'),
            'target_collection' => $request->input('collection', 'default'),
            'target_meta'       => $request->input('meta'),
            'target_directory'  => $request->input('directory'),
            'target_filename'   => $request->input('filename'),
            'target_no_uuid'    => (bool) $request->input('no_uuid', false),
            'imageable_type'    => $request->input('imageable_type'),
            'imageable_id'      => $request->input('imageable_id') ? (int) $request->input('imageable_id') : null,
            'status'            => 'uploading',
        ]);

        return response()->json([
            'upload_id'    => $session->id,
            'chunk_size'   => $chunksConfig['chunk_size'] ?? 2 * 1024 * 1024,
            'total_chunks' => $session->total_chunks,
        ], 201);
    }

    // -----------------------------------------------------------------------
    // POST /imageman/chunks/{uploadId}
    // -----------------------------------------------------------------------

    /**
     * Receive a single chunk and store it on the local disk.
     *
     * When the final chunk is received and all chunks are accounted for,
     * assembly is triggered either synchronously or via a queue job depending
     * on the `assemble_on_queue` config (which falls back to `imageman.queue`).
     *
     * This endpoint is idempotent: if a chunk index has already been stored
     * (e.g. client retried after a network drop), a 200 is returned immediately
     * without double-storing the chunk.
     *
     * Request (multipart/form-data):
     *   chunk        file     required  The raw chunk binary
     *   chunk_index  integer  required  0-based index of this chunk
     *
     * Response 200:
     *   status           string   Current session status
     *   received_chunks  array    All received chunk indices so far
     *   total_chunks     integer  Total expected chunks
     *   complete         bool     True when all chunks have been received
     */
    public function upload(Request $request, string $uploadId): JsonResponse
    {
        $session = ChunkSession::find($uploadId);

        if (!$session) {
            return response()->json(['message' => 'Upload session not found.'], 404);
        }

        // Guard: session must still be in the uploading state.
        if ($session->status !== 'uploading') {
            return response()->json([
                'message' => "Upload session is in '{$session->status}' state and cannot accept new chunks.",
                'status'  => $session->status,
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'chunk'       => ['required', 'file'],
            'chunk_index' => ['required', 'integer', 'min:0', 'max:' . ($session->total_chunks - 1)],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        // Validate chunk file size against the server-side hard limit.
        $chunksConfig  = config('imageman.chunks', []);
        $maxChunkBytes = $chunksConfig['max_chunk_size'] ?? 5 * 1024 * 1024;
        $chunkFile     = $request->file('chunk');

        if ($chunkFile->getSize() > $maxChunkBytes) {
            return response()->json([
                'message' => "Chunk exceeds the maximum allowed size of {$maxChunkBytes} bytes.",
            ], 422);
        }

        $index    = (int) $request->input('chunk_index');
        $received = $session->received_chunks ?? [];

        // Idempotency guard: return success if this chunk already arrived.
        if (in_array($index, $received, true)) {
            return response()->json([
                'status'          => $session->status,
                'received_chunks' => $received,
                'total_chunks'    => $session->total_chunks,
                'complete'        => $session->isComplete(),
            ]);
        }

        $this->assembler->storeChunk($session, $index, $chunkFile);

        // Refresh to get the updated received_chunks array.
        $session->refresh();

        $isComplete = $session->isComplete();

        if ($isComplete) {
            $useQueue = $chunksConfig['assemble_on_queue'] ?? config('imageman.queue', false);

            if ($useQueue) {
                AssembleChunksJob::dispatch($session->id)
                    ->onConnection(config('imageman.queue_connection', 'sync'))
                    ->onQueue(config('imageman.queue_name', 'images'));

                $session->update(['status' => 'assembling']);
            } else {
                $this->assembler->assemble($session);
                $session->refresh();
            }
        }

        return response()->json([
            'status'          => $session->status,
            'received_chunks' => $session->received_chunks,
            'total_chunks'    => $session->total_chunks,
            'complete'        => $isComplete,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /imageman/chunks/{uploadId}/status
    // -----------------------------------------------------------------------

    /**
     * Return the current state of the upload session.
     *
     * The client polls this endpoint after all chunks have been sent to
     * determine when assembly is complete and the image_id is available.
     *
     * Response 200:
     *   status           string       uploading | assembling | processing | complete | failed
     *   received_chunks  int[]        Indices of chunks that have arrived
     *   missing_chunks   int[]        Indices still expected (useful for resume)
     *   total_chunks     integer
     *   image_id         int|null     Populated when status = 'complete' or 'processing'
     *   error_message    string|null  Populated when status = 'failed'
     */
    public function status(string $uploadId): JsonResponse
    {
        $session = ChunkSession::find($uploadId);

        if (!$session) {
            return response()->json(['message' => 'Upload session not found.'], 404);
        }

        return response()->json([
            'status'          => $session->status,
            'received_chunks' => $session->received_chunks ?? [],
            'missing_chunks'  => $session->missingChunks(),
            'total_chunks'    => $session->total_chunks,
            'image_id'        => $session->image_id,
            'error_message'   => $session->error_message,
        ]);
    }

    // -----------------------------------------------------------------------
    // DELETE /imageman/chunks/{uploadId}
    // -----------------------------------------------------------------------

    /**
     * Abort the upload session.
     *
     * Deletes all chunk files from the local disk and removes the DB record.
     * Returns 204 No Content on success.
     * Returns 404 if the session does not exist (idempotent: safe to call twice).
     */
    public function abort(string $uploadId): JsonResponse
    {
        $session = ChunkSession::find($uploadId);

        if (!$session) {
            return response()->json(['message' => 'Upload session not found.'], 404);
        }

        $this->assembler->cleanup($session);
        $session->delete();

        return response()->json(null, 204);
    }
}

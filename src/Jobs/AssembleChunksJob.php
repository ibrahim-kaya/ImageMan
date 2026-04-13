<?php

namespace IbrahimKaya\ImageMan\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use IbrahimKaya\ImageMan\Chunk\ChunkAssembler;
use IbrahimKaya\ImageMan\Models\ChunkSession;

/**
 * Background queue job that assembles a completed chunked upload.
 *
 * Dispatched by ChunkUploadController::upload() when all chunks have been
 * received and config('imageman.chunks.assemble_on_queue') is true.
 *
 * The job fetches the ChunkSession by its UUID, delegates all assembly logic
 * to ChunkAssembler::assemble(), and then cleans up on completion.
 * Error handling (status = 'failed', error_message) is managed entirely
 * inside ChunkAssembler::assemble(), so this job never needs to catch exceptions.
 */
class AssembleChunksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  string $sessionId  The UUID primary key of the ChunkSession record.
     */
    public function __construct(public readonly string $sessionId) {}

    /**
     * Execute the job.
     *
     * Looks up the session and triggers assembly.
     * If the session no longer exists (e.g. aborted by the user before the job ran),
     * the job exits silently — no exception, no retry.
     */
    public function handle(ChunkAssembler $assembler): void
    {
        $session = ChunkSession::find($this->sessionId);

        if (!$session) {
            return; // Session was aborted before this job ran — nothing to do.
        }

        // Guard against duplicate job dispatches (e.g. retry after a failure
        // that already reached 'complete'). Do not re-assemble finished uploads.
        if (in_array($session->status, ['complete', 'failed'], true)) {
            return;
        }

        $assembler->assemble($session);
    }
}

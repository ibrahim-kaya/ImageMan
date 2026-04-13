<?php

namespace IbrahimKaya\ImageMan\Console;

use Illuminate\Console\Command;
use IbrahimKaya\ImageMan\Chunk\ChunkAssembler;
use IbrahimKaya\ImageMan\Models\ChunkSession;

/**
 * Artisan command to remove stale/orphaned chunked upload sessions.
 *
 * A session is considered stale when it has not received a new chunk within
 * the configured TTL window (config: imageman.chunks.session_ttl, default 24h).
 *
 * Usage:
 *   php artisan imageman:clean-chunks
 *   php artisan imageman:clean-chunks --dry-run
 *   php artisan imageman:clean-chunks --older-than=48   # hours
 *   php artisan imageman:clean-chunks --status=failed
 *
 * Recommended scheduling (App\Console\Kernel):
 *   $schedule->command('imageman:clean-chunks')->daily()->withoutOverlapping();
 */
class CleanChunksCommand extends Command
{
    protected $signature = 'imageman:clean-chunks
                            {--dry-run    : List stale sessions without deleting them}
                            {--older-than= : Override the session TTL in hours}
                            {--status=    : Only target sessions with this specific status}';

    protected $description = 'Delete stale or failed chunked upload sessions and their temporary chunk files.';

    public function handle(ChunkAssembler $assembler): int
    {
        $ttlHours = (int) ($this->option('older-than') ?: (config('imageman.chunks.session_ttl', 86400) / 3600));
        $cutoff   = now()->subHours($ttlHours);
        $dryRun   = (bool) $this->option('dry-run');
        $status   = $this->option('status');

        $this->info(sprintf(
            'Scanning for chunk sessions inactive since %s%s…',
            $cutoff->toDateTimeString(),
            $status ? " with status={$status}" : '',
        ));

        // Build query: sessions where last_chunk_at (or created_at if never received
        // a chunk) is older than the cutoff. Exclude sessions that are still actively
        // assembling or processing — those are handled by the queue worker.
        $query = ChunkSession::where(function ($q) use ($cutoff) {
            $q->where('last_chunk_at', '<', $cutoff)
              ->orWhere(function ($q2) use ($cutoff) {
                  $q2->whereNull('last_chunk_at')
                     ->where('created_at', '<', $cutoff);
              });
        })->whereNotIn('status', ['assembling', 'processing']);

        if ($status) {
            $query->where('status', $status);
        }

        $sessions = $query->get();

        if ($sessions->isEmpty()) {
            $this->info('No stale sessions found.');
            return self::SUCCESS;
        }

        $headers = ['ID', 'Status', 'Filename', 'Chunks', 'Last Activity'];
        $rows    = $sessions->map(fn (ChunkSession $s) => [
            $s->id,
            $s->status,
            $s->original_filename,
            count($s->received_chunks ?? []) . '/' . $s->total_chunks,
            ($s->last_chunk_at ?? $s->created_at)?->diffForHumans() ?? 'never',
        ])->all();

        $this->table($headers, $rows);

        if ($dryRun) {
            $this->comment(sprintf('[dry-run] Would delete %d session(s). Run without --dry-run to apply.', count($sessions)));
            return self::SUCCESS;
        }

        $deleted = 0;
        foreach ($sessions as $session) {
            $assembler->cleanup($session);
            $session->delete();
            $deleted++;
        }

        $this->info("Deleted {$deleted} stale session(s) and their chunk files.");

        return self::SUCCESS;
    }
}

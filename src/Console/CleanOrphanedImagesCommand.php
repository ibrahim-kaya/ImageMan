<?php

namespace IbrahimKaya\ImageMan\Console;

use Illuminate\Console\Command;
use IbrahimKaya\ImageMan\Models\Image;

/**
 * Find and remove Image records that are not associated with any model
 * (orphaned images — imageable_type and imageable_id are both null).
 *
 * Use --dry-run to preview what would be deleted without actually removing anything.
 *
 * Usage:
 *   php artisan imageman:clean
 *   php artisan imageman:clean --dry-run
 *   php artisan imageman:clean --collection=gallery
 *   php artisan imageman:clean --older-than=30
 */
class CleanOrphanedImagesCommand extends Command
{
    protected $signature = 'imageman:clean
                            {--dry-run     : Preview deletions without actually removing anything}
                            {--collection= : Limit to a specific collection}
                            {--older-than= : Only delete images older than N days}
                            {--chunk=100   : Process in chunks of this size}';

    protected $description = 'Delete orphaned images (not associated with any model)';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $query = Image::query()
            ->whereNull('imageable_type')
            ->whereNull('imageable_id');

        if ($collection = $this->option('collection')) {
            $query->where('collection', $collection);
        }

        if ($olderThan = $this->option('older-than')) {
            $query->where('created_at', '<', now()->subDays((int) $olderThan));
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('No orphaned images found.');
            return self::SUCCESS;
        }

        if ($isDryRun) {
            $this->warn("[Dry run] Would delete {$total} orphaned image(s):");
            $query->chunk((int) $this->option('chunk'), function ($images) {
                foreach ($images as $image) {
                    $this->line("  #{$image->id} | {$image->disk}:{$image->directory}/{$image->filename} | {$image->created_at}");
                }
            });
            return self::SUCCESS;
        }

        if (!$this->confirm("Delete {$total} orphaned image(s)? This will also remove the files from disk.")) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        $deleted = 0;
        $bar     = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunk((int) $this->option('chunk'), function ($images) use (&$deleted, $bar) {
            foreach ($images as $image) {
                try {
                    $image->delete(); // Removes disk files + DB row.
                    $deleted++;
                } catch (\Throwable $e) {
                    $this->warn(" Failed to delete image #{$image->id}: {$e->getMessage()}");
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Deleted {$deleted} orphaned image(s).");

        return self::SUCCESS;
    }
}

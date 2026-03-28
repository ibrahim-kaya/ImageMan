<?php

namespace IbrahimKaya\ImageMan\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use IbrahimKaya\ImageMan\Models\Image;
use IbrahimKaya\ImageMan\Processors\ImageManipulator;

/**
 * Regenerate size variants for all (or a filtered subset of) stored images.
 *
 * Useful when you add a new size preset to config/imageman.php and want to
 * backfill the variant for all existing images, or when you change quality
 * settings and want to re-encode existing files.
 *
 * Usage:
 *   php artisan imageman:regenerate
 *   php artisan imageman:regenerate --size=thumbnail
 *   php artisan imageman:regenerate --collection=avatars
 *   php artisan imageman:regenerate --disk=s3
 */
class RegenerateVariantsCommand extends Command
{
    protected $signature = 'imageman:regenerate
                            {--size=    : Regenerate only this named size preset}
                            {--collection= : Limit to images in this collection}
                            {--disk=    : Limit to images on this disk}
                            {--chunk=100 : Process images in chunks of this size}';

    protected $description = 'Regenerate size variants for stored images';

    public function handle(ImageManipulator $manipulator): int
    {
        $config     = config('imageman');
        $sizeFilter = $this->option('size');
        $sizes      = $sizeFilter ? [$sizeFilter] : array_keys($config['sizes'] ?? []);

        if (empty($sizes)) {
            $this->error('No size presets are configured in config/imageman.php.');
            return self::FAILURE;
        }

        $query = Image::query();

        if ($collection = $this->option('collection')) {
            $query->where('collection', $collection);
        }

        if ($disk = $this->option('disk')) {
            $query->where('disk', $disk);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('No images found matching the given filters.');
            return self::SUCCESS;
        }

        $this->info("Regenerating variants for {$total} image(s)…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $chunkSize = (int) $this->option('chunk');

        $query->chunk($chunkSize, function ($images) use ($manipulator, $config, $sizes, $bar) {
            foreach ($images as $image) {
                $this->regenerateForImage($image, $manipulator, $config, $sizes);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }

    private function regenerateForImage(Image $image, ImageManipulator $manipulator, array $config, array $sizes): void
    {
        try {
            $disk         = Storage::disk($image->disk);
            $mainFilePath = $image->directory . '/' . $image->filename;

            if (!$disk->exists($mainFilePath)) {
                $this->warn(" Skipping image #{$image->id}: main file not found on disk.");
                return;
            }

            // Write the main file to a temp path for the manipulator to read.
            $tmpPath = tempnam(sys_get_temp_dir(), 'imageman_regen_') . '.' . pathinfo($image->filename, PATHINFO_EXTENSION);
            file_put_contents($tmpPath, $disk->get($mainFilePath));

            $processConfig = array_merge($config, [
                'format'          => pathinfo($image->filename, PATHINFO_EXTENSION),
                'keep_original'   => false,
                'generate_lqip'   => false,
                'requested_sizes' => $sizes,
                'watermark'       => ['enabled' => false],
            ]);

            // Build a fake UploadedFile for the manipulator.
            $uploadedFile = new \Illuminate\Http\UploadedFile(
                $tmpPath,
                $image->original_filename,
                $image->mime_type,
                null,
                true,
            );

            $processed    = $manipulator->process($uploadedFile, $processConfig);
            $ext          = pathinfo($image->filename, PATHINFO_EXTENSION);
            $existingVariants = $image->variants ?? [];

            foreach ($processed->variants as $name => $variant) {
                $variantFilename = pathinfo($image->filename, PATHINFO_FILENAME) . '_' . $name . '.' . $ext;
                $variantPath     = $image->directory . '/' . $variantFilename;

                $disk->put($variantPath, file_get_contents($variant->tempPath));
                @unlink($variant->tempPath);

                $existingVariants[$name] = [
                    'path'   => $variantPath,
                    'width'  => $variant->width,
                    'height' => $variant->height,
                    'size'   => $variant->size,
                ];
            }

            $image->update(['variants' => $existingVariants]);

            @unlink($tmpPath);
        } catch (\Throwable $e) {
            $this->warn(" Failed to regenerate image #{$image->id}: {$e->getMessage()}");
        }
    }
}

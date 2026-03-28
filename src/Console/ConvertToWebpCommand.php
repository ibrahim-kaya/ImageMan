<?php

namespace IbrahimKaya\ImageMan\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use IbrahimKaya\ImageMan\Models\Image;
use IbrahimKaya\ImageMan\Processors\ImageManipulator;

/**
 * Bulk-convert stored images from their current format to a target format.
 *
 * This is useful when you change the 'format' config from 'jpeg' to 'webp'
 * and want to convert all previously stored images without re-uploading them.
 *
 * The original files are replaced on disk; DB metadata is updated accordingly.
 *
 * Usage:
 *   php artisan imageman:convert
 *   php artisan imageman:convert --format=avif
 *   php artisan imageman:convert --format=webp --disk=s3
 *   php artisan imageman:convert --format=webp --dry-run
 */
class ConvertToWebpCommand extends Command
{
    protected $signature = 'imageman:convert
                            {--format=webp  : Target format: webp, avif, or jpeg}
                            {--disk=        : Limit to images on this disk}
                            {--dry-run      : Preview without actually converting}
                            {--chunk=50     : Process in chunks of this size}';

    protected $description = 'Bulk-convert stored images to a target format (webp, avif, jpeg)';

    public function handle(ImageManipulator $manipulator): int
    {
        $targetFormat = $this->option('format') ?: 'webp';
        $isDryRun     = (bool) $this->option('dry-run');
        $config       = config('imageman');

        if (!in_array($targetFormat, ['webp', 'avif', 'jpeg'], true)) {
            $this->error("Unsupported format [{$targetFormat}]. Choose: webp, avif, jpeg.");
            return self::FAILURE;
        }

        $query = Image::query();

        if ($disk = $this->option('disk')) {
            $query->where('disk', $disk);
        }

        // Skip images already in the target format.
        $targetMime = $this->mimeForFormat($targetFormat);
        $query->where('mime_type', '!=', $targetMime);

        $total = $query->count();

        if ($total === 0) {
            $this->info("No images to convert (all already in {$targetFormat} format).");
            return self::SUCCESS;
        }

        if ($isDryRun) {
            $this->warn("[Dry run] Would convert {$total} image(s) to {$targetFormat}.");
            return self::SUCCESS;
        }

        $this->info("Converting {$total} image(s) to {$targetFormat}…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $chunkSize = (int) $this->option('chunk');

        $query->chunk($chunkSize, function ($images) use ($manipulator, $config, $targetFormat, $bar) {
            foreach ($images as $image) {
                $this->convertImage($image, $manipulator, $config, $targetFormat);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Conversion complete.');

        return self::SUCCESS;
    }

    private function convertImage(Image $image, ImageManipulator $manipulator, array $config, string $targetFormat): void
    {
        try {
            $disk     = Storage::disk($image->disk);
            $mainPath = $image->directory . '/' . $image->filename;

            if (!$disk->exists($mainPath)) {
                $this->warn(" Skipping #{$image->id}: file not found.");
                return;
            }

            $tmpPath = tempnam(sys_get_temp_dir(), 'imageman_conv_');
            file_put_contents($tmpPath, $disk->get($mainPath));

            $uploadedFile = new \Illuminate\Http\UploadedFile(
                $tmpPath,
                $image->original_filename,
                $image->mime_type,
                null,
                true,
            );

            $processConfig = array_merge($config, [
                'format'          => $targetFormat,
                'keep_original'   => false,
                'generate_lqip'   => true,
                'requested_sizes' => array_keys($image->variants ?? []),
                'watermark'       => ['enabled' => false],
            ]);

            $processed = $manipulator->process($uploadedFile, $processConfig);
            $newExt    = $targetFormat === 'jpeg' ? 'jpg' : $targetFormat;
            $stem      = pathinfo($image->filename, PATHINFO_FILENAME);

            // Replace main file.
            $newFilename = $stem . '.' . $newExt;
            $disk->put($image->directory . '/' . $newFilename, file_get_contents($processed->mainPath));
            $disk->delete($mainPath);

            // Replace variant files.
            $newVariants = [];
            foreach ($processed->variants as $name => $variant) {
                $oldVariantPath = ($image->variants[$name]['path'] ?? null);
                $newVariantPath = $image->directory . '/' . $stem . '_' . $name . '.' . $newExt;

                $disk->put($newVariantPath, file_get_contents($variant->tempPath));
                if ($oldVariantPath && $disk->exists($oldVariantPath)) {
                    $disk->delete($oldVariantPath);
                }
                @unlink($variant->tempPath);

                $newVariants[$name] = [
                    'path'   => $newVariantPath,
                    'width'  => $variant->width,
                    'height' => $variant->height,
                    'size'   => $variant->size,
                ];
            }

            $image->update([
                'filename'  => $newFilename,
                'mime_type' => $this->mimeForFormat($targetFormat),
                'size'      => $processed->size,
                'lqip'      => $processed->lqip,
                'variants'  => $newVariants,
            ]);

            @unlink($tmpPath);
            @unlink($processed->mainPath);
        } catch (\Throwable $e) {
            $this->warn(" Failed to convert image #{$image->id}: {$e->getMessage()}");
        }
    }

    private function mimeForFormat(string $format): string
    {
        return match ($format) {
            'webp'  => 'image/webp',
            'avif'  => 'image/avif',
            'jpeg', 'jpg' => 'image/jpeg',
            default => 'image/webp',
        };
    }
}

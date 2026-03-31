<?php

namespace IbrahimKaya\ImageMan\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use IbrahimKaya\ImageMan\DTOs\VariantResult;
use IbrahimKaya\ImageMan\Events\ImageProcessed;
use IbrahimKaya\ImageMan\Models\Image;
use IbrahimKaya\ImageMan\Processors\ImageManipulator;

/**
 * Background job that executes the image processing pipeline for an upload
 * that was dispatched to the queue (config: imageman.queue = true).
 *
 * The job receives the ID of a placeholder Image record that was pre-inserted
 * by ImageUploader, along with a temporary copy of the uploaded file stored
 * on the local disk. It processes the file, moves the outputs to the target
 * disk, and updates the DB record with the final values.
 *
 * On failure the job lands in the failed_jobs table per standard Laravel
 * queue failure handling. The placeholder record is left in place with
 * empty path fields so the calling code can detect the failure state.
 */
class ProcessImageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Maximum number of retry attempts before the job is marked as failed. */
    public int $tries = 3;

    /** Seconds to wait before retrying after a failure. */
    public int $backoff = 10;

    public function __construct(
        protected int    $imageId,
        protected string $tmpDisk,
        protected string $tmpPath,
        protected array  $config,
    ) {}

    /**
     * Execute the image processing pipeline and update the Image record.
     */
    public function handle(ImageManipulator $manipulator): void
    {
        $image = Image::find($this->imageId);

        if ($image === null) {
            // Record was deleted before the job ran — nothing to do.
            $this->cleanupTempFile();
            return;
        }

        // Reconstruct a temporary UploadedFile from the stored temp copy.
        $tmpFullPath = Storage::disk($this->tmpDisk)->path($this->tmpPath);
        $uploadedFile = new UploadedFile(
            $tmpFullPath,
            $image->original_filename,
            $image->mime_type,
            null,
            true, // test mode — skips is_uploaded_file() check
        );

        // Run the processing pipeline.
        $processed = $manipulator->process($uploadedFile, $this->config);

        // Build the storage directory path using the same logic as ImageUploader:
        //   {base_path} / {custom_directory?} / {uuid?}
        $uuid      = (string) Str::uuid();
        $basePath  = trim($this->config['path'] ?? 'images', '/');
        $subDir    = $this->config['custom_directory'] ?? null;
        $uuidPart  = ($this->config['use_uuid'] ?? true) ? $uuid : null;
        $parts     = array_filter([$basePath, $subDir, $uuidPart]);
        $directory = implode('/', $parts);

        $disk = Storage::disk($this->config['disk'] ?? 'local');
        $ext  = $this->extensionForMime($processed->mimeType);

        // Use custom filename stem when provided, otherwise fall back to UUID.
        $stem = $this->config['filename_stem'] ?? $uuid;

        // Store main file.
        $mainFilename = $stem . '.' . $ext;
        $disk->put($directory . '/' . $mainFilename, file_get_contents($processed->mainPath));

        // Store variants.
        $variantsData = [];
        foreach ($processed->variants as $name => $variant) {
            /** @var VariantResult $variant */
            $variantFilename = $stem . '_' . $name . '.' . $ext;
            $variantPath     = $directory . '/' . $variantFilename;

            $disk->put($variantPath, file_get_contents($variant->tempPath));

            $variantsData[$name] = [
                'path'   => $variantPath,
                'width'  => $variant->width,
                'height' => $variant->height,
                'size'   => $variant->size,
            ];
        }

        // Update the placeholder record with real values.
        $image->update([
            'disk'      => $this->config['disk'] ?? 'local',
            'directory' => $directory,
            'filename'  => $mainFilename,
            'mime_type' => $processed->mimeType,
            'size'      => $processed->size,
            'width'     => $processed->width,
            'height'    => $processed->height,
            'hash'      => $processed->hash,
            'lqip'      => $processed->lqip,
            'variants'  => $variantsData,
        ]);

        // Clean up all temp files.
        foreach ($processed->allTempPaths() as $tmpFilePath) {
            if (file_exists($tmpFilePath)) {
                @unlink($tmpFilePath);
            }
        }

        $this->cleanupTempFile();

        event(new ImageProcessed($image, $variantsData));
    }

    /**
     * Handle a job failure — log and optionally mark the record as failed.
     */
    public function failed(\Throwable $exception): void
    {
        $this->cleanupTempFile();

        // Optionally update the record with a failure indicator.
        // Image::find($this->imageId)?->update(['meta->processing_failed' => true]);
    }

    private function cleanupTempFile(): void
    {
        try {
            Storage::disk($this->tmpDisk)->delete($this->tmpPath);
        } catch (\Throwable) {
            // Non-fatal.
        }
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            default      => 'bin',
        };
    }
}

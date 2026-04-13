<?php

namespace IbrahimKaya\ImageMan;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use IbrahimKaya\ImageMan\DTOs\VariantResult;
use IbrahimKaya\ImageMan\Events\ImageDeleted;
use IbrahimKaya\ImageMan\Events\ImageProcessed;
use IbrahimKaya\ImageMan\Events\ImageUploaded;
use IbrahimKaya\ImageMan\Exceptions\DuplicateImageException;
use IbrahimKaya\ImageMan\Jobs\ProcessImageJob;
use IbrahimKaya\ImageMan\Models\Image;
use IbrahimKaya\ImageMan\Processors\ImageManipulator;

/**
 * Fluent builder for configuring and executing an image upload.
 *
 * Collects all options via chainable setter methods and defers all I/O
 * to the terminal save() call. Nothing is written to disk or database
 * until save() is called.
 *
 * Example:
 *   $image = ImageMan::upload($file)
 *       ->disk('s3')
 *       ->collection('gallery')
 *       ->sizes(['thumbnail', 'large'])
 *       ->for($post)
 *       ->save();
 */
class ImageUploader
{
    // --- Resolved options (set by fluent methods) ---
    protected string  $disk;
    protected string  $collection  = 'default';
    protected ?array  $sizes       = null;
    protected bool    $keepOriginal;
    protected ?Model  $model       = null;
    protected array   $meta        = [];
    protected ?string $format      = null;
    protected bool    $watermark;
    protected bool    $generateLqip;

    /**
     * Optional custom filename stem (without extension) for the stored file.
     * When null, a UUID is used as the stem (default behaviour).
     * The extension is always derived from the MIME type after conversion.
     */
    protected ?string $filename = null;

    /**
     * Optional subdirectory appended between the base path and the UUID folder.
     * Set via ->inDirectory(). Each path segment is slugified for filesystem safety.
     * Example: ->inDirectory('products/phones') → images/products/phones/{uuid}/file.webp
     */
    protected ?string $customDirectory = null;

    /**
     * When false, the UUID subfolder is omitted from the storage path.
     * Use with ->inDirectory() and ->filename() for fully deterministic paths.
     * If the same path is uploaded to again, the existing file is overwritten.
     */
    protected bool $useUuid = true;

    /**
     * Per-upload watermark overrides.
     * Keys mirror the config('imageman.watermark') sub-array.
     * Only the keys present in this array override the global config;
     * the rest fall back to the published config values.
     */
    protected array $watermarkOverrides = [];

    /**
     * When true, all previously stored images for the same model + collection
     * are deleted AFTER the new image has been successfully saved.
     *
     * Automatically set to true when the collection name is listed in
     * config('imageman.singleton_collections').
     *
     * Override per-upload with ->replaceExisting() / ->keepExisting().
     */
    protected bool $replaceExisting = false;

    // --- Validation overrides ---
    protected array $validationOverrides = [];

    /**
     * When true, the file validation step is completely skipped in save().
     *
     * Used by ChunkAssembler after all chunks are assembled: the chunk system
     * already enforces its own size and MIME-type gates at initiation time, so
     * running the standard ImageValidator on the assembled file is redundant.
     *
     * Not intended for general use — prefer the fluent validation overrides
     * (maxSize, minWidth, etc.) for per-upload constraint changes.
     */
    protected bool $skipValidation = false;

    public function __construct(
        protected UploadedFile     $file,
        protected array            $config,
        protected ImageManipulator $manipulator,
        protected ImageValidator   $validator,
    ) {
        // Set defaults from the published config.
        $this->disk         = $config['disk'] ?? 'local';
        $this->keepOriginal = (bool) ($config['keep_original'] ?? false);
        $this->watermark    = (bool) ($config['watermark']['enabled'] ?? false);
        $this->generateLqip = (bool) ($config['generate_lqip'] ?? true);

        // Singleton collections defined in config are treated as replaceExisting
        // by default — no need to call ->replaceExisting() manually for them.
        // This default can still be overridden per-upload with ->keepExisting().
        $singletonCollections = $config['singleton_collections'] ?? [];
        if (in_array($this->collection, $singletonCollections, true)) {
            $this->replaceExisting = true;
        }
    }

    // -----------------------------------------------------------------------
    // Fluent configuration setters
    // -----------------------------------------------------------------------

    /**
     * Override the target disk for this upload.
     *
     * @param  string $disk  A disk name registered in config/filesystems.php.
     */
    public function disk(string $disk): static
    {
        $this->disk = $disk;
        return $this;
    }

    /**
     * Set the logical collection name for grouping images within a model.
     *
     * When the collection name is listed in config('imageman.singleton_collections'),
     * replaceExisting is automatically enabled. You can still override it with
     * ->keepExisting() after calling ->collection() if needed.
     *
     * @param  string $name  e.g. 'avatar', 'gallery', 'profile_pic'.
     */
    public function collection(string $name): static
    {
        $this->collection = $name;

        // Re-evaluate singleton_collections whenever the collection changes,
        // because the constructor runs before collection() is typically called.
        $singletonCollections = $this->config['singleton_collections'] ?? [];
        if (in_array($name, $singletonCollections, true)) {
            $this->replaceExisting = true;
        }

        return $this;
    }

    /**
     * Specify which size presets to generate for this upload.
     * Overrides the 'default_sizes' config value.
     *
     * @param  array<string> $sizes  Preset names defined in config('imageman.sizes').
     */
    public function sizes(array $sizes): static
    {
        $this->sizes = $sizes;
        return $this;
    }

    /**
     * Retain the original uploaded file alongside the converted versions.
     * Overrides config('imageman.keep_original') for this upload.
     */
    public function withOriginal(bool $keep = true): static
    {
        $this->keepOriginal = $keep;
        return $this;
    }

    /**
     * Associate the uploaded image with an Eloquent model instance (polymorphic).
     *
     * @param  Model $model  Any Eloquent model — the image will be linked via morphTo.
     */
    public function for(Model $model): static
    {
        $this->model = $model;
        return $this;
    }

    /**
     * Attach arbitrary metadata to the image record.
     *
     * @param  array $meta  e.g. ['alt' => 'A red apple', 'title' => 'Product photo'].
     */
    public function meta(array $meta): static
    {
        $this->meta = $meta;
        return $this;
    }

    /**
     * Override the output format for this upload.
     *
     * @param  string $format  'webp' | 'avif' | 'jpeg' | 'original'.
     */
    public function format(string $format): static
    {
        $this->format = $format;
        return $this;
    }

    /**
     * Enable or disable the watermark for this upload.
     * Does not change the watermark type, path, or text — use
     * ->watermarkImage() or ->watermarkText() for that.
     *
     * @param  bool $enable  Pass false to disable (equivalent to ->noWatermark()).
     */
    public function watermark(bool $enable = true): static
    {
        $this->watermark = $enable;
        return $this;
    }

    /**
     * Disable watermark for this upload (overrides global config).
     */
    public function noWatermark(): static
    {
        $this->watermark = false;
        return $this;
    }

    /**
     * Use a specific image file (e.g. a logo) as the watermark for this upload.
     *
     * Automatically enables the watermark. Overrides the global 'watermark.path'
     * config without touching the permanent config file.
     *
     * @param  string $path      Absolute path to the watermark image (PNG with
     *                           transparency recommended for best results).
     * @param  string $position  Placement on the image. One of:
     *                           'top-left' | 'top-center' | 'top-right'
     *                           'center-left' | 'center' | 'center-right'
     *                           'bottom-left' | 'bottom-center' | 'bottom-right'
     * @param  int    $opacity   Watermark transparency: 0 (invisible) – 100 (solid).
     * @param  int    $padding   Pixel gap between the watermark and the image edge.
     *
     * Example:
     *   ImageMan::upload($file)
     *       ->watermarkImage(storage_path('app/logo.png'), 'bottom-right', 40)
     *       ->save();
     */
    public function watermarkImage(
        string $path,
        string $position = 'bottom-right',
        int    $opacity  = 50,
        int    $padding  = 10,
    ): static {
        $this->watermark         = true;
        $this->watermarkOverrides = array_merge($this->watermarkOverrides, [
            'type'     => 'image',
            'path'     => $path,
            'position' => $position,
            'opacity'  => $opacity,
            'padding'  => $padding,
        ]);
        return $this;
    }

    /**
     * Render a text string as the watermark for this upload.
     *
     * Automatically enables the watermark. Overrides the global 'watermark.text'
     * config without touching the permanent config file.
     *
     * @param  string $text      The string to render (e.g. '© 2024 My Company').
     * @param  string $position  Placement on the image (same options as watermarkImage).
     * @param  int    $opacity   Text transparency: 0 (invisible) – 100 (solid).
     * @param  int    $padding   Pixel gap between the text and the image edge.
     *
     * Example:
     *   ImageMan::upload($file)
     *       ->watermarkText('© 2024 Şirketim', 'bottom-center', 70)
     *       ->save();
     */
    public function watermarkText(
        string $text,
        string $position = 'bottom-right',
        int    $opacity  = 50,
        int    $padding  = 10,
    ): static {
        $this->watermark         = true;
        $this->watermarkOverrides = array_merge($this->watermarkOverrides, [
            'type'     => 'text',
            'text'     => $text,
            'position' => $position,
            'opacity'  => $opacity,
            'padding'  => $padding,
        ]);
        return $this;
    }

    /**
     * Set a custom filename stem for the stored image files.
     *
     * The stem is the filename without extension. The correct extension
     * (webp, avif, jpg, etc.) is always appended automatically based on
     * the output format after conversion — you do not need to include it.
     *
     * The value is slugified via Str::slug() to ensure filesystem safety,
     * so spaces and special characters are automatically converted:
     *   'Ürün Fotoğrafı' → 'urun-fotografi'
     *
     * The containing directory still uses a UUID so files from different
     * uploads with the same custom name never collide:
     *   images/{uuid}/my-product.webp
     *   images/{uuid}/my-product_thumbnail.webp
     *
     * If not called, the UUID is used as the stem (default behaviour).
     *
     * @param  string $name  Desired filename without extension.
     *                       e.g. 'product-photo', 'user-avatar', 'banner-2024'
     *
     * Example:
     *   ImageMan::upload($file)->filename('urun-fotografi')->save();
     *   // → images/{uuid}/urun-fotografi.webp
     *   // → images/{uuid}/urun-fotografi_thumbnail.webp
     */
    public function filename(string $name): static
    {
        // Strip any extension the user may have included (e.g. 'photo.jpg' → 'photo').
        $nameWithoutExt = pathinfo($name, PATHINFO_FILENAME);

        // Slugify for filesystem safety ('Ürün Fotoğrafı' → 'urun-fotografi').
        $this->filename = Str::slug($nameWithoutExt) ?: Str::uuid();

        return $this;
    }

    /**
     * Store the image inside a custom subdirectory on the disk.
     *
     * The path is appended between the base path (config: imageman.path) and
     * the UUID folder. Each segment is individually slugified for filesystem
     * safety, so slashes are preserved but special characters are cleaned up.
     *
     *   'Ürün Görselleri'  → 'urun-gorselleri'
     *   'products/phones'  → 'products/phones'  (slash preserved)
     *
     * Combined with the UUID folder (default behaviour):
     *   ->inDirectory('products') → images/products/{uuid}/file.webp
     *
     * Combined with ->noUuid() for a fully deterministic path:
     *   ->inDirectory('users/42')->filename('avatar')->noUuid()
     *   → images/users/42/avatar.webp
     *
     * @param  string $path  One or more slash-separated directory segments.
     */
    public function inDirectory(string $path): static
    {
        // Slugify each path segment individually, preserving the slash separators.
        $slugged = implode('/', array_map(
            fn (string $segment) => Str::slug($segment),
            explode('/', trim($path, '/')),
        ));

        $this->customDirectory = $slugged ?: null;

        return $this;
    }

    /**
     * Omit the UUID subfolder from the storage path.
     *
     * By default every upload is placed inside a UUID-named folder to prevent
     * filename collisions. Calling noUuid() removes this folder so the file
     * is stored directly inside the base path (+ any inDirectory() segment).
     *
     * When noUuid() is active and a file already exists at the target path,
     * it is silently overwritten — this is intentional for cases like profile
     * photos or cover images that must always live at the same URL.
     *
     * Recommended usage: combine with ->inDirectory() and ->filename() to get
     * a fully predictable, stable URL:
     *
     *   ImageMan::upload($file)
     *       ->inDirectory('users/' . $user->id)
     *       ->filename('avatar')
     *       ->noUuid()
     *       ->save();
     *   // → images/users/42/avatar.webp  (always the same path)
     */
    public function noUuid(): static
    {
        $this->useUuid = false;
        return $this;
    }

    /**
     * Enable LQIP generation for this upload.
     */
    public function withLqip(bool $generate = true): static
    {
        $this->generateLqip = $generate;
        return $this;
    }

    /**
     * Disable LQIP generation for this upload.
     */
    public function withoutLqip(): static
    {
        $this->generateLqip = false;
        return $this;
    }

    // --- Singleton / replace-existing ---

    /**
     * Delete all previously stored images for the same model + collection
     * AFTER the new image has been successfully saved.
     *
     * Safe by design: the old image is only removed once the new one is
     * confirmed written to disk and persisted in the database.
     * If the model is not set via ->for($model), this option is silently ignored
     * (there is no model context to scope the deletion to).
     *
     * Usage:
     *   // Explicit per-upload
     *   ImageMan::upload($file)->for($user)->collection('avatar')->replaceExisting()->save();
     *
     *   // Automatic via config (no ->replaceExisting() call needed)
     *   // config/imageman.php: 'singleton_collections' => ['avatar', 'profile_pic']
     *
     * @param  bool $replace  Pass false to disable (e.g. override a singleton_collection config).
     */
    public function replaceExisting(bool $replace = true): static
    {
        $this->replaceExisting = $replace;
        return $this;
    }

    /**
     * Explicitly allow multiple images to accumulate in the collection,
     * even if the collection is listed in config('imageman.singleton_collections').
     *
     * Equivalent to ->replaceExisting(false).
     */
    public function keepExisting(): static
    {
        $this->replaceExisting = false;
        return $this;
    }

    // --- Inline validation overrides ---

    /** Override max file size (KB) for this upload. */
    public function maxSize(int $kb): static
    {
        $this->validationOverrides['max_size'] = $kb;
        return $this;
    }

    /** Override minimum width (px) for this upload. */
    public function minWidth(int $px): static
    {
        $this->validationOverrides['min_width'] = $px;
        return $this;
    }

    /** Override maximum width (px) for this upload. */
    public function maxWidth(int $px): static
    {
        $this->validationOverrides['max_width'] = $px;
        return $this;
    }

    /** Override minimum height (px) for this upload. */
    public function minHeight(int $px): static
    {
        $this->validationOverrides['min_height'] = $px;
        return $this;
    }

    /** Override maximum height (px) for this upload. */
    public function maxHeight(int $px): static
    {
        $this->validationOverrides['max_height'] = $px;
        return $this;
    }

    /**
     * Enforce an aspect ratio for this upload.
     *
     * @param  string $ratio  e.g. '16/9', '1/1', '4/3'.
     */
    public function aspectRatio(string $ratio): static
    {
        $this->validationOverrides['aspect_ratio'] = $ratio;
        return $this;
    }

    /**
     * Skip the file validation step entirely for this upload.
     *
     * Intended for internal use by the chunk assembly pipeline, which enforces
     * its own size/MIME gates at session initiation. Do not use this in
     * application code unless you are certain the file has been validated upstream.
     */
    public function skipValidation(): static
    {
        $this->skipValidation = true;
        return $this;
    }

    // -----------------------------------------------------------------------
    // Terminal action
    // -----------------------------------------------------------------------

    /**
     * Execute the upload pipeline and persist the result.
     *
     * Order of operations:
     *   1. Validate file against configured constraints.
     *   2. Check for duplicates (if enabled).
     *   3. If queue enabled → insert DB record, dispatch ProcessImageJob.
     *   4. Otherwise       → process synchronously, store files, update DB.
     *   5. Fire ImageUploaded event.
     *
     * @return Image  The persisted Image model.
     *
     * @throws \IbrahimKaya\ImageMan\Exceptions\ValidationException
     * @throws \IbrahimKaya\ImageMan\Exceptions\DuplicateImageException
     * @throws \IbrahimKaya\ImageMan\Exceptions\InvalidImageException
     */
    public function save(): Image
    {
        // --- 1. Validate ---
        if (!$this->skipValidation) {
            $this->validator->validate($this->file);
        }

        // --- 2. Duplicate detection ---
        if ($this->config['detect_duplicates'] ?? true) {
            $hash = hash_file('sha256', $this->file->getRealPath());

            // Scope duplicate check to the same disk: the same file on a
            // different disk is a legitimate separate upload, not a duplicate.
            $duplicate = Image::where('hash', $hash)
                ->where('disk', $this->disk)
                ->first();

            if ($duplicate !== null) {
                $action = $this->config['on_duplicate'] ?? 'reuse';

                if ($action === 'reuse') {
                    return $duplicate;
                }

                if ($action === 'throw') {
                    throw DuplicateImageException::forImage($duplicate);
                }

                // 'allow' — fall through and create a new record.
            }
        }

        // --- 3. Queue processing ---
        if ($this->config['queue'] ?? false) {
            return $this->dispatchToQueue();
        }

        // --- 4. Synchronous processing ---
        return $this->processSynchronously();
    }

    // -----------------------------------------------------------------------
    // Private pipeline methods
    // -----------------------------------------------------------------------

    /**
     * Run the full processing pipeline synchronously.
     */
    private function processSynchronously(): Image
    {
        $mergedConfig = $this->buildProcessingConfig();

        // Run the image manipulation pipeline.
        $processed = $this->manipulator->process($this->file, $mergedConfig);

        // Build the storage directory path from configured components:
        //   {base_path} / {custom_directory?} / {uuid?}
        // The UUID segment is included by default to prevent filename collisions.
        // It can be omitted via ->noUuid() when a deterministic path is desired.
        $uuid      = (string) Str::uuid();
        $basePath  = trim($this->config['path'] ?? 'images', '/');
        $uuidPart  = $this->useUuid ? $uuid : null;
        $parts     = array_filter([$basePath, $this->customDirectory, $uuidPart]);
        $directory = implode('/', $parts);

        $disk = Storage::disk($this->disk);

        // Determine the output file extension from the MIME type.
        $ext = $this->extensionForMime($processed->mimeType);

        // Use the custom filename stem when provided, otherwise fall back to UUID.
        $stem = $this->filename ?? $uuid;

        // --- Store main file ---
        $mainFilename = $stem . '.' . $ext;
        $disk->put(
            $directory . '/' . $mainFilename,
            file_get_contents($processed->mainPath),
        );

        // --- Store size variants ---
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

        // --- Store original file (if kept) ---
        if ($processed->hasOriginal()) {
            $origExt      = strtolower($this->file->getClientOriginalExtension() ?: 'jpg');
            $origFilename = $stem . '_original.' . $origExt;
            $disk->put(
                $directory . '/' . $origFilename,
                file_get_contents($processed->originalPath),
            );
        }

        // --- Persist DB record ---
        $image = Image::create([
            'imageable_type'    => $this->model ? get_class($this->model) : null,
            'imageable_id'      => $this->model?->getKey(),
            'collection'        => $this->collection,
            'disk'              => $this->disk,
            'directory'         => $directory,
            'filename'          => $mainFilename,
            'original_filename' => $this->file->getClientOriginalName(),
            'mime_type'         => $processed->mimeType,
            'size'              => $processed->size,
            'width'             => $processed->width,
            'height'            => $processed->height,
            'hash'              => $processed->hash,
            'lqip'              => $processed->lqip,
            'variants'          => $variantsData,
            'exif_stripped'     => (bool) ($this->config['strip_exif'] ?? true),
            'meta'              => $this->meta ?: null,
        ]);

        // --- Clean up temp files ---
        foreach ($processed->allTempPaths() as $tmpPath) {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }

        // --- Replace existing images (singleton collection behaviour) ---
        // Deletion happens AFTER the new image is fully persisted so that a
        // processing failure mid-pipeline never leaves the model without any image.
        $this->deleteExistingIfNeeded($image);

        // --- Fire events ---
        event(new ImageUploaded($image, $this->model));
        event(new ImageProcessed($image, $variantsData));

        return $image;
    }

    /**
     * Insert a pending DB record and dispatch a background job for processing.
     */
    private function dispatchToQueue(): Image
    {
        // Store a temporary copy of the uploaded file so the queued job can access it.
        $tmpDisk     = 'local';
        $tmpPath     = 'imageman_queue/' . Str::uuid() . '.' . $this->file->getClientOriginalExtension();
        Storage::disk($tmpDisk)->put($tmpPath, file_get_contents($this->file->getRealPath()));

        // Insert a placeholder record; variants will be populated by the job.
        $image = Image::create([
            'imageable_type'    => $this->model ? get_class($this->model) : null,
            'imageable_id'      => $this->model?->getKey(),
            'collection'        => $this->collection,
            'disk'              => $this->disk,
            'directory'         => '',
            'filename'          => '',
            'original_filename' => $this->file->getClientOriginalName(),
            'mime_type'         => $this->file->getMimeType() ?? 'application/octet-stream',
            'size'              => 0,
            'width'             => 0,
            'height'            => 0,
            'hash'              => hash_file('sha256', $this->file->getRealPath()),
            'lqip'              => null,
            'variants'          => null,
            'exif_stripped'     => (bool) ($this->config['strip_exif'] ?? true),
            'meta'              => $this->meta ?: null,
        ]);

        ProcessImageJob::dispatch($image->id, $tmpDisk, $tmpPath, $this->buildProcessingConfig())
            ->onConnection($this->config['queue_connection'] ?? 'sync')
            ->onQueue($this->config['queue_name'] ?? 'images');

        // For queued uploads, delete old images immediately after the placeholder
        // record is inserted. The new image ID is already different from the old
        // ones, so the exclusion guard in deleteExistingIfNeeded() keeps it safe.
        $this->deleteExistingIfNeeded($image);

        event(new ImageUploaded($image, $this->model));

        return $image;
    }

    /**
     * Merge all config sources into a single processing config array.
     */
    private function buildProcessingConfig(): array
    {
        return array_merge($this->config, [
            'format'          => $this->format ?? $this->config['format'] ?? 'webp',
            'keep_original'   => $this->keepOriginal,
            'generate_lqip'   => $this->generateLqip,
            'requested_sizes' => $this->sizes ?? $this->config['default_sizes'] ?? [],
            'filename_stem'    => $this->filename,        // null = use UUID (default)
            'custom_directory' => $this->customDirectory, // null = no subdirectory
            'use_uuid'         => $this->useUuid,         // false = omit UUID folder
            'watermark'       => array_merge(
                $this->config['watermark'] ?? [],
                $this->watermarkOverrides,     // per-upload path / text / position overrides
                ['enabled' => $this->watermark],
            ),
            'validation'      => array_merge(
                $this->config['validation'] ?? [],
                $this->validationOverrides,
            ),
        ]);
    }

    /**
     * Delete all images that belong to the same model + collection EXCEPT the
     * newly created image, when replaceExisting mode is active.
     *
     * Called after the new image has been successfully persisted to ensure the
     * model is never left without any image during the transition.
     *
     * No-op when:
     *   - replaceExisting is false.
     *   - No model is associated (cannot scope deletion without a model context).
     *
     * @param  Image $newImage  The freshly persisted image to exclude from deletion.
     */
    private function deleteExistingIfNeeded(Image $newImage): void
    {
        if (!$this->replaceExisting || $this->model === null) {
            return;
        }

        Image::where('imageable_type', get_class($this->model))
            ->where('imageable_id', $this->model->getKey())
            ->where('collection', $this->collection)
            ->where('id', '!=', $newImage->id)   // Never delete the one we just created.
            ->get()
            ->each(fn (Image $old) => $old->delete());
    }

    /**
     * Map a MIME type string to a file extension.
     */
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

<?php

namespace IbrahimKaya\ImageMan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use IbrahimKaya\ImageMan\Drivers\Contracts\UrlGeneratorContract;

/**
 * Eloquent model representing a stored image record.
 *
 * Each row corresponds to one uploaded image and tracks its disk location,
 * dimensions, generated variants, and optional model association.
 *
 * @property int         $id
 * @property string|null $imageable_type
 * @property int|null    $imageable_id
 * @property string      $collection
 * @property string      $disk
 * @property string      $directory
 * @property string      $filename
 * @property string      $original_filename
 * @property string      $mime_type
 * @property int         $size
 * @property int         $width
 * @property int         $height
 * @property string|null $hash
 * @property string|null $lqip
 * @property array|null  $variants
 * @property bool        $exif_stripped
 * @property array|null  $meta
 */
class Image extends Model
{
    protected $table = 'imageman_images';

    protected $fillable = [
        'imageable_type',
        'imageable_id',
        'collection',
        'disk',
        'directory',
        'filename',
        'original_filename',
        'mime_type',
        'size',
        'width',
        'height',
        'hash',
        'lqip',
        'variants',
        'exif_stripped',
        'meta',
    ];

    protected $casts = [
        'size'          => 'integer',
        'width'         => 'integer',
        'height'        => 'integer',
        'variants'      => 'array',
        'meta'          => 'array',
        'exif_stripped' => 'boolean',
    ];

    // -----------------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------------

    /**
     * The model that owns this image (polymorphic, optional).
     */
    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }

    // -----------------------------------------------------------------------
    // URL / Path accessors
    // -----------------------------------------------------------------------

    /**
     * Get the public URL for the given size variant (or the main image).
     *
     * Falls back to config('imageman.fallback_url') when the variant does not
     * exist or the file is missing from the disk.
     *
     * @param  string $variant  Preset name ('thumbnail', 'medium', 'large') or 'original'.
     *                          Defaults to the main stored file when omitted.
     * @return string|null      Public URL string, or null if not found and no fallback set.
     */
    public function url(string $variant = 'default'): ?string
    {
        $path = $this->path($variant);

        if ($path === null) {
            return config('imageman.fallback_url');
        }

        /** @var UrlGeneratorContract $generator */
        $generator = app(UrlGeneratorContract::class);

        return $generator->url($this, $variant);
    }

    /**
     * Get the storage path for the given size variant.
     *
     * @param  string $variant  Preset name or 'original' / 'default'.
     * @return string|null      Relative storage path, or null when variant does not exist.
     */
    public function path(string $variant = 'default'): ?string
    {
        if ($variant === 'original') {
            return $this->directory . '/' . $this->filenameStem() . '_original.*';
        }

        if ($variant === 'default' || $variant === 'main') {
            return $this->directory . '/' . $this->filename;
        }

        $variantData = $this->variants[$variant] ?? null;

        return $variantData ? ($variantData['path'] ?? null) : null;
    }

    /**
     * Generate a signed temporary URL for the given variant.
     *
     * Only works for disks that support temporary URLs (e.g. S3, GCS).
     *
     * @param  int    $minutes  TTL in minutes. Defaults to config('imageman.signed_url_ttl').
     * @param  string $variant  Variant name. Defaults to the main image.
     * @return string
     */
    public function temporaryUrl(int $minutes = 0, string $variant = 'default'): string
    {
        if ($minutes <= 0) {
            $minutes = config('imageman.signed_url_ttl', 60);
        }

        /** @var UrlGeneratorContract $generator */
        $generator = app(UrlGeneratorContract::class);

        return $generator->temporaryUrl($this, $variant, $minutes);
    }

    /**
     * Get the base64 LQIP placeholder data URI.
     *
     * @return string|null  The data URI string, or null if LQIP was not generated.
     */
    public function lqip(): ?string
    {
        return $this->lqip;
    }

    /**
     * Generate an HTML srcset attribute value for all available variants.
     *
     * Only includes variants listed in config('imageman.srcset_sizes') that
     * were actually generated for this image.
     *
     * Example output:
     *   "/storage/images/abc_thumb.webp 150w, /storage/images/abc_med.webp 800w"
     *
     * @return string  The srcset string value (empty string if no variants).
     */
    public function srcset(): string
    {
        $srcsetSizes = config('imageman.srcset_sizes', []);
        $parts       = [];

        foreach ($srcsetSizes as $sizeName) {
            $variantData = $this->variants[$sizeName] ?? null;

            if ($variantData === null) {
                continue;
            }

            $url   = $this->url($sizeName);
            $width = $variantData['width'] ?? null;

            if ($url && $width) {
                $parts[] = "{$url} {$width}w";
            }
        }

        return implode(', ', $parts);
    }

    /**
     * Get all stored variant data as an array.
     *
     * @return array<string, array{path: string, width: int, height: int, size: int}>
     */
    public function variants(): array
    {
        return $this->variants ?? [];
    }

    /**
     * Check whether a specific size variant has been generated.
     */
    public function hasVariant(string $name): bool
    {
        return isset($this->variants[$name]);
    }

    // -----------------------------------------------------------------------
    // Deletion
    // -----------------------------------------------------------------------

    /**
     * Delete the image record and remove all associated files from the disk.
     *
     * @return bool  True on successful DB deletion.
     */
    public function delete(): bool
    {
        $this->deleteFiles();

        return parent::delete();
    }

    /**
     * Remove all files (main + variants + original) from the configured disk.
     * Does not touch the database record.
     */
    public function deleteFiles(): void
    {
        $disk = Storage::disk($this->disk);

        // Delete main file.
        $mainPath = $this->directory . '/' . $this->filename;
        if ($disk->exists($mainPath)) {
            $disk->delete($mainPath);
        }

        // Delete all size variants.
        foreach ($this->variants() as $variant) {
            $variantPath = $variant['path'] ?? null;
            if ($variantPath && $disk->exists($variantPath)) {
                $disk->delete($variantPath);
            }
        }

        // Attempt to remove the directory if it is now empty.
        try {
            $files = $disk->files($this->directory);
            if (empty($files)) {
                $disk->deleteDirectory($this->directory);
            }
        } catch (\Throwable) {
            // Non-fatal — directory cleanup is best effort.
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Return the filename without its extension.
     */
    private function filenameStem(): string
    {
        return pathinfo($this->filename, PATHINFO_FILENAME);
    }
}

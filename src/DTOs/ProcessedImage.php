<?php

namespace IbrahimKaya\ImageMan\DTOs;

/**
 * Immutable data transfer object that carries the results of a completed
 * image processing pipeline back to the ImageUploader for persistence.
 *
 * All paths stored here are absolute temporary filesystem paths that exist
 * only until the ImageUploader moves them to the target disk.
 */
final class ProcessedImage
{
    /**
     * @param string      $mainPath         Absolute path to the main processed file (WebP/AVIF/JPEG).
     * @param string      $mimeType         MIME type of the main file (e.g. 'image/webp').
     * @param int         $width            Width of the main processed image in pixels.
     * @param int         $height           Height of the main processed image in pixels.
     * @param int         $size             File size of the main processed image in bytes.
     * @param string      $hash             SHA-256 hash of the original uploaded file.
     * @param string|null $lqip             Base64-encoded data URI of the LQIP placeholder, or null.
     * @param string|null $originalPath     Absolute path to the preserved original file, or null.
     * @param array       $variants         Map of variant name → VariantResult DTOs.
     *                                      Shape: ['thumbnail' => VariantResult, …]
     */
    public function __construct(
        public readonly string  $mainPath,
        public readonly string  $mimeType,
        public readonly int     $width,
        public readonly int     $height,
        public readonly int     $size,
        public readonly string  $hash,
        public readonly ?string $lqip = null,
        public readonly ?string $originalPath = null,
        public readonly array   $variants = [],
    ) {}

    /**
     * Determine whether a preserved original file is available.
     */
    public function hasOriginal(): bool
    {
        return $this->originalPath !== null;
    }

    /**
     * Determine whether a LQIP placeholder was generated.
     */
    public function hasLqip(): bool
    {
        return $this->lqip !== null;
    }

    /**
     * Return all temporary file paths (main + variants + original) so they
     * can be cleaned up after the upload is stored to the target disk.
     *
     * @return array<string>
     */
    public function allTempPaths(): array
    {
        $paths = [$this->mainPath];

        foreach ($this->variants as $variant) {
            $paths[] = $variant->tempPath;
        }

        if ($this->originalPath !== null) {
            $paths[] = $this->originalPath;
        }

        return $paths;
    }
}

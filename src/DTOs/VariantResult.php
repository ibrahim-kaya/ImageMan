<?php

namespace IbrahimKaya\ImageMan\DTOs;

/**
 * Carries the result of a single size variant generation.
 * Stored as a value inside ProcessedImage::$variants.
 */
final class VariantResult
{
    /**
     * @param string $name     The preset name (e.g. 'thumbnail', 'medium').
     * @param string $tempPath Absolute path to the temporary variant file.
     * @param int    $width    Actual output width in pixels (may differ from preset if contained).
     * @param int    $height   Actual output height in pixels.
     * @param int    $size     File size in bytes.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $tempPath,
        public readonly int    $width,
        public readonly int    $height,
        public readonly int    $size,
    ) {}
}

<?php

namespace IbrahimKaya\ImageMan\Exceptions;

use RuntimeException;
use IbrahimKaya\ImageMan\Models\Image;

/**
 * Thrown when a duplicate image is detected (matching SHA-256 hash) and the
 * 'on_duplicate' config option is set to 'throw'.
 */
class DuplicateImageException extends RuntimeException
{
    protected Image $existing;

    public function __construct(Image $existing)
    {
        $this->existing = $existing;

        parent::__construct(
            "A duplicate image with hash [{$existing->hash}] already exists (ID: {$existing->id})."
        );
    }

    /**
     * Get the existing Image model that caused the duplicate detection.
     */
    public function existingImage(): Image
    {
        return $this->existing;
    }

    public static function forImage(Image $existing): self
    {
        return new self($existing);
    }
}

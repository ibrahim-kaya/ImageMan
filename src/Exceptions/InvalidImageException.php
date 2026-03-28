<?php

namespace IbrahimKaya\ImageMan\Exceptions;

use RuntimeException;

/**
 * Thrown when the provided file is not a valid image or cannot be decoded
 * by the image processing library (e.g. corrupted file, unsupported format).
 */
class InvalidImageException extends RuntimeException
{
    public static function forFile(string $filename): self
    {
        return new self("The file [{$filename}] is not a valid image or could not be decoded.");
    }
}

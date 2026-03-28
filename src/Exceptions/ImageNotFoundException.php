<?php

namespace IbrahimKaya\ImageMan\Exceptions;

use RuntimeException;

/**
 * Thrown by ImageManager::get() when no Image record exists for the given ID.
 */
class ImageNotFoundException extends RuntimeException
{
    public static function forId(int $id): self
    {
        return new self("No image found with ID [{$id}].");
    }
}

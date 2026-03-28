<?php

namespace IbrahimKaya\ImageMan\Exceptions;

use RuntimeException;

/**
 * Thrown when a requested disk name is not registered in config/filesystems.php
 * or cannot be resolved by Laravel's Storage manager.
 */
class DiskNotFoundException extends RuntimeException
{
    public static function forDisk(string $disk): self
    {
        return new self("The disk [{$disk}] is not defined in config/filesystems.php.");
    }
}

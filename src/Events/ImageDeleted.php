<?php

namespace IbrahimKaya\ImageMan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after an Image record and its associated disk files have been deleted.
 *
 * At the time this event is fired, the Image model row no longer exists in the
 * database and all files have been removed from the disk.
 *
 * Example listener:
 *   public function handle(ImageDeleted $event): void
 *   {
 *       $event->imageId;  // The deleted image's former primary key
 *       $event->disk;     // The disk the files were stored on
 *       $event->paths;    // Array of storage paths that were deleted
 *   }
 */
class ImageDeleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int    $imageId,
        public readonly string $disk,
        public readonly array  $paths = [],
    ) {}
}

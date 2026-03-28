<?php

namespace IbrahimKaya\ImageMan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use IbrahimKaya\ImageMan\Models\Image;

/**
 * Fired after all image variants have been generated and stored to disk.
 *
 * This event is fired both in synchronous mode (after save() completes)
 * and in queue mode (after ProcessImageJob finishes). Subscribe to this
 * event when you need to act on the fully-processed image.
 *
 * Example listener:
 *   public function handle(ImageProcessed $event): void
 *   {
 *       $event->image;      // The updated Image model
 *       $event->variants;   // ['thumbnail' => ['path' => …], …]
 *   }
 */
class ImageProcessed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Image $image,
        public readonly array $variants = [],
    ) {}
}

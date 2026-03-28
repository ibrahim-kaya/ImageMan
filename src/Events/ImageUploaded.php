<?php

namespace IbrahimKaya\ImageMan\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use IbrahimKaya\ImageMan\Models\Image;

/**
 * Fired immediately after an Image record is created in the database,
 * regardless of whether queue processing is enabled.
 *
 * When queue is enabled, variants may not yet be populated at this point —
 * listen for ImageProcessed instead if you need the variants to be ready.
 *
 * Example listener:
 *   public function handle(ImageUploaded $event): void
 *   {
 *       $event->image;    // The Image model
 *       $event->model;    // The associated Eloquent model (or null)
 *   }
 */
class ImageUploaded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Image  $image,
        public readonly ?Model $model = null,
    ) {}
}

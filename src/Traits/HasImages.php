<?php

namespace IbrahimKaya\ImageMan\Traits;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Http\UploadedFile;
use IbrahimKaya\ImageMan\ImageManFacade as ImageMan;
use IbrahimKaya\ImageMan\Models\Image;

/**
 * Provides image management methods for any Eloquent model.
 *
 * Add this trait to your model to enable easy image uploading and retrieval:
 *
 *   class User extends Model
 *   {
 *       use HasImages;
 *   }
 *
 *   // Upload from HTTP request
 *   $user->uploadImage($request->file('avatar'), 'avatars');
 *
 *   // Upload from a remote URL
 *   $post->uploadImage('https://example.com/photo.jpg', 'gallery');
 *
 *   // Retrieve
 *   $user->getImage('avatars')->url('medium');
 *
 *   // Delete
 *   $user->deleteImages('avatars');
 */
trait HasImages
{
    // -----------------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------------

    /**
     * All images belonging to this model across all collections.
     *
     * @return MorphMany<Image>
     */
    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable')
                    ->orderBy('created_at', 'desc');
    }

    /**
     * The most recently uploaded image from the 'default' collection.
     * Useful as a convenience accessor when a model has exactly one primary image.
     *
     * @return MorphOne<Image>
     */
    public function image(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable')
                    ->where('collection', 'default')
                    ->latestOfMany();
    }

    // -----------------------------------------------------------------------
    // Upload
    // -----------------------------------------------------------------------

    /**
     * Upload an image and associate it with this model.
     *
     * Accepts either an UploadedFile (from an HTTP request) or a remote URL string.
     * When a URL is provided the image is downloaded automatically before processing.
     *
     * @param  UploadedFile|string $source      An UploadedFile instance OR a full http/https URL.
     * @param  string              $collection  Logical collection name (default: 'default').
     * @param  array               $options     Optional overrides forwarded to the uploader:
     *   'disk'     => 's3'           Override the storage disk.
     *   'sizes'    => ['thumbnail']  Which size variants to generate.
     *   'meta'     => ['alt' => '…'] Metadata to attach.
     *   'format'   => 'avif'         Output format override.
     *   'watermark'=> true           Enable/disable watermark.
     *   'replace'  => true           Delete previous images in this collection after
     *                                the new one is saved (singleton behaviour).
     *                                Defaults to true when the collection is listed in
     *                                config('imageman.singleton_collections').
     *   'timeout'  => 30             Download timeout in seconds (URL uploads only).
     * @return Image                  The created Image model.
     */
    public function uploadImage(UploadedFile|string $source, string $collection = 'default', array $options = []): Image
    {
        // Route to the correct uploader depending on the source type.
        if (is_string($source)) {
            $timeout  = (int) ($options['timeout'] ?? 30);
            $uploader = ImageMan::uploadFromUrl($source, $timeout);
        } else {
            $uploader = ImageMan::upload($source);
        }

        $uploader->for($this)->collection($collection);

        if (!empty($options['disk'])) {
            $uploader->disk($options['disk']);
        }

        if (!empty($options['sizes'])) {
            $uploader->sizes($options['sizes']);
        }

        if (!empty($options['meta'])) {
            $uploader->meta($options['meta']);
        }

        if (isset($options['watermark'])) {
            $uploader->watermark((bool) $options['watermark']);
        }

        if (isset($options['format'])) {
            $uploader->format($options['format']);
        }

        // 'replace' option: explicitly enable or disable singleton behaviour.
        // When omitted, the uploader decides based on singleton_collections config.
        if (isset($options['replace'])) {
            $options['replace']
                ? $uploader->replaceExisting()
                : $uploader->keepExisting();
        }

        return $uploader->save();
    }

    // -----------------------------------------------------------------------
    // Retrieval
    // -----------------------------------------------------------------------

    /**
     * Get the most recently uploaded image from the specified collection.
     *
     * @param  string  $collection  Collection name (default: 'default').
     * @return Image|null           The most recent Image, or null if none exists.
     */
    public function getImage(string $collection = 'default'): ?Image
    {
        return $this->images()
                    ->where('collection', $collection)
                    ->first();
    }

    /**
     * Get all images from the specified collection, ordered newest-first.
     *
     * @param  string  $collection  Collection name (default: 'default').
     * @return Collection<int, Image>
     */
    public function getImages(string $collection = 'default'): Collection
    {
        return $this->images()
                    ->where('collection', $collection)
                    ->get();
    }

    /**
     * Get images from all collections, keyed by collection name.
     *
     * @return Collection<string, Collection<int, Image>>
     */
    public function getAllImages(): Collection
    {
        return $this->images()
                    ->get()
                    ->groupBy('collection');
    }

    // -----------------------------------------------------------------------
    // Deletion
    // -----------------------------------------------------------------------

    /**
     * Delete all images in the specified collection (disk files + DB records).
     *
     * @param  string  $collection  Collection name. Defaults to 'default'.
     *                             Pass '*' or null to delete all collections.
     */
    public function deleteImages(string $collection = 'default'): void
    {
        $query = $this->images();

        if ($collection !== '*') {
            $query->where('collection', $collection);
        }

        $query->get()->each(fn (Image $image) => $image->delete());
    }

    /**
     * Check whether this model has at least one image in the given collection.
     *
     * @param  string  $collection  Collection name (default: 'default').
     */
    public function hasImage(string $collection = 'default'): bool
    {
        return $this->images()
                    ->where('collection', $collection)
                    ->exists();
    }
}

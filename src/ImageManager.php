<?php

namespace IbrahimKaya\ImageMan;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use IbrahimKaya\ImageMan\Exceptions\ImageNotFoundException;
use IbrahimKaya\ImageMan\Exceptions\UrlFetchException;
use IbrahimKaya\ImageMan\Models\Image;
use IbrahimKaya\ImageMan\Processors\ImageManipulator;
use IbrahimKaya\ImageMan\Support\UrlImageFetcher;

/**
 * Primary entry point for the ImageMan package.
 *
 * This class is bound as a singleton in the service container and exposed
 * via the ImageMan facade. All public-facing operations (upload, retrieve,
 * delete) pass through here.
 *
 * Usage via facade:
 *   $image = ImageMan::upload($file)->disk('s3')->save();
 *   $image = ImageMan::uploadFromUrl('https://example.com/photo.jpg')->save();
 *   $image = ImageMan::find(1);
 *   ImageMan::destroy(1);
 */
class ImageManager
{
    /** @var string|null  Disk override applied to every subsequent upload. */
    protected ?string $diskOverride = null;

    public function __construct(
        protected array            $config,
        protected ImageManipulator $manipulator,
    ) {}

    // -----------------------------------------------------------------------
    // Upload
    // -----------------------------------------------------------------------

    /**
     * Begin a new image upload and return a fluent ImageUploader builder.
     *
     * Nothing is written to disk or database until you call ->save() on
     * the returned builder.
     *
     * @param  UploadedFile $file  The file from $request->file('field').
     * @return ImageUploader       Fluent builder to chain options.
     */
    public function upload(UploadedFile $file): ImageUploader
    {
        $config = $this->config;

        // Apply any disk override set via disk() before returning the builder.
        if ($this->diskOverride !== null) {
            $config['disk']   = $this->diskOverride;
            $this->diskOverride = null; // Reset after single use.
        }

        $validator = new ImageValidator($config['validation'] ?? []);

        return new ImageUploader($file, $config, $this->manipulator, $validator);
    }

    /**
     * Download an image from a remote URL and return a fluent ImageUploader builder.
     *
     * The URL is fetched synchronously before the builder is returned. The downloaded
     * file is written to a temporary path and passed through the same processing
     * pipeline as a regular file upload (conversion, resizing, watermarking, etc.).
     *
     * All fluent methods available on upload() work here too:
     *   ImageMan::uploadFromUrl('https://example.com/photo.jpg')
     *       ->disk('s3')
     *       ->collection('gallery')
     *       ->sizes(['thumbnail', 'medium'])
     *       ->save();
     *
     * @param  string $url             Full URL of the remote image (http/https).
     * @param  int    $timeoutSeconds  Max seconds to wait for the download. Default: 30.
     * @return ImageUploader           Fluent builder ready for further configuration.
     *
     * @throws UrlFetchException   If the URL cannot be reached, returns a non-2xx status,
     *                             or the response body is not a recognised image type.
     */
    public function uploadFromUrl(string $url, int $timeoutSeconds = 30): ImageUploader
    {
        $fetcher = new UrlImageFetcher();
        $file    = $fetcher->fetch($url, $timeoutSeconds);

        return $this->upload($file);
    }

    /**
     * Convenience method: begin an upload pre-associated with an Eloquent model.
     *
     * Equivalent to: ImageMan::upload($file)->for($model)
     *
     * @param  UploadedFile $file   The uploaded file.
     * @param  Model        $model  The model to associate with.
     * @return ImageUploader
     */
    public function forModel(Model $model, UploadedFile $file): ImageUploader
    {
        return $this->upload($file)->for($model);
    }

    // -----------------------------------------------------------------------
    // Disk override
    // -----------------------------------------------------------------------

    /**
     * Set a one-shot disk override for the next upload call.
     *
     * The override is consumed by the next upload() call and then cleared,
     * so it does not persist across multiple uploads.
     *
     * Example:
     *   ImageMan::disk('s3')->upload($file)->save();
     *
     * @param  string $disk  Disk name from config/filesystems.php.
     * @return static
     */
    public function disk(string $disk): static
    {
        $this->diskOverride = $disk;
        return $this;
    }

    // -----------------------------------------------------------------------
    // Retrieval
    // -----------------------------------------------------------------------

    /**
     * Find an image by its primary key. Returns null if not found.
     *
     * @param  int  $id
     * @return Image|null
     */
    public function find(int $id): ?Image
    {
        return Image::find($id);
    }

    /**
     * Get an image by its primary key. Throws if not found.
     *
     * @param  int  $id
     * @return Image
     *
     * @throws ImageNotFoundException
     */
    public function get(int $id): Image
    {
        $image = $this->find($id);

        if ($image === null) {
            throw ImageNotFoundException::forId($id);
        }

        return $image;
    }

    // -----------------------------------------------------------------------
    // Deletion
    // -----------------------------------------------------------------------

    /**
     * Delete an image record and remove all associated files from disk.
     *
     * @param  int  $id  The image primary key.
     * @return bool      True if the record was found and deleted.
     */
    public function destroy(int $id): bool
    {
        $image = $this->find($id);

        if ($image === null) {
            return false;
        }

        return $image->delete();
    }
}

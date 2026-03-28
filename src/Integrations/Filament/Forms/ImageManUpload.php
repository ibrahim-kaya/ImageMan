<?php

namespace IbrahimKaya\ImageMan\Integrations\Filament\Forms;

use Filament\Forms\Components\FileUpload;
use Illuminate\Database\Eloquent\Model;
use IbrahimKaya\ImageMan\ImageManFacade as ImageMan;

/**
 * Filament v3 form component for ImageMan image uploads.
 *
 * Extends Filament's built-in FileUpload component and hooks into the
 * save lifecycle to process the uploaded file through the ImageMan
 * pipeline (WebP conversion, resizing, watermarking, etc.).
 *
 * Usage:
 *   ImageManUpload::make('photo')
 *       ->collection('avatars')
 *       ->disk('s3')
 *       ->sizes(['thumbnail', 'medium'])
 *
 * @see https://filamentphp.com/docs/3.x/forms/fields/file-upload
 */
class ImageManUpload extends FileUpload
{
    protected string $imageManCollection = 'default';
    protected ?string $imageManDisk      = null;
    protected array $imageManSizes       = [];

    public static function make(string $name): static
    {
        $component = parent::make($name);
        $component->image();
        $component->imageEditor();

        return $component;
    }

    /**
     * Set the ImageMan collection name for uploads through this field.
     */
    public function collection(string $collection): static
    {
        $this->imageManCollection = $collection;
        return $this;
    }

    /**
     * Override the target disk for uploads through this field.
     */
    public function disk(?string $disk): static
    {
        $this->imageManDisk = $disk;
        return parent::disk($disk);
    }

    /**
     * Specify which size presets to generate for uploads through this field.
     *
     * @param  array<string> $sizes  e.g. ['thumbnail', 'medium']
     */
    public function sizes(array $sizes): static
    {
        $this->imageManSizes = $sizes;
        return $this;
    }

    /**
     * Override the save lifecycle to process uploads through ImageMan.
     *
     * This method is called by Filament when the form is saved with a new file.
     * It passes the uploaded file through the ImageMan pipeline and stores the
     * resulting Image model ID as the field value.
     */
    public function saveUploadedFileUsing(callable $callback): static
    {
        return parent::saveUploadedFileUsing(function ($file, Model $record) use ($callback) {
            $uploader = ImageMan::upload($file)
                ->for($record)
                ->collection($this->imageManCollection);

            if ($this->imageManDisk) {
                $uploader->disk($this->imageManDisk);
            }

            if (!empty($this->imageManSizes)) {
                $uploader->sizes($this->imageManSizes);
            }

            $image = $uploader->save();

            // Return the Image model ID as the stored value.
            return $image->id;
        });
    }
}

<?php

namespace IbrahimKaya\ImageMan\Integrations\Nova;

use Laravel\Nova\Fields\Image;
use IbrahimKaya\ImageMan\ImageManFacade as ImageMan;

/**
 * Laravel Nova field for ImageMan image uploads and display.
 *
 * Wraps Nova's built-in Image field and hooks into the store and fill
 * callbacks to process files through the ImageMan pipeline.
 *
 * Usage in a Nova resource:
 *
 *   use IbrahimKaya\ImageMan\Integrations\Nova\ImageManField;
 *
 *   public function fields(NovaRequest $request): array
 *   {
 *       return [
 *           ImageManField::make('Photo')
 *               ->collection('avatars')
 *               ->disk('s3')
 *               ->size('medium'),
 *       ];
 *   }
 *
 * @see https://nova.laravel.com/docs/resources/fields.html#image-field
 */
class ImageManField extends Image
{
    protected string $imageManCollection = 'default';
    protected string $imageManSize       = 'medium';
    protected ?string $imageManDisk      = null;

    public static function make(string $name, ?string $attribute = null, ?callable $resolveCallback = null): static
    {
        $field = parent::make($name, $attribute, $resolveCallback);

        // Override the store callback to process through ImageMan.
        $field->store(function ($request, $model, $attribute, $requestAttribute) use ($field) {
            $file = $request->file($requestAttribute);

            if ($file === null) {
                return null;
            }

            $uploader = ImageMan::upload($file)
                ->for($model)
                ->collection($field->imageManCollection);

            if ($field->imageManDisk) {
                $uploader->disk($field->imageManDisk);
            }

            $image = $uploader->save();

            // Store the Image model ID on the model attribute.
            $model->{$attribute} = $image->id;
            $model->save();

            return null; // Prevent Nova from storing the raw path.
        });

        // Override the resolve callback to display the image URL.
        $field->preview(function ($value, $disk, $model) use ($field) {
            if (empty($value)) {
                return config('imageman.fallback_url');
            }

            $image = \IbrahimKaya\ImageMan\Models\Image::find((int) $value);

            return $image?->url($field->imageManSize) ?? config('imageman.fallback_url');
        });

        return $field;
    }

    /**
     * Set the ImageMan collection for uploads through this field.
     */
    public function collection(string $collection): static
    {
        $this->imageManCollection = $collection;
        return $this;
    }

    /**
     * Set the ImageMan variant size to display in Nova.
     */
    public function size(string $size): static
    {
        $this->imageManSize = $size;
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
}

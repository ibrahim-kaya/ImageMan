<?php

namespace IbrahimKaya\ImageMan\Integrations\Filament\Tables;

use Filament\Tables\Columns\ImageColumn;
use IbrahimKaya\ImageMan\Models\Image;

/**
 * Filament v3 table column for displaying ImageMan images.
 *
 * Resolves the image URL from the ImageMan Image model stored in the
 * record's relationship and displays it in the Filament table.
 *
 * Usage:
 *   ImageManColumn::make('photo')
 *       ->collection('avatars')
 *       ->size('thumbnail')
 *       ->circular()
 *
 * @see https://filamentphp.com/docs/3.x/tables/columns/image
 */
class ImageManColumn extends ImageColumn
{
    protected string $imageManCollection = 'default';
    protected string $imageManSize       = 'thumbnail';

    /**
     * Set which collection to read the image from.
     */
    public function collection(string $collection): static
    {
        $this->imageManCollection = $collection;
        return $this;
    }

    /**
     * Set which variant size to display.
     */
    public function size(string $size): static
    {
        $this->imageManSize = $size;
        return $this;
    }

    /**
     * Override the URL resolution to use the ImageMan Image model.
     *
     * The column name should match a relationship or an integer foreign key
     * pointing to the Image record. E.g. if the model has a `photo_id` column
     * or a `photo` morphOne relationship, this column will read from it.
     */
    public function getImageUrl(?string $state = null): ?string
    {
        if (empty($state)) {
            return config('imageman.fallback_url');
        }

        $image = is_numeric($state)
            ? Image::find((int) $state)
            : null;

        if ($image === null) {
            return config('imageman.fallback_url');
        }

        return $image->url($this->imageManSize);
    }
}

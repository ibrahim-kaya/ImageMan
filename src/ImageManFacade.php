<?php

namespace IbrahimKaya\ImageMan;

use Illuminate\Support\Facades\Facade;

/**
 * ImageMan Facade.
 *
 * Provides a static interface to the ImageManager singleton bound in the
 * service container under the key 'imageman'.
 *
 * @method static \IbrahimKaya\ImageMan\ImageUploader upload(\Illuminate\Http\UploadedFile $file)
 * @method static \IbrahimKaya\ImageMan\ImageUploader uploadFromUrl(string $url, int $timeoutSeconds = 30)
 * @method static \IbrahimKaya\ImageMan\ImageManager  disk(string $disk)
 * @method static \IbrahimKaya\ImageMan\ImageUploader replaceExisting(bool $replace = true)
 * @method static \IbrahimKaya\ImageMan\ImageUploader keepExisting()
 * @method static \IbrahimKaya\ImageMan\ImageUploader watermarkImage(string $path, string $position = 'bottom-right', int $opacity = 50, int $padding = 10)
 * @method static \IbrahimKaya\ImageMan\ImageUploader watermarkText(string $text, string $position = 'bottom-right', int $opacity = 50, int $padding = 10)
 * @method static \IbrahimKaya\ImageMan\ImageUploader noWatermark()
 * @method static \IbrahimKaya\ImageMan\ImageUploader forModel(\Illuminate\Database\Eloquent\Model $model, \Illuminate\Http\UploadedFile $file)
 * @method static \IbrahimKaya\ImageMan\Models\Image|null find(int $id)
 * @method static \IbrahimKaya\ImageMan\Models\Image      get(int $id)
 * @method static bool                               destroy(int $id)
 *
 * @see \IbrahimKaya\ImageMan\ImageManager
 */
class ImageManFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'imageman';
    }
}

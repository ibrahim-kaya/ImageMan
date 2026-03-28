<?php

namespace IbrahimKaya\ImageMan\Drivers;

use IbrahimKaya\ImageMan\Drivers\Contracts\UrlGeneratorContract;
use IbrahimKaya\ImageMan\Models\Image;

/**
 * URL generator for Cloudinary (https://cloudinary.com).
 *
 * Generates Cloudinary delivery URLs with transformation parameters.
 * The stored path (without extension) is used as the Cloudinary public ID.
 *
 * Configuration (config/imageman.php):
 *   'url_generator' => 'cloudinary',
 *   'cloudinary' => [
 *       'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
 *       'api_key'    => env('CLOUDINARY_API_KEY'),
 *       'api_secret' => env('CLOUDINARY_API_SECRET'),
 *   ],
 */
class CloudinaryUrlGenerator implements UrlGeneratorContract
{
    public function url(Image $image, string $variant = 'default'): string
    {
        $cloudName  = config('imageman.cloudinary.cloud_name');
        $publicId   = $image->directory . '/' . pathinfo($image->filename, PATHINFO_FILENAME);
        $transforms = $this->buildTransformation($image, $variant);

        $transformStr = implode(',', $transforms);
        $prefix       = $transformStr ? "/{$transformStr}" : '';

        return "https://res.cloudinary.com/{$cloudName}/image/upload{$prefix}/{$publicId}";
    }

    public function temporaryUrl(Image $image, string $variant = 'default', int $minutes = 60): string
    {
        // Cloudinary signed URLs require additional SDK integration.
        // Return a standard URL here; use the Cloudinary PHP SDK for full signing.
        return $this->url($image, $variant);
    }

    /**
     * Build a Cloudinary transformation segment for the requested variant.
     *
     * @return array<string>  Array of Cloudinary transformation parameter strings.
     */
    private function buildTransformation(Image $image, string $variant): array
    {
        if ($variant === 'default' || $variant === 'main') {
            return ['f_auto', 'q_auto'];
        }

        $sizeConfig = config("imageman.sizes.{$variant}");

        if (!$sizeConfig) {
            return ['f_auto', 'q_auto'];
        }

        $crop = $this->mapFit($sizeConfig['fit'] ?? 'contain');

        return [
            "w_{$sizeConfig['width']}",
            "h_{$sizeConfig['height']}",
            "c_{$crop}",
            'f_auto',
            'q_auto',
        ];
    }

    /**
     * Map ImageMan fit values to Cloudinary crop mode values.
     */
    private function mapFit(string $fit): string
    {
        return match ($fit) {
            'cover'           => 'fill',
            'contain'         => 'fit',
            'fill', 'stretch' => 'scale',
            default           => 'fit',
        };
    }
}

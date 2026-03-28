<?php

namespace IbrahimKaya\ImageMan\Drivers;

use IbrahimKaya\ImageMan\Drivers\Contracts\UrlGeneratorContract;
use IbrahimKaya\ImageMan\Models\Image;

/**
 * URL generator for ImageKit (https://imagekit.io).
 *
 * Generates ImageKit delivery URLs with real-time transformation parameters
 * appended to the URL endpoint.
 *
 * Configuration (config/imageman.php):
 *   'url_generator' => 'imagekit',
 *   'imagekit' => [
 *       'url_endpoint' => env('IMAGEKIT_URL_ENDPOINT'),  // e.g. 'https://ik.imagekit.io/youraccountid'
 *       'public_key'   => env('IMAGEKIT_PUBLIC_KEY'),
 *       'private_key'  => env('IMAGEKIT_PRIVATE_KEY'),
 *   ],
 */
class ImageKitUrlGenerator implements UrlGeneratorContract
{
    public function url(Image $image, string $variant = 'default'): string
    {
        $endpoint   = rtrim(config('imageman.imagekit.url_endpoint'), '/');
        $path       = '/' . ltrim($image->directory . '/' . $image->filename, '/');
        $transforms = $this->buildTransformString($image, $variant);

        if ($transforms) {
            return "{$endpoint}/tr:{$transforms}{$path}";
        }

        return "{$endpoint}{$path}";
    }

    public function temporaryUrl(Image $image, string $variant = 'default', int $minutes = 60): string
    {
        // ImageKit signed URLs require HMAC-SHA256 signing with the private key.
        // For now, return a standard URL. Use the ImageKit PHP SDK for full signing:
        // https://github.com/imagekit-developer/imagekit-php
        return $this->url($image, $variant);
    }

    /**
     * Build an ImageKit transformation string for the requested variant.
     *
     * @see https://docs.imagekit.io/features/image-transformations
     * @return string  e.g. 'w-800,h-600,c-at_max,f-auto'
     */
    private function buildTransformString(Image $image, string $variant): string
    {
        if ($variant === 'default' || $variant === 'main') {
            return 'f-auto,q-auto';
        }

        $sizeConfig = config("imageman.sizes.{$variant}");

        if (!$sizeConfig) {
            return 'f-auto,q-auto';
        }

        $crop = $this->mapFit($sizeConfig['fit'] ?? 'contain');

        return implode(',', [
            "w-{$sizeConfig['width']}",
            "h-{$sizeConfig['height']}",
            "c-{$crop}",
            'f-auto',
            'q-auto',
        ]);
    }

    /**
     * Map ImageMan fit values to ImageKit crop mode values.
     */
    private function mapFit(string $fit): string
    {
        return match ($fit) {
            'cover'           => 'maintain_ratio',
            'contain'         => 'at_max',
            'fill', 'stretch' => 'force',
            default           => 'at_max',
        };
    }
}

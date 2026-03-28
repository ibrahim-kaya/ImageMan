<?php

namespace IbrahimKaya\ImageMan\Drivers;

use IbrahimKaya\ImageMan\Drivers\Contracts\UrlGeneratorContract;
use IbrahimKaya\ImageMan\Models\Image;

/**
 * URL generator for Imgix (https://www.imgix.com).
 *
 * Generates Imgix transformation URLs using the stored image path.
 * Resizing and format conversion happen on Imgix's CDN edge, so local
 * variants do not need to be generated — the path of the original (or
 * main) file is used as the Imgix source path, and size parameters
 * are appended as query string transformations.
 *
 * Configuration (config/imageman.php):
 *   'url_generator' => 'imgix',
 *   'imgix' => [
 *       'domain'   => env('IMGIX_DOMAIN'),   // e.g. 'mysite.imgix.net'
 *       'sign_key' => env('IMGIX_SIGN_KEY'),  // optional, enables URL signing
 *   ],
 */
class ImgixUrlGenerator implements UrlGeneratorContract
{
    public function url(Image $image, string $variant = 'default'): string
    {
        $domain = config('imageman.imgix.domain');
        $path   = $image->directory . '/' . $image->filename;
        $params = $this->buildTransformParams($image, $variant);

        $url = "https://{$domain}/{$path}";

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $signKey = config('imageman.imgix.sign_key');
        if ($signKey) {
            $url = $this->signUrl($url, $signKey);
        }

        return $url;
    }

    public function temporaryUrl(Image $image, string $variant = 'default', int $minutes = 60): string
    {
        // Imgix does not have native temporary URLs in the same sense as S3.
        // Return a standard URL; restrict access via Imgix source settings instead.
        return $this->url($image, $variant);
    }

    /**
     * Build Imgix transformation query parameters for the requested variant.
     *
     * @return array<string, mixed>
     */
    private function buildTransformParams(Image $image, string $variant): array
    {
        $params = ['auto' => 'format,compress'];

        $sizes = config('imageman.sizes', []);

        if ($variant === 'default' || $variant === 'main') {
            return $params;
        }

        $sizeConfig = $sizes[$variant] ?? null;

        if ($sizeConfig) {
            $params['w']   = $sizeConfig['width'];
            $params['h']   = $sizeConfig['height'];
            $params['fit'] = $this->mapFit($sizeConfig['fit'] ?? 'contain');
        }

        return $params;
    }

    /**
     * Map ImageMan fit values to Imgix fit parameter values.
     */
    private function mapFit(string $fit): string
    {
        return match ($fit) {
            'cover'   => 'crop',
            'contain' => 'max',
            'fill', 'stretch' => 'fill',
            default   => 'max',
        };
    }

    /**
     * Sign an Imgix URL with the provided secret key.
     * See: https://docs.imgix.com/setup/securing-images
     */
    private function signUrl(string $url, string $signKey): string
    {
        $parsedUrl    = parse_url($url);
        $pathAndQuery = ($parsedUrl['path'] ?? '/') . (isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '');
        $signature    = md5($signKey . $pathAndQuery);

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 's=' . $signature;
    }
}

<?php

namespace IbrahimKaya\ImageMan\Drivers;

use IbrahimKaya\ImageMan\Drivers\Contracts\UrlGeneratorContract;
use IbrahimKaya\ImageMan\Models\Image;

/**
 * URL generator for Cloudflare Images (https://developers.cloudflare.com/images/).
 *
 * Generates Cloudflare Images delivery URLs. Images are served from Cloudflare's
 * global CDN using the image ID (stored as the filename) and named variants.
 *
 * Configuration (config/imageman.php):
 *   'url_generator' => 'cloudflare',
 *   'cloudflare' => [
 *       'account_id' => env('CF_IMAGES_ACCOUNT_ID'),
 *       'api_token'  => env('CF_IMAGES_API_TOKEN'),
 *   ],
 *
 * Note: Cloudflare Images uses named delivery variants configured in the
 * Cloudflare dashboard (not query parameters). The preset names in your
 * imageman config should match the variant names in Cloudflare.
 * Use 'public' as a fallback when a named variant is not configured.
 */
class CloudflareUrlGenerator implements UrlGeneratorContract
{
    public function url(Image $image, string $variant = 'default'): string
    {
        $accountId = config('imageman.cloudflare.account_id');

        // Cloudflare Images uses the image ID (filename without extension) as the identifier.
        $imageId = pathinfo($image->filename, PATHINFO_FILENAME);

        // Map to a Cloudflare named variant, falling back to 'public'.
        $cfVariant = ($variant === 'default' || $variant === 'main') ? 'public' : $variant;

        return "https://imagedelivery.net/{$accountId}/{$imageId}/{$cfVariant}";
    }

    public function temporaryUrl(Image $image, string $variant = 'default', int $minutes = 60): string
    {
        // Cloudflare Images supports signed URLs via the Cloudflare Images API.
        // Full implementation requires an API call to generate a signed token.
        // For now, return the standard delivery URL.
        // See: https://developers.cloudflare.com/images/cloudflare-images/serve-images/serve-private-images-using-signed-url-tokens/
        return $this->url($image, $variant);
    }
}

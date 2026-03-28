<?php

namespace IbrahimKaya\ImageMan\Drivers;

use Illuminate\Support\Facades\Storage;
use IbrahimKaya\ImageMan\Drivers\Contracts\UrlGeneratorContract;
use IbrahimKaya\ImageMan\Models\Image;

/**
 * Default URL generator that delegates to Laravel's Storage facade.
 *
 * For public disks  → Storage::disk($disk)->url($path)
 * For private disks → Storage::disk($disk)->temporaryUrl($path, $expiry)
 *
 * This is the out-of-the-box generator; no external CDN credentials required.
 */
class DefaultUrlGenerator implements UrlGeneratorContract
{
    public function url(Image $image, string $variant = 'default'): string
    {
        $path = $this->resolveStoragePath($image, $variant);

        return Storage::disk($image->disk)->url($path);
    }

    public function temporaryUrl(Image $image, string $variant = 'default', int $minutes = 60): string
    {
        $path   = $this->resolveStoragePath($image, $variant);
        $expiry = now()->addMinutes($minutes);

        return Storage::disk($image->disk)->temporaryUrl($path, $expiry);
    }

    /**
     * Resolve the relative storage path for the requested variant.
     *
     * @param  Image  $image
     * @param  string $variant
     * @return string  Relative path within the disk.
     */
    private function resolveStoragePath(Image $image, string $variant): string
    {
        if ($variant === 'default' || $variant === 'main') {
            return $image->directory . '/' . $image->filename;
        }

        if ($variant === 'original') {
            // Original files are stored with the _original suffix.
            $stem = pathinfo($image->filename, PATHINFO_FILENAME);
            // Try to find the original by listing; fall back to the main file.
            return $image->directory . '/' . $stem . '_original';
        }

        $variantData = ($image->variants ?? [])[$variant] ?? null;

        if ($variantData && isset($variantData['path'])) {
            return $variantData['path'];
        }

        // Variant not found — return the main file path as a safe fallback.
        return $image->directory . '/' . $image->filename;
    }
}

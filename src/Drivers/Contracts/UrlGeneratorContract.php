<?php

namespace IbrahimKaya\ImageMan\Drivers\Contracts;

use IbrahimKaya\ImageMan\Models\Image;

/**
 * Contract that every URL generator must satisfy.
 *
 * Implementations are responsible for producing the public-facing URL
 * for a stored image variant. The active implementation is resolved from
 * the service container based on config('imageman.url_generator').
 */
interface UrlGeneratorContract
{
    /**
     * Return the public URL for the given image variant.
     *
     * @param  Image  $image    The image model.
     * @param  string $variant  Variant name ('thumbnail', 'medium', 'default', …).
     * @return string           Absolute public URL.
     */
    public function url(Image $image, string $variant): string;

    /**
     * Return a signed temporary URL for the given image variant.
     *
     * Not all disks support temporary URLs (e.g. local public disk does not).
     * Implementations that cannot support this should throw a \RuntimeException.
     *
     * @param  Image  $image    The image model.
     * @param  string $variant  Variant name.
     * @param  int    $minutes  Number of minutes until the URL expires.
     * @return string           Absolute signed URL.
     */
    public function temporaryUrl(Image $image, string $variant, int $minutes): string;
}

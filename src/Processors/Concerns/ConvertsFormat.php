<?php

namespace IbrahimKaya\ImageMan\Processors\Concerns;

use Intervention\Image\Interfaces\ImageInterface;

/**
 * Provides image format conversion methods for the ImageManipulator.
 * Supports WebP, AVIF and JPEG output using Intervention Image v3.
 */
trait ConvertsFormat
{
    /**
     * Encode the image as WebP and write it to a temporary file.
     *
     * @param  ImageInterface $image   The Intervention image instance to encode.
     * @param  int            $quality Encoding quality (1–100).
     * @return string                  Absolute path of the written temp file.
     */
    protected function encodeWebP(ImageInterface $image, int $quality): string
    {
        $path = $this->tempPath('webp');
        $image->toWebp($quality)->save($path);

        return $path;
    }

    /**
     * Encode the image as AVIF and write it to a temporary file.
     *
     * AVIF encoding is CPU-intensive; consider enabling the queue when using
     * this format (config: imageman.queue = true).
     *
     * @param  ImageInterface $image   The Intervention image instance to encode.
     * @param  int            $quality Encoding quality (1–100).
     * @return string                  Absolute path of the written temp file.
     */
    protected function encodeAvif(ImageInterface $image, int $quality): string
    {
        $path = $this->tempPath('avif');
        $image->toAvif($quality)->save($path);

        return $path;
    }

    /**
     * Encode the image as JPEG and write it to a temporary file.
     *
     * @param  ImageInterface $image   The Intervention image instance to encode.
     * @param  int            $quality Encoding quality (1–100).
     * @return string                  Absolute path of the written temp file.
     */
    protected function encodeJpeg(ImageInterface $image, int $quality): string
    {
        $path = $this->tempPath('jpg');
        $image->toJpeg($quality)->save($path);

        return $path;
    }

    /**
     * Encode the image in its original format and write it to a temp file.
     *
     * @param  ImageInterface $image     The Intervention image instance.
     * @param  string         $extension Original file extension (e.g. 'png', 'gif').
     * @return string                    Absolute path of the written temp file.
     */
    protected function encodeOriginal(ImageInterface $image, string $extension): string
    {
        $path = $this->tempPath($extension);
        $image->save($path);

        return $path;
    }

    /**
     * Resolve the correct MIME type string for a given format name.
     */
    protected function mimeForFormat(string $format): string
    {
        return match ($format) {
            'webp'  => 'image/webp',
            'avif'  => 'image/avif',
            'jpeg', 'jpg' => 'image/jpeg',
            'png'   => 'image/png',
            'gif'   => 'image/gif',
            default => 'application/octet-stream',
        };
    }
}

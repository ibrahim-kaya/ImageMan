<?php

namespace IbrahimKaya\ImageMan\Processors\Concerns;

use Intervention\Image\Interfaces\ImageInterface;

/**
 * Provides image resizing and cropping methods for the ImageManipulator.
 */
trait ResizesImage
{
    /**
     * Resize the image according to the given size preset configuration.
     *
     * @param  ImageInterface $image      Intervention image instance (mutated in place).
     * @param  int            $width      Target width in pixels.
     * @param  int            $height     Target height in pixels.
     * @param  string         $fit        Fit strategy: 'cover' | 'contain' | 'fill' | 'stretch'.
     * @return ImageInterface             The resized image instance.
     */
    protected function applyResize(ImageInterface $image, int $width, int $height, string $fit): ImageInterface
    {
        return match ($fit) {
            // Scale and crop to fill exact dimensions. Parts of the image may be cropped.
            'cover' => $image->cover($width, $height),

            // Fit within dimensions without cropping. May add empty space on two sides.
            'contain' => $image->contain($width, $height),

            // Stretch/squish to exact dimensions. Distorts aspect ratio.
            'fill', 'stretch' => $image->resize($width, $height),

            // Unknown fit strategy — fall back to contain (safe default).
            default => $image->contain($width, $height),
        };
    }

    /**
     * Crop the image to an arbitrary rectangle.
     *
     * @param  ImageInterface $image  Intervention image instance.
     * @param  int            $width  Width of the crop area in pixels.
     * @param  int            $height Height of the crop area in pixels.
     * @param  int            $x      X offset of the top-left crop corner.
     * @param  int            $y      Y offset of the top-left crop corner.
     * @return ImageInterface
     */
    protected function applyCrop(ImageInterface $image, int $width, int $height, int $x = 0, int $y = 0): ImageInterface
    {
        return $image->crop($width, $height, $x, $y);
    }

    /**
     * Rotate the image by the given number of degrees.
     *
     * @param  ImageInterface $image   Intervention image instance.
     * @param  float          $degrees Degrees to rotate (positive = counter-clockwise).
     * @return ImageInterface
     */
    protected function applyRotation(ImageInterface $image, float $degrees): ImageInterface
    {
        return $image->rotate($degrees);
    }

    /**
     * Flip the image horizontally or vertically.
     *
     * @param  ImageInterface $image     Intervention image instance.
     * @param  string         $direction 'h' for horizontal, 'v' for vertical.
     * @return ImageInterface
     */
    protected function applyFlip(ImageInterface $image, string $direction): ImageInterface
    {
        return match ($direction) {
            'h', 'horizontal' => $image->flip(),
            'v', 'vertical'   => $image->flop(),
            default           => $image,
        };
    }
}

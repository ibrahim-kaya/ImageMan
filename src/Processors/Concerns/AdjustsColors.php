<?php

namespace IbrahimKaya\ImageMan\Processors\Concerns;

use Intervention\Image\Interfaces\ImageInterface;

/**
 * Provides color and filter adjustment methods for the ImageManipulator.
 */
trait AdjustsColors
{
    /**
     * Convert the image to greyscale.
     *
     * @param  ImageInterface $image Intervention image instance.
     * @return ImageInterface
     */
    protected function applyGreyscale(ImageInterface $image): ImageInterface
    {
        return $image->greyscale();
    }

    /**
     * Adjust the brightness of the image.
     *
     * @param  ImageInterface $image  Intervention image instance.
     * @param  int            $level  Brightness level. Range: -100 (darkest) to 100 (brightest).
     *                                0 = no change.
     * @return ImageInterface
     */
    protected function applyBrightness(ImageInterface $image, int $level): ImageInterface
    {
        return $image->brightness($level);
    }

    /**
     * Adjust the contrast of the image.
     *
     * @param  ImageInterface $image  Intervention image instance.
     * @param  int            $level  Contrast level. Range: -100 (flat) to 100 (maximum contrast).
     *                                0 = no change.
     * @return ImageInterface
     */
    protected function applyContrast(ImageInterface $image, int $level): ImageInterface
    {
        return $image->contrast($level);
    }

    /**
     * Sharpen the image.
     *
     * @param  ImageInterface $image   Intervention image instance.
     * @param  int            $amount  Sharpening amount (0–100). Default: 10.
     * @return ImageInterface
     */
    protected function applySharpen(ImageInterface $image, int $amount = 10): ImageInterface
    {
        return $image->sharpen($amount);
    }

    /**
     * Apply a Gaussian blur to the image.
     *
     * @param  ImageInterface $image   Intervention image instance.
     * @param  int            $amount  Blur amount (0–100). Higher = more blurred. Default: 15.
     * @return ImageInterface
     */
    protected function applyBlur(ImageInterface $image, int $amount = 15): ImageInterface
    {
        return $image->blur($amount);
    }
}

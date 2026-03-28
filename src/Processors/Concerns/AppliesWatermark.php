<?php

namespace IbrahimKaya\ImageMan\Processors\Concerns;

use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\FontFactory;

/**
 * Provides watermark overlay methods for the ImageManipulator.
 * Supports both image-based (logo) and text-based watermarks.
 */
trait AppliesWatermark
{
    /**
     * Apply a watermark to the given image based on the provided config array.
     *
     * @param  ImageInterface $image  Intervention image instance (mutated in place).
     * @param  array          $config Watermark config subset from imageman.watermark.
     * @return ImageInterface
     */
    protected function applyWatermarkFromConfig(ImageInterface $image, array $config): ImageInterface
    {
        if (empty($config['enabled'])) {
            return $image;
        }

        if (($config['type'] ?? 'image') === 'text') {
            return $this->applyTextWatermark(
                $image,
                $config['text'] ?? '',
                $config['position'] ?? 'bottom-right',
                $config['opacity'] ?? 50,
                $config['padding'] ?? 10,
            );
        }

        if (!empty($config['path']) && file_exists($config['path'])) {
            return $this->applyImageWatermark(
                $image,
                $config['path'],
                $config['position'] ?? 'bottom-right',
                $config['opacity'] ?? 50,
                $config['padding'] ?? 10,
            );
        }

        return $image;
    }

    /**
     * Overlay a logo image file as a watermark.
     *
     * @param  ImageInterface $image      Base image.
     * @param  string         $logoPath   Absolute path to the watermark image (PNG recommended).
     * @param  string         $position   One of: top-left, top-center, top-right,
     *                                    center-left, center, center-right,
     *                                    bottom-left, bottom-center, bottom-right.
     * @param  int            $opacity    Watermark opacity (0–100).
     * @param  int            $padding    Pixel offset from the edge/corner.
     * @return ImageInterface
     */
    protected function applyImageWatermark(
        ImageInterface $image,
        string $logoPath,
        string $position,
        int $opacity,
        int $padding
    ): ImageInterface {
        [$x, $y, $alignment] = $this->resolvePosition($position, $padding, $image->width(), $image->height());

        $watermark = \Intervention\Image\ImageManager::gd()
            ->read($logoPath)
            ->opacity($opacity);

        return $image->place($watermark, $alignment, $x, $y);
    }

    /**
     * Render a text string as a watermark overlay.
     *
     * @param  ImageInterface $image    Base image.
     * @param  string         $text     The watermark text.
     * @param  string         $position Position identifier.
     * @param  int            $opacity  Text opacity (0–100).
     * @param  int            $padding  Pixel offset from the edge/corner.
     * @return ImageInterface
     */
    protected function applyTextWatermark(
        ImageInterface $image,
        string $text,
        string $position,
        int $opacity,
        int $padding
    ): ImageInterface {
        [$x, $y] = $this->resolveTextCoordinates($position, $padding, $image->width(), $image->height());

        $alphaValue = (int) round($opacity / 100 * 255);

        return $image->text($text, $x, $y, function (FontFactory $font) use ($alphaValue) {
            $font->size(20);
            $font->color([255, 255, 255, $alphaValue]);
            $font->align('left');
            $font->valign('top');
        });
    }

    /**
     * Resolve a named position string into Intervention Image placement alignment
     * and pixel offset coordinates.
     *
     * @return array{0: int, 1: int, 2: string}  [$xOffset, $yOffset, $alignment]
     */
    private function resolvePosition(string $position, int $padding, int $imgWidth, int $imgHeight): array
    {
        $map = [
            'top-left'      => ['top-left',     $padding, $padding],
            'top-center'    => ['top',           0,        $padding],
            'top-right'     => ['top-right',     $padding, $padding],
            'center-left'   => ['left',          $padding, 0],
            'center'        => ['center',        0,        0],
            'center-right'  => ['right',         $padding, 0],
            'bottom-left'   => ['bottom-left',   $padding, $padding],
            'bottom-center' => ['bottom',        0,        $padding],
            'bottom-right'  => ['bottom-right',  $padding, $padding],
        ];

        [$alignment, $x, $y] = $map[$position] ?? $map['bottom-right'];

        return [$x, $y, $alignment];
    }

    /**
     * Resolve a named position string into absolute pixel coordinates for text rendering.
     *
     * @return array{0: int, 1: int}  [$x, $y]
     */
    private function resolveTextCoordinates(string $position, int $padding, int $imgWidth, int $imgHeight): array
    {
        return match ($position) {
            'top-left'      => [$padding, $padding],
            'top-center'    => [(int)($imgWidth / 2), $padding],
            'top-right'     => [$imgWidth - $padding, $padding],
            'center-left'   => [$padding, (int)($imgHeight / 2)],
            'center'        => [(int)($imgWidth / 2), (int)($imgHeight / 2)],
            'center-right'  => [$imgWidth - $padding, (int)($imgHeight / 2)],
            'bottom-left'   => [$padding, $imgHeight - $padding],
            'bottom-center' => [(int)($imgWidth / 2), $imgHeight - $padding],
            'bottom-right'  => [$imgWidth - $padding, $imgHeight - $padding],
            default         => [$imgWidth - $padding, $imgHeight - $padding],
        };
    }
}

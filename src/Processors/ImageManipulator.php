<?php

namespace IbrahimKaya\ImageMan\Processors;

use Illuminate\Http\UploadedFile;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use IbrahimKaya\ImageMan\DTOs\ProcessedImage;
use IbrahimKaya\ImageMan\DTOs\VariantResult;
use IbrahimKaya\ImageMan\Exceptions\InvalidImageException;
use IbrahimKaya\ImageMan\Processors\Concerns\AdjustsColors;
use IbrahimKaya\ImageMan\Processors\Concerns\AppliesWatermark;
use IbrahimKaya\ImageMan\Processors\Concerns\ConvertsFormat;
use IbrahimKaya\ImageMan\Processors\Concerns\ResizesImage;

/**
 * Core image processing engine.
 *
 * Reads an uploaded file, applies EXIF stripping, format conversion,
 * resizing, watermarking, LQIP generation, and returns a ProcessedImage DTO
 * containing temporary file paths for all generated outputs.
 *
 * This class is stateless: every public method call creates and destroys its
 * own resources. It has no dependency on the database or filesystem disks —
 * all I/O happens in the system temp directory.
 */
class ImageManipulator
{
    use ConvertsFormat;
    use ResizesImage;
    use AppliesWatermark;
    use AdjustsColors;

    private ImageManager $manager;

    public function __construct()
    {
        // Prefer GD for broad server compatibility. Switch to Imagick if available:
        // $this->manager = ImageManager::imagick();
        $this->manager = ImageManager::gd();
    }

    /**
     * Execute the full processing pipeline for an uploaded file.
     *
     * @param  UploadedFile $file    The raw uploaded file from the HTTP request.
     * @param  array        $config  Merged config: imageman.php + per-upload overrides.
     * @return ProcessedImage        DTO carrying all temporary output paths and metadata.
     *
     * @throws InvalidImageException If the file cannot be decoded as an image.
     */
    public function process(UploadedFile $file, array $config): ProcessedImage
    {
        // Compute the SHA-256 hash before any modifications so we get the
        // true fingerprint of the original uploaded content.
        $hash = hash_file('sha256', $file->getRealPath());

        try {
            $image = $this->manager->read($file->getRealPath());
        } catch (\Throwable $e) {
            throw InvalidImageException::forFile($file->getClientOriginalName());
        }

        // Automatically correct orientation using EXIF data before stripping it.
        // This prevents images from appearing rotated after EXIF removal.
        $image->orient();

        // Strip EXIF metadata to protect uploader privacy (removes GPS, device info, etc.).
        if ($config['strip_exif'] ?? true) {
            $image->removeAnimation();
        }

        // Capture original dimensions before any resizing.
        $origWidth  = $image->width();
        $origHeight = $image->height();

        // --- Apply watermark to the main image (if enabled) ---
        if (!empty($config['watermark']['enabled'])) {
            $image = $this->applyWatermarkFromConfig($image, $config['watermark']);
        }

        // --- Encode the main image in the target format ---
        $format  = $config['format'] ?? 'webp';
        $origExt = strtolower($file->getClientOriginalExtension() ?: 'jpg');

        $mainPath = $this->encodeToFormat($image, $format, $config, $origExt);
        $mimeType = $this->mimeForFormat($format === 'original' ? $origExt : $format);
        $size     = filesize($mainPath);

        // Refresh dimensions after encoding (may differ after format conversion).
        $width  = $image->width();
        $height = $image->height();

        // --- Generate LQIP (Low-Quality Image Placeholder) ---
        $lqip = null;
        if ($config['generate_lqip'] ?? true) {
            $lqip = $this->generateLqip($image, $config['lqip_size'] ?? 20);
        }

        // --- Keep original file if requested ---
        $originalPath = null;
        if ($config['keep_original'] ?? false) {
            $originalPath = $this->tempPath($origExt . '_original');
            copy($file->getRealPath(), $originalPath);
        }

        // --- Generate size variants ---
        $variants = [];
        $sizes    = $config['sizes'] ?? [];

        foreach ($config['requested_sizes'] ?? array_keys($sizes) as $sizeName) {
            if (!isset($sizes[$sizeName])) {
                continue;
            }

            $sizeConfig = $sizes[$sizeName];

            // Create a fresh image instance for each variant to avoid state bleed.
            $variantImage = $this->manager->read($file->getRealPath());
            $variantImage->orient();

            if (!empty($config['watermark']['enabled'])) {
                $variantImage = $this->applyWatermarkFromConfig($variantImage, $config['watermark']);
            }

            $variantImage = $this->applyResize(
                $variantImage,
                (int) $sizeConfig['width'],
                (int) $sizeConfig['height'],
                $sizeConfig['fit'] ?? 'contain',
            );

            $variantPath = $this->encodeToFormat($variantImage, $format, $config, $origExt);

            $variants[$sizeName] = new VariantResult(
                name:     $sizeName,
                tempPath: $variantPath,
                width:    $variantImage->width(),
                height:   $variantImage->height(),
                size:     (int) filesize($variantPath),
            );
        }

        return new ProcessedImage(
            mainPath:     $mainPath,
            mimeType:     $mimeType,
            width:        $width,
            height:       $height,
            size:         $size,
            hash:         $hash,
            lqip:         $lqip,
            originalPath: $originalPath,
            variants:     $variants,
        );
    }

    /**
     * Generate a base64-encoded LQIP data URI from the given image.
     *
     * @param  ImageInterface $image The full-size processed image.
     * @param  int            $size  Width in pixels of the placeholder (height is auto).
     * @return string                data:image/webp;base64,... string.
     */
    public function generateLqip(ImageInterface $image, int $size = 20): string
    {
        // Clone so the original instance is not mutated.
        $placeholder = clone $image;
        $placeholder->scale(width: $size);
        $placeholder->blur(2);

        $encoded = $placeholder->toWebp(30);

        return 'data:image/webp;base64,' . base64_encode((string) $encoded);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Encode an image instance to the configured output format.
     *
     * @param  ImageInterface $image   The image to encode.
     * @param  string         $format  Target format ('webp', 'avif', 'jpeg', 'original').
     * @param  array          $config  Full merged config array.
     * @param  string         $origExt Original file extension (used when format='original').
     * @return string                  Absolute path to the temporary output file.
     */
    private function encodeToFormat(ImageInterface $image, string $format, array $config, string $origExt): string
    {
        return match ($format) {
            'webp'     => $this->encodeWebP($image, $config['webp_quality'] ?? 80),
            'avif'     => $this->encodeAvif($image, $config['avif_quality'] ?? 70),
            'jpeg', 'jpg' => $this->encodeJpeg($image, $config['jpeg_quality'] ?? 85),
            'original' => $this->encodeOriginal($image, $origExt),
            default    => $this->encodeWebP($image, $config['webp_quality'] ?? 80),
        };
    }

    /**
     * Generate a unique temporary file path in the system temp directory.
     *
     * @param  string $extension File extension without the leading dot.
     * @return string            Absolute path (file does not yet exist).
     */
    protected function tempPath(string $extension): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'imageman_' . uniqid() . '.' . ltrim($extension, '.');
    }
}

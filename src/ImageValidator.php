<?php

namespace IbrahimKaya\ImageMan;

use Illuminate\Http\UploadedFile;
use IbrahimKaya\ImageMan\Exceptions\ValidationException;

/**
 * Validates an uploaded image against a set of configurable constraints
 * before the processing pipeline begins.
 *
 * All constraints are optional (null = no limit). Validation failures
 * accumulate into a single ValidationException containing all error messages.
 */
class ImageValidator
{
    /** @var array<string, mixed> */
    protected array $rules = [];

    public function __construct(array $defaults = [])
    {
        $this->rules = $defaults;
    }

    // -----------------------------------------------------------------------
    // Fluent constraint setters
    // -----------------------------------------------------------------------

    /** Maximum file size in kilobytes. */
    public function maxSize(int $kb): static
    {
        $this->rules['max_size'] = $kb;
        return $this;
    }

    /** Minimum image width in pixels. */
    public function minWidth(int $pixels): static
    {
        $this->rules['min_width'] = $pixels;
        return $this;
    }

    /** Maximum image width in pixels. */
    public function maxWidth(int $pixels): static
    {
        $this->rules['max_width'] = $pixels;
        return $this;
    }

    /** Minimum image height in pixels. */
    public function minHeight(int $pixels): static
    {
        $this->rules['min_height'] = $pixels;
        return $this;
    }

    /** Maximum image height in pixels. */
    public function maxHeight(int $pixels): static
    {
        $this->rules['max_height'] = $pixels;
        return $this;
    }

    /**
     * Enforce a specific aspect ratio.
     *
     * @param  string $ratio  Fraction string, e.g. '16/9', '1/1', '4/3'.
     */
    public function aspectRatio(string $ratio): static
    {
        $this->rules['aspect_ratio'] = $ratio;
        return $this;
    }

    /**
     * Override the list of accepted MIME types.
     *
     * @param  array<string> $mimes  e.g. ['image/jpeg', 'image/png']
     */
    public function allowedMimes(array $mimes): static
    {
        $this->rules['allowed_mimes'] = $mimes;
        return $this;
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    /**
     * Validate the uploaded file against all configured constraints.
     *
     * @throws ValidationException If one or more constraints are violated.
     */
    public function validate(UploadedFile $file): void
    {
        $errors = [];

        // --- MIME type check ---
        $allowedMimes = $this->rules['allowed_mimes'] ?? [];
        if (!empty($allowedMimes) && !in_array($file->getMimeType(), $allowedMimes, true)) {
            $errors['mime'] = "File type [{$file->getMimeType()}] is not allowed. "
                . "Accepted types: " . implode(', ', $allowedMimes) . '.';
        }

        // --- File size check (KB) ---
        $maxSize = $this->rules['max_size'] ?? null;
        if ($maxSize !== null) {
            $fileSizeKb = $file->getSize() / 1024;
            if ($fileSizeKb > $maxSize) {
                $errors['size'] = "File size ({$fileSizeKb} KB) exceeds the maximum allowed size ({$maxSize} KB).";
            }
        }

        // Dimension checks require reading image metadata.
        // Only performed when at least one dimension rule is set.
        $hasDimensionRule = array_intersect_key($this->rules, array_flip([
            'min_width', 'max_width', 'min_height', 'max_height', 'aspect_ratio',
        ]));

        if (!empty($hasDimensionRule)) {
            [$width, $height] = $this->readDimensions($file);

            if ($width > 0 && $height > 0) {
                $errors = array_merge($errors, $this->checkDimensions($width, $height));
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withErrors($errors);
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Read the width and height from the uploaded file using PHP's getimagesize().
     *
     * @return array{0: int, 1: int}  [$width, $height]
     */
    private function readDimensions(UploadedFile $file): array
    {
        $info = @getimagesize($file->getRealPath());

        return $info ? [(int) $info[0], (int) $info[1]] : [0, 0];
    }

    /**
     * Check all dimension-related rules and return any error messages.
     *
     * @return array<string, string>
     */
    private function checkDimensions(int $width, int $height): array
    {
        $errors = [];

        if (!empty($this->rules['min_width']) && $width < $this->rules['min_width']) {
            $errors['min_width'] = "Image width ({$width}px) is below the minimum required {$this->rules['min_width']}px.";
        }

        if (!empty($this->rules['max_width']) && $width > $this->rules['max_width']) {
            $errors['max_width'] = "Image width ({$width}px) exceeds the maximum allowed {$this->rules['max_width']}px.";
        }

        if (!empty($this->rules['min_height']) && $height < $this->rules['min_height']) {
            $errors['min_height'] = "Image height ({$height}px) is below the minimum required {$this->rules['min_height']}px.";
        }

        if (!empty($this->rules['max_height']) && $height > $this->rules['max_height']) {
            $errors['max_height'] = "Image height ({$height}px) exceeds the maximum allowed {$this->rules['max_height']}px.";
        }

        if (!empty($this->rules['aspect_ratio'])) {
            $errors = array_merge($errors, $this->checkAspectRatio($width, $height, $this->rules['aspect_ratio']));
        }

        return $errors;
    }

    /**
     * Validate that the image's aspect ratio matches the required fraction.
     * Applies a ±5% tolerance to account for rounding in camera-produced images.
     *
     * @return array<string, string>
     */
    private function checkAspectRatio(int $width, int $height, string $ratio): array
    {
        if ($height === 0) {
            return [];
        }

        [$numerator, $denominator] = explode('/', $ratio, 2) + [1, 1];

        if ((int) $denominator === 0) {
            return [];
        }

        $required = (float) $numerator / (float) $denominator;
        $actual   = $width / $height;
        $tolerance = $required * 0.05; // ±5%

        if (abs($actual - $required) > $tolerance) {
            $actualFormatted   = round($actual, 2);
            $requiredFormatted = round($required, 2);
            return [
                'aspect_ratio' => "Image aspect ratio ({$actualFormatted}) does not match the required ratio {$ratio} ({$requiredFormatted}).",
            ];
        }

        return [];
    }
}

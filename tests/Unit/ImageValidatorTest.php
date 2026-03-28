<?php

namespace IbrahimKaya\ImageMan\Tests\Unit;

use IbrahimKaya\ImageMan\Exceptions\ValidationException;
use IbrahimKaya\ImageMan\ImageValidator;
use IbrahimKaya\ImageMan\Tests\TestCase;

/**
 * Unit tests for ImageValidator.
 */
class ImageValidatorTest extends TestCase
{
    // -----------------------------------------------------------------------
    // File size
    // -----------------------------------------------------------------------

    public function test_passes_when_file_size_is_within_limit(): void
    {
        $validator = new ImageValidator();
        $validator->maxSize(10240); // 10 MB

        $file = $this->fakeImageFile();

        // Should not throw.
        $validator->validate($file);
        $this->assertTrue(true);
    }

    public function test_fails_when_file_exceeds_max_size(): void
    {
        $this->expectException(ValidationException::class);

        $validator = new ImageValidator();
        $validator->maxSize(1); // 1 KB — impossible to satisfy with a real image

        $validator->validate($this->fakeImageFile());
    }

    // -----------------------------------------------------------------------
    // MIME type
    // -----------------------------------------------------------------------

    public function test_passes_allowed_mime_type(): void
    {
        $validator = new ImageValidator();
        $validator->allowedMimes(['image/jpeg', 'image/png']);

        $validator->validate($this->fakeImageFile());
        $this->assertTrue(true);
    }

    public function test_fails_disallowed_mime_type(): void
    {
        $this->expectException(ValidationException::class);

        $validator = new ImageValidator();
        $validator->allowedMimes(['image/png']); // JPEG not allowed

        $validator->validate($this->fakeImageFile('test.jpg'));
    }

    // -----------------------------------------------------------------------
    // Dimensions
    // -----------------------------------------------------------------------

    public function test_passes_when_dimensions_are_within_range(): void
    {
        $validator = new ImageValidator();
        $validator->minWidth(50)->maxWidth(200)->minHeight(50)->maxHeight(200);

        $validator->validate($this->fakeImageFile('test.jpg', 100, 100));
        $this->assertTrue(true);
    }

    public function test_fails_when_image_is_too_narrow(): void
    {
        $this->expectException(ValidationException::class);

        $validator = new ImageValidator();
        $validator->minWidth(500);

        $validator->validate($this->fakeImageFile('test.jpg', 100, 100));
    }

    // -----------------------------------------------------------------------
    // Aspect ratio
    // -----------------------------------------------------------------------

    public function test_passes_matching_aspect_ratio(): void
    {
        $validator = new ImageValidator();
        $validator->aspectRatio('1/1');

        // 100×100 = 1:1 ratio.
        $validator->validate($this->fakeImageFile('test.jpg', 100, 100));
        $this->assertTrue(true);
    }

    public function test_fails_non_matching_aspect_ratio(): void
    {
        $this->expectException(ValidationException::class);

        $validator = new ImageValidator();
        $validator->aspectRatio('16/9');

        // 100×100 is not 16:9.
        $validator->validate($this->fakeImageFile('test.jpg', 100, 100));
    }

    // -----------------------------------------------------------------------
    // Error accumulation
    // -----------------------------------------------------------------------

    public function test_validation_exception_contains_all_error_messages(): void
    {
        $validator = new ImageValidator();
        $validator->maxSize(1)->minWidth(10000); // Both will fail.

        try {
            $validator->validate($this->fakeImageFile());
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('size', $e->errors());
            $this->assertArrayHasKey('min_width', $e->errors());
        }
    }
}

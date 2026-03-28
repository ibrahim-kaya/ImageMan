<?php

namespace IbrahimKaya\ImageMan\Tests\Unit;

use IbrahimKaya\ImageMan\Processors\ImageManipulator;
use IbrahimKaya\ImageMan\Tests\TestCase;

/**
 * Unit tests for ImageManipulator (image processing engine).
 */
class ImageProcessorTest extends TestCase
{
    private ImageManipulator $manipulator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manipulator = new ImageManipulator();
    }

    // -----------------------------------------------------------------------
    // Format conversion
    // -----------------------------------------------------------------------

    public function test_converts_jpeg_to_webp(): void
    {
        $file   = $this->fakeImageFile();
        $config = array_merge(config('imageman'), [
            'format'          => 'webp',
            'requested_sizes' => [],
        ]);

        $result = $this->manipulator->process($file, $config);

        $this->assertSame('image/webp', $result->mimeType);
        $this->assertFileExists($result->mainPath);
    }

    public function test_generates_correct_dimensions(): void
    {
        $file   = $this->fakeImageFile('test.jpg', 800, 600);
        $config = array_merge(config('imageman'), [
            'format'          => 'webp',
            'requested_sizes' => [],
        ]);

        $result = $this->manipulator->process($file, $config);

        $this->assertSame(800, $result->width);
        $this->assertSame(600, $result->height);
    }

    // -----------------------------------------------------------------------
    // LQIP generation
    // -----------------------------------------------------------------------

    public function test_generates_lqip_when_enabled(): void
    {
        $file   = $this->fakeImageFile();
        $config = array_merge(config('imageman'), [
            'format'          => 'webp',
            'generate_lqip'   => true,
            'requested_sizes' => [],
        ]);

        $result = $this->manipulator->process($file, $config);

        $this->assertNotNull($result->lqip);
        $this->assertStringStartsWith('data:image/webp;base64,', $result->lqip);
    }

    public function test_skips_lqip_when_disabled(): void
    {
        $file   = $this->fakeImageFile();
        $config = array_merge(config('imageman'), [
            'format'          => 'webp',
            'generate_lqip'   => false,
            'requested_sizes' => [],
        ]);

        $result = $this->manipulator->process($file, $config);

        $this->assertNull($result->lqip);
    }

    // -----------------------------------------------------------------------
    // Size variants
    // -----------------------------------------------------------------------

    public function test_generates_requested_size_variants(): void
    {
        $file   = $this->fakeImageFile('test.jpg', 1000, 800);
        $config = array_merge(config('imageman'), [
            'format'          => 'webp',
            'requested_sizes' => ['thumbnail'],
            'sizes'           => [
                'thumbnail' => ['width' => 150, 'height' => 150, 'fit' => 'cover'],
            ],
        ]);

        $result = $this->manipulator->process($file, $config);

        $this->assertArrayHasKey('thumbnail', $result->variants);
        $this->assertSame(150, $result->variants['thumbnail']->width);
        $this->assertSame(150, $result->variants['thumbnail']->height);
    }

    // -----------------------------------------------------------------------
    // Hash
    // -----------------------------------------------------------------------

    public function test_computes_sha256_hash(): void
    {
        $file       = $this->fakeImageFile();
        $config     = array_merge(config('imageman'), ['format' => 'webp', 'requested_sizes' => []]);
        $result     = $this->manipulator->process($file, $config);
        $expected   = hash_file('sha256', $file->getRealPath());

        $this->assertSame($expected, $result->hash);
    }

    // -----------------------------------------------------------------------
    // Cleanup
    // -----------------------------------------------------------------------

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->cleanupTestDisk();
    }
}

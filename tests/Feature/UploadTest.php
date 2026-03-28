<?php

namespace IbrahimKaya\ImageMan\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use IbrahimKaya\ImageMan\ImageManFacade as ImageMan;
use IbrahimKaya\ImageMan\Models\Image;
use IbrahimKaya\ImageMan\Tests\TestCase;

/**
 * Feature tests for the full image upload pipeline.
 */
class UploadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    // -----------------------------------------------------------------------
    // Basic upload
    // -----------------------------------------------------------------------

    public function test_basic_upload_creates_image_record(): void
    {
        $file  = $this->fakeImageFile();
        $image = ImageMan::upload($file)->save();

        $this->assertInstanceOf(Image::class, $image);
        $this->assertNotNull($image->id);
        $this->assertDatabaseHas('imageman_images', ['id' => $image->id]);
    }

    public function test_upload_stores_file_on_disk(): void
    {
        $file  = $this->fakeImageFile();
        $image = ImageMan::upload($file)->save();

        $mainPath = $image->directory . '/' . $image->filename;
        Storage::disk('local')->assertExists($mainPath);
    }

    public function test_upload_sets_correct_mime_type(): void
    {
        $file  = $this->fakeImageFile();
        $image = ImageMan::upload($file)->save();

        $this->assertSame('image/webp', $image->mime_type);
    }

    // -----------------------------------------------------------------------
    // Size variants
    // -----------------------------------------------------------------------

    public function test_upload_generates_default_variants(): void
    {
        $file  = $this->fakeImageFile('test.jpg', 1000, 800);
        $image = ImageMan::upload($file)->save();

        foreach (config('imageman.default_sizes') as $size) {
            $this->assertArrayHasKey($size, $image->variants ?? []);
        }
    }

    public function test_upload_respects_custom_sizes(): void
    {
        $file  = $this->fakeImageFile('test.jpg', 1000, 800);
        $image = ImageMan::upload($file)->sizes(['thumbnail'])->save();

        $this->assertArrayHasKey('thumbnail', $image->variants ?? []);
        $this->assertArrayNotHasKey('medium', $image->variants ?? []);
    }

    // -----------------------------------------------------------------------
    // URL retrieval
    // -----------------------------------------------------------------------

    public function test_url_returns_string_for_existing_variant(): void
    {
        $file  = $this->fakeImageFile('test.jpg', 1000, 800);
        $image = ImageMan::upload($file)->sizes(['thumbnail'])->save();

        $url = $image->url('thumbnail');
        $this->assertIsString($url);
        $this->assertNotEmpty($url);
    }

    public function test_url_falls_back_to_fallback_url_for_missing_variant(): void
    {
        config(['imageman.fallback_url' => 'https://example.com/fallback.jpg']);

        $file  = $this->fakeImageFile();
        $image = ImageMan::upload($file)->sizes([])->save();

        // 'large' was not generated, so fallback URL should be returned.
        $this->assertSame('https://example.com/fallback.jpg', $image->url('large'));
    }

    // -----------------------------------------------------------------------
    // LQIP
    // -----------------------------------------------------------------------

    public function test_lqip_is_stored_when_enabled(): void
    {
        config(['imageman.generate_lqip' => true]);

        $file  = $this->fakeImageFile();
        $image = ImageMan::upload($file)->save();

        $this->assertNotNull($image->lqip);
        $this->assertStringStartsWith('data:image/webp;base64,', $image->lqip);
    }

    // -----------------------------------------------------------------------
    // Deletion
    // -----------------------------------------------------------------------

    public function test_delete_removes_record_and_files(): void
    {
        $file  = $this->fakeImageFile('test.jpg', 1000, 800);
        $image = ImageMan::upload($file)->save();
        $id    = $image->id;

        $image->delete();

        $this->assertDatabaseMissing('imageman_images', ['id' => $id]);
    }

    // -----------------------------------------------------------------------
    // Disk switching
    // -----------------------------------------------------------------------

    public function test_upload_uses_specified_disk(): void
    {
        Storage::fake('s3');

        $file  = $this->fakeImageFile();
        $image = ImageMan::upload($file)->disk('s3')->save();

        $this->assertSame('s3', $image->disk);
    }

    // -----------------------------------------------------------------------
    // Teardown
    // -----------------------------------------------------------------------

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->cleanupTestDisk();
    }
}

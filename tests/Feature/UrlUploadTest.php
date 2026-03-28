<?php

namespace IbrahimKaya\ImageMan\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use IbrahimKaya\ImageMan\Exceptions\UrlFetchException;
use IbrahimKaya\ImageMan\ImageManFacade as ImageMan;
use IbrahimKaya\ImageMan\Models\Image;
use IbrahimKaya\ImageMan\Tests\TestCase;

/**
 * Feature tests for URL-based image uploads.
 *
 * All HTTP calls are intercepted with Laravel's Http::fake() so no real
 * network requests are made during the test suite.
 */
class UrlUploadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    // -----------------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------------

    public function test_uploads_image_from_url_and_creates_record(): void
    {
        Http::fake([
            'https://example.com/photo.jpg' => $this->fakeImageHttpResponse(),
        ]);

        $image = ImageMan::uploadFromUrl('https://example.com/photo.jpg')->save();

        $this->assertInstanceOf(Image::class, $image);
        $this->assertNotNull($image->id);
        $this->assertDatabaseHas('imageman_images', ['id' => $image->id]);
    }

    public function test_url_upload_produces_webp_output(): void
    {
        Http::fake([
            'https://example.com/photo.jpg' => $this->fakeImageHttpResponse(),
        ]);

        $image = ImageMan::uploadFromUrl('https://example.com/photo.jpg')->save();

        $this->assertSame('image/webp', $image->mime_type);
    }

    public function test_url_upload_stores_file_on_disk(): void
    {
        Http::fake([
            'https://example.com/photo.jpg' => $this->fakeImageHttpResponse(),
        ]);

        $image = ImageMan::uploadFromUrl('https://example.com/photo.jpg')->save();

        $mainPath = $image->directory . '/' . $image->filename;
        Storage::disk('local')->assertExists($mainPath);
    }

    public function test_url_upload_accepts_fluent_options(): void
    {
        Storage::fake('s3');

        Http::fake([
            'https://example.com/photo.jpg' => $this->fakeImageHttpResponse(),
        ]);

        $image = ImageMan::uploadFromUrl('https://example.com/photo.jpg')
            ->disk('s3')
            ->collection('remote')
            ->sizes(['thumbnail'])
            ->save();

        $this->assertSame('s3', $image->disk);
        $this->assertSame('remote', $image->collection);
        $this->assertArrayHasKey('thumbnail', $image->variants ?? []);
    }

    // -----------------------------------------------------------------------
    // HasImages trait with URL
    // -----------------------------------------------------------------------

    public function test_has_images_trait_accepts_url_string(): void
    {
        Http::fake([
            'https://example.com/photo.jpg' => $this->fakeImageHttpResponse(),
        ]);

        // Use a real model class from the HasImages feature test.
        $post  = \IbrahimKaya\ImageMan\Tests\Feature\TestPost::create(['title' => 'URL test']);
        $image = $post->uploadImage('https://example.com/photo.jpg', 'gallery');

        $this->assertInstanceOf(Image::class, $image);
        $this->assertSame('gallery', $image->collection);
        $this->assertSame($post->id, $image->imageable_id);
    }

    // -----------------------------------------------------------------------
    // Error cases
    // -----------------------------------------------------------------------

    public function test_throws_when_url_returns_non_200(): void
    {
        Http::fake([
            'https://example.com/missing.jpg' => Http::response('Not Found', 404),
        ]);

        $this->expectException(UrlFetchException::class);

        ImageMan::uploadFromUrl('https://example.com/missing.jpg')->save();
    }

    public function test_throws_when_response_is_not_an_image(): void
    {
        Http::fake([
            'https://example.com/page.html' => Http::response(
                '<html>Hello</html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $this->expectException(UrlFetchException::class);

        ImageMan::uploadFromUrl('https://example.com/page.html')->save();
    }

    public function test_throws_when_response_body_is_empty(): void
    {
        Http::fake([
            'https://example.com/empty.jpg' => Http::response(
                '',
                200,
                ['Content-Type' => 'image/jpeg']
            ),
        ]);

        $this->expectException(UrlFetchException::class);

        ImageMan::uploadFromUrl('https://example.com/empty.jpg')->save();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build a fake HTTP response containing a real JPEG image body.
     */
    private function fakeImageHttpResponse(): \Illuminate\Http\Client\Response
    {
        // Generate a tiny JPEG in memory using GD.
        $img = imagecreatetruecolor(100, 100);
        $red = imagecolorallocate($img, 200, 50, 50);
        imagefill($img, 0, 0, $red);

        ob_start();
        imagejpeg($img, null, 85);
        $body = ob_get_clean();
        imagedestroy($img);

        return Http::response($body, 200, ['Content-Type' => 'image/jpeg']);
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

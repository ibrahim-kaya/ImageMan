<?php

namespace IbrahimKaya\ImageMan\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use IbrahimKaya\ImageMan\Models\Image;
use IbrahimKaya\ImageMan\Tests\TestCase;
use IbrahimKaya\ImageMan\Traits\HasImages;

/**
 * Feature tests for the HasImages trait.
 *
 * Uses a simple stub model (TestPost) created only for these tests.
 */
class HasImagesTraitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        // Create a temporary posts table for the stub model.
        Schema::create('test_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title')->default('Test');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_posts');
        $this->cleanupTestDisk();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    public function test_upload_image_associates_image_with_model(): void
    {
        $post  = TestPost::create(['title' => 'Hello']);
        $file  = $this->fakeImageFile();
        $image = $post->uploadImage($file);

        $this->assertInstanceOf(Image::class, $image);
        $this->assertSame(TestPost::class, $image->imageable_type);
        $this->assertSame($post->id, $image->imageable_id);
    }

    public function test_get_image_returns_latest_for_collection(): void
    {
        $post = TestPost::create(['title' => 'Hello']);
        $post->uploadImage($this->fakeImageFile(), 'avatar');

        $found = $post->getImage('avatar');

        $this->assertNotNull($found);
        $this->assertSame('avatar', $found->collection);
    }

    public function test_get_images_returns_all_for_collection(): void
    {
        $post = TestPost::create(['title' => 'Hello']);
        $post->uploadImage($this->fakeImageFile(), 'gallery');
        $post->uploadImage($this->fakeImageFile(), 'gallery');

        $images = $post->getImages('gallery');

        $this->assertCount(2, $images);
    }

    public function test_delete_images_removes_all_in_collection(): void
    {
        $post = TestPost::create(['title' => 'Hello']);
        $post->uploadImage($this->fakeImageFile(), 'gallery');
        $post->uploadImage($this->fakeImageFile(), 'gallery');

        $post->deleteImages('gallery');

        $this->assertCount(0, $post->getImages('gallery'));
    }

    public function test_has_image_returns_true_when_image_exists(): void
    {
        $post = TestPost::create(['title' => 'Hello']);
        $post->uploadImage($this->fakeImageFile(), 'avatar');

        $this->assertTrue($post->hasImage('avatar'));
        $this->assertFalse($post->hasImage('gallery'));
    }

    public function test_images_morphmany_relationship(): void
    {
        $post = TestPost::create(['title' => 'Hello']);
        $post->uploadImage($this->fakeImageFile());
        $post->uploadImage($this->fakeImageFile());

        $this->assertCount(2, $post->images);
    }
}

/**
 * Stub Eloquent model used only within this test class.
 */
class TestPost extends Model
{
    use HasImages;

    protected $table    = 'test_posts';
    protected $fillable = ['title'];
}

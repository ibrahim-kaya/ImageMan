<?php

namespace IbrahimKaya\ImageMan\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use IbrahimKaya\ImageMan\ImageManFacade as ImageMan;
use IbrahimKaya\ImageMan\Models\Image;
use IbrahimKaya\ImageMan\Tests\TestCase;
use IbrahimKaya\ImageMan\Traits\HasImages;

/**
 * Feature tests for singleton collection behaviour (replaceExisting).
 */
class SingletonCollectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Schema::create('singleton_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title')->default('Test');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('singleton_posts');
        $this->cleanupTestDisk();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Explicit ->replaceExisting()
    // -----------------------------------------------------------------------

    public function test_replace_existing_removes_old_image_after_new_upload(): void
    {
        $post = SingletonPost::create();

        // First upload.
        $first = ImageMan::upload($this->fakeImageFile())
            ->for($post)
            ->collection('profile_pic')
            ->save();

        $this->assertDatabaseHas('imageman_images', ['id' => $first->id]);

        // Second upload with replaceExisting.
        $second = ImageMan::upload($this->fakeImageFile())
            ->for($post)
            ->collection('profile_pic')
            ->replaceExisting()
            ->save();

        // Old record must be gone.
        $this->assertDatabaseMissing('imageman_images', ['id' => $first->id]);

        // New record must exist.
        $this->assertDatabaseHas('imageman_images', ['id' => $second->id]);
    }

    public function test_only_one_image_remains_after_multiple_replace_uploads(): void
    {
        $post = SingletonPost::create();

        foreach (range(1, 4) as $_) {
            ImageMan::upload($this->fakeImageFile())
                ->for($post)
                ->collection('avatar')
                ->replaceExisting()
                ->save();
        }

        $remaining = Image::where('imageable_type', SingletonPost::class)
            ->where('imageable_id', $post->id)
            ->where('collection', 'avatar')
            ->count();

        $this->assertSame(1, $remaining);
    }

    public function test_replace_existing_does_not_touch_other_collections(): void
    {
        $post = SingletonPost::create();

        // Upload one gallery image.
        $gallery = ImageMan::upload($this->fakeImageFile())
            ->for($post)
            ->collection('gallery')
            ->save();

        // Upload a profile_pic with replaceExisting.
        ImageMan::upload($this->fakeImageFile())
            ->for($post)
            ->collection('profile_pic')
            ->replaceExisting()
            ->save();

        // Gallery image must still be there.
        $this->assertDatabaseHas('imageman_images', ['id' => $gallery->id]);
    }

    public function test_replace_existing_does_not_affect_other_model_instances(): void
    {
        $post1 = SingletonPost::create();
        $post2 = SingletonPost::create();

        // Each post uploads to the same collection.
        $image1 = ImageMan::upload($this->fakeImageFile())
            ->for($post1)->collection('avatar')->save();

        $image2 = ImageMan::upload($this->fakeImageFile())
            ->for($post2)->collection('avatar')->save();

        // Now post2 uploads a replacement.
        ImageMan::upload($this->fakeImageFile())
            ->for($post2)->collection('avatar')->replaceExisting()->save();

        // post1's image must be untouched.
        $this->assertDatabaseHas('imageman_images', ['id' => $image1->id]);

        // post2's old image must be gone.
        $this->assertDatabaseMissing('imageman_images', ['id' => $image2->id]);
    }

    // -----------------------------------------------------------------------
    // Config-based singleton_collections
    // -----------------------------------------------------------------------

    public function test_singleton_collection_from_config_auto_replaces(): void
    {
        config(['imageman.singleton_collections' => ['profile_pic']]);

        $post = SingletonPost::create();

        $first = ImageMan::upload($this->fakeImageFile())
            ->for($post)->collection('profile_pic')->save();

        // No explicit ->replaceExisting() — config handles it.
        $second = ImageMan::upload($this->fakeImageFile())
            ->for($post)->collection('profile_pic')->save();

        $this->assertDatabaseMissing('imageman_images', ['id' => $first->id]);
        $this->assertDatabaseHas('imageman_images', ['id' => $second->id]);
    }

    public function test_keep_existing_overrides_singleton_collection_config(): void
    {
        config(['imageman.singleton_collections' => ['avatar']]);

        $post = SingletonPost::create();

        $first = ImageMan::upload($this->fakeImageFile())
            ->for($post)->collection('avatar')->save();

        // Explicitly opt out of replace behaviour.
        $second = ImageMan::upload($this->fakeImageFile())
            ->for($post)->collection('avatar')->keepExisting()->save();

        // Both images must survive.
        $this->assertDatabaseHas('imageman_images', ['id' => $first->id]);
        $this->assertDatabaseHas('imageman_images', ['id' => $second->id]);
    }

    // -----------------------------------------------------------------------
    // HasImages trait
    // -----------------------------------------------------------------------

    public function test_has_images_trait_replace_option(): void
    {
        $post = SingletonPost::create();

        $first  = $post->uploadImage($this->fakeImageFile(), 'profile_pic');
        $second = $post->uploadImage($this->fakeImageFile(), 'profile_pic', ['replace' => true]);

        $this->assertDatabaseMissing('imageman_images', ['id' => $first->id]);
        $this->assertDatabaseHas('imageman_images', ['id' => $second->id]);
    }

    public function test_has_images_trait_replace_via_singleton_config(): void
    {
        config(['imageman.singleton_collections' => ['profile_pic']]);

        $post = SingletonPost::create();

        $first  = $post->uploadImage($this->fakeImageFile(), 'profile_pic');
        $second = $post->uploadImage($this->fakeImageFile(), 'profile_pic');

        $this->assertDatabaseMissing('imageman_images', ['id' => $first->id]);
        $this->assertDatabaseHas('imageman_images', ['id' => $second->id]);
    }

    // -----------------------------------------------------------------------
    // Edge cases
    // -----------------------------------------------------------------------

    public function test_replace_existing_without_model_is_silent_noop(): void
    {
        // No ->for($model) — replaceExisting should simply be ignored.
        $image = ImageMan::upload($this->fakeImageFile())
            ->collection('avatar')
            ->replaceExisting()
            ->save();

        $this->assertDatabaseHas('imageman_images', ['id' => $image->id]);
    }

    public function test_new_image_survives_even_when_old_deletion_is_scoped_correctly(): void
    {
        $post = SingletonPost::create();

        // Upload and immediately replace 3 times.
        $last = null;
        foreach (range(1, 3) as $_) {
            $last = ImageMan::upload($this->fakeImageFile())
                ->for($post)->collection('cover')->replaceExisting()->save();
        }

        $this->assertNotNull($last);
        $this->assertDatabaseHas('imageman_images', ['id' => $last->id]);

        $total = Image::where('imageable_type', SingletonPost::class)
            ->where('imageable_id', $post->id)
            ->where('collection', 'cover')
            ->count();

        $this->assertSame(1, $total);
    }
}

/**
 * Stub model used only within this test file.
 */
class SingletonPost extends \Illuminate\Database\Eloquent\Model
{
    use HasImages;

    protected $table    = 'singleton_posts';
    protected $fillable = ['title'];
}

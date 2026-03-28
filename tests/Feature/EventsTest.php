<?php

namespace IbrahimKaya\ImageMan\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use IbrahimKaya\ImageMan\Events\ImageDeleted;
use IbrahimKaya\ImageMan\Events\ImageProcessed;
use IbrahimKaya\ImageMan\Events\ImageUploaded;
use IbrahimKaya\ImageMan\ImageManFacade as ImageMan;
use IbrahimKaya\ImageMan\Tests\TestCase;

/**
 * Feature tests verifying that ImageMan fires the correct events.
 */
class EventsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_image_uploaded_event_is_fired_on_save(): void
    {
        Event::fake([ImageUploaded::class]);

        $file = $this->fakeImageFile();
        ImageMan::upload($file)->save();

        Event::assertDispatched(ImageUploaded::class);
    }

    public function test_image_processed_event_is_fired_after_sync_processing(): void
    {
        Event::fake([ImageProcessed::class]);

        $file = $this->fakeImageFile();
        ImageMan::upload($file)->save();

        Event::assertDispatched(ImageProcessed::class);
    }

    public function test_image_uploaded_event_carries_image_model(): void
    {
        Event::fake([ImageUploaded::class, ImageProcessed::class]);

        $file  = $this->fakeImageFile();
        $image = ImageMan::upload($file)->save();

        Event::assertDispatched(ImageUploaded::class, function (ImageUploaded $event) use ($image) {
            return $event->image->id === $image->id;
        });
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->cleanupTestDisk();
    }
}

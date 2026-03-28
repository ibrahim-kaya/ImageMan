<?php

namespace IbrahimKaya\ImageMan\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use IbrahimKaya\ImageMan\ImageManServiceProvider;

/**
 * Base test case for all ImageMan tests.
 *
 * Bootstraps the package via Orchestra Testbench, sets up an in-memory SQLite
 * database, and runs the package migrations before each test.
 */
abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ImageManServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'ImageMan' => \IbrahimKaya\ImageMan\ImageManFacade::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Use SQLite in-memory database for speed.
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Use a temporary local disk for storing test files.
        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root'   => sys_get_temp_dir() . '/imageman_tests',
        ]);

        // Use synchronous queue processing in tests.
        $app['config']->set('queue.default', 'sync');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Run the package migration.
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    // -----------------------------------------------------------------------
    // Test helpers
    // -----------------------------------------------------------------------

    /**
     * Create a real JPEG UploadedFile for testing.
     * Generates a 100×100 red image using GD.
     *
     * @param  string $name  Client-side filename.
     * @return \Illuminate\Http\UploadedFile
     */
    protected function fakeImageFile(string $name = 'test.jpg', int $width = 100, int $height = 100): \Illuminate\Http\UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imageman_test_') . '.jpg';

        $img = imagecreatetruecolor($width, $height);
        $red = imagecolorallocate($img, 255, 0, 0);
        imagefill($img, 0, 0, $red);
        imagejpeg($img, $path, 90);
        imagedestroy($img);

        return new \Illuminate\Http\UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    /**
     * Clean up all test files from the temporary disk directory.
     */
    protected function cleanupTestDisk(): void
    {
        $dir = sys_get_temp_dir() . '/imageman_tests';
        if (is_dir($dir)) {
            $this->deleteDirectory($dir);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        foreach (glob("{$dir}/*") as $file) {
            is_dir($file) ? $this->deleteDirectory($file) : unlink($file);
        }
        rmdir($dir);
    }
}

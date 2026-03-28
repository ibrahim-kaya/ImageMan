<?php

namespace IbrahimKaya\ImageMan;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use IbrahimKaya\ImageMan\Console\CleanOrphanedImagesCommand;
use IbrahimKaya\ImageMan\Console\ConvertToWebpCommand;
use IbrahimKaya\ImageMan\Console\RegenerateVariantsCommand;
use IbrahimKaya\ImageMan\Drivers\CloudflareUrlGenerator;
use IbrahimKaya\ImageMan\Drivers\CloudinaryUrlGenerator;
use IbrahimKaya\ImageMan\Drivers\Contracts\UrlGeneratorContract;
use IbrahimKaya\ImageMan\Drivers\DefaultUrlGenerator;
use IbrahimKaya\ImageMan\Drivers\ImageKitUrlGenerator;
use IbrahimKaya\ImageMan\Drivers\ImgixUrlGenerator;
use IbrahimKaya\ImageMan\Models\Image;
use IbrahimKaya\ImageMan\Processors\ImageManipulator;

/**
 * ImageMan Service Provider.
 *
 * Registers all package bindings, publishes configuration and migrations,
 * loads routes (optionally), registers Blade directives, and binds Artisan
 * commands.
 *
 * Auto-discovery is enabled via the "extra.laravel" section in composer.json,
 * so this provider and the ImageMan facade alias are loaded automatically in
 * Laravel applications without any manual registration.
 */
class ImageManServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge the package config with any published user config.
        $this->mergeConfigFrom(__DIR__ . '/../config/imageman.php', 'imageman');

        // Bind the ImageManipulator as a singleton — it is stateless, so sharing
        // a single instance is safe and avoids repeated object allocation.
        $this->app->singleton(ImageManipulator::class);

        // Bind the main ImageManager singleton under the 'imageman' key.
        // This is what the facade resolves.
        $this->app->singleton('imageman', function ($app) {
            return new ImageManager(
                config('imageman'),
                $app->make(ImageManipulator::class),
            );
        });

        // Bind the URL generator contract to a concrete implementation based
        // on the 'url_generator' config value.
        $this->app->bind(UrlGeneratorContract::class, function ($app) {
            $driver = config('imageman.url_generator', 'default');

            // Allow a fully-qualified class name as a custom driver.
            if (class_exists($driver)) {
                return $app->make($driver);
            }

            return match ($driver) {
                'imgix'      => $app->make(ImgixUrlGenerator::class),
                'cloudinary' => $app->make(CloudinaryUrlGenerator::class),
                'imagekit'   => $app->make(ImageKitUrlGenerator::class),
                'cloudflare' => $app->make(CloudflareUrlGenerator::class),
                default      => $app->make(DefaultUrlGenerator::class),
            };
        });
    }

    public function boot(): void
    {
        // --- Publishable assets ---

        // Config file.
        $this->publishes([
            __DIR__ . '/../config/imageman.php' => config_path('imageman.php'),
        ], 'imageman-config');

        // Database migration.
        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'imageman-migrations');

        // Blade view components.
        $this->publishes([
            __DIR__ . '/../resources/views/' => resource_path('views/vendor/imageman'),
        ], 'imageman-views');

        // Load migrations automatically (unless the user has published them).
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // --- Blade directives ---
        $this->registerBladeDirectives();

        // --- Routes (optional) ---
        if (config('imageman.register_routes', false)) {
            $this->loadRoutesFrom(__DIR__ . '/Http/routes.php');
        }

        // --- Views ---
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'imageman');

        // --- Artisan commands ---
        if ($this->app->runningInConsole()) {
            $this->commands([
                RegenerateVariantsCommand::class,
                CleanOrphanedImagesCommand::class,
                ConvertToWebpCommand::class,
            ]);
        }
    }

    /**
     * Register all Blade directives provided by this package.
     */
    private function registerBladeDirectives(): void
    {
        /**
         * @image($id, $variant, $attributes)
         *
         * Renders a simple <img> tag for the given image and variant.
         * Equivalent to: @include('imageman::components.image', [...])
         *
         * Usage: @image($post->image->id, 'medium', ['class' => 'rounded'])
         */
        Blade::directive('image', function (string $expression) {
            [$imageId, $variant, $attributes] = array_pad(
                array_map('trim', explode(',', $expression, 3)),
                3,
                '[]'
            );

            return "<?php echo view('imageman::components.image', ['imageId' => {$imageId}, 'variant' => {$variant}, 'attributes' => {$attributes}])->render(); ?>";
        });

        /**
         * @responsiveImage($id, $attributes)
         *
         * Renders a responsive <img> tag with srcset and sizes attributes.
         *
         * Usage: @responsiveImage($post->image->id, ['sizes' => '100vw'])
         */
        Blade::directive('responsiveImage', function (string $expression) {
            [$imageId, $attributes] = array_pad(
                array_map('trim', explode(',', $expression, 2)),
                2,
                '[]'
            );

            return "<?php echo view('imageman::components.responsive-image', ['imageId' => {$imageId}, 'attributes' => {$attributes}])->render(); ?>";
        });

        /**
         * @lazyImage($id, $variant, $attributes)
         *
         * Renders an <img> with the LQIP placeholder in src and the full image
         * URL in data-src, ready for use with a JavaScript lazy-loading library
         * such as lazysizes (https://github.com/aFarkas/lazysizes).
         *
         * Add 'lazyload' to the class attribute of the <img> to activate lazysizes.
         *
         * Usage: @lazyImage($post->image->id, 'large', ['class' => 'lazyload'])
         */
        Blade::directive('lazyImage', function (string $expression) {
            [$imageId, $variant, $attributes] = array_pad(
                array_map('trim', explode(',', $expression, 3)),
                3,
                '[]'
            );

            return <<<PHP
            <?php
            \$__imageman_img = is_int({$imageId})
                ? \\Vendor\\ImageMan\\Models\\Image::find({$imageId})
                : {$imageId};
            \$__imageman_variant   = {$variant} ?? 'large';
            \$__imageman_attrs     = {$attributes} ?? [];
            \$__imageman_lqip      = \$__imageman_img?->lqip() ?? '';
            \$__imageman_fullSrc   = \$__imageman_img?->url(\$__imageman_variant) ?? '';
            \$__imageman_class     = 'lazyload ' . (\$__imageman_attrs['class'] ?? '');
            \$__imageman_alt       = \$__imageman_attrs['alt'] ?? (\$__imageman_img?->meta['alt'] ?? '');

            if (\$__imageman_fullSrc):
            ?>
            <img
                src="<?= e(\$__imageman_lqip) ?>"
                data-src="<?= e(\$__imageman_fullSrc) ?>"
                class="<?= e(trim(\$__imageman_class)) ?>"
                alt="<?= e(\$__imageman_alt) ?>"
                loading="lazy"
            >
            <?php endif; ?>
            PHP;
        });
    }
}

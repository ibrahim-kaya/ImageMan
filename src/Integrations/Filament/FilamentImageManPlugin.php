<?php

namespace IbrahimKaya\ImageMan\Integrations\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Filament v3 plugin registration class for ImageMan.
 *
 * Register this plugin in your Filament panel provider to enable the
 * ImageManUpload form component and the ImageManColumn table column.
 *
 * Usage in PanelProvider::panel():
 *   $panel->plugin(FilamentImageManPlugin::make())
 *
 * @see https://filamentphp.com/docs/3.x/panels/plugins
 */
class FilamentImageManPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'imageman';
    }

    public function register(Panel $panel): void
    {
        // Additional panel-level registrations (e.g. assets, navigation items)
        // can be added here when needed.
    }

    public function boot(Panel $panel): void
    {
        // Boot-time setup for the plugin within the panel context.
    }
}

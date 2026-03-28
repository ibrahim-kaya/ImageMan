<?php

use Illuminate\Support\Facades\Route;
use IbrahimKaya\ImageMan\Http\Controllers\ImageController;

/*
|--------------------------------------------------------------------------
| ImageMan Routes
|--------------------------------------------------------------------------
|
| These routes are only registered when config('imageman.register_routes')
| is true (default: false). Enable them when you need to serve images from
| a private disk through your Laravel application.
|
| Routes are prefixed with 'imageman' by default. Change the prefix via
| config('imageman.route_prefix').
|
| Available routes:
|
|   GET /imageman/{id}/{variant?}       → Proxy-stream the image through PHP
|   GET /imageman/{id}/{variant?}/sign  → Redirect to a signed temporary URL
|
*/

$prefix     = config('imageman.route_prefix', 'imageman');
$middleware = config('imageman.route_middleware', []);

Route::prefix($prefix)
    ->middleware($middleware)
    ->name('imageman.')
    ->group(function () {
        // Proxy-stream the image content through PHP.
        Route::get('/{id}/{variant?}', [ImageController::class, 'show'])
            ->name('show')
            ->where('id', '[0-9]+');

        // Redirect to a signed temporary URL (private disks, e.g. S3).
        Route::get('/{id}/{variant?}/sign', [ImageController::class, 'signedRedirect'])
            ->name('sign')
            ->where('id', '[0-9]+');
    });

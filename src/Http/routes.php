<?php

use Illuminate\Support\Facades\Route;
use IbrahimKaya\ImageMan\Http\Controllers\ChunkUploadController;
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

// --- Chunked Upload Routes ---
// Registered separately so they can carry their own middleware stack defined
// in config('imageman.chunks.middleware'), independent of the image-proxy routes.
if (config('imageman.chunks.enabled', true)) {
    $chunkMiddleware = config('imageman.chunks.middleware', []);

    Route::prefix($prefix . '/chunks')
        ->middleware($chunkMiddleware)
        ->name('imageman.chunks.')
        ->group(function () {
            // Initiate a new chunked upload session.
            Route::post('/initiate', [ChunkUploadController::class, 'initiate'])
                ->name('initiate');

            // Upload a single chunk.
            Route::post('/{uploadId}', [ChunkUploadController::class, 'upload'])
                ->name('upload');

            // Poll the assembly status.
            Route::get('/{uploadId}/status', [ChunkUploadController::class, 'status'])
                ->name('status');

            // Abort and clean up the session.
            Route::delete('/{uploadId}', [ChunkUploadController::class, 'abort'])
                ->name('abort');
        });
}

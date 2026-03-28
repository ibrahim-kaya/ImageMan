<?php

namespace IbrahimKaya\ImageMan\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use IbrahimKaya\ImageMan\Models\Image;

/**
 * Serves images from private disks via signed URL or direct proxy.
 *
 * This controller is optional and only needed when you store images on a
 * private disk (not publicly accessible) and want to serve them through
 * your Laravel application. Public disks can be served directly by the
 * web server without going through PHP.
 *
 * Routes are registered in src/Http/routes.php and can be enabled by
 * calling ImageManServiceProvider::registerRoutes() or by loading the
 * routes file manually.
 *
 * Two delivery modes are available:
 *
 *   1. Proxy mode   — GET /imageman/{id}/{variant}
 *      PHP reads the file from the private disk and streams it to the
 *      browser. Works with any disk. Higher PHP CPU/memory usage.
 *
 *   2. Signed URL   — GET /imageman/{id}/{variant}/sign
 *      Generates a signed temporary URL and redirects the browser there.
 *      Only works with disks that support temporary URLs (S3, GCS, etc.).
 *      Much more efficient for large files.
 */
class ImageController extends Controller
{
    /**
     * Stream an image file directly through PHP (proxy mode).
     *
     * @param  Request $request
     * @param  int     $id       Image primary key.
     * @param  string  $variant  Variant name ('thumbnail', 'medium', 'default', …).
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\Response
     */
    public function show(Request $request, int $id, string $variant = 'default')
    {
        $image = Image::findOrFail($id);
        $path  = $image->path($variant);

        if ($path === null) {
            abort(404, 'Image variant not found.');
        }

        $disk = Storage::disk($image->disk);

        if (!$disk->exists($path)) {
            abort(404, 'Image file not found on disk.');
        }

        return $disk->response($path, null, [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Redirect to a signed temporary URL (for S3/GCS private buckets).
     *
     * @param  Request $request
     * @param  int     $id
     * @param  string  $variant
     * @return \Illuminate\Http\RedirectResponse
     */
    public function signedRedirect(Request $request, int $id, string $variant = 'default')
    {
        $image   = Image::findOrFail($id);
        $minutes = config('imageman.signed_url_ttl', 60);

        return redirect()->away($image->temporaryUrl($minutes, $variant));
    }
}

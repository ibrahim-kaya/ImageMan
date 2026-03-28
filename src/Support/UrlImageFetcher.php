<?php

namespace IbrahimKaya\ImageMan\Support;

use Illuminate\Http\UploadedFile;
use IbrahimKaya\ImageMan\Exceptions\UrlFetchException;

/**
 * Downloads an image from a remote URL and wraps it in an UploadedFile
 * so it can be fed into the standard ImageMan processing pipeline.
 *
 * Uses PHP's stream context (no Guzzle/HTTP client dependency required).
 * When Laravel's HTTP client (Guzzle) is available it is preferred for
 * better redirect handling and timeout control.
 */
class UrlImageFetcher
{
    /**
     * MIME types that are accepted as valid image responses.
     * Anything outside this list throws UrlFetchException::notAnImage().
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
        'image/bmp',
        'image/svg+xml',
        'image/tiff',
    ];

    /**
     * Download the image at the given URL and return an UploadedFile instance.
     *
     * @param  string $url             The remote image URL.
     * @param  int    $timeoutSeconds  Maximum seconds to wait for the response. Default: 30.
     * @return UploadedFile            A temporary UploadedFile backed by a temp file.
     *
     * @throws UrlFetchException  If the download fails or the response is not an image.
     */
    public function fetch(string $url, int $timeoutSeconds = 30): UploadedFile
    {
        // Prefer Laravel's HTTP client when Guzzle is available (better redirect support).
        if (class_exists(\Illuminate\Support\Facades\Http::class)) {
            return $this->fetchWithLaravelHttp($url, $timeoutSeconds);
        }

        return $this->fetchWithStreamContext($url, $timeoutSeconds);
    }

    // -----------------------------------------------------------------------
    // Laravel HTTP client path (Guzzle available)
    // -----------------------------------------------------------------------

    /**
     * Download using Laravel's HTTP facade (Guzzle-backed).
     *
     * @throws UrlFetchException
     */
    private function fetchWithLaravelHttp(string $url, int $timeout): UploadedFile
    {
        try {
            $response = \Illuminate\Support\Facades\Http::timeout($timeout)
                ->withHeaders(['User-Agent' => 'ImageMan/1.0'])
                ->get($url);
        } catch (\Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'timed out')) {
                throw UrlFetchException::timeout($url);
            }
            throw new UrlFetchException("Failed to fetch [{$url}]: {$e->getMessage()}", 0, $e);
        }

        if (!$response->successful()) {
            throw UrlFetchException::notReachable($url, $response->status());
        }

        $contentType = $this->normalizeContentType(
            $response->header('Content-Type') ?? ''
        );

        $this->assertImageContentType($url, $contentType);

        $body = $response->body();

        if (empty($body)) {
            throw UrlFetchException::emptyResponse($url);
        }

        return $this->writeToTempFile($url, $body, $contentType);
    }

    // -----------------------------------------------------------------------
    // Plain PHP stream context path (no Guzzle)
    // -----------------------------------------------------------------------

    /**
     * Download using PHP's file_get_contents() with a stream context.
     *
     * @throws UrlFetchException
     */
    private function fetchWithStreamContext(string $url, int $timeout): UploadedFile
    {
        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => $timeout,
                'follow_location' => 1,
                'max_redirects'   => 5,
                'header'          => "User-Agent: ImageMan/1.0\r\n",
                'ignore_errors'   => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        // $http_response_header is populated by file_get_contents().
        $headers    = $http_response_header ?? [];
        $statusCode = $this->parseStatusCode($headers);
        $contentType = $this->parseContentTypeHeader($headers);

        if ($body === false || $statusCode < 200 || $statusCode >= 300) {
            throw UrlFetchException::notReachable($url, $statusCode);
        }

        if (empty($body)) {
            throw UrlFetchException::emptyResponse($url);
        }

        // If the server didn't send a Content-Type, detect from binary content.
        if (empty($contentType)) {
            $contentType = $this->detectMimeFromContent($body);
        }

        $this->assertImageContentType($url, $contentType);

        return $this->writeToTempFile($url, $body, $contentType);
    }

    // -----------------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------------

    /**
     * Write binary content to a named temp file and wrap in UploadedFile.
     */
    private function writeToTempFile(string $url, string $body, string $mimeType): UploadedFile
    {
        $extension = $this->extensionForMime($mimeType);
        $tmpPath   = tempnam(sys_get_temp_dir(), 'imageman_url_') . '.' . $extension;

        file_put_contents($tmpPath, $body);

        // Derive a sensible client filename from the URL path.
        $originalName = $this->filenameFromUrl($url, $extension);

        return new UploadedFile(
            $tmpPath,
            $originalName,
            $mimeType,
            null,
            true, // test mode — skips is_uploaded_file() check
        );
    }

    /**
     * Assert that the given MIME type is an allowed image type.
     *
     * @throws UrlFetchException
     */
    private function assertImageContentType(string $url, string $contentType): void
    {
        $normalized = $this->normalizeContentType($contentType);

        if (!in_array($normalized, self::ALLOWED_MIME_TYPES, true)) {
            throw UrlFetchException::notAnImage($url, $contentType);
        }
    }

    /**
     * Extract just the MIME type from a Content-Type header value.
     * e.g. "image/jpeg; charset=utf-8" → "image/jpeg"
     */
    private function normalizeContentType(string $contentType): string
    {
        return strtolower(trim(explode(';', $contentType, 2)[0]));
    }

    /**
     * Parse the HTTP status code from the response headers array.
     * The first element is typically "HTTP/1.1 200 OK".
     */
    private function parseStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }

    /**
     * Find the Content-Type value in the response headers array.
     */
    private function parseContentTypeHeader(array $headers): string
    {
        foreach (array_reverse($headers) as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                return trim(substr($header, strlen('Content-Type:')));
            }
        }
        return '';
    }

    /**
     * Detect MIME type from binary content using PHP's finfo extension.
     */
    private function detectMimeFromContent(string $body): string
    {
        if (extension_loaded('fileinfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            return $finfo->buffer($body) ?: 'application/octet-stream';
        }

        return 'application/octet-stream';
    }

    /**
     * Derive a client filename from a URL. Uses the last path segment,
     * falling back to a UUID-based name if the URL has no useful path.
     */
    private function filenameFromUrl(string $url, string $fallbackExtension): string
    {
        $path     = parse_url($url, PHP_URL_PATH) ?? '';
        $basename = basename($path);

        // Strip query strings that may have leaked into the basename.
        $basename = preg_replace('/\?.*$/', '', $basename);

        if ($basename && str_contains($basename, '.')) {
            return $basename;
        }

        return 'remote-image.' . $fallbackExtension;
    }

    /**
     * Map a MIME type to a file extension.
     */
    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg'   => 'jpg',
            'image/png'    => 'png',
            'image/gif'    => 'gif',
            'image/webp'   => 'webp',
            'image/avif'   => 'avif',
            'image/bmp'    => 'bmp',
            'image/svg+xml'=> 'svg',
            'image/tiff'   => 'tiff',
            default        => 'jpg',
        };
    }
}

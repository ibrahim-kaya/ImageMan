<?php

namespace IbrahimKaya\ImageMan\Exceptions;

use RuntimeException;

/**
 * Thrown when ImageMan fails to download an image from a remote URL.
 *
 * Common causes:
 *   - The URL is unreachable or returns a non-200 HTTP status.
 *   - The response Content-Type is not an image MIME type.
 *   - The downloaded file size exceeds the configured max_size limit.
 *   - A network timeout occurs during the download.
 */
class UrlFetchException extends RuntimeException
{
    public static function notReachable(string $url, int $statusCode): self
    {
        return new self("Failed to fetch image from [{$url}]: HTTP {$statusCode}.");
    }

    public static function notAnImage(string $url, string $contentType): self
    {
        return new self(
            "The URL [{$url}] did not return an image. "
            . "Received Content-Type: [{$contentType}]."
        );
    }

    public static function emptyResponse(string $url): self
    {
        return new self("The URL [{$url}] returned an empty response body.");
    }

    public static function timeout(string $url): self
    {
        return new self("Request to [{$url}] timed out.");
    }
}

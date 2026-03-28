<?php

namespace IbrahimKaya\ImageMan\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON API resource for transforming an Image model into a structured
 * response payload suitable for REST APIs and SPA front-ends.
 *
 * Usage:
 *   return ImageResource::make($image);
 *   return ImageResource::collection($images);
 *
 * Example output:
 *   {
 *     "id": 1,
 *     "url": "https://…/images/abc.webp",
 *     "variants": {
 *       "thumbnail": "https://…/images/abc_thumbnail.webp",
 *       "medium":    "https://…/images/abc_medium.webp"
 *     },
 *     "lqip": "data:image/webp;base64,…",
 *     "srcset": "https://…/abc_thumbnail.webp 150w, …",
 *     "width": 1920,
 *     "height": 1080,
 *     "size": 204800,
 *     "mime_type": "image/webp",
 *     "original_filename": "photo.jpg",
 *     "collection": "gallery",
 *     "meta": { "alt": "Product photo" },
 *     "created_at": "2024-01-01T00:00:00.000000Z"
 *   }
 *
 * @mixin \IbrahimKaya\ImageMan\Models\Image
 */
class ImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Build the variant URL map.
        $variantUrls = [];
        foreach (array_keys($this->variants() ?: []) as $variantName) {
            $variantUrls[$variantName] = $this->url($variantName);
        }

        return [
            'id'                => $this->id,
            'url'               => $this->url(),
            'variants'          => $variantUrls,
            'lqip'              => $this->lqip(),
            'srcset'            => $this->srcset(),
            'width'             => $this->width,
            'height'            => $this->height,
            'size'              => $this->size,
            'mime_type'         => $this->mime_type,
            'original_filename' => $this->original_filename,
            'collection'        => $this->collection,
            'disk'              => $this->disk,
            'meta'              => $this->meta,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}

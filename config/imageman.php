<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Disk
    |--------------------------------------------------------------------------
    |
    | The default filesystem disk to use for storing uploaded images.
    | This should correspond to one of the disks defined in your
    | config/filesystems.php file (e.g., 'local', 's3', 'ftp', 'sftp').
    |
    | You can override this per-upload using the ->disk('s3') fluent method.
    |
    | Supported: any disk name registered in config/filesystems.php
    |
    */
    'disk' => env('IMAGEMAN_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Storage Path Prefix
    |--------------------------------------------------------------------------
    |
    | All uploaded images will be stored under this directory on the disk.
    | A UUID-based subdirectory will be created inside this path for each
    | upload to prevent filename collisions and keep the filesystem tidy.
    |
    | Example: 'images' → stored at {disk-root}/images/{uuid}/filename.webp
    |
    | You may also set this via the IMAGEMAN_PATH environment variable.
    |
    */
    'path' => env('IMAGEMAN_PATH', 'images'),

    /*
    |--------------------------------------------------------------------------
    | Output Format
    |--------------------------------------------------------------------------
    |
    | The format to which uploaded images will be converted before storage.
    | Converting to modern formats like WebP or AVIF significantly reduces
    | file size while maintaining visual quality, leading to faster page loads.
    |
    | 'webp'     → Convert all uploads to WebP. Recommended for most use cases.
    |              Approximately 25–34% smaller than equivalent JPEG.
    |              Supported by all modern browsers.
    | 'avif'     → Convert to AVIF (AV1 Image File Format). Next-generation format.
    |              Up to 50% smaller than JPEG at the same quality. Slower to encode.
    |              Browser support is widespread but not universal (check caniuse.com).
    | 'jpeg'     → Convert to JPEG. Widest compatibility, no transparency support.
    |              Good choice when browser support for modern formats is a concern.
    | 'original' → Keep the original format without any conversion.
    |              EXIF stripping and resizing still apply if configured.
    |
    */
    'format' => 'webp',

    /*
    |--------------------------------------------------------------------------
    | Encoding Quality
    |--------------------------------------------------------------------------
    |
    | Quality settings (1–100) for each output format. A higher value produces
    | a sharper, more faithful image at the cost of a larger file size.
    |
    | Recommended starting points:
    |   WebP:  80 — Good balance of quality and compression
    |   AVIF:  70 — AVIF is perceptually superior at lower values due to better
    |               compression algorithms; values above 80 rarely add visible benefit
    |   JPEG:  85 — Standard quality for high-fidelity web images
    |
    | Lower values (60–75) are acceptable for thumbnails where detail is less critical.
    |
    */
    'webp_quality'  => 80,
    'avif_quality'  => 70,
    'jpeg_quality'  => 85,

    /*
    |--------------------------------------------------------------------------
    | Keep Original File
    |--------------------------------------------------------------------------
    |
    | Whether to retain the original uploaded file alongside the converted
    | variants on disk. When set to false (default), only the converted file
    | and its size variants are stored, saving disk space.
    |
    | When set to true:
    |   - The original file is stored as '{uuid}_original.{ext}'
    |   - Accessible via $image->url('original') or $image->path('original')
    |   - Useful when you need the unmodified source for re-processing later
    |
    */
    'keep_original' => false,

    /*
    |--------------------------------------------------------------------------
    | EXIF Data Stripping
    |--------------------------------------------------------------------------
    |
    | Uploaded images often contain EXIF metadata embedded by cameras and phones:
    |   - GPS coordinates (exact location where the photo was taken)
    |   - Device make, model and serial number
    |   - Shooting date and time
    |   - Camera settings (aperture, shutter speed, ISO)
    |
    | Enabling this option strips all EXIF data before the image is stored,
    | protecting the privacy of your users. This is strongly recommended for
    | any public-facing upload feature.
    |
    | Note: Stripping EXIF data may slightly alter the file size and can
    | remove auto-rotation hints. The Intervention Image library applies
    | auto-rotation based on the EXIF orientation before stripping.
    |
    */
    'strip_exif' => true,

    /*
    |--------------------------------------------------------------------------
    | Low-Quality Image Placeholder (LQIP)
    |--------------------------------------------------------------------------
    |
    | Generates a tiny, blurred version of the image encoded as a base64
    | data URI. This technique is used as a visual placeholder shown while
    | the full-resolution image is still loading, enabling smooth lazy-loading
    | transitions (e.g. the progressive blur-to-sharp effect seen on Medium).
    |
    | 'generate_lqip' → Enable or disable LQIP generation for all uploads.
    |                   Can be toggled per-upload with ->withLqip() / ->withoutLqip().
    |
    | 'lqip_size'     → The width in pixels of the generated placeholder.
    |                   Height is calculated automatically to maintain aspect ratio.
    |                   Smaller = tinier base64 string = faster HTML payload.
    |                   Larger = more recognisable shape before full image loads.
    |                   Default: 20px (roughly 300–500 bytes as base64)
    |
    | Usage in Blade:
    |   <img src="{{ $image->lqip() }}" data-src="{{ $image->url('large') }}" class="lazyload">
    |
    | Or use the @lazyImage directive which handles this automatically.
    |
    */
    'generate_lqip' => true,
    'lqip_size'     => 20,

    /*
    |--------------------------------------------------------------------------
    | Duplicate Image Detection
    |--------------------------------------------------------------------------
    |
    | When enabled, ImageMan computes a SHA-256 hash of each uploaded file's
    | binary content and checks whether an identical image already exists in
    | the imageman_images table. This prevents storing the same file multiple
    | times, saving disk space and database rows.
    |
    | 'detect_duplicates' → Master switch for the duplicate detection feature.
    |
    | 'on_duplicate' → The action to take when a duplicate hash is found:
    |
    |   'reuse'  → Return the existing Image model without re-processing or
    |              storing anything. The most storage-efficient option.
    |              The existing record's model association is NOT changed.
    |
    |   'throw'  → Throw a \IbrahimKaya\ImageMan\Exceptions\DuplicateImageException.
    |              Use this when duplicate uploads should be explicitly prevented,
    |              such as for product images or document attachments.
    |
    |   'allow'  → Ignore the duplicate hash entirely and create a new record
    |              with its own disk copy. Use this when the same file legitimately
    |              belongs to different models or collections.
    |
    */
    'detect_duplicates' => true,
    'on_duplicate'      => 'reuse',

    /*
    |--------------------------------------------------------------------------
    | Size Presets
    |--------------------------------------------------------------------------
    |
    | Define named size variants that will be generated from each uploaded image.
    | Each preset specifies target dimensions and how the image should be fitted
    | into those dimensions.
    |
    | Keys    → Preset names used to access variants: $image->url('hero')
    | 'width' → Target width in pixels
    | 'height'→ Target height in pixels
    | 'fit'   → How to fill the target bounding box. Options:
    |
    |   'cover'   → Scale and crop to fill the exact width × height dimensions.
    |               Parts of the image may be cropped. No empty space.
    |               Best for: thumbnails, profile avatars, card images.
    |
    |   'contain' → Scale to fit entirely within width × height without cropping.
    |               May leave empty (transparent or white) space on two sides.
    |               Best for: product images where the whole subject must be visible.
    |
    |   'fill'    → Stretch or squish to exactly fill width × height.
    |               Will distort the image if the aspect ratio differs.
    |               Rarely recommended; use with caution.
    |
    |   'stretch' → Alias for 'fill'.
    |
    | You can add as many custom presets as your application requires.
    | Presets not listed in 'default_sizes' will only be generated when
    | explicitly requested via ->sizes(['hero', 'banner']).
    |
    */
    'sizes' => [
        'thumbnail' => ['width' => 150,  'height' => 150,  'fit' => 'cover'],
        'medium'    => ['width' => 800,  'height' => 600,  'fit' => 'contain'],
        'large'     => ['width' => 1920, 'height' => 1080, 'fit' => 'contain'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Singleton Collections
    |--------------------------------------------------------------------------
    |
    | Collection names listed here are treated as "singleton" collections:
    | when a new image is uploaded to one of these collections on a model,
    | all previously stored images in that collection for the same model
    | are automatically deleted AFTER the new image has been successfully saved.
    |
    | This is ideal for profile pictures, cover photos, avatars, or any
    | scenario where a model should have exactly one image per collection.
    |
    | Deletion is always performed AFTER the new image is confirmed safe on
    | disk and in the database, so a processing failure mid-pipeline will
    | never leave the model without an image.
    |
    | Examples:
    |   'singleton_collections' => ['avatar', 'profile_pic', 'cover']
    |
    | You can also control this per-upload via the fluent API:
    |   ->replaceExisting()   Force singleton behaviour for one upload.
    |   ->keepExisting()      Override a singleton collection and keep all images.
    |
    */
    'singleton_collections' => [],

    /*
    |--------------------------------------------------------------------------
    | Default Sizes to Generate
    |--------------------------------------------------------------------------
    |
    | The size presets from the 'sizes' array above that will be generated
    | automatically for every upload, unless the upload explicitly overrides
    | them using the ->sizes([...]) fluent method on ImageUploader.
    |
    | Examples:
    |   ['thumbnail', 'medium']       → Generate only these two by default
    |   ['thumbnail', 'medium', 'large'] → Generate all three
    |   []                            → Generate no variants; only the main file
    |
    | Generating fewer sizes by default improves upload performance. You can
    | always request additional sizes per-upload as needed.
    |
    */
    'default_sizes' => ['thumbnail', 'medium'],

    /*
    |--------------------------------------------------------------------------
    | Watermark
    |--------------------------------------------------------------------------
    |
    | Optionally overlay a watermark on uploaded images before saving to disk.
    | Watermarks can be an image file (e.g. a logo) or a text string.
    | The watermark is applied to all generated variants including the main file.
    |
    | 'enabled'  → Master on/off switch. When false, no watermark is applied
    |              globally. Can be overridden per-upload: ->watermark() or ->noWatermark()
    |
    | 'type'     → 'image' to use a logo/PNG file, or 'text' to render a string.
    |
    | 'path'     → Absolute filesystem path to the watermark image file.
    |              Only used when type = 'image'. The file must be PNG or WebP
    |              with transparency for best results.
    |              Example: storage_path('app/watermark.png')
    |
    | 'text'     → The string to render as a watermark overlay.
    |              Only used when type = 'text'.
    |              Example: '© 2024 My Company'
    |
    | 'position' → Where on the image to place the watermark. Options:
    |              'top-left' | 'top-center' | 'top-right'
    |              'center-left' | 'center' | 'center-right'
    |              'bottom-left' | 'bottom-center' | 'bottom-right'
    |
    | 'opacity'  → Transparency of the watermark. 0 = fully transparent (invisible),
    |              100 = fully opaque (solid). Default: 50 (semi-transparent).
    |
    | 'padding'  → Distance in pixels between the watermark and the image edge
    |              or corner. Only applies to non-center positions. Default: 10.
    |
    */
    'watermark' => [
        'enabled'  => false,
        'type'     => 'image',
        'path'     => null,
        'text'     => null,
        'position' => 'bottom-right',
        'opacity'  => 50,
        'padding'  => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload Validation Rules
    |--------------------------------------------------------------------------
    |
    | Default validation constraints applied to every upload before processing
    | begins. Validation failures throw \IbrahimKaya\ImageMan\Exceptions\ValidationException.
    |
    | All of these can be overridden per-upload using the fluent builder:
    |   ->maxSize(2048)->minWidth(400)->aspectRatio('16/9')
    |
    | 'max_size'      → Maximum allowed file size in kilobytes (KB).
    |                   Default: 10240 KB = 10 MB.
    |                   Set to null to allow any size (not recommended).
    |
    | 'min_width'     → Minimum required image width in pixels.
    |                   Useful for ensuring uploads meet a minimum resolution.
    |                   null = no minimum width enforced.
    |
    | 'max_width'     → Maximum allowed image width in pixels.
    |                   Useful for rejecting oversized source images before
    |                   processing begins. null = no maximum width enforced.
    |
    | 'min_height'    → Minimum required image height in pixels.
    |                   null = no minimum height enforced.
    |
    | 'max_height'    → Maximum allowed image height in pixels.
    |                   null = no maximum height enforced.
    |
    | 'aspect_ratio'  → Enforce a specific aspect ratio. Specified as a fraction
    |                   string. Examples: '16/9', '1/1', '4/3', '3/2'.
    |                   A ±5% tolerance is applied to account for rounding in
    |                   camera-produced images. null = any aspect ratio accepted.
    |
    | 'allowed_mimes' → Array of accepted MIME types. This is checked against the
    |                   actual file content (not just the extension) to prevent
    |                   malicious file uploads disguised as images.
    |
    */
    'validation' => [
        'max_size'      => 10240,
        'min_width'     => null,
        'max_width'     => null,
        'min_height'    => null,
        'max_height'    => null,
        'aspect_ratio'  => null,
        'allowed_mimes' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/avif',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Processing
    |--------------------------------------------------------------------------
    |
    | When enabled, the image processing pipeline (format conversion, resizing,
    | watermarking, LQIP generation) is dispatched as a background queue job
    | instead of executing synchronously during the HTTP request.
    |
    | This is strongly recommended for production when:
    |   - Users upload large or high-resolution images
    |   - Multiple size variants need to be generated
    |   - AVIF encoding is used (computationally expensive)
    |
    | 'queue'            → Master switch. When false, processing is synchronous.
    |
    | 'queue_connection' → The queue driver/connection to use, as defined in
    |                      config/queue.php. Common values: 'redis', 'sqs', 'database'.
    |                      Falls back to the default QUEUE_CONNECTION env variable.
    |
    | 'queue_name'       → The named queue/channel to push image jobs onto.
    |                      Using a dedicated queue ('images') allows you to run
    |                      a separate worker and prioritise or scale it independently.
    |
    | Important: When queue = true, the Image model is inserted into the database
    | immediately (so you get an ID back), but variants will not be available until
    | the job completes. Use $image->lqip() as a placeholder in the interim, or
    | listen for the ImageProcessed event to know when variants are ready.
    |
    */
    'queue'            => false,
    'queue_connection' => env('QUEUE_CONNECTION', 'sync'),
    'queue_name'       => 'images',

    /*
    |--------------------------------------------------------------------------
    | URL Generator
    |--------------------------------------------------------------------------
    |
    | Determines how public-facing URLs are constructed for stored images.
    | By default, Laravel's Storage::url() is used. Switch to a CDN-specific
    | generator to serve images through a CDN with on-the-fly transformations.
    |
    | 'default'    → Uses Laravel's Storage facade (Storage::url() for public
    |                disks, Storage::temporaryUrl() for private disks).
    |                No CDN required. Works out of the box.
    |
    | 'imgix'      → Constructs Imgix transformation URLs. Requires the 'imgix'
    |                config block below to be filled in. Imgix applies resizing
    |                and format conversion on their CDN, so local variants may
    |                not need to be generated.
    |
    | 'cloudinary' → Constructs Cloudinary delivery URLs with transformation
    |                parameters. Requires the 'cloudinary' config block.
    |
    | 'imagekit'   → Constructs ImageKit delivery URLs. Requires 'imagekit' config.
    |
    | 'cloudflare' → Constructs Cloudflare Images delivery URLs. Requires
    |                the 'cloudflare' config block.
    |
    | Custom       → Pass a fully-qualified class name (FQCN) of any class
    |                that implements \IbrahimKaya\ImageMan\Drivers\Contracts\UrlGeneratorContract.
    |                This allows you to integrate any CDN or image proxy.
    |
    */
    'url_generator' => 'default',

    /*
    |--------------------------------------------------------------------------
    | CDN Provider Credentials
    |--------------------------------------------------------------------------
    |
    | Authentication credentials and configuration for each supported CDN.
    | Only the block corresponding to the active 'url_generator' above is used.
    |
    | Always store sensitive values (API keys, secrets) in your .env file.
    | Never commit credentials to version control.
    |
    */

    // Imgix — https://www.imgix.com
    // Required: domain (your Imgix source domain, e.g. 'mysite.imgix.net')
    // Optional: sign_key (enables URL signing for private sources)
    'imgix' => [
        'domain'   => env('IMGIX_DOMAIN'),
        'sign_key' => env('IMGIX_SIGN_KEY'),
    ],

    // Cloudinary — https://cloudinary.com
    // Required: cloud_name, api_key, api_secret
    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key'    => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
    ],

    // ImageKit — https://imagekit.io
    // Required: url_endpoint (your ImageKit URL endpoint), public_key, private_key
    'imagekit' => [
        'url_endpoint' => env('IMAGEKIT_URL_ENDPOINT'),
        'public_key'   => env('IMAGEKIT_PUBLIC_KEY'),
        'private_key'  => env('IMAGEKIT_PRIVATE_KEY'),
    ],

    // Cloudflare Images — https://developers.cloudflare.com/images
    // Required: account_id, api_token
    'cloudflare' => [
        'account_id' => env('CF_IMAGES_ACCOUNT_ID'),
        'api_token'  => env('CF_IMAGES_API_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback URL
    |--------------------------------------------------------------------------
    |
    | The URL to return from $image->url() when a requested variant or the
    | main image file cannot be found on the configured disk.
    |
    | This prevents <img> tags from displaying broken image icons when a file
    | has been deleted externally or the disk is temporarily unavailable.
    |
    | Set to null to return null when the file is not found (you handle it).
    | Set to a placeholder URL such as a default avatar or a "no image" graphic.
    |
    | Example: 'https://via.placeholder.com/800x600?text=No+Image'
    |
    */
    'fallback_url' => null,

    /*
    |--------------------------------------------------------------------------
    | Temporary (Signed) URL TTL
    |--------------------------------------------------------------------------
    |
    | The default duration in minutes for which a signed temporary URL remains
    | valid. Temporary URLs are used for images stored on private disks (such as
    | a private S3 bucket) where direct public access is not allowed.
    |
    | This default is used when calling $image->temporaryUrl() without an
    | explicit duration argument. Pass a custom duration like ->temporaryUrl(30)
    | to override for individual images.
    |
    | Only applies to disks that support temporary URLs (S3, GCS, Azure Blob).
    | Calling temporaryUrl() on a local disk will throw an exception.
    |
    */
    'signed_url_ttl' => 60,

    /*
    |--------------------------------------------------------------------------
    | Srcset Size Order
    |--------------------------------------------------------------------------
    |
    | The ordered list of size preset names to include when generating an HTML
    | srcset attribute string. Used by $image->srcset() and the @responsiveImage
    | Blade directive.
    |
    | Presets are listed in ascending order of width so that browsers can
    | correctly select the most appropriate variant for the current viewport.
    |
    | Only sizes that have actually been generated for a given image will appear
    | in the srcset output. Missing variants are silently omitted.
    |
    | Example output:
    |   "/storage/images/abc_thumb.webp 150w, /storage/images/abc_med.webp 800w"
    |
    */
    'srcset_sizes' => ['thumbnail', 'medium', 'large'],

    /*
    |--------------------------------------------------------------------------
    | Chunked Upload
    |--------------------------------------------------------------------------
    |
    | ImageMan supports resumable, browser-side chunked uploads via a built-in
    | HTTP API. This allows files larger than PHP's upload_max_filesize limit
    | to be uploaded reliably without changing server configuration.
    |
    | The browser splits the file into fixed-size chunks and POSTs them one by
    | one (or in parallel). The server reassembles them and feeds the result
    | through the standard ImageUploader pipeline (WebP/AVIF conversion, size
    | variants, watermarking, LQIP, queue, etc.).
    |
    | A vanilla JS helper class (ImageManUploader) is included and can be
    | published with: php artisan vendor:publish --tag=imageman-js
    |
    | 'enabled'           → Master switch. Set to false to disable chunk endpoints.
    |
    | 'chunk_size'        → Recommended chunk size returned to the client at
    |                       session initiation, in bytes. The client is free to
    |                       use a different size; this is only a hint.
    |                       Default: 2 MB. Env: IMAGEMAN_CHUNK_SIZE.
    |
    | 'max_chunk_size'    → Hard upper limit enforced server-side per individual
    |                       chunk. Requests exceeding this are rejected with 422.
    |                       Default: 5 MB.
    |
    | 'max_chunks'        → Maximum number of chunks per upload session.
    |                       Default: 500 (500 × 2 MB = ~1 GB).
    |
    | 'max_total_size'    → Maximum assembled file size in bytes. The client
    |                       reports the full file size at initiation; requests
    |                       exceeding this limit are rejected with 422.
    |                       Default: 512 MB.
    |
    | 'session_ttl'       → Inactivity threshold in seconds after which a session
    |                       is considered stale and eligible for cleanup by the
    |                       imageman:clean-chunks Artisan command.
    |                       Default: 86400 (24 hours).
    |
    | 'middleware'        → Middleware applied exclusively to the chunk upload
    |                       routes. Useful for adding auth guards (e.g. 'auth:api')
    |                       without affecting the image-proxy routes.
    |                       Default: [] (no extra middleware).
    |
    | 'assemble_on_queue' → When true, chunk assembly (merging .part files and
    |                       running the ImageUploader pipeline) is dispatched as
    |                       a queued job (AssembleChunksJob) rather than running
    |                       synchronously in the upload request.
    |                       null = inherit the global 'queue' config value.
    |                       Default: null.
    |
    */
    'chunks' => [
        'enabled'           => true,
        'chunk_size'        => env('IMAGEMAN_CHUNK_SIZE', 2 * 1024 * 1024),
        'max_chunk_size'    => 5 * 1024 * 1024,
        'max_chunks'        => 500,
        'max_total_size'    => 512 * 1024 * 1024,
        'session_ttl'       => 86400,
        'middleware'        => [],
        'assemble_on_queue' => null,
    ],

];

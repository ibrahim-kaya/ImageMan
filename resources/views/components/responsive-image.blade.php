{{--
    @responsiveImage($imageId, $attributes)

    Renders a responsive <img> tag with a srcset attribute generated from
    all available variants, plus an optional LQIP blur placeholder for
    lazy-loading transitions.

    Parameters:
      $imageId   — int|Image   The image primary key or Image model instance.
      $attributes— array       Extra HTML attributes. Supports:
                               'sizes'   → HTML sizes attribute (e.g. '(max-width: 768px) 100vw, 50vw')
                               'class'   → CSS class string
                               'alt'     → Alt text (defaults to image meta alt)
                               'loading' → 'lazy' (default) | 'eager'

    Example:
      @responsiveImage($post->image->id, [
          'sizes'  => '(max-width: 768px) 100vw, 800px',
          'class'  => 'w-full rounded-lg',
      ])

    Output:
      <img
          src="https://…/abc_medium.webp"
          srcset="https://…/abc_thumbnail.webp 150w, https://…/abc_medium.webp 800w, …"
          sizes="(max-width: 768px) 100vw, 800px"
          width="800"
          height="600"
          class="w-full rounded-lg"
          loading="lazy"
          alt="…"
      >
--}}
@php
    use IbrahimKaya\ImageMan\Models\Image;

    /** @var Image|null $img */
    $img        = ($imageId instanceof Image) ? $imageId : Image::find($imageId);
    $attributes = $attributes ?? [];

    $src    = $img ? $img->url('medium') : config('imageman.fallback_url');
    $srcset = $img ? $img->srcset() : '';
    $alt    = $attributes['alt'] ?? ($img?->meta['alt'] ?? '');

    // Determine default display dimensions (medium variant or original).
    $width  = null;
    $height = null;

    if ($img) {
        $mediumData = $img->variants()['medium'] ?? null;
        $width  = $mediumData['width']  ?? $img->width  ?? null;
        $height = $mediumData['height'] ?? $img->height ?? null;
    }

    $htmlAttrs = array_filter([
        'src'     => $src,
        'srcset'  => $srcset ?: null,
        'sizes'   => $attributes['sizes'] ?? null,
        'width'   => $width,
        'height'  => $height,
        'class'   => $attributes['class'] ?? null,
        'loading' => $attributes['loading'] ?? 'lazy',
        'alt'     => $alt,
    ], fn ($v) => $v !== null && $v !== '');
@endphp

@if ($src)
    <img
        @foreach ($htmlAttrs as $attr => $value)
            {{ $attr }}="{{ e($value) }}"
        @endforeach
    >
@endif

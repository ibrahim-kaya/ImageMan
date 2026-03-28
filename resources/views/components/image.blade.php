{{--
    @image($imageId, $variant, $attributes)

    Renders a single <img> tag for the given image ID and variant.

    Parameters:
      $imageId   — int|Image   The image primary key or Image model instance.
      $variant   — string      The size variant name (default: 'medium').
      $attributes— array       Extra HTML attributes merged onto the <img> tag.

    Example:
      @image($post->image->id, 'medium', ['class' => 'rounded-xl', 'alt' => 'Post photo'])

    Output:
      <img src="https://…/abc.webp" width="800" height="600" class="rounded-xl" alt="Post photo" loading="lazy">
--}}
@php
    use IbrahimKaya\ImageMan\Models\Image;

    /** @var Image|null $img */
    $img = ($imageId instanceof Image) ? $imageId : Image::find($imageId);

    $variant    = $variant ?? 'medium';
    $attributes = $attributes ?? [];
    $src        = $img ? $img->url($variant) : config('imageman.fallback_url');
    $width      = null;
    $height     = null;

    if ($img) {
        $variantData = $img->variants()[$variant] ?? null;
        $width  = $variantData['width']  ?? $img->width  ?? null;
        $height = $variantData['height'] ?? $img->height ?? null;
    }

    $defaultAttrs = [
        'src'     => $src,
        'width'   => $width,
        'height'  => $height,
        'loading' => 'lazy',
        'alt'     => $img?->meta['alt'] ?? '',
    ];

    $mergedAttrs = array_filter(array_merge($defaultAttrs, $attributes), fn ($v) => $v !== null);
@endphp

@if ($src)
    <img
        @foreach ($mergedAttrs as $attr => $value)
            {{ $attr }}="{{ e($value) }}"
        @endforeach
    >
@endif

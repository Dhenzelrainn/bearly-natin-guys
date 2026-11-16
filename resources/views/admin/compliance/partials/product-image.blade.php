@php
    $preview = $preview ?? false;
    $hasImage = filled($imageUrl);
@endphp

<span @class(['admin-compliance-product-image', 'is-preview' => $preview])>
    @if ($hasImage)
        <img
            src="{{ $imageUrl }}"
            alt="{{ $imageAlt }}"
            width="{{ $preview ? 196 : 44 }}"
            height="{{ $preview ? 196 : 44 }}"
            loading="lazy"
            decoding="async"
            onerror="this.hidden=true;this.nextElementSibling.hidden=false;"
        >
    @endif
    <span class="admin-compliance-image-unavailable" role="img" aria-label="Product image unavailable" @if ($hasImage) hidden @endif>
        <i data-lucide="image-off" aria-hidden="true"></i>
        @if ($preview)
            <span>Image unavailable</span>
        @endif
    </span>
</span>

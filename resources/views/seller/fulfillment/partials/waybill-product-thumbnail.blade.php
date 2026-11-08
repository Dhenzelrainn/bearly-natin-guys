<span class="waybill-product-thumbnail">
    @if (filled($product['image_url']))
        <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" loading="lazy" decoding="async">
    @else
        <i data-lucide="shopping-bag" aria-hidden="true"></i>
        <span class="sr-only">{{ $product['name'] }}</span>
    @endif
</span>

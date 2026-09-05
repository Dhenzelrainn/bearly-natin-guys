<article
    class="bp-product-card"
    data-bp-product
    data-id="{{ $product['id'] }}"
    data-name="{{ strtolower($product['name']) }}"
    data-price="{{ $product['price'] }}"
    data-condition="{{ $product['condition_slug'] }}"
    data-subcategory="{{ $product['subcategory_slug'] }}"
    data-sizes="{{ implode('|', $product['sizes']) }}"
    data-colors="{{ implode('|', $product['colors']) }}"
    data-free-shipping="{{ $product['free_shipping'] ? '1' : '0' }}"
>
    <div class="bp-product-image">
        <img src="{{ $product['image'] }}" alt="{{ $product['name'] }}" loading="lazy">
    </div>

    <div class="bp-product-info">
        <h3>{{ $product['name'] }}</h3>
        <p>{{ $product['condition'] }}</p>
        <strong>₱{{ number_format($product['price']) }}</strong>

        <button
            class="bp-view-product"
            type="button"
            data-bp-preview="{{ $product['name'] }}"
        >
            View product
            <span aria-hidden="true">→</span>
        </button>
    </div>
</article>

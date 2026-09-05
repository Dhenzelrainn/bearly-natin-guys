<section class="bp-product-grid" id="bp-product-grid" aria-label="Men's Apparel products">
    @foreach($mensProducts as $product)
        @include('buyer.Products.components.product-card', ['product' => $product])
    @endforeach
</section>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $product->name }} | Bearly</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
    @include('buyer.partials.account-context-script')
    @vite(['resources/css/buyer.css', 'resources/js/buyer.js'])
    <style>
        .catalog-detail-page { min-height: 100vh; background: #f7f3ed; }
        .catalog-detail-shell { width: min(1180px, calc(100% - 32px)); margin: 0 auto; padding: 34px 0 72px; }
        .catalog-breadcrumb { display:flex; gap:8px; align-items:center; margin-bottom:24px; color:#75695d; font-size:13px; }
        .catalog-breadcrumb a { color:#5e3b28; font-weight:700; text-decoration:none; }
        .detail-card { display:grid; grid-template-columns:minmax(0, .95fr) minmax(0, 1.05fr); gap:42px; padding:32px; border:1px solid #e4d8ca; border-radius:14px; background:#fffdfa; box-shadow:0 8px 24px rgba(74,48,30,.06); }
        .detail-media { min-width:0; }
        .detail-primary-image { aspect-ratio:1 / 1; display:grid; place-items:center; overflow:hidden; border-radius:12px; background:#eee5da; }
        .detail-primary-image img { width:100%; height:100%; object-fit:cover; }
        .detail-primary-image.is-empty::before { content:'No product image'; color:#806f60; font-weight:600; }
        .detail-gallery { display:flex; gap:10px; margin-top:12px; flex-wrap:wrap; }
        .detail-gallery img { width:68px; height:68px; object-fit:cover; border:1px solid #decfbe; border-radius:10px; }
        .detail-kicker { margin:0 0 8px; color:#9a6d2f; font-size:12px; font-weight:800; letter-spacing:.12em; text-transform:uppercase; }
        .detail-info h1 { margin:0; color:#39251b; font-size:clamp(28px,4vw,46px); line-height:1.1; }
        .detail-price { display:block; margin:18px 0 8px; color:#5b351f; font-size:32px; font-weight:800; }
        .detail-description { color:#6c6055; line-height:1.75; white-space:pre-line; }
        .detail-seller { display:flex; align-items:center; gap:12px; margin:24px 0; padding:14px; border:1px solid #eadfd3; border-radius:10px; background:#fbf7f1; }
        .detail-seller-avatar { display:grid; place-items:center; width:42px; height:42px; border-radius:50%; background:#5b3827; color:#fff; font-weight:800; }
        .detail-seller small { display:block; color:#88776a; }
        .detail-seller a { color:#5a3522; font-weight:800; text-decoration:none; }
        .detail-options { display:grid; gap:10px; margin:20px 0; }
        .detail-options label { color:#57483e; font-size:13px; font-weight:700; }
        .detail-options select { width:100%; margin-top:5px; padding:12px 14px; border:1px solid #d8c8b8; border-radius:10px; background:#fff; color:#39251b; font:inherit; }
        .detail-stock { color:#75695d; font-size:13px; }
        .detail-actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:20px; }
        .detail-actions button, .detail-actions a { display:inline-flex; align-items:center; justify-content:center; min-height:46px; padding:0 20px; border-radius:10px; font:700 14px Poppins,sans-serif; text-decoration:none; cursor:pointer; }
        .detail-actions button { border:0; background:#5b3827; color:#fff; }
        .detail-actions button:disabled { opacity:.5; cursor:not-allowed; }
        .detail-actions a { border:1px solid #cdbba9; color:#5b3827; background:#fff; }
        .detail-toast { min-height:22px; margin-top:12px; color:#597347; font-size:13px; font-weight:700; }
        .related-section { margin-top:48px; }
        .related-section h2 { color:#39251b; }
        .related-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; }
        .related-card { overflow:hidden; border:1px solid #e4d8ca; border-radius:10px; background:#fffdfa; text-decoration:none; color:inherit; }
        .related-card img, .related-card .related-image { display:block; width:100%; aspect-ratio:1 / 1; object-fit:cover; background:#eee5da; }
        .related-copy { padding:14px; }
        .related-copy strong { display:block; color:#39251b; line-height:1.35; }
        .related-copy span { display:block; margin-top:7px; color:#6f4a2e; font-weight:800; }
        @media (max-width: 800px) { .detail-card { grid-template-columns:1fr; gap:26px; padding:20px; } .related-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
        @media (max-width: 520px) { .catalog-detail-shell { width:min(100% - 20px, 1180px); padding-top:20px; } .detail-card { border-radius:12px; padding:14px; } .related-grid { gap:10px; } .related-copy { padding:10px; font-size:13px; } }
    </style>
</head>
<body class="bh catalog-detail-page">
    @include('buyer.partials.buyer-header', [
        'headerSearchInputId' => 'product-search-input',
        'headerSearchAction' => route('products.index'),
    ])

    <main class="catalog-detail-shell">
        <nav class="catalog-breadcrumb" aria-label="Breadcrumb">
            <a href="{{ route('home') }}">Home</a><span aria-hidden="true">/</span>
            <a href="{{ route('products.index', ['category' => $productCard['category_slug']]) }}">{{ $product->category?->name }}</a><span aria-hidden="true">/</span>
            <span>{{ $product->name }}</span>
        </nav>

        <article class="detail-card" data-product-detail>
            <section class="detail-media" aria-label="Product images">
                <div class="detail-primary-image {{ $productCard['image'] ? '' : 'is-empty' }}">
                    @if ($productCard['image'])
                        <img src="{{ $productCard['image'] }}" alt="{{ $product->name }}">
                    @endif
                </div>
                @if (count($productCard['gallery']))
                    <div class="detail-gallery">
                        @foreach ($productCard['gallery'] as $image)
                            <img src="{{ $image }}" alt="{{ $product->name }} gallery image">
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="detail-info">
                <p class="detail-kicker">{{ $product->category?->name }}</p>
                <h1>{{ $product->name }}</h1>
                <strong class="detail-price" data-detail-price>₱{{ number_format($productCard['price'], 2) }}</strong>
                <p class="detail-description">{{ $product->description ?: 'This seller has not added a product description yet.' }}</p>

                <div class="detail-seller">
                    <span class="detail-seller-avatar" aria-hidden="true">{{ strtoupper(substr($product->store?->name ?: 'B', 0, 1)) }}</span>
                    <div><small>Sold by</small><a href="{{ route('stores.show', $product->store->slug) }}">{{ $product->store->name }}</a></div>
                </div>

                @if (count($productCard['variants']) > 1)
                    <div class="detail-options">
                        <label for="detail-variant">Choose variation</label>
                        <select id="detail-variant" data-detail-variant>
                            @foreach ($productCard['variants'] as $variant)
                                <option value="{{ $variant['id'] }}" data-price="{{ $variant['price'] }}" data-stock="{{ $variant['stock'] }}">{{ $variant['name'] }} · ₱{{ number_format($variant['price'], 2) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <p class="detail-stock" data-detail-stock>{{ $productCard['stock'] }} piece(s) available</p>
                <div class="detail-actions">
                    <button type="button" data-detail-add>Add to cart</button>
                    <a href="{{ route('stores.show', $product->store->slug) }}">View store</a>
                </div>
                <div class="detail-toast" role="status" aria-live="polite" data-detail-toast></div>
            </section>
        </article>

        @if (count($relatedProducts))
            <section class="related-section" aria-labelledby="related-title">
                <h2 id="related-title">More from this category</h2>
                <div class="related-grid">
                    @foreach ($relatedProducts as $related)
                        <a class="related-card" href="{{ route('products.show', $related['id']) }}">
                            @if ($related['image'])<img src="{{ $related['image'] }}" alt="{{ $related['name'] }}">@else<div class="related-image"></div>@endif
                            <div class="related-copy"><strong>{{ $related['name'] }}</strong><span>₱{{ number_format($related['price'], 2) }}</span></div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </main>

    <script>
        (() => {
            const variants = @json($productCard['variants']);
            const select = document.querySelector('[data-detail-variant]');
            const price = document.querySelector('[data-detail-price]');
            const stock = document.querySelector('[data-detail-stock]');
            const button = document.querySelector('[data-detail-add]');
            const toast = document.querySelector('[data-detail-toast]');
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const current = () => variants.find(item => Number(item.id) === Number(select?.value)) || variants[0];
            const render = () => { const item = current(); if (!item) return; price.textContent = `₱${Number(item.price).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`; stock.textContent = `${item.stock} piece(s) available`; button.disabled = Number(item.stock) < 1; };
            select?.addEventListener('change', render);
            button?.addEventListener('click', async () => { const item = current(); if (!item || Number(item.stock) < 1) return; button.disabled = true; try { const response = await fetch('{{ route('cart.add') }}', { method:'POST', headers:{ Accept:'application/json', 'Content-Type':'application/json', 'X-CSRF-TOKEN':csrf }, body:JSON.stringify({ product_variant_id:item.id, quantity:1 }) }); const result = await response.json().catch(() => ({})); if (!response.ok) throw new Error(Object.values(result.errors || {}).flat()[0] || result.message || 'Unable to add this product.'); toast.textContent = 'Added to your live cart.'; window.BearlyNavbarState?.sync?.(); } catch (error) { toast.textContent = error.message; } finally { render(); } });
            render();
        })();
    </script>
</body>
</html>

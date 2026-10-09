<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $store->name }} | Bearly</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
    @include('buyer.partials.account-context-script')
    @vite(['resources/css/buyer.css', 'resources/js/buyer.js'])
    <style>
        .store-page { min-height:100vh; background:#f7f3ed; }
        .store-shell { width:min(1180px, calc(100% - 32px)); margin:0 auto; padding:32px 0 72px; }
        .store-hero { overflow:hidden; border:1px solid #e4d8ca; border-radius:14px; background:#fffdfa; box-shadow:0 8px 24px rgba(74,48,30,.06); }
        .store-banner { min-height:220px; background:#d9c8b6 center/cover no-repeat; }
        .store-banner.is-empty { display:grid; place-items:center; color:#fff; background:linear-gradient(135deg,#5b3827,#9b7145); }
        .store-identity { display:flex; align-items:center; gap:18px; padding:22px 26px; }
        .store-avatar { display:grid; place-items:center; flex:0 0 78px; width:78px; height:78px; margin-top:-58px; overflow:hidden; border:5px solid #fffdfa; border-radius:50%; background:#5b3827; color:#fff; font-size:28px; font-weight:800; }
        .store-avatar img { width:100%; height:100%; object-fit:cover; }
        .store-identity h1 { margin:0; color:#39251b; font-size:clamp(25px,4vw,40px); }
        .store-identity p { margin:6px 0 0; color:#75695d; }
        .store-category { color:#6f4a2e; font-size:13px; font-weight:700; }
        .store-meta { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin:0 26px 24px; }
        .store-meta-item { display:flex; gap:10px; align-items:flex-start; padding:14px; border:1px solid #eadfd4; border-radius:10px; background:#fffaf4; color:#5d5148; }
        .store-meta-item > .material-symbols-outlined { color:#6f4a2e; font-size:20px; }
        .store-meta-item strong { display:block; margin-bottom:3px; color:#39251b; font-size:12px; }
        .store-meta-item span { font-size:13px; line-height:1.5; }
        .store-promotions, .store-about, .store-policies, .store-products { margin-top:28px; }
        .store-promotions h2, .store-about h2, .store-policies h2, .store-products h2 { margin:0 0 14px; color:#39251b; }
        .store-promo-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
        .store-promo { overflow:hidden; border:1px solid #e4d8ca; border-radius:12px; background:#fffdfa; }
        .store-promo a { display:block; color:inherit; text-decoration:none; }
        .store-promo img { display:block; width:100%; aspect-ratio:16/6; object-fit:cover; background:#eee5da; }
        .store-promo-copy { padding:12px 14px 14px; }
        .store-promo-copy strong { display:block; color:#39251b; }
        .store-promo-copy span { display:block; margin-top:4px; color:#75695d; font-size:12px; }
        .store-about { display:grid; grid-template-columns:minmax(0,1.15fr) minmax(260px,.85fr); gap:18px; }
        .store-about-panel, .store-policy-panel { padding:20px; border:1px solid #e4d8ca; border-radius:12px; background:#fffdfa; }
        .store-about-panel p { margin:0; color:#62574e; line-height:1.7; white-space:pre-line; }
        .store-policy-list { display:grid; gap:16px; margin:0; }
        .store-policy-list div { padding-bottom:14px; border-bottom:1px solid #eee4da; }
        .store-policy-list div:last-child { padding-bottom:0; border-bottom:0; }
        .store-policy-list dt { color:#39251b; font-weight:700; }
        .store-policy-list dd { margin:5px 0 0; color:#62574e; line-height:1.6; white-space:pre-line; }
        .store-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; }
        .store-product { overflow:hidden; border:1px solid #e4d8ca; border-radius:10px; background:#fffdfa; color:inherit; text-decoration:none; }
        .store-product img, .store-product .store-product-image { display:block; width:100%; aspect-ratio:1/1; object-fit:cover; background:#eee5da; }
        .store-product-copy { padding:14px; }
        .store-product-copy strong { display:block; color:#39251b; line-height:1.35; }
        .store-subcategory-filters { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 16px; }
        .store-subcategory-filters a { display:inline-flex; min-height:36px; align-items:center; padding:7px 12px; border:1px solid #dfd2c4; border-radius:999px; color:#604832; background:#fffdfa; font-size:12px; font-weight:600; text-decoration:none; }
        .store-subcategory-filters a[aria-current="page"] { border-color:#6f4a2e; color:#fff; background:#6f4a2e; }
        .store-product-subcategory { display:inline-flex !important; width:fit-content; margin-top:8px !important; padding:4px 7px; border-radius:5px; color:#6c543f !important; background:#f4ece3; font-size:10px; font-weight:600 !important; }
        .store-product-copy span { display:block; margin-top:7px; color:#6f4a2e; font-weight:800; }
        .store-empty { padding:38px 20px; border:1px dashed #cdbba9; border-radius:16px; color:#75695d; text-align:center; background:#fffdfa; }
        @media (max-width:800px) { .store-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } .store-identity { align-items:flex-start; padding:18px; } .store-about { grid-template-columns:1fr; } }
        @media (max-width:620px) { .store-meta, .store-promo-grid { grid-template-columns:1fr; } }
        @media (max-width:520px) { .store-shell { width:min(100% - 20px,1180px); padding-top:20px; } .store-banner { min-height:150px; } .store-avatar { flex-basis:62px; width:62px; height:62px; margin-top:-44px; font-size:22px; } .store-identity h1 { font-size:25px; } .store-grid { gap:10px; } .store-product-copy { padding:10px; font-size:13px; } }
    </style>
</head>
<body class="bh store-page">
    @include('buyer.partials.buyer-header', [
        'headerSearchInputId' => 'store-search-input',
        'headerSearchAction' => route('products.index'),
    ])
    <main class="store-shell">
        <section class="store-hero">
            @if ($store->banner_path)
                <div class="store-banner" style="background-image:url('{{ $coverUrl }}')"></div>
            @else
                <div class="store-banner is-empty">Bearly seller storefront</div>
            @endif
            <div class="store-identity">
                @if ($store->logo_path)
                    <span class="store-avatar"><img src="{{ $logoUrl }}" alt="{{ $store->name }} logo"></span>
                @else
                    <span class="store-avatar" aria-hidden="true">{{ strtoupper(substr($store->name, 0, 1)) }}</span>
                @endif
                <div>
                    <h1>{{ $store->name }}</h1>
                    <p class="store-category">{{ $category }}</p>
                    <p>{{ $store->description ?: 'Browse published products from this Bearly store.' }}</p>
                </div>
            </div>
            @if (filled($store->announcement) || filled($store->operating_hours))
                <div class="store-meta">
                    @if (filled($store->announcement))
                        <div class="store-meta-item"><span class="material-symbols-outlined" aria-hidden="true">campaign</span><div><strong>Store announcement</strong><span>{{ $store->announcement }}</span></div></div>
                    @endif
                    @if (filled($store->operating_hours))
                        <div class="store-meta-item"><span class="material-symbols-outlined" aria-hidden="true">schedule</span><div><strong>Operating hours</strong><span>{{ $store->operating_hours }}</span></div></div>
                    @endif
                </div>
            @endif
        </section>

        @if (count($promoBanners))
            <section class="store-promotions" aria-labelledby="store-promotions-title">
                <h2 id="store-promotions-title">Store promotions</h2>
                <div class="store-promo-grid">
                    @foreach ($promoBanners as $banner)
                        <article class="store-promo">
                            @if ($banner['link'])<a href="{{ $banner['link'] }}" target="_blank" rel="noopener noreferrer">@endif
                                <img src="{{ $banner['url'] }}" alt="{{ $banner['title'] ?: $store->name.' promotion' }}">
                                @if (filled($banner['title']) || $banner['link'])
                                    <div class="store-promo-copy">
                                        @if (filled($banner['title']))<strong>{{ $banner['title'] }}</strong>@endif
                                        @if ($banner['link'])<span>Learn more ↗</span>@endif
                                    </div>
                                @endif
                            @if ($banner['link'])</a>@endif
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="store-about" aria-labelledby="store-about-title">
            <div class="store-about-panel">
                <h2 id="store-about-title">About this store</h2>
                <p>{{ $store->description ?: 'This store has not added a description yet.' }}</p>
            </div>
            <div class="store-policy-panel">
                <h2>Buyer information</h2>
                <dl class="store-policy-list">
                    <div><dt>Shipping policy</dt><dd>{{ $store->shipping_policy ?: 'No shipping policy added yet.' }}</dd></div>
                    <div><dt>Returns and refunds</dt><dd>{{ $store->return_policy ?: 'No return and refund policy added yet.' }}</dd></div>
                    <div><dt>Warranty</dt><dd>{{ $store->warranty_policy ?: 'No warranty policy added yet.' }}</dd></div>
                </dl>
            </div>
        </section>

        <section class="store-products" aria-labelledby="store-products-title">
            <h2 id="store-products-title">Published products</h2>
            @if ($storeSubcategories->isNotEmpty())
                <nav class="store-subcategory-filters" aria-label="Filter store products by subcategory">
                    <a href="{{ route('stores.show', $store->slug) }}" @if ($selectedSubcategoryId === null) aria-current="page" @endif>All products</a>
                    @foreach ($storeSubcategories as $subcategory)
                        <a href="{{ route('stores.show', ['store' => $store->slug, 'subcategory' => $subcategory->id]) }}" @if ($selectedSubcategoryId === (int) $subcategory->id) aria-current="page" @endif>{{ $subcategory->name }}</a>
                    @endforeach
                </nav>
            @endif
            @if (count($products))
                <div class="store-grid">
                    @foreach ($products as $product)
                        <a class="store-product" href="{{ route('products.show', $product['id']) }}">
                            @if ($product['image'])<img src="{{ $product['image'] }}" alt="{{ $product['name'] }}">@else<div class="store-product-image"></div>@endif
                            <div class="store-product-copy">
                                <strong>{{ $product['name'] }}</strong>
                                @if (filled($product['seller_subcategory'] ?? null))<span class="store-product-subcategory">{{ $product['seller_subcategory'] }}</span>@endif
                                <span>₱{{ number_format($product['price'], 2) }}</span>
                            </div>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="store-empty">This store has no published products yet.</div>
            @endif
        </section>
    </main>
</body>
</html>

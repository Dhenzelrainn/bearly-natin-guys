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
        .store-product-link { display:block; color:inherit; text-decoration:none; }
        .store-subcategory-filters { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 16px; }
        .store-section-filter-title { flex-basis:100%; color:#6d625a; font-size:11px; font-weight:700; }
        .store-subcategory-filters a { display:inline-flex; min-height:36px; align-items:center; padding:7px 12px; border:1px solid #dfd2c4; border-radius:999px; color:#604832; background:#fffdfa; font-size:12px; font-weight:600; text-decoration:none; }
        .store-subcategory-filters a[aria-current="page"] { border-color:#6f4a2e; color:#fff; background:#6f4a2e; }
        .store-product-subcategory { display:inline-flex !important; width:fit-content; margin-top:8px !important; padding:4px 7px; border-radius:5px; color:#6c543f !important; background:#f4ece3; font-size:10px; font-weight:600 !important; }
        .store-product-copy > span { display:block; margin-top:7px; color:#6f4a2e; font-weight:800; }
        .store-shop-actions { display:grid; gap:8px; margin-left:auto; }
        .store-shop-actions a, .store-shop-actions button, .store-product-add { display:inline-flex; min-height:40px; align-items:center; justify-content:center; gap:7px; padding:8px 14px; border:1px solid #6f4a2e; border-radius:8px; background:#6f4a2e; color:#fff; font:600 12px Poppins,sans-serif; text-decoration:none; cursor:pointer; }
        .store-shop-actions button { border-color:#cbb49c; background:#fffdfa; color:#6f4a2e; }
        .store-shop-actions a:hover, .store-product-add:hover:not(:disabled) { background:#563823; }
        .store-shop-actions button:hover { border-color:#6f4a2e; background:#f4ebe0; }
        .store-stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:1px; margin:0 26px 22px; overflow:hidden; border:1px solid #eadfd4; border-radius:10px; background:#eadfd4; }
        .store-stats > div { min-height:76px; display:flex; flex-direction:column; justify-content:center; padding:12px 16px; background:#fffaf4; }
        .store-stats strong { color:#39251b; font-size:19px; }
        .store-stats span { margin-top:2px; color:#75695d; font-size:11px; }
        .store-products-heading { display:flex; align-items:baseline; justify-content:space-between; gap:12px; }
        .store-products-heading h2 { margin-bottom:12px; }
        .store-products-heading > span { color:#75695d; font-size:12px; }
        .store-toolbar { display:grid; grid-template-columns:minmax(220px,1fr) minmax(190px,260px); gap:12px; margin:0 0 16px; }
        .store-toolbar label { display:grid; gap:5px; color:#62574e; font-size:11px; font-weight:700; }
        .store-toolbar input, .store-toolbar select, .store-product-variant { width:100%; min-height:40px; padding:8px 11px; border:1px solid #d8c8b8; border-radius:8px; background:#fffdfa; color:#39251b; font:inherit; font-size:12px; }
        .store-product[hidden] { display:none !important; }
        .store-product-rating { display:flex; align-items:center; flex-wrap:wrap; gap:5px; margin-top:7px; color:#75695d; font-size:11px; }
        .store-product-rating .store-stars { color:#b87511; letter-spacing:1px; }
        .store-product-copy .store-product-price { margin-top:8px; color:#5b351f; font-size:17px; }
        .store-product-stock { color:#75695d !important; font-size:11px; font-weight:500 !important; }
        .store-variant-field { display:grid; gap:5px; margin-top:10px; color:#62574e; font-size:10px; font-weight:700; }
        .store-product-add { width:100%; min-height:38px; margin-top:10px; }
        .store-product-add:disabled { border-color:#cfc5bb; background:#cfc5bb; cursor:not-allowed; }
        .store-product-feedback { min-height:16px; margin:6px 0 0; color:#52703d; font-size:10px; line-height:1.4; }
        .store-product-feedback a { color:#5b3827; font-weight:700; }
        .store-filter-empty { margin-top:14px; padding:18px; border:1px dashed #cdbba9; border-radius:10px; color:#75695d; text-align:center; background:#fffdfa; }
        .store-pagination { display:flex; justify-content:center; align-items:center; gap:12px; margin-top:22px; color:#75695d; font-size:12px; }
        .store-pagination a { padding:8px 12px; border:1px solid #d8c8b8; border-radius:7px; background:#fffdfa; color:#604832; font-weight:700; text-decoration:none; }
        .store-pagination a:hover { border-color:#6f4a2e; }
        .store-empty { padding:38px 20px; border:1px dashed #cdbba9; border-radius:16px; color:#75695d; text-align:center; background:#fffdfa; }
        @media (max-width:800px) { .store-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } .store-identity { align-items:flex-start; padding:18px; } .store-shop-actions { margin-left:0; } .store-about { grid-template-columns:1fr; } .store-stats { margin-right:18px; margin-left:18px; } }
        @media (max-width:620px) { .store-meta, .store-promo-grid { grid-template-columns:1fr; } }
        @media (max-width:520px) { .store-shell { width:min(100% - 20px,1180px); padding-top:20px; } .store-banner { min-height:150px; } .store-avatar { flex-basis:62px; width:62px; height:62px; margin-top:-44px; font-size:22px; } .store-identity { flex-wrap:wrap; } .store-identity h1 { font-size:25px; } .store-grid { gap:10px; } .store-product-copy { padding:10px; font-size:13px; } .store-stats { grid-template-columns:repeat(2,minmax(0,1fr)); margin:0 14px 16px; } .store-stats > div { min-height:64px; padding:10px 12px; } .store-stats strong { font-size:16px; } .store-toolbar { grid-template-columns:1fr; } }
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
                <div class="store-shop-actions">
                    <a href="#store-products"><span class="material-symbols-outlined" aria-hidden="true">storefront</span>Shop products</a>
                    <form method="POST" action="{{ route('buyer.chat.store') }}">
                        @csrf
                        <input type="hidden" name="store" value="{{ $store->slug }}">
                        <button type="submit"><span class="material-symbols-outlined" aria-hidden="true">chat_bubble</span>Chat with seller</button>
                    </form>
                </div>
            </div>
            <div class="store-stats" aria-label="Store information">
                <div><strong>{{ number_format($publishedProductCount) }}</strong><span>Published products</span></div>
                <div><strong>{{ $storeReviewSummary['count'] > 0 ? number_format($storeReviewSummary['average'], 1) : '—' }}</strong><span>Buyer rating</span></div>
                <div><strong>{{ number_format($storeReviewSummary['count']) }}</strong><span>Verified product reviews</span></div>
                <div><strong>{{ $store->created_at?->format('M Y') ?? '—' }}</strong><span>On Bearly since</span></div>
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

        <section class="store-about" id="store-about" aria-labelledby="store-about-title">
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

        <section class="store-products" id="store-products" aria-labelledby="store-products-title">
            <div class="store-products-heading">
                <h2 id="store-products-title">Shop products</h2>
                <span>{{ count($products) }} shown · {{ $publishedProductCount }} in this store</span>
            </div>
            @if ($storeSubcategories->isNotEmpty())
                <nav class="store-subcategory-filters" aria-label="Store sections">
                    <span class="store-section-filter-title">Shop by Store section</span>
                    <a href="{{ route('stores.show', $store->slug) }}" @if ($selectedSubcategoryId === null) aria-current="page" @endif>All products</a>
                    @foreach ($storeSubcategories as $subcategory)
                        <a href="{{ route('stores.show', ['store' => $store->slug, 'subcategory' => $subcategory->id]) }}" @if ($selectedSubcategoryId === (int) $subcategory->id) aria-current="page" @endif>{{ $subcategory->name }}</a>
                    @endforeach
                </nav>
            @endif
            @if (count($products))
                <div class="store-toolbar">
                    <label for="store-product-search">Search this store<input id="store-product-search" type="search" placeholder="Search product name or category" autocomplete="off"></label>
                    <label for="store-product-sort">Sort products<select id="store-product-sort"><option value="newest">Recently listed</option><option value="price-asc">Price: low to high</option><option value="price-desc">Price: high to low</option><option value="name">Name: A to Z</option></select></label>
                </div>
                <div class="store-grid">
                    @foreach ($products as $product)
                        @php
                            $reviewSummary = $product['review_summary'];
                            $initialVariant = collect($product['variants'])->firstWhere('id', $product['default_variant_id']) ?: ($product['variants'][0] ?? null);
                            $initialPrice = (float) ($initialVariant['price'] ?? $product['price']);
                            $initialRegularPrice = (float) ($initialVariant['regular_price'] ?? $initialPrice);
                            $initialDiscount = (int) ($initialVariant['discount_percent'] ?? 0);
                        @endphp
                        <article class="store-product" data-store-product data-product-name="{{ $product['name'] }}" data-product-category="{{ $product['category'] }} {{ $product['seller_subcategory'] ?? '' }}" data-price="{{ $initialVariant['price'] ?? $product['price'] }}" data-position="{{ $loop->index }}">
                            <a class="store-product-link" href="{{ route('products.show', $product['id']) }}" aria-label="View {{ $product['name'] }}">
                                @if ($product['image'])<img src="{{ $product['image'] }}" alt="{{ $product['name'] }}" loading="lazy">@else<div class="store-product-image"></div>@endif
                            </a>
                            <div class="store-product-copy">
                                <a class="store-product-link" href="{{ route('products.show', $product['id']) }}"><strong>{{ $product['name'] }}</strong></a>
                                @if (filled($product['seller_subcategory'] ?? null))<span class="store-product-subcategory">Store section · {{ $product['seller_subcategory'] }}</span>@endif
                                <div class="store-product-rating" role="img" aria-label="{{ $reviewSummary['count'] > 0 ? number_format($reviewSummary['average'], 1).' out of 5, '.$reviewSummary['count'].' reviews' : 'No ratings yet' }}">
                                    @if ($reviewSummary['count'] > 0)<span class="store-stars" aria-hidden="true">{{ str_repeat('★', (int) round($reviewSummary['average'])) }}{{ str_repeat('☆', 5 - (int) round($reviewSummary['average'])) }}</span><strong>{{ number_format($reviewSummary['average'], 1) }}</strong><span>({{ $reviewSummary['count'] }})</span>@else<span>No ratings yet</span>@endif
                                </div>
                                <div class="store-product-price-line">
                                    <span class="store-product-price" data-store-price>₱{{ number_format($initialPrice, 2) }}</span>
                                    @include('buyer.partials.product-price-meta', [
                                        'current' => $initialPrice,
                                        'regular' => $initialRegularPrice,
                                        'discount' => $initialDiscount,
                                        'price_source' => $initialVariant['price_source'] ?? null,
                                        'campaign_name' => $initialVariant['campaign_name'] ?? null,
                                        'product_discount_name' => $initialVariant['product_discount_name'] ?? null,
                                    ])
                                </div>
                                <span class="store-product-stock" data-store-stock>{{ (int) ($initialVariant['stock'] ?? $product['stock']) }} available</span>
                                @if (count($product['variants']) > 1)
                                    <label class="store-variant-field" for="store-variant-{{ $product['id'] }}">Choose variation
                                        <select class="store-product-variant" id="store-variant-{{ $product['id'] }}" data-store-variant>
                                            @foreach ($product['variants'] as $variant)
                                                <option value="{{ $variant['id'] }}" data-price="{{ $variant['price'] }}" data-regular-price="{{ $variant['regular_price'] ?? $variant['price'] }}" data-discount="{{ $variant['discount_percent'] ?? 0 }}" data-campaign-name="{{ ($variant['price_source'] ?? '') === 'campaign' ? ($variant['campaign_name'] ?? '') : ($variant['product_discount_name'] ?? '') }}" data-stock="{{ $variant['stock'] }}" @selected((int) $variant['id'] === (int) ($initialVariant['id'] ?? 0))>{{ $variant['name'] }} · ₱{{ number_format($variant['price'], 2) }}@if ($variant['stock'] < 1) · Out of stock @endif</option>
                                            @endforeach
                                        </select>
                                    </label>
                                @else
                                    <span class="store-product-stock">Variation: {{ $initialVariant['name'] ?? 'Standard' }}</span>
                                @endif
                                <button class="store-product-add" type="button" data-store-add data-variant-id="{{ $initialVariant['id'] ?? '' }}" aria-label="Add {{ $product['name'] }} to cart" @disabled((int) ($initialVariant['stock'] ?? 0) < 1)>Add to cart</button>
                                <p class="store-product-feedback" data-store-feedback role="status" aria-live="polite"></p>
                            </div>
                        </article>
                    @endforeach
                </div>
                @if ($productPaginator->hasPages())
                    <nav class="store-pagination" aria-label="Store product pages">
                        @if ($productPaginator->onFirstPage())<span aria-disabled="true">Previous</span>@else<a href="{{ $productPaginator->previousPageUrl() }}" rel="prev">Previous</a>@endif
                        <span>Page {{ $productPaginator->currentPage() }} of {{ $productPaginator->lastPage() }}</span>
                        @if ($productPaginator->hasMorePages())<a href="{{ $productPaginator->nextPageUrl() }}" rel="next">Next</a>@else<span aria-disabled="true">Next</span>@endif
                    </nav>
                @endif
                <p class="store-filter-empty" data-store-filter-empty hidden>No products match your search.</p>
            @else
                <div class="store-empty">This store has no published products yet.</div>
            @endif
        </section>
    </main>
    <script>
        (() => {
            const grid = document.querySelector('.store-grid');
            const search = document.querySelector('#store-product-search');
            const sort = document.querySelector('#store-product-sort');
            const empty = document.querySelector('[data-store-filter-empty]');
            const addUrl = @json(route('cart.add'));
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            if (!grid) return;

            const cards = [...grid.querySelectorAll('[data-store-product]')];
            const priceText = value => `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

            const syncPriceMeta = (card, price, regular, discount, option = null) => {
                const meta = card.querySelector('[data-price-meta]');
                const regularAmount = card.querySelector('[data-price-regular-amount]');
                const badge = card.querySelector('[data-price-badge]');
                const campaign = card.querySelector('[data-price-campaign]');
                const hasDiscount = Number(discount) > 0 && Number(regular) > Number(price);

                if (meta) meta.hidden = !hasDiscount;
                if (regularAmount) regularAmount.textContent = priceText(regular > 0 ? regular : price);
                if (badge) badge.textContent = hasDiscount ? `${Number(discount) || 0}% OFF` : '';
                if (campaign) {
                    campaign.textContent = option?.dataset.campaignName || '';
                    campaign.hidden = !hasDiscount || !campaign.textContent;
                }
            };

            const syncVariant = (card, select) => {
                const option = select.selectedOptions[0];
                if (!option) return;

                const price = Number(option.dataset.price) || 0;
                const regular = Number(option.dataset.regularPrice) || price;
                const discount = Number(option.dataset.discount) || 0;
                const stock = Number(option.dataset.stock) || 0;
                const button = card.querySelector('[data-store-add]');
                const priceNode = card.querySelector('[data-store-price]');
                const stockNode = card.querySelector('[data-store-stock]');
                card.dataset.price = String(price);
                if (priceNode) priceNode.textContent = priceText(price);
                syncPriceMeta(card, price, regular, discount, option);
                if (stockNode) stockNode.textContent = `${stock} available`;
                if (button) {
                    button.dataset.variantId = option.value;
                    button.disabled = stock < 1;
                }
            };

            cards.forEach(card => {
                const select = card.querySelector('[data-store-variant]');
                if (!select) return;
                const selected = select.selectedOptions[0];
                if (selected && Number(selected.dataset.stock) < 1) {
                    const available = [...select.options].find(option => Number(option.dataset.stock) > 0);
                    if (available) select.value = available.value;
                }
                syncVariant(card, select);
            });

            const renderProducts = () => {
                const query = search?.value.trim().toLocaleLowerCase() || '';
                const ordered = [...cards];
                switch (sort?.value) {
                    case 'price-asc': ordered.sort((a, b) => Number(a.dataset.price) - Number(b.dataset.price)); break;
                    case 'price-desc': ordered.sort((a, b) => Number(b.dataset.price) - Number(a.dataset.price)); break;
                    case 'name': ordered.sort((a, b) => (a.dataset.productName || '').localeCompare(b.dataset.productName || '')); break;
                    default: ordered.sort((a, b) => Number(a.dataset.position) - Number(b.dataset.position));
                }

                let visible = 0;
                ordered.forEach(card => {
                    const haystack = `${card.dataset.productName || ''} ${card.dataset.productCategory || ''}`.toLocaleLowerCase();
                    card.hidden = query !== '' && !haystack.includes(query);
                    if (!card.hidden) visible += 1;
                    grid.append(card);
                });
                if (empty) empty.hidden = visible > 0;
            };

            search?.addEventListener('input', renderProducts);
            sort?.addEventListener('change', renderProducts);
            grid.addEventListener('change', event => {
                const select = event.target.closest('[data-store-variant]');
                if (select) {
                    syncVariant(select.closest('[data-store-product]'), select);
                    renderProducts();
                }
            });
            grid.addEventListener('click', async event => {
                const button = event.target.closest('[data-store-add]');
                if (!button || button.disabled) return;

                const card = button.closest('[data-store-product]');
                const feedback = card?.querySelector('[data-store-feedback]');
                const variantId = Number(button.dataset.variantId);
                if (!variantId) return;

                button.disabled = true;
                button.textContent = 'Adding…';
                if (feedback) feedback.textContent = '';
                try {
                    const response = await fetch(addUrl, {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                        body: JSON.stringify({ product_variant_id: variantId, quantity: 1 }),
                    });
                    const result = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        const validationMessage = Object.values(result.errors || {}).flat()[0];
                        throw new Error(validationMessage || result.message || 'Unable to add this item to your cart.');
                    }

                    if (feedback) {
                        feedback.append(document.createTextNode('Added to cart · '));
                        const link = document.createElement('a');
                        link.href = @json(route('cart.view'));
                        link.textContent = 'View cart';
                        feedback.append(link);
                    }
                    window.bearlyBuyerProfile = {
                        ...(window.bearlyBuyerProfile || {}),
                        cartCount: Number(result.data?.cart_count ?? window.bearlyBuyerProfile?.cartCount ?? 0),
                    };
                    window.BearlyNavbarState?.sync?.();
                } catch (error) {
                    if (feedback) feedback.textContent = error.message || 'Unable to add this item to your cart.';
                } finally {
                    button.textContent = 'Add to cart';
                    const select = card?.querySelector('[data-store-variant]');
                    const stock = select
                        ? Number(select.selectedOptions[0]?.dataset.stock || 0)
                        : Number(card?.querySelector('[data-store-stock]')?.textContent.match(/\d+/)?.[0] || 0);
                    button.disabled = stock < 1;
                }
            });

            renderProducts();
        })();
    </script>
</body>
</html>

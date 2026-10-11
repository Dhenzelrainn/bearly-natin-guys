@php
    $variantCollection = collect($productCard['variants'] ?? []);
    $initialVariant = $variantCollection->first(fn (array $variant): bool => (int) ($variant['stock'] ?? 0) > 0)
        ?? $variantCollection->first();
    $initialVariantId = (int) ($initialVariant['id'] ?? 0);
    $initialImage = filled($initialVariant['image'] ?? null)
        ? (string) $initialVariant['image']
        : (string) ($productCard['image'] ?? '');
    $variantOptionRows = $variantCollection->map(static function (array $variant): array {
        $options = [];

        foreach ((array) ($variant['options'] ?? []) as $name => $value) {
            $key = Str::lower(trim((string) $name));
            $key = $key === 'colour' ? 'color' : $key;
            $value = trim((string) $value);

            if ($key !== '' && $key !== 'label' && $value !== '') {
                $options[$key] = $value;
            }
        }

        foreach (['color', 'size'] as $legacyKey) {
            if (! isset($options[$legacyKey]) && filled($variant[$legacyKey] ?? null)) {
                $options[$legacyKey] = (string) $variant[$legacyKey];
            }
        }

        return ['variant' => $variant, 'options' => $options];
    })->values();
    $variantOptionGroups = $variantOptionRows
        ->flatMap(fn (array $row): array => array_keys($row['options']))
        ->unique()
        ->values()
        ->map(function (string $key) use ($variantOptionRows): array {
            $values = $variantOptionRows
                ->map(fn (array $row): string => (string) ($row['options'][$key] ?? ''))
                ->filter(fn (string $value): bool => filled($value))
                ->unique(fn (string $value): string => Str::lower(trim($value)))
                ->values()
                ->map(function (string $value) use ($key, $variantOptionRows): array {
                    $image = '';

                    if ($key === 'color') {
                        $imageRow = $variantOptionRows->first(fn (array $row): bool =>
                            Str::lower(trim((string) ($row['options'][$key] ?? ''))) === Str::lower(trim($value))
                            && filled($row['variant']['image'] ?? null)
                        );
                        $image = (string) ($imageRow['variant']['image'] ?? '');
                    }

                    return ['value' => $value, 'image' => $image];
                });

            return [
                'key' => $key,
                'label' => $key === 'color' ? 'Color' : Str::headline($key),
                'has_images' => $key === 'color',
                'values' => $values->all(),
            ];
        })
        ->filter(fn (array $group): bool => count($group['values']) > 1)
        ->sortBy(fn (array $group): int => match ($group['key']) {
            'color' => 0,
            'size' => 1,
            default => 2,
        })
        ->values();
    $initialVariantOptionRow = $variantOptionRows->first(fn (array $row): bool => (int) ($row['variant']['id'] ?? 0) === $initialVariantId);
    $initialVariantOptions = $initialVariantOptionRow['options'] ?? [];
    $variantOptionsAreComplete = $variantOptionGroups->isNotEmpty()
        && $variantOptionRows->every(fn (array $row): bool => $variantOptionGroups->every(
            fn (array $group): bool => filled($row['options'][$group['key']] ?? null),
        ));
    $variantOptionCombinationCount = $variantOptionRows
        ->map(fn (array $row): string => serialize($variantOptionGroups
            ->map(fn (array $group): string => Str::lower(trim((string) ($row['options'][$group['key']] ?? ''))))
            ->all()))
        ->unique()
        ->count();
    $useVisualVariantOptions = $variantCollection->count() > 1
        && $variantOptionsAreComplete
        && $variantOptionCombinationCount === $variantCollection->count();
    $initialVariantSpecs = $detailVariantSpecs[$initialVariantId] ?? [];
    $initialWeight = filled($initialVariantSpecs['weight_kg'] ?? null)
        ? number_format((float) $initialVariantSpecs['weight_kg'], 3).' kg'
        : '—';
    $hasInitialDimensions = collect(['length_cm', 'width_cm', 'height_cm'])
        ->every(fn (string $key): bool => filled($initialVariantSpecs[$key] ?? null));
    $initialDimensions = $hasInitialDimensions
        ? collect(['length_cm', 'width_cm', 'height_cm'])
            ->map(fn (string $key): string => number_format((float) $initialVariantSpecs[$key], 2))
            ->implode(' × ').' cm'
        : '—';
    $reviewCollection = collect($productReviews);
    $reviewFacetCounts = [
        'all' => (int) $reviewSummary['count'],
        5 => (int) $reviewSummary['distribution'][5],
        4 => (int) $reviewSummary['distribution'][4],
        3 => (int) $reviewSummary['distribution'][3],
        2 => (int) $reviewSummary['distribution'][2],
        1 => (int) $reviewSummary['distribution'][1],
        'comments' => (int) $reviewSummary['with_comments'],
        'media' => (int) $reviewSummary['with_media'],
    ];
    $reviewListIsLimited = (int) $reviewSummary['count'] > $reviewCollection->count();

    $detailImages = [];
    $seenDetailImageUrls = [];
    $appendDetailImage = function (string $url, string $label, ?int $variantId = null) use (&$detailImages, &$seenDetailImageUrls): void {
        $url = trim($url);

        if ($url === '' || isset($seenDetailImageUrls[$url])) {
            return;
        }

        $seenDetailImageUrls[$url] = true;
        $detailImages[] = [
            'url' => $url,
            'label' => $label,
            'variant_id' => $variantId,
        ];
    };

    $productCardImageIsVariantImage = $variantCollection->contains(fn (array $variant): bool =>
        filled($productCard['image'] ?? null)
        && (string) ($variant['image'] ?? '') === (string) $productCard['image']
    );

    if (filled($productCard['image'] ?? null) && ! $productCardImageIsVariantImage) {
        $appendDetailImage((string) $productCard['image'], 'Main product image');
    }

    foreach ($productCard['gallery'] as $index => $image) {
        $appendDetailImage((string) $image, 'Gallery image '.($index + 1));
    }

    foreach ($productCard['variants'] as $variant) {
        if (! filled($variant['image'] ?? null)) {
            continue;
        }

        $variantLabel = filled($variant['color'] ?? '')
            ? ucfirst((string) $variant['color']).' variant image ('.$variant['name'].')'
            : 'Variant image ('.$variant['name'].')';

        $appendDetailImage((string) $variant['image'], $variantLabel, (int) $variant['id']);
    }
@endphp

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
    @vite(['resources/css/buyer.css', 'resources/css/product-detail.css', 'resources/js/buyer.js', 'resources/js/product-detail.js'])
</head>
<body class="bh catalog-detail-page product-detail-page">
    @include('buyer.partials.buyer-header', [
        'headerSearchInputId' => 'product-search-input',
        'headerSearchAction' => route('products.index'),
    ])

    <main class="catalog-detail-shell">
        <nav class="catalog-breadcrumb" aria-label="Breadcrumb">
            <a href="{{ route('home') }}">Home</a><span aria-hidden="true">/</span>
            <a href="{{ route('products.index', ['category' => $productCard['category_slug']]) }}">{{ $product->category?->name }}</a><span aria-hidden="true">/</span>
            <span aria-current="page">{{ $product->name }}</span>
        </nav>

        <article class="detail-card" data-product-detail>
            <section class="detail-media" aria-label="Product images">
                <div class="detail-primary-image {{ $initialImage === '' ? 'is-empty' : '' }}">
                    @if ($initialImage !== '')
                        <img src="{{ $initialImage }}" alt="{{ $product->name }}" data-detail-primary-image data-default-image="{{ $initialImage }}">
                    @endif
                    @if (count($detailImages) > 1)
                        <button class="detail-gallery-arrow detail-gallery-arrow--previous" type="button" data-gallery-prev aria-label="Previous product image"><span class="material-symbols-outlined" aria-hidden="true">chevron_left</span></button>
                        <button class="detail-gallery-arrow detail-gallery-arrow--next" type="button" data-gallery-next aria-label="Next product image"><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></button>
                    @endif
                </div>
                @if (count($detailImages) > 1)
                    <div class="detail-gallery" role="group" aria-label="Product image choices">
                        @foreach ($detailImages as $image)
                            <button
                                type="button"
                                data-detail-thumbnail
                                data-image-src="{{ $image['url'] }}"
                                data-image-alt="{{ $image['label'] }}"
                                @if ($image['variant_id']) data-variant-id="{{ $image['variant_id'] }}" @endif
                                aria-label="View {{ $image['label'] }}"
                                aria-pressed="{{ $image['url'] === $initialImage ? 'true' : 'false' }}"
                            ><img src="{{ $image['url'] }}" alt="" aria-hidden="true" loading="lazy"></button>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="detail-info">
                <p class="detail-kicker">{{ $product->category?->name }}</p>
                <h1>{{ $product->name }}</h1>
                <a class="detail-rating-summary" href="#product-reviews" aria-label="{{ $reviewSummary['count'] > 0 ? number_format($reviewSummary['average'], 1).' out of 5, '.$reviewSummary['count'].' product reviews' : 'No ratings yet. View product reviews' }}">
                    @if ($reviewSummary['count'] > 0)
                        <span class="detail-rating-stars" aria-hidden="true">{{ str_repeat('★', (int) round($reviewSummary['average'])) }}{{ str_repeat('☆', 5 - (int) round($reviewSummary['average'])) }}</span>
                        <strong>{{ number_format($reviewSummary['average'], 1) }}</strong>
                        <span>{{ number_format($reviewSummary['count']) }} {{ Str::plural('rating', $reviewSummary['count']) }}</span>
                    @else
                        <span>No ratings yet</span>
                    @endif
                </a>

                <div class="detail-price-row"><strong class="detail-price" data-detail-price>₱{{ number_format((float) ($initialVariant['price'] ?? $productCard['price']), 2) }}</strong><span class="detail-price-caption">Current variation price</span></div>

                @if (!empty($productCard['payment_methods']))
                    <div class="detail-payment-methods" aria-label="Payment methods listed by seller">
                        <strong>Payment options:</strong>
                        @foreach ($productCard['payment_methods'] as $method)
                            <span>{{ $method === 'cod' ? 'Cash on Delivery' : 'Online payment' }}</span>
                        @endforeach
                    </div>
                @endif

                @if (count($productCard['variants']) > 1)
                    <div class="detail-options" data-detail-options>
                        @if ($useVisualVariantOptions)
                            @foreach ($variantOptionGroups as $group)
                                @php($optionHeadingId = 'detail-option-heading-'.$loop->index)
                                <div class="detail-option-group">
                                    <span class="detail-option-heading" id="{{ $optionHeadingId }}">
                                        {{ $group['label'] }}: <span data-selected-option="{{ $group['key'] }}">{{ $initialVariantOptions[$group['key']] ?? 'Select' }}</span>
                                    </span>
                                    <div class="detail-option-values" role="group" aria-labelledby="{{ $optionHeadingId }}">
                                        @foreach ($group['values'] as $option)
                                            @php($isSelectedOption = Str::lower(trim((string) ($initialVariantOptions[$group['key']] ?? ''))) === Str::lower(trim((string) $option['value'])))
                                            <button
                                                type="button"
                                                data-option-group="{{ $group['key'] }}"
                                                data-option-value="{{ $option['value'] }}"
                                                @if ($group['has_images'] && filled($option['image'])) data-option-image @endif
                                                aria-label="Select {{ $group['label'] }}: {{ $option['value'] }}"
                                                title="{{ $option['value'] }}"
                                                aria-pressed="{{ $isSelectedOption ? 'true' : 'false' }}"
                                            >
                                                @if ($group['has_images'] && filled($option['image']))
                                                    <img src="{{ $option['image'] }}" alt="" aria-hidden="true" loading="lazy">
                                                    <span class="sr-only">{{ $option['value'] }}</span>
                                                @else
                                                    <span>{{ $option['value'] }}</span>
                                                @endif
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                            <select class="detail-variant-select" data-detail-variant aria-label="Selected product variation" hidden>
                                @foreach ($productCard['variants'] as $variant)
                                    <option value="{{ $variant['id'] }}" @selected((int) $variant['id'] === $initialVariantId)>{{ $variant['name'] }}</option>
                                @endforeach
                            </select>
                        @else
                            <label class="detail-option-heading" for="detail-variant">Choose variation</label>
                            <select class="detail-variant-select" id="detail-variant" data-detail-variant>
                                @foreach ($productCard['variants'] as $variant)
                                    <option value="{{ $variant['id'] }}" @selected((int) $variant['id'] === $initialVariantId)>
                                        {{ $variant['name'] }} · ₱{{ number_format($variant['price'], 2) }}{{ (int) $variant['stock'] < 1 ? ' · Out of stock' : '' }}
                                    </option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                @endif

                <div class="detail-stock-row">
                    <div class="detail-quantity-row">
                        <label class="detail-quantity-label" for="detail-quantity">Quantity</label>
                        <div class="detail-quantity-control">
                            <button type="button" data-detail-quantity-down aria-label="Decrease quantity">−</button>
                            <input id="detail-quantity" type="number" value="1" min="1" inputmode="numeric" data-detail-quantity aria-label="Quantity">
                            <button type="button" data-detail-quantity-up aria-label="Increase quantity">+</button>
                        </div>
                    </div>
                    <p class="detail-stock" data-detail-stock aria-live="polite">{{ (int) ($initialVariant['stock'] ?? 0) > 0 ? $initialVariant['stock'].' available' : 'Out of stock' }}</p>
                </div>

                <div class="detail-actions">
                    <button class="detail-add-button" type="button" data-detail-add><span class="material-symbols-outlined" aria-hidden="true">add_shopping_cart</span>Add to cart</button>
                    <button class="detail-buy-button" type="button" data-detail-buy-now><span class="material-symbols-outlined" aria-hidden="true">shopping_bag</span>Buy now</button>
                </div>
                <p class="detail-toast" role="status" aria-live="polite" data-detail-toast></p>
            </section>
        </article>

        <section class="detail-store" aria-label="Seller store">
            <span class="detail-store-logo" aria-hidden="true">
                @if ($storeLogoUrl)<img src="{{ $storeLogoUrl }}" alt="">@else<span class="material-symbols-outlined">storefront</span>@endif
            </span>
            <div class="detail-store-copy">
                <small>Sold by</small>
                <a href="{{ route('stores.show', $product->store->slug) }}">{{ $product->store->name }}</a>
                <p>{{ $storeProvince ?: 'Philippines' }} · On Bearly since {{ $product->store->created_at?->format('Y') ?? '—' }}</p>
            </div>
            <div class="detail-store-metrics" aria-label="Store information">
                <span><strong>{{ number_format($storeProductCount) }}</strong><small>Products</small></span>
                <span><strong>{{ $storeReviewSummary['count'] > 0 ? number_format($storeReviewSummary['average'], 1) : '—' }}</strong><small>Rating · {{ number_format($storeReviewSummary['count']) }} {{ Str::plural('review', $storeReviewSummary['count']) }}</small></span>
            </div>
            <a class="detail-store-action" href="{{ route('stores.show', $product->store->slug) }}">View Store</a>
        </section>

        <section class="detail-panel" aria-labelledby="detail-specs-title">
            <h2 id="detail-specs-title">Product Specifications</h2>
            <dl class="detail-specs">
                <div><dt>Category</dt><dd>{{ $product->category?->name ?: '—' }}</dd></div>
                <div><dt>SKU</dt><dd data-detail-sku>{{ $initialVariantSpecs['sku'] ?? '—' }}</dd></div>
                <div><dt>Available stock</dt><dd data-detail-stock-spec>{{ (int) ($initialVariant['stock'] ?? 0) }}</dd></div>
                <div><dt>Package weight</dt><dd data-detail-weight>{{ $initialWeight }}</dd></div>
                <div><dt>Package dimensions</dt><dd data-detail-dimensions>{{ $initialDimensions }}</dd></div>
            </dl>
        </section>

        <section class="detail-panel" aria-labelledby="detail-description-title">
            <h2 id="detail-description-title">Product Description</h2>
            <p class="detail-description">{{ $product->description ?: 'The seller has not added a description for this product.' }}</p>
        </section>

        <section class="detail-reviews" id="product-reviews" aria-labelledby="detail-reviews-title">
            <div class="detail-reviews-heading">
                <div>
                    <h2 id="detail-reviews-title">Customer Reviews &amp; Ratings <span>({{ number_format($reviewSummary['count']) }})</span></h2>
                    <p>Reviews are from completed, verified purchases.</p>
                </div>
            </div>
            @if ($reviewSummary['count'] > 0)
                <div class="detail-review-summary">
                    <div class="detail-review-score">
                        <strong>{{ number_format($reviewSummary['average'], 1) }}</strong>
                        <span>out of 5</span>
                        <div class="detail-review-stars" role="img" aria-label="{{ number_format($reviewSummary['average'], 1) }} out of 5 stars">{{ str_repeat('★', (int) round($reviewSummary['average'])) }}{{ str_repeat('☆', 5 - (int) round($reviewSummary['average'])) }}</div>
                        <small>{{ number_format($reviewSummary['count']) }} {{ Str::plural('rating', $reviewSummary['count']) }}</small>
                    </div>
                    <div class="detail-review-dist">
                        @foreach ([5, 4, 3, 2, 1] as $stars)
                            <div><span>{{ $stars }} star</span><b><i style="width:{{ round($reviewSummary['distribution'][$stars] / $reviewSummary['count'] * 100) }}%"></i></b><strong>{{ number_format($reviewSummary['distribution'][$stars]) }}</strong></div>
                        @endforeach
                    </div>
                </div>
                <div class="detail-review-controls">
                    <div class="detail-review-filters" role="group" aria-label="Filter product reviews">
                        <button type="button" data-review-filter="all" aria-pressed="true">All ({{ number_format($reviewFacetCounts['all']) }})</button>
                        @foreach ([5, 4, 3, 2, 1] as $stars)
                            <button type="button" data-review-filter="rating-{{ $stars }}" aria-pressed="false">{{ $stars }} star ({{ number_format($reviewFacetCounts[$stars]) }})</button>
                        @endforeach
                        <button type="button" data-review-filter="comments" aria-pressed="false">With comments ({{ number_format($reviewFacetCounts['comments']) }})</button>
                        <button type="button" data-review-filter="media" aria-pressed="false">With photos ({{ number_format($reviewFacetCounts['media']) }})</button>
                    </div>
                    <label class="sr-only" for="detail-review-sort">Sort reviews</label>
                    <select class="detail-review-sort" id="detail-review-sort" data-review-sort>
                        <option value="newest">Newest first</option>
                        <option value="highest">Highest rating</option>
                        <option value="lowest">Lowest rating</option>
                    </select>
                </div>
                <p class="detail-review-limit-note" @if (! $reviewListIsLimited) hidden @endif>Filters and sorting apply to the latest 50 reviews shown.</p>
                <div class="detail-review-list" data-review-list>
                    @foreach ($productReviews as $review)
                        <article class="detail-review" data-review-card data-rating="{{ $review['rating'] }}" data-date="{{ $review['date'] }}" data-has-comment="{{ filled($review['text']) ? 'true' : 'false' }}" data-has-photo="{{ filled($review['photo']) ? 'true' : 'false' }}">
                            <span class="detail-review-avatar" aria-hidden="true"><span class="material-symbols-outlined">person</span></span>
                            <div class="detail-review-copy">
                                <strong>{{ $review['name'] }}</strong>
                                <div class="detail-review-meta"><span class="detail-review-stars" aria-label="{{ $review['rating'] }} out of 5 stars">{{ str_repeat('★', $review['rating']) }}{{ str_repeat('☆', 5 - $review['rating']) }}</span><span>{{ $review['date_display'] }}</span><span>Variation: {{ $review['variation'] }}</span></div>
                                @if (filled($review['text']))<p>{{ $review['text'] }}</p>@endif
                                @if ($review['photo'])<img class="detail-review-photo" src="{{ $review['photo'] }}" alt="Photo attached to a verified product review" loading="lazy">@endif
                                @if ($review['response'])
                                    <aside class="detail-review-response"><strong>Seller's response</strong><p>{{ $review['response'] }}</p></aside>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
                <p class="detail-review-filter-empty" data-review-empty hidden>No reviews match this filter.</p>
            @else
                <p class="detail-reviews-empty">No reviews yet. Reviews from verified purchases will appear here.</p>
            @endif
        </section>

        @if (count($relatedProducts))
            <section class="related-section" aria-labelledby="related-title">
                <h2 id="related-title">More from this category</h2>
                <div class="related-grid">
                    @foreach ($relatedProducts as $related)
                        <a class="related-card" href="{{ route('products.show', $related['id']) }}">
                            @if ($related['image'])<img src="{{ $related['image'] }}" alt="{{ $related['name'] }}" loading="lazy">@else<div class="related-image" aria-hidden="true">Image unavailable</div>@endif
                            <div class="related-copy"><strong>{{ $related['name'] }}</strong><span>₱{{ number_format($related['price'], 2) }}</span></div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </main>

    <script type="application/json" data-product-detail-data>{!! json_encode([
        'variants' => $productCard['variants'],
        'optionGroups' => $variantOptionGroups->map(fn (array $group): array => ['key' => $group['key']])->values()->all(),
        'variantSpecs' => $detailVariantSpecs,
        'productName' => $product->name,
        'defaultImage' => $productCard['image'],
        'addUrl' => route('cart.add'),
        'buyNowUrl' => route('cart.buy-now'),
        'checkoutUrl' => route('checkout'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>

</body>
</html>

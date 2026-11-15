<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daily Discoveries | Bearly</title>
    <meta name="description" content="Browse the latest Buyer-visible products across the Bearly marketplace.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
    @include('buyer.partials.account-context-script')
    @vite(['resources/css/buyer.css', 'resources/js/buyer.js'])
</head>
<body class="bh buyer-search-page buyer-daily-discoveries-page">
    @include('buyer.partials.buyer-header', [
        'headerSearchInputId' => 'daily-discoveries-search-input',
        'headerSearchAction' => route('products.index'),
        'headerSearchValue' => '',
        'headerShowCategorySelect' => false,
    ])

    <main class="buyer-search-shell daily-discoveries-shell">
        <nav class="daily-discoveries-back-nav" aria-label="Back navigation">
            <a class="daily-discoveries-back" href="{{ route('home') }}">
                <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
                Back to homepage
            </a>
        </nav>

        <header class="buyer-search-heading daily-discoveries-heading">
            <p class="buyer-search-eyebrow">Bearly marketplace</p>
            <h1>Daily Discoveries</h1>
            <p>Browse the latest Buyer-visible products from across Bearly.</p>
        </header>

        @php
            $priceLimit = max(
                5000,
                (int) ($dailyDiscoveryFacets['price_limit'] ?? 5000),
                (int) ($dailyDiscoveryFilters['min'] ?? 0),
                (int) ($dailyDiscoveryFilters['max'] ?? 0),
            );
            $minRangeValue = min($priceLimit, max(0, (int) ($dailyDiscoveryFilters['min'] ?? 0)));
            $maxRangeValue = min($priceLimit, max(0, (int) ($dailyDiscoveryFilters['max'] ?? $priceLimit)));
            $selectedSizes = (array) ($dailyDiscoveryFilters['size'] ?? []);
            $selectedColors = (array) ($dailyDiscoveryFilters['color'] ?? []);
            $hasPriceFilter = ($dailyDiscoveryFilters['min'] ?? null) !== null
                || ($dailyDiscoveryFilters['max'] ?? null) !== null;
            $selectedFilterCount = (int) filled($dailyDiscoveryFilters['q'] ?? null)
                + (int) filled($dailyDiscoveryFilters['category'] ?? null)
                + (int) $hasPriceFilter
                + count($selectedSizes)
                + count($selectedColors);
            $pricePresetBase = collect($dailyDiscoveryQuery)->except(['min', 'max', 'page'])->all();
            $pricePresets = [
                [
                    'label' => 'Under ₱500',
                    'query' => array_merge($pricePresetBase, ['max' => 499]),
                ],
                [
                    'label' => '₱500–₱1,000',
                    'query' => array_merge($pricePresetBase, ['min' => 500, 'max' => 1000]),
                ],
                [
                    'label' => 'Over ₱1,000',
                    'query' => array_merge($pricePresetBase, ['min' => 1001]),
                ],
            ];
            $dailyColorSwatches = [
                'beige' => '#d8c8a4',
                'black' => '#26292a',
                'blue' => '#305e94',
                'brown' => '#79543b',
                'gray' => '#8a8b8e',
                'green' => '#68745a',
                'red' => '#a9483d',
                'white' => '#fff',
                'yellow' => '#e9c34a',
            ];
        @endphp

        <div class="daily-discoveries-layout">
            <aside class="daily-discoveries-sidebar" aria-label="Product filters">
                <details class="daily-discoveries-filter-panel" open>
                    <summary class="daily-discoveries-filter-summary">
                        <span><span class="material-symbols-outlined" aria-hidden="true">tune</span>Filters</span>
                        <span class="material-symbols-outlined" aria-hidden="true">expand_more</span>
                    </summary>

                    <div class="daily-discoveries-filter-body">
                        <div class="daily-discoveries-filter-heading">
                            <h2 class="sr-only">Refine Daily Discoveries</h2>
                            <button type="button" data-daily-discoveries-collapse aria-expanded="true">Collapse all</button>
                        </div>

                        <form id="daily-discoveries-filter-form" action="{{ route('buyer.daily-discoveries.index') }}" method="GET" class="daily-discoveries-filter-form" data-price-limit="{{ $priceLimit }}">
                            <label class="daily-discoveries-search" for="daily-discoveries-query">
                                <span class="material-symbols-outlined" aria-hidden="true">search</span>
                                <input
                                    id="daily-discoveries-query"
                                    name="q"
                                    type="search"
                                    maxlength="120"
                                    placeholder="Search within Daily Discoveries"
                                    value="{{ old('q', $dailyDiscoveryFilters['q'] ?? '') }}"
                                >
                            </label>
                            @error('q')
                                <span class="daily-discoveries-filter-error">{{ $message }}</span>
                            @enderror

                            <div class="daily-discoveries-selected">
                                <strong>Selected ({{ $selectedFilterCount }})</strong>
                                <a href="{{ route('buyer.daily-discoveries.index') }}">Clear all</a>
                            </div>

                            <details class="daily-discoveries-filter-group" data-daily-filter-group open>
                                <summary>Category</summary>
                                <div class="daily-discoveries-filter-group-content">
                                    <select id="daily-discoveries-category" name="category" aria-label="Category">
                                        <option value="">All categories</option>
                                        @foreach ($categories as $category)
                                            <option value="{{ $category['slug'] }}" @selected(old('category', $dailyDiscoveryFilters['category'] ?? '') === $category['slug'])>
                                                {{ $category['name'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </details>

                            <details class="daily-discoveries-filter-group" data-daily-filter-group open>
                                <summary>Price range</summary>
                                <div class="daily-discoveries-filter-group-content">
                                    <fieldset class="daily-discoveries-price-fields">
                                        <legend class="sr-only">Price range in Philippine pesos</legend>
                                        <div>
                                            <label for="daily-discoveries-min">₱ Min</label>
                                            <input
                                                id="daily-discoveries-min"
                                                name="min"
                                                type="number"
                                                min="0"
                                                max="100000000"
                                                step="1"
                                                inputmode="numeric"
                                                placeholder="0"
                                                value="{{ old('min', $dailyDiscoveryFilters['min'] ?? '') }}"
                                                @if ($errors->has('min')) aria-invalid="true" aria-describedby="daily-discoveries-min-error" @endif
                                            >
                                            @error('min')
                                                <span class="daily-discoveries-filter-error" id="daily-discoveries-min-error">{{ $message }}</span>
                                            @enderror
                                        </div>
                                        <div>
                                            <label for="daily-discoveries-max">₱ Max</label>
                                            <input
                                                id="daily-discoveries-max"
                                                name="max"
                                                type="number"
                                                min="0"
                                                max="100000000"
                                                step="1"
                                                inputmode="numeric"
                                                placeholder="{{ $priceLimit }}"
                                                value="{{ old('max', $dailyDiscoveryFilters['max'] ?? '') }}"
                                                @if ($errors->has('max')) aria-invalid="true" aria-describedby="daily-discoveries-max-error" @endif
                                            >
                                            @error('max')
                                                <span class="daily-discoveries-filter-error" id="daily-discoveries-max-error">{{ $message }}</span>
                                            @enderror
                                        </div>
                                    </fieldset>

                                    <div class="daily-discoveries-ranges" aria-label="Adjust price range">
                                        <input
                                            id="daily-discoveries-min-range"
                                            type="range"
                                            min="0"
                                            max="{{ $priceLimit }}"
                                            step="1"
                                            value="{{ $minRangeValue }}"
                                            aria-label="Minimum price slider"
                                        >
                                        <input
                                            id="daily-discoveries-max-range"
                                            type="range"
                                            min="0"
                                            max="{{ $priceLimit }}"
                                            step="1"
                                            value="{{ $maxRangeValue }}"
                                            aria-label="Maximum price slider"
                                        >
                                    </div>

                                    <div class="daily-discoveries-presets" aria-label="Popular price ranges">
                                        @foreach ($pricePresets as $preset)
                                            <a href="{{ route('buyer.daily-discoveries.index', $preset['query']) }}">{{ $preset['label'] }}</a>
                                        @endforeach
                                    </div>
                                </div>
                            </details>

                            <details class="daily-discoveries-filter-group" data-daily-filter-group open>
                                <summary>Size</summary>
                                <div class="daily-discoveries-filter-group-content daily-discoveries-size-options">
                                    @forelse ($dailyDiscoveryFacets['sizes'] ?? [] as $size)
                                        <label>
                                            <input type="checkbox" name="size[]" value="{{ $size }}" @checked(in_array($size, $selectedSizes, true))>
                                            <span>{{ $size }}</span>
                                        </label>
                                    @empty
                                        <p class="daily-discoveries-empty-option">No options available in this category.</p>
                                    @endforelse
                                </div>
                            </details>

                            <details class="daily-discoveries-filter-group" data-daily-filter-group open>
                                <summary>Color</summary>
                                <div class="daily-discoveries-filter-group-content daily-discoveries-color-options">
                                    @forelse ($dailyDiscoveryFacets['colors'] ?? [] as $color)
                                        @php
                                            $colorKey = mb_strtolower($color);
                                        @endphp
                                        <label>
                                            <input type="checkbox" name="color[]" value="{{ $color }}" @checked(in_array($color, $selectedColors, true))>
                                            <span class="daily-discoveries-color-swatch" style="--swatch: {{ $dailyColorSwatches[$colorKey] ?? '#aaa' }}" aria-hidden="true"></span>
                                            <span>{{ $color }}</span>
                                        </label>
                                    @empty
                                        <p class="daily-discoveries-empty-option">No options available in this category.</p>
                                    @endforelse
                                </div>
                            </details>

                            <details class="daily-discoveries-filter-group" data-daily-filter-group open>
                                <summary>Sort by</summary>
                                <div class="daily-discoveries-filter-group-content">
                                    <select id="daily-discoveries-sort" name="sort" aria-label="Sort products">
                                        @foreach ([
                                            'featured' => 'Newest first',
                                            'price-low' => 'Price: low to high',
                                            'price-high' => 'Price: high to low',
                                        ] as $value => $label)
                                            <option value="{{ $value }}" @selected(old('sort', $dailyDiscoveryFilters['sort'] ?? 'featured') === $value)>
                                                {{ $label }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </details>

                            <button type="submit" class="daily-discoveries-apply">Apply filters</button>
                        </form>
                    </div>
                </details>
            </aside>

            <section class="buyer-search-products daily-discoveries-products" aria-labelledby="daily-discoveries-products-title">
                <div class="buyer-search-section-heading">
                    <div>
                        <p class="buyer-search-eyebrow">Latest buyer-visible finds</p>
                        <h2 id="daily-discoveries-products-title">Products</h2>
                    </div>
                    <span>{{ number_format($dailyDiscoveryResults['pagination']['total']) }} {{ $dailyDiscoveryResults['pagination']['total'] === 1 ? 'product' : 'products' }}</span>
                </div>

                @if ($dailyDiscoveryResults['products'] !== [])
                    <div class="buyer-search-product-grid daily-discoveries-product-grid" data-daily-discoveries-grid>
                        @foreach ($dailyDiscoveryResults['products'] as $product)
                            @php
                                $productUrl = route('products.show', ['product' => $product['id']]);
                                $categoryName = $product['marketplace_category_name'] ?: $product['category'];
                                $currentPrice = ((int) $product['price_minor']) / 100;
                                $regularPrice = ((int) ($product['regular_price_minor'] ?? $product['price_minor'])) / 100;
                            @endphp
                            <article class="buyer-search-product-card daily-discoveries-product-card">
                                <a class="buyer-search-product-image" href="{{ $productUrl }}">
                                    @if ($product['image'])
                                        <img src="{{ $product['image'] }}" alt="{{ $product['name'] }}" loading="lazy" decoding="async">
                                    @else
                                        <span class="material-symbols-outlined" aria-hidden="true">image</span>
                                    @endif
                                </a>
                                <div class="buyer-search-product-copy">
                                    <p>{{ $categoryName }}</p>
                                    <h3><a href="{{ $productUrl }}">{{ $product['name'] }}</a></h3>
                                    <div class="buyer-search-price-line">
                                        <strong class="buyer-search-price">₱{{ number_format($currentPrice, 2) }}</strong>
                                        @include('buyer.partials.product-price-meta', [
                                            'current' => $currentPrice,
                                            'regular' => $regularPrice,
                                            'discount' => (int) ($product['discount_percent'] ?? 0),
                                            'price_source' => $product['price_source'] ?? null,
                                            'campaign_name' => $product['campaign_name'] ?? null,
                                            'product_discount_name' => $product['product_discount_name'] ?? null,
                                        ])
                                    </div>
                                    @if ($product['store_slug'])
                                        <a class="buyer-search-seller" href="{{ route('stores.show', ['store' => $product['store_slug']]) }}">
                                            Sold by {{ $product['seller_name'] }}
                                        </a>
                                    @else
                                        <span class="buyer-search-seller">Sold by {{ $product['seller_name'] }}</span>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>

                    @php
                        $pagination = $dailyDiscoveryResults['pagination'];
                        $firstItem = (($pagination['current_page'] - 1) * $pagination['per_page']) + 1;
                        $lastItem = min($pagination['current_page'] * $pagination['per_page'], $pagination['total']);
                    @endphp
                    @if ($pagination['last_page'] > 1)
                        <nav class="buyer-search-pagination daily-discoveries-pagination" aria-label="Daily discoveries pages">
                            @if ($pagination['current_page'] > 1)
                                <a href="{{ route('buyer.daily-discoveries.index', array_merge($dailyDiscoveryQuery, ['page' => $pagination['current_page'] - 1])) }}" rel="prev">Previous</a>
                            @else
                                <span aria-disabled="true">Previous</span>
                            @endif
                            <span>Page {{ $pagination['current_page'] }} of {{ $pagination['last_page'] }} · {{ number_format($firstItem) }}–{{ number_format($lastItem) }} of {{ number_format($pagination['total']) }}</span>
                            @if ($pagination['has_more'])
                                <a href="{{ route('buyer.daily-discoveries.index', array_merge($dailyDiscoveryQuery, ['page' => $pagination['current_page'] + 1])) }}" rel="next">Next</a>
                            @else
                                <span aria-disabled="true">Next</span>
                            @endif
                        </nav>
                    @endif
                @else
                    <div class="buyer-search-empty daily-discoveries-empty" role="status">
                        <span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>
                        <h3>No products match these filters</h3>
                        <p>Try a wider price range or choose another category.</p>
                        <a href="{{ route('buyer.daily-discoveries.index') }}">Clear filters</a>
                    </div>
                @endif
            </section>
        </div>
    </main>
</body>
</html>

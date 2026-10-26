@php
    $rankingRouteName = $rankingMode === 'popular' ? 'top-products' : 'top-sales';
    $selectedCategoryDetails = $selectedRankingCategory !== null
        ? $rankingCategories->firstWhere('slug', $selectedRankingCategory)
        : null;
    $rankingLabel = $rankingMode === 'popular' ? 'Popular Picks' : 'Best Sellers';
@endphp

<main class="top-products-main" data-ranking-page data-ranking-mode="{{ $rankingMode }}">
    <a class="flash-back" href="{{ url('/home') }}">
        <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
        Back to Home
    </a>

    <section class="top-products-hero">
        <div>
            <p class="top-products-kicker">
                <span class="material-symbols-outlined" aria-hidden="true">{{ $rankingMode === 'popular' ? 'favorite' : 'trending_up' }}</span>
                {{ $rankingMode === 'popular' ? 'Popular Picks' : 'Best Sellers' }}
            </p>
            <h1>{{ $rankingPageHeading }}</h1>
            <p>{{ $rankingIntro }}</p>
        </div>
    </section>

    @if ($rankingCategories->isNotEmpty())
        <nav class="top-products-toolbar" aria-label="{{ $rankingFilterLabel }}">
            <div class="top-filter-row">
                <a
                    href="{{ route($rankingRouteName) }}"
                    @class(['is-active' => $selectedRankingCategory === null])
                    @if ($selectedRankingCategory === null) aria-current="page" @endif
                >All categories</a>
                @foreach ($rankingCategories as $category)
                    <a
                        href="{{ route($rankingRouteName, ['category' => $category['slug']]) }}"
                        @class(['is-active' => $selectedRankingCategory === $category['slug']])
                        @if ($selectedRankingCategory === $category['slug']) aria-current="page" @endif
                    >{{ $category['name'] }}</a>
                @endforeach
            </div>
        </nav>
    @endif

    @if ($rankingProducts->total() > 0)
        <section class="top-products-grid ranking-products-grid" aria-label="{{ $rankingPageHeading }} ranked products">
            @foreach ($rankingProducts as $product)
                @include('buyer.partials.ranked-product-card', [
                    'product' => $product,
                    'rank' => (int) ($rankingProducts->firstItem() ?? 1) + $loop->index,
                    'rankingType' => $rankingMode,
                ])
            @endforeach
        </section>

        @if ($rankingProducts->hasPages())
            <nav class="ranking-pagination" aria-label="Product ranking pages">
                {{ $rankingProducts->links() }}
            </nav>
        @endif
    @else
        <section class="top-products-empty ranking-empty" role="status">
            <span class="material-symbols-outlined" aria-hidden="true">query_stats</span>
            @if ($selectedCategoryDetails)
                <h2>No {{ $rankingLabel }} in {{ $selectedCategoryDetails['name'] }} yet</h2>
                <p>This category has no Buyer-visible, in-stock COD products with ranking activity yet. Check back as real saves or completed orders come in.</p>
            @else
                <h2>{{ $rankingEmptyHeading }}</h2>
                <p>{{ $rankingEmptyDescription }}</p>
            @endif
            <a href="{{ route('products.index') }}">Browse live products</a>
        </section>
    @endif

    <p id="result-count" class="sr-only" role="status" aria-live="polite"></p>
</main>

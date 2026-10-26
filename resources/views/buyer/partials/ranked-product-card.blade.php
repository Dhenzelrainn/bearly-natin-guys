@php
    $homeRankingCard = (bool) ($homeRankingCard ?? false);
    $rankingType = (string) ($rankingType ?? 'popular');
    $rankingMetric = $rankingType === 'popular'
        ? (int) ($product['buyer_likes_count'] ?? 0)
        : (int) ($product['completed_units_sold'] ?? 0);
    $categoryLabel = $product['marketplace_category_name'] ?: ($product['category'] ?? 'Marketplace');
    $productUrl = route('products.show', ['product' => $product['id']]);
    $reviewSummary = $product['review_summary'] ?? null;
@endphp

<article
    class="top-product-card ranking-product-card discovery-card{{ $homeRankingCard ? ' home-ranking-product' : '' }}"
    data-discovery-product="live:{{ (int) $product['id'] }}"
    data-live-product-id="{{ (int) $product['id'] }}"
    @if ($homeRankingCard) data-home-ranking-product-id="{{ (int) $product['id'] }}" @endif
>
    <div class="top-product-image">
        <a class="ranking-product-image-link" href="{{ $productUrl }}" aria-label="View {{ $product['name'] }}">
            @if (filled($product['image'] ?? null))
                <img src="{{ $product['image'] }}" alt="{{ $product['name'] }}" loading="lazy" decoding="async">
            @else
                <span class="material-symbols-outlined ranking-product-no-image" aria-hidden="true">image</span>
            @endif
        </a>
        <span class="top-rank{{ $rank <= 3 ? ' top-rank-podium' : '' }}{{ $homeRankingCard ? ' top-home-rank' : '' }}">#{{ $rank }}</span>
        @if ($homeRankingCard)
            <span class="top-home-metric">
                @if ($rankingType === 'popular')
                    {{ number_format($rankingMetric) }} {{ \Illuminate\Support\Str::plural('Buyer save', $rankingMetric) }}
                @else
                    {{ number_format($rankingMetric) }} completed units sold
                @endif
            </span>
        @endif
        <button
            type="button"
            class="top-like discovery-like{{ ($product['is_wishlisted'] ?? false) ? ' is-liked' : '' }}"
            data-discovery-like
            aria-pressed="{{ ($product['is_wishlisted'] ?? false) ? 'true' : 'false' }}"
            aria-label="{{ ($product['is_wishlisted'] ?? false) ? 'Remove' : 'Save' }} {{ $product['name'] }} {{ ($product['is_wishlisted'] ?? false) ? 'from' : 'to' }} My Likes"
        >
            <span class="material-symbols-outlined" aria-hidden="true">favorite</span>
        </button>
    </div>

    <div class="top-product-copy">
        <small>{{ $categoryLabel }}</small>
        <h3><a href="{{ $productUrl }}">{{ $product['name'] }}</a></h3>

        @if ($reviewSummary && (int) ($reviewSummary['count'] ?? 0) > 0)
            <div class="ranking-product-rating" role="img" aria-label="{{ number_format((float) $reviewSummary['average'], 1) }} out of 5 from {{ $reviewSummary['count'] }} verified {{ \Illuminate\Support\Str::plural('review', (int) $reviewSummary['count']) }}">
                <span aria-hidden="true">★</span>
                <strong>{{ number_format((float) $reviewSummary['average'], 1) }}</strong>
                <small>{{ number_format((int) $reviewSummary['count']) }} {{ \Illuminate\Support\Str::plural('review', (int) $reviewSummary['count']) }}</small>
            </div>
        @endif

        @unless ($homeRankingCard)
            <p class="ranking-product-metric">
                @if ($rankingType === 'popular')
                    {{ number_format($rankingMetric) }} {{ \Illuminate\Support\Str::plural('Buyer save', $rankingMetric) }}
                @else
                    {{ number_format($rankingMetric) }} completed units sold
                @endif
            </p>
        @endunless

        <div class="top-product-bottom">
            <strong>₱{{ number_format(((int) $product['price_minor']) / 100, 2) }}</strong>
        </div>
    </div>
</article>

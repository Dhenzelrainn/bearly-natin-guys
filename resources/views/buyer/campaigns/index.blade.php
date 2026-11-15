<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>All Campaigns | Bearly</title>
    <meta name="description" content="Browse all active Bearly campaigns available to you.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
    @include('buyer.partials.account-context-script')
    @vite(['resources/css/buyer.css', 'resources/js/buyer.js'])
</head>
<body class="bh buyer-search-page buyer-campaign-page buyer-campaign-index-page">
    @include('buyer.partials.buyer-header', [
        'headerSearchInputId' => 'buyer-campaign-index-input',
        'headerSearchAction' => route('products.index'),
        'headerSearchValue' => '',
        'headerShowCategorySelect' => false,
    ])

    <main class="buyer-search-shell">
        <a class="campaign-back" href="{{ route('home') }}">
            <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
            <span>Back to Home</span>
        </a>
        <nav class="buyer-search-breadcrumb" aria-label="Breadcrumb">
            <a href="{{ route('home') }}">Home</a>
            <span aria-hidden="true">/</span>
            <span aria-current="page">All Campaigns</span>
        </nav>

        <header class="buyer-search-heading buyer-campaign-index-heading">
            <p class="buyer-search-eyebrow">Bearly events</p>
            <h1>All Campaigns</h1>
            <p>Explore every active campaign available to your account.</p>
        </header>

        @if ($campaigns !== [])
            <section class="campaign-index-section" aria-labelledby="campaign-index-title">
                <div class="buyer-search-section-heading">
                    <h2 id="campaign-index-title">Live campaigns</h2>
                    <span>{{ number_format(count($campaigns)) }} {{ \Illuminate\Support\Str::plural('campaign', count($campaigns)) }}</span>
                </div>

                <div class="campaign-index-grid">
                    @foreach ($campaigns as $campaign)
                        @php
                            $campaignIcon = match ($campaign['type_key'] ?? 'general') {
                                'flash_deal' => 'bolt',
                                'piso_sale' => 'local_offer',
                                'payday_sale' => 'payments',
                                'monthly_deal' => 'calendar_month',
                                'double_date' => 'celebration',
                                default => 'campaign',
                            };
                            $campaignDescription = trim(strip_tags((string) ($campaign['description'] ?? '')));
                        @endphp
                        <article class="campaign-index-card" data-campaign-type="{{ $campaign['type_key'] ?? 'general' }}">
                            <a class="campaign-index-artwork" href="{{ route('campaigns.show', $campaign['id']) }}" aria-label="View {{ $campaign['name'] }} campaign">
                                @if (filled($campaign['banner_url'] ?? null))
                                    <img src="{{ $campaign['banner_url'] }}" alt="{{ $campaign['banner_title'] ?: $campaign['name'].' campaign banner' }}" loading="lazy" decoding="async">
                                @else
                                    <span class="campaign-index-fallback" data-campaign-tone="{{ $campaign['type_key'] ?? 'general' }}">
                                        <span class="material-symbols-outlined" aria-hidden="true">{{ $campaignIcon }}</span>
                                        <span>{{ $campaign['type_label'] }}</span>
                                    </span>
                                @endif
                            </a>
                            <div class="campaign-index-copy">
                                <div class="campaign-index-labels">
                                    <span class="campaign-index-type">{{ $campaign['type_label'] }}</span>
                                    <span class="campaign-index-status">{{ $campaign['status_label'] }}</span>
                                </div>
                                <h3><a href="{{ route('campaigns.show', $campaign['id']) }}">{{ $campaign['name'] }}</a></h3>
                                <p class="campaign-index-description">
                                    {{ $campaignDescription !== '' ? \Illuminate\Support\Str::limit($campaignDescription, 150) : 'Explore participating products in this Bearly campaign.' }}
                                </p>
                                <div class="campaign-index-footer">
                                    <div class="campaign-index-facts">
                                        @if (filled($campaign['discount_label'] ?? null))
                                            <span class="campaign-index-discount">{{ $campaign['discount_label'] }}</span>
                                        @endif
                                        @if (filled($campaign['starts_on_label'] ?? null) && filled($campaign['ends_on_label'] ?? null))
                                            <span class="campaign-index-date">
                                                <span class="material-symbols-outlined" aria-hidden="true">calendar_month</span>
                                                <time datetime="{{ $campaign['starts_on'] }}">{{ $campaign['starts_on_label'] }}</time>
                                                <span aria-hidden="true">–</span>
                                                <time datetime="{{ $campaign['ends_on'] }}">{{ $campaign['ends_on_label'] }}</time>
                                            </span>
                                        @endif
                                    </div>
                                    <a class="campaign-index-link" href="{{ route('campaigns.show', $campaign['id']) }}" aria-label="Shop {{ $campaign['name'] }}">
                                        Shop campaign <span aria-hidden="true">→</span>
                                    </a>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @else
            <section class="buyer-search-empty campaign-index-empty" aria-labelledby="campaign-index-empty-title">
                <span class="material-symbols-outlined" aria-hidden="true">campaign</span>
                <h2 id="campaign-index-empty-title">No live campaigns right now</h2>
                <p>Check back soon, or browse the latest products from Bearly sellers.</p>
                <a class="campaign-empty-link" href="{{ route('products.index') }}">Browse products</a>
            </section>
        @endif
    </main>
</body>
</html>

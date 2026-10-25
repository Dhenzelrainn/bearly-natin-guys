@php
    $campaignOffers = collect($campaigns ?? []);
    $campaignTypeTabs = $campaignOffers
        ->groupBy(fn (array $campaign): string => (string) ($campaign['type_key'] ?? 'general'))
        ->map(fn ($group, $key): array => [
            'key' => (string) $key,
            'label' => (string) ($group->first()['type_label'] ?? 'Campaign'),
        ])
        ->values();
@endphp

@if ($campaignOffers->isNotEmpty())
<section class="home-campaign-showcase" data-home-campaigns aria-labelledby="home-campaigns-title">
    <div class="home-campaigns-heading">
        <div>
            <p>Bearly events</p>
            <h2 id="home-campaigns-title">Campaigns &amp; Offers</h2>
        </div>
        <a class="home-campaigns-view-all" href="{{ route('flash-deals') }}">
            View all deals <span aria-hidden="true">→</span>
        </a>
    </div>

    @if ($campaignTypeTabs->count() > 1)
        <div class="home-campaign-filters" role="group" aria-label="Filter campaigns">
            <button class="is-active" type="button" data-campaign-filter="all" aria-pressed="true">All Campaigns</button>
            @foreach ($campaignTypeTabs as $tab)
                <button type="button" data-campaign-filter="{{ $tab['key'] }}" aria-pressed="false">{{ $tab['label'] }}</button>
            @endforeach
        </div>
    @endif

    <div class="home-campaign-carousel">
        <button class="home-campaign-arrow home-campaign-prev" type="button" data-campaign-prev aria-label="Previous campaigns" hidden>
            <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
        </button>

        <div class="home-campaign-track" data-campaign-track tabindex="0" aria-label="Active Bearly campaigns">
            @foreach ($campaignOffers as $campaign)
                @php
                    $campaignTypeKey = (string) ($campaign['type_key'] ?? 'general');
                    $campaignEndsAt = $campaign['ends_at']
                        ? \Illuminate\Support\Carbon::parse($campaign['ends_at'])->timezone('Asia/Manila')
                        : null;
                    $campaignDateLabel = $campaignEndsAt ? 'Until '.$campaignEndsAt->format('M j, Y') : 'Live now';
                    $campaignDescription = trim((string) ($campaign['description'] ?? ''));
                    $campaignIcon = match ($campaignTypeKey) {
                        'flash_deal' => 'bolt',
                        'piso_sale' => 'local_offer',
                        'payday_sale' => 'payments',
                        'monthly_deal' => 'calendar_month',
                        'double_date' => 'celebration',
                        default => 'campaign',
                    };
                @endphp
                <article class="home-campaign-card" data-campaign-card data-campaign-type="{{ $campaignTypeKey }}">
                    <a class="home-campaign-link" href="{{ route('campaigns.show', $campaign['id']) }}">
                        <span class="home-campaign-banner">
                            @if ($campaign['banner_url'])
                                <img
                                    src="{{ $campaign['banner_url'] }}"
                                    alt="{{ $campaign['banner_title'] ?: $campaign['name'].' campaign banner' }}"
                                    loading="lazy"
                                >
                            @else
                                <span class="home-campaign-fallback" data-campaign-tone="{{ $campaignTypeKey }}">
                                    <span class="material-symbols-outlined" aria-hidden="true">{{ $campaignIcon }}</span>
                                    <span class="home-campaign-fallback-copy">
                                        <small>{{ $campaign['type_label'] }}</small>
                                        <strong>{{ $campaign['name'] }}</strong>
                                    </span>
                                </span>
                            @endif
                        </span>

                        <span class="home-campaign-copy">
                            <small class="home-campaign-type">{{ $campaign['type_label'] }}</small>
                            <span class="home-campaign-title-row">
                                <strong>{{ $campaign['name'] }}</strong>
                                <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
                            </span>
                            <span class="home-campaign-description">
                                {{ $campaignDescription !== '' ? \Illuminate\Support\Str::limit(strip_tags($campaignDescription), 108) : 'Shop participating products from this Bearly campaign.' }}
                            </span>
                            <span class="home-campaign-date">
                                <span class="material-symbols-outlined" aria-hidden="true">calendar_today</span>
                                {{ $campaignDateLabel }}
                            </span>
                        </span>
                    </a>
                </article>
            @endforeach
        </div>

        <button class="home-campaign-arrow home-campaign-next" type="button" data-campaign-next aria-label="Next campaigns" hidden>
            <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
        </button>
    </div>

    <div class="home-campaign-dots" data-campaign-dots role="group" aria-label="Campaign pages" hidden></div>
</section>
@endif

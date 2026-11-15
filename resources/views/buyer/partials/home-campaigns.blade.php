@php
    $campaignOffers = collect($campaigns ?? [])
        ->filter(fn (array $campaign): bool => filled($campaign['banner_url'] ?? null))
        ->values();
    $campaignTypeTabs = $campaignOffers
        ->groupBy(fn (array $campaign): string => (string) ($campaign['type_key'] ?? 'general'))
        ->map(fn ($group, $key): array => [
            'key' => (string) $key,
            'label' => (string) ($group->first()['type_label'] ?? 'Campaign'),
        ])
        ->values();
    $campaignTrackLayout = match ($campaignOffers->count()) {
        1 => 'has-one-campaign',
        2 => 'has-two-campaigns',
        default => '',
    };
@endphp

@if ($campaignOffers->isNotEmpty())
<section class="home-campaign-showcase" data-home-campaigns aria-labelledby="home-campaigns-title">
    <div class="home-campaigns-heading">
        <h2 id="home-campaigns-title">Campaigns &amp; Offers</h2>
        <a class="home-campaigns-view-all" href="{{ route('campaigns.index') }}">
            View all campaigns <span aria-hidden="true">→</span>
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

    <div class="home-campaign-track {{ $campaignTrackLayout }}" data-campaign-track aria-label="Active Bearly campaign banners">
        @foreach ($campaignOffers as $campaign)
            <article
                class="home-campaign-card"
                data-campaign-card
                data-campaign-type="{{ $campaign['type_key'] ?? 'general' }}"
                data-campaign-id="{{ $campaign['id'] }}"
            >
                <a
                    class="home-campaign-link"
                    href="{{ route('campaigns.show', $campaign['id']) }}"
                    aria-label="View campaign details for {{ $campaign['name'] }}"
                >
                    <img
                        class="home-campaign-banner"
                        src="{{ $campaign['banner_url'] }}"
                        alt="{{ $campaign['banner_title'] ?: $campaign['name'].' campaign banner' }}"
                        loading="lazy"
                        decoding="async"
                >
                </a>
                <div class="home-campaign-meta">
                    <div class="home-campaign-copy">
                        <h3 class="home-campaign-title-row"><strong>{{ $campaign['name'] }}</strong></h3>
                        <p class="home-campaign-type">{{ $campaign['funding_label'] }}</p>
                        @if (filled($campaign['starts_on_label'] ?? null) && filled($campaign['ends_on_label'] ?? null))
                            <p class="home-campaign-date">
                                <span class="material-symbols-outlined" aria-hidden="true">calendar_month</span>
                                <time datetime="{{ $campaign['starts_on'] }}">{{ $campaign['starts_on_label'] }}</time>
                                <span aria-hidden="true">–</span>
                                <time datetime="{{ $campaign['ends_on'] }}">{{ $campaign['ends_on_label'] }}</time>
                            </p>
                        @endif
                    </div>
                    <a
                        class="home-campaign-cta"
                        href="{{ route('campaigns.show', $campaign['id']) }}"
                        aria-label="View details for {{ $campaign['name'] }}"
                    >
                        View Details <span aria-hidden="true">→</span>
                    </a>
                </div>
            </article>
        @endforeach
    </div>
</section>
@endif

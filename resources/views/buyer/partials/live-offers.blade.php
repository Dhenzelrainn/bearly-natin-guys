@php
    $offers = collect($promotions ?? []);
    $labels = [
        'voucher' => 'Voucher',
        'automatic_discount' => 'Automatic discount',
        'free_shipping' => 'Shipping offer',
        'flash_deal' => 'Flash deal',
        'seller_campaign' => 'Bearly campaign',
    ];
    $sectionEyebrow = $eyebrow ?? 'Live Bearly offers';
    $sectionHeading = $heading ?? 'More ways to save right now';
@endphp

@if ($offers->isNotEmpty())
<section class="live-platform-offers" aria-labelledby="live-platform-offers-title">
    <div class="live-platform-offers-head">
        <div class="live-platform-offers-heading">
            <p>
                <span class="material-symbols-outlined" aria-hidden="true">campaign</span>
                {{ $sectionEyebrow }}
            </p>
            <h2 id="live-platform-offers-title">{{ $sectionHeading }}</h2>
        </div>
        <a href="{{ url('/profile#vouchers') }}">
            Open My Vouchers
            <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
        </a>
    </div>

    <div class="live-platform-offers-grid">
        @foreach ($offers as $offer)
            @php
                $isCampaign = (bool) ($offer['is_campaign'] ?? false) || $offer['kind'] === 'seller_campaign';
                $href = $isCampaign
                    ? route('campaigns.show', $offer['id'])
                    : ($offer['kind'] === 'flash_deal'
                        ? url('/flash-deals')
                        : ($offer['banner_link'] ?: url('/profile#vouchers')));
                $offerLabel = $isCampaign && filled($offer['campaign_type_label'] ?? null)
                    ? $offer['campaign_type_label']
                    : ($labels[$offer['kind']] ?? 'Bearly offer');
                $startsAt = $offer['starts_at']
                    ? \Illuminate\Support\Carbon::parse($offer['starts_at'])->timezone('Asia/Manila')
                    : null;
                $endsAt = $offer['ends_at']
                    ? \Illuminate\Support\Carbon::parse($offer['ends_at'])->timezone('Asia/Manila')
                    : null;
                $isUpcoming = $startsAt?->isFuture() ?? false;
                $statusLabel = $isUpcoming ? 'Upcoming' : 'Ongoing';
                $dateLabel = match (true) {
                    $startsAt !== null && $endsAt !== null && $startsAt->isSameDay($endsAt) => $startsAt->format('M j, Y'),
                    $startsAt !== null && $endsAt !== null => $startsAt->format('M j, Y').' – '.$endsAt->format('M j, Y'),
                    $endsAt !== null => 'Until '.$endsAt->format('M j, Y'),
                    $startsAt !== null => 'From '.$startsAt->format('M j, Y'),
                    default => null,
                };
                $description = trim(strip_tags((string) ($offer['description'] ?? '')));
                $tone = $isCampaign ? 'campaign' : (string) $offer['kind'];
                $icon = match ($tone) {
                    'free_shipping' => 'local_shipping',
                    'flash_deal' => 'bolt',
                    'voucher' => 'confirmation_number',
                    'automatic_discount' => 'sell',
                    'campaign' => 'campaign',
                    default => 'local_offer',
                };
            @endphp

            <article class="live-platform-offer-card" data-offer-tone="{{ $tone }}">
                <div class="live-platform-offer-visual" aria-hidden="true">
                    @if ($offer['banner_url'])
                        <img
                            src="{{ $offer['banner_url'] }}"
                            alt=""
                            loading="{{ $loop->first ? 'eager' : 'lazy' }}"
                            decoding="async"
                        >
                    @else
                        <span class="live-platform-offer-fallback">
                            <span class="material-symbols-outlined">{{ $icon }}</span>
                            <small>{{ $offerLabel }}</small>
                        </span>
                    @endif
                </div>

                <div class="live-platform-offer-copy">
                    <div class="live-platform-offer-eyebrow">
                        <span class="live-platform-offer-status {{ $isUpcoming ? 'is-upcoming' : 'is-ongoing' }}">
                            @if ($isUpcoming)
                                <span class="material-symbols-outlined" aria-hidden="true">schedule</span>
                            @else
                                <i aria-hidden="true"></i>
                            @endif
                            {{ $statusLabel }}
                        </span>
                        <span class="live-platform-offer-kind">{{ $offerLabel }}</span>
                    </div>

                    <h3>{{ $offer['name'] }}</h3>

                    @if (filled($offer['discount_label'] ?? null))
                        <p class="live-platform-offer-value">{{ $offer['discount_label'] }}</p>
                    @endif

                    @if ($description !== '')
                        <p class="live-platform-offer-description">{{ $description }}</p>
                    @endif

                    @if ($dateLabel || filled($offer['code'] ?? null))
                        <div class="live-platform-offer-meta">
                            @if ($dateLabel)
                                <span>
                                    <span class="material-symbols-outlined" aria-hidden="true">calendar_month</span>
                                    {{ $dateLabel }}
                                </span>
                            @endif
                            @if (filled($offer['code'] ?? null))
                                <code>{{ $offer['code'] }}</code>
                            @endif
                        </div>
                    @endif

                    <a
                        class="live-platform-offer-cta {{ $isUpcoming ? 'is-secondary' : '' }}"
                        href="{{ $href }}"
                        aria-label="View details for {{ $offer['name'] }}"
                    >
                        View Details
                        <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                    </a>
                </div>
            </article>
        @endforeach
    </div>
</section>
@endif

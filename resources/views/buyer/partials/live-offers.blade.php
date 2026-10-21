@php
    $offers = $promotions ?? collect();
    $labels = [
        'voucher' => 'Voucher',
        'automatic_discount' => 'Automatic discount',
        'free_shipping' => 'Shipping offer',
        'flash_deal' => 'Flash deal',
    ];
@endphp
@if ($offers->isNotEmpty())
<section class="live-platform-offers" aria-labelledby="live-platform-offers-title">
    <div class="live-platform-offers-head">
        <div>
            <p>Live Bearly offers</p>
            <h2 id="live-platform-offers-title">More ways to save right now</h2>
        </div>
        <a href="{{ url('/profile#vouchers') }}">Open My Vouchers <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></a>
    </div>
    <div class="live-platform-offers-grid">
        @foreach ($offers as $offer)
            @php
                $href = $offer['kind'] === 'flash_deal'
                    ? url('/flash-deals')
                    : ($offer['banner_link'] ?: url('/profile#vouchers'));
                $ends = $offer['ends_at']
                    ? \Illuminate\Support\Carbon::parse($offer['ends_at'])->timezone('Asia/Manila')->format('M j, g:i A').' PHT'
                    : null;
            @endphp
            <a class="live-platform-offer" href="{{ $href }}">
                @if ($offer['banner_url'])
                    <img src="{{ $offer['banner_url'] }}" alt="" loading="lazy">
                @else
                    <span class="live-platform-offer-icon material-symbols-outlined" aria-hidden="true">
                        {{ $offer['kind'] === 'free_shipping' ? 'local_shipping' : ($offer['kind'] === 'flash_deal' ? 'bolt' : 'confirmation_number') }}
                    </span>
                @endif
                <span class="live-platform-offer-copy">
                    <small>{{ $labels[$offer['kind']] ?? 'Bearly offer' }}</small>
                    <strong>{{ $offer['name'] }}</strong>
                    <span>{{ $offer['discount_label'] }}@if ($ends) · until {{ $ends }}@endif</span>
                    @if ($offer['description'])
                        <span class="live-platform-offer-description">{{ $offer['description'] }}</span>
                    @endif
                    @if ($offer['code'])
                        <code>{{ $offer['code'] }}</code>
                    @endif
                </span>
            </a>
        @endforeach
    </div>
</section>
@endif

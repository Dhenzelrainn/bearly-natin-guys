@extends('logistics.layouts.app')

@section('title', 'Shipping settlement')
@section('page-title', 'Shipping settlement')

@section('content')
<section class="page-hero">
    <div>
        <span class="eyebrow">Provider earnings</span>
        <h1>Shipping settlement and Rider payouts</h1>
        <p>Outbound settlements wait for Seller Order eligibility checks. A return-shipping settlement becomes eligible after the approved return is delivered to the Seller. Admin/Finance must remit the exact 40/60 allocation to your provider; record the actual final Rider payout here.</p>
    </div>
</section>

<section class="metric-strip" aria-label="Logistics shipping earnings summary">
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="wallet" aria-hidden="true"></i></span>
        <span class="metric-copy">
            <small>Total eligible Logistics share</small>
            <strong>₱{{ number_format((int) $earningsSummary['total_minor'] / 100, 2) }}</strong>
            <span>Your 40% share across eligible outbound and return shipping settlements</span>
        </span>
    </article>
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="clock-3" aria-hidden="true"></i></span>
        <span class="metric-copy">
            <small>Awaiting Finance remittance</small>
            <strong>₱{{ number_format((int) $earningsSummary['pending_minor'] / 100, 2) }}</strong>
            <span>Logistics share on settlements not yet marked remitted</span>
        </span>
    </article>
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="circle-check-big" aria-hidden="true"></i></span>
        <span class="metric-copy">
            <small>Finance remitted</small>
            <strong>₱{{ number_format((int) $earningsSummary['remitted_minor'] / 100, 2) }}</strong>
            <span>40% Logistics share; Rider pool is tracked separately below</span>
        </span>
    </article>
</section>

<section class="panel" data-shipping-settlement-live data-status-url="{{ route('logistics.settlements.status-version') }}" data-status-version="{{ $earningsStatusVersion }}">
    <div class="panel-heading">
        <div>
            <span class="eyebrow">Your provider only</span>
            <h2>Rider shipping earnings</h2>
            <p>COD product cash is not Logistics or Rider revenue. This page records the Rider pool for eligible outbound deliveries and approved returns.</p>
        </div>
    </div>

    <div class="cash-custody-table-wrap" role="region" aria-label="Rider shipping earnings" tabindex="0">
        <table class="cash-custody-table">
            <thead>
                <tr>
                    <th>Shipment / Seller Order</th>
                    <th>Rider</th>
                    <th>Fee allocation</th>
                    <th>Status</th>
                    <th>Actual Rider payout</th>
                </tr>
            </thead>
            <tbody data-shipping-settlement-rows>
                @forelse($earnings as $earning)
                    @php
                        $settlement = $earning->shippingSettlement;
                        $payout = $earning->payout;
                        $isSelected = (int) old('earning_id', 0) === (int) $earning->id;
                        $sellerOrder = $earning->shipment?->sellerOrder;
                        $settlementIsReturn = $settlement?->settlement_type === 'return';
                        $returnRequest = $settlement?->returnRequest;
                        $returnWindowEndedAt = ! $settlementIsReturn && $sellerOrder?->completed_at
                            ? \Carbon\CarbonImmutable::parse($sellerOrder->completed_at)
                                ->addDays(\App\Services\ReturnRefundService::RETURN_WINDOW_DAYS)
                                ->endOfDay()
                            : null;
                        $productPreviews = $earning->product_previews ?? [];
                        $productCount = (int) ($earning->product_count ?? 0);
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $earning->shipment?->shipment_no ?? 'Shipment' }}</strong>
                            @if ($settlementIsReturn)
                                <small class="table-subline">Return {{ $returnRequest?->return_no ?? 'case unavailable' }} · {{ $sellerOrder?->store?->name ?? 'Seller' }}</small>
                            @else
                                <small class="table-subline">{{ $sellerOrder?->seller_order_no ?? 'Seller Order' }} · {{ $sellerOrder?->store?->name ?? 'Seller' }}</small>
                            @endif
                            <small class="table-subline">Settlement {{ $settlement?->settlement_no ?? 'pending' }}</small>
                            @if($productPreviews)
                                <div class="shipping-settlement-products">
                                    @include('partials.order-item-preview-list', ['items' => $productPreviews])
                                    @if($productCount > count($productPreviews))
                                        <small class="shipping-settlement-products-more">+{{ $productCount - count($productPreviews) }} more {{ $settlementIsReturn ? 'returned ' : '' }}{{ $productCount - count($productPreviews) === 1 ? 'item' : 'items' }}</small>
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td>{{ $earning->riderProfile?->user?->name ?? 'Rider' }}</td>
                        <td>
                            <strong>Rider pool: ₱{{ number_format((int) $earning->amount_minor / 100, 2) }}</strong>
                            <small class="table-subline">{{ $settlementIsReturn ? 'Return route fee' : 'Original fee' }} ₱{{ number_format((int) ($settlement?->feeAmountMinor() ?? 0) / 100, 2) }}</small>
                            <small class="table-subline">Logistics 40%: ₱{{ number_format((int) ($settlement?->logistics_share_minor ?? 0) / 100, 2) }}</small>
                        </td>
                        <td><span class="status-badge" data-status="{{ $earning->status }}">{{ str($earning->status)->replace('_', ' ')->title() }}</span>
                            @if($returnWindowEndedAt)
                                <small class="table-subline">3-day return period ended {{ $returnWindowEndedAt->format('M j, Y') }}</small>
                            @endif
                            @if($settlement?->remitted_at)
                                <small class="table-subline">Finance remitted {{ $settlement->remitted_on?->format('M j, Y') }} · ₱{{ number_format((int) $settlement->remitted_amount_minor / 100, 2) }}</small>
                                <small class="table-subline">{{ str($settlement->remittance_method)->replace('_', ' ')->title() }} · <code>{{ $settlement->remittance_reference }}</code></small>
                            @endif
                        </td>
                        <td>
                            @if($payout)
                                <strong>{{ str($payout->method)->replace('_', ' ')->title() }}</strong>
                                <small class="table-subline">₱{{ number_format((int) $payout->amount_minor / 100, 2) }} · {{ $payout->paid_on?->format('M j, Y') }}</small>
                                @if($payout->method === 'cash' && str_starts_with((string) $payout->reference, 'LRP-'))
                                    <small class="table-subline">Bearly cash payout reference: <code>{{ $payout->reference }}</code></small>
                                    <a class="button button-secondary button-small logistics-cash-payout-receipt-link" href="{{ route('logistics.settlements.rider-earnings.cash-receipt', $earning->id) }}" target="_blank" rel="noopener"><i data-lucide="printer" aria-hidden="true"></i>Print cash receipt</a>
                                @else
                                    <small class="table-subline">Reference: <code>{{ $payout->reference }}</code></small>
                                @endif
                                <small class="table-subline">Recorded by {{ $payout->recorder?->name ?? 'Logistics' }}</small>
                            @elseif($earning->status === 'pending')
                                <span class="empty-copy">Awaiting Admin/Finance remittance to your provider.</span>
                            @elseif($earning->status === 'posted')
                                <form method="POST" action="{{ route('logistics.settlements.rider-earnings.record-paid', $earning->id) }}" class="cash-action-form" data-rider-payout-form>
                                    @csrf
                                    <input type="hidden" name="earning_id" value="{{ $earning->id }}">
                                    <label for="rider-payout-amount-{{ $earning->id }}">Amount actually paid (₱)
                                        <input id="rider-payout-amount-{{ $earning->id }}" type="text" name="actual_amount" inputmode="decimal" value="{{ $isSelected && old('actual_amount') !== null ? old('actual_amount') : number_format((int) $earning->amount_minor / 100, 2, '.', '') }}" maxlength="11" required>
                                    </label>
                                    <label for="rider-payout-method-{{ $earning->id }}">Method
                                        <select id="rider-payout-method-{{ $earning->id }}" name="method" required>
                                            <option value="">Select method</option>
                                            <option value="bank_transfer" @selected($isSelected && old('method') === 'bank_transfer')>Bank transfer</option>
                                            <option value="gcash" @selected($isSelected && old('method') === 'gcash')>GCash</option>
                                            <option value="cash" @selected($isSelected && old('method') === 'cash')>Cash</option>
                                            <option value="other" @selected($isSelected && old('method') === 'other')>Other</option>
                                        </select>
                                    </label>
                                    <label for="rider-payout-reference-{{ $earning->id }}" data-external-rider-payout-reference>External payout reference
                                        <input id="rider-payout-reference-{{ $earning->id }}" type="text" name="reference" maxlength="150" value="{{ $isSelected ? old('reference') : '' }}">
                                    </label>
                                    <small class="table-subline" data-generated-rider-cash-reference hidden>Cash only: leave the external reference blank. Bearly will generate an internal receipt reference after you confirm the cash was already paid to the Rider.</small>
                                    <label for="rider-payout-date-{{ $earning->id }}">Paid on
                                        <input id="rider-payout-date-{{ $earning->id }}" type="date" name="paid_on" max="{{ now()->toDateString() }}" value="{{ $isSelected ? old('paid_on', now()->toDateString()) : now()->toDateString() }}" required>
                                    </label>
                                    <label for="rider-payout-reason-{{ $earning->id }}">Reason / note
                                        <textarea id="rider-payout-reason-{{ $earning->id }}" name="reason" minlength="5" maxlength="1000" rows="2" required>{{ $isSelected ? old('reason') : '' }}</textarea>
                                    </label>
                                    <label class="finance-confirm">
                                        <input type="checkbox" name="payout_confirmed" value="1" @checked($isSelected && old('payout_confirmed')) required>
                                        I confirm this exact amount was already paid to the Rider.
                                    </label>
                                    <button class="button button-primary button-small" type="submit">Record actual Rider payout</button>
                                </form>
                            @else
                                <span class="empty-copy">No payout details recorded.</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><p class="empty-copy">No Rider-pool earnings are currently recorded for this provider. Outbound deliveries appear after the 3-calendar-day return/refund period, payment, case, and COD checks pass. A return-shipping earning appears only after an approved return is delivered to the Seller. Admin/Finance remittance and your actual Rider payout are separate steps.</p></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

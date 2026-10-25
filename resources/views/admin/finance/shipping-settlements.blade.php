@extends('layouts.admin')

@section('title', 'Shipping Settlements')
@section('page-title', 'Shipping Settlements')

@section('content')
@php
    $pendingSettlements = $shippingSettlements->where('status', 'pending');
    $remittedSettlements = $shippingSettlements->where('status', 'remitted');
    $pendingAllocationMinor = $pendingSettlements->sum(fn ($settlement): int => (int) $settlement->logistics_share_minor + (int) $settlement->rider_share_minor);
    $remittedAllocationMinor = $remittedSettlements->sum(fn ($settlement): int => (int) ($settlement->remitted_amount_minor ?? 0));
    $riderPaidCount = $shippingSettlements->filter(fn ($settlement): bool => $settlement->riderEarning?->status === 'paid')->count();
@endphp

<section class="page-hero">
    <div>
        <span class="eyebrow">Logistics · Finance</span>
        <h1>Shipping allocations to Logistics</h1>
        <p>Record only the original shipping fee allocation: 40% to the Logistics provider and 60% to the Rider pool. COD product cash is not Logistics or Rider revenue and remains on its own custody path.</p>
    </div>
    <div class="hero-actions">
        <a class="button button-secondary" href="{{ route('admin.payments') }}"><i data-lucide="credit-card" aria-hidden="true"></i>Seller Payments &amp; COD</a>
        <a class="button button-secondary" href="{{ route('admin.seller-payouts.index') }}"><i data-lucide="wallet" aria-hidden="true"></i>Seller Payouts</a>
    </div>
</section>

<section class="kpi-grid kpi-grid-compact">
    <article class="mini-stat"><span><i data-lucide="clock-3"></i></span><div><strong>₱{{ number_format($pendingAllocationMinor / 100, 2) }}</strong><small>Awaiting Finance remittance</small></div></article>
    <article class="mini-stat"><span><i data-lucide="circle-check-big"></i></span><div><strong>₱{{ number_format($remittedAllocationMinor / 100, 2) }}</strong><small>Remitted to Logistics</small></div></article>
    <article class="mini-stat"><span><i data-lucide="bike"></i></span><div><strong>{{ $riderPaidCount }}/{{ $shippingSettlements->count() }}</strong><small>Rider pools paid by Logistics</small></div></article>
</section>

<section class="panel">
    <div class="panel-heading">
        <div>
            <span class="eyebrow">Eligible zone-priced shipments</span>
            <h2>Shipping settlement ledger</h2>
            <p>Rows appear only after delivery and all settlement checks pass, including the 3-calendar-day return/refund period from Seller Order completion, payment, no unresolved case, and exact Finance reconciliation for every positive-COD outbound shipment. Passing the period is not a payment date: Finance must still record the remittance. Legacy flat-fee and other non-zone quotes are excluded.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="admin-table">
            <thead><tr><th>Shipment / Seller Order</th><th>Provider / Rider</th><th>Fee funding</th><th>40% / 60% split</th><th>Remittance status</th><th>Finance action</th></tr></thead>
            <tbody>
            @forelse($shippingSettlements as $settlement)
                @php
                    $isSelectedShippingSettlement = (int) old('settlement_id', 0) === (int) $settlement->id;
                    $allocationMinor = (int) $settlement->logistics_share_minor + (int) $settlement->rider_share_minor;
                    $riderPayout = $settlement->riderEarning?->payout;
                @endphp
                <tr>
                    <td>
                        <strong>{{ $settlement->shipment?->shipment_no ?? 'Shipment' }}</strong>
                        <small class="table-subline">{{ $settlement->sellerOrder?->seller_order_no ?? 'Seller Order' }} · {{ $settlement->sellerOrder?->store?->name ?? 'Seller' }}</small>
                        <small class="table-subline">{{ $settlement->settlement_no }}</small>
                    </td>
                    <td>
                        {{ $settlement->logisticsProfile?->display_name ?? 'Logistics provider' }}
                        <small class="table-subline">Final delivery Rider: {{ $settlement->riderProfile?->user?->name ?? 'Rider' }}</small>
                    </td>
                    <td>
                        <dl class="finance-cash-meta">
                            <div><dt>Original zone fee</dt><dd>₱{{ number_format((int) $settlement->original_shipping_fee_minor / 100, 2) }} · Zone {{ (int) $settlement->zone_level }}</dd></div>
                            <div><dt>Buyer paid</dt><dd>₱{{ number_format((int) $settlement->buyer_paid_shipping_fee_minor / 100, 2) }}</dd></div>
                            <div><dt>Bearly subsidy</dt><dd>₱{{ number_format((int) $settlement->platform_shipping_subsidy_minor / 100, 2) }} · {{ str($settlement->shipping_funding_source)->replace('_', ' ')->title() }}</dd></div>
                        </dl>
                    </td>
                    <td>
                        <strong>₱{{ number_format((int) $settlement->logistics_share_minor / 100, 2) }}</strong>
                        <small class="table-subline">Logistics · 40%</small>
                        <strong>₱{{ number_format((int) $settlement->rider_share_minor / 100, 2) }}</strong>
                        <small class="table-subline">Rider pool · 60%</small>
                    </td>
                    <td>
                        <span class="status-badge {{ $settlement->status === 'remitted' ? 'badge-success' : 'badge-warning' }}">{{ str($settlement->status)->replace('_', ' ')->title() }}</span>
                        @if($settlement->remitted_at)
                            <small class="table-subline">Sent {{ $settlement->remitted_on?->format('M j, Y') }} by {{ $settlement->remitter?->name ?? 'Finance' }}</small>
                            <small class="table-subline">{{ str($settlement->remittance_method)->replace('_', ' ')->title() }} · <code>{{ $settlement->remittance_reference }}</code></small>
                            @if($riderPayout)
                                <small class="table-subline">Rider paid {{ $riderPayout->paid_on?->format('M j, Y') }} · {{ str($riderPayout->method)->replace('_', ' ')->title() }} · ₱{{ number_format((int) $riderPayout->amount_minor / 100, 2) }}</small>
                                <small class="table-subline">Rider reference: <code>{{ $riderPayout->reference }}</code></small>
                            @else
                                <small class="table-subline">Rider payout: Awaiting Logistics</small>
                            @endif
                        @endif
                    </td>
                    <td>
                        @if($settlement->status === 'pending')
                            <form method="POST" action="{{ route('admin.shipping-settlements.record-paid', $settlement) }}" class="finance-action-form">
                                @csrf
                                <input type="hidden" name="settlement_id" value="{{ $settlement->id }}">
                                <label for="shipping-remit-amount-{{ $settlement->id }}">Amount actually sent (₱)
                                    <input id="shipping-remit-amount-{{ $settlement->id }}" type="text" name="actual_amount" inputmode="decimal" value="{{ $isSelectedShippingSettlement && old('actual_amount') !== null ? old('actual_amount') : number_format($allocationMinor / 100, 2, '.', '') }}" required>
                                </label>
                                <label for="shipping-remit-method-{{ $settlement->id }}">Method
                                    <select id="shipping-remit-method-{{ $settlement->id }}" name="method" required>
                                        <option value="">Select method</option>
                                        <option value="bank_transfer" @selected($isSelectedShippingSettlement && old('method') === 'bank_transfer')>Bank transfer</option>
                                        <option value="gcash" @selected($isSelectedShippingSettlement && old('method') === 'gcash')>GCash</option>
                                        <option value="cash" @selected($isSelectedShippingSettlement && old('method') === 'cash')>Cash</option>
                                        <option value="other" @selected($isSelectedShippingSettlement && old('method') === 'other')>Other</option>
                                    </select>
                                </label>
                                <label for="shipping-remit-reference-{{ $settlement->id }}">Actual transfer reference
                                    <input id="shipping-remit-reference-{{ $settlement->id }}" type="text" name="reference" maxlength="150" value="{{ $isSelectedShippingSettlement ? old('reference') : '' }}" required>
                                </label>
                                <label for="shipping-remit-date-{{ $settlement->id }}">Sent on
                                    <input id="shipping-remit-date-{{ $settlement->id }}" type="date" name="paid_on" max="{{ now()->toDateString() }}" value="{{ $isSelectedShippingSettlement ? old('paid_on', now()->toDateString()) : now()->toDateString() }}" required>
                                </label>
                                <label for="shipping-remit-reason-{{ $settlement->id }}">Reason / note
                                    <textarea id="shipping-remit-reason-{{ $settlement->id }}" name="reason" minlength="5" maxlength="1000" rows="2" required>{{ $isSelectedShippingSettlement ? old('reason') : '' }}</textarea>
                                </label>
                                <label class="finance-confirm"><input type="checkbox" name="remittance_confirmed" value="1" @checked($isSelectedShippingSettlement && old('remittance_confirmed')) required> I confirm Finance already sent exactly ₱{{ number_format($allocationMinor / 100, 2) }} to this provider.</label>
                                <button class="button button-primary button-small" type="submit">Record remittance</button>
                            </form>
                        @else
                            <span class="empty-copy">Remittance recorded. Logistics must record the actual Rider payout separately.</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><p class="empty-copy">No eligible shipping allocations are recorded yet. A zone-priced shipment appears here only after delivery and all Seller Order settlement checks pass. Delivery history, COD product cash, and legacy flat-fee or other non-zone quotes do not create a row here.</p></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

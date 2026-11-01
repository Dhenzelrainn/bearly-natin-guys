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
        <p>Record the outbound or approved return-shipping allocation: 40% to the Logistics provider and 60% to the final-delivery Rider pool. COD product cash is not Logistics or Rider revenue and remains on its own custody path.</p>
    </div>
    <div class="hero-actions">
        <a class="button button-secondary" href="{{ route('admin.payments') }}"><i data-lucide="credit-card" aria-hidden="true"></i>Seller Payments &amp; COD</a>
        <a class="button button-secondary" href="{{ route('admin.seller-payouts.index') }}"><i data-lucide="wallet" aria-hidden="true"></i>Seller Payouts</a>
    </div>
</section>

@if($pendingPayoutAccounts->isNotEmpty())
    <section class="panel">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">Finance review required</span>
                <h2>Logistics payout destinations</h2>
                <p>Review the full destination and independently confirm it with the provider before verification. Only verified destinations can be selected for bank or GCash remittance.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead><tr><th>Logistics provider</th><th>Method</th><th>Account holder</th><th>Destination</th><th>Submitted</th><th>Review</th></tr></thead>
                <tbody>
                    @foreach($pendingPayoutAccounts as $payoutAccount)
                        <tr>
                            <td>{{ $payoutAccount->logisticsProfile?->display_name ?? 'Logistics provider' }}</td>
                            <td>{{ $payoutAccount->channel === 'gcash' ? 'GCash' : 'Bank transfer' }}<small class="table-subline">{{ $payoutAccount->provider_name }}</small></td>
                            <td>{{ $payoutAccount->account_name }}</td>
                            <td>{{ $payoutAccount->maskedDestination() }}</td>
                            <td>{{ $payoutAccount->created_at?->format('M j, Y g:i A') }}</td>
                            <td><a class="button button-secondary button-small" href="{{ route('admin.logistics-payout-accounts.show', $payoutAccount->id) }}">Review details</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

<section class="kpi-grid kpi-grid-compact">
    <article class="mini-stat"><span><i data-lucide="clock-3"></i></span><div><strong>₱{{ number_format($pendingAllocationMinor / 100, 2) }}</strong><small>Awaiting Finance remittance</small></div></article>
    <article class="mini-stat"><span><i data-lucide="circle-check-big"></i></span><div><strong>₱{{ number_format($remittedAllocationMinor / 100, 2) }}</strong><small>Remitted to Logistics</small></div></article>
    <article class="mini-stat"><span><i data-lucide="bike"></i></span><div><strong>{{ $riderPaidCount }}/{{ $shippingSettlements->count() }}</strong><small>Rider pools paid by Logistics</small></div></article>
</section>

<section class="panel">
    <div class="panel-heading">
        <div>
            <span class="eyebrow">Eligible outbound and return shipments</span>
            <h2>Shipping settlement ledger</h2>
            <p>Outbound rows appear after the Seller Order return window, payment, return/refund, and COD reconciliation checks pass. Return rows appear after an approved physical return is delivered to the Seller. Finance must record each exact remittance before Logistics can record the Rider payout. Return-delivery fees are separate from the full Buyer refund.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="admin-table">
            <thead><tr><th>Shipment / order or return</th><th>Provider / Rider</th><th>Fee funding</th><th>40% / 60% split</th><th>Remittance status</th><th>Finance action</th></tr></thead>
            <tbody>
            @forelse($shippingSettlements as $settlement)
                @php
                    $isSelectedShippingSettlement = (int) old('settlement_id', 0) === (int) $settlement->id;
                    $allocationMinor = (int) $settlement->logistics_share_minor + (int) $settlement->rider_share_minor;
                    $riderPayout = $settlement->riderEarning?->payout;
                    $settlementPayoutAccounts = $payoutAccountsByProvider->get((int) $settlement->logistics_profile_id, collect());
                @endphp
                <tr>
                    <td class="finance-shipping-settlement-products-cell">
                        <strong>{{ $settlement->shipment?->shipment_no ?? 'Shipment' }}</strong>
                        @if ($settlement->settlement_type === 'return')
                            <small class="table-subline">Return {{ $settlement->returnRequest?->return_no ?? 'case unavailable' }} · {{ $settlement->sellerOrder?->store?->name ?? 'Seller' }}</small>
                        @else
                            <small class="table-subline">{{ $settlement->sellerOrder?->seller_order_no ?? 'Seller Order' }} · {{ $settlement->sellerOrder?->store?->name ?? 'Seller' }}</small>
                        @endif
                        <small class="table-subline">{{ $settlement->settlement_no }}</small>
                        @if($settlement->product_previews)
                            <div class="finance-shipping-settlement-products">
                                @include('partials.order-item-preview-list', ['items' => $settlement->product_previews])
                                @if($settlement->product_count > count($settlement->product_previews))
                                    @php($additionalItems = $settlement->product_count - count($settlement->product_previews))
                                    <small class="finance-shipping-settlement-products-more">+{{ $additionalItems }} more {{ $settlement->settlement_type === 'return' ? 'returned ' : '' }}{{ $additionalItems === 1 ? 'item' : 'items' }}</small>
                                @endif
                            </div>
                        @endif
                    </td>
                    <td>
                        {{ $settlement->logisticsProfile?->display_name ?? 'Logistics provider' }}
                        <small class="table-subline">Final delivery Rider: {{ $settlement->riderProfile?->user?->name ?? 'Rider' }}</small>
                    </td>
                    <td>
                        <dl class="finance-cash-meta">
                            @if ($settlement->settlement_type === 'return')
                                <div><dt>Return route fee</dt><dd>₱{{ number_format($settlement->feeAmountMinor() / 100, 2) }} · Zone {{ (int) $settlement->zone_level }}</dd></div>
                                <div><dt>Route</dt><dd>{{ data_get($settlement->shipment?->return_shipping_quote, 'origin.city_municipality', 'Buyer city') }} → {{ data_get($settlement->shipment?->return_shipping_quote, 'destination.city_municipality', 'Seller city') }}</dd></div>
                                <div><dt>Responsible party</dt><dd>{{ ucfirst((string) $settlement->financially_responsible_party) }} · Finance advances the provider remittance</dd></div>
                                <div><dt>Buyer refund</dt><dd>Full approved amount; no return-fee deduction</dd></div>
                            @else
                                <div><dt>Original zone fee</dt><dd>₱{{ number_format($settlement->feeAmountMinor() / 100, 2) }} · Zone {{ (int) $settlement->zone_level }}</dd></div>
                                <div><dt>Buyer paid</dt><dd>₱{{ number_format((int) $settlement->buyer_paid_shipping_fee_minor / 100, 2) }}</dd></div>
                                <div><dt>Bearly subsidy</dt><dd>₱{{ number_format((int) $settlement->platform_shipping_subsidy_minor / 100, 2) }} · {{ str($settlement->shipping_funding_source)->replace('_', ' ')->title() }}</dd></div>
                            @endif
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
                            @if($settlement->remittance_method === 'cash' && str_starts_with((string) $settlement->remittance_reference, 'SRM-'))
                                <small class="table-subline">Bearly cash handoff reference: <code>{{ $settlement->remittance_reference }}</code></small>
                                <a class="button button-secondary button-small finance-cash-receipt-link" href="{{ route('admin.shipping-settlements.cash-receipt', $settlement) }}" target="_blank" rel="noopener"><i data-lucide="printer" aria-hidden="true"></i>Print cash receipt</a>
                            @elseif($settlement->remittance_method === 'cash')
                                <small class="table-subline">Previously recorded cash reference: <code>{{ $settlement->remittance_reference }}</code></small>
                            @else
                                <small class="table-subline">{{ str($settlement->remittance_method)->replace('_', ' ')->title() }} · <code>{{ $settlement->remittance_reference }}</code></small>
                            @endif
                            @if($settlement->logistics_payout_account_id)
                                <small class="table-subline">Sent to {{ $settlement->remitted_to_provider }} · {{ $settlement->remitted_to_account_name }} · •••• {{ $settlement->remitted_to_last4 }}</small>
                                <a class="table-subline" href="{{ route('admin.logistics-payout-accounts.show', $settlement->logistics_payout_account_id) }}" target="_blank" rel="noopener">View destination record</a>
                            @endif
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
                            <form method="POST" action="{{ route('admin.shipping-settlements.record-paid', $settlement) }}" class="finance-action-form" data-shipping-remittance-form>
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
                                <label for="shipping-remit-payout-account-{{ $settlement->id }}" data-logistics-payout-account-field>
                                    Verified destination
                                    <select id="shipping-remit-payout-account-{{ $settlement->id }}" name="logistics_payout_account_id" data-logistics-payout-account-select>
                                        <option value="">Choose a verified destination</option>
                                        @foreach($settlementPayoutAccounts as $payoutAccount)
                                            <option value="{{ $payoutAccount->id }}" data-channel="{{ $payoutAccount->channel }}" @selected($isSelectedShippingSettlement && (int) old('logistics_payout_account_id') === (int) $payoutAccount->id)>
                                                {{ $payoutAccount->provider_name }} · {{ $payoutAccount->account_name }} · {{ $payoutAccount->maskedDestination() }}
                                            </option>
                                        @endforeach
                                    </select>
                                </label>
                                @if($settlementPayoutAccounts->isNotEmpty())
                                    <small class="table-subline">Open the selected verified destination to view the full details before transferring:</small>
                                    @foreach($settlementPayoutAccounts as $payoutAccount)
                                        <a class="table-subline" href="{{ route('admin.logistics-payout-accounts.show', $payoutAccount->id) }}" target="_blank" rel="noopener">View {{ $payoutAccount->provider_name }} destination ending in {{ $payoutAccount->destination_last4 }}</a>
                                    @endforeach
                                @else
                                    <small class="table-subline">This provider has no verified bank or GCash destination. Finance may use Cash or another separately documented method.</small>
                                @endif
                                <label for="shipping-remit-reference-{{ $settlement->id }}" data-external-transfer-reference>External transfer reference
                                    <input id="shipping-remit-reference-{{ $settlement->id }}" type="text" name="reference" maxlength="150" value="{{ $isSelectedShippingSettlement ? old('reference') : '' }}">
                                    <small>Enter the reference from the GCash, bank, or other payment receipt.</small>
                                </label>
                                <small class="table-subline" data-generated-cash-reference hidden>Cash only: leave the transfer reference blank. Bearly will generate an internal handoff reference after you confirm the cash was physically handed to Logistics.</small>
                                <label for="shipping-remit-date-{{ $settlement->id }}">Sent on
                                    <input id="shipping-remit-date-{{ $settlement->id }}" type="date" name="paid_on" max="{{ now()->toDateString() }}" value="{{ $isSelectedShippingSettlement ? old('paid_on', now()->toDateString()) : now()->toDateString() }}" required>
                                </label>
                                <label for="shipping-remit-reason-{{ $settlement->id }}">Reason / note
                                    <textarea id="shipping-remit-reason-{{ $settlement->id }}" name="reason" minlength="5" maxlength="1000" rows="2" required>{{ $isSelectedShippingSettlement ? old('reason') : '' }}</textarea>
                                </label>
                                <label class="finance-confirm"><input type="checkbox" name="remittance_confirmed" value="1" @checked($isSelectedShippingSettlement && old('remittance_confirmed')) required> I confirm Finance already transferred or physically handed exactly ₱{{ number_format($allocationMinor / 100, 2) }} to this provider.</label>
                                <button class="button button-primary button-small" type="submit">Record remittance</button>
                            </form>
                        @else
                            <span class="empty-copy">Remittance recorded. Logistics must record the actual Rider payout separately.</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><p class="empty-copy">No eligible shipping allocations are recorded yet. Outbound shipments appear after their Seller Order settlement checks pass; approved physical returns appear after delivery to the Seller. COD product cash is not a shipping settlement.</p></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

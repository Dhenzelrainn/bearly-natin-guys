@extends('layouts.admin')

@section('title', 'Shipping Settlements')
@section('page-title', 'Shipping Settlements')

@section('content')
@php
    $pendingAllocationMinor = $shippingSettlements->sum(fn ($settlement): int => $settlement->pendingRemittanceMinor());
    $remittedAllocationMinor = $shippingSettlements->sum(fn ($settlement): int => $settlement->remittedAmountTotalMinor());
    $riderPaidCount = $shippingSettlements->filter(fn ($settlement): bool => $settlement->riderEarning?->status === 'paid')->count();
@endphp

<section class="page-hero">
    <div>
        <span class="eyebrow">Logistics · Finance</span>
        <h1>Shipping allocations to Logistics</h1>
        <p>Single-provider outbound delivery and approved returns keep the 40% Logistics / 60% Rider split. Inter-hub outbound delivery is split 10% to origin Logistics, 30% to destination Logistics, and 60% to the final Rider; Finance records separate 10% and 90% provider remittances. COD product cash remains on its own custody path.</p>
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
            <p>Outbound rows appear after the Seller Order return window, payment, return/refund, and COD reconciliation checks pass. For inter-hub shipments, record both exact provider remittances before Logistics can pay the Rider through its existing payout workflow. Return rows appear after an approved physical return is delivered to the Seller and retain 40/60.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="admin-table">
            <thead><tr><th>Shipment / order or return</th><th>Providers / Rider</th><th>Fee funding</th><th>Provider / Rider allocation</th><th>Remittance status</th><th>Finance action</th></tr></thead>
            <tbody>
            @forelse($shippingSettlements as $settlement)
                @php
                    $isInterHub = $settlement->isInterHub();
                    $originRemittance = $settlement->originRemittance;
                    $originRemitted = $originRemittance?->status === 'remitted';
                    $finalRemitted = $settlement->remitted_at !== null;
                    $finalRemittanceMinor = $settlement->expectedFinalLogisticsRemittanceMinor();
                    $isSelectedFinal = (int) old('settlement_id', 0) === (int) $settlement->id
                        && old('recipient', 'final') === 'final';
                    $isSelectedOrigin = (int) old('settlement_id', 0) === (int) $settlement->id
                        && old('recipient') === 'origin';
                    $riderPayout = $settlement->riderEarning?->payout;
                    $settlementPayoutAccounts = $payoutAccountsByProvider->get((int) $settlement->logistics_profile_id, collect());
                    $originPayoutAccounts = $payoutAccountsByProvider->get((int) ($settlement->origin_logistics_profile_id ?? 0), collect());
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
                        @if($isInterHub)
                            <strong>Origin: {{ $settlement->originLogisticsProfile?->display_name ?? 'Logistics provider' }}</strong>
                            <small class="table-subline">Destination: {{ $settlement->logisticsProfile?->display_name ?? 'Logistics provider' }}</small>
                        @else
                            {{ $settlement->logisticsProfile?->display_name ?? 'Logistics provider' }}
                        @endif
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
                        @if($isInterHub)
                            <strong>₱{{ number_format((int) $settlement->origin_logistics_share_minor / 100, 2) }}</strong>
                            <small class="table-subline">Origin Logistics · 10%</small>
                            <strong>₱{{ number_format((int) $settlement->logistics_share_minor / 100, 2) }}</strong>
                            <small class="table-subline">Destination Logistics · 30%</small>
                            <strong>₱{{ number_format((int) $settlement->rider_share_minor / 100, 2) }}</strong>
                            <small class="table-subline">Final Rider · 60%</small>
                        @else
                            <strong>₱{{ number_format((int) $settlement->logistics_share_minor / 100, 2) }}</strong>
                            <small class="table-subline">Logistics · 40%</small>
                            <strong>₱{{ number_format((int) $settlement->rider_share_minor / 100, 2) }}</strong>
                            <small class="table-subline">Rider pool · 60%</small>
                        @endif
                    </td>
                    <td>
                        <span class="status-badge {{ $settlement->status === 'remitted' ? 'badge-success' : 'badge-warning' }}">{{ str($settlement->status)->replace('_', ' ')->title() }}</span>
                        @if($isInterHub)
                            @if($originRemitted)
                                <small class="table-subline">Origin L1 sent ₱{{ number_format((int) $originRemittance->amount_minor / 100, 2) }} · {{ $originRemittance->remitted_on?->format('M j, Y') }} by {{ $originRemittance->remitter?->name ?? 'Finance' }}</small>
                                <small class="table-subline">{{ str($originRemittance->remittance_method)->replace('_', ' ')->title() }} · <code>{{ $originRemittance->remittance_reference }}</code></small>
                                @if($originRemittance->remittance_method === 'cash' && str_starts_with((string) $originRemittance->remittance_reference, 'SRM-'))
                                    <a class="button button-secondary button-small finance-cash-receipt-link" href="{{ route('admin.shipping-settlements.cash-receipt', ['settlement' => $settlement, 'recipient' => 'origin']) }}" target="_blank" rel="noopener"><i data-lucide="printer" aria-hidden="true"></i>Print L1 cash receipt</a>
                                @endif
                                @if($originRemittance->logistics_payout_account_id)
                                    <small class="table-subline">Sent to {{ $originRemittance->remitted_to_provider }} · {{ $originRemittance->remitted_to_account_name }} · •••• {{ $originRemittance->remitted_to_last4 }}</small>
                                @endif
                            @else
                                <small class="table-subline">Origin L1 remittance pending · ₱{{ number_format((int) $settlement->origin_logistics_share_minor / 100, 2) }}</small>
                            @endif
                        @endif
                        @if($finalRemitted)
                            <small class="table-subline">{{ $isInterHub ? 'Destination L2' : 'Logistics' }} sent ₱{{ number_format((int) $settlement->remitted_amount_minor / 100, 2) }} · {{ $settlement->remitted_on?->format('M j, Y') }} by {{ $settlement->remitter?->name ?? 'Finance' }}</small>
                            @if($settlement->remittance_method === 'cash' && str_starts_with((string) $settlement->remittance_reference, 'SRM-'))
                                <small class="table-subline">Bearly cash handoff reference: <code>{{ $settlement->remittance_reference }}</code></small>
                                <a class="button button-secondary button-small finance-cash-receipt-link" href="{{ route('admin.shipping-settlements.cash-receipt', ['settlement' => $settlement, 'recipient' => 'final']) }}" target="_blank" rel="noopener"><i data-lucide="printer" aria-hidden="true"></i>Print cash receipt</a>
                            @else
                                <small class="table-subline">{{ str($settlement->remittance_method)->replace('_', ' ')->title() }} · <code>{{ $settlement->remittance_reference }}</code></small>
                            @endif
                            @if($settlement->logistics_payout_account_id)
                                <small class="table-subline">Sent to {{ $settlement->remitted_to_provider }} · {{ $settlement->remitted_to_account_name }} · •••• {{ $settlement->remitted_to_last4 }}</small>
                                <a class="table-subline" href="{{ route('admin.logistics-payout-accounts.show', $settlement->logistics_payout_account_id) }}" target="_blank" rel="noopener">View destination record</a>
                            @endif
                        @else
                            <small class="table-subline">{{ $isInterHub ? 'Destination L2' : 'Logistics' }} remittance pending · ₱{{ number_format($finalRemittanceMinor / 100, 2) }}</small>
                        @endif
                        @if($riderPayout)
                            <small class="table-subline">Rider paid {{ $riderPayout->paid_on?->format('M j, Y') }} · {{ str($riderPayout->method)->replace('_', ' ')->title() }} · ₱{{ number_format((int) $riderPayout->amount_minor / 100, 2) }}</small>
                            <small class="table-subline">Rider reference: <code>{{ $riderPayout->reference }}</code></small>
                        @else
                            <small class="table-subline">Rider payout: Awaiting Logistics</small>
                        @endif
                    </td>
                    <td>
                        @if(!$finalRemitted)
                            <form method="POST" action="{{ route('admin.shipping-settlements.record-paid', $settlement) }}" class="finance-action-form" data-shipping-remittance-form>
                                @csrf
                                <input type="hidden" name="settlement_id" value="{{ $settlement->id }}">
                                <input type="hidden" name="recipient" value="final">
                                <strong>{{ $isInterHub ? 'Destination L2 · 90% including Rider pool' : ($settlement->settlement_type === 'return' ? 'Logistics · full 40/60 allocation' : 'Logistics · full 40/60 allocation') }}</strong>
                                <label for="shipping-remit-amount-{{ $settlement->id }}-final">Amount actually sent to {{ $settlement->logisticsProfile?->display_name ?? 'Logistics' }} (₱)
                                    <input id="shipping-remit-amount-{{ $settlement->id }}-final" type="text" name="actual_amount" inputmode="decimal" value="{{ $isSelectedFinal && old('actual_amount') !== null ? old('actual_amount') : number_format($finalRemittanceMinor / 100, 2, '.', '') }}" required>
                                </label>
                                <label for="shipping-remit-method-{{ $settlement->id }}-final">Method
                                    <select id="shipping-remit-method-{{ $settlement->id }}-final" name="method" required>
                                        <option value="">Select method</option>
                                        <option value="bank_transfer" @selected($isSelectedFinal && old('method') === 'bank_transfer')>Bank transfer</option>
                                        <option value="gcash" @selected($isSelectedFinal && old('method') === 'gcash')>GCash</option>
                                        <option value="cash" @selected($isSelectedFinal && old('method') === 'cash')>Cash</option>
                                        <option value="other" @selected($isSelectedFinal && old('method') === 'other')>Other</option>
                                    </select>
                                </label>
                                <label for="shipping-remit-payout-account-{{ $settlement->id }}-final" data-logistics-payout-account-field>
                                    Verified destination
                                    <select id="shipping-remit-payout-account-{{ $settlement->id }}-final" name="logistics_payout_account_id" data-logistics-payout-account-select>
                                        <option value="">Choose a verified destination</option>
                                        @foreach($settlementPayoutAccounts as $payoutAccount)
                                            <option value="{{ $payoutAccount->id }}" data-channel="{{ $payoutAccount->channel }}" @selected($isSelectedFinal && (int) old('logistics_payout_account_id') === (int) $payoutAccount->id)>
                                                {{ $payoutAccount->provider_name }} · {{ $payoutAccount->account_name }} · {{ $payoutAccount->maskedDestination() }}
                                            </option>
                                        @endforeach
                                    </select>
                                </label>
                                @if($settlementPayoutAccounts->isNotEmpty())
                                    @foreach($settlementPayoutAccounts as $payoutAccount)
                                        <a class="table-subline" href="{{ route('admin.logistics-payout-accounts.show', $payoutAccount->id) }}" target="_blank" rel="noopener">View {{ $payoutAccount->provider_name }} destination ending in {{ $payoutAccount->destination_last4 }}</a>
                                    @endforeach
                                @else
                                    <small class="table-subline">This provider has no verified bank or GCash destination. Finance may use Cash or another documented method.</small>
                                @endif
                                <label for="shipping-remit-reference-{{ $settlement->id }}-final" data-external-transfer-reference>External transfer reference
                                    <input id="shipping-remit-reference-{{ $settlement->id }}-final" type="text" name="reference" maxlength="150" value="{{ $isSelectedFinal ? old('reference') : '' }}">
                                </label>
                                <small class="table-subline" data-generated-cash-reference hidden>Cash only: leave the transfer reference blank. Bearly generates an internal handoff reference after Finance confirms cash was physically handed to the provider.</small>
                                <label for="shipping-remit-date-{{ $settlement->id }}-final">Sent on
                                    <input id="shipping-remit-date-{{ $settlement->id }}-final" type="date" name="paid_on" max="{{ now()->toDateString() }}" value="{{ $isSelectedFinal ? old('paid_on', now()->toDateString()) : now()->toDateString() }}" required>
                                </label>
                                <label for="shipping-remit-reason-{{ $settlement->id }}-final">Reason / note
                                    <textarea id="shipping-remit-reason-{{ $settlement->id }}-final" name="reason" minlength="5" maxlength="1000" rows="2" required>{{ $isSelectedFinal ? old('reason') : '' }}</textarea>
                                </label>
                                <label class="finance-confirm"><input type="checkbox" name="remittance_confirmed" value="1" @checked($isSelectedFinal && old('remittance_confirmed')) required> I confirm Finance already sent exactly ₱{{ number_format($finalRemittanceMinor / 100, 2) }} to this provider.</label>
                                <button class="button button-primary button-small" type="submit">Record {{ $isInterHub ? 'L2' : 'Logistics' }} remittance</button>
                            </form>
                        @endif

                        @if($isInterHub && !$originRemitted)
                            <form method="POST" action="{{ route('admin.shipping-settlements.record-paid', $settlement) }}" class="finance-action-form" data-shipping-remittance-form>
                                @csrf
                                <input type="hidden" name="settlement_id" value="{{ $settlement->id }}">
                                <input type="hidden" name="recipient" value="origin">
                                <strong>Origin L1 · 10%</strong>
                                <label for="shipping-remit-amount-{{ $settlement->id }}-origin">Amount actually sent to {{ $settlement->originLogisticsProfile?->display_name ?? 'origin Logistics' }} (₱)
                                    <input id="shipping-remit-amount-{{ $settlement->id }}-origin" type="text" name="actual_amount" inputmode="decimal" value="{{ $isSelectedOrigin && old('actual_amount') !== null ? old('actual_amount') : number_format((int) $settlement->origin_logistics_share_minor / 100, 2, '.', '') }}" required>
                                </label>
                                <label for="shipping-remit-method-{{ $settlement->id }}-origin">Method
                                    <select id="shipping-remit-method-{{ $settlement->id }}-origin" name="method" required>
                                        <option value="">Select method</option>
                                        <option value="bank_transfer" @selected($isSelectedOrigin && old('method') === 'bank_transfer')>Bank transfer</option>
                                        <option value="gcash" @selected($isSelectedOrigin && old('method') === 'gcash')>GCash</option>
                                        <option value="cash" @selected($isSelectedOrigin && old('method') === 'cash')>Cash</option>
                                        <option value="other" @selected($isSelectedOrigin && old('method') === 'other')>Other</option>
                                    </select>
                                </label>
                                <label for="shipping-remit-payout-account-{{ $settlement->id }}-origin" data-logistics-payout-account-field>
                                    Origin provider's verified destination
                                    <select id="shipping-remit-payout-account-{{ $settlement->id }}-origin" name="logistics_payout_account_id" data-logistics-payout-account-select>
                                        <option value="">Choose a verified destination</option>
                                        @foreach($originPayoutAccounts as $payoutAccount)
                                            <option value="{{ $payoutAccount->id }}" data-channel="{{ $payoutAccount->channel }}" @selected($isSelectedOrigin && (int) old('logistics_payout_account_id') === (int) $payoutAccount->id)>
                                                {{ $payoutAccount->provider_name }} · {{ $payoutAccount->account_name }} · {{ $payoutAccount->maskedDestination() }}
                                            </option>
                                        @endforeach
                                    </select>
                                </label>
                                @if($originPayoutAccounts->isNotEmpty())
                                    @foreach($originPayoutAccounts as $payoutAccount)
                                        <a class="table-subline" href="{{ route('admin.logistics-payout-accounts.show', $payoutAccount->id) }}" target="_blank" rel="noopener">View origin {{ $payoutAccount->provider_name }} destination ending in {{ $payoutAccount->destination_last4 }}</a>
                                    @endforeach
                                @else
                                    <small class="table-subline">Origin provider has no verified bank or GCash destination. Finance may use Cash or another documented method.</small>
                                @endif
                                <label for="shipping-remit-reference-{{ $settlement->id }}-origin" data-external-transfer-reference>External transfer reference
                                    <input id="shipping-remit-reference-{{ $settlement->id }}-origin" type="text" name="reference" maxlength="150" value="{{ $isSelectedOrigin ? old('reference') : '' }}">
                                </label>
                                <small class="table-subline" data-generated-cash-reference hidden>Cash only: leave the transfer reference blank. Bearly generates an internal handoff reference after Finance confirms cash was physically handed to the provider.</small>
                                <label for="shipping-remit-date-{{ $settlement->id }}-origin">Sent on
                                    <input id="shipping-remit-date-{{ $settlement->id }}-origin" type="date" name="paid_on" max="{{ now()->toDateString() }}" value="{{ $isSelectedOrigin ? old('paid_on', now()->toDateString()) : now()->toDateString() }}" required>
                                </label>
                                <label for="shipping-remit-reason-{{ $settlement->id }}-origin">Reason / note
                                    <textarea id="shipping-remit-reason-{{ $settlement->id }}-origin" name="reason" minlength="5" maxlength="1000" rows="2" required>{{ $isSelectedOrigin ? old('reason') : '' }}</textarea>
                                </label>
                                <label class="finance-confirm"><input type="checkbox" name="remittance_confirmed" value="1" @checked($isSelectedOrigin && old('remittance_confirmed')) required> I confirm Finance already sent exactly ₱{{ number_format((int) $settlement->origin_logistics_share_minor / 100, 2) }} to this provider.</label>
                                <button class="button button-primary button-small" type="submit">Record L1 remittance</button>
                            </form>
                        @endif

                        @if($finalRemitted && (!$isInterHub || $originRemitted))
                            <span class="empty-copy">All Finance remittances are recorded. Logistics may pay the Rider through the existing payout workflow.</span>
                        @elseif($finalRemitted || $originRemitted)
                            <span class="empty-copy">One provider remittance is recorded. The Rider earning stays pending until both provider remittances are complete.</span>
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

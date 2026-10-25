@extends('logistics.layouts.app')

@section('title', 'Shipping settlement')
@section('page-title', 'Shipping settlement')

@section('content')
<section class="page-hero">
    <div>
        <span class="eyebrow">Provider earnings</span>
        <h1>Shipping settlement and Rider payouts</h1>
        <p>A shipment becomes settlement-eligible only after Seller Order completion, the 3-calendar-day return/refund period, and payment/COD checks. Admin/Finance must then remit the original shipping allocation to your provider. Record the actual Rider-pool payout here; the Rider earning becomes Paid only after you confirm it.</p>
    </div>
</section>

<section class="panel">
    <div class="panel-heading">
        <div>
            <span class="eyebrow">Your provider only</span>
            <h2>Rider shipping earnings</h2>
            <p>COD product cash is not Logistics or Rider revenue. This page records only the Rider share of the original shipping fee.</p>
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
            <tbody>
                @forelse($earnings as $earning)
                    @php
                        $settlement = $earning->shippingSettlement;
                        $payout = $earning->payout;
                        $isSelected = (int) old('earning_id', 0) === (int) $earning->id;
                        $sellerOrder = $earning->shipment?->sellerOrder;
                        $returnWindowEndedAt = $sellerOrder?->completed_at
                            ? \Carbon\CarbonImmutable::parse($sellerOrder->completed_at)
                                ->addDays(\App\Services\ReturnRefundService::RETURN_WINDOW_DAYS)
                                ->endOfDay()
                            : null;
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $earning->shipment?->shipment_no ?? 'Shipment' }}</strong>
                            <small class="table-subline">{{ $earning->shipment?->sellerOrder?->seller_order_no ?? 'Seller Order' }} · {{ $earning->shipment?->sellerOrder?->store?->name ?? 'Seller' }}</small>
                            <small class="table-subline">Settlement {{ $settlement?->settlement_no ?? 'pending' }}</small>
                        </td>
                        <td>{{ $earning->riderProfile?->user?->name ?? 'Rider' }}</td>
                        <td>
                            <strong>Rider pool: ₱{{ number_format((int) $earning->amount_minor / 100, 2) }}</strong>
                            <small class="table-subline">Original fee ₱{{ number_format((int) ($settlement?->original_shipping_fee_minor ?? 0) / 100, 2) }}</small>
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
                                <small class="table-subline">Reference: <code>{{ $payout->reference }}</code></small>
                                <small class="table-subline">Recorded by {{ $payout->recorder?->name ?? 'Logistics' }}</small>
                            @elseif($earning->status === 'pending')
                                <span class="empty-copy">Awaiting Admin/Finance remittance to your provider.</span>
                            @elseif($earning->status === 'posted')
                                <form method="POST" action="{{ route('logistics.settlements.rider-earnings.record-paid', $earning->id) }}" class="cash-action-form">
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
                                    <label for="rider-payout-reference-{{ $earning->id }}">Actual payout reference
                                        <input id="rider-payout-reference-{{ $earning->id }}" type="text" name="reference" maxlength="150" value="{{ $isSelected ? old('reference') : '' }}" required>
                                    </label>
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
                    <tr><td colspan="5"><p class="empty-copy">No shipping Rider earnings are currently recorded for this provider. Delivery history alone does not create an earning. After the 3-calendar-day return/refund period ends, payment, unresolved-case, and COD checks still determine eligibility. Eligible zone-priced shipments appear here only after those checks pass; Admin/Finance remittance and your recorded Rider payout are separate later steps.</p></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

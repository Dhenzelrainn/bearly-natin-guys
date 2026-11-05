@extends('logistics.layouts.app')

@section('title', 'Shipping settlement')
@section('page-title', 'Shipping settlement')

@section('content')
<section class="page-hero">
    <div>
        <span class="eyebrow">Provider earnings</span>
        <h1>Shipping settlement and Rider payouts</h1>
        <p>Outbound and return delivery earnings keep their existing eligibility and Finance remittance gates. Logistics prepares and approves one payout batch per Rider, then records the actual external payment after it is made.</p>
    </div>
</section>

<section class="metric-strip" aria-label="Logistics shipping earnings summary">
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="wallet" aria-hidden="true"></i></span>
        <span class="metric-copy">
            <small>Total eligible Logistics share</small>
            <strong>₱{{ number_format((int) $earningsSummary['total_minor'] / 100, 2) }}</strong>
            <span>Your final-provider and origin-provider shares across eligible shipping settlements</span>
        </span>
    </article>
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="clock-3" aria-hidden="true"></i></span>
        <span class="metric-copy">
            <small>Awaiting Finance remittance</small>
            <strong>₱{{ number_format((int) $earningsSummary['pending_minor'] / 100, 2) }}</strong>
            <span>Your Finance remittances still outstanding</span>
        </span>
    </article>
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="circle-check-big" aria-hidden="true"></i></span>
        <span class="metric-copy">
            <small>Finance remitted</small>
            <strong>₱{{ number_format((int) $earningsSummary['remitted_minor'] / 100, 2) }}</strong>
            <span>Provider shares only; the Rider pool is tracked separately below</span>
        </span>
    </article>
</section>

<section class="panel" data-shipping-settlement-live data-status-url="{{ route('logistics.settlements.status-version') }}" data-status-version="{{ $earningsStatusVersion }}">
    <div class="panel-heading">
        <div>
            <span class="eyebrow">Your provider only</span>
            <h2>Rider shipping earnings</h2>
            <p>COD product cash is not Logistics or Rider revenue. This page records your Rider pool for eligible deliveries and your separate origin-provider allocation when another Logistics hub completes the final delivery.</p>
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
                        $payoutBatch = $earning->payoutBatchItem?->batch;
                        $sellerOrder = $earning->shipment?->sellerOrder;
                        $settlementIsReturn = $settlement?->settlement_type === 'return';
                        $settlementIsInterHub = $settlement?->isInterHub() ?? false;
                        $originRemittance = $settlement?->originRemittance;
                        $returnRequest = $settlement?->returnRequest;
                        $returnWindowEndedAt = ! $settlementIsReturn && $sellerOrder?->completed_at
                            ? \Carbon\CarbonImmutable::parse($sellerOrder->completed_at)
                                ->addDays(\App\Services\ReturnRefundService::RETURN_WINDOW_DAYS)
                                ->endOfDay()
                            : null;
                        $productPreviews = $earning->product_previews ?? [];
                        $productCount = (int) ($earning->product_count ?? 0);
                        $isPickupEarning = $earning->type === 'pickup_parcel';
                    @endphp
                    <tr>
                        <td>
                            @if($isPickupEarning)
                                <strong>{{ $earning->parcel?->parcel_no ?? 'Pickup parcel' }}</strong>
                                <small class="table-subline">{{ $earning->pickupAssignment?->pickupRequest?->pickup_no ?? 'Pickup assignment' }} · {{ $earning->description }}</small>
                            @else
                            <strong>{{ $earning->shipment?->shipment_no ?? 'Shipment' }}</strong>
                            @if ($settlementIsReturn)
                                <small class="table-subline">Return {{ $returnRequest?->return_no ?? 'case unavailable' }} · {{ $sellerOrder?->store?->name ?? 'Seller' }}</small>
                            @else
                                <small class="table-subline">{{ $sellerOrder?->seller_order_no ?? 'Seller Order' }} · {{ $sellerOrder?->store?->name ?? 'Seller' }}</small>
                            @endif
                            <small class="table-subline">Settlement {{ $settlement?->settlement_no ?? 'pending' }}</small>
                            @endif
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
                            <strong>{{ $isPickupEarning ? 'Pickup rate' : 'Rider pool' }}: ₱{{ number_format((int) $earning->amount_minor / 100, 2) }}</strong>
                            @if($isPickupEarning)
                                <small class="table-subline">Saved size class: {{ $earning->size_class_snapshot ?: 'not classified' }}</small>
                            @else
                                <small class="table-subline">{{ $settlementIsReturn ? 'Return route fee' : 'Original fee' }} ₱{{ number_format((int) ($settlement?->feeAmountMinor() ?? 0) / 100, 2) }}</small>
                                <small class="table-subline">{{ $settlementIsInterHub ? 'Destination Logistics 30%' : 'Logistics 40%' }}: ₱{{ number_format((int) ($settlement?->logistics_share_minor ?? 0) / 100, 2) }}</small>
                                @if($settlementIsInterHub)
                                    <small class="table-subline">Origin Logistics 10%: ₱{{ number_format((int) $settlement->origin_logistics_share_minor / 100, 2) }} · {{ $settlement->originLogisticsProfile?->display_name ?? 'Origin provider' }}</small>
                                    <small class="table-subline">Rider pool 60%: ₱{{ number_format((int) $settlement->rider_share_minor / 100, 2) }}</small>
                                @endif
                            @endif
                        </td>
                        <td><span class="status-badge" data-status="{{ $earning->status }}">{{ str($earning->status)->replace('_', ' ')->title() }}</span>
                            @if($returnWindowEndedAt)
                                <small class="table-subline">3-day return period ended {{ $returnWindowEndedAt->format('M j, Y') }}</small>
                            @endif
                            @if($settlementIsInterHub)
                                <small class="table-subline">Destination remittance: {{ $settlement->remitted_at ? 'sent '.($settlement->remitted_on?->format('M j, Y') ?? '') : 'awaiting Finance' }} · ₱{{ number_format((int) ($settlement->remitted_amount_minor ?? $settlement->expectedFinalLogisticsRemittanceMinor()) / 100, 2) }}</small>
                                <small class="table-subline">Origin remittance: {{ $originRemittance?->status === 'remitted' ? 'sent '.($originRemittance->remitted_on?->format('M j, Y') ?? '') : 'awaiting Finance' }} · ₱{{ number_format((int) $settlement->origin_logistics_share_minor / 100, 2) }}</small>
                            @elseif($settlement?->remitted_at)
                                <small class="table-subline">Finance remitted {{ $settlement->remitted_on?->format('M j, Y') }} · ₱{{ number_format((int) $settlement->remitted_amount_minor / 100, 2) }}</small>
                                <small class="table-subline">{{ str($settlement->remittance_method)->replace('_', ' ')->title() }} · <code>{{ $settlement->remittance_reference }}</code></small>
                            @endif
                        </td>
                        <td>
                            @if($payout)
                                <strong>{{ str($payout->method)->replace('_', ' ')->title() }}</strong>
                                <small class="table-subline">₱{{ number_format((int) $payout->amount_minor / 100, 2) }} · {{ $payout->paid_on?->format('M j, Y') }}</small>
                                @if($payout->method === 'cash' && str_starts_with((string) $payout->reference, 'LRP-') && $payoutBatch?->status === 'paid')
                                    <small class="table-subline">Consolidated batch: <code>{{ $payoutBatch->batch_no }}</code></small>
                                    <small class="table-subline">Bearly cash payout reference: <code>{{ $payout->reference }}</code></small>
                                    <a class="button button-secondary button-small logistics-cash-payout-receipt-link" href="{{ route('logistics.settlements.payout-batches.cash-receipt', $payoutBatch->id) }}" target="_blank" rel="noopener"><i data-lucide="printer" aria-hidden="true"></i>Print cash receipt</a>
                                @elseif($payout->method === 'cash' && str_starts_with((string) $payout->reference, 'LRP-'))
                                    <small class="table-subline">Bearly cash payout reference: <code>{{ $payout->reference }}</code></small>
                                    <a class="button button-secondary button-small logistics-cash-payout-receipt-link" href="{{ route('logistics.settlements.rider-earnings.cash-receipt', $earning->id) }}" target="_blank" rel="noopener"><i data-lucide="printer" aria-hidden="true"></i>Print cash receipt</a>
                                @else
                                    <small class="table-subline">Reference: <code>{{ $payout->reference }}</code></small>
                                @endif
                                <small class="table-subline">Recorded by {{ $payout->recorder?->name ?? 'Logistics' }}</small>
                            @elseif($earning->status === 'held')
                                <span class="empty-copy">Held for Logistics size-class review before payout.</span>
                            @elseif($earning->status === 'pending' && $isPickupEarning)
                                <span class="empty-copy">Waiting for review.</span>
                            @elseif($earning->status === 'pending')
                                <span class="empty-copy">Awaiting Admin/Finance remittance to your provider.</span>
                            @elseif($earning->status === 'posted')
                                <span class="empty-copy">Eligible for the Rider’s consolidated payout batch. Individual payments are no longer recorded outside batch approval.</span>
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

@if($originRemittances->isNotEmpty())
<section class="panel" style="margin-top:20px" aria-label="Origin Logistics remittance allocations">
    <div class="panel-header">
        <div>
            <h2>Origin-provider allocations</h2>
            <p>These 10% shares belong to your provider for shipments transferred to another Logistics hub. Finance remits this amount directly; the destination provider receives the separate 90% and manages the final Rider payout.</p>
        </div>
    </div>
    <div class="cash-custody-table-wrap" role="region" aria-label="Origin provider remittance history" tabindex="0">
        <table class="cash-custody-table">
            <thead>
                <tr>
                    <th>Shipment / settlement</th>
                    <th>Your origin share</th>
                    <th>Finance status</th>
                    <th>Remittance record</th>
                </tr>
            </thead>
            <tbody>
                @foreach($originRemittances as $originRemittance)
                    @php($settlement = $originRemittance->settlement)
                    <tr>
                        <td>
                            <strong>{{ $settlement?->shipment?->shipment_no ?? 'Shipment' }}</strong>
                            <small class="table-subline">Settlement {{ $settlement?->settlement_no ?? 'unavailable' }}</small>
                            <small class="table-subline">{{ $settlement?->sellerOrder?->seller_order_no ?? 'Seller Order' }}</small>
                        </td>
                        <td>
                            <strong>₱{{ number_format((int) $originRemittance->amount_minor / 100, 2) }}</strong>
                            <small class="table-subline">10% of original shipping fee</small>
                        </td>
                        <td>
                            <span class="status-badge" data-status="{{ $originRemittance->status }}">{{ str($originRemittance->status)->replace('_', ' ')->title() }}</span>
                            @if($originRemittance->remitted_on)
                                <small class="table-subline">Sent {{ $originRemittance->remitted_on->format('M j, Y') }}</small>
                            @else
                                <small class="table-subline">Finance has not recorded this remittance yet.</small>
                            @endif
                        </td>
                        <td>
                            @if($originRemittance->status === 'remitted')
                                <strong>{{ str($originRemittance->remittance_method)->replace('_', ' ')->title() }}</strong>
                                <small class="table-subline">Reference: <code>{{ $originRemittance->remittance_reference }}</code></small>
                                @if($originRemittance->logistics_payout_account_id)
                                    <small class="table-subline">{{ $originRemittance->remitted_to_provider }} · {{ $originRemittance->remitted_to_account_name }} · •••• {{ $originRemittance->remitted_to_last4 }}</small>
                                @endif
                                <small class="table-subline">Recorded by {{ $originRemittance->remitter?->name ?? 'Finance' }}</small>
                            @else
                                <span class="empty-copy">Awaiting Finance remittance.</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
@endif

@if($heldPickupEarnings->isNotEmpty())
<section class="panel" style="margin-top:20px" aria-label="Held pickup earnings">
    <div class="panel-header">
        <div>
            <h2>Pickup earnings held for size-class review</h2>
            <p>These parcels were collected, but their saved size class is missing or unrecognized. Classify each parcel from its persisted size class; do not infer bulky from parcel count.</p>
        </div>
    </div>
    <div class="cash-custody-table-wrap" role="region" aria-label="Held pickup earnings" tabindex="0">
        <table>
            <thead>
                <tr>
                    <th>Parcel</th>
                    <th>Rider</th>
                    <th>Saved size class</th>
                    <th>Classify</th>
                </tr>
            </thead>
            <tbody>
                @foreach($heldPickupEarnings as $earning)
                    <tr>
                        <td>
                            <strong>{{ $earning->parcel?->parcel_no ?? 'Parcel #'.$earning->parcel_id }}</strong>
                            <small class="table-subline">{{ $earning->pickupAssignment?->pickupRequest?->pickup_no ?? 'Pickup assignment' }}</small>
                        </td>
                        <td>{{ $earning->riderProfile?->user?->name ?? 'Rider' }}</td>
                        <td><code>{{ $earning->size_class_snapshot ?: 'missing' }}</code></td>
                        <td>
                            <form method="POST" action="{{ route('logistics.settlements.rider-earnings.classify-size', $earning->id) }}" class="cash-action-form">
                                @csrf
                                <label for="pickup-size-{{ $earning->id }}">Authoritative size class
                                    <select id="pickup-size-{{ $earning->id }}" name="size_class" required>
                                        <option value="">Select size class</option>
                                        <option value="{{ $pickupRates['bulky_class'] }}">Bulky (₱{{ number_format($pickupRates['bulky_minor'] / 100, 2) }})</option>
                                        @foreach($pickupRates['regular_classes'] as $regularClass)
                                            <option value="{{ $regularClass }}">{{ str($regularClass)->title() }} (₱{{ number_format($pickupRates['regular_minor'] / 100, 2) }})</option>
                                        @endforeach
                                    </select>
                                </label>
                                <button class="button button-primary button-small" type="submit">Classify and release</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
@endif

<section class="panel" style="margin-top:20px" aria-label="Consolidated Rider payout batches">
    <div class="panel-header">
        <div>
            <h2>Consolidated Rider payout batches</h2>
            <p>Prepare one batch per Rider across eligible pickup and delivery earnings, review and approve it, then record the actual external payment. Delivery earnings appear only after their existing Finance remittance gate.</p>
        </div>
    </div>

    @if($payoutCandidates->isEmpty())
        <div class="panel-body">
            <p class="empty-copy">No payout-eligible earnings are waiting for a batch. Held pickup earnings and unremitted delivery earnings are excluded.</p>
        </div>
    @else
        <div class="cash-custody-table-wrap" role="region" aria-label="Payout batch candidates" tabindex="0">
            <table>
                <thead>
                    <tr>
                        <th>Rider</th>
                        <th>Eligible earnings</th>
                        <th>Total</th>
                        <th>Prepare batch</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($payoutCandidates as $candidate)
                        <tr>
                            <td>
                                <strong>{{ $candidate['rider']?->user?->name ?? 'Rider' }}</strong>
                                <small class="table-subline">{{ $candidate['rider']?->specializationValue()->label() }}</small>
                            </td>
                            <td>
                                {{ number_format($candidate['earnings']->count()) }} {{ $candidate['earnings']->count() === 1 ? 'earning' : 'earnings' }}
                                <small class="table-subline">{{ $candidate['earnings']->pluck('type')->unique()->map(fn ($type) => str($type)->replace('_', ' ')->title())->implode(' · ') }}</small>
                            </td>
                            <td><strong>₱{{ number_format($candidate['total_minor'] / 100, 2) }}</strong></td>
                            <td>
                                <form method="POST" action="{{ route('logistics.settlements.payout-batches.store') }}" class="cash-action-form">
                                    @csrf
                                    <input type="hidden" name="rider_profile_id" value="{{ $candidate['rider']->id }}">
                                    <label for="batch-notes-{{ $candidate['rider']->id }}">Review note (optional)
                                        <input id="batch-notes-{{ $candidate['rider']->id }}" type="text" name="notes" maxlength="1000">
                                    </label>
                                    <button class="button button-primary button-small" type="submit">Prepare payout batch</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="panel-body">
        <h3>Batch history</h3>
        @forelse($payoutBatches as $batch)
            <div class="cash-custody-row" style="border-top:1px solid var(--border); padding:16px 0">
                <div>
                    <strong>{{ $batch->batch_no }}</strong>
                    <small class="table-subline">{{ $batch->riderProfile?->user?->name ?? 'Rider' }} · {{ number_format($batch->items->count()) }} {{ $batch->items->count() === 1 ? 'earning' : 'earnings' }} · ₱{{ number_format((int) $batch->total_minor / 100, 2) }}</small>
                    @if($batch->review_notes)<small class="table-subline">Review note: {{ $batch->review_notes }}</small>@endif
                    <details>
                        <summary>View included earnings</summary>
                        <ul>
                            @foreach($batch->items as $item)
                                @php($earning = $item->riderEarning)
                                <li>
                                    {{ str($item->earning_type)->replace('_', ' ')->title() }} ·
                                    @if($item->earning_type === config('bearly_rider_compensation.courier_daily_earning_type', 'courier_daily'))
                                        {{ $earning?->linehaulRun?->run_no ?? 'Line-haul run' }} · Work date {{ $earning?->work_date?->format('M j, Y') ?? '—' }}
                                    @else
                                        {{ $earning?->parcel?->parcel_no ?? $earning?->shipment?->shipment_no ?? 'Earning #'.$item->rider_earning_id }}
                                    @endif
                                    · ₱{{ number_format((int) $item->amount_minor / 100, 2) }}
                                </li>
                            @endforeach
                        </ul>
                    </details>
                    <small class="table-subline">
                        <span class="status-badge" data-status="{{ $batch->status }}">{{ str($batch->status)->replace('_', ' ')->title() }}</span>
                        @if($batch->approved_at) · Approved {{ $batch->approved_at->format('M j, Y g:i A') }} @endif
                        @if($batch->paid_on) · Paid {{ $batch->paid_on->format('M j, Y') }} @endif
                    </small>
                </div>

                <div>
                    @if($batch->status === 'pending_review')
                        <form method="POST" action="{{ route('logistics.settlements.payout-batches.approve', $batch->id) }}">
                            @csrf
                            <button class="button button-primary button-small" type="submit">Review and approve</button>
                        </form>
                    @elseif($batch->status === 'approved')
                        <p>Pay the approved batch total of <strong>₱{{ number_format((int) $batch->total_minor / 100, 2) }}</strong> to the Rider before recording payment.</p>
                        <form method="POST" action="{{ route('logistics.settlements.payout-batches.record-paid', $batch->id) }}" class="cash-action-form">
                            @csrf
                            <label for="batch-method-{{ $batch->id }}">Method
                                <select id="batch-method-{{ $batch->id }}" name="method" required>
                                    <option value="">Select method</option>
                                    <option value="bank_transfer">Bank transfer</option>
                                    <option value="gcash">GCash</option>
                                    <option value="cash">Cash</option>
                                    <option value="other">Other</option>
                                </select>
                            </label>
                            <label for="batch-reference-{{ $batch->id }}">External payout reference
                                <input id="batch-reference-{{ $batch->id }}" type="text" name="reference" maxlength="150">
                            </label>
                            <label for="batch-paid-on-{{ $batch->id }}">Paid on
                                <input id="batch-paid-on-{{ $batch->id }}" type="date" name="paid_on" max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" required>
                            </label>
                            <label for="batch-reason-{{ $batch->id }}">Reason / note
                                <textarea id="batch-reason-{{ $batch->id }}" name="reason" minlength="5" maxlength="1000" rows="2" required></textarea>
                            </label>
                            <label class="finance-confirm">
                                <input type="checkbox" name="payout_confirmed" value="1" required>
                                I confirm this exact amount was already paid to the Rider.
                            </label>
                            <button class="button button-primary button-small" type="submit">Record actual batch payout</button>
                        </form>
                    @else
                        <small class="table-subline">
                            {{ str($batch->method ?? 'payment')->replace('_', ' ')->title() }}
                            @if($batch->reference) · Reference <code>{{ $batch->reference }}</code> @endif
                            @if($batch->reason) · {{ $batch->reason }} @endif
                        </small>
                        @if($batch->method === 'cash' && str_starts_with((string) $batch->reference, 'LRP-'))
                            <a class="button button-secondary button-small" href="{{ route('logistics.settlements.payout-batches.cash-receipt', $batch->id) }}" target="_blank" rel="noopener"><i data-lucide="printer" aria-hidden="true"></i>Print cash receipt</a>
                        @endif
                    @endif
                </div>
            </div>
        @empty
            <p class="empty-copy">No payout batches have been prepared for this provider.</p>
        @endforelse
    </div>
</section>
@endsection

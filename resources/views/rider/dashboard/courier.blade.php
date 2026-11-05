@extends('rider.layouts.app')

@section('title', 'Courier Runs')
@section('page-title', 'City / Line-haul Courier')

@section('content')
@php
    $isCourierAvailable = $courierProfile->availability_status === 'available';
@endphp
<div class="page-header">
    <div>
        <p class="page-kicker">Logistics-verified work type</p>
        <h2>Your assigned runs</h2>
        <p>Follow the manifest in order. Destination Logistics changes custody only after its authorized acceptance scan.</p>
    </div>
    <div class="page-actions">
        <div class="rider-availability-control" data-rider-availability-control data-rider-verification-status="approved">
            <span
                class="status-badge {{ $isCourierAvailable ? 'is-success' : '' }}"
                data-rider-availability-badge
                data-status="{{ $courierProfile->availability_status }}"
                aria-live="polite"
            >{{ $isCourierAvailable ? 'Available' : 'Off duty' }}</span>
            <form method="POST" action="{{ route('rider.availability.update') }}" data-rider-availability-form>
                @csrf
                @method('PATCH')
                <input type="hidden" name="availability_status" value="{{ $isCourierAvailable ? 'offline' : 'available' }}" data-rider-availability-input>
                <button class="button button-small" type="submit" data-rider-availability-submit>{{ $isCourierAvailable ? 'Go off duty' : 'Set available' }}</button>
            </form>
        </div>
        <span class="status-badge is-success">Verified capacity: {{ $courierProfile->linehaul_parcel_capacity }} parcels</span>
    </div>
</div>

<section class="panel" style="margin-bottom:20px" aria-labelledby="courier-daily-pay-heading">
    <div class="panel-header">
        <div>
            <p class="page-kicker">Attendance and compensation</p>
            <h3 id="courier-daily-pay-heading">₱650 per qualifying workday</h3>
            <p>Daily pay requires check-in and check-out, plus at least one run assigned during that shift that destination Logistics has fully accepted. Several runs in one day still count once.</p>
        </div>
        <a class="button button-small" href="{{ route('rider.earnings.index') }}">
            <i data-lucide="wallet" aria-hidden="true"></i>
            Earnings & payout history
        </a>
    </div>
    <div class="panel-body">
        <div class="attention-item" role="status" aria-live="polite">
            <i data-lucide="clock-4" aria-hidden="true"></i>
            <span>
                @if($currentShift)
                    <strong>Checked in {{ $currentShift->checkedInAtPht()?->format('M j, Y · g:i A') }} {{ \App\Models\RiderShift::TIMEZONE_LABEL }}</strong>
                    <small>Check out when your shift ends. This shift is not eligible for daily pay until it is closed and a run assigned during it is accepted in full.</small>
                @else
                    <strong>You are not checked in</strong>
                    <small>Record both your start and end time. Attendance alone does not earn daily pay.</small>
                @endif
            </span>
            @if($currentShift)
                <form method="POST" action="{{ route('rider.attendance.check-out') }}">
                    @csrf
                    <button class="button button-primary button-small" type="submit">Check out</button>
                </form>
            @else
                <form method="POST" action="{{ route('rider.attendance.check-in') }}">
                    @csrf
                    <button class="button button-primary button-small" type="submit">Check in</button>
                </form>
            @endif
        </div>

        <div class="metric-strip" aria-label="Courier daily earnings summary" style="margin-top:18px">
            <article class="metric-item"><span class="metric-copy"><small>Daily rate</small><strong>{{ $courierEarnings['rate'] }}</strong><span>One earning per work date</span></span></article>
            <article class="metric-item"><span class="metric-copy"><small>Qualifying days</small><strong>{{ number_format($courierEarnings['qualifying_days']) }}</strong><span>{{ $courierEarnings['total'] }} recorded earnings</span></span></article>
            <article class="metric-item"><span class="metric-copy"><small>Ready for payout</small><strong>{{ $courierEarnings['eligible'] }}</strong><span>Awaiting Logistics' actual payout</span></span></article>
            <article class="metric-item"><span class="metric-copy"><small>Recorded as paid</small><strong>{{ $courierEarnings['paid'] }}</strong><span>External payment recorded by Logistics</span></span></article>
        </div>

        <div class="table-wrap" style="margin-top:18px">
            <table class="data-table">
                <thead><tr><th>Work date</th><th>Qualifying run</th><th>Amount</th><th>Status / payout</th></tr></thead>
                <tbody>
                    @forelse($courierEarningHistory as $earning)
                        <tr>
                            <td>{{ $earning->work_date?->format('M j, Y') ?? '—' }}</td>
                            <td>{{ $earning->linehaulRun?->run_no ?? 'Run unavailable' }}</td>
                            <td><strong>₱{{ number_format((int) $earning->amount_minor / 100, 2) }}</strong></td>
                            <td>
                                <span class="status-badge {{ $earning->status === 'paid' ? 'is-success' : '' }}">{{ str($earning->status)->replace('_', ' ')->title() }}</span>
                                @if($earning->payout)
                                    <small class="table-subline">Paid {{ $earning->payout->paid_on?->format('M j, Y') ?? 'date unavailable' }} · {{ str($earning->payout->method)->replace('_', ' ')->title() }} · {{ $earning->payout->reference }}</small>
                                @elseif($earning->payoutBatchItem?->batch)
                                    <small class="table-subline">Payout batch {{ $earning->payoutBatchItem->batch->batch_no }} · {{ str($earning->payoutBatchItem->batch->status)->replace('_', ' ')->title() }}</small>
                                @else
                                    <small class="table-subline">Not yet included in a payout batch</small>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><p class="empty-copy">No qualifying days yet. Check-in/out and wait for destination Logistics to accept every shipment on an assigned run.</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="zone-routing-note" style="margin:14px 0 0">This ledger is not a wallet. Logistics records payment only after paying you through an external method.</p>
    </div>
</section>

@forelse($runs as $run)
    <section class="panel" style="margin-bottom:16px">
        <div class="panel-header">
            <div>
                <p class="page-kicker">{{ $run->run_no }}</p>
                <h3>{{ $run->originSortingCenter?->name ?? 'Origin facility' }} → {{ $run->destinationLogisticsProfile?->display_name ?? 'Destination facility' }}</h3>
                <p>Status: <strong>{{ str($run->status)->replace('_', ' ')->title() }}</strong> · Assigned {{ $run->assigned_at?->format('M j, Y · g:i A') }}</p>
            </div>
        </div>

        <div class="panel-body">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Waybill</th><th>Shipment</th><th>Seller</th><th>Parcels</th></tr></thead>
                    <tbody>
                        @foreach($run->shipments as $shipment)
                            <tr>
                                <td>{{ $shipment->waybill?->waybill_no ?? 'Waybill pending' }}</td>
                                <td>{{ $shipment->shipment_no }}</td>
                                <td>{{ $shipment->sellerOrder?->store?->name ?? 'Unknown Seller' }}</td>
                                <td>{{ $shipment->parcels->count() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="form-actions">
                @if($run->status === 'assigned')
                    <form method="POST" action="{{ route('rider.courier.runs.start-loading', $run) }}">@csrf<button class="button button-primary" type="submit">Start loading</button></form>
                @elseif($run->status === 'loading')
                    <form method="POST" action="{{ route('rider.courier.runs.loaded', $run) }}">@csrf<button class="button button-primary" type="submit">Confirm all cargo loaded</button></form>
                @elseif($run->status === 'loaded')
                    <form method="POST" action="{{ route('rider.courier.runs.depart', $run) }}">@csrf<button class="button button-primary" type="submit">Record departure</button></form>
                @elseif($run->status === 'departed')
                    <form method="POST" action="{{ route('rider.courier.runs.arrive', $run) }}">@csrf<button class="button button-primary" type="submit">Record arrival</button></form>
                @elseif($run->status === 'exception')
                    <form method="POST" action="{{ route('rider.courier.runs.exceptions.resolve', $run) }}" class="field span-2">
                        @csrf
                        <label for="resolution-{{ $run->id }}">Resolution notes</label>
                        <input id="resolution-{{ $run->id }}" name="resolution" minlength="5" maxlength="1000" required>
                        <button class="button button-primary" type="submit">Resolve exception</button>
                    </form>
                @elseif($run->status === 'arrived')
                    <p class="zone-routing-note">Waiting for destination Logistics to scan and accept every shipment.</p>
                @elseif($run->status === 'completed')
                    <p class="status-badge is-success">All handoffs accepted by destination Logistics.</p>
                @endif

                @if(! in_array($run->status, ['exception', 'completed'], true))
                    <form method="POST" action="{{ route('rider.courier.runs.exceptions.store', $run) }}" class="field span-2">
                        @csrf
                        <label for="exception-{{ $run->id }}">Report a run exception</label>
                        <input id="exception-{{ $run->id }}" name="reason" minlength="5" maxlength="1000" placeholder="Describe a delay, access issue, or cargo discrepancy" required>
                        <button class="button button-small" type="submit">Place run on exception hold</button>
                    </form>
                @endif
            </div>

            @if($run->events->isNotEmpty())
                <details>
                    <summary>Run history ({{ $run->events->count() }} events)</summary>
                    <ol class="attention-list">
                        @foreach($run->events as $event)
                            <li class="attention-item">
                                <span><strong>{{ str($event->event_type)->replace('_', ' ')->title() }}</strong><small>{{ $event->occurred_at?->format('M j, Y · g:i A') }} · {{ $event->actor?->name ?? 'System' }}</small>@if($event->reason)<small>{{ $event->reason }}</small>@endif</span>
                            </li>
                        @endforeach
                    </ol>
                </details>
            @endif
        </div>
    </section>
@empty
    <section class="panel"><div class="panel-body"><p class="empty-copy">No line-haul run is assigned to you right now. Your sponsoring Logistics provider will assign eligible manifests here.</p></div></section>
@endforelse
@endsection

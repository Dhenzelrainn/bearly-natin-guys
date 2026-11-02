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

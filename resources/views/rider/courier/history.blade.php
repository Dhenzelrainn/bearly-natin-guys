@extends('rider.layouts.app')

@section('title', 'Line-haul History')
@section('page-title', 'Line-haul History')

@section('content')
<div class="page-header">
    <div>
        <p class="page-kicker">Courier records</p>
        <h2>Your assigned line-haul runs</h2>
        <p>Review manifests assigned to your Courier account, their current status, and recorded run events. This history is separate from the earnings ledger.</p>
    </div>
    <div class="page-actions">
        <a class="button" href="{{ route('rider.courier.dashboard') }}">
            <i data-lucide="truck" aria-hidden="true"></i>
            Courier runs
        </a>
        <a class="button" href="{{ route('rider.earnings.index') }}">
            <i data-lucide="wallet-cards" aria-hidden="true"></i>
            Earnings &amp; payouts
        </a>
    </div>
</div>

<section class="panel" aria-label="Courier line-haul run history">
    <div class="panel-body">
        <p class="zone-routing-note" style="margin:0 0 16px">
            A run does not create pay by itself. Courier pay uses closed QR-recorded hours, up to ₱650 per PHT work date, and requires at least one run assigned during a shift that destination Logistics has accepted in full.
        </p>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Assigned</th>
                        <th scope="col">Run</th>
                        <th scope="col">Route</th>
                        <th scope="col">Status</th>
                        <th scope="col">Manifest</th>
                        <th scope="col">Run events</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($runs as $run)
                        <tr>
                            <td>{{ $run->assigned_at?->format('M j, Y · g:i A') ?? '—' }}</td>
                            <td><strong>{{ $run->run_no }}</strong></td>
                            <td>{{ $run->originSortingCenter?->name ?? 'Origin facility' }} → {{ $run->destinationLogisticsProfile?->display_name ?? 'Destination facility' }}</td>
                            <td>
                                <span class="status-badge" data-status="{{ $run->status }}">
                                    {{ str($run->status)->replace('_', ' ')->title() }}
                                </span>
                            </td>
                            <td>
                                <details>
                                    <summary>{{ $run->shipments->count() }} shipment(s) · {{ $run->shipments->sum(fn ($shipment) => $shipment->parcels->count()) }} parcel(s)</summary>
                                    @if($run->shipments->isNotEmpty())
                                        <ul class="attention-list">
                                            @foreach($run->shipments as $shipment)
                                                <li class="attention-item">
                                                    <span>
                                                        <strong>{{ $shipment->waybill?->waybill_no ?? $shipment->shipment_no }}</strong>
                                                        <small>{{ $shipment->shipment_no }}</small>
                                                    </span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @else
                                        <p class="empty-copy">No manifest shipments are recorded.</p>
                                    @endif
                                </details>
                            </td>
                            <td>
                                @if($run->events->isNotEmpty())
                                    <details>
                                        <summary>View {{ $run->events->count() }} event(s)</summary>
                                        <ol class="attention-list">
                                            @foreach($run->events as $event)
                                                <li class="attention-item">
                                                    <span>
                                                        <strong>{{ str($event->event_type)->replace('_', ' ')->title() }}</strong>
                                                        <small>{{ $event->occurred_at?->format('M j, Y · g:i A') ?? 'Time unavailable' }} · {{ $event->actor?->name ?? 'System' }}</small>
                                                        @if($event->reason)
                                                            <small>{{ $event->reason }}</small>
                                                        @endif
                                                    </span>
                                                </li>
                                            @endforeach
                                        </ol>
                                    </details>
                                @else
                                    <span class="muted">No run events recorded</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="empty-row" colspan="6">No line-haul runs have been assigned to you yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($runs->total() > 0)
            <div class="history-pagination" role="status" aria-live="polite">
                <span>Showing {{ $runs->firstItem() ?? 0 }}–{{ $runs->lastItem() ?? 0 }} of {{ number_format($runs->total()) }} runs</span>
                <div class="page-actions">
                    @if($runs->previousPageUrl())
                        <a class="button button-small" href="{{ $runs->previousPageUrl() }}" rel="prev">Previous</a>
                    @endif
                    @if($runs->nextPageUrl())
                        <a class="button button-small" href="{{ $runs->nextPageUrl() }}" rel="next">Next</a>
                    @endif
                </div>
            </div>
        @endif
    </div>
</section>
@endsection

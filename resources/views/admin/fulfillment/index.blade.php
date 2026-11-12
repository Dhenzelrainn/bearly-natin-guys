@extends('layouts.admin')

@section('title', 'Fulfillment')
@section('page-title', 'Fulfillment')

@section('content')
    @php
        $statusMetrics = $metrics;

        $metricCards = [
            ['key' => 'pending_pickup', 'label' => 'Pending pickup', 'icon' => 'package-search'],
            ['key' => 'intake', 'label' => 'At sorting center', 'icon' => 'warehouse'],
            ['key' => 'transit', 'label' => 'In transit / delivery', 'icon' => 'truck'],
            ['key' => 'delivered', 'label' => 'Delivered', 'icon' => 'package-check'],
            ['key' => 'failed', 'label' => 'Exceptions', 'icon' => 'triangle-alert'],
            ['key' => 'tracking_review', 'label' => 'Expired pickup windows', 'icon' => 'scan-search'],
        ];

        $overrideStatuses = [
            'pending_waybill',
            'ready_for_pickup',
            'at_sorting_center',
            'in_transit',
            'out_for_delivery',
            'delivered',
            'delivery_failed',
            'exception',
        ];
    @endphp

    <section class="page-hero">
        <div>
            <span class="eyebrow">Operations monitoring</span>
            <h1>Shipment fulfillment</h1>
            <p>Review shipment history, pickup appointments, Waybills, and delivery proofs. Tracking cues are advisory only: a missing scan is not proof of Seller misconduct, and no automatic penalty is applied.</p>
        </div>

        <div class="hero-context-stat">
            <span class="hero-context-icon"><i data-lucide="boxes"></i></span>
            <div>
                <strong>{{ number_format($shipments->total()) }}</strong>
                <span>Recorded shipments</span>
            </div>
        </div>
    </section>

    @if (session('success'))
        <div class="info-box" role="status">{{ session('success') }}</div>
    @endif

    <section class="user-stats-grid fulfillment-metrics" aria-label="Shipment status totals">
        @foreach ($metricCards as $metric)
            <article>
                <span class="metric-icon"><i data-lucide="{{ $metric['icon'] }}"></i></span>
                <strong>{{ number_format((int) ($statusMetrics[$metric['key']] ?? 0)) }}</strong>
                <small>{{ $metric['label'] }}</small>
            </article>
        @endforeach
    </section>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">Shipment ledger</span>
                <h2>Recent shipments</h2>
            </div>
        </div>

        <div class="table-wrap">
            <table class="admin-table fulfillment-table">
                <thead>
                    <tr>
                        <th>Shipment / Order</th>
                        <th>Store</th>
                        <th>Recipient</th>
                        <th>Waybill</th>
                        <th>Status</th>
                        <th>Latest scan</th>
                        <th>Tracking review</th>
                        <th>Admin override</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($shipments as $shipment)
                        @php
                            $sellerOrder = $shipment->sellerOrder;
                            $order = $sellerOrder?->order;
                            $latestEvent = $shipment->events->last();
                            $deliveryProofs = $shipment->parcels
                                ->flatMap(fn ($parcel) => $parcel->deliveryAttempts)
                                ->flatMap(fn ($attempt) => $attempt->proofs)
                                ->where('type', 'photo')
                                ->sortByDesc('captured_at')
                                ->unique('file_path')
                                ->values();
                        @endphp

                        <tr>
                            <td>
                                <strong>{{ $shipment->shipment_no }}</strong>
                                <small class="table-subline">
                                    {{ $sellerOrder?->seller_order_no ?: ($order?->order_no ?: 'Order unavailable') }}
                                    · {{ str($shipment->purpose)->replace('_', ' ')->title() }}
                                </small>
                            </td>
                            <td>{{ $sellerOrder?->store?->name ?: 'Store unavailable' }}</td>
                            <td>{{ $order?->recipient_name ?: ($order?->buyer?->name ?: 'Recipient unavailable') }}</td>
                            <td>
                                @if ($shipment->waybill)
                                    <strong>{{ $shipment->waybill->waybill_no }}</strong>
                                    @if ($shipment->purpose === 'outbound' && ! $shipment->waybill->voided_at && $shipment->waybill->status !== 'voided')
                                        <a
                                            class="button button-ghost button-small"
                                            href="{{ route('admin.fulfillment.waybills.pdf', $shipment->waybill) }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            aria-label="Open audited Waybill PDF for {{ $shipment->waybill->waybill_no }}"
                                        >View Waybill PDF</a>
                                    @endif
                                @else
                                    Not generated
                                @endif
                            </td>
                            <td>
                                <span class="status-badge badge-warning">
                                    {{ str($shipment->status)->replace('_', ' ')->title() }}
                                </span>
                            </td>
                            <td>
                                @if ($latestEvent)
                                    <strong>{{ str($latestEvent->event_type)->replace('_', ' ')->title() }}</strong>
                                    <small class="table-subline">
                                        {{ $latestEvent->occurred_at?->format('M j, Y g:i A') ?: 'Time unavailable' }}
                                        @if (filled($latestEvent->notes))
                                            · {{ $latestEvent->notes }}
                                        @endif
                                    </small>
                                @else
                                    <span class="muted">No scan recorded</span>
                                @endif
                                @foreach ($deliveryProofs as $proof)
                                    <a
                                        class="button button-ghost button-small"
                                        href="{{ route('admin.fulfillment.delivery-proofs.show', $proof) }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        aria-label="Preview delivery proof {{ $loop->iteration }} for {{ $shipment->shipment_no }}"
                                    >
                                        View delivery proof{{ $deliveryProofs->count() > 1 ? ' · '.$loop->iteration : '' }}
                                    </a>
                                @endforeach
                            </td>
                            <td>
                                @if ($shipment->tracking_review_signal)
                                    <span class="status-badge badge-warning">{{ $shipment->tracking_review_signal['label'] }}</span>
                                    <small class="table-subline">{{ $shipment->tracking_review_signal['detail'] }}</small>
                                @else
                                    <span class="muted">No current review cue</span>
                                @endif
                            </td>
                            <td>
                                <form
                                    class="fulfillment-override-form"
                                    method="POST"
                                    action="{{ route('admin.fulfillment.override', $shipment) }}"
                                    onsubmit="return confirm('Apply and audit this shipment status override?')"
                                >
                                    @csrf
                                    @method('PATCH')

                                    <label class="sr-only" for="shipment-status-{{ $shipment->id }}">
                                        New status for {{ $shipment->shipment_no }}
                                    </label>
                                    <select id="shipment-status-{{ $shipment->id }}" name="status" required>
                                        <option value="">Choose status</option>
                                        @foreach ($overrideStatuses as $status)
                                            <option value="{{ $status }}">
                                                {{ str($status)->replace('_', ' ')->title() }}
                                            </option>
                                        @endforeach
                                    </select>

                                    <label class="sr-only" for="shipment-note-{{ $shipment->id }}">
                                        Reason for overriding {{ $shipment->shipment_no }}
                                    </label>
                                    <input
                                        id="shipment-note-{{ $shipment->id }}"
                                        type="text"
                                        name="note"
                                        required
                                        minlength="5"
                                        maxlength="1000"
                                        placeholder="Reason for override"
                                    >

                                    <button class="button button-ghost button-small" type="submit">Save</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <div class="table-empty">
                                    <i data-lucide="package-open"></i>
                                    <strong>No shipments recorded</strong>
                                    <span>Shipments will appear here as fulfillment records are created.</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{ $shipments->links() }}
@endsection

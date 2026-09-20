@extends('layouts.seller')

@section('title', 'Shipment Tracking')
@section('page-title', 'Shipment Tracking')

@section('content')
@php
    $shipmentCollection = collect($shipments);

    $trackingCounts = [
        'all' => $shipmentCollection->count(),
        'sorting' => $shipmentCollection->where('status_key', 'sorting')->count(),
        'transit' => $shipmentCollection->where('status_key', 'transit')->count(),
        'out-delivery' => $shipmentCollection->where('status_key', 'out-delivery')->count(),
        'delivered' => $shipmentCollection->where('status_key', 'delivered')->count(),
        'failed' => $shipmentCollection->where('status_key', 'failed')->count(),
    ];

    $attentionShipment = $shipmentCollection->firstWhere('status_key', 'failed');
@endphp

<div class="tracking-page">
    <header class="page-heading fulfillment-heading tracking-page-heading">
        <div>
            <h2>Shipment Tracking</h2>
            <p>Monitor parcels after handover to logistics.</p>
        </div>

        <button class="seller-secondary-button tracking-export-button" type="button" data-tracking-export>
            <i data-lucide="download" aria-hidden="true"></i>
            <span>Export Shipments</span>
        </button>
    </header>

    @if ($attentionShipment)
        <section class="tracking-attention-banner" aria-label="Shipment needs attention">
            <i class="tracking-attention-icon" data-lucide="triangle-alert" aria-hidden="true"></i>

            <div class="tracking-attention-copy">
                <strong>
                    {{ $trackingCounts['failed'] }}
                    shipment{{ $trackingCounts['failed'] === 1 ? '' : 's' }} needs attention
                </strong>
                <p>{{ $attentionShipment['latest'] }}.</p>
            </div>

            <button
                class="tracking-attention-action"
                type="button"
                data-tracking-view
                data-shipment='@json($attentionShipment)'
            >
                <span>View shipment</span>
                <i data-lucide="arrow-right" aria-hidden="true"></i>
            </button>
        </section>
    @endif

    <section class="fulfillment-workspace tracking-workspace" data-tracking-workspace>
        <nav class="fulfillment-tabs tracking-tabs" aria-label="Shipment status filters">
            <button class="is-active" type="button" data-tracking-tab="all">
                All Shipments <span>{{ $trackingCounts['all'] }}</span>
            </button>

            <button type="button" data-tracking-tab="sorting">
                Sorting Center <span>{{ $trackingCounts['sorting'] }}</span>
            </button>

            <button type="button" data-tracking-tab="transit">
                Sorted / In Transit <span>{{ $trackingCounts['transit'] }}</span>
            </button>

            <button type="button" data-tracking-tab="out-delivery">
                Out for Delivery <span>{{ $trackingCounts['out-delivery'] }}</span>
            </button>

            <button type="button" data-tracking-tab="delivered">
                Delivered <span>{{ $trackingCounts['delivered'] }}</span>
            </button>

            <button type="button" data-tracking-tab="failed">
                Needs Attention <span>{{ $trackingCounts['failed'] }}</span>
            </button>
        </nav>

        <div class="fulfillment-toolbar tracking-toolbar">
            <label class="tracking-search">
                <i data-lucide="search" aria-hidden="true"></i>
                <input
                    type="search"
                    placeholder="Search tracking, order, customer, or destination"
                    data-tracking-search
                    aria-label="Search shipments"
                >
            </label>

            <button class="tracking-reset-button" type="button" data-tracking-reset>
                <i data-lucide="rotate-ccw" aria-hidden="true"></i>
                <span>Reset</span>
            </button>
        </div>

        <div class="fulfillment-table-wrap tracking-table-wrap">
            <table class="fulfillment-table tracking-table">
                <colgroup>
                    <col class="tracking-col-order">
                    <col class="tracking-col-customer">
                    <col class="tracking-col-rider">
                    <col class="tracking-col-update">
                    <col class="tracking-col-eta">
                    <col class="tracking-col-status">
                    <col class="tracking-col-action">
                </colgroup>

                <thead>
                    <tr>
                        <th>Tracking / Order</th>
                        <th>Customer / Destination</th>
                        <th>Rider</th>
                        <th>Latest update</th>
                        <th>ETA</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($shipments as $item)
                        @php
                            $riderPrimary = $item['rider'];
                            $riderSecondary = 'Delivery rider';

                            if (str_starts_with($item['rider'], 'Pickup rider:')) {
                                $riderPrimary = trim(str_replace('Pickup rider:', '', $item['rider']));
                                $riderSecondary = 'Pickup rider';
                            } elseif (str_contains(strtolower($item['rider']), 'assignment pending')) {
                                $riderPrimary = 'Awaiting assignment';
                                $riderSecondary = '—';
                            }

                            $etaLabel = match ($item['status_key']) {
                                'delivered' => '—',
                                'failed' => 'To be updated',
                                default => $item['eta'],
                            };
                        @endphp

                        <tr
                            data-tracking-row
                            data-status="{{ $item['status_key'] }}"
                            data-search="{{ strtolower(implode(' ', $item)) }}"
                        >
                            <td class="tracking-order-cell">
                                <strong>{{ $item['tracking'] }}</strong>
                                <small>{{ $item['order'] }}</small>
                            </td>

                            <td class="tracking-customer-cell">
                                <strong>{{ $item['customer'] }}</strong>
                                <small>{{ $item['destination'] }}</small>
                            </td>

                            <td class="tracking-rider-cell">
                                <span>{{ $riderPrimary }}</span>
                                <small>{{ $riderSecondary }}</small>
                            </td>

                            <td class="tracking-update-cell">
                                <strong>{{ $item['latest'] }}</strong>
                                <small>{{ $item['updated'] }}</small>
                            </td>

                            <td class="tracking-eta-cell">{{ $etaLabel }}</td>

                            <td class="tracking-status-cell">
                                <span class="fulfillment-status is-{{ $item['status_key'] }}">
                                    {{ $item['status'] }}
                                </span>
                            </td>

                            <td class="tracking-action-cell">
                                <button
                                    class="fulfillment-row-action"
                                    type="button"
                                    data-tracking-view
                                    data-shipment='@json($item)'
                                >
                                    Track
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="fulfillment-empty" data-tracking-empty hidden>
                <i data-lucide="search-x" aria-hidden="true"></i>
                <strong>No matching shipments</strong>
                <span>Try changing your search or status tab.</span>
            </div>
        </div>

        <footer class="fulfillment-footer tracking-footer">
            <span>Showing <strong data-tracking-count>{{ count($shipments) }}</strong> shipments</span>
            <span>Tracking updates come from logistics and courier scans.</span>
        </footer>
    </section>

    <div class="tracking-completion-note">
        <i data-lucide="badge-check" aria-hidden="true"></i>
        <span>Delivered orders are completed after buyer confirmation.</span>
    </div>
</div>

<div class="seller-modal fulfillment-modal tracking-modal" data-modal="tracking-details" hidden>
    <button class="modal-backdrop" type="button" data-modal-close aria-label="Close tracking details"></button>

    <section class="modal-card">
        <div class="modal-heading">
            <div>
                <span class="section-kicker">Shipment activity</span>
                <h3 data-tracking-number>Tracking details</h3>
            </div>
            <button type="button" data-modal-close aria-label="Close">
                <i data-lucide="x" aria-hidden="true"></i>
            </button>
        </div>

        <div class="tracking-overview">
            <div>
                <span>Order</span>
                <strong data-tracking-order>—</strong>
            </div>
            <div>
                <span>Customer</span>
                <strong data-tracking-customer>—</strong>
            </div>
            <div>
                <span>Destination</span>
                <strong data-tracking-destination>—</strong>
            </div>
            <div>
                <span>Estimated delivery</span>
                <strong data-tracking-eta>—</strong>
            </div>
        </div>

        <ol class="shipment-timeline">
            <li class="is-done">
                <span><i data-lucide="check" aria-hidden="true"></i></span>
                <div>
                    <strong>Parcel picked up</strong>
                    <small>Rider confirmed seller handover</small>
                </div>
            </li>

            <li class="is-done">
                <span><i data-lucide="check" aria-hidden="true"></i></span>
                <div>
                    <strong>Sorting center processing</strong>
                    <small>Parcel scanned, sorted, and routed by destination</small>
                </div>
            </li>

            <li class="is-current">
                <span><i data-lucide="truck" aria-hidden="true"></i></span>
                <div>
                    <strong data-tracking-latest>Current shipment update</strong>
                    <small data-tracking-updated>Latest scan time</small>
                </div>
            </li>

            <li>
                <span><i data-lucide="package-check" aria-hidden="true"></i></span>
                <div>
                    <strong>Delivered</strong>
                    <small>Courier records successful delivery</small>
                </div>
            </li>

            <li>
                <span><i data-lucide="circle-check-big" aria-hidden="true"></i></span>
                <div>
                    <strong>Completed</strong>
                    <small>Buyer confirms receipt; financial settlement can finalize</small>
                </div>
            </li>
        </ol>

        <section class="delivery-proof" data-delivery-proof hidden>
            <i data-lucide="badge-check" aria-hidden="true"></i>
            <div>
                <strong>Proof of delivery recorded</strong>
                <p>Delivered to the customer. Buyer confirmation is still required before COMPLETED.</p>
            </div>
            <button type="button" data-fulfillment-demo="Delivery proof opened.">View Proof</button>
        </section>

        <div class="modal-actions">
            <button class="draft-button" type="button" data-modal-close>Close</button>
            <button class="seller-primary-button" type="button" data-report-shipment>
                <i data-lucide="triangle-alert" aria-hidden="true"></i>
                Report Issue
            </button>
        </div>
    </section>
</div>

<div class="seller-modal fulfillment-modal" data-modal="tracking-report" hidden>
    <button class="modal-backdrop" type="button" data-modal-close aria-label="Close report form"></button>

    <section class="modal-card">
        <div class="modal-heading">
            <div>
                <span class="section-kicker">Shipment exception</span>
                <h3>Report Shipment Issue</h3>
            </div>
            <button type="button" data-modal-close aria-label="Close">
                <i data-lucide="x" aria-hidden="true"></i>
            </button>
        </div>

        <form class="shipment-report-form" data-shipment-report-form>
            <label>
                <span>Issue type</span>
                <select required>
                    <option value="">Choose issue</option>
                    <option>No tracking update</option>
                    <option>Pickup or delivery delay</option>
                    <option>Parcel damaged in transit</option>
                    <option>Incorrect delivery status</option>
                </select>
            </label>

            <label>
                <span>Description</span>
                <textarea
                    rows="4"
                    required
                    placeholder="Describe what happened and the help you need."
                ></textarea>
            </label>

            <label>
                <span>Supporting evidence</span>
                <input type="file" accept="image/*,.pdf">
            </label>

            <div class="modal-actions">
                <button class="draft-button" type="button" data-modal-close>Cancel</button>
                <button class="seller-primary-button" type="submit">Submit Report</button>
            </div>
        </form>
    </section>
</div>
@endsection

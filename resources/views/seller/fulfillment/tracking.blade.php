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
                    shipment{{ $trackingCounts['failed'] === 1 ? '' : 's' }} {{ $trackingCounts['failed'] === 1 ? 'needs' : 'need' }} attention
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
                        <tr
                            data-tracking-row
                            data-status="{{ $item['status_key'] }}"
                            data-search="{{ $item['search'] }}"
                        >
                            <td class="tracking-order-cell">
                                <div class="tracking-shipment">
                                    <span class="tracking-shipment-thumb" role="img" aria-label="{{ $item['product_name'] }}" title="{{ $item['product_name'] }}">
                                        @if (filled($item['product_image']))
                                            <img src="{{ $item['product_image'] }}" alt="" loading="lazy" decoding="async">
                                        @else
                                            <i data-lucide="package" aria-hidden="true"></i>
                                        @endif
                                    </span>

                                    <span class="tracking-shipment-copy">
                                        <strong>{{ $item['tracking'] }}</strong>
                                        <small>{{ $item['order'].($item['item_count'] > 1 ? ' · '.$item['item_count'].' items' : '') }}</small>
                                    </span>
                                </div>
                            </td>

                            <td class="tracking-customer-cell">
                                <strong>{{ $item['customer'] }}</strong>
                                <small>{{ $item['destination'] }}</small>
                            </td>

                            <td class="tracking-rider-cell">
                                <span>{{ $item['rider_name'] ?? 'Awaiting assignment' }}</span>
                                <small>{{ $item['rider_role'] ?? '—' }}</small>
                            </td>

                            <td class="tracking-update-cell">
                                <strong>{{ $item['latest'] }}</strong>
                                <small>{{ $item['updated'] }}</small>
                            </td>

                            <td class="tracking-eta-cell">{{ $item['status_key'] === 'delivered' ? '—' : $item['eta'] }}</td>

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

            @if ($trackingCounts['all'] === 0)
                <div class="fulfillment-empty" data-tracking-empty>
                    <i data-lucide="package-open" aria-hidden="true"></i>
                    <strong>No shipments to track yet</strong>
                    <span>Outbound parcels appear here after pickup handover to logistics.</span>
                </div>
            @else
                <div class="fulfillment-empty" data-tracking-empty hidden>
                    <i data-lucide="search-x" aria-hidden="true"></i>
                    <strong>No matching shipments</strong>
                    <span>Try changing your search or status tab.</span>
                </div>
            @endif
        </div>

        <footer class="fulfillment-footer tracking-footer">
            <span role="status" aria-live="polite">Showing <strong data-tracking-count>{{ count($shipments) }}</strong> shipments</span>
            <span class="tracking-footer-note">Tracking updates come from logistics and courier scans · Delivered orders are completed after buyer confirmation.</span>
        </footer>
    </section>
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
                <i data-lucide="package" aria-hidden="true"></i>
                <div>
                    <span>Order</span>
                    <strong data-tracking-order>—</strong>
                </div>
            </div>
            <div>
                <i data-lucide="user" aria-hidden="true"></i>
                <div>
                    <span>Customer</span>
                    <strong data-tracking-customer>—</strong>
                </div>
            </div>
            <div>
                <i data-lucide="map-pin" aria-hidden="true"></i>
                <div>
                    <span>Destination</span>
                    <strong data-tracking-destination>—</strong>
                </div>
            </div>
            <div>
                <i data-lucide="calendar-days" aria-hidden="true"></i>
                <div>
                    <span>Estimated delivery</span>
                    <strong data-tracking-eta>—</strong>
                </div>
            </div>
        </div>

        <ol class="shipment-timeline" data-tracking-timeline aria-label="Shipment scan history"></ol>

        <section class="delivery-proof" data-delivery-proof hidden>
            <i data-lucide="badge-check" aria-hidden="true"></i>
            <div>
                <strong>Proof of delivery recorded</strong>
                <p>Delivered to the customer. Buyer confirmation is still required before COMPLETED.</p>
            </div>
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

<div class="seller-modal fulfillment-modal tracking-modal" data-modal="tracking-report" hidden>
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

        <form
            class="shipment-report-form"
            action="{{ route('contact.store') }}"
            method="POST"
            data-shipment-report-form
        >
            @csrf
            <input type="hidden" name="name" value="{{ $seller['name'] }}">
            <input type="hidden" name="email" value="{{ $seller['email'] }}">
            <input type="hidden" name="role" value="Seller">
            <input type="hidden" name="topic" value="Delivery &amp; Tracking">
            <input type="hidden" name="reference" maxlength="50" data-shipment-report-reference>
            <input type="hidden" name="subject" data-shipment-report-subject>

            <p class="tracking-report-context" data-shipment-report-context>
                The shipment tracking number will be attached to your support ticket.
            </p>

            <label>
                <span>Issue type</span>
                <select required data-shipment-report-issue>
                    <option value="">Choose issue</option>
                    <option value="No tracking update">No tracking update</option>
                    <option value="Pickup or delivery delay">Pickup or delivery delay</option>
                    <option value="Parcel damaged in transit">Parcel damaged in transit</option>
                    <option value="Incorrect delivery status">Incorrect delivery status</option>
                </select>
            </label>

            <label>
                <span>Description</span>
                <textarea
                    name="message"
                    rows="4"
                    required
                    minlength="10"
                    maxlength="900"
                    data-shipment-report-description
                    placeholder="Describe what happened and the help you need."
                ></textarea>
            </label>

            <p class="tracking-report-note">
                This creates a Delivery &amp; Tracking support ticket. File attachments are not available in this form yet.
            </p>

            <p class="tracking-report-error" data-shipment-report-error role="alert" hidden></p>

            <div class="modal-actions">
                <button class="draft-button" type="button" data-modal-close>Cancel</button>
                <button class="seller-primary-button" type="submit" data-shipment-report-submit>Submit Report</button>
            </div>
        </form>
    </section>
</div>
@endsection

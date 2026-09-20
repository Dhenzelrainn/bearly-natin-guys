@extends('layouts.seller')

@section('title', 'Waybills')
@section('page-title', 'Waybills')

@section('content')
@php
    $waybillCounts = [
        'all' => count($waybills),
        'ready' => count(array_filter($waybills, fn ($item) => $item['status_key'] === 'ready')),
        'printed' => count(array_filter($waybills, fn ($item) => $item['status_key'] === 'printed')),
        'reprint' => count(array_filter($waybills, fn ($item) => $item['status_key'] === 'reprint')),
    ];
@endphp

<div class="waybills-page">
    <div class="page-heading fulfillment-heading waybills-heading">
        <div>
            <span class="section-kicker">Fulfillment preparation</span>
            <h2>Waybills</h2>
            <p>Generate, print, and audit shipping labels for packed orders.</p>
        </div>

        <button class="seller-primary-button waybill-bulk-button" type="button" data-waybill-bulk disabled>
            <i data-lucide="printer" aria-hidden="true"></i>
            Print Selected
        </button>
    </div>

    @include('seller.fulfillment.partials.summary', ['items' => $summary])

    <section class="fulfillment-workspace waybill-workspace" data-waybill-workspace aria-label="Waybill management">
        <div class="waybill-workspace-head">
            <div class="fulfillment-tabs" role="tablist" aria-label="Waybill status filters">
                <button class="is-active" type="button" role="tab" aria-selected="true" data-waybill-tab="all">
                    All <span>{{ $waybillCounts['all'] }}</span>
                </button>
                <button type="button" role="tab" aria-selected="false" data-waybill-tab="ready">
                    Ready to Print <span>{{ $waybillCounts['ready'] }}</span>
                </button>
                <button type="button" role="tab" aria-selected="false" data-waybill-tab="printed">
                    Printed <span>{{ $waybillCounts['printed'] }}</span>
                </button>
                <button type="button" role="tab" aria-selected="false" data-waybill-tab="reprint">
                    Reprint Required <span>{{ $waybillCounts['reprint'] }}</span>
                </button>
            </div>
        </div>

        <div class="fulfillment-toolbar waybill-toolbar">
            <label>
                <i data-lucide="search" aria-hidden="true"></i>
                <input
                    type="search"
                    placeholder="Search order number, tracking number, or customer name"
                    data-waybill-search
                    aria-label="Search waybills"
                >
            </label>

            <button type="button" data-waybill-history>
                <i data-lucide="history" aria-hidden="true"></i>
                Print History
            </button>
        </div>

        <div class="fulfillment-table-wrap waybill-table-wrap">
            <table class="fulfillment-table waybill-table">
                <thead>
                    <tr>
                        <th scope="col">
                            <input type="checkbox" data-waybill-check-all aria-label="Select all printable waybills">
                        </th>
                        <th scope="col">Order / Customer</th>
                        <th scope="col">Tracking Number</th>
                        <th scope="col">Courier</th>
                        <th scope="col">Parcel Details</th>
                        <th scope="col">Destination</th>
                        <th scope="col">Pickup</th>
                        <th scope="col">Status</th>
                        <th scope="col">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($waybills as $item)
                        <tr
                            data-waybill-row
                            data-status="{{ $item['status_key'] }}"
                            data-search="{{ strtolower(implode(' ', $item)) }}"
                            class="{{ $item['status_key'] === 'ready' ? 'is-actionable' : '' }}"
                        >
                            <td>
                                <input
                                    type="checkbox"
                                    data-waybill-check
                                    {{ $item['status_key'] === 'printed' ? 'disabled' : '' }}
                                    aria-label="Select {{ $item['order'] }}"
                                >
                            </td>
                            <td>
                                <strong>{{ $item['order'] }}</strong>
                                <small>{{ $item['customer'] }}</small>
                            </td>
                            <td><strong>{{ $item['tracking'] }}</strong></td>
                            <td>{{ $item['courier'] }}</td>
                            <td>
                                <strong>{{ $item['packages'] }} {{ $item['packages'] > 1 ? 'packages' : 'package' }}</strong>
                                <small>{{ $item['weight'] }} · {{ $item['size'] }}</small>
                            </td>
                            <td>{{ $item['destination'] }}</td>
                            <td>{{ $item['pickup'] }}</td>
                            <td>
                                <span class="fulfillment-status is-{{ $item['status_key'] }}">{{ $item['status'] }}</span>
                            </td>
                            <td>
                                <button
                                    class="fulfillment-row-action {{ $item['status_key'] === 'ready' ? 'is-primary' : '' }}"
                                    type="button"
                                    data-waybill-action
                                    data-waybill='@json($item)'
                                >
                                    {{ $item['action'] }}
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="fulfillment-empty" data-waybill-empty hidden>
                <i data-lucide="search-x" aria-hidden="true"></i>
                <strong>No matching waybills</strong>
                <span>Try another status or search term.</span>
            </div>
        </div>

        <footer class="fulfillment-footer waybill-footer">
            <span>Showing <strong data-waybill-count>{{ count($waybills) }}</strong> records</span>
        </footer>
    </section>

    <div class="seller-modal fulfillment-modal" data-modal="waybill-preview" hidden>
        <button class="modal-backdrop" type="button" data-modal-close aria-label="Close"></button>
        <section class="modal-card" aria-label="Waybill preview">
            <div class="modal-heading">
                <div>
                    <span class="section-kicker">Shipping label</span>
                    <h3 data-waybill-modal-order>Waybill preview</h3>
                </div>
                <button type="button" data-modal-close aria-label="Close waybill preview">
                    <i data-lucide="x" aria-hidden="true"></i>
                </button>
            </div>

            <div class="waybill-label">
                <div class="waybill-barcode">
                    <i data-lucide="scan-line" aria-hidden="true"></i>
                    <strong data-waybill-modal-tracking>Tracking number</strong>
                </div>
                <dl>
                    <div>
                        <dt>Ship to</dt>
                        <dd data-waybill-modal-customer>—</dd>
                    </div>
                    <div>
                        <dt>Destination</dt>
                        <dd data-waybill-modal-destination>—</dd>
                    </div>
                    <div>
                        <dt>Courier</dt>
                        <dd data-waybill-modal-courier>—</dd>
                    </div>
                    <div>
                        <dt>Parcel</dt>
                        <dd data-waybill-modal-parcel>—</dd>
                    </div>
                </dl>
            </div>

            <div class="modal-actions">
                <button class="draft-button" type="button" data-modal-close>Close</button>
                <button class="seller-primary-button waybill-modal-print" type="button" data-waybill-print>
                    <i data-lucide="printer" aria-hidden="true"></i>
                    Print Waybill
                </button>
            </div>
        </section>
    </div>

    <div class="seller-modal fulfillment-modal" data-modal="waybill-history" hidden>
        <button class="modal-backdrop" type="button" data-modal-close aria-label="Close"></button>
        <section class="modal-card waybill-history-modal" aria-label="Waybill print history">
            <div class="modal-heading">
                <div>
                    <span class="section-kicker">Audit log</span>
                    <h3>Print History</h3>
                </div>
                <button type="button" data-modal-close aria-label="Close print history">
                    <i data-lucide="x" aria-hidden="true"></i>
                </button>
            </div>

            <div class="waybill-history-table-wrap">
                <table class="waybill-history-table">
                    <thead>
                        <tr>
                            <th scope="col">Order</th>
                            <th scope="col">Tracking Number</th>
                            <th scope="col">Action</th>
                            <th scope="col">Printed By</th>
                            <th scope="col">Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($history as $entry)
                            <tr>
                                <td><strong>{{ $entry['order'] }}</strong></td>
                                <td>{{ $entry['tracking'] }}</td>
                                <td>{{ $entry['action'] }}</td>
                                <td>{{ $entry['actor'] }}</td>
                                <td>{{ $entry['time'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

@endsection

@extends('layouts.seller')

@section('title', 'Waybills')
@section('page-title', 'Waybills')

@section('content')
<div class="waybills-page">
    <div class="page-heading fulfillment-heading waybills-heading">
        <div>
            <span class="section-kicker">Fulfillment preparation</span>
            <h2>Waybills</h2>
            <p>Prepare and attach parcel labels before the Rider pickup.</p>
        </div>

        <button class="seller-primary-button waybill-bulk-button" type="button" data-waybill-bulk disabled>
            <i data-lucide="file-down" aria-hidden="true"></i>
            Download Selected PDFs
        </button>
    </div>

    @if (session('success'))
        <div class="waybill-alert is-success" role="status">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="waybill-alert is-error" role="alert">{{ session('error') }}</div>
    @endif

    <section class="fulfillment-workspace waybill-workspace" data-waybill-workspace aria-label="Waybill management">
        <div class="waybill-workspace-head">
            <nav class="fulfillment-tabs" aria-label="Waybill status filters">
                @foreach (['all' => 'All', 'ready' => 'Ready to Print', 'printed' => 'Printed', 'voided' => 'Voided'] as $key => $label)
                    <a href="{{ route('seller.fulfillment.waybills', array_filter(['status' => $key === 'all' ? null : $key, 'search' => $search ?: null])) }}"
                       @class(['is-active' => $statusFilter === $key])
                       @if ($statusFilter === $key) aria-current="page" @endif>
                        {{ $label }} <span>{{ $counts[$key] }}</span>
                    </a>
                @endforeach
            </nav>
        </div>

        <div class="fulfillment-toolbar waybill-toolbar">
            <form method="get" action="{{ route('seller.fulfillment.waybills') }}">
                @if ($statusFilter !== 'all')<input type="hidden" name="status" value="{{ $statusFilter }}">@endif
                <label>
                    <i data-lucide="search" aria-hidden="true"></i>
                    <input type="search" name="search" value="{{ $search }}" placeholder="Search order, tracking number, or recipient" aria-label="Search waybills">
                </label>
                <button type="submit">Search</button>
                @if ($search !== '')<a href="{{ route('seller.fulfillment.waybills', $statusFilter === 'all' ? [] : ['status' => $statusFilter]) }}">Clear</a>@endif
            </form>
            <button type="button" data-waybill-history>
                <i data-lucide="history" aria-hidden="true"></i>
                PDF / Print History
            </button>
        </div>

        <div class="fulfillment-table-wrap waybill-table-wrap">
            <table class="fulfillment-table waybill-table">
                <thead>
                    <tr>
                        <th scope="col"><input type="checkbox" data-waybill-check-all aria-label="Select all printable waybills"></th>
                        <th scope="col">Seller Order / Recipient</th>
                        <th scope="col">Waybill Number</th>
                        <th scope="col">Logistics</th>
                        <th scope="col">Parcel</th>
                        <th scope="col">Destination</th>
                        <th scope="col">Pickup</th>
                        <th scope="col">Label Status</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($waybills as $item)
                        <tr data-waybill-row data-status="{{ $item['status_key'] }}" class="{{ $item['status_key'] === 'ready' ? 'is-actionable' : '' }}">
                            <td>
                                <input type="checkbox" data-waybill-check value="{{ $item['id'] }}" {{ $item['status_key'] === 'voided' ? 'disabled' : '' }} aria-label="Select {{ $item['order'] }}">
                            </td>
                            <td><strong>{{ $item['order'] }}</strong><small>{{ $item['customer'] }}</small></td>
                            <td><strong>{{ $item['tracking'] }}</strong><small>Generated {{ $item['generated_at'] }}</small></td>
                            <td>{{ $item['courier'] }}</td>
                            <td>
                                <strong>{{ $item['packages'] }} {{ $item['packages'] === 1 ? 'piece' : 'pieces' }}</strong>
                                <small>{{ $item['weight'] }} · {{ $item['size'] }}</small>
                                @if ($item['print_count'] > 0)<small>{{ $item['print_count'] }} print confirmation(s)</small>@endif
                            </td>
                            <td>
                                <span class="waybill-cell-with-icon">
                                    <i data-lucide="map-pin" aria-hidden="true"></i>
                                    <span>{{ $item['destination'] }}</span>
                                </span>
                            </td>
                            <td>
                                <span class="waybill-cell-with-icon">
                                    <i data-lucide="calendar-days" aria-hidden="true"></i>
                                    <span>{{ $item['pickup'] }}</span>
                                </span>
                            </td>
                            <td>
                                <span @class(['fulfillment-status', 'is-ready' => $item['status_key'] === 'ready', 'is-printed' => $item['status_key'] === 'printed', 'is-voided' => $item['status_key'] === 'voided'])>
                                    @if ($item['status_key'] === 'ready')<i data-lucide="printer" aria-hidden="true"></i>@endif
                                    {{ $item['status'] }}
                                </span>
                            </td>
                            <td class="waybill-actions">
                                @if ($item['status_key'] !== 'voided')
                                    <button class="fulfillment-row-action is-primary" type="button" data-waybill-download="{{ $item['id'] }}">Download PDF</button>
                                    @if (! $item['pdf_generated'])<small class="waybill-print-hint">Download the PDF to enable print confirmation.</small>@endif
                                    <form method="post" action="{{ route('seller.fulfillment.waybills.printed', $item['id']) }}">
                                        @csrf
                                        <button class="fulfillment-row-action" type="submit" data-waybill-print-confirm @disabled(! $item['pdf_generated']) title="{{ $item['pdf_generated'] ? '' : 'Download the PDF first.' }}">{{ $item['status_key'] === 'printed' ? 'Confirm Reprint & Attach' : 'Mark Printed & Attached' }}</button>
                                    </form>
                                @else
                                    <span class="waybill-voided-note">Not valid for shipping</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="waybill-empty-cell">No waybills match this view. Submit a pickup request after a Seller Order is ready to create its waybill.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($waybills->hasPages())
            <footer class="fulfillment-footer waybill-footer">{{ $waybills->links() }}</footer>
        @else
            <footer class="fulfillment-footer waybill-footer">Showing {{ $waybills->count() }} {{ $waybills->count() === 1 ? 'waybill' : 'waybills' }}</footer>
        @endif
    </section>

    <form id="waybill-pdf-form" method="post" action="{{ route('seller.fulfillment.waybills.pdf') }}" target="_blank" hidden>
        @csrf
    </form>

    <div class="seller-modal fulfillment-modal" data-modal="waybill-history" hidden>
        <button class="modal-backdrop" type="button" data-modal-close aria-label="Close"></button>
        <section class="modal-card waybill-history-modal" aria-label="Waybill PDF and print history">
            <div class="modal-heading">
                <div><span class="section-kicker">Audit log</span><h3>PDF / Print History</h3></div>
                <button type="button" data-modal-close aria-label="Close history"><i data-lucide="x" aria-hidden="true"></i></button>
            </div>
            <div class="waybill-history-table-wrap">
                <table class="waybill-history-table">
                    <thead><tr><th scope="col">Seller Order</th><th scope="col">Waybill</th><th scope="col">Action</th><th scope="col">Actor</th><th scope="col">Time</th></tr></thead>
                    <tbody>
                        @forelse ($history as $entry)
                            <tr><td><strong>{{ $entry['order'] }}</strong></td><td>{{ $entry['tracking'] }}</td><td>{{ $entry['action'] }}</td><td>{{ $entry['actor'] }}</td><td>{{ $entry['time'] }}</td></tr>
                        @empty
                            <tr><td colspan="5">No PDF or print actions recorded yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
@endsection

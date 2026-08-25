@extends('layouts.courier')

@section('title', 'Delivery History')
@section('page-title', 'Delivery History')

@section('content')
<section class="page-hero">
    <div><span class="eyebrow">Module 08</span><h1>Delivery history</h1><p>Search and filter completed, canceled, and returned deliveries, then open a receipt-style detail drawer.</p></div>
    <div class="hero-summary-card"><span class="metric-icon"><i data-lucide="history"></i></span><div><strong>{{ count($deliveries) }}</strong><small>Mock delivery records</small></div></div>
</section>

<section class="panel">
    <div class="panel-heading panel-heading-wrap">
        <div><span class="eyebrow">Past deliveries</span><h2>Delivery records</h2></div>
        <div class="table-toolbar"><label class="field-with-icon compact-field"><i data-lucide="search"></i><input type="search" placeholder="Search order, seller, buyer..." data-table-search="history-table"></label><select class="select-field" data-table-filter="history-table" data-filter-key="status"><option value="">All statuses</option><option>Completed</option><option>Cancelled</option><option>Returned</option></select><input class="date-field" type="date" value="2026-08-25"></div>
    </div>
    <div class="table-wrap"><table class="courier-table" id="history-table"><thead><tr><th>Order</th><th>Route</th><th>Date</th><th>Distance</th><th>Payout</th><th>Status</th><th class="align-right">Details</th></tr></thead><tbody>
        @foreach($deliveries as $delivery)
            <tr data-table-row data-status="{{ $delivery['status'] }}" data-search="{{ strtolower($delivery['id'].' '.$delivery['seller'].' '.$delivery['buyer'].' '.$delivery['route']) }}">
                <td><div class="identity-cell"><span class="avatar avatar-soft">{{ substr($delivery['seller'],0,1) }}{{ substr($delivery['buyer'],0,1) }}</span><div><strong>{{ $delivery['id'] }}</strong><small>{{ $delivery['seller'] }} → {{ $delivery['buyer'] }}</small></div></div></td>
                <td>{{ $delivery['route'] }}</td><td>{{ $delivery['date'] }}</td><td>{{ $delivery['distance'] }}</td><td><strong>{{ $delivery['payout'] }}</strong></td><td><span class="status-badge {{ $delivery['status']==='Completed' ? 'badge-success' : ($delivery['status']==='Cancelled' ? 'badge-danger' : 'badge-warning') }}"><span class="history-status-dot history-{{ strtolower($delivery['status']) }}"></span>{{ $delivery['status'] }}</span></td>
                <td class="align-right"><button class="button button-ghost button-small" type="button" data-open-history data-id="{{ $delivery['id'] }}" data-date="{{ $delivery['date'] }}" data-status="{{ $delivery['status'] }}" data-seller="{{ $delivery['seller'] }}" data-buyer="{{ $delivery['buyer'] }}" data-route="{{ $delivery['route'] }}" data-distance="{{ $delivery['distance'] }}" data-payout="{{ $delivery['payout'] }}"><i data-lucide="receipt-text"></i> View</button></td>
            </tr>
        @endforeach
    </tbody></table><div class="table-empty" data-table-empty="history-table" hidden><i data-lucide="search-x"></i><strong>No deliveries found</strong><span>Try another search or status filter.</span></div></div>
</section>

<div class="drawer-shell" data-drawer="history-detail" hidden>
    <button class="drawer-backdrop" type="button" data-close-drawer></button>
    <aside class="drawer-card">
        <div class="drawer-heading"><div><span class="eyebrow" data-history-id>ORD-00000</span><h2>Delivery receipt</h2></div><button class="icon-button" type="button" data-close-drawer><i data-lucide="x"></i></button></div>
        <div class="receipt-block"><h4>Delivery summary</h4><div class="receipt-row"><span>Date</span><strong data-history-date>—</strong></div><div class="receipt-row"><span>Status</span><strong data-history-status>—</strong></div><div class="receipt-row"><span>Route</span><strong data-history-route>—</strong></div><div class="receipt-row"><span>Distance</span><strong data-history-distance>—</strong></div></div>
        <div class="receipt-block"><h4>Parties</h4><div class="receipt-row"><span>Seller</span><strong data-history-seller>—</strong></div><div class="receipt-row"><span>Buyer</span><strong data-history-buyer>—</strong></div><div class="receipt-row"><span>Courier</span><strong>{{ $courier['name'] }}</strong></div></div>
        <div class="receipt-block"><h4>Courier payout</h4><div class="receipt-row"><span>Net delivery earning</span><strong data-history-payout>—</strong></div><div class="receipt-row"><span>Payment status</span><strong>Settled preview</strong></div></div>
        <div class="drawer-actions"><button class="button button-secondary" type="button" data-mock-action="Receipt preview prepared for printing."><i data-lucide="printer"></i> Print preview</button><button class="button button-primary" type="button" data-close-drawer>Done</button></div>
    </aside>
</div>
@endsection

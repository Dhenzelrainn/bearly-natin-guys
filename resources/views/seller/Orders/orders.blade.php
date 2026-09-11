@extends('layouts.seller')

@section('title', 'Orders')
@section('page-title', 'Orders')
@section('content')
@php($activeOrderStatus = $defaultOrderStatus ?? 'all')
<div class="page-heading orders-page-heading">
    <div>
        <span class="section-kicker">Seller order workflow</span>
        <h2>Order Management</h2>
        <p>Confirm orders, prepare parcels, hand them to logistics, then monitor the delivery.</p>
    </div>
    <button class="orders-export-button" type="button" data-order-demo="Order list exported for preview.">
        <i data-lucide="download"></i> Export orders
    </button>
</div>

<section class="order-flow-strip" aria-label="Order lifecycle ownership">
    <div><strong>Seller</strong><span>PLACED → CONFIRMED → PREPARING → READY FOR PICKUP</span></div>
    <i data-lucide="arrow-right"></i>
    <div><strong>Logistics / Courier</strong><span>PICKED_UP → AT_SORTING_CENTER → SORTED → ASSIGNED_TO_RIDER → OUT_FOR_DELIVERY</span></div>
    <i data-lucide="arrow-right"></i>
    <div><strong>Buyer</strong><span>DELIVERED → buyer confirms receipt → COMPLETED</span></div>
</section>

<section class="order-queue" aria-label="Orders requiring action">
    @foreach ($orderQueue as $queue)
        <article class="order-queue-item queue-{{ $queue['tone'] }}">
            <span class="order-queue-icon"><i data-lucide="{{ $queue['icon'] }}"></i></span>
            <div><span>{{ $queue['label'] }}</span><strong>{{ $queue['count'] }}</strong><small>{{ $queue['note'] }}</small></div>
        </article>
    @endforeach
</section>

<section class="orders-workspace" data-orders-workspace>
    <div class="order-tabs" role="tablist" aria-label="Filter orders by seller-facing status">
        @foreach ($orderTabs as $tab)
            <button class="order-tab {{ $tab['key'] === $activeOrderStatus ? 'is-active' : '' }}" type="button" role="tab" aria-selected="{{ $tab['key'] === $activeOrderStatus ? 'true' : 'false' }}" data-order-tab="{{ $tab['key'] }}">
                {{ $tab['label'] }} @if ($tab['count'] !== null)<span>{{ $tab['count'] }}</span>@endif
            </button>
        @endforeach
    </div>

    <div class="order-toolbar">
        <label class="order-search"><i data-lucide="search"></i><span class="sr-only">Search orders</span><input type="search" placeholder="Search order ID or customer" data-order-search></label>
        <label class="order-select"><i data-lucide="calendar-days"></i><span class="sr-only">Filter by date</span><select data-order-date><option value="">All dates</option><option value="today">Today</option><option value="upcoming">Upcoming / previous</option></select></label>
        <label class="order-select"><i data-lucide="credit-card"></i><span class="sr-only">Filter by payment</span><select data-order-payment><option value="">All payments</option><option value="paid">Paid / Refunded</option><option value="cod">Cash on Delivery</option></select></label>
        <button class="order-filter-reset" type="button" data-order-reset><i data-lucide="list-filter"></i> Reset</button>
    </div>

    <div class="order-bulk-bar" data-order-bulk-bar>
        <label class="order-check-all"><input type="checkbox" data-order-check-all><span><strong data-order-selected-count>0</strong> selected</span></label>
        <div class="order-bulk-actions">
            <button type="button" disabled data-order-bulk-action="Print waybill"><i data-lucide="printer"></i>Print waybill</button>
            <button type="button" disabled data-order-bulk-action="Arrange pickup"><i data-lucide="truck"></i>Arrange pickup</button>
        </div>
        <small class="order-bulk-rule"><i data-lucide="shield-check"></i> Statuses are changed only by valid workflow actions—not by a free-form status control.</small>
    </div>

    <div class="orders-table-wrap">
        <table class="orders-table orders-table-polished">
            <thead><tr><th aria-label="Select order"></th><th>Order</th><th>Customer</th><th>Items</th><th>Payment</th><th>Total</th><th>Deadline / Update</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
                @foreach ($orders as $order)
                    <tr
                        data-order-row
                        data-status="{{ $order['status_key'] }}"
                        data-date="{{ $order['date_key'] }}"
                        data-payment="{{ $order['payment_key'] }}"
                        data-search="{{ strtolower($order['id'].' '.$order['customer'].' '.$order['canonical_status']) }}"
                        data-order-customer="{{ $order['customer'] }}"
                        data-order-items="{{ $order['item_detail'] }}"
                        data-order-payment-copy="{{ $order['payment'] }}"
                        data-order-total="{{ $order['total'] }}"
                        data-order-status-copy="{{ $order['status'] }}"
                        data-order-canonical="{{ $order['canonical_status'] }}"
                        data-order-owner="{{ $order['responsibility'] }}"
                        data-order-next="{{ $order['next_step'] }}"
                    >
                        <td><input type="checkbox" aria-label="Select {{ $order['id'] }}" data-order-check></td>
                        <td><button class="order-id-button" type="button" data-order-details="{{ $order['id'] }}">{{ $order['id'] }}</button></td>
                        <td><strong class="order-customer">{{ $order['customer'] }}</strong></td>
                        <td><span>{{ $order['items'] }}</span><small class="order-item-mini">{{ $order['item_detail'] }}</small></td>
                        <td><span class="payment-label payment-{{ $order['payment_key'] }}"><i data-lucide="{{ $order['payment_key'] === 'cod' ? 'banknote' : 'circle-check' }}"></i>{{ $order['payment'] }}</span></td>
                        <td><strong>{{ $order['total'] }}</strong></td>
                        <td><span class="deadline-label {{ $order['urgent'] ? 'is-urgent' : '' }}">{{ $order['deadline'] }} @if ($order['urgent'])<small>Action required</small>@endif</span></td>
                        <td>
                            <span class="order-status-badge order-status-{{ $order['tone'] }}">{{ $order['status'] }}</span>
                            <small class="canonical-status">{{ $order['canonical_status'] }}</small>
                        </td>
                        <td>
                            @if ($order['action_url'])
                                <a class="order-row-action" href="{{ $order['action_url'] }}">{{ $order['action'] }}</a>
                            @else
                                <button class="order-row-action" type="button" data-order-details="{{ $order['id'] }}">{{ $order['action'] }}</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="orders-no-results" data-orders-no-results hidden><i data-lucide="search-x"></i><strong>No matching orders</strong><span>Try changing the search or filters.</span></div>
    </div>
    <footer class="orders-table-footer"><span>Showing <strong data-order-visible-count>{{ count($orders) }}</strong> of {{ count($orders) }} preview orders</span><span>UI tabs are simplified buckets; canonical ERP statuses remain visible per order.</span></footer>
</section>

<section class="orders-handoff-grid">
    <article class="seller-panel handoff-card">
        <span class="handoff-icon"><i data-lucide="printer"></i></span>
        <div><span class="section-kicker">Step 1</span><h3>Prepare the parcel & waybill</h3><p>Waybill creation belongs to Fulfillment. Orders only send eligible parcels into that workspace.</p></div>
        <a class="seller-secondary-button" href="{{ route('seller.fulfillment.waybills') }}">Open Waybills <i data-lucide="arrow-right"></i></a>
    </article>
    <article class="seller-panel handoff-card">
        <span class="handoff-icon"><i data-lucide="truck"></i></span>
        <div><span class="section-kicker">Step 2</span><h3>Arrange logistics pickup</h3><p>After a labeled parcel is ready, submit the pickup request. Logistics controls approval and rider assignment.</p></div>
        <a class="seller-secondary-button" href="{{ route('seller.fulfillment.pickups') }}">Open Pickup Requests <i data-lucide="arrow-right"></i></a>
    </article>
</section>

<div class="seller-modal order-details-modal" data-modal="order-details" hidden>
    <button class="modal-backdrop" type="button" data-modal-close aria-label="Close order details"></button>
    <section class="modal-card order-workflow-modal" role="dialog" aria-modal="true" aria-labelledby="order-details-title">
        <div class="modal-heading">
            <div><span class="section-kicker">Order workflow</span><h3 id="order-details-title">Order details</h3></div>
            <button type="button" data-modal-close aria-label="Close"><i data-lucide="x"></i></button>
        </div>
        <div class="order-detail-preview order-detail-grid">
            <p><span>Order</span><strong data-order-detail-id>—</strong></p>
            <p><span>Customer</span><strong data-order-detail-customer>—</strong></p>
            <p><span>Items</span><strong data-order-detail-items>—</strong></p>
            <p><span>Payment</span><strong data-order-detail-payment>—</strong></p>
            <p><span>Total</span><strong data-order-detail-total>—</strong></p>
            <p><span>Canonical status</span><strong data-order-detail-canonical>—</strong></p>
        </div>
        <section class="order-responsibility-card">
            <span><i data-lucide="user-check"></i></span>
            <div><small>Current responsible role</small><strong data-order-detail-owner>—</strong><p data-order-detail-next>—</p></div>
        </section>
        <p class="order-detail-note">Seller actions should only move an order through valid seller-owned steps: <strong>PLACED → CONFIRMED → PREPARING → READY_FOR_PICKUP</strong>. Shipment statuses are updated by logistics/courier; <strong>COMPLETED</strong> follows buyer confirmation.</p>
        <div class="modal-actions">
            <button class="draft-button" type="button" data-modal-close>Close</button>
            <button class="seller-primary-button" type="button" data-order-demo="Valid workflow action previewed. Backend state transition will be connected later."><span data-order-modal-action>Continue workflow</span></button>
        </div>
    </section>
</div>
<script type="application/json" data-default-order-status>@json($activeOrderStatus)</script>
@endsection

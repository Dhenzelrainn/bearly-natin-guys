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
                        data-order-payment-key="{{ $order['payment_key'] }}"
                        data-order-total="{{ $order['total'] }}"
                        data-order-status-copy="{{ $order['status'] }}"
                        data-order-canonical="{{ $order['canonical_status'] }}"
                        data-order-owner="{{ $order['responsibility'] }}"
                        data-order-next="{{ $order['next_step'] }}"
                        data-order-id="{{ $order['id'] }}"
                        data-order-item-count="{{ $order['items'] }}"
                        data-order-deadline="{{ $order['deadline'] }}"
                        data-order-action="{{ $order['action'] }}"
                        data-order-date-copy="{{ $order['order_date'] ?? $order['date'] ?? $order['date_key'] ?? '' }}"
                        data-order-address="{{ $order['address'] ?? $order['shipping_address'] ?? '' }}"
                        data-order-sku="{{ $order['sku'] ?? '' }}"
                        data-order-product-image="{{ $order['product_image'] ?? $order['image'] ?? '' }}"
                        data-order-items-json="{{ e(json_encode($order['item_rows'] ?? $order['order_items'] ?? [])) }}"
                    >
                        <td><input type="checkbox" aria-label="Select {{ $order['id'] }}" data-order-check></td>
                        <td><button class="order-id-button" type="button" data-order-details="{{ $order['id'] }}" data-bearly-action-open data-bearly-action-mode="details">{{ $order['id'] }}</button></td>
                        <td><strong class="order-customer">{{ $order['customer'] }}</strong></td>
                        <td><span>{{ $order['items'] }}</span><small class="order-item-mini">{{ $order['item_detail'] }}</small></td>
                        <td><span class="payment-label payment-{{ $order['payment_key'] }}"><i data-lucide="{{ $order['payment_key'] === 'cod' ? 'banknote' : 'circle-check' }}"></i>{{ $order['payment'] }}</span></td>
                        <td><strong>{{ $order['total'] }}</strong></td>
                        <td><span class="deadline-label {{ $order['urgent'] ? 'is-urgent' : '' }}">{{ $order['deadline'] }} @if ($order['urgent'])<small>Action required</small>@endif</span></td>
                        <td>
                            <span class="order-status-badge order-status-{{ $order['tone'] }}">{{ $order['status'] }}</span>
                        </td>
                        <td>
                            @if ($order['action_url'])
                                <a class="order-row-action" href="{{ $order['action_url'] }}">{{ $order['action'] }}</a>
                            @else
                                <button class="order-row-action" type="button" data-order-details="{{ $order['id'] }}" data-bearly-action-open data-bearly-action-mode="action">{{ $order['action'] }}</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="orders-no-results" data-orders-no-results hidden><i data-lucide="search-x"></i><strong>No matching orders</strong><span>Try changing the search or filters.</span></div>
    </div>
    <footer class="orders-table-footer"><span>Showing <strong data-order-visible-count>{{ count($orders) }}</strong> of {{ count($orders) }} preview orders</span></footer>
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
<div class="seller-modal bearly-action-modal" data-bearly-action-modal hidden>
    <button class="modal-backdrop" type="button" data-bearly-action-close aria-label="Close order dialog"></button>
    <section class="bearly-action-card" role="dialog" aria-modal="true" aria-labelledby="bearly-action-title">
        <header class="bearly-action-header">
            <span class="bearly-action-header-icon" data-bearly-action-icon><i data-lucide="clipboard-check"></i></span>
            <div class="bearly-action-heading-copy">
                <span class="section-kicker">Order workflow</span>
                <h3 id="bearly-action-title" data-bearly-action-title>Order Details</h3>
                <p data-bearly-action-subtitle>Review the order information.</p>
            </div>
            <span class="bearly-action-badge" data-bearly-action-badge>Placed</span>
            <button class="bearly-action-x" type="button" data-bearly-action-close aria-label="Close"><i data-lucide="x"></i></button>
        </header>

        <section class="bearly-action-summary">
            <div><span>Order</span><strong data-bearly-order-id>—</strong></div>
            <div><span>Customer</span><strong data-bearly-order-customer>—</strong></div>
            <div><span data-bearly-date-label>Order Date</span><strong data-bearly-order-date>—</strong></div>
            <div><span>Payment Method</span><strong class="bearly-action-payment"><i data-lucide="circle-check"></i><b data-bearly-order-payment>—</b></strong></div>
            <div class="bearly-action-address-cell" data-bearly-address-cell><span data-bearly-address-label>Shipping Address</span><strong data-bearly-order-address>—</strong></div>
            <div><span>Total Amount</span><strong data-bearly-order-total>—</strong></div>
        </section>

        <section class="bearly-action-items-section">
            <div class="bearly-action-section-heading">
                <h4>Items <span data-bearly-item-count></span></h4>
            </div>
            <div class="bearly-action-items" data-bearly-items></div>
        </section>

        <div data-bearly-action-context></div>

        <footer class="bearly-action-footer" data-bearly-action-footer>
            <button class="bearly-action-secondary" type="button" data-bearly-action-close>Close</button>
            <button class="bearly-action-primary" type="button" data-bearly-action-primary>Continue</button>
        </footer>
    </section>
</div>

<style>
/* Orders actions only — intentionally scoped so the rest of Seller Center is untouched. */
.bearly-action-modal .bearly-action-card{position:relative;z-index:1;width:min(760px,calc(100vw - 38px));max-height:calc(100vh - 38px);overflow:auto;margin:auto;padding:22px;border:1px solid #e2d8ce;border-radius:15px;background:#fff;box-shadow:0 28px 80px rgba(43,29,22,.28);font-family:Poppins,sans-serif}.bearly-action-header{display:grid;grid-template-columns:52px minmax(0,1fr) auto 36px;align-items:start;gap:13px;padding-bottom:17px;border-bottom:1px solid var(--seller-line,#e7dfd6)}.bearly-action-header-icon{display:grid;width:50px;height:50px;place-items:center;border:1px solid #efd8b0;border-radius:11px;color:#a6610b;background:#fff4e2}.bearly-action-header-icon svg{width:22px;height:22px}.bearly-action-heading-copy{min-width:0}.bearly-action-heading-copy .section-kicker{display:block;margin:1px 0 3px;color:#9c5d0d;font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase}.bearly-action-heading-copy h3{margin:0;color:var(--seller-brown-950,#2b1d16);font-size:20px;line-height:1.3}.bearly-action-heading-copy p{margin:4px 0 0;color:var(--seller-muted,#746b65);font-size:12px;line-height:1.55}.bearly-action-badge{align-self:center;padding:6px 12px;border:1px solid #efd4a4;border-radius:999px;color:#985805;background:#fff3df;font-size:11px;font-weight:700;white-space:nowrap}.bearly-action-x{display:grid;width:34px;height:34px;place-items:center;padding:0;border:1px solid #ddd4cb;border-radius:8px;color:#6b615b;background:#fff;cursor:pointer}.bearly-action-x:hover{background:#fff8ed;border-color:#d8b783}.bearly-action-x svg{width:15px;height:15px}.bearly-action-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));margin-top:15px;overflow:hidden;border:1px solid var(--seller-line,#e7dfd6);border-radius:10px;background:#fdfbf8}.bearly-action-summary>div{display:flex;min-height:72px;flex-direction:column;justify-content:center;gap:4px;padding:11px 13px;border-right:1px solid var(--seller-line,#e7dfd6);border-bottom:1px solid var(--seller-line,#e7dfd6)}.bearly-action-summary>div:nth-child(3n){border-right:0}.bearly-action-summary>div:nth-last-child(-n+3){border-bottom:0}.bearly-action-summary span{color:#837970;font-size:11px}.bearly-action-summary strong{color:var(--seller-brown-950,#2b1d16);font-size:13px;line-height:1.45}.bearly-action-payment{display:flex;align-items:center;gap:6px}.bearly-action-payment svg{width:15px;height:15px;color:#a36210}.bearly-action-payment b{font:inherit}.bearly-action-address-cell strong{font-weight:600}.bearly-action-items-section{margin-top:15px}.bearly-action-section-heading{display:flex;align-items:center;justify-content:space-between;margin-bottom:7px}.bearly-action-section-heading h4{margin:0;color:#382d27;font-size:13px}.bearly-action-section-heading h4 span{color:#8a8078;font-weight:500}.bearly-action-items{overflow:hidden;border:1px solid var(--seller-line,#e7dfd6);border-radius:10px;background:#fff}.bearly-action-item{display:grid;grid-template-columns:50px minmax(0,1fr) auto;align-items:center;gap:11px;min-height:69px;padding:9px 12px}.bearly-action-item+.bearly-action-item{border-top:1px solid #eee7df}.bearly-action-thumb{display:grid;width:46px;height:46px;place-items:center;overflow:hidden;border:1px solid #eee1d2;border-radius:8px;color:#9c621b;background:#faf2e7}.bearly-action-thumb img{width:100%;height:100%;object-fit:cover}.bearly-action-thumb svg{width:20px;height:20px}.bearly-action-item-copy{min-width:0}.bearly-action-item-copy strong{display:block;overflow:hidden;color:#443a34;font-size:12px;font-weight:600;line-height:1.4;text-overflow:ellipsis;white-space:nowrap}.bearly-action-item-copy small{display:block;margin-top:3px;color:#8d8279;font-size:10px}.bearly-action-item-side{color:#5e534c;font-size:11px;font-weight:700;text-align:right}.bearly-action-notice,.bearly-action-next{display:flex;align-items:flex-start;gap:10px;margin-top:13px;padding:11px 12px;border:1px solid #efd4a5;border-radius:9px;color:#75460f;background:#fff7e8}.bearly-action-notice>span,.bearly-action-next>span{display:grid;width:27px;height:27px;flex:0 0 27px;place-items:center;border-radius:50%;color:#a25e08;background:#ffe5b2}.bearly-action-notice svg,.bearly-action-next svg{width:14px;height:14px}.bearly-action-notice p,.bearly-action-next p{margin:3px 0 0;font-size:11px;line-height:1.55}.bearly-action-next strong,.bearly-action-next small{display:block}.bearly-action-next small{color:#9b8063;font-size:10px}.bearly-action-next strong{margin-top:2px;color:#5f472f;font-size:12px}.bearly-action-check-card{margin-top:13px;overflow:hidden;border:1px solid #ead2a8;border-radius:9px;background:#fff9ed}.bearly-action-check-head{display:flex;align-items:center;justify-content:space-between;padding:11px 12px;border-bottom:1px solid #eddfc8}.bearly-action-check-head strong{font-size:12px}.bearly-action-check-head span{color:#966018;font-size:10px;font-weight:600}.bearly-action-check{display:flex;min-height:40px;align-items:center;gap:9px;padding:0 12px;color:#5e534c;font-size:11px;cursor:pointer}.bearly-action-check+.bearly-action-check{border-top:1px solid #eee1cd}.bearly-action-check input{width:16px;height:16px;accent-color:var(--seller-gold-dark,#d88b12)}.bearly-action-shipping{margin-top:13px}.bearly-action-shipping h4,.bearly-action-status-title{margin:0 0 7px;color:#3a302a;font-size:13px}.bearly-action-address-box{display:flex;align-items:flex-start;gap:10px;padding:11px 12px;border:1px solid var(--seller-line,#e7dfd6);border-radius:9px;background:#fdfbf8}.bearly-action-address-box svg{width:18px;height:18px;margin-top:1px;color:#9c6115}.bearly-action-address-box strong,.bearly-action-address-box small{display:block}.bearly-action-address-box strong{font-size:11px}.bearly-action-address-box small{margin-top:2px;color:#776d66;font-size:10px}.bearly-action-waybill-preview{display:grid;grid-template-columns:110px minmax(0,1fr) auto;align-items:center;gap:12px;margin-top:9px;padding:10px 12px;border:1px solid #e6ddd5;border-radius:9px;background:#fff}.bearly-action-barcode{height:45px;border:1px solid #d8cec5;border-radius:5px;background:repeating-linear-gradient(90deg,#2f2824 0 2px,transparent 2px 5px,#2f2824 5px 6px,transparent 6px 9px)}.bearly-action-waybill-preview strong,.bearly-action-waybill-preview small{display:block}.bearly-action-waybill-preview strong{font-size:11px}.bearly-action-waybill-preview small{margin-top:2px;color:#837970;font-size:9px}.bearly-action-print-pill{display:inline-flex;align-items:center;gap:5px;padding:8px 10px;border:1px solid #ead9bf;border-radius:7px;color:#8d5610;background:#fff8ec;font-size:10px;font-weight:700}.bearly-action-status-layout{display:grid;grid-template-columns:minmax(0,1fr) 250px;gap:17px;margin-top:14px}.bearly-action-status-layout .bearly-action-items-section{margin-top:0}.bearly-action-timeline{margin:0;padding:0;list-style:none}.bearly-action-timeline li{position:relative;display:grid;grid-template-columns:25px minmax(0,1fr);gap:9px;min-height:48px}.bearly-action-timeline li:not(:last-child)::after{content:"";position:absolute;top:23px;bottom:0;left:10px;width:2px;background:#e5dcd3}.bearly-action-timeline-marker{position:relative;z-index:1;display:grid;width:22px;height:22px;place-items:center;border:2px solid #ddd3ca;border-radius:50%;color:#a79d95;background:#fff}.bearly-action-timeline-marker svg{width:11px;height:11px}.bearly-action-timeline li.is-done .bearly-action-timeline-marker,.bearly-action-timeline li.is-current .bearly-action-timeline-marker{border-color:var(--seller-gold-dark,#d88b12);color:#fff;background:var(--seller-gold-dark,#d88b12)}.bearly-action-timeline li.is-done:not(:last-child)::after{background:#e2b363}.bearly-action-timeline-copy strong,.bearly-action-timeline-copy small{display:block}.bearly-action-timeline-copy strong{color:#544942;font-size:11px}.bearly-action-timeline-copy small{margin-top:2px;color:#8c8179;font-size:9px}.bearly-action-timeline li.is-current .bearly-action-timeline-copy small{color:#a05e0d;font-weight:600}.bearly-action-footer{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:16px;padding-top:14px;border-top:1px solid var(--seller-line,#e7dfd6)}.bearly-action-footer button{min-height:40px;border-radius:7px;font:700 12px/1 Poppins,sans-serif;cursor:pointer}.bearly-action-secondary{border:1px solid #d98a1f;color:#a35d05;background:#fff}.bearly-action-secondary:hover{background:#fff9f0}.bearly-action-primary{border:1px solid var(--seller-gold,#e9a72f);color:#35220e;background:var(--seller-gold,#e9a72f);box-shadow:0 5px 13px rgba(216,139,18,.15)}.bearly-action-primary:hover{border-color:var(--seller-gold-dark,#d88b12);background:var(--seller-gold-dark,#d88b12)}.bearly-action-primary:disabled{border-color:#e8d6b8;color:#a49277;background:#e9decb;box-shadow:none;cursor:not-allowed}.bearly-action-footer.is-read-only{grid-template-columns:1fr}.bearly-action-footer.is-read-only .bearly-action-primary{display:none}
@media(max-width:720px){.bearly-action-modal .bearly-action-card{width:100%;padding:17px}.bearly-action-header{grid-template-columns:43px minmax(0,1fr) 34px}.bearly-action-header-icon{width:42px;height:42px}.bearly-action-badge{grid-column:2;grid-row:2;justify-self:start}.bearly-action-x{grid-column:3;grid-row:1}.bearly-action-summary{grid-template-columns:1fr 1fr}.bearly-action-summary>div{border-right:1px solid var(--seller-line,#e7dfd6)!important;border-bottom:1px solid var(--seller-line,#e7dfd6)!important}.bearly-action-summary>div:nth-child(2n){border-right:0!important}.bearly-action-summary>div:nth-last-child(-n+2){border-bottom:0!important}.bearly-action-status-layout{grid-template-columns:1fr}.bearly-action-footer{grid-template-columns:1fr}.bearly-action-item{grid-template-columns:42px minmax(0,1fr)}.bearly-action-item-side{grid-column:2;text-align:left}.bearly-action-waybill-preview{grid-template-columns:90px minmax(0,1fr)}}
@media(max-width:470px){.bearly-action-summary{grid-template-columns:1fr}.bearly-action-summary>div{border-right:0!important}.bearly-action-summary>div:not(:last-child){border-bottom:1px solid var(--seller-line,#e7dfd6)!important}.bearly-action-header{grid-template-columns:43px minmax(0,1fr) 34px}}
</style>

<script>
(() => {
    const modal = document.querySelector('[data-bearly-action-modal]');
    if (!modal || modal.dataset.bound === '1') return;
    modal.dataset.bound = '1';

    const q = (selector) => modal.querySelector(selector);
    const title = q('[data-bearly-action-title]');
    const subtitle = q('[data-bearly-action-subtitle]');
    const icon = q('[data-bearly-action-icon]');
    const badge = q('[data-bearly-action-badge]');
    const orderId = q('[data-bearly-order-id]');
    const customer = q('[data-bearly-order-customer]');
    const orderDate = q('[data-bearly-order-date]');
    const payment = q('[data-bearly-order-payment]');
    const total = q('[data-bearly-order-total]');
    const address = q('[data-bearly-order-address]');
    const addressCell = q('[data-bearly-address-cell]');
    const addressLabel = q('[data-bearly-address-label]');
    const itemCount = q('[data-bearly-item-count]');
    const items = q('[data-bearly-items]');
    const context = q('[data-bearly-action-context]');
    const primary = q('[data-bearly-action-primary]');
    const footer = q('[data-bearly-action-footer]');
    let lastFocus = null;
    let active = null;

    const statusLabels = {
        PLACED:'Placed', CONFIRMED:'Confirmed', PREPARING:'Preparing', PACKED:'Packed',
        READY_FOR_PICKUP:'Ready for Pickup', PICKED_UP:'Picked Up', AT_SORTING_CENTER:'At Sorting Center',
        SORTED:'Sorted', ASSIGNED_TO_RIDER:'Rider Assigned', OUT_FOR_DELIVERY:'Out for Delivery',
        DELIVERED:'Delivered', COMPLETED:'Completed', DELIVERY_FAILED:'Delivery Failed', RETURNED:'Returned', CANCELLED:'Cancelled'
    };
    const flow = [
        ['PLACED','Placed'],['CONFIRMED','Confirmed'],['PREPARING','Preparing'],['PACKED','Packed'],
        ['READY_FOR_PICKUP','Ready for Pickup'],['PICKED_UP','Picked Up'],['AT_SORTING_CENTER','At Sorting Center'],
        ['SORTED','Sorted'],['ASSIGNED_TO_RIDER','Rider Assigned'],['OUT_FOR_DELIVERY','Out for Delivery'],
        ['DELIVERED','Delivered'],['COMPLETED','Completed']
    ];

    const configFor = (row, mode) => {
        const status = (row.dataset.orderCanonical || '').toUpperCase();
        const action = (row.dataset.orderAction || '').toLowerCase();
        if (mode === 'details' || action.includes('view detail') || action.includes('track')) return {type:'details', title:'Order Details', subtitle:'View the complete details and current status of this order.', icon:'info', button:null};
        if (action.includes('review') || action.includes('confirm') || status === 'PLACED') return {type:'review', title:'Review and Confirm Order', subtitle:'Check the order details and confirm if you can fulfill this order.', icon:'clipboard-check', button:'Confirm Order'};
        if (action.includes('start preparing') || status === 'CONFIRMED') return {type:'prepare', title:'Start Preparing Order', subtitle:'Prepare the correct items and organize them for packing.', icon:'package-check', button:'Start Preparing'};
        if (action.includes('continue packing') || action.includes('packing') || status === 'PREPARING') return {type:'packing', title:'Continue Packing', subtitle:'Finish packing and get the parcel ready for its shipping label.', icon:'package', button:'Continue Packing'};
        if (action.includes('waybill') || action.includes('label') || status === 'PACKED') return {type:'waybill', title:'Print Waybill / Label', subtitle:'Generate and print the shipping label for this order.', icon:'printer', button:'Print Waybill'};
        if (action.includes('ready') || action.includes('pickup') || status === 'READY_FOR_PICKUP') return {type:'ready', title:'Mark as Ready for Pickup', subtitle:'Confirm that the parcel is packed, labeled, and ready for pickup.', icon:'truck', button:'Mark as Ready'};
        return {type:'details', title:'Order Details', subtitle:'View the complete details and current status of this order.', icon:'info', button:null};
    };

    const productIcon = text => {
        const v = text.toLowerCase();
        if (v.includes('shirt') || v.includes('dress') || v.includes('top')) return 'shirt';
        if (v.includes('bag') || v.includes('tote')) return 'shopping-bag';
        if (v.includes('shoe') || v.includes('sneaker')) return 'footprints';
        if (v.includes('watch')) return 'watch';
        return 'package';
    };

    const paymentText = row => {
        const raw = (row.dataset.orderPaymentCopy || '').trim();
        const key = (row.dataset.orderPaymentKey || row.dataset.payment || '').trim().toLowerCase();
        const canonical = (row.dataset.orderCanonical || '').trim().toLowerCase();
        const statusCopy = (row.dataset.orderStatusCopy || '').trim().toLowerCase();
        const badValues = new Set(['cancelled','returned','completed','delivered','preparing','confirmed','placed','packed','ready for pickup','delivery failed']);
        if (raw && !badValues.has(raw.toLowerCase()) && raw.toLowerCase() !== canonical && raw.toLowerCase() !== statusCopy) return raw;
        if (key === 'cod') return 'Cash on Delivery';
        if (key === 'paid') return 'Paid';
        return 'Payment details unavailable';
    };

    const formatOrderDate = rawValue => {
        const raw = String(rawValue || '').trim();
        if (!raw) return 'Not available in preview';
        const lower = raw.toLowerCase();
        if (lower === 'today') {
            return new Intl.DateTimeFormat('en-PH', {month:'short', day:'numeric', year:'numeric'}).format(new Date());
        }
        if (['upcoming','previous','upcoming / previous'].includes(lower)) return 'Not available in preview';
        const parsed = new Date(raw);
        if (!Number.isNaN(parsed.getTime())) {
            const hasTime = /\d{1,2}:\d{2}|t\d{2}:\d{2}/i.test(raw);
            const options = {month:'short', day:'numeric', year:'numeric'};
            if (hasTime) Object.assign(options, {hour:'numeric', minute:'2-digit'});
            return new Intl.DateTimeFormat('en-PH', options).format(parsed);
        }
        return raw;
    };

    const parseItemsJson = row => {
        const raw = (row.dataset.orderItemsJson || '').trim();
        if (!raw) return [];
        try {
            const value = JSON.parse(raw);
            return Array.isArray(value) ? value : [];
        } catch (_) {
            return [];
        }
    };

    const itemImage = item => item.image || item.product_image || item.image_url || item.thumbnail || '';
    const itemName = item => item.name || item.product_name || item.title || 'Order item';
    const itemVariant = item => item.variant || item.variation || item.option || '';
    const itemSku = item => item.sku || item.product_sku || '';
    const itemQty = item => Number(item.qty ?? item.quantity ?? 1) || 1;
    const itemPriceText = item => {
        const value = item.unit_price ?? item.price ?? item.amount ?? null;
        if (value === null || value === '') return '';
        if (typeof value === 'number' || /^\d+(\.\d+)?$/.test(String(value))) {
            return new Intl.NumberFormat('en-PH', {style:'currency', currency:'PHP', maximumFractionDigits:2}).format(Number(value));
        }
        return String(value);
    };

    const renderItems = row => {
        items.replaceChildren();
        const databaseItems = parseItemsJson(row);

        if (databaseItems.length) {
            databaseItems.forEach((item, index) => {
                const nameText = itemName(item);
                const article = document.createElement('article');
                article.className = 'bearly-action-item';

                const thumb = document.createElement('span');
                thumb.className = 'bearly-action-thumb';
                const image = itemImage(item);
                if (image) {
                    const img = document.createElement('img');
                    img.src = image;
                    img.alt = nameText;
                    img.loading = 'lazy';
                    img.addEventListener('error', () => {
                        thumb.replaceChildren();
                        thumb.innerHTML = `<i data-lucide="${productIcon(nameText)}" aria-hidden="true"></i>`;
                        window.lucide?.createIcons();
                    }, {once:true});
                    thumb.append(img);
                } else {
                    thumb.innerHTML = `<i data-lucide="${productIcon(nameText)}" aria-hidden="true"></i>`;
                }

                const copy = document.createElement('div');
                copy.className = 'bearly-action-item-copy';
                const name = document.createElement('strong');
                name.textContent = [nameText, itemVariant(item)].filter(Boolean).join(' · ');
                const meta = document.createElement('small');
                const skuText = itemSku(item);
                meta.textContent = skuText ? `SKU: ${skuText}` : 'Order item';
                copy.append(name, meta);

                const side = document.createElement('span');
                side.className = 'bearly-action-item-side';
                const price = itemPriceText(item);
                side.textContent = price ? `${price} × ${itemQty(item)}` : `Qty ${itemQty(item)}`;
                article.append(thumb, copy, side);
                items.append(article);
            });
            itemCount.textContent = `(${databaseItems.reduce((sum, item) => sum + itemQty(item), 0)} ${databaseItems.length === 1 && itemQty(databaseItems[0]) === 1 ? 'item' : 'items'})`;
            return;
        }

        const raw = (row.dataset.orderItems || '').trim();
        const parts = raw ? raw.split(/\s*\+\s*/).filter(Boolean) : ['Order item'];
        const sku = (row.dataset.orderSku || '').trim();
        const image = (row.dataset.orderProductImage || '').trim();
        parts.forEach((part, index) => {
            const article = document.createElement('article');
            article.className = 'bearly-action-item';
            const thumb = document.createElement('span');
            thumb.className = 'bearly-action-thumb';
            if (image && index === 0) {
                const img = document.createElement('img');
                img.src = image;
                img.alt = part.trim();
                img.loading = 'lazy';
                img.addEventListener('error', () => {
                    thumb.replaceChildren();
                    thumb.innerHTML = `<i data-lucide="${productIcon(part)}" aria-hidden="true"></i>`;
                    window.lucide?.createIcons();
                }, {once:true});
                thumb.append(img);
            } else {
                thumb.innerHTML = `<i data-lucide="${productIcon(part)}" aria-hidden="true"></i>`;
            }
            const copy = document.createElement('div'); copy.className = 'bearly-action-item-copy';
            const name = document.createElement('strong'); name.textContent = part.trim();
            const meta = document.createElement('small'); meta.textContent = sku && index === 0 ? `SKU: ${sku}` : 'Order item';
            copy.append(name, meta);
            const side = document.createElement('span'); side.className = 'bearly-action-item-side'; side.textContent = parts.length === 1 ? (row.dataset.orderTotal || '') : `Item ${index + 1}`;
            article.append(thumb, copy, side); items.append(article);
        });
        itemCount.textContent = `(${row.dataset.orderItemCount || parts.length})`;
    };

    const notice = (iconName, text) => `<section class="bearly-action-notice"><span><i data-lucide="${iconName}"></i></span><p>${text}</p></section>`;

    const renderContext = (row, cfg) => {
        context.innerHTML = '';
        if (cfg.type === 'review') {
            context.innerHTML = notice('info', 'By confirming, you accept this order and will prepare it for pickup.');
        } else if (cfg.type === 'prepare') {
            context.innerHTML = `<section class="bearly-action-next"><span><i data-lucide="package-check"></i></span><div><small>Before packing</small><strong>Verify the item, variation, quantity, and condition against the order.</strong></div></section>`;
        } else if (cfg.type === 'packing') {
            context.innerHTML = `<section class="bearly-action-check-card"><div class="bearly-action-check-head"><strong>Packing Checklist</strong><span data-bearly-check-count>0/3 Completed</span></div><label class="bearly-action-check"><input type="checkbox" data-bearly-pack-check><span>Correct item and variation are packed</span></label><label class="bearly-action-check"><input type="checkbox" data-bearly-pack-check><span>Order quantity is complete</span></label><label class="bearly-action-check"><input type="checkbox" data-bearly-pack-check><span>Parcel is secure and in good condition</span></label></section>`;
            const checks = [...context.querySelectorAll('[data-bearly-pack-check]')];
            const counter = context.querySelector('[data-bearly-check-count]');
            primary.disabled = true;
            checks.forEach(check => check.addEventListener('change', () => {
                const done = checks.filter(c => c.checked).length;
                counter.textContent = `${done}/3 Completed`;
                primary.disabled = done !== checks.length;
            }));
        } else if (cfg.type === 'waybill') {
            const ship = row.dataset.orderAddress || 'Shipping address will appear here when connected to order data.';
            context.innerHTML = `<section class="bearly-action-shipping"><h4>Shipping Details</h4><div class="bearly-action-address-box"><i data-lucide="map-pin"></i><div><strong>Delivery Address</strong><small></small></div></div><div class="bearly-action-waybill-preview"><div class="bearly-action-barcode" aria-hidden="true"></div><div><strong>Order ${escapeHtml(row.dataset.orderId || '')}</strong><small>Shipping label preview with recipient and order details.</small></div><span class="bearly-action-print-pill"><i data-lucide="printer"></i>Print Preview</span></div>${notice('printer','Print the waybill and attach it securely to the package.')}</section>`;
            context.querySelector('.bearly-action-address-box small').textContent = ship;
        } else if (cfg.type === 'ready') {
            context.innerHTML = notice('truck', 'Once marked as ready, this parcel can proceed to logistics pickup. No delivery agency is assigned here.');
        } else {
            const current = (row.dataset.orderCanonical || '').toUpperCase();
            let timelineFlow = flow;
            if (current === 'CANCELLED') timelineFlow = [['PLACED','Placed'],['CANCELLED','Cancelled']];
            if (current === 'RETURNED') timelineFlow = [...flow.slice(0,10),['RETURNED','Returned']];
            if (current === 'DELIVERY_FAILED') timelineFlow = [...flow.slice(0,10),['DELIVERY_FAILED','Delivery Failed']];
            let idx = timelineFlow.findIndex(([key]) => key === current);
            if (idx < 0 && current === 'PACKED') idx = timelineFlow.findIndex(([key]) => key === 'PACKED');
            if (idx < 0) idx = 0;
            const wrap = document.createElement('section'); wrap.className = 'bearly-action-status-layout';
            const left = document.createElement('div');
            const h = document.createElement('h4'); h.className = 'bearly-action-status-title'; h.textContent = 'Current Order Status'; left.append(h);
            if (current === 'CANCELLED') left.append(Object.assign(document.createElement('div'), {className:'bearly-action-notice'}));
            const right = document.createElement('ol'); right.className = 'bearly-action-timeline';
            timelineFlow.forEach(([key,label], i) => {
                const li = document.createElement('li'); li.className = i < idx ? 'is-done' : i === idx ? 'is-current' : '';
                const marker = document.createElement('span'); marker.className = 'bearly-action-timeline-marker'; if (i <= idx) marker.innerHTML = '<i data-lucide="check"></i>';
                const copy = document.createElement('div'); copy.className = 'bearly-action-timeline-copy';
                const strong = document.createElement('strong'); strong.textContent = label; copy.append(strong);
                if (i === idx) { const small = document.createElement('small'); small.textContent = 'Current status'; copy.append(small); }
                li.append(marker, copy); right.append(li);
            });
            const summary = document.createElement('div'); summary.className = 'bearly-action-next';
            if (current === 'CANCELLED') {
                summary.innerHTML = '<span><i data-lucide="info"></i></span><div><small>Order closed</small><strong>This order is cancelled. No further seller fulfillment action is required.</strong></div>';
            } else {
                summary.innerHTML = '<span><i data-lucide="info"></i></span><div><small>Next update</small><strong></strong></div>';
                summary.querySelector('strong').textContent = row.dataset.orderNext || 'No seller action is required right now.';
            }
            left.append(summary); wrap.append(left, right); context.append(wrap);
        }
    };

    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    const open = (row, mode, trigger) => {
        active = {row, mode, trigger}; lastFocus = trigger;
        const cfg = configFor(row, mode);
        title.textContent = cfg.title; subtitle.textContent = cfg.subtitle; icon.innerHTML = `<i data-lucide="${cfg.icon}"></i>`;
        const canonical = (row.dataset.orderCanonical || '').toUpperCase(); badge.textContent = row.dataset.orderStatusCopy || statusLabels[canonical] || 'Order';
        orderId.textContent = row.dataset.orderId || trigger.dataset.orderDetails || '—'; customer.textContent = row.dataset.orderCustomer || '—';
        orderDate.textContent = formatOrderDate(row.dataset.orderDateCopy || row.dataset.date); payment.textContent = paymentText(row); total.textContent = row.dataset.orderTotal || '—';
        const addr = (row.dataset.orderAddress || '').trim();
        if (['review','prepare','packing'].includes(cfg.type)) {
            addressLabel.textContent = 'Order Status';
            address.textContent = row.dataset.orderStatusCopy || statusLabels[canonical] || '—';
        } else {
            addressLabel.textContent = 'Shipping Address';
            address.textContent = addr || 'Not available in preview';
        }
        addressCell.hidden = false;
        renderItems(row); primary.disabled = false; primary.hidden = !cfg.button; primary.textContent = cfg.button || ''; footer.classList.toggle('is-read-only', !cfg.button);
        renderContext(row, cfg); modal.hidden = false; document.body.classList.add('modal-open'); window.lucide?.createIcons();
        (cfg.button ? primary : q('.bearly-action-x'))?.focus();
    };

    const close = () => { modal.hidden = true; document.body.classList.remove('modal-open'); lastFocus?.focus?.(); active = null; };
    const toast = message => { const el = document.querySelector('[data-seller-toast]'); if (!el) return; el.textContent = message; el.classList.add('is-visible'); clearTimeout(window.__bearlyActionToast); window.__bearlyActionToast = setTimeout(() => el.classList.remove('is-visible'), 3000); };

    document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-bearly-action-open]');
        if (!trigger) return;
        const row = trigger.closest('[data-order-row]'); if (!row) return;
        event.preventDefault(); event.stopPropagation(); event.stopImmediatePropagation();
        open(row, trigger.dataset.bearlyActionMode || 'action', trigger);
    }, true);

    modal.querySelectorAll('[data-bearly-action-close]').forEach(button => button.addEventListener('click', close));
    primary.addEventListener('click', () => {
        if (!active) return;
        const cfg = configFor(active.row, active.mode);
        toast(`${cfg.button} previewed. Backend status change is not connected yet.`);
        close();
    });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && !modal.hidden) close(); });
})();
</script>
<script type="application/json" data-default-order-status>@json($activeOrderStatus)</script>
@endsection

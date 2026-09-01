@extends('layouts.seller')

@section('title', 'Orders')
@section('page-title', 'Orders')

@section('content')
<div class="page-heading orders-page-heading">
    <div>
        <h2>Order Management</h2>
        <p>Review, prepare, and track customer orders.</p>
    </div>
    <button class="orders-export-button" type="button" data-order-demo="Order list exported for preview.">
        <i data-lucide="download"></i>
        Export orders
    </button>
</div>

<section class="order-queue" aria-label="Orders requiring action">
    @foreach ($orderQueue as $queue)
        <article class="order-queue-item queue-{{ $queue['tone'] }}">
            <span class="order-queue-icon"><i data-lucide="{{ $queue['icon'] }}"></i></span>
            <div>
                <span>{{ $queue['label'] }}</span>
                <strong>{{ $queue['count'] }}</strong>
                <small>{{ $queue['note'] }}</small>
            </div>
        </article>
    @endforeach
</section>

<section class="orders-workspace" data-orders-workspace>
    <div class="order-tabs" role="tablist" aria-label="Filter orders by fulfillment status">
        @foreach ($orderTabs as $tab)
            <button
                class="order-tab {{ $tab['key'] === 'all' ? 'is-active' : '' }}"
                type="button"
                role="tab"
                aria-selected="{{ $tab['key'] === 'all' ? 'true' : 'false' }}"
                data-order-tab="{{ $tab['key'] }}"
            >
                {{ $tab['label'] }}
                @if ($tab['count'] !== null)<span>{{ $tab['count'] }}</span>@endif
            </button>
        @endforeach
    </div>

    <div class="order-toolbar">
        <label class="order-search">
            <i data-lucide="search"></i>
            <span class="sr-only">Search orders</span>
            <input type="search" placeholder="Search order ID or customer" data-order-search>
        </label>

        <label class="order-select">
            <i data-lucide="calendar-days"></i>
            <span class="sr-only">Filter by date</span>
            <select data-order-date>
                <option value="">All dates</option>
                <option value="today">Today</option>
                <option value="upcoming">Upcoming</option>
            </select>
        </label>

        <label class="order-select">
            <i data-lucide="credit-card"></i>
            <span class="sr-only">Filter by payment</span>
            <select data-order-payment>
                <option value="">All payments</option>
                <option value="paid">Paid</option>
                <option value="cod">Cash on Delivery</option>
            </select>
        </label>

        <button class="order-filter-reset" type="button" data-order-reset>
            <i data-lucide="list-filter"></i>
            Reset
        </button>
    </div>

    <div class="order-bulk-bar" data-order-bulk-bar>
        <label class="order-check-all">
            <input type="checkbox" data-order-check-all>
            <span><strong data-order-selected-count>0</strong> selected</span>
        </label>
        <div class="order-bulk-actions">
            <button type="button" disabled data-order-bulk-action="Print waybill"><i data-lucide="printer"></i>Print waybill</button>
            <button type="button" disabled data-order-bulk-action="Schedule pickup"><i data-lucide="truck"></i>Schedule pickup</button>
            <button type="button" disabled data-order-bulk-action="Update status"><i data-lucide="refresh-cw"></i>Update status</button>
        </div>
    </div>

    <div class="orders-table-wrap">
        <table class="orders-table">
            <thead>
                <tr>
                    <th aria-label="Select order"></th>
                    <th>Order</th>
                    <th>Customer</th>
                    <th>Items</th>
                    <th>Payment</th>
                    <th>Total</th>
                    <th>Fulfillment deadline</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($orders as $order)
                    <tr
                        data-order-row
                        data-status="{{ $order['status_key'] }}"
                        data-date="{{ $order['date_key'] }}"
                        data-payment="{{ $order['payment_key'] }}"
                        data-search="{{ strtolower($order['id'].' '.$order['customer']) }}"
                    >
                        <td><input type="checkbox" aria-label="Select {{ $order['id'] }}" data-order-check></td>
                        <td><button class="order-id-button" type="button" data-order-details="{{ $order['id'] }}">{{ $order['id'] }}</button></td>
                        <td><strong class="order-customer">{{ $order['customer'] }}</strong></td>
                        <td>{{ $order['items'] }}</td>
                        <td>
                            <span class="payment-label payment-{{ $order['payment_key'] }}">
                                <i data-lucide="{{ $order['payment_key'] === 'cod' ? 'banknote' : 'circle-check' }}"></i>
                                {{ $order['payment'] }}
                            </span>
                        </td>
                        <td><strong>{{ $order['total'] }}</strong></td>
                        <td>
                            <span class="deadline-label {{ $order['urgent'] ? 'is-urgent' : '' }}">
                                {{ $order['deadline'] }}
                                @if ($order['urgent'])<small>Action required</small>@endif
                            </span>
                        </td>
                        <td><span class="order-status-badge order-status-{{ $order['tone'] }}">{{ $order['status'] }}</span></td>
                        <td>
                            <button class="order-row-action" type="button" data-order-demo="{{ $order['action'] }} opened for {{ $order['id'] }}.">
                                {{ $order['action'] }}
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="orders-no-results" data-orders-no-results hidden>
            <i data-lucide="search-x"></i>
            <strong>No matching orders</strong>
            <span>Try changing the search or filters.</span>
        </div>
    </div>

    <footer class="orders-table-footer">
        <span>Showing <strong data-order-visible-count>{{ count($orders) }}</strong> of {{ count($orders) }} preview orders</span>
        <span>Frontend preview data</span>
    </footer>
</section>

<section class="courier-handover-card" aria-labelledby="courier-handover-title">
    <div class="courier-handover-main">
        <span class="courier-handover-icon"><i data-lucide="truck"></i></span>
        <div>
            <span class="section-kicker">Courier handover</span>
            <h3 id="courier-handover-title">Today’s pickup</h3>
            <p>Pickup at <strong>3:00 PM</strong> · Laguna route · <strong>2 orders ready</strong></p>
        </div>
    </div>
    <div class="courier-handover-progress" aria-label="Two of five orders ready for pickup">
        <div><span>Pickup readiness</span><strong>2 of 5 ready</strong></div>
        <span><i style="width: 40%"></i></span>
    </div>
    <button class="seller-secondary-button" type="button" data-order-demo="Courier handover checklist opened.">
        View handover checklist
        <i data-lucide="arrow-right"></i>
    </button>
</section>

<div class="seller-modal order-details-modal" data-modal="order-details" hidden>
    <button class="modal-backdrop" type="button" data-modal-close aria-label="Close order details"></button>
    <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="order-details-title">
        <div class="modal-heading">
            <div><span class="section-kicker">Order preview</span><h3 id="order-details-title">Order details</h3></div>
            <button type="button" data-modal-close aria-label="Close"><i data-lucide="x"></i></button>
        </div>
        <div class="order-detail-preview">
            <p><span>Order</span><strong data-order-detail-id>—</strong></p>
            <p><span>Preview mode</span><strong>No database connected</strong></p>
        </div>
        <p class="order-detail-note">The complete customer address, item variants, payment confirmation, and courier assignment will be connected during backend development.</p>
        <div class="modal-actions">
            <button class="draft-button" type="button" data-modal-close>Close</button>
            <button class="seller-primary-button" type="button" data-order-demo="Order workflow opened in preview mode.">Continue workflow</button>
        </div>
    </section>
</div>
@endsection

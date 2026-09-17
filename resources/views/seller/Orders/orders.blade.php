@extends('layouts.seller')

@section('title', 'Orders')
@section('page-title', 'Orders')

@section('content')
@php($activeOrderStatus = $defaultOrderStatus ?? 'all')

<span data-default-order-status hidden>@json($activeOrderStatus)</span>

<div class="page-heading orders-page-heading">
    <div>
        <h2>Order Management</h2>
        <p>Confirm orders, prepare parcels, and monitor delivery.</p>
    </div>
    <button class="orders-export-button" type="button" data-order-demo="Order list exported for preview.">
        <i data-lucide="download"></i> Export orders
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
    <div class="order-tabs" role="tablist" aria-label="Filter orders by seller-facing status">
        @foreach ($orderTabs as $tab)
            <button
                class="order-tab {{ $tab['key'] === $activeOrderStatus ? 'is-active' : '' }}"
                type="button"
                role="tab"
                aria-selected="{{ $tab['key'] === $activeOrderStatus ? 'true' : 'false' }}"
                data-order-tab="{{ $tab['key'] }}"
            >
                {{ $tab['label'] }}
                @if ($tab['count'] !== null)
                    <span>{{ $tab['count'] }}</span>
                @endif
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
                <option value="upcoming">Upcoming / previous</option>
            </select>
        </label>

        <label class="order-select">
            <i data-lucide="credit-card"></i>
            <span class="sr-only">Filter by payment</span>
            <select data-order-payment>
                <option value="">All payments</option>
                <option value="paid">Paid / Refunded</option>
                <option value="cod">Cash on Delivery</option>
            </select>
        </label>

        <button class="order-filter-reset" type="button" data-order-reset>
            <i data-lucide="list-filter"></i> Reset
        </button>
    </div>

    <div class="order-bulk-bar" data-order-bulk-bar>
        <label class="order-check-all">
            <input type="checkbox" data-order-check-all>
            <span><strong data-order-selected-count>0</strong> selected</span>
        </label>

        <div class="order-bulk-actions">
            <button type="button" disabled data-order-bulk-action="Print waybill">
                <i data-lucide="printer"></i>Print waybill
            </button>
            <button type="button" disabled data-order-bulk-action="Arrange pickup">
                <i data-lucide="truck"></i>Arrange pickup
            </button>
        </div>
    </div>

    <div class="orders-table-wrap">
        <table class="orders-table orders-table-polished">
            <thead>
                <tr>
                    <th aria-label="Select order"></th>
                    <th>Order</th>
                    <th>Customer</th>
                    <th>Items</th>
                    <th>Payment</th>
                    <th>Total</th>
                    <th>Deadline / Update</th>
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
                        <td>
                            <input type="checkbox" aria-label="Select {{ $order['id'] }}" data-order-check>
                        </td>
                        <td>
                            <button
                                class="order-id-button"
                                type="button"
                                data-order-details="{{ $order['id'] }}"
                                data-bearly-action-open
                                data-bearly-action-mode="details"
                            >
                                {{ $order['id'] }}
                            </button>
                        </td>
                        <td><strong class="order-customer">{{ $order['customer'] }}</strong></td>
                        <td>
                            <span>{{ $order['items'] }}</span>
                            <small class="order-item-mini">{{ $order['item_detail'] }}</small>
                        </td>
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
                                @if ($order['urgent'])
                                    <small>Action required</small>
                                @endif
                            </span>
                        </td>
                        <td>
                            <span class="order-status-badge order-status-{{ $order['tone'] }}">
                                {{ $order['status'] }}
                            </span>
                        </td>
                        <td>
                            @if ($order['action_url'])
                                <a class="order-row-action" href="{{ $order['action_url'] }}">
                                    {{ $order['action'] }}
                                </a>
                            @else
                                <button
                                    class="order-row-action"
                                    type="button"
                                    data-order-details="{{ $order['id'] }}"
                                    data-bearly-action-open
                                    data-bearly-action-mode="action"
                                >
                                    {{ $order['action'] }}
                                </button>
                            @endif
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
        <span>
            Showing <strong data-order-visible-count>{{ count($orders) }}</strong>
            of {{ count($orders) }} preview orders
        </span>
    </footer>
</section>

<section class="orders-handoff-grid">
    <article class="seller-panel handoff-card">
        <span class="handoff-icon"><i data-lucide="printer"></i></span>
        <div>
            <span class="section-kicker">Step 1</span>
            <h3>Prepare the parcel & waybill</h3>
            <p>Waybill creation belongs to Fulfillment. Orders only send eligible parcels into that workspace.</p>
        </div>
        <a class="seller-secondary-button" href="{{ route('seller.fulfillment.waybills') }}">
            Open Waybills <i data-lucide="arrow-right"></i>
        </a>
    </article>

    <article class="seller-panel handoff-card">
        <span class="handoff-icon"><i data-lucide="truck"></i></span>
        <div>
            <span class="section-kicker">Step 2</span>
            <h3>Arrange logistics pickup</h3>
            <p>After a labeled parcel is ready, submit the pickup request. Logistics controls approval and rider assignment.</p>
        </div>
        <a class="seller-secondary-button" href="{{ route('seller.fulfillment.pickups') }}">
            Open Pickup Requests <i data-lucide="arrow-right"></i>
        </a>
    </article>
</section>

<div class="seller-modal bearly-action-modal" data-bearly-action-modal hidden>
    <button class="modal-backdrop" type="button" data-bearly-action-close aria-label="Close order dialog"></button>

    <section class="bearly-action-card" role="dialog" aria-modal="true" aria-labelledby="bearly-action-title">
        <header class="bearly-action-header">
            <span class="bearly-action-header-icon" data-bearly-action-icon>
                <i data-lucide="clipboard-check"></i>
            </span>

            <div class="bearly-action-heading-copy">
                <span class="section-kicker">Order workflow</span>
                <h3 id="bearly-action-title" data-bearly-action-title>Order Details</h3>
                <p data-bearly-action-subtitle>Review the order information.</p>
            </div>

            <span class="bearly-action-badge" data-bearly-action-badge>Placed</span>

            <button class="bearly-action-x" type="button" data-bearly-action-close aria-label="Close">
                <i data-lucide="x"></i>
            </button>
        </header>

        <section class="bearly-action-summary">
            <div><span>Order</span><strong data-bearly-order-id>—</strong></div>
            <div><span>Customer</span><strong data-bearly-order-customer>—</strong></div>
            <div><span data-bearly-date-label>Order Date</span><strong data-bearly-order-date>—</strong></div>
            <div>
                <span>Payment Method</span>
                <strong class="bearly-action-payment">
                    <i data-lucide="circle-check"></i>
                    <b data-bearly-order-payment>—</b>
                </strong>
            </div>
            <div class="bearly-action-address-cell" data-bearly-address-cell>
                <span data-bearly-address-label>Shipping Address</span>
                <strong data-bearly-order-address>—</strong>
            </div>
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
@endsection

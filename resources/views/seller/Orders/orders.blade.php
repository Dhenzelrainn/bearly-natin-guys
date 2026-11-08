@extends('layouts.seller')

@section('title', 'Orders')
@section('page-title', 'Orders')

@section('content')
<span data-default-order-status hidden>@json($defaultOrderStatus ?? 'all')</span>

<div class="page-heading orders-page-heading">
    <div>
        <h2>Order Management</h2>
        <p>Confirm new orders, prepare them for pickup, and keep each Store’s order status up to date.</p>
    </div>
</div>

@if (session('success'))
    <p class="orders-success-note" role="status" data-auto-dismiss="5000">{{ session('success') }}</p>
@endif

<p class="orders-workflow-note" role="note">After an order is ready, open <a href="{{ route('seller.fulfillment.pickups') }}">Pickup Requests</a> to schedule collection. Logistics verifies the request and assigns a Rider.</p>

<section class="order-queue" aria-label="Orders requiring action">
    @foreach ($orderQueue as $queue)
        <article class="order-queue-item queue-{{ $queue['tone'] }}" data-order-queue-card="{{ $queue['key'] }}">
            <span class="order-queue-icon"><i data-lucide="{{ $queue['icon'] }}"></i></span>
            <div>
                <span>{{ $queue['label'] }}</span>
                <strong>{{ $queue['count'] }}</strong>
                <small>{{ $queue['note'] }}</small>
            </div>
        </article>
    @endforeach
</section>

<section class="orders-workspace" data-orders-workspace data-order-statuses-url="{{ route('seller.orders.status-updates') }}">
    <div class="order-tabs" role="tablist" aria-label="Filter orders by seller-facing status">
        @foreach ($orderTabs as $tab)
            <button
                class="order-tab {{ $tab['key'] === ($defaultOrderStatus ?? 'all') ? 'is-active' : '' }}"
                type="button"
                role="tab"
                aria-selected="{{ $tab['key'] === ($defaultOrderStatus ?? 'all') ? 'true' : 'false' }}"
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

    <div class="orders-table-wrap">
        <table class="orders-table orders-table-polished">
            <thead>
                <tr>
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
            @foreach ($orderDisplayGroups as $displayGroup)
                <tbody @if ($displayGroup['is_shipment_group']) id="{{ $displayGroup['dom_id'] }}" @endif class="{{ $displayGroup['is_shipment_group'] ? 'orders-table-group' : 'orders-table-single' }}">
                    @if ($displayGroup['is_shipment_group'])
                        <tr class="shipment-group-row" data-order-shipment-group data-expanded="false" data-shipment-group-id="{{ $displayGroup['shipment_group_id'] }}" data-shipment-group-count="{{ $displayGroup['orders']->count() }}">
                            <td colspan="8" class="shipment-group-cell">
                                <div class="shipment-group-summary {{ $displayGroup['has_side_content'] ? 'has-side-content' : '' }}">
                                    <div class="shipment-group-identity">
                                        <button
                                            class="shipment-group-toggle"
                                            type="button"
                                            data-shipment-group-toggle
                                            aria-expanded="false"
                                            aria-controls="{{ $displayGroup['dom_id'] }}"
                                            aria-label="Show {{ $displayGroup['orders']->count() }} Seller Orders in this combined shipment"
                                        >
                                            <i data-lucide="chevron-right" aria-hidden="true"></i>
                                            <span class="shipment-group-copy">
                                                <span class="shipment-group-kicker">Combined shipment · {{ $displayGroup['orders']->first()['shipment_group_label'] }}</span>
                                                <strong>{{ $displayGroup['orders']->count() }} Seller Orders</strong>
                                                <small>One shipment action. Order details and payments remain separate.</small>
                                                <span class="shipment-group-toggle-label" data-shipment-group-toggle-label>View {{ $displayGroup['orders']->count() }} Seller Orders</span>
                                            </span>
                                        </button>
                                        <span class="shipment-group-status" data-shipment-group-status role="status">{{ $displayGroup['status_summary'] }}</span>
                                    </div>
                                    @if ($displayGroup['has_side_content'])
                                        <div class="shipment-group-actions">
                                            @if ($displayGroup['can_mark_ready'])
                                                <button
                                                    class="order-row-action shipment-group-ready-action"
                                                    type="button"
                                                    data-shipment-group-ready
                                                    data-shipment-group-id="{{ $displayGroup['shipment_group_id'] }}"
                                                    aria-label="Mark all {{ $displayGroup['orders']->count() }} Seller Orders in shipment {{ $displayGroup['orders']->first()['shipment_group_label'] }} ready for pickup"
                                                >
                                                    <i data-lucide="package-check" aria-hidden="true"></i>
                                                    Mark all {{ $displayGroup['orders']->count() }} Seller Orders ready for pickup
                                                </button>
                                            @else
                                                <small data-shipment-group-unavailable>{{ $displayGroup['action_note'] }}</small>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endif

                    @foreach ($displayGroup['orders'] as $order)
                    <tr
                        data-order-row
                        data-order-tracking-url="{{ $order['tracking_url'] }}"
                        data-seller-order-id="{{ $order['seller_order_id'] }}"
                        data-order-seller-status="{{ $order['seller_status'] }}"
                        data-status="{{ $order['status_key'] }}"
                        data-date="{{ $order['date_key'] }}"
                        data-payment="{{ $order['payment_key'] }}"
                        data-search="{{ strtolower($order['id'].' '.$order['customer'].' '.$order['canonical_status']) }}"
                        @if ($displayGroup['is_shipment_group']) data-order-shipment-group-member="{{ $displayGroup['shipment_group_id'] }}" data-order-group-member-id="{{ $order['seller_order_id'] }}" hidden @endif
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
                        data-order-transition-action="{{ $order['transition_action'] ?? '' }}"
                        data-order-transition-url="{{ $order['transition_url'] }}"
                        data-order-date-copy="{{ $order['order_date'] ?? $order['date'] ?? $order['date_key'] ?? '' }}"
                        data-order-address="{{ $order['address'] ?? $order['shipping_address'] ?? '' }}"
                        data-order-sku="{{ $order['sku'] ?? '' }}"
                        data-order-product-image="{{ $order['product_image'] ?? $order['image'] ?? '' }}"
                        data-order-items-json="{{ json_encode($order['item_rows'] ?? $order['order_items'] ?? []) }}"
                        data-order-status-history="{{ json_encode($order['status_history'] ?? []) }}"
                    >
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
                            <div class="order-product-cell">
                                <span class="order-product-thumb" aria-hidden="true">
                                    @if (!empty($order['product_image']))
                                        <img src="{{ $order['product_image'] }}" alt="" loading="lazy">
                                    @else
                                        <i data-lucide="package"></i>
                                    @endif
                                </span>
                                <span class="order-product-copy">
                                    <span>{{ $order['items'] }}</span>
                                    <small class="order-item-mini">{{ $order['item_detail'] }}</small>
                                </span>
                            </div>
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
                            <span
                                class="order-status-badge order-status-{{ $order['tone'] }}"
                                data-order-status-badge
                            >
                                <span data-order-status-label>{{ $order['status'] }}</span>
                                @if ($order['status_detail'])
                                    <small data-order-status-detail>{{ $order['status_detail'] }}</small>
                                @endif
                            </span>
                        </td>
                        <td>
                            @if ($displayGroup['is_shipment_group'] && ($order['transition_action'] ?? null) === 'mark_ready')
                                <span class="shipment-member-action-note">Ready status is managed for the shared shipment above.</span>
                            @elseif ($order['action_url'])
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
            @endforeach
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
            of {{ count($orders) }} orders
        </span>
    </footer>
</section>

<div class="seller-modal bearly-action-modal" data-bearly-action-modal hidden>
    <button class="modal-backdrop" type="button" data-bearly-action-close aria-label="Close order dialog"></button>

    <form data-bearly-transition-form method="post" action="">
        @csrf
        @method('PATCH')
        <input type="hidden" name="action" value="" data-bearly-action-field>

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
                    <h4><span data-bearly-items-title>Items</span> <span data-bearly-item-count></span></h4>
                </div>
                <div class="bearly-action-items" data-bearly-items></div>
            </section>

            <div data-bearly-action-context></div>
            <p class="bearly-action-error" data-bearly-action-error role="alert" hidden></p>

            <section class="bearly-action-cancel-fields" data-bearly-cancel-controls hidden>
                <label for="bearly-cancel-reason">Why are you cancelling this Seller Order?</label>
                <textarea id="bearly-cancel-reason" name="reason" rows="3" minlength="5" maxlength="1000" data-bearly-cancel-reason disabled></textarea>
                <small>Cancellation is only available before you confirm the order. Reserved stock for these items will be released.</small>
                <button class="bearly-action-cancel-back" type="button" data-bearly-cancel-back hidden>Keep this order</button>
            </section>

            <footer class="bearly-action-footer" data-bearly-action-footer>
                <button class="bearly-action-secondary" type="button" data-bearly-action-close>Close</button>
                <button class="bearly-action-primary" type="submit" data-bearly-action-primary>Continue</button>
            </footer>
        </section>
    </form>
</div>
@endsection

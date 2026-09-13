@extends('layouts.seller')

@section('title', 'Orders')
@section('page-title', 'Orders')

@section('content')

@php($activeOrderStatus = $defaultOrderStatus ?? 'all')

<div class="page-heading orders-page-heading">
    <div>
        <span class="section-kicker">Seller order workflow</span>

        <h2>Order Management</h2>

        <p>
            Confirm orders, prepare parcels, hand them to logistics,
            then monitor the delivery.
        </p>
    </div>

    <button
        class="orders-export-button"
        type="button"
        data-order-demo="Order list exported for preview."
    >
        <i data-lucide="download"></i>
        Export orders
    </button>
</div>


<section
    class="order-queue"
    aria-label="Orders requiring action"
>
    @foreach ($orderQueue as $queue)

        <article class="order-queue-item queue-{{ $queue['tone'] }}">

            <span class="order-queue-icon">
                <i data-lucide="{{ $queue['icon'] }}"></i>
            </span>

            <div>
                <span>{{ $queue['label'] }}</span>
                <strong>{{ $queue['count'] }}</strong>
                <small>{{ $queue['note'] }}</small>
            </div>

        </article>

    @endforeach
</section>


<section
    class="orders-workspace"
    data-orders-workspace
>

    {{-- STATUS TABS --}}
    <div
        class="order-tabs"
        role="tablist"
        aria-label="Filter orders by seller-facing status"
    >

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


    {{-- FILTERS --}}
    <div class="order-toolbar">

        <label class="order-search">

            <i data-lucide="search"></i>

            <span class="sr-only">
                Search orders
            </span>

            <input
                type="search"
                placeholder="Search order ID or customer"
                data-order-search
            >

        </label>


        <label class="order-select">

            <i data-lucide="calendar-days"></i>

            <span class="sr-only">
                Filter by date
            </span>

            <select data-order-date>
                <option value="">All dates</option>
                <option value="today">Today</option>
                <option value="upcoming">
                    Upcoming / previous
                </option>
            </select>

        </label>


        <label class="order-select">

            <i data-lucide="credit-card"></i>

            <span class="sr-only">
                Filter by payment
            </span>

            <select data-order-payment>
                <option value="">All payments</option>
                <option value="paid">
                    Paid / Refunded
                </option>
                <option value="cod">
                    Cash on Delivery
                </option>
            </select>

        </label>


        <button
            class="order-filter-reset"
            type="button"
            data-order-reset
        >
            <i data-lucide="list-filter"></i>
            Reset
        </button>

    </div>


    {{-- BULK ACTIONS --}}
    <div
        class="order-bulk-bar"
        data-order-bulk-bar
    >

        <label class="order-check-all">

            <input
                type="checkbox"
                data-order-check-all
            >

            <span>
                <strong data-order-selected-count>
                    0
                </strong>
                selected
            </span>

        </label>


        <div class="order-bulk-actions">

            <button
                type="button"
                disabled
                data-order-bulk-action="Print waybill"
            >
                <i data-lucide="printer"></i>
                Print waybill
            </button>

            <button
                type="button"
                disabled
                data-order-bulk-action="Arrange pickup"
            >
                <i data-lucide="truck"></i>
                Arrange pickup
            </button>

        </div>

    </div>


    {{-- ORDERS TABLE --}}
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

                        data-order-id="{{ $order['id'] }}"
                        data-status="{{ $order['status_key'] }}"
                        data-date="{{ $order['date_key'] }}"
                        data-payment="{{ $order['payment_key'] }}"

                        data-search="{{ strtolower(
                            $order['id'].' '.
                            $order['customer'].' '.
                            $order['canonical_status']
                        ) }}"

                        data-order-customer="{{ $order['customer'] }}"
                        data-order-items="{{ $order['item_detail'] }}"
                        data-order-item-count="{{ $order['items'] }}"
                        data-order-payment-copy="{{ $order['payment'] }}"
                        data-order-total="{{ $order['total'] }}"
                        data-order-status-copy="{{ $order['status'] }}"
                        data-order-canonical="{{ $order['canonical_status'] }}"
                        data-order-owner="{{ $order['responsibility'] }}"
                        data-order-next="{{ $order['next_step'] }}"
                        data-order-deadline="{{ $order['deadline'] }}"
                        data-order-action="{{ $order['action'] }}"
                        data-order-urgent="{{ $order['urgent'] ? '1' : '0' }}"
                    >

                        {{-- CHECKBOX --}}
                        <td>
                            <input
                                type="checkbox"
                                aria-label="Select {{ $order['id'] }}"
                                data-order-check
                            >
                        </td>


                        {{-- ORDER ID --}}
                        <td>

                            <button
                                class="order-id-button"
                                type="button"
                                data-order-workflow-open
                                data-workflow-mode="details"
                            >
                                {{ $order['id'] }}
                            </button>

                        </td>


                        {{-- CUSTOMER --}}
                        <td>

                            <strong class="order-customer">
                                {{ $order['customer'] }}
                            </strong>

                        </td>


                        {{-- ITEMS --}}
                        <td>

                            <span>
                                {{ $order['items'] }}
                            </span>

                            <small class="order-item-mini">
                                {{ $order['item_detail'] }}
                            </small>

                        </td>


                        {{-- PAYMENT --}}
                        <td>

                            <span class="payment-label payment-{{ $order['payment_key'] }}">

                                <i
                                    data-lucide="{{ $order['payment_key'] === 'cod'
                                        ? 'banknote'
                                        : 'circle-check'
                                    }}"
                                ></i>

                                {{ $order['payment'] }}

                            </span>

                        </td>


                        {{-- TOTAL --}}
                        <td>

                            <strong>
                                {{ $order['total'] }}
                            </strong>

                        </td>


                        {{-- DEADLINE --}}
                        <td>

                            <span
                                class="deadline-label {{ $order['urgent'] ? 'is-urgent' : '' }}"
                            >

                                {{ $order['deadline'] }}

                                @if ($order['urgent'])
                                    <small>
                                        Action required
                                    </small>
                                @endif

                            </span>

                        </td>


                        {{-- STATUS --}}
                        <td>

                            <span
                                class="order-status-badge order-status-{{ $order['tone'] }}"
                            >
                                {{ $order['status'] }}
                            </span>

                        </td>


                        {{-- ACTION --}}
                        <td>

                            @if ($order['action_url'])

                                <a
                                    class="order-row-action"
                                    href="{{ $order['action_url'] }}"
                                >
                                    {{ $order['action'] }}
                                </a>

                            @else

                                <button
                                    class="order-row-action"
                                    type="button"
                                    data-order-workflow-open
                                    data-workflow-mode="action"
                                >
                                    {{ $order['action'] }}
                                </button>

                            @endif

                        </td>

                    </tr>

                @endforeach

            </tbody>

        </table>


        <div
            class="orders-no-results"
            data-orders-no-results
            hidden
        >

            <i data-lucide="search-x"></i>

            <strong>
                No matching orders
            </strong>

            <span>
                Try changing the search or filters.
            </span>

        </div>

    </div>


    <footer class="orders-table-footer">

        <span>
            Showing
            <strong data-order-visible-count>
                {{ count($orders) }}
            </strong>
            of {{ count($orders) }} preview orders
        </span>

    </footer>

</section>


{{-- EXISTING HANDOFF CARDS --}}
<section class="orders-handoff-grid">

    <article class="seller-panel handoff-card">

        <span class="handoff-icon">
            <i data-lucide="printer"></i>
        </span>

        <div>

            <span class="section-kicker">
                Step 1
            </span>

            <h3>
                Prepare the parcel & waybill
            </h3>

            <p>
                Waybill creation belongs to Fulfillment.
                Orders only send eligible parcels into that workspace.
            </p>

        </div>

        <a
            class="seller-secondary-button"
            href="{{ route('seller.fulfillment.waybills') }}"
        >
            Open Waybills
            <i data-lucide="arrow-right"></i>
        </a>

    </article>


    <article class="seller-panel handoff-card">

        <span class="handoff-icon">
            <i data-lucide="truck"></i>
        </span>

        <div>

            <span class="section-kicker">
                Step 2
            </span>

            <h3>
                Arrange logistics pickup
            </h3>

            <p>
                After a labeled parcel is ready,
                submit the pickup request.
                Logistics controls approval and rider assignment.
            </p>

        </div>

        <a
            class="seller-secondary-button"
            href="{{ route('seller.fulfillment.pickups') }}"
        >
            Open Pickup Requests
            <i data-lucide="arrow-right"></i>
        </a>

    </article>

</section>


{{-- =====================================================
     REUSABLE ORDER WORKFLOW MODAL
     ===================================================== --}}
<div
    class="seller-modal order-details-modal"
    data-order-workflow-modal
    hidden
>

    <button
        class="modal-backdrop"
        type="button"
        data-order-workflow-close
        aria-label="Close order details"
    ></button>


    <section
        class="modal-card order-workflow-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="order-workflow-title"
    >

        {{-- MODAL HEADER --}}
        <header class="order-workflow-heading">

            <span
                class="order-workflow-icon"
                data-order-workflow-icon-wrap
            >
                <i data-lucide="clipboard-check"></i>
            </span>


            <div class="order-workflow-heading-copy">

                <span class="section-kicker">
                    Order workflow
                </span>

                <h3
                    id="order-workflow-title"
                    data-order-workflow-title
                >
                    Order Details
                </h3>

                <p data-order-workflow-subtitle>
                    Review the order information.
                </p>


                <div class="order-workflow-meta">

                    <span
                        class="order-workflow-status"
                        data-order-workflow-status
                    >
                        —
                    </span>

                    <span
                        class="order-workflow-deadline"
                        data-order-workflow-deadline
                    ></span>

                </div>

            </div>


            <button
                class="order-workflow-x"
                type="button"
                data-order-workflow-close
                aria-label="Close"
            >
                <i data-lucide="x"></i>
            </button>

        </header>


        {{-- BASIC ORDER INFORMATION --}}
        <section class="order-workflow-summary">

            <article>
                <span>Order</span>

                <strong data-order-workflow-id>
                    —
                </strong>
            </article>


            <article>
                <span>Customer</span>

                <strong data-order-workflow-customer>
                    —
                </strong>
            </article>


            <article>
                <span>Payment Method</span>

                <strong data-order-workflow-payment>
                    —
                </strong>
            </article>


            <article>
                <span>Total Amount</span>

                <strong data-order-workflow-total>
                    —
                </strong>
            </article>

        </section>


        {{-- ITEMS --}}
        <section class="order-workflow-items">

            <div class="order-workflow-section-heading">

                <h4>
                    Items
                </h4>

                <span data-order-workflow-item-count>
                    —
                </span>

            </div>


            <div
                class="order-workflow-items-list"
                data-order-workflow-items-list
            ></div>

        </section>


        {{-- ACTION NOTE --}}
        <section
            class="order-workflow-guidance"
            data-order-workflow-guidance
        >

            <span
                class="order-workflow-guidance-icon"
                data-order-workflow-guidance-icon
            >
                <i data-lucide="info"></i>
            </span>

            <p data-order-workflow-guidance-text></p>

        </section>


        {{-- PACKING CHECKLIST --}}
        <ul
            class="order-workflow-checklist"
            data-order-workflow-checklist
            hidden
        ></ul>


        {{-- READ ONLY DELIVERY TIMELINE --}}
        <section
            class="order-workflow-timeline-panel"
            data-order-workflow-timeline-panel
            hidden
        >

            <div class="order-workflow-section-heading">

                <h4>
                    Order Status
                </h4>

            </div>

            <ol
                class="order-workflow-timeline"
                data-order-workflow-timeline
            ></ol>

        </section>


        {{-- ACTIONS --}}
        <footer class="order-workflow-actions">

            <button
                class="order-workflow-close-button"
                type="button"
                data-order-workflow-close
            >
                Close
            </button>


            <button
                class="order-workflow-primary-button"
                type="button"
                data-order-workflow-primary
            >
                Confirm Order
            </button>

        </footer>

    </section>

</div>


<script
    type="application/json"
    data-default-order-status
>
    @json($activeOrderStatus)
</script>

@endsection
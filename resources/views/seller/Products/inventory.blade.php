@extends('layouts.seller')

@section('title', 'Inventory')
@section('page-title', 'Inventory')
@section('topbar-subtitle', 'Monitor stock levels and product variations.')

@section('content')
@php
    $inventoryImages = [
        'Classic Linen Shirt' => asset('images/landing-page/products/cotton-tshirt.jpg'),
        'Canvas Tote Bag' => asset('images/landing-page/products/canvas-tote.jpg'),
        'Everyday Sneakers' => asset('images/landing-page/products/everyday-sneakers.jpg'),
    ];
@endphp

<div class="inventory-topline">
    <div class="inventory-quick-metrics" aria-label="Inventory summary">
        <div class="inventory-quick-metric">
            <strong>{{ $inventoryTotal }}</strong>
            <span>Total SKUs</span>
        </div>

        <span class="inventory-metric-divider" aria-hidden="true"></span>

        <div class="inventory-quick-metric">
            <strong>{{ $inventorySummary[1]['value'] }}</strong>
            <span>Available units</span>
        </div>
    </div>

    <div class="inventory-heading-actions">
        <a class="inventory-secondary-button" href="#stock-history">
            <i data-lucide="history"></i>
            Stock History
        </a>

        <a class="inventory-add-button" href="{{ route('seller.products.create') }}">
            <i data-lucide="plus"></i>
            Add Product
        </a>
    </div>
</div>

<div class="inventory-restock-row" aria-label="Inventory alerts">
    <div class="inventory-restock-copy">
        <span class="inventory-restock-icon" aria-hidden="true">
            <i data-lucide="triangle-alert"></i>
        </span>

        <p>
            <strong>{{ $inventoryAttention['total'] }} items need restocking</strong>
            <span>· {{ $inventoryAttention['low'] }} low stock, {{ $inventoryAttention['out'] }} out of stock</span>
        </p>
    </div>

    <button type="button" data-inventory-alerts>
        Review items
        <i data-lucide="arrow-right"></i>
    </button>
</div>

<section class="inventory-workspace" data-inventory-workspace>
    <div class="inventory-tabs" role="tablist" aria-label="Filter inventory by stock status">
        @foreach ($inventoryTabs as $tab)
            <button
                class="inventory-tab {{ $tab['key'] === 'all' ? 'is-active' : '' }}"
                type="button"
                role="tab"
                aria-selected="{{ $tab['key'] === 'all' ? 'true' : 'false' }}"
                data-inventory-tab="{{ $tab['key'] }}"
            >
                {{ $tab['label'] }}
                @if ($tab['count'] !== null)
                    <span>{{ $tab['count'] }}</span>
                @endif
            </button>
        @endforeach
    </div>

    <div class="inventory-table-panel">
        <div class="inventory-toolbar">
            <label class="inventory-search">
                <i data-lucide="search"></i>
                <span class="sr-only">Search inventory</span>
                <input
                    type="search"
                    placeholder="Search product, SKU, or variation"
                    data-inventory-search
                >
            </label>

            <label class="inventory-select">
                <span class="sr-only">Filter by category</span>
                <select data-inventory-category>
                    <option value="">All Categories</option>
                    @foreach ($inventoryCategories as $category)
                        <option value="{{ strtolower($category) }}">{{ $category }}</option>
                    @endforeach
                </select>
            </label>

            <label class="inventory-variation-toggle">
                <input type="checkbox" checked data-inventory-variations>
                <span>Show variations</span>
            </label>

            <button class="inventory-reset-button" type="button" data-inventory-reset>
                Reset
            </button>
        </div>

        <div class="inventory-bulk-bar">
            <label>
                <input type="checkbox" data-inventory-check-all>
                <span><strong data-inventory-selected-count>0</strong> selected</span>
            </label>

            <div>
                <button type="button" disabled data-inventory-bulk="Adjust stock">
                    <i data-lucide="package-plus"></i>
                    Adjust stock
                </button>

                <button type="button" disabled data-inventory-bulk="Set threshold">
                    <i data-lucide="sliders-horizontal"></i>
                    Set threshold
                </button>
            </div>
        </div>

        <div class="inventory-table-wrap">
            <table class="inventory-management-table">
                <thead>
                    <tr>
                        <th aria-label="Select inventory item"></th>
                        <th>Product / SKU</th>
                        <th>Variation</th>
                        <th>On hand</th>
                        <th>
                            Reserved
                            <span class="inventory-info" title="Units reserved by active orders">
                                <i data-lucide="info"></i>
                            </span>
                        </th>
                        <th>Available</th>
                        <th>Low stock at</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($inventoryItems as $item)
                        @php($productImage = $inventoryImages[$item['product']] ?? null)

                        <tr
                            data-inventory-row
                            data-id="{{ $item['id'] }}"
                            data-product="{{ strtolower($item['product']) }}"
                            data-search="{{ strtolower($item['product'].' '.$item['sku'].' '.$item['variation']) }}"
                            data-category="{{ strtolower($item['category']) }}"
                            data-status="{{ $item['status_key'] }}"
                            data-parent="{{ $item['parent'] }}"
                            data-variation-row="{{ $item['variation_row'] ? 'true' : 'false' }}"
                            data-on-hand="{{ $item['on_hand'] }}"
                            data-reserved="{{ $item['reserved'] }}"
                            data-threshold="{{ $item['threshold'] }}"
                        >
                            <td>
                                <input
                                    type="checkbox"
                                    aria-label="Select {{ $item['sku'] }}"
                                    data-inventory-check
                                >
                            </td>

                            <td>
                                <div class="inventory-product-cell">
                                    <span class="inventory-product-thumb">
                                        @if ($productImage)
                                            <img src="{{ $productImage }}" alt="" aria-hidden="true">
                                        @else
                                            <i data-lucide="{{ $item['icon'] }}"></i>
                                        @endif
                                    </span>

                                    <span class="inventory-product-copy">
                                        <strong>{{ $item['product'] }}</strong>
                                        <small>{{ $item['sku'] }}</small>
                                    </span>
                                </div>
                            </td>

                            <td>{{ $item['variation'] }}</td>
                            <td><strong data-stock-on-hand>{{ $item['on_hand'] }}</strong></td>
                            <td><span class="reserved-stock" data-stock-reserved>{{ $item['reserved'] }}</span></td>
                            <td>
                                <strong
                                    class="available-stock {{ $item['available'] === 0 ? 'is-empty' : '' }}"
                                    data-stock-available
                                >
                                    {{ $item['available'] }}
                                </strong>
                            </td>
                            <td><span data-stock-threshold>{{ $item['threshold'] }}</span></td>
                            <td>
                                <span
                                    class="inventory-stock-badge stock-{{ $item['status_key'] }}"
                                    data-stock-status
                                >
                                    {{ $item['status'] }}
                                </span>
                            </td>
                            <td>
                                <button
                                    class="inventory-row-action"
                                    type="button"
                                    data-adjust-stock
                                    data-item-id="{{ $item['id'] }}"
                                    data-item-label="{{ $item['product'].' · '.$item['variation'] }}"
                                >
                                    {{ $item['available'] === 0 ? 'Restock' : 'Adjust stock' }}
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="inventory-no-results" data-inventory-no-results hidden>
                <i data-lucide="package-search"></i>
                <strong>No matching inventory</strong>
                <span>Try changing the search or stock filters.</span>
            </div>
        </div>

        <footer class="inventory-table-footer">
            <span>
                <strong data-inventory-visible-count>{{ count($inventoryItems) }}</strong>
                sample SKUs shown
            </span>
        </footer>
    </div>
</section>

<section class="stock-history-panel" id="stock-history">
    <div class="stock-history-heading">
        <h3>Recent stock movements</h3>
    </div>

    <div class="stock-history-table-wrap">
        <table class="stock-history-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Product / SKU</th>
                    <th>Type</th>
                    <th>Quantity</th>
                    <th>Balance</th>
                    <th>Actioned by</th>
                </tr>
            </thead>

            <tbody>
                @foreach ($stockMovements as $movement)
                    <tr>
                        <td>{{ $movement['date'] }}</td>
                        <td>
                            <strong>{{ $movement['product'] }}</strong>
                            <small>{{ $movement['sku'] }}</small>
                        </td>
                        <td>{{ $movement['type'] }}</td>
                        <td>
                            <strong class="movement-quantity quantity-{{ $movement['direction'] }}">
                                {{ $movement['quantity'] }}
                            </strong>
                        </td>
                        <td>{{ $movement['balance'] }}</td>
                        <td>{{ $movement['actor'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>

<div class="seller-modal inventory-adjust-modal" data-modal="inventory-adjust" hidden>
    <button
        class="modal-backdrop"
        type="button"
        data-modal-close
        aria-label="Close stock adjustment"
    ></button>

    <section
        class="modal-card"
        role="dialog"
        aria-modal="true"
        aria-labelledby="inventory-adjust-title"
    >
        <div class="modal-heading">
            <div>
                <span class="section-kicker">Inventory adjustment</span>
                <h3 id="inventory-adjust-title">Adjust Stock</h3>
            </div>

            <button type="button" data-modal-close aria-label="Close">
                <i data-lucide="x"></i>
            </button>
        </div>

        <p class="inventory-adjust-product" data-adjust-item-label>
            Selected inventory item
        </p>

        <input type="hidden" data-adjust-item-id>

        <div class="inventory-current-stock">
            <span>Current on-hand quantity</span>
            <strong data-adjust-current>0</strong>
        </div>

        <div class="inventory-adjust-grid">
            <label class="seller-field">
                <span>Adjustment</span>
                <select data-adjust-mode>
                    <option value="add">Add stock</option>
                    <option value="remove">Remove stock</option>
                    <option value="set">Set exact quantity</option>
                </select>
            </label>

            <label class="seller-field">
                <span>Quantity</span>
                <input
                    type="number"
                    min="0"
                    step="1"
                    value="1"
                    data-adjust-quantity
                >
            </label>
        </div>

        <label class="seller-field">
            <span>Reason</span>
            <select data-adjust-reason>
                <option>New stock received</option>
                <option>Manual stock count</option>
                <option>Damaged item</option>
                <option>Returned order</option>
                <option>Other adjustment</option>
            </select>
        </label>

        <p class="inventory-preview-note">
            <i data-lucide="info"></i>
            This updates the current browser preview only. It will reset after refreshing the page.
        </p>

        <div class="modal-actions">
            <button class="draft-button" type="button" data-modal-close>
                Cancel
            </button>

            <button class="seller-primary-button" type="button" data-apply-stock-adjustment>
                Apply Adjustment
            </button>
        </div>
    </section>
</div>
@endsection

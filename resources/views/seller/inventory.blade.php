@extends('layouts.seller')

@section('title', 'Inventory')
@section('page-title', 'Inventory')

@section('content')
<div class="page-heading inventory-page-heading">
    <div>
        <h2>Inventory Management</h2>
        <p>Monitor stock levels, product variations, and quantity changes.</p>
    </div>
    <div class="inventory-heading-actions">
        <a class="inventory-secondary-button" href="#stock-history">
            <i data-lucide="history"></i>
            Stock History
        </a>
        <a class="seller-primary-button inventory-add-button" href="{{ route('seller.products.create') }}">
            <i data-lucide="plus"></i>
            Add Product
        </a>
    </div>
</div>

<section class="inventory-summary-strip" aria-label="Inventory summary">
    @foreach ($inventorySummary as $summary)
        <article class="inventory-summary-item summary-{{ $summary['tone'] }}">
            <span class="inventory-summary-icon"><i data-lucide="{{ $summary['icon'] }}"></i></span>
            <div>
                <span>{{ $summary['label'] }}</span>
                <strong>{{ $summary['value'] }}</strong>
                <small>{{ $summary['note'] }}</small>
            </div>
        </article>
    @endforeach
</section>

<section class="inventory-attention-bar" aria-label="Inventory alerts">
    <span class="inventory-attention-icon"><i data-lucide="triangle-alert"></i></span>
    <div>
        <strong>{{ $inventoryAttention['total'] }} inventory items need attention</strong>
        <span>{{ $inventoryAttention['low'] }} low stock · {{ $inventoryAttention['out'] }} out of stock</span>
    </div>
    <button type="button" data-inventory-alerts>
        Review alerts
        <i data-lucide="arrow-right"></i>
    </button>
</section>

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
                @if ($tab['count'] !== null)<span>{{ $tab['count'] }}</span>@endif
            </button>
        @endforeach
    </div>

    <div class="inventory-toolbar">
        <label class="inventory-search">
            <i data-lucide="search"></i>
            <span class="sr-only">Search inventory</span>
            <input type="search" placeholder="Search product, SKU, or variation" data-inventory-search>
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

        <label class="inventory-select">
            <span class="sr-only">Filter by stock status</span>
            <select data-inventory-status>
                <option value="">All Stock Status</option>
                <option value="in-stock">In Stock</option>
                <option value="low-stock">Low Stock</option>
                <option value="out-of-stock">Out of Stock</option>
                <option value="archived">Archived</option>
            </select>
        </label>

        <label class="inventory-variation-toggle">
            <input type="checkbox" checked data-inventory-variations>
            <span>Show variations</span>
        </label>

        <button class="inventory-reset-button" type="button" data-inventory-reset>Reset</button>
    </div>

    <div class="inventory-bulk-bar">
        <label>
            <input type="checkbox" data-inventory-check-all>
            <span><strong data-inventory-selected-count>0</strong> selected</span>
        </label>
        <div>
            <button type="button" disabled data-inventory-bulk="Adjust stock"><i data-lucide="package-plus"></i>Adjust stock</button>
            <button type="button" disabled data-inventory-bulk="Set threshold"><i data-lucide="sliders-horizontal"></i>Set threshold</button>
        </div>
    </div>

    <div class="inventory-table-wrap">
        <table class="inventory-management-table">
            <thead>
                <tr>
                    <th aria-label="Select inventory item"></th>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Variation</th>
                    <th>On Hand</th>
                    <th>Reserved <span class="inventory-info" title="Units reserved by active orders"><i data-lucide="info"></i></span></th>
                    <th>Available</th>
                    <th>Low Stock At</th>
                    <th>Stock Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($inventoryItems as $item)
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
                        <td><input type="checkbox" aria-label="Select {{ $item['sku'] }}" data-inventory-check></td>
                        <td>
                            <div class="inventory-product-cell">
                                <span><i data-lucide="{{ $item['icon'] }}"></i></span>
                                <strong>{{ $item['product'] }}</strong>
                            </div>
                        </td>
                        <td><code>{{ $item['sku'] }}</code></td>
                        <td>{{ $item['variation'] }}</td>
                        <td><strong data-stock-on-hand>{{ $item['on_hand'] }}</strong></td>
                        <td><span class="reserved-stock" data-stock-reserved>{{ $item['reserved'] }}</span></td>
                        <td><strong class="available-stock {{ $item['available'] === 0 ? 'is-empty' : '' }}" data-stock-available>{{ $item['available'] }}</strong></td>
                        <td><span data-stock-threshold>{{ $item['threshold'] }}</span></td>
                        <td><span class="inventory-stock-badge stock-{{ $item['status_key'] }}" data-stock-status>{{ $item['status'] }}</span></td>
                        <td>
                            <button
                                class="inventory-row-action"
                                type="button"
                                data-adjust-stock
                                data-item-id="{{ $item['id'] }}"
                                data-item-label="{{ $item['product'].' · '.$item['variation'] }}"
                            >
                                {{ $item['available'] === 0 ? 'Restock' : 'Adjust Stock' }}
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
        <span>Showing <strong data-inventory-visible-count>{{ count($inventoryItems) }}</strong> of {{ $inventoryTotal }} SKUs</span>
        <nav class="inventory-pagination" aria-label="Inventory pages">
            <button type="button" disabled aria-label="Previous page"><i data-lucide="chevron-left"></i></button>
            <button class="is-current" type="button">1</button>
            <button type="button">2</button>
            <button type="button">3</button>
            <span>…</span>
            <button type="button">10</button>
            <button type="button" aria-label="Next page"><i data-lucide="chevron-right"></i></button>
        </nav>
    </footer>
</section>

<section class="stock-history-panel" id="stock-history">
    <div class="stock-history-heading">
        <div>
            <span class="section-kicker">Inventory audit trail</span>
            <h3>Recent Stock Movements</h3>
            <p>Latest manual adjustments, reservations, and order deductions.</p>
        </div>
        <button type="button" data-inventory-demo="Full stock history opened.">View full history</button>
    </div>

    <div class="stock-history-table-wrap">
        <table class="stock-history-table">
            <thead><tr><th>Date</th><th>Type</th><th>Product / SKU</th><th>Variation</th><th>Reference</th><th>Quantity</th><th>Balance</th><th>Actioned By</th></tr></thead>
            <tbody>
                @foreach ($stockMovements as $movement)
                    <tr>
                        <td>{{ $movement['date'] }}</td>
                        <td><span class="movement-type movement-{{ $movement['tone'] }}">{{ $movement['type'] }}</span></td>
                        <td><strong>{{ $movement['product'] }}</strong><small>{{ $movement['sku'] }}</small></td>
                        <td>{{ $movement['variation'] }}</td>
                        <td>{{ $movement['reference'] }}</td>
                        <td><strong class="movement-quantity quantity-{{ $movement['direction'] }}">{{ $movement['quantity'] }}</strong></td>
                        <td>{{ $movement['balance'] }}</td>
                        <td>{{ $movement['actor'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>

<div class="seller-modal inventory-adjust-modal" data-modal="inventory-adjust" hidden>
    <button class="modal-backdrop" type="button" data-modal-close aria-label="Close stock adjustment"></button>
    <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="inventory-adjust-title">
        <div class="modal-heading">
            <div>
                <span class="section-kicker">Frontend preview</span>
                <h3 id="inventory-adjust-title">Adjust Stock</h3>
            </div>
            <button type="button" data-modal-close aria-label="Close"><i data-lucide="x"></i></button>
        </div>

        <p class="inventory-adjust-product" data-adjust-item-label>Selected inventory item</p>
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
                <input type="number" min="0" step="1" value="1" data-adjust-quantity>
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

        <p class="inventory-preview-note"><i data-lucide="info"></i>This updates the current browser preview only. It will reset after refreshing the page.</p>

        <div class="modal-actions">
            <button class="draft-button" type="button" data-modal-close>Cancel</button>
            <button class="seller-primary-button" type="button" data-apply-stock-adjustment>Apply Adjustment</button>
        </div>
    </section>
</div>
@endsection

@extends('layouts.seller')

@section('title', 'Inventory')
@section('page-title', 'Inventory')
@section('topbar-subtitle', 'Monitor stock levels and product variations.')

@section('content')
<div class="inventory-topline">
    <div class="inventory-quick-metrics" aria-label="Inventory summary">
        <div class="inventory-quick-metric">
            <strong>{{ number_format($inventoryTotal) }}</strong>
            <span>Total SKUs</span>
        </div>

        <span class="inventory-metric-divider" aria-hidden="true"></span>

        <div class="inventory-quick-metric">
            <strong>{{ number_format($inventorySummary[1]['value']) }}</strong>
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
            <strong>{{ number_format($inventoryAttention['total']) }} SKU(s) need restocking</strong>
            <span>· {{ number_format($inventoryAttention['low']) }} low stock, {{ number_format($inventoryAttention['out']) }} out of stock</span>
        </p>
    </div>

    <button type="button" data-inventory-alerts>
        Review items
        <i data-lucide="arrow-right"></i>
    </button>
</div>

<section
    class="inventory-workspace"
    data-inventory-workspace
    data-inventory-initial-status="{{ $inventorySelectedStatus ?? 'all' }}"
>
    <div class="inventory-tabs" role="tablist" aria-label="Filter inventory by stock status">
        @foreach ($inventoryTabs as $tab)
            <button
                class="inventory-tab {{ $tab['key'] === ($inventorySelectedStatus ?? 'all') ? 'is-active' : '' }}"
                type="button"
                role="tab"
                aria-selected="{{ $tab['key'] === ($inventorySelectedStatus ?? 'all') ? 'true' : 'false' }}"
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
                    value="{{ $inventorySearch ?? '' }}"
                    data-inventory-search
                >
            </label>

            <form
                class="inventory-filter-form"
                method="GET"
                action="{{ route('seller.inventory') }}"
                data-inventory-filter-form
            >
                <input type="hidden" name="inventory_search" value="{{ $inventorySearch ?? '' }}" data-inventory-search-state>
                <input type="hidden" name="inventory_status" value="{{ $inventorySelectedStatus ?? 'all' }}" data-inventory-status-state>
                <input type="hidden" name="inventory_variations" value="{{ ($inventoryShowVariations ?? true) ? '1' : '0' }}" data-inventory-variations-state>

                <label class="inventory-select">
                    <span class="sr-only">Filter by Store section</span>
                    <select name="store_section" data-inventory-store-section>
                        <option value="">All Store Sections</option>
                        @foreach ($inventorySubcategories as $subcategory)
                            <option
                                value="{{ $subcategory['id'] }}"
                                @selected(($inventorySelectedStoreSection ?? '') === (string) $subcategory['id'])
                            >{{ $subcategory['name'] }}</option>
                        @endforeach
                        @if ($inventoryHasUncategorized)
                            <option value="uncategorized" @selected(($inventorySelectedStoreSection ?? '') === 'uncategorized')>
                                No Store Section
                            </option>
                        @endif
                    </select>
                </label>

                <label class="inventory-select">
                    <span class="sr-only">Filter by Marketplace subcategory</span>
                    <select name="marketplace_subcategory" data-inventory-marketplace-subcategory>
                        <option value="">All Marketplace Subcategories</option>
                        @foreach (collect($inventoryMarketplaceSubcategories)->groupBy('marketplace_category') as $marketplaceCategory => $subcategories)
                            <optgroup label="{{ $marketplaceCategory }}">
                                @foreach ($subcategories as $subcategory)
                                    <option
                                        value="{{ $subcategory['id'] }}"
                                        @selected(($inventorySelectedMarketplaceSubcategory ?? '') === (string) $subcategory['id'])
                                    >{{ $subcategory['name'] }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                        @if ($inventoryHasUnclassifiedMarketplaceItems)
                            <option value="unclassified" @selected(($inventorySelectedMarketplaceSubcategory ?? '') === 'unclassified')>
                                No Marketplace Subcategory
                            </option>
                        @endif
                    </select>
                </label>

                <noscript><button type="submit" class="inventory-filter-submit">Apply filters</button></noscript>
            </form>

            <label class="inventory-variation-toggle">
                <input type="checkbox" @checked($inventoryShowVariations ?? true) data-inventory-variations>
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
                <button type="button" disabled data-inventory-bulk="adjust-stock">
                    <i data-lucide="package-plus"></i>
                    Adjust stock
                </button>

                <button
                    type="button"
                    @disabled($inventoryTotal === 0)
                    data-inventory-bulk="set-threshold"
                >
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
                        <tr
                            data-inventory-row
                            data-id="{{ $item['id'] }}"
                            data-product="{{ strtolower($item['product']) }}"
                            data-search="{{ strtolower($item['product'].' '.$item['sku'].' '.$item['variation']) }}"
                            data-store-section="{{ $item['subcategory_id'] ?? 'uncategorized' }}"
                            data-marketplace-subcategory="{{ $item['marketplace_subcategory_id'] ?? 'unclassified' }}"
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
                                    @disabled($item['is_compliance_held'])
                                >
                            </td>

                            <td>
                                <div class="inventory-product-cell">
                                    <span class="inventory-product-thumb">
                                        @if ($item['image'])
                                            <img src="{{ $item['image'] }}" alt="" aria-hidden="true" loading="lazy">
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
                                    @if ($item['is_compliance_held']) title="Unavailable for sale while compliance status is {{ $item['status'] }}" @endif
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
                                @if ($item['is_compliance_held'])
                                    <button class="inventory-row-action" type="button" disabled title="Resolve the compliance issue before adjusting sellable stock.">
                                        Compliance hold
                                    </button>
                                @else
                                    <button
                                        class="inventory-row-action"
                                        type="button"
                                        data-adjust-stock
                                        data-item-id="{{ $item['id'] }}"
                                        data-item-label="{{ $item['product'].' · '.$item['variation'] }}"
                                    >
                                        {{ $item['available'] === 0 ? 'Restock' : 'Adjust stock' }}
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="inventory-no-results" data-inventory-no-results @if ($inventoryItems->isNotEmpty()) hidden @endif>
                <i data-lucide="package-search"></i>
                <strong>No matching inventory</strong>
                <span>Try changing the search or stock filters, or add a product with a variation.</span>
            </div>
        </div>

        <footer class="inventory-table-footer">
            <span>
                <strong data-inventory-visible-count>{{ $inventoryItems->count() }}</strong>
                SKUs shown
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
                    <th>Reason</th>
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
                        <td>{{ $movement['reason'] }}</td>
                        <td>
                            <strong class="movement-quantity quantity-{{ $movement['direction'] }}">
                                {{ $movement['quantity'] }}
                            </strong>
                        </td>
                        <td>{{ $movement['balance'] }}</td>
                        <td>{{ $movement['actor'] }}</td>
                    </tr>
                @endforeach
                @if ($stockMovements->isEmpty())
                    <tr><td colspan="7">No stock movements recorded yet.</td></tr>
                @endif
            </tbody>
        </table>
    </div>
</section>

<div class="seller-modal inventory-adjust-modal" data-modal="inventory-adjust" @if (old('inventory_action') !== 'adjust') hidden @endif>
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

        <form method="POST" action="{{ route('seller.inventory.adjustments') }}" data-inventory-adjust-form>
            @csrf
            <input type="hidden" name="inventory_action" value="adjust">
            <div data-adjust-variant-ids>
                @foreach (old('variant_ids', []) as $variantId)
                    <input type="hidden" name="variant_ids[]" value="{{ $variantId }}">
                @endforeach
            </div>
            @if (old('inventory_action') === 'adjust' && $errors->any())
                <div class="form-alert products-alert" role="alert">
                    <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif
            <p class="inventory-adjust-product" data-adjust-item-label>
                {{ count(old('variant_ids', [])) }} selected SKU(s)
            </p>

            <div class="inventory-current-stock">
                <span>Current on-hand quantity</span>
                <strong data-adjust-current>—</strong>
            </div>

            <div class="inventory-adjust-grid">
                <label class="seller-field">
                    <span>Adjustment</span>
                    <select name="mode" data-adjust-mode required>
                        <option value="add" @selected(old('mode', 'add') === 'add')>Add stock</option>
                        <option value="remove" @selected(old('mode') === 'remove')>Remove stock</option>
                        <option value="set" @selected(old('mode') === 'set')>Set exact quantity</option>
                    </select>
                </label>

                <label class="seller-field">
                    <span>Quantity</span>
                    <input
                        type="number"
                        name="quantity"
                        min="{{ old('mode', 'add') === 'set' ? 0 : 1 }}"
                        max="{{ old('mode', 'add') === 'set' ? 4294967295 : 2147483647 }}"
                        step="1"
                        value="{{ old('quantity', 1) }}"
                        data-adjust-quantity
                        required
                    >
                </label>
            </div>

            <label class="seller-field">
                <span>Reason</span>
                <select name="reason" data-adjust-reason required>
                    @foreach ($adjustmentReasons as $value => $label)
                        <option value="{{ $value }}" @selected(old('reason', 'new_stock_received') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="seller-field">
                <span>Notes <small>(optional)</small></span>
                <input type="text" name="reason_note" value="{{ old('reason_note') }}" maxlength="180" placeholder="Reference or brief details">
            </label>

            <p class="inventory-preview-note">
                <i data-lucide="info"></i>
                Adjustments are saved to stock history. Stock cannot be reduced below units reserved for orders.
            </p>

            <div class="modal-actions">
                <button class="draft-button" type="button" data-modal-close>Cancel</button>
                <button class="seller-primary-button" type="submit">Apply Adjustment</button>
            </div>
        </form>
    </section>
</div>

<div class="seller-modal inventory-adjust-modal" data-modal="inventory-threshold" @if (old('inventory_action') !== 'threshold') hidden @endif>
    <button class="modal-backdrop" type="button" data-modal-close aria-label="Close threshold update"></button>

    <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="inventory-threshold-title">
        <div class="modal-heading">
            <div>
                <span class="section-kicker">Inventory settings</span>
                <h3 id="inventory-threshold-title">Set Low-Stock Threshold</h3>
            </div>
            <button type="button" data-modal-close aria-label="Close"><i data-lucide="x"></i></button>
        </div>

        <form method="POST" action="{{ route('seller.inventory.thresholds') }}" data-inventory-threshold-form>
            @csrf
            <input type="hidden" name="inventory_action" value="threshold">
            <div data-threshold-variant-ids>
                @foreach (old('variant_ids', []) as $variantId)
                    <input type="hidden" name="variant_ids[]" value="{{ $variantId }}">
                @endforeach
            </div>
            @if (old('inventory_action') === 'threshold' && $errors->any())
                <div class="form-alert products-alert" role="alert">
                    <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif
            <p class="inventory-adjust-product" data-threshold-selected-label>{{ count(old('variant_ids', [])) }} selected SKU(s)</p>

            <label class="seller-field">
                <span>Alert when available stock reaches</span>
                <input type="number" name="threshold" min="0" max="4294967295" step="1" value="{{ old('threshold', 5) }}" required>
                <small>Applied to every selected SKU. Reserved units are not counted as available.</small>
            </label>

            <div class="modal-actions">
                <button class="draft-button" type="button" data-modal-close>Cancel</button>
                <button class="seller-primary-button" type="submit">Save Threshold</button>
            </div>
        </form>
    </section>
</div>
@endsection

@extends('rider.layouts.app')

@section('title', 'Delivery History')
@section('page-title', 'Delivery History')

@section('content')
<div class="page-header">
    <div>
        <p class="page-kicker">Completed work</p>
        <h2>Delivery and return history</h2>
        <p>Review your recorded delivery attempts, return deliveries, canceled assignments, and any earnings posted to your Rider ledger.</p>
    </div>
    <div class="page-actions">
        <a class="button" href="{{ route('rider.history.export', $filters) }}">
            <i data-lucide="download" aria-hidden="true"></i>
            Export history
        </a>
    </div>
</div>

<section class="metric-strip" aria-label="Delivery history summary">
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="package-check" aria-hidden="true"></i></span>
        <span class="metric-copy">
            <small>Completed this month</small>
            <strong>{{ number_format($metrics['completed_this_month']) }}</strong>
            <span>{{ $metrics['delivery_rate'] }} · {{ $metrics['delivery_rate_description'] }}</span>
        </span>
    </article>
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="rotate-ccw" aria-hidden="true"></i></span>
        <span class="metric-copy">
            <small>Return deliveries</small>
            <strong>{{ number_format($metrics['return_deliveries']) }}</strong>
            <span>Recorded return shipments delivered by you</span>
        </span>
    </article>
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="circle-x" aria-hidden="true"></i></span>
        <span class="metric-copy">
            <small>Canceled assignments</small>
            <strong>{{ number_format($metrics['cancelled_assignments']) }}</strong>
            <span>Parcels in dispatch batches canceled before an attempt</span>
        </span>
    </article>
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="triangle-alert" aria-hidden="true"></i></span>
        <span class="metric-copy">
            <small>Failed attempts this month</small>
            <strong>{{ number_format($metrics['failed_attempts_this_month']) }}</strong>
            <span>Recorded attempts that may need a retry</span>
        </span>
    </article>
</section>

<section class="panel" data-table-scope aria-label="Delivery history records">
    <form class="table-toolbar history-toolbar" method="GET" action="{{ route('rider.history.index') }}">
        <label class="search-field">
            <i data-lucide="search" aria-hidden="true"></i>
            <input
                type="search"
                name="search"
                value="{{ $filters['search'] }}"
                maxlength="120"
                placeholder="Search shipment, parcel, recipient, or area"
                aria-label="Search shipment, parcel, recipient, or area"
            >
        </label>

        <label>
            <select class="filter-select" name="outcome" aria-label="Filter by outcome">
                <option value="">All outcomes</option>
                <option value="delivered" @selected($filters['outcome'] === 'delivered')>Delivered</option>
                <option value="returned" @selected($filters['outcome'] === 'returned')>Returned</option>
                <option value="failed" @selected($filters['outcome'] === 'failed')>Failed attempt</option>
                <option value="cancelled" @selected($filters['outcome'] === 'cancelled')>Canceled</option>
            </select>
        </label>

        <label class="history-date-field">
            <span>Start date</span>
            <input class="filter-select" type="date" name="date_from" value="{{ $filters['date_from'] }}" aria-label="Start date" aria-invalid="{{ $errors->has('date_from') ? 'true' : 'false' }}">
            @error('date_from')<small class="delivery-field-error" role="alert">{{ $message }}</small>@enderror
        </label>

        <label class="history-date-field">
            <span>End date</span>
            <input class="filter-select" type="date" name="date_to" value="{{ $filters['date_to'] }}" aria-label="End date" aria-invalid="{{ $errors->has('date_to') ? 'true' : 'false' }}">
            @error('date_to')<small class="delivery-field-error" role="alert">{{ $message }}</small>@enderror
        </label>

        <button class="button button-small" type="submit">Apply</button>

        @if(collect($filters)->filter()->isNotEmpty())
            <a class="button button-small" href="{{ route('rider.history.index') }}">Clear</a>
        @endif
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Delivery</th>
                    <th>Customer / recipient</th>
                    <th>Service area</th>
                    <th>Outcome</th>
                    <th>Earning</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                @forelse($history as $item)
                    <tr data-row data-status="{{ $item['status'] }}">
                        <td>{{ $item['date'] }}</td>
                        <td>
                            @php($previewProduct = $item['products'][0] ?? null)
                            <div class="history-delivery-cell">
                                @if($previewProduct)
                                    <span class="history-product-thumbnail">
                                        @if(filled($previewProduct['image_url']))
                                            <img src="{{ $previewProduct['image_url'] }}" alt="" loading="lazy" decoding="async">
                                        @else
                                            <span class="history-product-thumbnail-placeholder" role="img" aria-label="No product image available">
                                                <i data-lucide="package" aria-hidden="true"></i>
                                            </span>
                                        @endif
                                    </span>
                                @endif
                                <span class="history-delivery-copy">
                                    <strong>{{ $item['id'] }}</strong>
                                    <small class="history-secondary-value">{{ $item['parcel_no'] }}</small>
                                    @if($previewProduct)
                                        <small class="history-preview-product">{{ $previewProduct['name'] }} · Qty {{ $previewProduct['quantity'] }}</small>
                                        @if(count($item['products']) > 1)
                                            <small class="history-secondary-value">+{{ count($item['products']) - 1 }} more products</small>
                                        @endif
                                    @endif
                                </span>
                            </div>
                        </td>
                        <td>{{ $item['customer'] }}</td>
                        <td>{{ $item['area'] }}</td>
                        <td>
                            <span class="status-badge" data-status="{{ $item['status'] }}">{{ $item['status_label'] }}</span>
                        </td>
                        <td><strong>{{ $item['earning'] }}</strong></td>
                        <td>
                            <button
                                class="button button-small"
                                type="button"
                                data-history-detail
                                data-history-key="{{ $item['key'] }}"
                                data-history-id="{{ $item['id'] }}"
                                data-history-parcel="{{ $item['parcel_no'] }}"
                                data-history-customer="{{ $item['customer'] }}"
                                data-history-area="{{ $item['area'] }}"
                                data-history-status="{{ $item['status_label'] }}"
                                data-history-earning="{{ $item['earning'] }}"
                                data-history-batch="{{ $item['batch_no'] }}"
                                data-history-attempt="{{ $item['attempt'] }}"
                                data-history-proof-recipient="{{ $item['proof_recipient'] }}"
                                data-history-failure-reason="{{ $item['failure_reason'] }}"
                                data-history-events="{{ json_encode($item['timeline']) }}"
                                data-history-products="{{ json_encode($item['products'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
                                aria-haspopup="dialog"
                                aria-label="View details for {{ $item['id'] }} · {{ $item['parcel_no'] }}"
                            >
                                View
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="empty-row" colspan="7">
                            No delivery history matches these filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="history-pagination" role="status" aria-live="polite">
        <span>
            Showing {{ $history->firstItem() ?? 0 }}–{{ $history->lastItem() ?? 0 }} of {{ number_format($history->total()) }} records
        </span>
        <div class="page-actions">
            @if($history->previousPageUrl())
                <a class="button button-small" href="{{ $history->previousPageUrl() }}" rel="prev">Previous</a>
            @endif
            @if($history->nextPageUrl())
                <a class="button button-small" href="{{ $history->nextPageUrl() }}" rel="next">Next</a>
            @endif
        </div>
    </div>
</section>

<section
    class="modal history-detail-modal"
    data-modal="history-detail"
    role="dialog"
    aria-modal="true"
    aria-labelledby="history-detail-title"
    hidden
>
    <div class="modal-header">
        <div>
            <h3 id="history-detail-title" data-history-title>Delivery details</h3>
            <p data-history-subtitle>Recorded shipment and parcel activity.</p>
        </div>
        <button class="icon-button" type="button" data-modal-close aria-label="Close delivery details">
            <i data-lucide="x" aria-hidden="true"></i>
        </button>
    </div>
    <div class="modal-body">
        <dl class="history-detail-grid">
            <div><dt>Recipient</dt><dd data-history-field="customer">—</dd></div>
            <div><dt>Service area</dt><dd data-history-field="area">—</dd></div>
            <div><dt>Outcome</dt><dd data-history-field="status">—</dd></div>
            <div><dt>Recorded earning</dt><dd data-history-field="earning">—</dd></div>
            <div><dt>Parcel</dt><dd data-history-field="parcel">—</dd></div>
            <div><dt>Dispatch batch</dt><dd data-history-field="batch">—</dd></div>
            <div><dt>Delivery attempt</dt><dd data-history-field="attempt">—</dd></div>
            <div><dt>Accepted by</dt><dd data-history-field="proof-recipient">—</dd></div>
            <div class="history-detail-grid-wide"><dt>Failure reason</dt><dd data-history-field="failure-reason">—</dd></div>
        </dl>

        <section class="history-detail-products" aria-labelledby="history-products-title">
            <h4 id="history-products-title">Products in shipment</h4>
            <div class="order-item-preview-list" data-history-products-list role="list">
                <p class="empty-copy">Select a history record to review its products.</p>
            </div>
        </section>

        <h4 class="history-timeline-heading">Parcel activity</h4>
        <div class="activity-list" data-history-timeline aria-live="polite">
            <p class="empty-copy">Select a history record to review its activity.</p>
        </div>
    </div>
    <div class="modal-footer">
        <button class="button" type="button" data-modal-close>Close</button>
    </div>
</section>
@endsection

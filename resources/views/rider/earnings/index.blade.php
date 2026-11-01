@extends('rider.layouts.app')

@section('title', 'Earnings')
@section('page-title', 'Earnings')

@section('content')
@php
    $exportFilters = collect($filters)->filter(static fn ($value) => filled($value))->all();
    $hasFilters = collect($filters)->filter(static fn ($value) => filled($value))->isNotEmpty();
@endphp

<div data-rider-earnings-live data-status-url="{{ route('rider.earnings.status-version') }}" data-status-version="{{ $earningsStatusVersion }}">
<div class="page-header">
    <div>
        <p class="page-kicker">Rider ledger</p>
        <h2>Recorded earnings</h2>
        <p>Outbound shipping earnings wait for Seller Order completion, the 3-calendar-day return/refund period, and payment/COD checks. A return-shipping earning becomes eligible after the approved return is delivered to the Seller. Both wait for Admin/Finance remittance; Logistics records your actual payout afterward.</p>
    </div>
    <div class="page-actions">
        <a class="button" href="{{ route('rider.history.index') }}">
            <i data-lucide="history" aria-hidden="true"></i>
            View Delivery History
        </a>
        <a class="button button-primary" href="{{ route('rider.earnings.export', $exportFilters) }}">
            <i data-lucide="download" aria-hidden="true"></i>
            Export filtered CSV
        </a>
    </div>
</div>

<section class="metric-strip" aria-label="Earnings ledger summary" data-rider-earnings-summary>
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="sun" aria-hidden="true"></i></span>
        <span class="metric-copy">
            <small>Today</small>
            <strong>{{ $earnings['today']['amount'] }}</strong>
            <span>{{ number_format($earnings['today']['entries']) }} active ledger {{ $earnings['today']['entries'] === 1 ? 'entry' : 'entries' }}</span>
        </span>
    </article>
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="calendar-days" aria-hidden="true"></i></span>
        <span class="metric-copy">
            <small>This week</small>
            <strong>{{ $earnings['week']['amount'] }}</strong>
            <span>{{ number_format($earnings['week']['entries']) }} active ledger {{ $earnings['week']['entries'] === 1 ? 'entry' : 'entries' }}</span>
        </span>
    </article>
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="calendar-range" aria-hidden="true"></i></span>
        <span class="metric-copy">
            <small>This month</small>
            <strong>{{ $earnings['month']['amount'] }}</strong>
            <span>{{ number_format($earnings['month']['entries']) }} active ledger {{ $earnings['month']['entries'] === 1 ? 'entry' : 'entries' }}</span>
        </span>
    </article>
    <article class="metric-item">
        <span class="metric-icon"><i data-lucide="book-open-check" aria-hidden="true"></i></span>
        <span class="metric-copy">
            <small>Active ledger total</small>
            <strong>{{ $earnings['active_total'] }}</strong>
            <span>{{ number_format($earnings['active_entries']) }} active entries · {{ number_format($earnings['excluded_entries']) }} reversed/cancelled excluded</span>
        </span>
    </article>
</section>

<section class="panel" style="margin-bottom:20px" aria-label="Earnings ledger information">
    <div class="panel-body">
        <div class="attention-item">
            <i data-lucide="info" aria-hidden="true"></i>
            <span>
                <strong>Outbound and return earnings have different eligibility checks</strong>
                <small>Outbound earnings wait for the 3-calendar-day return/refund period from Seller Order completion, captured payment, no open return/refund request or pending/processing refund, and exact COD reconciliation. A return-shipping earning becomes eligible when the approved return is delivered to the Seller. Pending means Finance has not remitted to your provider; Posted means remittance was recorded; Paid means Logistics recorded your actual payout. A missing row is not labeled pending, and this page does not guess why it is missing.</small>
            </span>
        </div>
    </div>
</section>

<section class="panel" aria-label="Earnings activity">
    <div class="panel-header">
        <div>
            <h3>Earnings activity</h3>
            <p>Filter entries by their recorded earned date, linked work, type, or ledger status.</p>
        </div>
    </div>

    <form class="table-toolbar history-toolbar earnings-toolbar" method="GET" action="{{ route('rider.earnings.index') }}">
        <label class="search-field">
            <i data-lucide="search" aria-hidden="true"></i>
            <input type="search" name="search" value="{{ $filters['search'] }}" maxlength="120" placeholder="Search job, type, status, or description" aria-label="Search earnings">
        </label>

        <label>
            <span class="sr-only">Status</span>
            <select class="filter-select" name="status" aria-label="Filter by ledger status">
                <option value="">All statuses</option>
                @foreach($statusOptions as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ \Illuminate\Support\Str::headline(str_replace('_', ' ', $status)) }}</option>
                @endforeach
            </select>
        </label>

        <label>
            <span class="sr-only">Type</span>
            <select class="filter-select" name="type" aria-label="Filter by earning type">
                <option value="">All types</option>
                @foreach($typeOptions as $type)
                    <option value="{{ $type }}" @selected($filters['type'] === $type)>{{ \Illuminate\Support\Str::headline(str_replace('_', ' ', $type)) }}</option>
                @endforeach
            </select>
        </label>

        <label class="history-date-field">
            <span>Earned from</span>
            <input class="filter-select" type="date" name="date_from" value="{{ $filters['date_from'] }}" aria-label="Earned from" aria-invalid="{{ $errors->has('date_from') ? 'true' : 'false' }}">
            @error('date_from')<small class="delivery-field-error" role="alert">{{ $message }}</small>@enderror
        </label>

        <label class="history-date-field">
            <span>Earned to</span>
            <input class="filter-select" type="date" name="date_to" value="{{ $filters['date_to'] }}" aria-label="Earned to" aria-invalid="{{ $errors->has('date_to') ? 'true' : 'false' }}">
            @error('date_to')<small class="delivery-field-error" role="alert">{{ $message }}</small>@enderror
        </label>

        <button class="button button-small" type="submit"><i data-lucide="filter" aria-hidden="true"></i>Apply</button>

        @if($hasFilters)
            <a class="button button-small" href="{{ route('rider.earnings.index') }}">Clear</a>
        @endif
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Earned at</th>
                    <th>Linked job</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Posted at</th>
                    <th>Paid at</th>
                    <th>Payout details</th>
                </tr>
            </thead>
            <tbody data-rider-earnings-rows>
                @forelse($logs as $log)
                    <tr>
                        <td><time datetime="{{ $log['earned_at_iso'] }}">{{ $log['earned_at'] }}</time></td>
                        <td>
                            @php($jobProducts = $log['products'] ?? [])
                            <div class="earnings-job-preview">
                                @if($jobProducts)
                                    <div class="earnings-product-thumbnails" role="group" aria-label="Product images for {{ $log['job'] }}">
                                        @foreach(array_slice($jobProducts, 0, 3) as $product)
                                            <span class="earnings-product-thumbnail">
                                                @if(filled($product['image_url']))
                                                    <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" title="{{ $product['name'] }}" loading="lazy" decoding="async">
                                                @else
                                                    <span class="earnings-product-thumbnail-placeholder" role="img" aria-label="No image for {{ $product['name'] }}">
                                                        <i data-lucide="package" aria-hidden="true"></i>
                                                    </span>
                                                @endif
                                            </span>
                                        @endforeach
                                        @if(count($jobProducts) > 3)
                                            @php($additionalImages = count($jobProducts) - 3)
                                            <span class="earnings-product-thumbnail-more" aria-label="{{ $additionalImages }} more {{ $additionalImages === 1 ? 'product' : 'products' }}">+{{ $additionalImages }}</span>
                                        @endif
                                    </div>
                                @endif
                                <div class="earnings-job-cell">
                                    <strong>{{ $log['job'] }}</strong>
                                    @if(filled($log['order_no']))<small>Order {{ $log['order_no'] }}</small>@endif
                                    <small>{{ $log['source'] }}</small>
                                    @if($jobProducts)
                                        @php($previewProduct = $jobProducts[0])
                                        <small class="earnings-job-product-preview">{{ $previewProduct['name'] }}@if(filled($previewProduct['variant'])) · {{ $previewProduct['variant'] }}@endif · Qty {{ $previewProduct['quantity'] }}</small>
                                        @if(count($jobProducts) > 1)<small>{{ count($jobProducts) - 1 }} more {{ count($jobProducts) - 1 === 1 ? 'product' : 'products' }} in this job</small>@endif
                                    @endif
                                    @if(filled($log['description']))
                                        <small>{{ $log['description'] }}</small>
                                    @endif
                                    @if(filled($log['return_window_ended_at']))
                                        <small>3-day return period ended {{ $log['return_window_ended_at'] }}</small>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td>{{ $log['type_label'] }}</td>
                        <td><strong>{{ $log['amount'] }}</strong></td>
                        <td><span class="status-badge" data-status="{{ $log['status'] }}">{{ $log['status_label'] }}</span></td>
                        <td>{{ $log['posted_at'] }}</td>
                        <td>{{ $log['paid_at'] }}</td>
                        <td>
                            @if(filled($log['payout_reference']))
                                <strong>{{ \Illuminate\Support\Str::headline(str_replace('_', ' ', $log['payout_method'])) }}</strong>
                                <small class="table-subline">{{ $log['payout_paid_on'] }} · {{ $log['payout_amount'] }}</small>
                                <code>{{ $log['payout_reference'] }}</code>
                                @if(filled($log['payout_reason']))<small class="table-subline">{{ $log['payout_reason'] }}</small>@endif
                            @else
                                <span class="empty-copy">No payout recorded</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="empty-row" colspan="8">
                            @if($hasFilters)
                                No earning ledger entries match these filters.
                            @else
                                <strong>No earning ledger entries are currently recorded for your Rider profile.</strong>
                                <span>Delivery History is separate and does not create an earning by itself. Outbound earnings appear after the 3-calendar-day return/refund period and payment/COD checks pass. A return-shipping earning appears after an approved return is delivered to the Seller. Finance remits the exact 40/60 allocation to your provider; Logistics records the final Rider payout.</span>
                                <a class="button button-small" href="{{ route('rider.history.index') }}">Review Delivery History</a>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="history-pagination" role="status" aria-live="polite" data-rider-earnings-pagination>
        <span>Showing {{ $logs->firstItem() ?? 0 }}–{{ $logs->lastItem() ?? 0 }} of {{ number_format($logs->total()) }} records</span>
        <div class="page-actions">
            @if($logs->previousPageUrl())
                <a class="button button-small" href="{{ $logs->previousPageUrl() }}" rel="prev">Previous</a>
            @endif
            @if($logs->nextPageUrl())
                <a class="button button-small" href="{{ $logs->nextPageUrl() }}" rel="next">Next</a>
            @endif
        </div>
    </div>
</section>
</div>
@endsection

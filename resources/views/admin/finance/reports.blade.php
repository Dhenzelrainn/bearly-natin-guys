@extends('layouts.admin')

@section('title', 'Financial Reports')
@section('page-title', 'Financial Reports')

@section('content')
<section class="page-hero">
    <div><span class="eyebrow">Finance reporting</span><h1>Generate financial reports</h1><p>Choose a sales, commission, seller settlement, or refund report, set the reporting period, and export the filtered result.</p></div>
    <div class="hero-actions"><button class="button button-secondary" type="button" data-export-report="pdf"><i data-lucide="file-down"></i> Export PDF</button><a class="button button-primary" href="{{ $exportUrl }}"><i data-lucide="sheet"></i> Export CSV</a></div>
</section>

<form class="report-filter-bar panel" method="GET" action="{{ route('admin.reports') }}">
    <div>
        <span class="section-label">Report period</span>
        <div class="date-range-inline">
            <input class="date-field" type="date" name="from" value="{{ old('from', $dateRange['from']) }}" required aria-label="Report start date">
            <span>to</span>
            <input class="date-field" type="date" name="to" value="{{ old('to', $dateRange['to']) }}" required aria-label="Report end date">
        </div>
        @error('from')<small class="field-error">{{ $message }}</small>@enderror
        @error('to')<small class="field-error">{{ $message }}</small>@enderror
    </div>
    <label class="form-field inline-filter"><span>Report type</span>
        <select class="select-field" name="type">
            @foreach ($reportTypes as $key => $label)
                <option value="{{ $key }}" @selected($reportKey === $key)>{{ $label }}</option>
            @endforeach
        </select>
        @error('type')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    @if ($reportKey === 'sales_seller')
        <label class="form-field inline-filter"><span>Seller</span>
            <select class="select-field" name="seller">
                <option value="">All sellers</option>
                @foreach ($sellerOptions as $option)
                    <option value="{{ $option['id'] }}" @selected($selectedSeller === $option['id'])>{{ $option['label'] }}</option>
                @endforeach
            </select>
        </label>
    @elseif ($reportKey === 'sales_category')
        <label class="form-field inline-filter"><span>Category</span>
            <select class="select-field" name="category">
                <option value="">All categories</option>
                @foreach ($categoryOptions as $option)
                    <option value="{{ $option['id'] }}" @selected($selectedCategory === $option['id'])>{{ $option['label'] }}</option>
                @endforeach
            </select>
        </label>
    @endif
    <button class="button button-primary" type="submit"><i data-lucide="sliders-horizontal"></i> Apply filters</button>
</form>

<div class="report-filter-summary" data-report-context
     data-report-label="{{ $reportLabel }}"
     data-report-period="{{ $dateRange['label'] }}"
     data-report-from="{{ $dateRange['from'] }}"
     data-report-to="{{ $dateRange['to'] }}"
     data-report-basis="{{ $basisNote }}">
    <span class="report-filter-chip"><i data-lucide="calendar-range"></i>{{ $reportLabel }} · {{ $dateRange['label'] }}</span>
    @if ($filterLabel !== null)
        <span class="report-filter-chip"><i data-lucide="funnel"></i>{{ $filterLabel }}</span>
    @endif
    <p>{{ $basisNote }}</p>
</div>

<section class="kpi-grid">
    @foreach ($reportKpis as $metric)
        <article class="kpi-card report-kpi"><p>{{ $metric['label'] }}</p><strong>{{ $metric['value'] }}</strong><small>{{ $metric['note'] }}</small></article>
    @endforeach
</section>

@if ($reportKey === 'sales_summary')
    <section class="dashboard-grid dashboard-grid-main">
        <article class="panel panel-large">
            <div class="panel-heading"><div><span class="eyebrow">Sales summary report</span><h2>Net sales by period</h2><p>Server-bucketed net product sales for {{ $dateRange['label'] }}: gross product sales after item discounts and completed refunds.</p></div><span class="status-badge badge-success">{{ $chartHasData ? 'Recorded sales' : 'No data' }}</span></div>
            <div class="line-chart-shell" data-chart>
                @if (! $chartHasData || $salesChart === [])
                    <div class="report-no-results report-chart-empty"><i data-lucide="bar-chart-3"></i><strong>No completed sales in this period</strong><span>Adjust the report period or complete orders to see the sales trend.</span></div>
                @else
                    <div class="line-chart-grid"></div>
                    @php
                        $chartCount = count($salesChart);
                        $chartMax = (int) collect($salesChart)->max(fn (array $bucket): int => max(0, (int) $bucket['net_minor']));
                        $chartPoints = collect($salesChart)->map(function (array $bucket, int $index) use ($chartCount, $chartMax): array {
                            $netMinor = (int) $bucket['net_minor'];
                            $x = $chartCount > 1 ? ($index / ($chartCount - 1)) * 1080 + 10 : 550;
                            $y = 285 - ($chartMax > 0 ? (max(0, $netMinor) / $chartMax) * 235 : 0);

                            return [
                                'x' => round($x, 2),
                                'y' => round($y, 2),
                                'label' => $bucket['label'],
                                'display' => ($netMinor < 0 ? '−' : '').'₱'.number_format(abs($netMinor) / 100, 2),
                            ];
                        })->values();
                    @endphp
                    <svg class="line-chart-svg" viewBox="0 0 1100 320" preserveAspectRatio="none" role="group" aria-label="Net sales trend chart">
                        <polyline points="{{ $chartPoints->map(fn ($point) => $point['x'].','.$point['y'])->implode(' ') }}" fill="none" stroke="currentColor" stroke-width="8" stroke-linecap="round" stroke-linejoin="round" />
                        @foreach ($chartPoints as $index => $point)
                            <circle
                                cx="{{ $point['x'] }}"
                                cy="{{ $point['y'] }}"
                                r="10"
                                data-chart-point
                                tabindex="{{ $index === 0 ? 0 : -1 }}"
                                role="img"
                                aria-label="{{ $point['label'] }}: {{ $point['display'] }}"
                                data-label="{{ $point['label'] }}"
                                data-value="{{ $point['display'] }}"
                            />
                        @endforeach
                    </svg>
                    <div class="chart-months" style="grid-template-columns: repeat({{ $chartCount }}, 1fr)">@foreach ($salesChart as $bucket)<span>{{ $bucket['axis'] }}</span>@endforeach</div>
                @endif
            </div>
        </article>
        <aside class="panel report-breakdown">
            <div class="panel-heading"><div><span class="eyebrow">Category preview</span><h2>Top categories</h2></div></div>
            @forelse ($topCategories as $row)
                <div class="report-mini-row">
                    <div><strong>{{ $row['label'] }}</strong><small>{{ $row['gross'] }} gross product sales</small></div>
                    <span class="report-share"><b>{{ number_format($row['share'], 1) }}%</b><i><em style="width:{{ $row['share'] }}%"></em></i></span>
                </div>
            @empty
                <div class="report-no-results"><i data-lucide="tags"></i><strong>No category sales yet</strong><span>Category totals appear once orders complete in this period.</span></div>
            @endforelse
            <a class="text-button" href="{{ route('admin.reports', ['type' => 'sales_category', 'from' => $dateRange['from'], 'to' => $dateRange['to']]) }}">Open Sales by Category</a>
        </aside>
    </section>

    <section class="panel">
        <div class="panel-heading"><div><span class="eyebrow">Sales summary report</span><h2>Sales by period</h2><p>The same buckets used by the trend chart, from the server-filtered dataset.</p></div></div>
        <div class="table-wrap"><table class="admin-table" data-report-export-table><thead><tr><th>Period</th><th class="align-right">Seller orders</th><th class="align-right">Gross product sales</th><th class="align-right">Discounts</th><th class="align-right">Completed refunds</th><th class="align-right">Net sales</th><th class="align-right">Units</th></tr></thead><tbody>
            @forelse ($summaryRows as $row)
                <tr><td>{{ $row['label'] }}</td><td class="align-right">{{ $row['orders'] }}</td><td class="align-right">{{ $row['gross'] }}</td><td class="align-right">{{ $row['discounts'] }}</td><td class="align-right">{{ $row['refunds'] }}</td><td class="align-right"><strong>{{ $row['net'] }}</strong></td><td class="align-right">{{ $row['units'] }}</td></tr>
            @empty
                <tr><td colspan="7"><div class="report-no-results"><i data-lucide="calendar-x"></i><strong>No completed sales in this period</strong><span>Try a wider date range.</span></div></td></tr>
            @endforelse
        </tbody></table></div>
    </section>
@endif

@if ($reportKey === 'sales_seller')
    <section class="panel">
        <div class="panel-heading"><div><span class="eyebrow">Sales by seller report</span><h2>Seller sales performance</h2><p>Completed seller orders grouped by Store (one Store per Seller) for {{ $dateRange['label'] }}.</p></div></div>
        <div class="table-wrap"><table class="admin-table" data-report-export-table><thead><tr><th>Seller</th><th class="align-right">Gross product sales</th><th class="align-right">Discounts</th><th class="align-right">Completed refunds</th><th class="align-right">Net sales</th><th class="align-right">Seller orders</th><th class="align-right">Units</th><th>Share of gross</th></tr></thead><tbody>
            @forelse ($sellerRows as $row)
                <tr>
                    <td><div class="identity-cell"><span class="avatar avatar-soft">{{ $loop->iteration }}</span><div><strong>{{ $row['name'] }}</strong><small>{{ $row['meta'] }}</small></div></div></td>
                    <td class="align-right">{{ $row['gross'] }}</td>
                    <td class="align-right">{{ $row['discounts'] }}</td>
                    <td class="align-right">{{ $row['refunds'] }}</td>
                    <td class="align-right"><strong>{{ $row['net'] }}</strong></td>
                    <td class="align-right">{{ $row['orders'] }}</td>
                    <td class="align-right">{{ $row['units'] }}</td>
                    <td><span class="report-share"><b>{{ number_format($row['share'], 1) }}%</b><i><em style="width:{{ $row['share'] }}%"></em></i></span></td>
                </tr>
            @empty
                <tr><td colspan="8"><div class="report-no-results"><i data-lucide="store"></i><strong>No seller sales in this period</strong><span>Completed seller orders will appear here.</span></div></td></tr>
            @endforelse
        </tbody></table></div>
        <p class="report-table-note">Refunds are attributed to the Store on their completed return case; seller orders from one marketplace checkout stay split per seller, so nothing is double counted.</p>
    </section>
@endif

@if ($reportKey === 'sales_category')
    <section class="panel">
        <div class="panel-heading"><div><span class="eyebrow">Sales by category report</span><h2>Category sales performance</h2><p>Purchase-time category snapshots from completed seller orders for {{ $dateRange['label'] }}.</p></div></div>
        <div class="table-wrap"><table class="admin-table" data-report-export-table><thead><tr><th>Category</th><th class="align-right">Gross product sales</th><th class="align-right">Discounts</th><th class="align-right">Completed refunds</th><th class="align-right">Net sales</th><th class="align-right">Orders</th><th class="align-right">Units</th><th>Share of gross</th></tr></thead><tbody>
            @forelse ($categoryRows as $row)
                <tr>
                    <td><strong>{{ $row['label'] }}</strong>@if (! $row['recorded']) <small class="report-row-note">No purchase-time category recorded</small>@endif</td>
                    <td class="align-right">{{ $row['gross'] }}</td>
                    <td class="align-right">{{ $row['discounts'] }}</td>
                    <td class="align-right">{{ $row['refunds'] }}</td>
                    <td class="align-right"><strong>{{ $row['net'] }}</strong></td>
                    <td class="align-right">{{ $row['orders'] }}</td>
                    <td class="align-right">{{ $row['units'] }}</td>
                    <td><span class="report-share"><b>{{ number_format($row['share'], 1) }}%</b><i><em style="width:{{ $row['share'] }}%"></em></i></span></td>
                </tr>
            @empty
                <tr><td colspan="8"><div class="report-no-results"><i data-lucide="tags"></i><strong>No category sales in this period</strong><span>Completed seller orders will appear here.</span></div></td></tr>
            @endforelse
        </tbody></table></div>
        <p class="report-table-note">Order counts can overlap: a marketplace order containing items from multiple categories is counted in each of those categories. Refunds are allocated per refunded item. Legacy items without a purchase-time snapshot stay under “Category not recorded”.</p>
    </section>
@endif

@if ($reportKey === 'commission')
    <section class="panel">
        <div class="panel-heading"><div><span class="eyebrow">Commission report</span><h2>Top seller contribution</h2><p>Commission ledger rows finalized in {{ $dateRange['label'] }}. This is a ledger view, not the Sales by Seller report.</p></div><a class="text-button" href="{{ route('admin.transactions') }}">Open transaction ledger</a></div>
        <div class="table-wrap"><table class="admin-table" data-report-export-table><thead><tr><th>Seller</th><th class="align-right">Commissionable sales</th><th class="align-right">Platform commission</th><th>Share</th></tr></thead><tbody>
            @forelse ($topSellers as $index => $seller)
                <tr><td><div class="identity-cell"><span class="avatar avatar-soft">{{ $index + 1 }}</span><div><strong>{{ $seller['seller'] }}</strong><small>Recognized commission ledger entries</small></div></div></td><td class="align-right">{{ $seller['sales'] }}</td><td class="align-right commission-value">{{ $seller['commission'] }}</td><td><span class="report-share"><b>{{ number_format($seller['share'], 1) }}%</b><i><em style="width:{{ $seller['share'] }}%"></em></i></span></td></tr>
            @empty
                <tr><td colspan="4"><div class="report-no-results"><i data-lucide="receipt"></i><strong>No finalized commission entries</strong><span>Finalized commissions and completed reversals appear here.</span></div></td></tr>
            @endforelse
        </tbody></table></div>
    </section>
@endif

@if ($reportKey === 'settlement')
    <section class="panel">
        <div class="panel-heading"><div><span class="eyebrow">Seller settlement report</span><h2>Payout status by seller</h2><p>Settlement periods overlapping {{ $dateRange['label'] }}.</p></div><a class="text-button" href="{{ route('admin.payments') }}">Manage seller payments</a></div>
        <div class="table-wrap"><table class="admin-table" data-report-export-table><thead><tr><th>Seller</th><th>Settlement period</th><th class="align-right">Net payout</th><th>Status</th></tr></thead><tbody>
            @forelse ($settlementRows as $row)
                <tr><td><strong>{{ $row['seller'] }}</strong></td><td>{{ $row['period'] }}</td><td class="align-right">{{ $row['net'] }}</td><td><span class="status-badge {{ $row['status'] === 'Paid' ? 'badge-success' : ($row['status'] === 'Processing' ? 'badge-info' : 'badge-warning') }}">{{ $row['status'] }}</span></td></tr>
            @empty
                <tr><td colspan="4"><div class="report-no-results"><i data-lucide="wallet"></i><strong>No seller settlements in this period</strong><span>Payout records appear once seller settlement periods overlap the selected dates.</span></div></td></tr>
            @endforelse
        </tbody></table></div>
    </section>
@endif

@if ($reportKey === 'refund')
    <section class="panel">
        <div class="panel-heading"><div><span class="eyebrow">Refund report</span><h2>Refund amounts and resolution status</h2><p>Refund cases created in {{ $dateRange['label'] }}.</p></div><a class="text-button" href="{{ route('admin.compliance.returns-refunds') }}">Open returns &amp; refunds</a></div>
        <div class="table-wrap"><table class="admin-table" data-report-export-table><thead><tr><th>Case</th><th>Order</th><th>Seller</th><th class="align-right">Amount</th><th>Financially responsible</th><th>Seller recovery balance</th><th>Status</th></tr></thead><tbody>
            @forelse ($refundRows as $row)
                <tr><td><strong>{{ $row['case'] }}</strong></td><td>{{ $row['order'] }}</td><td>{{ $row['seller'] }}</td><td class="align-right">{{ $row['amount'] }}</td><td>{{ $row['responsible_party'] }}</td><td>{{ $row['seller_recovery'] }}</td><td><span class="status-badge {{ $row['status'] === 'Completed' ? 'badge-success' : ($row['status'] === 'Approved' ? 'badge-info' : 'badge-warning') }}">{{ $row['status'] }}</span></td></tr>
            @empty
                <tr><td colspan="7"><div class="report-no-results"><i data-lucide="undo-2"></i><strong>No refund cases in this period</strong><span>Refund cases raised in the selected dates appear here.</span></div></td></tr>
            @endforelse
        </tbody></table></div>
    </section>
@endif
@endsection

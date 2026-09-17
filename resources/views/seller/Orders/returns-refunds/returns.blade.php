@extends('layouts.seller')

@section('title', 'Returns & Refunds')
@section('page-title', 'Returns & Refunds')
@section('topbar-subtitle', 'Review buyer requests, submit evidence, and track case resolution.')
@section('content')
<div class="returns-refined">
<div class="returns-utility">
    <details class="returns-guide">
        <summary>How returns work</summary>
        <dl>
            <div><dt>Buyer</dt><dd>Submits the request and evidence.</dd></div>
            <div><dt>Seller</dt><dd>Reviews the request and submits a response and evidence.</dd></div>
            <div><dt>Platform / Admin</dt><dd>Reviews disputes and determines the resolution.</dd></div>
            <div><dt>Logistics</dt><dd>Handles the physical return when required.</dd></div>
        </dl>
    </details>
    <a class="seller-secondary-button" href="{{ route('seller.orders') }}"><i data-lucide="arrow-left" aria-hidden="true"></i>Manage Orders</a>
</div>

<section class="returns-summary" aria-label="Returns and refunds summary">
    @foreach ($summary as $item)
        <article class="returns-summary-card tone-{{ $item['tone'] }}">
            <span class="returns-summary-icon"><i data-lucide="{{ $item['icon'] }}"></i></span>
            <div><span>{{ $item['label'] }}</span><strong>{{ $item['value'] }}</strong>@if (stripos($item['label'], 'refunded') !== false)
                <small>{{ $item['period_label'] ?? 'Current calendar month' }}</small>
            @else
                <small>{{ $item['note'] }}</small>
            @endif</div>
        </article>
    @endforeach
</section>

<section class="returns-workspace" data-returns-workspace>
    <div class="returns-tabs" role="tablist" aria-label="Filter return and refund cases">
        @foreach ($tabs as $tab)
            <button class="returns-tab {{ $tab['key'] === 'all' ? 'is-active' : '' }}" type="button" role="tab" aria-selected="{{ $tab['key'] === 'all' ? 'true' : 'false' }}" data-return-tab="{{ $tab['key'] }}">
                {{ $tab['label'] }} @if ($tab['count'] !== null)<span>{{ $tab['count'] }}</span>@endif
            </button>
        @endforeach
    </div>
    <div class="returns-toolbar">
        <label class="returns-search"><i data-lucide="search"></i><span class="sr-only">Search return cases</span><input type="search" placeholder="Search case, order, customer, or product" data-return-search></label>
        <label class="returns-select"><span class="sr-only">Filter request type</span><select data-return-type><option value="">All request types</option><option value="return-refund">Return & Refund</option><option value="refund-only">Refund Only</option></select></label>
        <button class="returns-reset" type="button" data-return-reset><i data-lucide="rotate-ccw"></i>Reset</button>
        <button class="returns-export" type="button" data-return-export><i data-lucide="download"></i>Export</button>
    </div>
    <div class="returns-table-wrap">
        <table class="returns-table returns-table-polished">
            <thead><tr><th>Case / Order</th><th>Customer / Product</th><th>Request</th><th>Reason</th><th>Amount</th><th>Financial Effect</th><th>Response Deadline</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
                @foreach ($returns as $case)
                    @php($typeKey = $case['request'] === 'Refund Only' ? 'refund-only' : 'return-refund')
                    <tr data-return-row data-status="{{ $case['status_key'] }}" data-type="{{ $typeKey }}" data-search="{{ strtolower(implode(' ', $case)) }}">
                        <td><strong>{{ $case['id'] }}</strong><small>{{ $case['order'] }} · {{ $case['submitted'] }}</small></td>
                        <td><strong>{{ $case['customer'] }}</strong><small>{{ $case['product'] }}</small></td>
                        <td>{{ $case['request'] }}</td><td>{{ $case['reason'] }}</td><td><strong>{{ $case['amount'] }}</strong></td>
                        <td><span class="return-finance-effect">{{ $case['commission_effect'] }}</span>@if (trim($case['earnings_effect'] ?? '') !== '' && strcasecmp(trim($case['earnings_effect']), trim($case['commission_effect'] ?? '')) !== 0)
                            <small>{{ $case['earnings_effect'] }}</small>
                        @endif</td>
                        <td><span class="return-deadline {{ $case['status_key'] === 'action-required' ? 'is-urgent' : '' }}">{{ $case['deadline'] }}</span></td>
                        <td><span class="return-status status-{{ $case['tone'] }}">{{ $case['status'] }}</span></td>
                        <td><a class="return-action" href="{{ route('seller.orders.returns.show', ['caseId' => $case['id']]) }}">{{ $case['action'] }}</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="returns-empty" data-returns-empty hidden><i data-lucide="search-x"></i><strong>No matching cases</strong><span>Try changing the status, type, or search term.</span></div>
    </div>
    <footer class="returns-footer"><span>Showing <strong data-return-count>{{ count($returns) }}</strong> of {{ count($returns) }} cases</span><span>A finalized full refund reverses the related platform commission.</span></footer>
</section>
</div>
<style>
/* Returns list only: same typography tokens as Orders. */
.returns-refined { font-size:var(--seller-font-body,14px); }
.seller-body:has(.returns-refined) .seller-topbar-subtitle {
    font-size:var(--seller-font-secondary,12px); line-height:1.5;
}
.returns-refined .returns-utility {
    display:flex; align-items:flex-start; justify-content:space-between;
    gap:20px; margin-bottom:20px;
}
.returns-refined .returns-guide { flex:1; min-width:0; color:#4b3428; }
.returns-refined .returns-guide summary {
    width:fit-content; padding:10px 0; cursor:pointer; font-weight:600;
    font-size:var(--seller-font-control,13px);
}
.returns-refined .returns-guide dl { margin:8px 0 0; padding:0; }
.returns-refined .returns-guide dl > div {
    display:grid; grid-template-columns:140px minmax(0,1fr);
    gap:12px; padding:8px 0; border-top:1px solid #eeeae6;
}
.returns-refined .returns-guide dt { font-weight:600; }
.returns-refined .returns-guide dd { margin:0; color:#71665e; }
.returns-refined .returns-summary {
    display:grid; grid-template-columns:repeat(4,minmax(0,1fr));
    gap:0; overflow:hidden; border:1px solid #e8e3de;
    border-radius:12px; background:#fff; box-shadow:none;
}
.returns-refined .returns-summary-card {
    min-width:0; border:0; border-radius:0; background:transparent;
    padding:20px; gap:14px; box-shadow:none;
}
.returns-refined .returns-summary-card + .returns-summary-card {
    border-left:1px solid #f0ece8;
}
.returns-refined .returns-summary-card .returns-summary-icon {
    width:28px; height:32px; flex:0 0 28px;
    border:0; border-radius:0; background:transparent; color:#6f4a31;
}
.returns-refined .returns-summary-icon svg { width:24px; height:24px; }
.returns-refined .returns-summary-card > div > span { font-size:13px; }
.returns-refined .returns-summary-card > div > strong { font-size:27px; line-height:1.2; }
.returns-refined .returns-summary-card small { line-height:1.5; }
.returns-refined .returns-workspace { border-color:#e8e3de; border-radius:12px; box-shadow:none; }
.returns-refined .returns-tabs {
    overflow-x:auto; overflow-y:hidden; scrollbar-width:thin;
    background:#fff; border-bottom:1px solid #eeeae6;
}
.returns-refined .returns-tab { flex:0 0 auto; }
.returns-refined .returns-toolbar { background:#fff; border-bottom:1px solid #eeeae6; }
.returns-refined .return-action,
.returns-refined .returns-export {
    display:inline-flex; align-items:center; justify-content:center;
    min-height:38px; border:1px solid #dfa130; border-radius:7px;
    color:#a65e00; background:#fff; box-shadow:none; white-space:nowrap;
}
.returns-refined .return-action:hover,
.returns-refined .returns-export:hover {
    color:#fff; background:var(--seller-gold-dark,#d88b12);
    border-color:var(--seller-gold-dark,#d88b12);
}
.returns-refined .returns-table-wrap { overflow-x:auto; }
.returns-refined .returns-table { font-size:var(--seller-font-table,13.5px); }
.returns-refined .returns-table th,
.returns-refined .returns-table td { border-bottom-color:#eeeae6; }
.returns-refined .return-status { font-size:var(--seller-font-badge,11.5px); }
.returns-refined .returns-table td > small {
    white-space:normal; overflow:visible; text-overflow:clip;
}
.returns-refined .returns-footer {
    flex-wrap:wrap; padding-block:12px; background:#fff;
    font-size:var(--seller-font-secondary,12px);
}
.returns-refined summary:focus-visible,
.returns-refined a:focus-visible,
.returns-refined button:focus-visible {
    outline:2px solid #b77a18; outline-offset:3px;
}
.returns-refined .returns-search:focus-within,
.returns-refined .returns-select:focus-within { outline:2px solid #b77a18; outline-offset:2px; }
@media(max-width:1100px) {
    .returns-refined .returns-summary { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .returns-refined .returns-summary-card:nth-child(3) { border-left:0; }
    .returns-refined .returns-summary-card:nth-child(n+3) { border-top:1px solid #f0ece8; }
}
@media(max-width:620px) {
    .returns-refined .returns-utility { flex-wrap:wrap; }
    .returns-refined .returns-guide { flex-basis:100%; }
    .returns-refined .returns-guide dl > div { grid-template-columns:1fr; gap:4px; }
    .returns-refined .returns-summary { grid-template-columns:1fr; }
    .returns-refined .returns-summary-card + .returns-summary-card {
        border-left:0; border-top:1px solid #f0ece8;
    }
    .returns-refined .returns-toolbar { grid-template-columns:1fr 1fr; }
    .returns-refined .returns-search,
    .returns-refined .returns-select { grid-column:1/-1; min-width:0; }
}
</style>
@endsection

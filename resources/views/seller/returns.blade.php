@extends('layouts.seller')

@section('title', 'Returns & Refunds')
@section('page-title', 'Returns & Refunds')

@section('content')
<div class="page-heading returns-page-heading">
    <div><span class="section-kicker">Order resolution</span><h2>Returns & Refunds</h2><p>Review buyer requests, submit evidence, and monitor return or refund resolution.</p></div>
    <a class="seller-secondary-button" href="{{ route('seller.orders') }}"><i data-lucide="arrow-left"></i>Manage Orders</a>
</div>

<section class="returns-summary" aria-label="Returns and refunds summary">
    @foreach ($summary as $item)
        <article class="returns-summary-card tone-{{ $item['tone'] }}">
            <span class="returns-summary-icon"><i data-lucide="{{ $item['icon'] }}"></i></span>
            <div><span>{{ $item['label'] }}</span><strong>{{ $item['value'] }}</strong><small>{{ $item['note'] }}</small></div>
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
        <table class="returns-table">
            <thead><tr><th>Case / Order</th><th>Customer / Product</th><th>Request</th><th>Reason</th><th>Amount</th><th>Response Deadline</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
                @foreach ($returns as $case)
                    @php($typeKey = $case['request'] === 'Refund Only' ? 'refund-only' : 'return-refund')
                    <tr data-return-row data-status="{{ $case['status_key'] }}" data-type="{{ $typeKey }}" data-search="{{ strtolower(implode(' ', $case)) }}">
                        <td><strong>{{ $case['id'] }}</strong><small>{{ $case['order'] }} · {{ $case['submitted'] }}</small></td>
                        <td><strong>{{ $case['customer'] }}</strong><small>{{ $case['product'] }}</small></td>
                        <td>{{ $case['request'] }}</td><td>{{ $case['reason'] }}</td><td><strong>{{ $case['amount'] }}</strong></td>
                        <td><span class="return-deadline {{ $case['status_key'] === 'action-required' ? 'is-urgent' : '' }}">{{ $case['deadline'] }}</span></td>
                        <td><span class="return-status status-{{ $case['tone'] }}">{{ $case['status'] }}</span></td>
                        <td><a class="return-action" href="{{ route('seller.orders.returns.show', ['caseId' => $case['id']]) }}">{{ $case['action'] }}</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="returns-empty" data-returns-empty hidden><i data-lucide="search-x"></i><strong>No matching cases</strong><span>Try changing the status, type, or search term.</span></div>
    </div>
    <footer class="returns-footer"><span>Showing <strong data-return-count>{{ count($returns) }}</strong> of {{ count($returns) }} cases</span><span>Seller response deadlines follow platform review rules.</span></footer>
</section>

@endsection

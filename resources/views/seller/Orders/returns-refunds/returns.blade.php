@extends('layouts.seller')

@section('title', 'Returns & Refunds')
@section('page-title', 'Returns & Refunds')
@section('topbar-subtitle', 'Review buyer requests, submit evidence, and track case resolution.')
@section('content')
<div class="returns-refined">
<div class="returns-utility">
    <details class="returns-guide">
        <summary>How returns work</summary>
        <ol class="returns-guide-steps">
            <li><strong>The Buyer asks for help.</strong> Within 7 days after Bearly marks the order complete, they can ask for <em>Return &amp; Refund</em> or <em>Refund Only</em>. They can add photos or files.</li>
            <li><strong>You reply.</strong> Open the case, explain what happened, and attach at least one helpful file before the deadline shown.</li>
            <li><strong>Admin checks the case.</strong> Admin reads both sides and may ask the Buyer, you, or both for more proof. Then Admin approves or declines the request.</li>
            <li><strong>If an item must come back,</strong> Logistics picks it up and brings it to you. When it arrives, check each item and record its condition. Only items that are okay to sell and marked <em>Restock</em> go back into stock.</li>
            <li><strong>Admin handles the refund.</strong> For Refund Only, Admin may send the refund after approval. For Return &amp; Refund, Admin waits until you finish checking the returned item. Admin sends the money outside Bearly, then records it here. If Admin says the Seller must pay some money back, you will see the amount here. It will not be taken from your payout automatically.</li>
        </ol>
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
            <thead><tr><th>Case / Order</th><th>Customer / Product</th><th>Request &amp; Reason</th><th>Amount</th><th>Financial Effect</th><th>Response Deadline</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
                @foreach ($returns as $case)
                    @php
                        $typeKey = $case['request'] === 'Refund Only' ? 'refund-only' : 'return-refund';
                        $deadlineParts = explode(' · ', $case['deadline'], 2);
                        $caseIdBreakAt = (int) ceil(strlen($case['id']) / 2);
                    @endphp
                    <tr data-return-row data-status="{{ $case['status_key'] }}" data-type="{{ $typeKey }}" data-search="{{ strtolower(implode(' ', [$case['id'], $case['order'], $case['customer'], $case['product'], $case['reason'], $case['request'], $case['status']])) }}">
                        <td>
                            <div class="returns-table-primary">
                                <span class="returns-table-icon"><i data-lucide="file-text" aria-hidden="true"></i></span>
                                <div class="returns-table-primary-copy">
                                    <strong class="return-case-id">{{ substr($case['id'], 0, $caseIdBreakAt) }}<wbr>{{ substr($case['id'], $caseIdBreakAt) }}</strong>
                                    <small>{{ $case['order'] }}</small>
                                    <small class="return-case-submitted">{{ $case['submitted'] }}</small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="returns-table-primary">
                                <span class="returns-table-icon"><i data-lucide="user-round" aria-hidden="true"></i></span>
                                <div class="returns-table-primary-copy">
                                    <strong>{{ $case['customer'] }}</strong>
                                    <small class="returns-table-product">{{ str_replace(' · ', "\n", $case['product']) }}</small>
                                </div>
                            </div>
                        </td>
                        <td><span class="return-request-label">{{ $case['request'] }}</span><small class="return-reason">{{ $case['reason'] }}</small></td>
                        <td><strong class="return-amount">{{ $case['amount'] }}</strong></td>
                        <td>
                            <div class="return-finance-copy">
                                <span class="return-finance-effect">{{ $case['commission_effect'] }}</span>
                                @if (trim($case['earnings_effect'] ?? '') !== '' && strcasecmp(trim($case['earnings_effect']), trim($case['commission_effect'] ?? '')) !== 0)
                                    <small>{{ $case['earnings_effect'] }}</small>
                                @endif
                            </div>
                        </td>
                        <td>
                            <div class="return-deadline-layout">
                                <span class="returns-table-icon"><i data-lucide="calendar-days" aria-hidden="true"></i></span>
                                <span class="return-deadline {{ $case['status_key'] === 'action-required' ? 'is-urgent' : '' }}">
                                    <strong>{{ $deadlineParts[0] }}</strong>
                                    @if(isset($deadlineParts[1]))<small>{{ $deadlineParts[1] }}</small>@endif
                                </span>
                            </div>
                        </td>
                        <td><span class="return-status status-{{ $case['tone'] }}">{{ $case['status'] }}</span></td>
                        <td><a class="return-action" href="{{ route('seller.orders.returns.show', ['caseId' => $case['id']]) }}"><span>{{ $case['action'] }}</span><i data-lucide="chevron-right" aria-hidden="true"></i></a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="returns-empty" data-returns-empty hidden><i data-lucide="search-x"></i><strong>No matching cases</strong><span>Try changing the status, type, or search term.</span></div>
    </div>
    <footer class="returns-footer"><span class="returns-footer-count">Showing <strong data-return-count>{{ count($returns) }}</strong> of {{ count($returns) }} cases</span><span class="returns-footer-note"><i data-lucide="info" aria-hidden="true"></i><span>Seller-liable refunds may create a recoverable balance; no settlement is automatically deducted.</span></span></footer>
</section>
</div>
@endsection

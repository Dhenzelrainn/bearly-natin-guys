@extends('layouts.seller')

@section('title', 'Returns & Refunds')
@section('page-title', 'Returns & Refunds')

@section('content')
<div class="page-heading seller-workspace-heading">
    <div>
        <span class="section-kicker">Order resolution</span>
        <h2>Returns & Refunds</h2>
        <p>Review buyer claims, evidence, returned parcels, and refund outcomes.</p>
    </div>
    <a class="seller-secondary-button" href="{{ route('seller.orders') }}">
        <i data-lucide="arrow-left"></i>Manage Orders
    </a>
</div>

<section class="workspace-kpi-strip" aria-label="Returns and refunds summary">
    @foreach ($summary as $item)
        <article><span>{{ $item['label'] }}</span><strong>{{ $item['value'] }}</strong></article>
    @endforeach
</section>

<section class="workspace-card" data-seller-workspace>
    <div class="workspace-toolbar">
        <label class="workspace-search">
            <i data-lucide="search"></i>
            <span class="sr-only">Search return or refund cases</span>
            <input type="search" placeholder="Search case, order, or customer" data-workspace-search>
        </label>
        <button type="button" data-workspace-demo="Return filters opened."><i data-lucide="list-filter"></i>Filters</button>
        <button type="button" data-workspace-demo="Return and refund report exported."><i data-lucide="download"></i>Export</button>
    </div>

    <div class="workspace-table-wrap">
        <table class="workspace-table">
            <thead>
                <tr>
                    <th>Case</th><th>Order</th><th>Customer</th><th>Request</th><th>Reason</th>
                    <th>Amount</th><th>Submitted</th><th>Status</th><th>Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($returns as $case)
                    <tr data-workspace-row data-search="{{ strtolower(implode(' ', $case)) }}">
                        <td><strong>{{ $case['id'] }}</strong></td>
                        <td>{{ $case['order'] }}</td>
                        <td>{{ $case['customer'] }}</td>
                        <td>{{ $case['request'] }}</td>
                        <td>{{ $case['reason'] }}</td>
                        <td><strong>{{ $case['amount'] }}</strong></td>
                        <td>{{ $case['submitted'] }}</td>
                        <td><span class="workspace-status">{{ $case['status'] }}</span></td>
                        <td><button class="workspace-row-action" type="button" data-workspace-demo="{{ $case['action'] }} opened for {{ $case['id'] }}.">{{ $case['action'] }}</button></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="workspace-empty" data-workspace-empty hidden>
            <i data-lucide="search-x"></i><strong>No matching cases</strong><span>Try another case number, order, or customer.</span>
        </div>
    </div>

    <footer class="workspace-footer">
        <span>Showing <strong data-workspace-count>{{ count($returns) }}</strong> return and refund cases</span>
        <span>Frontend preview data</span>
    </footer>
</section>
@endsection

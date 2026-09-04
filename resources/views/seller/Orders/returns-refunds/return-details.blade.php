@extends('layouts.seller')
@section('title', $case['id'].' · Return Case')
@section('page-title', 'Return Case Details')
@section('content')
<nav class="case-breadcrumb" aria-label="Breadcrumb"><a href="{{ route('seller.orders.returns') }}">Returns & Refunds</a><i data-lucide="chevron-right"></i><span>{{ $case['id'] }}</span></nav>
<div class="page-heading case-page-heading">
    <div><span class="section-kicker">Return and refund case</span><div class="case-title-row"><h2>{{ $case['id'] }}</h2><span class="return-status status-{{ $case['tone'] }}">{{ $case['status'] }}</span></div><p>{{ $case['request'] }} for order {{ $case['order'] }} · Submitted {{ $case['submitted'] }}</p></div>
    <a class="seller-secondary-button" href="{{ route('seller.orders.returns') }}"><i data-lucide="arrow-left"></i>Back to cases</a>
</div>
@switch($case['status_key'])
    @case('action-required') @include('seller.Orders.returns-refunds.return-actions.action-required') @break
    @case('under-review') @include('seller.Orders.returns-refunds.return-actions.under-review') @break
    @case('return-shipping') @include('seller.Orders.returns-refunds.return-actions.return-shipping') @break
    @case('resolved') @include('seller.Orders.returns-refunds.return-actions.resolved') @break
@endswitch
<div class="seller-modal case-confirm-modal" data-modal="case-confirm" hidden>
    <button class="modal-backdrop" type="button" data-modal-close aria-label="Close confirmation"></button>
    <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="case-confirm-title">
        <div class="modal-heading"><div><span class="section-kicker">Confirm decision</span><h3 id="case-confirm-title" data-case-confirm-title>Confirm action</h3></div><button type="button" data-modal-close aria-label="Close"><i data-lucide="x"></i></button></div>
        <div class="case-confirm-content"><span><i data-lucide="circle-help"></i></span><p data-case-confirm-message>Please confirm this case action.</p></div>
        <div class="modal-actions"><button class="draft-button" type="button" data-modal-close>Cancel</button><button class="seller-primary-button" type="button" data-case-confirm>Confirm</button></div>
    </section>
</div>
@endsection

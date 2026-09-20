@extends('layouts.seller')
@section('title', 'Customer Feedback')
@section('page-title', 'Customer Service')
@section('content')
<section class="customer-feedback-page" data-feedback-workspace>
    <header class="customer-service-heading"><div><span class="section-kicker">Post-delivery reviews</span><h2>Customer Feedback</h2><p>Review product ratings and respond to buyers after completed orders.</p></div></header>
    <section class="feedback-overview">
        <article class="feedback-score"><span>Store rating</span><strong>{{ $summary['rating'] }}</strong><div aria-label="{{ $summary['rating'] }} out of 5 stars">★★★★★</div><small>Based on {{ $summary['total'] }} verified reviews</small></article>
        <article class="feedback-distribution">@foreach ($distribution as $stars => $count)<div><span>{{ $stars }} <i data-lucide="star"></i></span><b><i style="width:{{ round(($count / $summary['total']) * 100) }}%"></i></b><strong>{{ $count }}</strong></div>@endforeach</article>
        <article class="feedback-response-summary"><span>Seller response</span><strong>{{ $summary['response_rate'] }}</strong><small>Response rate</small><p><b>{{ $summary['new'] }}</b> new reviews are waiting for a reply.</p></article>
    </section>
    <section class="feedback-content">
        <div class="feedback-toolbar"><div class="feedback-tabs" role="tablist"><button class="is-active" type="button" data-feedback-filter="all">All Reviews <b>{{ $summary['total'] }}</b></button><button type="button" data-feedback-filter="new">Needs Reply <b>{{ $summary['new'] }}</b></button><button type="button" data-feedback-filter="replied">Replied</button></div><label><i data-lucide="search"></i><input type="search" placeholder="Search customer, order, or product" data-feedback-search></label></div>
        <div class="feedback-review-list">
            @foreach ($reviews as $review)
                <article class="feedback-review" data-feedback-review data-status="{{ $review['status'] }}" data-search="{{ strtolower($review['customer'].' '.$review['order'].' '.$review['product'].' '.$review['comment']) }}">
                    <header><span class="feedback-review-avatar">{{ $review['initials'] }}</span><div><strong>{{ $review['customer'] }}</strong><span>@for ($star = 1; $star <= 5; $star++)<i data-lucide="star" class="{{ $star <= $review['rating'] ? 'is-filled' : '' }}"></i>@endfor</span><small>{{ $review['date'] }}</small></div><span class="feedback-review-status {{ $review['status'] === 'replied' ? 'is-replied' : '' }}">{{ $review['status'] === 'replied' ? 'Replied' : 'Needs reply' }}</span></header>
                    <div class="feedback-review-context"><span><i data-lucide="package"></i></span><div><strong>{{ $review['product'] }}</strong><small>{{ $review['variant'] }} · {{ $review['order'] }}</small></div><a href="{{ route('seller.orders') }}">View order</a></div>
                    <blockquote>{{ $review['comment'] }}</blockquote>
                    @if ($review['response'])<div class="seller-feedback-response"><span>Seller response</span><p>{{ $review['response'] }}</p></div>@endif
                    <div class="feedback-reply-area" data-feedback-reply-area {{ $review['response'] ? 'hidden' : '' }}><button type="button" data-feedback-reply><i data-lucide="reply"></i>Reply to review</button><form data-feedback-form hidden><textarea rows="3" maxlength="400" placeholder="Write a professional response to this buyer…"></textarea><div><small>Public response · 400 characters maximum</small><button type="button" data-feedback-cancel>Cancel</button><button type="submit">Post reply</button></div></form></div>
                </article>
            @endforeach
            <div class="feedback-empty" data-feedback-empty hidden><i data-lucide="message-square-off"></i><strong>No matching feedback</strong><span>Try another filter or search.</span></div>
        </div>
    </section>
</section>
@endsection

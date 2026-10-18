<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $case->dispute_no }} · Bearly</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
    @include('buyer.partials.account-context-script')
    @vite(['resources/css/buyer.css', 'resources/css/checkout.css', 'resources/css/buyer-dispute.css'])
</head>
<body class="bh buyer-dispute-page">
@include('buyer.partials.buyer-header', [
    'headerClass' => 'checkout-header',
    'headerSearchInputId' => 'dispute-search-input',
    'headerSearchAction' => route('products.index'),
])

<main class="buyer-dispute-shell">
    <a class="checkout-back" href="{{ route('buyer.profile') }}#purchases"><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>My Purchases</a>

    @if (session('success'))<p class="buyer-dispute-alert is-success" role="status">{{ session('success') }}</p>@endif

    <header class="buyer-dispute-heading">
        <div class="buyer-dispute-heading-row">
            <div><span class="section-kicker">{{ $case->dispute_no }}</span><h1>{{ $case->subject }}</h1></div>
            <span class="buyer-dispute-status">{{ str($case->status)->replace('_', ' ')->title() }}</span>
        </div>
        <p>Submitted {{ $case->opened_at?->format('M j, Y · g:i A') ?? 'Date unavailable' }}. Admin notes are private and are not shown here.</p>
    </header>

    <section class="buyer-dispute-order-summary" aria-label="Dispute context">
        <div><span>Seller</span><strong>{{ $case->sellerOrder?->store?->name ?? 'Seller' }}</strong></div>
        <div><span>Seller Order</span><strong>{{ $case->sellerOrder?->seller_order_no ?? 'Unavailable' }}</strong></div>
        <div><span>Order value</span><strong>{{ $case->amount_minor !== null ? '₱'.number_format(((int) $case->amount_minor) / 100, 2) : '—' }}</strong></div>
        <div><span>Opened by</span><strong>{{ $case->opener?->name ?? 'Buyer' }}</strong></div>
    </section>

    <div class="buyer-dispute-show-grid">
        <section class="buyer-dispute-card">
            <div class="buyer-dispute-section-heading"><span class="material-symbols-outlined" aria-hidden="true">description</span><div><h2>Case details</h2><p>Your original complaint remains part of the case history.</p></div></div>
            <p class="buyer-dispute-description">{{ $case->description }}</p>
        </section>

        <section class="buyer-dispute-card">
            <div class="buyer-dispute-section-heading"><span class="material-symbols-outlined" aria-hidden="true">attach_file</span><div><h2>Evidence</h2><p>Files are private to case participants and Admin.</p></div></div>
            @if ($case->evidence->isNotEmpty())
                <ul class="buyer-dispute-evidence-list">
                    @foreach ($case->evidence as $evidence)
                        <li><span><strong>{{ $evidence->original_name }}</strong><small>Uploaded {{ $evidence->created_at?->format('M j, Y · g:i A') ?? 'recently' }}</small></span><a href="{{ route('disputes.evidence.download', $evidence) }}">Download</a></li>
                    @endforeach
                </ul>
            @else
                <p class="buyer-dispute-muted">No evidence was attached to this case.</p>
            @endif
        </section>
    </div>

    <section class="buyer-dispute-card">
        <div class="buyer-dispute-section-heading"><span class="material-symbols-outlined" aria-hidden="true">history</span><div><h2>Case updates</h2><p>Participant-visible updates and final decisions appear here.</p></div></div>
        @if ($visibleEvents->isNotEmpty())
            <div class="buyer-dispute-timeline">
                @foreach ($visibleEvents as $event)
                    <article><span>{{ $event->created_at?->format('M j · g:i A') ?? 'Recent' }}</span><div><strong>{{ str($event->event_type)->replace('_', ' ')->title() }}</strong><p>{{ $event->note ?: 'A case update was recorded.' }}</p></div></article>
                @endforeach
            </div>
        @else
            <p class="buyer-dispute-muted">No participant-visible updates yet.</p>
        @endif
    </section>
</main>
</body>
</html>

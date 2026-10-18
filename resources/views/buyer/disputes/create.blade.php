<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Report a problem · Bearly</title>
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
    <a class="checkout-back" href="{{ route('buyer.profile') }}#purchases">
        <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
        My Purchases
    </a>

    @if ($errors->any())
        <div class="buyer-dispute-alert is-error" role="alert">
            <strong>Please review your submission.</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <header class="buyer-dispute-heading">
        <span class="section-kicker">Seller Order {{ $sellerOrder->seller_order_no }}</span>
        <h1>Report a problem</h1>
        <p>Tell Bearly what happened. An Admin will review the case with the Buyer, Seller, and any connected delivery participants.</p>
    </header>

    <section class="buyer-dispute-order-summary" aria-label="Seller Order summary">
        <div><span>Seller</span><strong>{{ $sellerOrder->store?->name ?? 'Seller' }}</strong></div>
        <div><span>Seller Order</span><strong>{{ $sellerOrder->seller_order_no }}</strong></div>
        <div><span>Order status</span><strong>{{ str($sellerOrder->status)->replace('_', ' ')->title() }}</strong></div>
        <div><span>Order value</span><strong>₱{{ number_format(((int) $sellerOrder->total_minor) / 100, 2) }}</strong></div>
    </section>

    <form class="buyer-dispute-form" method="post" action="{{ route('buyer.disputes.store', $sellerOrder->id) }}" enctype="multipart/form-data">
        @csrf
        <section class="buyer-dispute-card">
            <div class="buyer-dispute-section-heading">
                <span class="material-symbols-outlined" aria-hidden="true">support_agent</span>
                <div><h2>Describe the issue</h2><p>Use specific details that help the Admin review the order and any delivery history.</p></div>
            </div>

            <label for="dispute-subject">Subject
                <input id="dispute-subject" name="subject" type="text" maxlength="180" value="{{ old('subject') }}" required placeholder="Example: Item arrived damaged">
            </label>

            <label for="dispute-description">What happened?
                <textarea id="dispute-description" name="description" rows="7" maxlength="3000" required placeholder="Describe the problem, what you expected, and what you are asking Bearly to review.">{{ old('description') }}</textarea>
                <small>Do not include passwords, payment PINs, or other private credentials.</small>
            </label>

            <label class="buyer-dispute-upload" for="dispute-evidence">
                <span class="material-symbols-outlined" aria-hidden="true">attach_file</span>
                <span><strong>Add supporting evidence (optional)</strong><small>JPG, PNG, WebP, PDF, DOC, DOCX, or TXT · up to 5 files · 10 MB each</small></span>
                <input id="dispute-evidence" type="file" name="evidence[]" accept="image/jpeg,image/png,image/webp,application/pdf,text/plain,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" multiple>
            </label>
        </section>

        <aside class="buyer-dispute-info"><span class="material-symbols-outlined" aria-hidden="true">info</span><p>This is a general dispute intake and does not start a return, refund, replacement, payout, or order-status change. Any administrative outcome is recorded in the case workspace.</p></aside>
        <div class="buyer-dispute-actions"><a class="buyer-dispute-secondary" href="{{ route('buyer.profile') }}#purchases">Cancel</a><button class="buyer-dispute-primary" type="submit">Submit dispute</button></div>
    </form>
</main>
</body>
</html>

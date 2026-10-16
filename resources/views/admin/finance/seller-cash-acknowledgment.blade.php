<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $reference }} | Seller Cash Acknowledgment</title>
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #292421; background: #f7f5f2; font-family: Arial, sans-serif; font-size: 14px; line-height: 1.5; }
        .acknowledgment { width: min(820px, calc(100% - 32px)); margin: 32px auto; padding: 32px; border: 1px solid #e5cfb7; border-radius: 12px; background: #fff; }
        .acknowledgment-header { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding-bottom: 20px; border-bottom: 2px solid #4a2c20; }
        .acknowledgment-header img { display: block; width: 150px; max-height: 48px; object-fit: contain; object-position: left center; }
        .heading { text-align: right; }
        .heading p { margin: 0 0 3px; color: #78695f; font-size: 11px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; }
        .heading h1 { margin: 0; color: #35251e; font-size: 22px; }
        .reference { margin-top: 22px; padding: 16px 18px; border: 1px solid #dfc8b0; border-radius: 8px; background: #fbf7f2; }
        .reference span { display: block; color: #6a625d; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        .reference code { display: block; margin-top: 4px; color: #35251e; font-family: Consolas, monospace; font-size: 20px; font-weight: 700; overflow-wrap: anywhere; }
        .notice { margin: 16px 0 20px; padding: 12px; border-left: 3px solid #c7a77e; color: #5d5148; background: #faf7f4; }
        .notice strong { display: block; margin-bottom: 4px; color: #35251e; }
        .section { margin-top: 22px; }
        .section h2 { margin: 0 0 10px; padding-bottom: 7px; border-bottom: 1px solid #eadfd2; color: #4a2c20; font-size: 13px; letter-spacing: .06em; text-transform: uppercase; }
        .details { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 13px 22px; margin: 0; }
        .details div { min-width: 0; }
        .details dt { color: #756960; font-size: 11px; }
        .details dd { margin: 3px 0 0; color: #292421; font-weight: 600; overflow-wrap: anywhere; }
        .details code { font-family: Consolas, monospace; font-size: 12px; }
        .amount { margin-top: 18px; padding: 16px; border: 1px solid #dfc8b0; border-radius: 8px; text-align: center; }
        .amount span { display: block; color: #6a625d; font-size: 12px; }
        .amount strong { display: block; margin-top: 4px; color: #35251e; font-size: 28px; }
        .statement { margin: 20px 0 10px; }
        .signatures { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 30px; margin-top: 48px; }
        .signature { min-height: 76px; padding-top: 8px; border-top: 1px solid #6a625d; }
        .signature strong, .signature span { display: block; }
        .signature span { margin-top: 3px; color: #756960; font-size: 11px; }
        .footer { margin-top: 28px; padding-top: 12px; border-top: 1px dashed #cfc2b7; color: #756960; font-size: 11px; }
        .actions { display: flex; justify-content: flex-end; gap: 10px; margin: 0 auto 24px; width: min(820px, calc(100% - 32px)); }
        .actions a, .actions button { display: inline-flex; min-height: 40px; align-items: center; justify-content: center; padding: 9px 14px; border: 1px solid #d8c5b1; border-radius: 7px; color: #4a2c20; background: #fff; font: inherit; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .actions button { border-color: #4a2c20; color: #fff; background: #4a2c20; }
        @media (max-width: 560px) {
            .acknowledgment { width: calc(100% - 20px); margin: 10px auto; padding: 18px; }
            .acknowledgment-header { align-items: flex-start; flex-direction: column; }
            .heading { text-align: left; }
            .details, .signatures { grid-template-columns: minmax(0, 1fr); }
            .signatures { gap: 18px; margin-top: 30px; }
            .actions { width: calc(100% - 20px); flex-wrap: wrap; }
        }
        @media print {
            body { background: #fff; font-size: 12px; }
            .acknowledgment { width: 100%; margin: 0; padding: 0; border: 0; border-radius: 0; }
            .actions { display: none; }
            .reference, .notice, .amount { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body>
<main class="acknowledgment" aria-labelledby="acknowledgment-title">
    <header class="acknowledgment-header">
        <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly">
        <div class="heading">
            <p>Seller payout record</p>
            <h1 id="acknowledgment-title">Cash Payout Acknowledgment</h1>
        </div>
    </header>

    <section class="reference" aria-label="Seller cash payout reference">
        <span>Seller cash payout reference</span>
        <code>{{ $reference }}</code>
    </section>

    <p class="notice">
        <strong>Finance/Admin: follow these steps.</strong>
        Print this page. Give the cash directly to the Seller—do not send a Rider. Let the Seller count the money. Ask the Seller to sign only after receiving the full amount. Upload a photo or scan of this signed page when you record the payout. Before it is signed, this page is not proof of payment.
    </p>

    <section class="section" aria-labelledby="seller-details-heading">
        <h2 id="seller-details-heading">Seller and settlement</h2>
        <dl class="details">
            <div><dt>Store</dt><dd>{{ $payout->sellerProfile?->store?->name ?? 'Store not available' }}</dd></div>
            <div><dt>Seller / business</dt><dd>{{ $payout->sellerProfile?->legal_business_name ?: ($payout->sellerProfile?->user?->name ?? 'Seller not available') }}</dd></div>
            <div><dt>Seller payout number</dt><dd><code>{{ $payout->payout_no }}</code></dd></div>
            <div><dt>Coverage period</dt><dd>{{ $payout->period_start->format('M d, Y') }} – {{ $payout->period_end->format('M d, Y') }}</dd></div>
            <div><dt>Printed by</dt><dd>{{ $printedBy }}</dd></div>
            <div><dt>Cash handoff date</dt><dd>____________________________</dd></div>
        </dl>
    </section>

    <div class="amount" aria-label="Exact cash amount to hand to Seller">
        <span>Exact cash amount</span>
        <strong>₱{{ number_format((int) $payout->net_minor / 100, 2) }}</strong>
    </div>

    <p class="statement">I confirm that I received and counted the full cash amount shown above for this Seller payout.</p>

    <section class="signatures" aria-label="Cash payout signatures">
        <div class="signature"><strong>Seller / authorized representative</strong><span>Print name, sign, and write the date after receiving the cash.</span></div>
        <div class="signature"><strong>Finance/Admin who handed over the cash</strong><span>Print name and sign after the handoff.</span></div>
    </section>

    <footer class="footer">
        Bearly uses this signed page as proof of a direct Seller cash payout. This is not a COD cash custody receipt, BIR/tax invoice, or bank deposit receipt. Bearly records the handoff; it does not move the cash.
    </footer>
</main>

<nav class="actions" aria-label="Acknowledgment actions">
    <a href="{{ route('admin.payments') }}">Back to Seller Payments</a>
    <button type="button" onclick="window.print()">Print / save as PDF</button>
</nav>
</body>
</html>

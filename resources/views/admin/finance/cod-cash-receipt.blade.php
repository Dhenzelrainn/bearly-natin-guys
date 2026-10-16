<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $collection->finance_reference }} | Bearly Finance Receipt</title>
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #292421; background: #f7f5f2; font-family: Arial, sans-serif; font-size: 14px; line-height: 1.5; }
        .receipt { width: min(820px, calc(100% - 32px)); margin: 32px auto; padding: 32px; border: 1px solid #e5cfb7; border-radius: 12px; background: #fff; }
        .receipt-header { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding-bottom: 20px; border-bottom: 2px solid #4a2c20; }
        .receipt-header img { display: block; width: 150px; max-height: 48px; object-fit: contain; object-position: left center; }
        .receipt-heading { text-align: right; }
        .receipt-heading p { margin: 0 0 3px; color: #78695f; font-size: 11px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; }
        .receipt-heading h1 { margin: 0; color: #35251e; font-size: 22px; }
        .receipt-number { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-top: 22px; padding: 16px 18px; border: 1px solid #dfc8b0; border-radius: 8px; background: #fbf7f2; }
        .receipt-number span { display: block; color: #6a625d; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        .receipt-number code { display: block; margin-top: 4px; color: #35251e; font-family: Consolas, monospace; font-size: 20px; font-weight: 700; overflow-wrap: anywhere; }
        .receipt-status { flex: 0 0 auto; padding: 6px 10px; border-radius: 5px; color: #356747; background: #edf8ef; font-size: 11px; font-weight: 700; letter-spacing: .06em; }
        .receipt-notice { margin: 16px 0 20px; padding: 10px 12px; border-left: 3px solid #c7a77e; color: #5d5148; background: #faf7f4; font-size: 12px; }
        .receipt-section { margin-top: 22px; }
        .receipt-section h2 { margin: 0 0 10px; padding-bottom: 7px; border-bottom: 1px solid #eadfd2; color: #4a2c20; font-size: 13px; letter-spacing: .06em; text-transform: uppercase; }
        .receipt-details { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 13px 22px; margin: 0; }
        .receipt-details div { min-width: 0; }
        .receipt-details dt { color: #756960; font-size: 11px; }
        .receipt-details dd { margin: 3px 0 0; color: #292421; font-weight: 600; overflow-wrap: anywhere; }
        .receipt-details code { font-family: Consolas, monospace; font-size: 12px; }
        .receipt-amounts { width: 100%; border-collapse: collapse; }
        .receipt-amounts th, .receipt-amounts td { padding: 9px 10px; border-bottom: 1px solid #eee7e0; text-align: left; }
        .receipt-amounts th { color: #62564e; font-size: 12px; font-weight: 600; }
        .receipt-amounts td { color: #292421; font-variant-numeric: tabular-nums; font-weight: 600; text-align: right; white-space: nowrap; }
        .receipt-amounts tr:last-child th, .receipt-amounts tr:last-child td { border-top: 2px solid #4a2c20; border-bottom: 0; color: #35251e; font-size: 15px; font-weight: 700; }
        .receipt-footer { margin-top: 28px; padding-top: 12px; border-top: 1px dashed #cfc2b7; color: #756960; font-size: 11px; }
        .receipt-actions { display: flex; justify-content: flex-end; gap: 10px; margin: 0 auto 24px; width: min(820px, calc(100% - 32px)); }
        .receipt-actions a, .receipt-actions button { display: inline-flex; min-height: 40px; align-items: center; justify-content: center; padding: 9px 14px; border: 1px solid #d8c5b1; border-radius: 7px; color: #4a2c20; background: #fff; font: inherit; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .receipt-actions button { border-color: #4a2c20; color: #fff; background: #4a2c20; }
        @media (max-width: 560px) {
            .receipt { width: calc(100% - 20px); margin: 10px auto; padding: 18px; }
            .receipt-header { align-items: flex-start; flex-direction: column; }
            .receipt-heading { text-align: left; }
            .receipt-number { align-items: flex-start; flex-direction: column; }
            .receipt-details { grid-template-columns: minmax(0, 1fr); }
            .receipt-actions { width: calc(100% - 20px); flex-wrap: wrap; }
        }
        @media print {
            body { background: #fff; font-size: 12px; }
            .receipt { width: 100%; margin: 0; padding: 0; border: 0; border-radius: 0; }
            .receipt-actions { display: none; }
            .receipt-number, .receipt-notice { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body>
@php $isBearlyReceipt = str_starts_with((string) $collection->finance_reference, 'BFR-'); @endphp
<main class="receipt" aria-labelledby="receipt-title">
    <header class="receipt-header">
        <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly">
        <div class="receipt-heading">
            <p>{{ $isBearlyReceipt ? 'Internal Finance Record' : 'Historical Finance Record' }}</p>
            <h1 id="receipt-title">{{ $isBearlyReceipt ? 'COD Cash Receipt' : 'COD Reconciliation Record' }}</h1>
        </div>
    </header>

    <section class="receipt-number" aria-label="Finance receipt reference">
        <div>
            <span>{{ $isBearlyReceipt ? 'Bearly Finance receipt number' : 'Previously recorded Finance reference' }}</span>
            <code>{{ $collection->finance_reference }}</code>
        </div>
        <strong class="receipt-status">RECONCILED</strong>
    </section>

    <p class="receipt-notice">
        @if($isBearlyReceipt)
            Internal Bearly record of physical COD cash received and confirmed by Finance. This is not a BIR/tax invoice or a bank deposit receipt.
        @else
            Printed copy of a historical Bearly COD reconciliation. The Finance reference was recorded under the earlier workflow and was not issued by Bearly; this is not a tax invoice or bank deposit receipt.
        @endif
    </p>

    <section class="receipt-section" aria-labelledby="receipt-details-heading">
        <h2 id="receipt-details-heading">Receipt details</h2>
        <dl class="receipt-details">
            <div><dt>Finance confirmed on</dt><dd>{{ $collection->reconciled_at?->format('M d, Y h:i A') ?? 'Not recorded' }}</dd></div>
            <div><dt>Confirmed by</dt><dd>{{ $collection->financeReconciler?->name ?? 'Admin Finance' }}</dd></div>
            <div><dt>Collection number</dt><dd><code>{{ $collection->collection_no }}</code></dd></div>
            <div><dt>Marketplace order</dt><dd>{{ $collection->order?->order_no ?? 'Not available' }}</dd></div>
            <div><dt>Seller order / Store</dt><dd>{{ $collection->sellerOrder?->seller_order_no ?? 'Not available' }} · {{ $collection->sellerOrder?->store?->name ?? 'Store unavailable' }}</dd></div>
            <div><dt>Logistics provider</dt><dd>{{ $collection->logisticsProfile?->display_name ?? 'Not available' }}</dd></div>
            <div><dt>Delivery Rider</dt><dd>{{ $collection->riderProfile?->user?->name ?? 'Not available' }}</dd></div>
        </dl>
    </section>

    <section class="receipt-section" aria-labelledby="custody-heading">
        <h2 id="custody-heading">Cash custody references</h2>
        <dl class="receipt-details">
            <div><dt>Rider → Logistics</dt><dd><code>{{ $collection->logistics_reference ?? 'Not recorded' }}</code></dd></div>
            <div><dt>Logistics → Finance</dt><dd><code>{{ $collection->finance_remittance_reference ?? 'Not recorded' }}</code></dd></div>
        </dl>
    </section>

    <section class="receipt-section" aria-labelledby="cash-summary-heading">
        <h2 id="cash-summary-heading">Cash summary</h2>
        <table class="receipt-amounts">
            <tbody>
                <tr><th scope="row">COD amount due</th><td>₱{{ number_format((int) $collection->amount_due_minor / 100, 2) }}</td></tr>
                <tr><th scope="row">Cash recorded by Rider</th><td>₱{{ number_format((int) $collection->amount_collected_minor / 100, 2) }}</td></tr>
                <tr><th scope="row">Cash acknowledged by Logistics</th><td>₱{{ number_format((int) $collection->amount_received_by_logistics_minor / 100, 2) }}</td></tr>
                <tr><th scope="row">Cash remitted by Logistics</th><td>₱{{ number_format((int) $collection->amount_remitted_to_finance_minor / 100, 2) }}</td></tr>
                <tr><th scope="row">Cash physically received by Finance</th><td>₱{{ number_format((int) $collection->amount_reconciled_minor / 100, 2) }}</td></tr>
            </tbody>
        </table>
    </section>

    <footer class="receipt-footer">
        Bearly records the confirmation made by the signed-in Finance/Admin user. This printout does not itself transfer funds or confirm a bank deposit.
    </footer>
</main>

<nav class="receipt-actions" aria-label="Receipt actions">
    <a href="{{ route('admin.payments') }}">Back to Seller Payments</a>
    <button type="button" onclick="window.print()">Print / save as PDF</button>
</nav>
</body>
</html>

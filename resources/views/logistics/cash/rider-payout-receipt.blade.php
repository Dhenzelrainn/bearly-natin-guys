<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $payout->reference }} | Bearly Rider Cash Receipt</title>
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
        .receipt-number code { display: block; margin-top: 4px; color: #35251e; font-family: Consolas, monospace; font-size: 19px; font-weight: 700; overflow-wrap: anywhere; }
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
        .receipt-signatures { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 36px; margin-top: 36px; }
        .receipt-signature { min-width: 0; padding-top: 34px; border-bottom: 1px solid #756960; }
        .receipt-signature strong { display: block; margin-top: 8px; color: #35251e; font-size: 12px; }
        .receipt-signature small { display: block; color: #756960; font-size: 10px; }
        .receipt-footer { margin-top: 28px; padding-top: 12px; border-top: 1px dashed #cfc2b7; color: #756960; font-size: 11px; }
        .receipt-actions { display: flex; justify-content: flex-end; gap: 10px; margin: 0 auto 24px; width: min(820px, calc(100% - 32px)); }
        .receipt-actions a, .receipt-actions button { display: inline-flex; min-height: 40px; align-items: center; justify-content: center; padding: 9px 14px; border: 1px solid #d8c5b1; border-radius: 7px; color: #4a2c20; background: #fff; font: inherit; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .receipt-actions button { border-color: #4a2c20; color: #fff; background: #4a2c20; }
        @media (max-width: 560px) {
            .receipt { width: calc(100% - 20px); margin: 10px auto; padding: 18px; }
            .receipt-header { align-items: flex-start; flex-direction: column; }
            .receipt-heading { text-align: left; }
            .receipt-number { align-items: flex-start; flex-direction: column; }
            .receipt-details, .receipt-signatures { grid-template-columns: minmax(0, 1fr); }
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
<main class="receipt" aria-labelledby="receipt-title">
    <header class="receipt-header">
        <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly">
        <div class="receipt-heading">
            <p>Internal Logistics Record</p>
            <h1 id="receipt-title">Rider cash payout receipt</h1>
        </div>
    </header>

    <section class="receipt-number" aria-label="Cash payout reference">
        <div>
            <span>Bearly Rider cash payout reference</span>
            <code>{{ $payout->reference }}</code>
        </div>
        <strong class="receipt-status">CASH PAID</strong>
    </section>

    <p class="receipt-notice">Internal Bearly record for a cash payout that Logistics confirmed was physically handed to the Rider. This reference is not a bank or GCash transaction number.</p>

    <section class="receipt-section" aria-labelledby="receipt-details-heading">
        <h2 id="receipt-details-heading">Payout details</h2>
        <dl class="receipt-details">
            <div><dt>Shipping settlement</dt><dd><code>{{ $settlement?->settlement_no ?? 'Not available' }}</code></dd></div>
            <div><dt>Shipment</dt><dd><code>{{ $settlement?->shipment?->shipment_no ?? 'Not available' }}</code></dd></div>
            <div><dt>{{ $settlement?->settlement_type === 'return' ? 'Return case' : 'Seller Order' }}</dt><dd>{{ $settlement?->settlement_type === 'return' ? ($settlement?->returnRequest?->return_no ?? 'Not available') : ($settlement?->sellerOrder?->seller_order_no ?? 'Not available') }}</dd></div>
            <div><dt>Marketplace order</dt><dd>{{ $settlement?->settlement_type === 'return' ? ($settlement?->returnRequest?->order?->order_no ?? 'Not available') : ($settlement?->sellerOrder?->order?->order_no ?? 'Not available') }}</dd></div>
            <div><dt>Logistics provider</dt><dd>{{ $payout->logisticsProfile?->display_name ?? 'Not available' }}</dd></div>
            <div><dt>Rider / recipient</dt><dd>{{ $riderName }}</dd></div>
            <div><dt>Paid on</dt><dd>{{ $payout->paid_on?->format('M d, Y') ?? 'Not available' }}</dd></div>
            <div><dt>Recorded by</dt><dd>{{ $payout->recorder?->name ?? 'Logistics' }}</dd></div>
        </dl>
    </section>

    <section class="receipt-section" aria-labelledby="cash-summary-heading">
        <h2 id="cash-summary-heading">Cash payout</h2>
        <table class="receipt-amounts">
            <tbody>
                <tr><th scope="row">Rider amount physically paid</th><td>₱{{ number_format((int) $payout->amount_minor / 100, 2) }}</td></tr>
            </tbody>
        </table>
        <p class="receipt-notice">Reason / note: {{ $payout->reason }}</p>
    </section>

    <section class="receipt-signatures" aria-label="Cash payout signatures">
        <div class="receipt-signature"><small>Logistics — paid by</small><strong>{{ $payout->recorder?->name ?? 'Logistics representative' }}</strong><small>Signature and date</small></div>
        <div class="receipt-signature"><small>Rider — cash received by</small><strong>{{ $riderName }}</strong><small>Signature and date</small></div>
    </section>

    <footer class="receipt-footer">Bearly records the confirmation made by the Logistics user. Print only after the exact cash amount has physically been handed to the Rider.</footer>
</main>

<nav class="receipt-actions" aria-label="Receipt actions">
    <a href="{{ route('logistics.settlements.index') }}">Back to Shipping settlement</a>
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
</nav>
</body>
</html>

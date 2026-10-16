<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Bearly Waybill</title>
    <style>
        @page { size: 4in 6in; margin: 0.1in; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #211912; font-family: "DejaVu Sans", sans-serif; font-size: 7.5pt; }
        .label-page { position: relative; width: 100%; height: 5.76in; page-break-inside: avoid; }
        .brand { display: table; width: 100%; height: 36pt; padding-bottom: 4pt; border-bottom: 1.5pt solid #35251e; }
        .brand-name { display: table-cell; width: 62%; vertical-align: middle; }
        .brand-name img { display: block; width: 112pt; max-height: 38pt; height: auto; }
        .brand-wordmark { color: #321b10; font-size: 25pt; font-weight: bold; letter-spacing: -1.2pt; }
        .brand-side { display: table-cell; color: #35251e; text-align: right; vertical-align: middle; font-size: 7pt; line-height: 1.45; text-transform: uppercase; }
        .brand-side strong { font-size: 8pt; }
        .tracking-card { margin-top: 5pt; padding: 5pt 8pt 4pt; border-radius: 9pt; background: #f7f1eb; text-align: center; }
        .tracking-label { color: #5e4030; font-size: 7pt; font-weight: bold; letter-spacing: 1pt; text-transform: uppercase; }
        .tracking { margin-top: 2pt; color: #321b10; font-size: 13pt; font-weight: bold; letter-spacing: .3pt; }
        .barcode { height: 36pt; margin: 3pt 0 1pt; padding: 0 4pt; overflow: hidden; background: #fff; }
        .barcode > div { margin: 0 auto; }
        .barcode-human { color: #4b4037; font-size: 6.7pt; letter-spacing: 1pt; }
        .section { margin-top: 5pt; overflow: hidden; border: 1pt solid #d8c5b1; border-radius: 7pt; }
        .section-heading { display: table; width: 100%; min-height: 16pt; padding: 2pt 6pt; color: #4b2c1c; background: #f5eee7; }
        .section-icon { display: table-cell; width: 17pt; vertical-align: middle; }
        .section-icon svg { display: block; width: 11pt; height: 11pt; fill: none; stroke: #6f4a31; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .section-label { display: table-cell; vertical-align: middle; font-size: 6.5pt; font-weight: bold; letter-spacing: .8pt; text-transform: uppercase; }
        .section-body { padding: 3pt 7pt 4pt 23pt; }
        .recipient { color: #211912; font-size: 8.8pt; font-weight: bold; }
        .address { margin-top: 1pt; color: #302923; font-size: 6.9pt; line-height: 1.2; overflow-wrap: anywhere; }
        .contact { margin-top: 2pt; color: #4b4037; font-size: 6.4pt; }
        .sender { margin-top: 4pt; }
        .sender .section-body { line-height: 1.2; }
        .store-name { color: #211912; font-size: 8.2pt; font-weight: bold; }
        .meta { display: table; width: 100%; margin-top: 5pt; border-collapse: collapse; }
        .meta-cell { display: table-cell; width: 50%; padding: 4pt 6pt; border: 1pt solid #d8c5b1; vertical-align: top; }
        .meta-heading { display: table; width: 100%; margin-bottom: 2pt; color: #4b2c1c; }
        .meta-heading svg { display: table-cell; width: 11pt; height: 11pt; margin-right: 4pt; fill: none; stroke: #6f4a31; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; vertical-align: middle; }
        .meta-label { display: table-cell; padding-left: 4pt; color: #6b5b4c; font-size: 6pt; font-weight: bold; letter-spacing: .5pt; text-transform: uppercase; vertical-align: middle; }
        .meta-value { margin-top: 1pt; color: #211912; font-size: 7pt; font-weight: bold; }
        .meta-subvalue { margin-top: 1pt; color: #4b4037; font-size: 6pt; }
        .cod { display: table; width: 100%; margin-top: 5pt; padding: 4pt 7pt; border: 1.4pt solid #4b2c1c; border-radius: 7pt; background: #fbf7f2; }
        .cod-icon,.cod-copy { display: table-cell; vertical-align: middle; }
        .cod-icon { width: 22pt; }
        .cod-icon svg { display: block; width: 15pt; height: 15pt; fill: none; stroke: #6f4a31; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .cod-label { color: #5e4030; font-size: 6.5pt; font-weight: bold; letter-spacing: .6pt; text-transform: uppercase; }
        .cod-amount { margin-top: 1pt; color: #321b10; font-size: 14pt; font-weight: bold; }
        .cod-none { margin-top: 2pt; color: #321b10; font-size: 9pt; font-weight: bold; }
        .footer { display: table; width: 100%; height: 21pt; margin-top: 6pt; padding-top: 3pt; border-top: 1pt dashed #b9aa9c; color: #5e5146; font-size: 5.7pt; }
        .footer-logo,.footer-message,.footer-piece { display: table-cell; vertical-align: middle; }
        .footer-logo { width: 23%; }
        .footer-logo img { display: block; width: 55pt; height: auto; }
        .footer-message { padding-left: 7pt; border-left: 1pt solid #b9aa9c; }
        .footer-piece { width: 27%; border-left: 1pt solid #b9aa9c; text-align: right; font-weight: bold; }
    </style>
</head>
<body>
@foreach ($labels as $label)
            <main class="label-page" style="page-break-after: {{ $loop->last ? 'auto' : 'always' }}">
        <header class="brand">
            <div class="brand-name">
                @if ($label['logo_data_uri'])
                    <img src="{{ $label['logo_data_uri'] }}" alt="Bearly">
                @else
                    <span class="brand-wordmark">bearly</span>
                @endif
            </div>
            <div class="brand-side"><strong>Bearly Logistics</strong><br>Outbound Shipment</div>
        </header>

        <section class="tracking-card" aria-label="Waybill barcode">
            <div class="tracking-label">Waybill / Tracking Number</div>
            <div class="tracking">{{ $label['waybill_no'] }}</div>
            <div class="barcode">{!! $label['barcode_html'] !!}</div>
            <div class="barcode-human">{{ $label['barcode_value'] }}</div>
        </section>

        <section class="section">
            <div class="section-heading">
                <span class="section-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg></span>
                <span class="section-label">Ship to</span>
            </div>
            <div class="section-body">
                <div class="recipient">{{ $label['recipient_name'] }}</div>
                <div class="address">{{ $label['recipient_address'] }}</div>
                <div class="contact">Contact: {{ $label['recipient_phone'] }}</div>
            </div>
        </section>

        <section class="section sender">
            <div class="section-heading">
                <span class="section-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 10 9-7 9 7"/><path d="M5 9v11h14V9M9 20v-7h6v7"/></svg></span>
                <span class="section-label">From / Return Address</span>
            </div>
            <div class="section-body">
                <div class="store-name">{{ $label['store_name'] }}</div>
                <div class="address">{{ $label['sender_address'] }}</div>
                <div class="contact">Contact: {{ $label['sender_phone'] }}</div>
            </div>
        </section>

        <div class="meta">
            <div class="meta-cell">
                <div class="meta-heading">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="M3 8v9l9 5 9-5V8M12 13v9M7.5 5.5l9 5"/></svg>
                    <span class="meta-label">Package</span>
                </div>
                <div class="meta-value">{{ $label['piece_count'] }} {{ $label['piece_count'] === 1 ? 'piece' : 'pieces' }} · {{ $label['weight'] }} kg</div>
                <div class="meta-subvalue">{{ $label['dimensions'] }} cm</div>
            </div>
            <div class="meta-cell">
                <div class="meta-heading">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 12h7M9 16h7"/></svg>
                    <span class="meta-label">Seller Order</span>
                </div>
                <div class="meta-value">{{ $label['seller_order_no'] }}</div>
                <div class="meta-subvalue">Marketplace {{ $label['order_no'] }}</div>
            </div>
        </div>

        <section class="cod">
            <span class="cod-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="18" height="14" rx="2"/><path d="M3 10h18M16 15h2M7 4h11"/></svg></span>
            <div class="cod-copy">
                <div class="cod-label">COD amount to collect</div>
            @if ($label['cod_amount'])
                <div class="cod-amount">{{ $label['cod_amount'] }}</div>
            @else
                <div class="cod-none">No COD collection</div>
            @endif
            </div>
        </section>

        <footer class="footer">
            <span class="footer-logo">
                @if ($label['logo_data_uri'])<img src="{{ $label['logo_data_uri'] }}" alt="">@else Bearly @endif
            </span>
            <span class="footer-message">Thank you for shipping with Bearly!<br>Generated {{ $label['generated_at'] }}</span>
            <span class="footer-piece">Piece 1 of {{ $label['piece_count'] }}</span>
        </footer>
    </main>
@endforeach
</body>
</html>

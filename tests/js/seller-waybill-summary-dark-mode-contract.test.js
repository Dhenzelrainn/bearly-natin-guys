import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const sellerLegibility = await readFile(
    new URL('../../resources/css/seller-legibility.css', import.meta.url),
    'utf8',
);
const sellerStyles = await readFile(
    new URL('../../resources/css/seller.css', import.meta.url),
    'utf8',
);
const waybillView = await readFile(
    new URL('../../resources/views/seller/fulfillment/waybills.blade.php', import.meta.url),
    'utf8',
);

test('Seller Waybill keeps aligned visible field headings on the light surface in both themes', () => {
    const darkModeRules = sellerLegibility.slice(
        sellerLegibility.indexOf('/* Seller Waybills:'),
    );

    assert.match(
        sellerStyles,
        /\.waybill-record\s*\{[^}]*border:\s*1px solid #b78c61;[^}]*background:#fff;/s,
    );
    assert.match(
        sellerStyles,
        /\.waybill-order-overview\s*\{[^}]*border:0;[^}]*background:transparent;/s,
    );
    assert.match(
        sellerStyles,
        /\.waybill-record-details\s*\{[^}]*border-top:1px solid #e7dfd6;[^}]*background:#fff;/s,
    );
    assert.match(
        sellerStyles,
        /\.waybill-detail-grid\s*\{[^}]*grid-template-columns:minmax\(0,1\.5fr\) minmax\(0,1\.2fr\) minmax\(0,1\.15fr\) minmax\(0,\.95fr\) minmax\(0,1\.3fr\) minmax\(0,\.95fr\);[^}]*align-items:stretch;/s,
    );
    assert.match(
        sellerStyles,
        /\.waybill-detail-grid > div\s*\{[^}]*grid-template-rows:minmax\(2\.6em,auto\) minmax\(64px,1fr\);/s,
    );
    assert.match(
        sellerStyles,
        /\.waybill-detail-grid dd\s*\{[^}]*min-height:64px;[^}]*align-content:center;/s,
    );
    assert.match(
        sellerStyles,
        /\.waybill-cell-with-icon > svg\s*\{[^}]*color:#a65e00;/s,
    );
    assert.match(
        waybillView,
        /<dt>Waybill Number<\/dt>/,
    );
    assert.match(
        waybillView,
        /'waybill-label-status'/,
    );
    assert.match(
        waybillView,
        /<dt>Label Status<\/dt>/,
    );
    assert.match(
        waybillView,
        /<span class="waybill-detail-heading waybill-actions-heading">Actions<\/span>/,
    );
    assert.match(
        darkModeRules,
        /\.waybill-record, \.waybill-recipient-card\s*\)\s*\{[^}]*background-color:\s*var\(--seller-surface\);/s,
    );
    assert.match(
        darkModeRules,
        /\.waybill-record-details\s*\{[^}]*background-color:\s*var\(--seller-surface\);/s,
    );
    assert.match(
        darkModeRules,
        /\.waybill-workspace \.fulfillment-tabs a:is\([\s\S]*?:focus-visible, \.is-active[\s\S]*?\)\s*\{[^}]*color:\s*#fff8ef;/s,
    );
    assert.match(
        darkModeRules,
        /\.waybill-workspace \.fulfillment-tabs a:hover\s*\{[^}]*color:\s*#efbd71;/s,
    );
    assert.match(
        darkModeRules,
        /\.waybill-seller-order-list li, \.waybill-recipient-card\s*\)\s*\{[^}]*background-color:\s*var\(--seller-surface-soft\);/s,
    );
    assert.match(
        darkModeRules,
        /\.waybill-overview-heading h3, \.waybill-overview-section-heading h4,[\s\S]*?\.waybill-recipient-card strong,[\s\S]*?\)\s*\{[^}]*color:\s*var\(--theme-text\);/s,
    );
});

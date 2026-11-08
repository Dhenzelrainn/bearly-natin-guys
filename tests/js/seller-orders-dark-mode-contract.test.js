import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const sellerLegibility = await readFile(
    new URL('../../resources/css/seller-legibility.css', import.meta.url),
    'utf8',
);
const theme = await readFile(
    new URL('../../resources/css/theme.css', import.meta.url),
    'utf8',
);
const sellerStyles = await readFile(
    new URL('../../resources/css/seller.css', import.meta.url),
    'utf8',
);

test('Seller Orders table copy has readable dark-mode colors without overriding statuses', () => {
    assert.match(
        sellerLegibility,
        /:root\[data-theme="dark"\] body\.seller-body \.seller-content \.orders-workspace \.orders-table tbody td\s*\{\s*color:\s*#e7ddd3;/,
    );
    assert.match(
        sellerLegibility,
        /\.orders-table :is\(\s*\.order-customer, \.payment-label, tbody td > strong\s*\)\s*\{\s*color:\s*#fff8ef;/,
    );
    assert.match(
        sellerLegibility,
        /\.orders-table \.order-product-copy \.order-item-mini\s*\{\s*color:\s*#c0b2a5;/,
    );
    assert.match(
        sellerLegibility,
        /\.orders-table :is\(\s*\.deadline-label\.is-urgent, \.deadline-label\.is-urgent small\s*\)\s*\{\s*color:\s*#ffaaa2;/,
    );

    assert.match(theme, /\.order-status-badge\.order-status-prepare,\s*:root\[data-theme="dark"\] body\.seller-body \.order-status-badge\.order-status-completed\s*\{[^}]*color:\s*#a8d5b0;/s);
    assert.match(theme, /\.order-status-badge\.order-status-new,\s*:root\[data-theme="dark"\] body\.seller-body \.order-status-badge\.order-status-pickup\s*\{[^}]*color:\s*#efbd71;/s);
    assert.match(theme, /\.order-status-badge\.order-status-waybill,\s*:root\[data-theme="dark"\] body\.seller-body \.order-status-badge\.order-status-transit\s*\{[^}]*color:\s*#b2dcf4;/s);
    assert.match(theme, /\.order-status-badge\.order-status-cancelled\s*\{[^}]*color:\s*#ffaaa2;/s);
});

test('expanded shipment members are visually highlighted in both seller themes', () => {
    assert.match(
        sellerStyles,
        /\.orders-table-group\.is-expanded > tr\[data-order-row\]\s*\{\s*background-color:\s*#fff9f0;/,
    );
    assert.match(
        sellerLegibility,
        /\.orders-table-group\.is-expanded > tr\[data-order-row\]\s*\{\s*background-color:\s*#30241d;/,
    );
    assert.match(
        sellerLegibility,
        /\.orders-table-group\.is-expanded > tr\[data-order-row\] > td:first-child\s*\{\s*box-shadow:\s*inset 4px 0 0 #d69b43;/,
    );
});

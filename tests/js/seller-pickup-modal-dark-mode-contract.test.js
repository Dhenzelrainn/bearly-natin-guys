import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const sellerLegibility = await readFile(
    new URL('../../resources/css/seller-legibility.css', import.meta.url),
    'utf8',
);

test('Seller pickup modal actions have no brown panel fill in dark mode', () => {
    const rule = sellerLegibility.match(
        /:root\[data-theme="dark"\] body\.seller-body \.seller-content \.pickups-page \.seller-modal \.modal-actions\s*\{[^}]*\}/s,
    )?.[0];

    assert.ok(rule, 'Expected a scoped dark-mode override for the pickup modal action row.');
    assert.match(rule, /background-color:\s*transparent\s*!important;/);
});

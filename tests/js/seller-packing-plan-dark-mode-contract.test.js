import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const sellerLegibility = await readFile(
    new URL('../../resources/css/seller-legibility.css', import.meta.url),
    'utf8',
);

test('Seller Packing Plan dark mode replaces pale panels, parcel headers, and controls', () => {
    const darkModeRules = sellerLegibility.slice(
        sellerLegibility.indexOf('/* Seller Packing Plan:'),
    );

    assert.match(
        darkModeRules,
        /:root\[data-theme="dark"\] body\.seller-body \.seller-content :is\([\s\S]*?\.packing-plan-note,[\s\S]*?\.packing-plan-policy-note[\s\S]*?\)\s*\{[^}]*background-color:\s*var\(--seller-surface-soft\);[^}]*color:\s*var\(--theme-text\);/s,
    );
    assert.match(
        darkModeRules,
        /\.packing-parcel-heading\s*\{[^}]*background-color:\s*var\(--seller-surface-soft\);/s,
    );
    assert.match(
        darkModeRules,
        /\.packing-plan-tool:not\(\.is-primary\)\s*\{[^}]*background-color:\s*var\(--seller-surface-raised\);[^}]*color:\s*var\(--theme-text\);/s,
    );
    assert.match(
        darkModeRules,
        /\.packing-plan-errors\s*\{[^}]*background-color:\s*#3b2522;[^}]*color:\s*#ffaaa2;/s,
    );
});

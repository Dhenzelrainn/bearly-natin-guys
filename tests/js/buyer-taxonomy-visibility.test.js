import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const buyerCss = await readFile(new URL('../../resources/css/buyer.css', import.meta.url), 'utf8');
const buyerJs = await readFile(new URL('../../resources/js/buyer.js', import.meta.url), 'utf8');

test('live category CSS keeps canonical Marketplace subcategory navigation visible', () => {
    assert.match(
        buyerCss,
        /body\.bc\[data-live-catalog="true"\]:not\(\[data-canonical-marketplace-taxonomy="true"\]\) \.subcategories/,
    );
    assert.match(
        buyerCss,
        /body\.bc:not\(\[data-canonical-marketplace-taxonomy="true"\]\) \.shortcuts\{display:none!important\}/,
    );
    assert.doesNotMatch(buyerCss, /body\.bc\[data-live-catalog="true"\] \.subcategories\s*,/);
});

test('canonical category pages show guidance when no visible subcategory is available', () => {
    assert.match(buyerJs, /canonicalLiveTaxonomy && !document\.querySelector\('\[data-subcategory-id\]'\)/);
    assert.match(buyerJs, /No active canonical subcategories have orderable listings assigned yet/);
});

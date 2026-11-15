import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const discountForm = await readFile(
    new URL('../../resources/views/seller/Products/product-discount-form.blade.php', import.meta.url),
    'utf8',
);
const sellerLegibility = await readFile(
    new URL('../../resources/css/seller-legibility.css', import.meta.url),
    'utf8',
);

test('Seller product discount controls have a dark-mode fallback', () => {
    for (const source of [discountForm, sellerLegibility]) {
        assert.match(source, /promotion-input-shell/);
        assert.match(source, /promotion-input-suffix/);
        assert.match(source, /promotion-form-schedule-note/);
        assert.match(source, /promotion-scope-note-compact/);
        assert.match(source, /input\[type="date"\]/);
        assert.match(source, /input\[type="time"\]/);
    }

    assert.match(discountForm, /data-selected-product-count/);
    assert.match(discountForm, /color-scheme: dark/);
});

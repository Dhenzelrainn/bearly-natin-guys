import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const [sellerScript, ordersView] = await Promise.all([
    read('resources/js/seller.js'),
    read('resources/views/seller/Orders/orders.blade.php'),
]);

test('Seller Orders success notice auto-dismisses without targeting validation alerts', () => {
    assert.match(ordersView, /class="orders-success-note" role="status" data-auto-dismiss="5000"/);
    assert.match(sellerScript, /\.orders-success-note\[data-auto-dismiss\]/);
    assert.match(sellerScript, /window\.setTimeout\(\(\) => notice\.remove\(\), delay\)/);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { actionButtons, purchaseItemLineTotalMinor } from '../../resources/js/my-purchases.js';

test('unsupported Pay Now and Buy Again actions are not offered', () => {
    const toPay = actionButtons({
        server: true,
        status: 'to-pay',
        details_url: '/orders/receipt',
    });
    const completed = actionButtons({
        server: true,
        status: 'completed',
        details_url: '/orders/receipt',
    });

    assert.doesNotMatch(toPay, /Pay Now/i);
    assert.doesNotMatch(completed, /Buy Again/i);
    assert.match(toPay, /View Details/);
    assert.match(completed, /Rate Product/);
});

test('purchase cards use the saved quantity line total rather than the per-unit price', () => {
    assert.equal(purchaseItemLineTotalMinor({
        price: 69990,
        qty: 5,
        line_total_minor: 34995000,
    }), 34995000);
});

test('purchase card line total falls back to unit price times quantity when unavailable', () => {
    assert.equal(purchaseItemLineTotalMinor({ price: 69990, qty: 5 }), 34995000);
});

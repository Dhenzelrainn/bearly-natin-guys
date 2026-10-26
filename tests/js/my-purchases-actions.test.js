import test from 'node:test';
import assert from 'node:assert/strict';
import { actionButtons } from '../../resources/js/my-purchases.js';

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

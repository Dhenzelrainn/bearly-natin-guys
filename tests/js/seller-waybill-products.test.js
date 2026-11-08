import test from 'node:test';
import assert from 'node:assert/strict';
import { setupWaybillProductToggles } from '../../resources/js/seller-waybill-products.js';

test('Waybill +N reveals the extra thumbnails and can collapse them again', () => {
    const attributes = new Map([
        ['aria-expanded', 'false'],
        ['aria-label', 'Show 2 more product images'],
        ['aria-controls', 'waybill-extra-products-1'],
    ]);
    const classes = new Set();
    let clickHandler;
    const button = {
        dataset: {
            waybillMoreCount: '2',
            waybillMoreLabel: '+2',
            waybillLessLabel: 'Show less',
        },
        textContent: '+2',
        getAttribute(name) { return attributes.get(name) ?? null; },
        setAttribute(name, value) { attributes.set(name, value); },
        addEventListener(eventName, listener) {
            assert.equal(eventName, 'click');
            clickHandler = listener;
        },
        classList: {
            toggle(name, force) {
                if (force) classes.add(name);
                else classes.delete(name);
            },
        },
    };
    const extraProducts = { hidden: true };
    const root = {
        querySelectorAll(selector) {
            assert.equal(selector, '[data-waybill-products-toggle]');
            return [button];
        },
        getElementById(id) {
            assert.equal(id, 'waybill-extra-products-1');
            return extraProducts;
        },
    };

    setupWaybillProductToggles(root);
    assert.equal(typeof clickHandler, 'function');

    clickHandler();
    assert.equal(extraProducts.hidden, false);
    assert.equal(button.textContent, 'Show less');
    assert.equal(attributes.get('aria-expanded'), 'true');
    assert.equal(attributes.get('aria-label'), 'Show fewer product images');
    assert.equal(classes.has('is-expanded'), true);

    clickHandler();
    assert.equal(extraProducts.hidden, true);
    assert.equal(button.textContent, '+2');
    assert.equal(attributes.get('aria-expanded'), 'false');
    assert.equal(attributes.get('aria-label'), 'Show 2 more product images');
    assert.equal(classes.has('is-expanded'), false);
});

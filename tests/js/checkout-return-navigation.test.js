import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const cartScript = await readFile(new URL('../../resources/js/cart.js', import.meta.url), 'utf8');
const productDetailScript = await readFile(new URL('../../resources/js/product-detail.js', import.meta.url), 'utf8');
const checkoutView = await readFile(new URL('../../resources/views/buyer/checkout.blade.php', import.meta.url), 'utf8');
const checkoutController = await readFile(new URL('../../app/Http/Controllers/BuyerCheckoutController.php', import.meta.url), 'utf8');

test('Cart checkout includes its current page as the return destination', () => {
    assert.match(cartScript, /new URL\('\/checkout', window\.location\.origin\)/);
    assert.match(cartScript, /searchParams\.set\([\s\S]*?'return_to',[\s\S]*?window\.location\.pathname/);
});

test('Buy Now carries the same-origin page that opened the product into checkout', () => {
    assert.match(productDetailScript, /new URL\(document\.referrer\)/);
    assert.match(productDetailScript, /referrer\.origin === window\.location\.origin/);
    assert.match(productDetailScript, /checkout\.searchParams\.set\('return_to', checkoutBackTarget\(\)\)/);
});

test('Checkout renders the server-validated back destination instead of always linking to Cart', () => {
    assert.match(checkoutView, /href="\{\{ \$checkoutBackUrl \}\}"/);
    assert.match(checkoutView, /\{\{ \$checkoutBackLabel \}\}/);
    assert.doesNotMatch(checkoutView, /class="checkout-back" href="\{\{ url\('\/cart'\) \}\}"/);
    assert.match(checkoutController, /strcasecmp\(\(string\) \$host, \$request->getHost\(\)\)/);
    assert.match(checkoutController, /#\^\/checkout\(\?:\/\|\$\)#/);
});

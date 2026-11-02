import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const feedbackView = await readFile(
    new URL('../../resources/views/seller/CustomerService/feedback.blade.php', import.meta.url),
    'utf8',
);
const sellerLegibility = await readFile(
    new URL('../../resources/css/seller-legibility.css', import.meta.url),
    'utf8',
);

test('Seller feedback product placeholders stay visible in dark mode', () => {
    for (const source of [feedbackView, sellerLegibility]) {
        assert.match(source, /feedback-review-context > span/);
        assert.match(source, /background-color: #45331d/);
        assert.match(source, /stroke: currentColor/);
    }

    assert.match(feedbackView, /feedback-review-photo/);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const messagesView = await readFile(
    new URL('../../resources/views/seller/CustomerService/messages.blade.php', import.meta.url),
    'utf8',
);
const sellerLegibility = await readFile(
    new URL('../../resources/css/seller-legibility.css', import.meta.url),
    'utf8',
);

test('Seller message context chips use the gold dark-mode accent', () => {
    for (const source of [messagesView, sellerLegibility]) {
        assert.match(source, /conversation-chip/);
        assert.match(source, /background: #efbd71/);
        assert.match(source, /color: #2b1d16/);
        assert.match(source, /message-filter-tabs/);
        assert.match(source, /message-filter-tabs button\.is-active/);
    }

    assert.match(messagesView, /message-order-reference/);
});

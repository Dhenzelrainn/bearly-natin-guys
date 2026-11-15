import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const buyerCss = await readFile(
    new URL('../../resources/css/buyer.css', import.meta.url),
    'utf8',
);
const notificationCss = await readFile(
    new URL('../../resources/css/notification-center.css', import.meta.url),
    'utf8',
);
const notificationView = await readFile(
    new URL('../../resources/views/notifications/partials/center.blade.php', import.meta.url),
    'utf8',
);

test('composite search bars use one rounded focus treatment', () => {
    for (const source of [buyerCss, notificationCss, notificationView]) {
        assert.match(source, /input(?:\[type="search"\])?:focus-visible/);
        assert.match(source, /outline:\s*none/);
        assert.match(source, /box-shadow:\s*none/);
        assert.match(source, /:focus-within/);
    }

    assert.match(buyerCss, /\.search:focus-within/);
    assert.match(buyerCss, /\.category-search\):focus-within/);
    assert.match(notificationCss, /\.nc-search:focus-within/);
    assert.match(notificationView, /@push\('styles'\)/);
});

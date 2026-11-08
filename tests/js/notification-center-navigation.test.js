import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

test('same-page notification links switch the Buyer profile to My Purchases', async () => {
    const source = await readFile(new URL('../../resources/js/notification-center.js', import.meta.url), 'utf8');

    assert.match(source, /destination\.pathname === window\.location\.pathname/);
    assert.match(source, /destination\.hash === '#purchases'/);
    assert.match(source, /window\.bearlyShowAccountPanel\('purchases'\)/);
});

test('Buyer notification destination rows are native whole-row links', async () => {
    const source = await readFile(new URL('../../resources/js/notification-center.js', import.meta.url), 'utf8');
    const pageSource = await readFile(new URL('../../resources/js/notification-center-page.js', import.meta.url), 'utf8');
    const buyerStyles = await readFile(new URL('../../resources/css/buyer.css', import.meta.url), 'utf8');
    const centerStyles = await readFile(new URL('../../resources/css/notification-center.css', import.meta.url), 'utf8');
    const buyerRow = source.match(/const rowBuyer = item => \{[\s\S]*?\n\};/)?.[0] || '';
    const centerRow = pageSource.match(/const rowFor = \(skin, item\) => \{[\s\S]*?\n\};/)?.[0] || '';

    assert.match(buyerRow, /const tag = item\.url \? 'a' : 'button'/);
    assert.match(buyerRow, /<\$\{tag\} \$\{action\} class="buyer-notification-item/);
    assert.match(buyerRow, /buyer-notification-arrow[\s\S]*?<\/\$\{tag\}>/);
    assert.match(source, /if \(row\.tagName === 'A'\) event\.preventDefault\(\)/);
    assert.match(buyerStyles, /\.buyer-notification-item\s*\{[^}]*width:\s*100%/s);

    assert.match(centerRow, /const isBuyerLink = skin === 'buyer' && Boolean\(item\.url\)/);
    assert.match(centerRow, /<\$\{tag\} class="nc-row/);
    assert.match(centerRow, /<\/\$\{tag\}>/);
    assert.match(pageSource, /if \(row\.tagName === 'A'\) event\.preventDefault\(\)/);
    assert.match(centerStyles, /\.nc-page--buyer \.nc-row\[href\]\s*\{[^}]*width:\s*100%/s);
});

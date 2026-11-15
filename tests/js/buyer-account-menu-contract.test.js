import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const [menuView, menuScript, profileScript, buyerCss] = await Promise.all([
    readFile(new URL('../../resources/views/buyer/partials/account-menu.blade.php', import.meta.url), 'utf8'),
    readFile(new URL('../../resources/js/buyer-account-menu.js', import.meta.url), 'utf8'),
    readFile(new URL('../../resources/js/profile.js', import.meta.url), 'utf8'),
    readFile(new URL('../../resources/css/buyer.css', import.meta.url), 'utf8'),
]);

test('Buyer account menu exposes the existing account destinations', () => {
    for (const section of ['profile', 'addresses', 'likes', 'purchases', 'reviews', 'notifications', 'vouchers', 'help']) {
        assert.match(menuView, new RegExp(`data-buyer-account-link="${section}"`));
    }

    assert.match(menuView, /aria-expanded="false"/);
    assert.match(menuView, /aria-controls="\{\{ \$accountMenuId \}\}"/);
    assert.match(menuView, /route\('notifications\.center', \['source' => 'profile'\]\)/);
});

test('Buyer menu matches the account dropdown reference without showing an admin role', () => {
    assert.match(menuView, /data-buyer-account-menu-identity/);
    assert.match(menuView, /data-buyer-account-avatar/);
    assert.match(menuView, /data-buyer-account-name/);
    assert.match(menuView, /buyer-account-menu-chevron/);
    assert.match(menuView, /data-notification-badge="dot"/);
    assert.match(menuView, /route\('logout'\)/);
    assert.doesNotMatch(menuView, /Administrator|Admin(?:istrator)? badge/i);
    assert.match(buyerCss, /width:min\(364px, calc\(100vw - 32px\)\)/);
    assert.match(buyerCss, /\.buyer-account-menu-identity/);
    assert.match(buyerCss, /\.buyer-account-logout-form/);
});

test('Buyer account menu opens on pointer hover and keyboard focus, and supports click, outside-close, and Escape', () => {
    assert.match(menuScript, /addEventListener\('pointerenter'/);
    assert.match(menuScript, /addEventListener\('focusin'/);
    assert.match(menuScript, /trigger\.addEventListener\('click'/);
    assert.match(menuScript, /document\.addEventListener\('pointerdown'/);
    assert.match(menuScript, /event\.key !== 'Escape'/);
    assert.match(menuScript, /aria-expanded/);
});

test('Buyer profile hash links switch tabs even when the account page is already open', () => {
    assert.match(profileScript, /window\.addEventListener\('hashchange'/);
    assert.match(profileScript, /bearly:account-panel-changed/);
    assert.match(profileScript, /'#addresses':'addresses'/);
    assert.match(profileScript, /'#vouchers':'vouchers'/);
    assert.match(profileScript, /'#help':'help'/);
});

test('Buyer profile updates refresh the duplicated dropdown photo and display name', () => {
    assert.match(profileScript, /querySelectorAll\('\[data-buyer-account-avatar\]'\)/);
    assert.match(profileScript, /querySelectorAll\('\[data-buyer-account-name\]'\)/);
    assert.match(profileScript, /element\.replaceChildren\(image\)/);
});

test('Buyer account chevron stays compact and unboxed', () => {
    assert.match(buyerCss, /\.buyer-account-trigger\{[\s\S]*?border:0;[\s\S]*?background:transparent;/);
    assert.match(buyerCss, /\.buyer-account-dropdown\[hidden\]\{display:none!important\}/);
});

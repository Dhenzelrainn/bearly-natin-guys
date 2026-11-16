import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');
const [layout, styles, darkStyles] = await Promise.all([
    read('resources/views/layouts/seller.blade.php'),
    read('resources/css/seller.css'),
    read('resources/css/seller-legibility.css'),
]);

test('Seller profile logout keeps its POST and CSRF form with a styled submit button', () => {
    assert.match(layout, /<form method="POST" action="{{ route\('logout'\) }}">\s*@csrf\s*<button\s+type="submit"\s+class="seller-profile-logout"/);
    assert.doesNotMatch(layout, /style="width:100%; border:0; background:transparent; cursor:pointer;"/);
});

test('Seller account and logout actions share a left-aligned icon row and keyboard focus', () => {
    const action = styles.match(/\.profile-popover > a,\s*\.profile-popover \.seller-profile-logout\s*\{([^}]+)\}/)?.[1];
    assert.ok(action, 'the account link and logout button share their layout');
    for (const property of ['display: flex;', 'align-items: center;', 'justify-content: flex-start;', 'gap: 8px;', 'width: 100%;', 'min-height: 40px;', 'text-align: left;']) {
        assert.ok(action.includes(property), `menu actions retain ${property}`);
    }
    assert.match(styles, /\.profile-popover > a:focus-visible,\s*\.profile-popover \.seller-profile-logout:focus-visible\s*\{[^}]*outline: 2px solid/);
    assert.match(styles, /\.profile-popover \.seller-profile-logout \.seller-ui-icon\s*\{[^}]*width: 16px;[^}]*height: 16px;[^}]*flex: 0 0 16px;/);
});

test('Seller profile logout uses the same dark-theme action color as account settings', () => {
    assert.match(darkStyles, /body\.seller-body \.profile-popover :is\(a, \.seller-profile-logout\)\s*\{\s*color: #efbd71;/);
});

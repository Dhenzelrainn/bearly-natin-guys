import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const [themeCss, adminLayout, shippingSettlementsView] = await Promise.all([
    read('resources/css/theme.css'),
    read('resources/views/layouts/admin.blade.php'),
    read('resources/views/admin/finance/shipping-settlements.blade.php'),
]);

test('Admin Finance remittance forms use readable dark-mode surfaces and fields', () => {
    assert.match(shippingSettlementsView, /class="finance-action-form"/);
    assert.match(themeCss, /body\.admin-body \.finance-action-form\s*\{[\s\S]*?background:\s*var\(--theme-surface\);[\s\S]*?color:\s*var\(--theme-text\);/);
    assert.match(themeCss, /body\.admin-body \.finance-action-form :is\([\s\S]*?background:\s*var\(--theme-input\);[\s\S]*?color:\s*var\(--theme-text\);/);
    assert.match(themeCss, /body\.admin-body \.finance-action-form :is\([\s\S]*?small,\s*\.table-subline\s*\)[\s\S]*?color:\s*#c0b2a5;/);
    assert.match(adminLayout, /body\.admin-body \.finance-action-form\s*\{[\s\S]*?background:\s*#211c18 !important;[\s\S]*?color:\s*#f1e9e1 !important;/);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const [themeCss, adminLayout, shippingRatesView] = await Promise.all([
    read('resources/css/theme.css'),
    read('resources/views/layouts/admin.blade.php'),
    read('resources/views/admin/shipping-rates.blade.php'),
]);

test('Admin Shipping Fees keeps volumetric values and calculation copy readable in dark mode', () => {
    assert.match(shippingRatesView, /id="volumetric-divisor"/);
    assert.match(shippingRatesView, /Volumetric weight \(kg\) = combined parcel/);
    assert.match(themeCss, /body\.admin-body :is\([\s\S]*?label\[for="volumetric-divisor"\][\s\S]*?color:\s*#f1e9e1;/);
    assert.match(themeCss, /body\.admin-body :is\([\s\S]*?#volumetric-divisor[\s\S]*?background:\s*var\(--theme-input\);[\s\S]*?color:\s*#f1e9e1;[\s\S]*?color-scheme:\s*dark;/);
    assert.match(themeCss, /body\.admin-body :is\([\s\S]*?#volumetric-divisor \+ small[\s\S]*?color:\s*#c0b2a5;/);
    assert.match(adminLayout, /body\.admin-body :is\([\s\S]*?#volumetric-divisor[\s\S]*?background:\s*#1b1714 !important;[\s\S]*?color:\s*#f1e9e1 !important;/);
});

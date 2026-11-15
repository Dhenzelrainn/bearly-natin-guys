import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const [themeCss, adminLayout, disputesView] = await Promise.all([
    read('resources/css/theme.css'),
    read('resources/views/layouts/admin.blade.php'),
    read('resources/views/admin/compliance/disputes.blade.php'),
]);

test('Admin dispute participant controls have readable dark-mode surfaces and copy', () => {
    assert.match(disputesView, /class="panel coordination-panel"/);
    assert.match(themeCss, /body\.admin-body \.coordination-panel \.party-icon\s*\{[\s\S]*?background:\s*#45331d;[\s\S]*?color:\s*#efbd71;/);
    assert.match(themeCss, /body\.admin-body \.coordination-panel \.party-card small,[\s\S]*?body\.admin-body \.coordination-panel \.dispute-visibility-help\s*\{[\s\S]*?color:\s*#c0b2a5;/);
    assert.match(themeCss, /body\.admin-body \.coordination-panel \.form-field > span\s*\{[\s\S]*?color:\s*#f1e9e1;/);
    assert.match(themeCss, /body\.admin-body \.coordination-panel \.form-field textarea\s*\{[\s\S]*?background:\s*var\(--theme-input\);[\s\S]*?color:\s*var\(--theme-text\);/);
    assert.match(adminLayout, /body\.admin-body \.coordination-panel \.party-icon\s*\{[\s\S]*?background:\s*#45331d !important;[\s\S]*?color:\s*#efbd71 !important;/);
});

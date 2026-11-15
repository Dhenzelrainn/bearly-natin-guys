import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const [themeCss, adminLayout, settingsView] = await Promise.all([
    read('resources/css/theme.css'),
    read('resources/views/layouts/admin.blade.php'),
    read('resources/views/admin/system/settings.blade.php'),
]);

test('Admin Platform Settings replaces light-only status and icon surfaces in dark mode', () => {
    assert.match(settingsView, /class="settings-toggle-card"/);
    assert.match(settingsView, /class="settings-save-state"/);
    assert.match(settingsView, /Configuration saved/);
    assert.match(themeCss, /body\.admin-body \.settings-toggle-card > div\s*\{[\s\S]*?background:\s*#45331d;[\s\S]*?color:\s*#efbd71;/);
    assert.match(themeCss, /body\.admin-body \.settings-save-state\s*\{[\s\S]*?background:\s*#3b3027;[\s\S]*?color:\s*var\(--theme-text\);/);
    assert.match(themeCss, /body\.admin-body \.settings-save-state strong\s*\{[\s\S]*?color:\s*#f1e9e1;/);
    assert.match(themeCss, /body\.admin-body \.settings-save-state-icon\s*\{[\s\S]*?background:\s*#1e3828;[\s\S]*?color:\s*#a8d5b0;/);
    assert.match(themeCss, /body\.admin-body \.settings-grid input\[type="number"\][\s\S]*?color-scheme:\s*dark;/);
    assert.match(adminLayout, /body\.admin-body \.settings-save-state\s*\{[\s\S]*?background:\s*#3b3027 !important;[\s\S]*?color:\s*#f1e9e1 !important;/);
});

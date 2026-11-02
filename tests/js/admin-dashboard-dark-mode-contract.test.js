import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const themeCss = await readFile(
    new URL('../../resources/css/theme.css', import.meta.url),
    'utf8',
);
const dashboardView = await readFile(
    new URL('../../resources/views/admin/dashboard.blade.php', import.meta.url),
    'utf8',
);

test('Admin dashboard dark mode replaces fixed light controls and copy', () => {
    for (const source of [themeCss, dashboardView]) {
        assert.match(source, /dashboard-kpi-card \.metric-icon/);
        assert.match(source, /dashboard-kpi-status\.is-neutral/);
        assert.match(source, /segmented-control button\.is-active/);
        assert.match(source, /background(?:-color)?: #45331d/);
        assert.match(source, /color(?:-color)?: #efbd71/);
    }

    assert.match(themeCss, /dashboard-kpi-card > p[\s\S]*?color: #c0b2a5/);
    assert.match(themeCss, /dashboard-kpi-card > small[\s\S]*?color: #b4a79c/);
    assert.match(dashboardView, /@push\('styles'\)/);
});

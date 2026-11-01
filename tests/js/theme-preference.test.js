import test from 'node:test';
import assert from 'node:assert/strict';
import { isTheme, nextTheme, resolveTheme, THEME_STORAGE_KEY } from '../../resources/js/theme-preference.js';

test('first visit follows the system preference when no supported choice is saved', () => {
    assert.equal(resolveTheme(null, false), 'light');
    assert.equal(resolveTheme(null, true), 'dark');
    assert.equal(resolveTheme('unknown', true), 'dark');
});

test('a saved light or dark choice overrides the system preference', () => {
    assert.equal(resolveTheme('light', true), 'light');
    assert.equal(resolveTheme('dark', false), 'dark');
    assert.equal(isTheme('light'), true);
    assert.equal(isTheme('dark'), true);
    assert.equal(isTheme('system'), false);
});

test('the switch toggles themes and uses one browser-wide storage key', () => {
    assert.equal(nextTheme('light'), 'dark');
    assert.equal(nextTheme('dark'), 'light');
    assert.equal(THEME_STORAGE_KEY, 'bearly-theme');
});

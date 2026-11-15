import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const themeCss = await readFile(
    new URL('../../resources/css/theme.css', import.meta.url),
    'utf8',
);

test('Admin notification trigger hover and keyboard focus use dark surfaces', () => {
    assert.match(
        themeCss,
        /:root\[data-theme="dark"\] body\.admin-body \.admin-notification-trigger:hover:not\(:disabled\),\s*:root\[data-theme="dark"\] body\.admin-body \.admin-notification-trigger:focus-visible:not\(:disabled\)\s*\{[^}]*border-color:\s*#806334;[^}]*background:\s*#34271e;[^}]*color:\s*var\(--theme-accent\);/,
    );
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const logisticsCss = await readFile(
    new URL('../../resources/css/logistics.css', import.meta.url),
    'utf8',
);

test('Finance handoff details and instructions use readable dark-mode surfaces', () => {
    assert.match(
        logisticsCss,
        /:root\[data-theme="dark"\] body\.ops-body \.cash-remittance-details\s*\{[^}]*background:\s*var\(--theme-surface-soft\);/s,
    );
    assert.match(
        logisticsCss,
        /:root\[data-theme="dark"\] body\.ops-body \.cash-remittance-details-heading\s*\{[^}]*background:\s*#34291f;/s,
    );
    assert.match(
        logisticsCss,
        /\.cash-remittance-details-heading h3,[\s\S]*?\.cash-remittance-grid dd\s*\{[^}]*color:\s*var\(--theme-text\);/,
    );
    assert.match(
        logisticsCss,
        /:root\[data-theme="dark"\] body\.ops-body \.cash-remittance-grid dt\s*\{[^}]*color:\s*var\(--theme-muted\);/s,
    );
    assert.match(
        logisticsCss,
        /:root\[data-theme="dark"\] body\.ops-body \.cash-remittance-instructions\s*\{[^}]*background:\s*#34291f;[^}]*color:\s*#e7d6c0;/s,
    );
});

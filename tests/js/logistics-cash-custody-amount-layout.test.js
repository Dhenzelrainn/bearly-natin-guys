import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const logisticsCss = await readFile(
    new URL('../../resources/css/logistics.css', import.meta.url),
    'utf8',
);

test('COD collection amount column has enough width for single-line currency values', () => {
    assert.match(
        logisticsCss,
        /body\[data-module="logistics"\] \.cash-custody-card\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1\.1fr\)\s+minmax\(0,\s*1\.35fr\)\s+minmax\(0,\s*1\.1fr\);/s,
    );
    assert.match(
        logisticsCss,
        /body\[data-module="logistics"\] \.cod-cash-amount-item > strong\s*\{[^}]*font-size:\s*clamp\(16px,\s*1\.35vw,\s*21px\);[^}]*white-space:\s*nowrap;/s,
    );
});

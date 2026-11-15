import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const [view, styles] = await Promise.all([
    readFile(new URL('../../resources/views/logistics/cash/index.blade.php', import.meta.url), 'utf8'),
    readFile(new URL('../../resources/css/logistics.css', import.meta.url), 'utf8'),
]);

test('COD allocations render as separate labeled order references with a usable copy action', () => {
    assert.match(view, /<dd class="cash-custody-order-list">/);
    assert.match(view, /class="cash-custody-order-entry"/);
    assert.match(view, /class="cash-custody-order-label">Order<\/span>/);
    assert.match(view, /class="cash-custody-order-label">Seller order<\/span>/);
    assert.match(view, /data-copy-value="\{\{ \$allocation->order->order_no \}\}"/);
    assert.match(view, /aria-label="Copy order ID \{\{ \$allocation->order->order_no \}\}"/);
    assert.match(view, /Due <strong>₱\{\{ number_format\(\(int\) \$allocation->amount_due_minor/);
});

test('COD order references use a full-width stacked layout and wrap long identifiers safely', () => {
    assert.match(styles, /\.cash-custody-reference-item dd\.cash-custody-order-list\s*\{[^}]*display:\s*grid;/s);
    assert.match(styles, /\.cash-custody-order-entry\s*\{[^}]*min-width:\s*0;/s);
    assert.match(styles, /\.cash-custody-order-value\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\)\s+auto;/s);
    assert.match(styles, /\.cash-custody-order-value > code,[\s\S]*?overflow-wrap:\s*anywhere;/);
    assert.match(styles, /\.cash-custody-order-entry \+ \.cash-custody-order-entry\s*\{[^}]*border-top:/s);
    assert.match(styles, /:root\[data-theme="dark"\][\s\S]*?\.cash-custody-order-value \.cash-custody-copy:hover/);
});

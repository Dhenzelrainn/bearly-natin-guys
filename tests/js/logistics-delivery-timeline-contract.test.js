import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const [view, styles] = await Promise.all([
    read('resources/views/logistics/dispatch/monitoring.blade.php'),
    read('resources/css/logistics.css'),
]);

test('Logistics delivery timeline uses an accessible parcel-focused dialog and event timeline', () => {
    assert.match(view, /class="modal delivery-timeline-modal"/);
    assert.match(view, /role="dialog"/);
    assert.match(view, /aria-modal="true"/);
    assert.match(view, /aria-labelledby="delivery-timeline-title-/);
    assert.match(view, /aria-describedby="delivery-timeline-subtitle-/);
    assert.match(view, /data-lucide="package"/);
    assert.match(view, /Dispatch monitoring/);
    assert.match(view, /delivery-timeline-time/);
    assert.match(view, /delivery-timeline-marker/);
    assert.match(view, /delivery-timeline-copy/);
    assert.match(view, /aria-label="Close delivery timeline"/);
});

test('Delivery timeline styles provide the wide reference layout and responsive dark-theme treatment', () => {
    assert.match(styles, /\.delivery-timeline-modal\s*\{[\s\S]*?width:\s*min\(1200px/);
    assert.match(styles, /\.delivery-timeline-list\.has-events::before/);
    assert.match(styles, /\.delivery-timeline-event\.is-verification/);
    assert.match(styles, /:root\[data-theme="dark"\][\s\S]*?\.delivery-timeline-modal/);
    assert.match(styles, /@media \(max-width:\s*680px\)[\s\S]*?\.delivery-timeline-event/);
    assert.match(styles, /\.delivery-timeline-modal\[hidden\]\s*\{\s*display:\s*none/);
});

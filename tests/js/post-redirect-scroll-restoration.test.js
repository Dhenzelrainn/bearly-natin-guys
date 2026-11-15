import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { setupPostRedirectScrollRestoration } from '../../resources/js/post-redirect-scroll.js';

const STORAGE_KEY = 'bearly:post-redirect-scroll:v1';
const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const [themeScript, themeToggle, adminLayout, financePayments, ...roleLayouts] = await Promise.all([
    read('resources/js/theme.js'),
    read('resources/views/partials/theme-toggle.blade.php'),
    read('resources/views/layouts/admin.blade.php'),
    read('resources/views/admin/finance/payments.blade.php'),
    read('resources/views/layouts/seller.blade.php'),
    read('resources/views/layouts/buyer.blade.php'),
    read('resources/views/logistics/layouts/app.blade.php'),
    read('resources/views/rider/layouts/app.blade.php'),
]);

function createHarness({
    url = 'https://dev.bearlymarket.com/admin/payments',
    scrollX = 0,
    scrollY = 0,
    storage = new Map(),
} = {}) {
    const frames = [];
    const microtasks = [];
    const listeners = new Map();
    const scrollCalls = [];
    const sessionStorage = {
        getItem: (key) => storage.get(key) ?? null,
        setItem: (key, value) => storage.set(key, String(value)),
        removeItem: (key) => storage.delete(key),
    };
    const windowRef = {
        location: new URL(url),
        sessionStorage,
        scrollX,
        scrollY,
        scrollTo: (...coordinates) => scrollCalls.push(coordinates),
        requestAnimationFrame: (callback) => frames.push(callback),
        queueMicrotask: (callback) => microtasks.push(callback),
    };
    const documentRef = {
        readyState: 'complete',
        documentElement: { style: {} },
        addEventListener: (type, callback) => listeners.set(type, callback),
    };

    setupPostRedirectScrollRestoration({ documentRef, windowRef });

    return {
        documentRef,
        frames,
        listeners,
        microtasks,
        scrollCalls,
        sessionStorage,
        storage,
        windowRef,
    };
}

function submit(harness, {
    method = 'post',
    target = '',
    prevented = false,
    specialized = false,
} = {}) {
    const form = {
        tagName: 'FORM',
        method,
        target,
        dataset: {},
        hasAttribute: () => false,
        closest: () => specialized ? {} : null,
    };
    const event = { target: form, defaultPrevented: prevented };
    harness.listeners.get('submit')(event);

    return { event, form };
}

function flushFrames(harness) {
    while (harness.frames.length) harness.frames.shift()();
}

test('shared theme script enables restoration on Admin Finance and all main role layouts', () => {
    assert.match(themeToggle, /resources\/js\/theme\.js/);
    assert.match(themeScript, /import \{ setupPostRedirectScrollRestoration \} from '\.\/post-redirect-scroll\.js'/);
    assert.match(themeScript, /setupPostRedirectScrollRestoration\(\);/);
    assert.match(financePayments, /@extends\('layouts\.admin'\)/);
    assert.match(adminLayout, /@include\('partials\.theme-toggle'\)/);
    roleLayouts.forEach((layout) => assert.match(layout, /@include\('partials\.theme-toggle'\)/));
});

test('restores the viewport after a same-page POST redirect, ignoring the URL hash', () => {
    const storage = new Map();
    const source = createHarness({
        url: 'https://dev.bearlymarket.com/admin/payments#refund-cash-assignments',
        scrollY: 1460,
        storage,
    });

    submit(source);
    source.microtasks.splice(0).forEach((callback) => callback());

    assert.equal(JSON.parse(storage.get(STORAGE_KEY)).scrollY, 1460);

    const destination = createHarness({
        url: 'https://dev.bearlymarket.com/admin/payments',
        storage,
    });
    flushFrames(destination);

    assert.deepEqual(destination.scrollCalls, [[0, 1460]]);
    assert.equal(storage.has(STORAGE_KEY), false);
    assert.equal(destination.documentRef.documentElement.style.scrollBehavior, '');
});

test('captures the current query string when a page URL changes before submit', () => {
    const storage = new Map();
    const source = createHarness({ scrollY: 880, storage });
    source.windowRef.location.search = '?page=2';

    submit(source);
    source.microtasks.splice(0).forEach((callback) => callback());

    assert.equal(JSON.parse(storage.get(STORAGE_KEY)).page, '/admin/payments?page=2');

    const destination = createHarness({
        url: 'https://dev.bearlymarket.com/admin/payments?page=2',
        storage,
    });
    flushFrames(destination);

    assert.deepEqual(destination.scrollCalls, [[0, 880]]);
});

test('does not restore after a redirect to a different page and clears the one-shot record', () => {
    const storage = new Map([[STORAGE_KEY, JSON.stringify({
        page: '/admin/payments',
        scrollX: 0,
        scrollY: 900,
        savedAt: Date.now(),
    })]]);
    const destination = createHarness({
        url: 'https://dev.bearlymarket.com/admin/dashboard',
        storage,
    });

    assert.deepEqual(destination.scrollCalls, []);
    assert.equal(storage.has(STORAGE_KEY), false);
});

test('ignores GET, canceled, new-window, and page-specific form submissions', () => {
    const harness = createHarness({ scrollY: 640 });

    submit(harness, { method: 'get' });
    submit(harness, { prevented: true });
    submit(harness, { target: '_blank' });
    submit(harness, { specialized: true });

    assert.equal(harness.storage.has(STORAGE_KEY), false);
});

test('clears the saved position if a later submit handler cancels navigation', () => {
    const harness = createHarness({ scrollY: 640 });
    const { event } = submit(harness);

    event.defaultPrevented = true;
    harness.microtasks.splice(0).forEach((callback) => callback());

    assert.equal(harness.storage.has(STORAGE_KEY), false);
});

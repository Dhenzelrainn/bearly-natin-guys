import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createContext, runInContext } from 'node:vm';

const notificationSource = (await readFile(new URL('../../resources/js/notification-center.js', import.meta.url), 'utf8'))
    .replace(/^export /gm, '');
const navbarSources = await Promise.all(['buyer.js', 'profile.js', 'bearly-chat.js'].map(async file => {
    const source = await readFile(new URL(`../../resources/js/${file}`, import.meta.url), 'utf8');
    const navbar = source.match(/\(function initBearlyGlobalNavbarState\(\)\s*\{[\s\S]*?\n\}\)\(\);/)?.[0];
    assert.ok(navbar, `${file} still has its navbar initializer`);
    return { file, navbar };
}));

function badge(kind = '', text = '') {
    return {
        dataset: { notificationBadge: kind },
        textContent: text,
        hidden: true,
        style: {},
        attributes: { 'aria-label': 'Unread notifications' },
        setAttribute(name, value) { this.attributes[name] = value; },
    };
}

function harness({ dotOnly = false } = {}) {
    const numeric = badge('', '0');
    const dot = badge('dot');
    const badges = dotOnly ? [dot] : [numeric, dot];
    const navCount = badge();
    const cart = badge();
    const cartLink = { style: {}, querySelector: () => cart };
    const profileActions = { style: {} };
    const listeners = new Map();
    const intervals = [];
    const events = [];
    const document = {
        readyState: 'loading',
        addEventListener() {},
        getElementById: () => navCount,
        querySelector: () => badges[0],
        querySelectorAll(selector) {
            if (selector === '[data-notification-badge]') return badges;
            if (selector === 'a[href$="/cart"],a[href="/cart"]') return [cartLink];
            if (selector === '.profile-top-actions') return [profileActions];
            if (selector === 'a[href*="#notifications"]') return [];
            throw new Error(`Unexpected selector: ${selector}`);
        },
    };
    const window = {
        bearlyBuyerProfile: { cartCount: 3 },
        addEventListener: (name, callback) => listeners.set(name, callback),
        dispatchEvent: event => events.push(event),
    };
    const context = createContext({
        document, window,
        setInterval: callback => intervals.push(callback),
        CustomEvent: class {
            constructor(type, options) { this.type = type; this.detail = options.detail; }
        },
    });
    runInContext(notificationSource, context);

    return {
        numeric, dot, navCount, cart, profileActions, window, events,
        sync: count => context.syncNotificationBadges(count),
        boot(navbar) {
            document.readyState = 'complete';
            runInContext(navbar, context);
        },
        resync() {
            listeners.get('focus')?.();
            listeners.get('storage')?.();
            intervals.forEach(callback => callback());
            window.BearlyNavbarState.sync();
        },
    };
}

test('notification sync clears stray dot text while keeping the numeric count and accessible labels', () => {
    const h = harness();
    h.dot.textContent = '1';

    h.sync(12);

    assert.equal(h.dot.textContent, '');
    assert.equal(h.dot.hidden, false);
    assert.equal(h.dot.attributes['aria-label'], 'Unread notifications');
    assert.equal(h.numeric.textContent, '12');
    assert.equal(h.numeric.attributes['aria-label'], '12 unread notifications');
    assert.equal(h.navCount.textContent, '12');
    assert.equal(h.events.at(-1).detail.unread, 12);
});

test('reading all notifications hides both indicators and new unread notifications restore them', () => {
    const h = harness();

    for (const count of [1, 0, 4, -1, 'invalid']) {
        h.sync(count);
        const unread = Math.max(0, Number(count) || 0);
        assert.equal(h.dot.textContent, '');
        assert.equal(h.dot.hidden, unread === 0);
        assert.equal(h.numeric.textContent, String(unread));
        assert.equal(h.numeric.hidden, unread === 0);
        assert.equal(h.navCount.hidden, unread === 0);
        assert.equal(h.dot.style.display, unread === 0 ? 'none' : '');
        assert.equal(h.numeric.style.display, unread === 0 ? 'none' : '');
    }
});

for (const { file, navbar } of navbarSources) {
    test(`${file} navbar focus, storage, polling and cart updates never write numbers into notification dots`, () => {
        const h = harness();
        h.sync(1);
        h.boot(navbar);
        h.resync();

        assert.equal(h.dot.textContent, '');
        assert.equal(h.dot.hidden, false);
        assert.equal(h.numeric.textContent, '1');
        assert.equal(h.cart.textContent, 3);
        assert.equal(h.cart.hidden, false);

        h.sync(0);
        h.window.bearlyBuyerProfile.cartCount = 0;
        h.resync();
        assert.equal(h.dot.hidden, true);
        assert.equal(h.numeric.hidden, true);
        assert.equal(h.cart.hidden, true);
        assert.equal(h.profileActions.style.marginLeft, 'auto');
    });

    test(`${file} navbar preserves server-synced unread presence without a numeric notification badge`, () => {
        const h = harness({ dotOnly: true });
        h.sync(4);
        h.boot(navbar);
        h.resync();

        assert.equal(h.dot.textContent, '');
        assert.equal(h.dot.hidden, false);
        assert.equal(h.dot.style.display, '');
    });
}

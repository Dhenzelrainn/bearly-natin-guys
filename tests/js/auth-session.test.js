import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { initAuthSessionMonitor } from '../../resources/js/auth-session.js';

const active = (userId = 17) => ({
    status: 200,
    ok: true,
    json: async () => ({ data: { authenticated: true, user_id: userId } }),
});
const unauthorized = () => ({ status: 401, ok: false });
const settle = () => new Promise(setImmediate);

function harness({ userId = 17, hidden = false, fetchImpl, statusUrl = '/auth/session/status' } = {}) {
    const calls = [];
    const redirects = [];
    const intervals = new Map();
    const timeouts = new Map();
    let reloads = 0;
    let disconnects = 0;
    let timerId = 0;
    const documentRef = Object.assign(new EventTarget(), { hidden });
    const location = new URL('https://bearly.example.test/home');
    location.replace = (url) => redirects.push(url);
    location.reload = () => { reloads += 1; };
    const windowRef = Object.assign(new EventTarget(), {
        location,
        AbortController,
        fetch: async (...args) => {
            calls.push(args);
            return fetchImpl ? fetchImpl(...args) : active();
        },
        setInterval: (callback, ms) => {
            const id = ++timerId;
            intervals.set(id, { callback, ms });
            return id;
        },
        clearInterval: (id) => intervals.delete(id),
        setTimeout: (callback, ms) => {
            const id = ++timerId;
            timeouts.set(id, { callback, ms });
            return id;
        },
        clearTimeout: (id) => timeouts.delete(id),
        BearlyRealtime: { echo: { disconnect: () => { disconnects += 1; } } },
    });
    const monitor = initAuthSessionMonitor({
        userId,
        statusUrl,
        signInUrl: '/login?session=ended',
        documentRef,
        windowRef,
    });

    return {
        calls, redirects, intervals, timeouts, documentRef, windowRef, monitor,
        reloads: () => reloads,
        disconnects: () => disconnects,
        tick: () => [...intervals.values()].forEach(({ callback }) => callback()),
    };
}

test('monitor is initialized before the optional Reverb connection and shares all five role entry points', async () => {
    const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');
    const realtime = await read('resources/js/realtime.js');
    assert.ok(realtime.indexOf('const sessionMonitor = initAuthSessionMonitor(')
        < realtime.indexOf('if (userId > 0 && key)'));
    assert.match(realtime, /\.listen\('\.auth\.sessions-revoked', \(\) => \{\s*void sessionMonitor\?\.check\(\{ force: true \}\)/);

    const entryPoints = await Promise.all([
        'resources/views/buyer/partials/buyer-header.blade.php',
        'resources/views/layouts/seller.blade.php',
        'resources/views/layouts/admin.blade.php',
        'resources/views/logistics/layouts/app.blade.php',
        'resources/views/rider/layouts/app.blade.php',
        'resources/views/announcements/show.blade.php',
    ].map(read));
    entryPoints.forEach((source) => {
        assert.match(source, /resources\/js\/realtime\.js/);
        assert.match(source, /@include\('partials\.realtime-identity'\)/);
    });
});

test('checks the current session with same-origin credentials, no cache, no redirects, and a timeout', async () => {
    const h = harness();
    await settle();
    const [url, options] = h.calls[0];
    assert.equal(url, 'https://bearly.example.test/auth/session/status');
    assert.equal(options.credentials, 'same-origin');
    assert.equal(options.cache, 'no-store');
    assert.equal(options.redirect, 'error');
    assert.equal(options.headers.Accept, 'application/json');
    assert.equal(options.headers['X-Requested-With'], 'XMLHttpRequest');
    assert.ok(options.signal instanceof AbortSignal);
    assert.equal([...h.intervals.values()][0].ms, 15000);
    assert.equal(h.timeouts.size, 0);
    assert.deepEqual(h.redirects, []);
    h.monitor.stop();
});

test('server-confirmed signout replaces the page, disconnects Echo, and stops further monitoring', async () => {
    const h = harness({ fetchImpl: async () => unauthorized() });
    await settle();
    assert.deepEqual(h.redirects, ['https://bearly.example.test/login?session=ended']);
    assert.equal(h.disconnects(), 1);
    assert.equal(h.intervals.size, 0);
    h.windowRef.dispatchEvent(new Event('focus'));
    await h.monitor.check({ force: true });
    assert.equal(h.calls.length, 1);
});

test('a delayed revocation signal never signs out a fresh valid login', async () => {
    const h = harness();
    await settle();
    await h.monitor.check({ force: true });
    assert.equal(h.calls.length, 2);
    assert.deepEqual(h.redirects, []);
    assert.equal(h.disconnects(), 0);
    h.monitor.stop();
});

test('a failing realtime disconnect cannot block the confirmed signout redirect', async () => {
    const h = harness({ hidden: true, fetchImpl: async () => unauthorized() });
    h.windowRef.BearlyRealtime.echo.disconnect = () => { throw new Error('Transport failed'); };
    await h.monitor.check({ force: true });
    assert.equal(h.redirects.length, 1);
    assert.equal(h.intervals.size, 0);
});

test('an overlapping revocation signal triggers a second check instead of being lost', async () => {
    let finishFirst;
    let request = 0;
    const h = harness({ fetchImpl: () => {
        request += 1;
        return request === 1 ? new Promise((resolve) => { finishFirst = resolve; }) : unauthorized();
    } });
    await h.monitor.check({ force: true });
    await h.monitor.check({ force: true });
    assert.equal(h.calls.length, 1);
    finishFirst(active());
    await settle();
    assert.equal(h.calls.length, 2);
    assert.equal(h.redirects.length, 1);
});

test('polling remains available without realtime, skips hidden pages, and checks on return', async () => {
    const h = harness({ hidden: true });
    h.windowRef.BearlyRealtime = { enabled: false, connected: false };
    h.tick();
    await settle();
    assert.equal(h.calls.length, 0);
    h.documentRef.hidden = false;
    h.documentRef.dispatchEvent(new Event('visibilitychange'));
    await settle();
    assert.equal(h.calls.length, 1);
    h.tick();
    await settle();
    assert.equal(h.calls.length, 2);
    h.monitor.stop();
});

test('focus, online, page restoration, and realtime reconnect all recheck missed revocations', async () => {
    const h = harness();
    await settle();
    for (const type of ['focus', 'online', 'pageshow', 'bearly:realtime-status']) {
        const event = new Event(type);
        event.detail = { connected: true };
        h.windowRef.dispatchEvent(event);
        await settle();
    }
    assert.equal(h.calls.length, 5);
    h.monitor.stop();
});

test('a realtime wake-up also checks an idle background tab', async () => {
    const h = harness({ hidden: true, fetchImpl: async () => unauthorized() });
    assert.equal(h.calls.length, 0);
    await h.monitor.check({ force: true });
    assert.equal(h.redirects.length, 1);
});

test('network, rate-limit, and server errors never imply a forced signout', async () => {
    for (const status of [403, 404, 419, 429, 500, 503]) {
        const h = harness({ fetchImpl: async () => ({ ok: false, status }) });
        await settle();
        assert.deepEqual(h.redirects, [], `HTTP ${status}`);
        assert.equal(h.intervals.size, 1);
        h.monitor.stop();
    }
    let offline = true;
    const h = harness({ fetchImpl: async () => {
        if (offline) throw new TypeError('Network unavailable');
        return unauthorized();
    } });
    await settle();
    assert.equal(h.redirects.length, 0);
    offline = false;
    await h.monitor.check();
    assert.equal(h.redirects.length, 1);
});

test('a hung check times out and the next check can recover', async () => {
    let hang = true;
    const h = harness({ fetchImpl: (_, { signal }) => {
        if (!hang) return active();
        return new Promise((_, reject) => {
            signal.addEventListener('abort', () => reject(new Error('Aborted')), { once: true });
        });
    } });
    const timeout = [...h.timeouts.values()][0];
    assert.equal(timeout.ms, 8000);
    timeout.callback();
    await settle();
    assert.equal(h.redirects.length, 0);
    hang = false;
    await h.monitor.check();
    assert.equal(h.calls.length, 2);
    h.monitor.stop();
});

test('account changes refresh stale pages without logging the new account out', async () => {
    const h = harness({ fetchImpl: async () => active(99) });
    await settle();
    assert.equal(h.reloads(), 1);
    assert.equal(h.disconnects(), 1);
    assert.deepEqual(h.redirects, []);
});

test('all affected tabs react but an unrelated user stays signed in', async () => {
    const tabs = [harness(), harness(), harness({ userId: 99, fetchImpl: async () => active(99) })];
    await settle();
    for (const tab of tabs.slice(0, 2)) {
        tab.windowRef.fetch = async () => unauthorized();
        await tab.monitor.check({ force: true });
    }
    await tabs[2].monitor.check({ force: true });
    assert.deepEqual(tabs.map((tab) => tab.redirects.length), [1, 1, 0]);
    tabs[2].monitor.stop();
});

test('guests and cross-origin session endpoints never initialize monitoring', () => {
    for (const options of [{ userId: 0 }, { statusUrl: 'https://untrusted.example.test/session' }]) {
        const h = harness(options);
        assert.equal(h.monitor, null);
        assert.equal(h.calls.length, 0);
        assert.equal(h.intervals.size, 0);
    }
});

test('a stopped monitor ignores a late unauthorized response', async () => {
    let finish;
    const h = harness({ fetchImpl: () => new Promise((resolve) => { finish = resolve; }) });
    h.monitor.stop();
    finish(unauthorized());
    await settle();
    assert.deepEqual(h.redirects, []);
});

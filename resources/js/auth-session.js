const POLL_INTERVAL_MS = 15000;
const CHECK_TIMEOUT_MS = 8000;

function sameOriginUrl(value, location) {
    if (typeof value !== 'string' || !value) return null;

    try {
        const url = new URL(value, location.href);
        return url.origin === location.origin ? url.href : null;
    } catch {
        return null;
    }
}

/**
 * Realtime is a wake-up signal only. The server checks the current browser
 * session, so a delayed event cannot sign out a fresh login. Monitoring also
 * works without Echo/Reverb; transient network failures never mean sign-out.
 */
export function initAuthSessionMonitor({
    userId,
    statusUrl,
    signInUrl,
    documentRef = document,
    windowRef = window,
} = {}) {
    const expectedUserId = Number(userId);
    const endpoint = sameOriginUrl(statusUrl, windowRef.location);
    const login = sameOriginUrl(signInUrl, windowRef.location);

    if (!Number.isSafeInteger(expectedUserId) || expectedUserId < 1
        || !endpoint || !login || typeof windowRef.fetch !== 'function') {
        return null;
    }

    let stopped = false;
    let inFlight = false;
    let checkAgain = false;
    let controller = null;
    let timer = null;

    const onResume = () => { void check({ force: true }); };
    const onVisibility = () => { if (!documentRef.hidden) onResume(); };
    const onRealtime = (event) => { if (event.detail?.connected) onResume(); };

    const stop = () => {
        stopped = true;
        windowRef.clearInterval(timer);
        controller?.abort();
        documentRef.removeEventListener('visibilitychange', onVisibility);
        windowRef.removeEventListener('focus', onResume);
        windowRef.removeEventListener('online', onResume);
        windowRef.removeEventListener('pageshow', onResume);
        windowRef.removeEventListener('bearly:realtime-status', onRealtime);
    };

    const leaveStalePage = (signedOut) => {
        stop();

        try {
            windowRef.BearlyRealtime?.echo?.disconnect?.();
        } catch {
            // A broken transport must not prevent leaving a revoked session.
        }

        if (signedOut) {
            windowRef.location.replace(login);
        } else {
            // Another account signed in on this browser. Reload through normal
            // server authorization; never log that new account out.
            windowRef.location.reload();
        }
    };

    const check = async ({ force = false } = {}) => {
        if (stopped || (!force && documentRef.hidden)) return;

        if (inFlight) {
            // An event may arrive after an earlier request read a valid version.
            // Check once more instead of losing that revocation signal.
            checkAgain = true;
            return;
        }

        inFlight = true;
        controller = new windowRef.AbortController();
        const timeout = windowRef.setTimeout(() => controller?.abort(), CHECK_TIMEOUT_MS);

        try {
            const response = await windowRef.fetch(endpoint, {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'error',
                signal: controller.signal,
            });

            if (stopped) return;

            if (response.status === 401) {
                leaveStalePage(true);
            } else if (response.ok) {
                const payload = await response.json();
                const currentUserId = Number(payload?.data?.user_id);

                if (!stopped && payload?.data?.authenticated === true
                    && Number.isSafeInteger(currentUserId) && currentUserId > 0
                    && currentUserId !== expectedUserId) {
                    leaveStalePage(false);
                }
            }
        } catch {
            // Offline, aborted, redirected, or unavailable: retry on the next
            // poll/reconnect/resume. Do not mistake a transport error for logout.
        } finally {
            windowRef.clearTimeout(timeout);
            controller = null;
            inFlight = false;

            if (checkAgain && !stopped) {
                checkAgain = false;
                void check({ force: true });
            }
        }
    };

    documentRef.addEventListener('visibilitychange', onVisibility);
    windowRef.addEventListener('focus', onResume);
    windowRef.addEventListener('online', onResume);
    windowRef.addEventListener('pageshow', onResume);
    windowRef.addEventListener('bearly:realtime-status', onRealtime);
    timer = windowRef.setInterval(() => { void check(); }, POLL_INTERVAL_MS);
    void check();

    return { check, stop };
}

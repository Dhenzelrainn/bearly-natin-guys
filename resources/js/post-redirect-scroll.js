const STORAGE_KEY = 'bearly:post-redirect-scroll:v1';
const MAX_AGE_MS = 10 * 60 * 1000;
const SPECIALIZED_PAGE_SELECTOR = '[data-courier-run-dashboard], [data-shipping-policies-page]';

function pageKey(location) {
    return `${location.pathname}${location.search}`;
}

function scheduleFrame(windowRef, callback) {
    if (typeof windowRef.requestAnimationFrame === 'function') {
        windowRef.requestAnimationFrame(callback);
        return;
    }

    callback();
}

/**
 * Preserve the viewport for ordinary same-page form POST/redirect flows.
 * Page-specific scroll handling remains responsible for specialized workflows.
 */
export function setupPostRedirectScrollRestoration({
    documentRef = document,
    windowRef = window,
} = {}) {
    if (!documentRef || !windowRef?.location) return;

    let storage;
    let savedPosition = null;

    try {
        storage = windowRef.sessionStorage;
        try {
            savedPosition = JSON.parse(storage.getItem(STORAGE_KEY) || 'null');
        } catch {
            savedPosition = null;
        }
        storage.removeItem(STORAGE_KEY);
    } catch {
        // Scroll restoration is an enhancement; form submission remains unaffected.
    }

    const currentPage = pageKey(windowRef.location);
    const savedScrollY = Number(savedPosition?.scrollY);
    const savedScrollX = Number(savedPosition?.scrollX);
    const savedAt = Number(savedPosition?.savedAt);
    const savedAge = Date.now() - savedAt;
    const canRestore = savedPosition?.page === currentPage
        && Number.isFinite(savedScrollX)
        && savedScrollX >= 0
        && Number.isFinite(savedScrollY)
        && savedScrollY >= 0
        && Number.isFinite(savedAt)
        && savedAge >= 0
        && savedAge <= MAX_AGE_MS;

    if (canRestore && typeof windowRef.scrollTo === 'function') {
        const rootStyle = documentRef.documentElement?.style;
        const previousScrollBehavior = rootStyle?.scrollBehavior ?? '';

        if (rootStyle) rootStyle.scrollBehavior = 'auto';

        scheduleFrame(windowRef, () => {
            windowRef.scrollTo(savedScrollX, savedScrollY);
            scheduleFrame(windowRef, () => {
                if (rootStyle) rootStyle.scrollBehavior = previousScrollBehavior;
            });
        });
    }

    if (!storage) return;

    documentRef.addEventListener('submit', (event) => {
        const form = event.target;

        if (!form || String(form.tagName).toUpperCase() !== 'FORM' || event.defaultPrevented) return;

        const method = String(form.method || 'get').toLowerCase();
        const target = String(form.target || '').toLowerCase();

        if (method === 'get' || method === 'dialog' || (target !== '' && target !== '_self')) return;
        if (form.dataset?.scrollRestoration === 'off' || form.hasAttribute?.('data-no-scroll-restoration')) return;
        if (form.closest?.(SPECIALIZED_PAGE_SELECTOR)) return;

        const position = {
            page: pageKey(windowRef.location),
            scrollX: Number(windowRef.scrollX) || 0,
            scrollY: Number(windowRef.scrollY) || 0,
            savedAt: Date.now(),
        };

        const serialized = JSON.stringify(position);

        try {
            storage.setItem(STORAGE_KEY, serialized);
        } catch {
            return;
        }

        // A later window-level handler may cancel submission after this listener.
        const clearIfCanceled = () => {
            if (!event.defaultPrevented) return;

            try {
                if (storage.getItem(STORAGE_KEY) === serialized) {
                    storage.removeItem(STORAGE_KEY);
                }
            } catch {
                // A stale one-shot record is cleared on the next page load.
            }
        };

        if (typeof windowRef.queueMicrotask === 'function') {
            windowRef.queueMicrotask(clearIfCanceled);
        } else {
            Promise.resolve().then(clearIfCanceled);
        }
    });
}

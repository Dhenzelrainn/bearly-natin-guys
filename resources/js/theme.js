import { isTheme, nextTheme, resolveTheme, THEME_STORAGE_KEY } from './theme-preference.js';

const root = document.documentElement;
const systemTheme = window.matchMedia?.('(prefers-color-scheme: dark)');

function getSavedTheme() {
    try {
        return window.localStorage.getItem(THEME_STORAGE_KEY);
    } catch {
        return null;
    }
}

function syncThemeControls(theme) {
    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        const isDark = theme === 'dark';
        button.setAttribute('aria-checked', String(isDark));
        button.title = isDark ? 'Switch to light mode' : 'Switch to dark mode';

        const label = button.querySelector('[data-theme-toggle-label]');
        if (label) label.textContent = isDark ? 'Dark mode on' : 'Dark mode';
    });
}

function applyTheme(theme, { persist = false, animate = false } = {}) {
    if (!isTheme(theme)) return;

    if (animate && !window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
        root.dataset.themeTransition = 'true';
        window.setTimeout(() => delete root.dataset.themeTransition, 220);
    }

    root.dataset.theme = theme;
    root.style.colorScheme = theme;
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme === 'dark' ? '#171411' : '#432214');
    syncThemeControls(theme);

    if (persist) {
        try {
            window.localStorage.setItem(THEME_STORAGE_KEY, theme);
        } catch {
            // The current page can still use the selected theme when storage is unavailable.
        }
    }
}

const savedTheme = getSavedTheme();
applyTheme(
    resolveTheme(savedTheme, Boolean(systemTheme?.matches)),
    { persist: false, animate: false },
);

document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : event.target?.parentElement;
    const button = target?.closest('[data-theme-toggle]');
    if (!button) return;

    applyTheme(nextTheme(root.dataset.theme), { persist: true, animate: true });
});

window.addEventListener('storage', (event) => {
    if (event.key !== THEME_STORAGE_KEY) return;
    applyTheme(resolveTheme(event.newValue, Boolean(systemTheme?.matches)));
});

systemTheme?.addEventListener?.('change', (event) => {
    if (!isTheme(getSavedTheme())) applyTheme(resolveTheme(null, event.matches));
});

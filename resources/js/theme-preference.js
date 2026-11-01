export const THEME_STORAGE_KEY = 'bearly-theme';

export function isTheme(value) {
    return value === 'light' || value === 'dark';
}

export function resolveTheme(savedTheme, prefersDark = false) {
    return isTheme(savedTheme) ? savedTheme : (prefersDark ? 'dark' : 'light');
}

export function nextTheme(currentTheme) {
    return currentTheme === 'dark' ? 'light' : 'dark';
}

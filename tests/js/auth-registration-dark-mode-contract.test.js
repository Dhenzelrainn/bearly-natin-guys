import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const themeStyles = await readFile(
    new URL('../../resources/css/theme.css', import.meta.url),
    'utf8',
);
const authStyles = await readFile(
    new URL('../../resources/css/bearly-auth.css', import.meta.url),
    'utf8',
);
const pendingView = await readFile(
    new URL('../../resources/views/auth/pending.blade.php', import.meta.url),
    'utf8',
);

test('Dark auth registration summary keeps its surface, details, and Edit controls readable', () => {
    assert.match(
        themeStyles,
        /:root\[data-theme="dark"\] body\.auth-body \.application-summary\s*\{[^}]*background: var\(--theme-surface-soft\)/,
    );
    assert.match(
        themeStyles,
        /:root\[data-theme="dark"\] body\.auth-body \.review-group dt\s*\{[^}]*color: var\(--theme-muted\)/,
    );
    assert.match(
        themeStyles,
        /:root\[data-theme="dark"\] body\.auth-body \.summary-edit\s*\{[^}]*background: var\(--theme-surface\);[^}]*color: var\(--theme-accent\)/,
    );
});

test('Seller and historical Buyer application receipt stays centered and dark-mode readable', () => {
    assert.match(pendingView, /class="login-page pending-status-page"/);
    assert.match(pendingView, /class="pending-receipt"/);
    assert.match(
        authStyles,
        /\.pending-status-page > \.pending-receipt\s*\{[^}]*width: min\(100%, 720px\)/,
    );
    assert.match(
        authStyles,
        /\.pending-status-page \.pending-receipt\s*\{[^}]*background: var\(--white\);[^}]*border-color: var\(--line-soft\)/,
    );
    assert.match(
        themeStyles,
        /:root\[data-theme="dark"\] body\.auth-body\s*\{[^}]*--white: var\(--theme-surface\)/,
    );
    assert.match(
        authStyles,
        /:root\[data-theme="dark"\] \.pending-status-page \.pending-status\.is-approved\s*\{[^}]*color: var\(--theme-success\)/,
    );
});

test('Dark registration upload cards, dropzones, and file actions remain readable', () => {
    assert.match(
        themeStyles,
        /:root\[data-theme="dark"\] body\.auth-body \.upload-dropzone\s*\{[^}]*background: var\(--theme-surface\);[^}]*color: var\(--theme-muted\) !important/,
    );
    assert.match(
        themeStyles,
        /:root\[data-theme="dark"\] body\.auth-body \.upload-dropzone:hover,[\s\S]*?background: var\(--theme-surface-soft\)/,
    );
    assert.match(
        themeStyles,
        /:root\[data-theme="dark"\] body\.auth-body \.file-status__actions button\s*\{[^}]*color: var\(--theme-accent\)/,
    );
});

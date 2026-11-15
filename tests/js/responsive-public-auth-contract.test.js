import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const authCss = await readFile(new URL('../../resources/css/bearly-auth.css', import.meta.url), 'utf8');
const landingCss = await readFile(new URL('../../resources/css/landing.css', import.meta.url), 'utf8');

test('decorative auth arrow sizing does not inflate the recovery link label', () => {
    assert.match(authCss, /\.header-link span:first-child\s*\{[^}]*font-size:\s*28px;/s);
    assert.doesNotMatch(authCss, /\.header-link span\s*\{[^}]*font-size:\s*28px;/s);
});

test('narrow auth recovery headers reserve room for the brand and keep the link on one line', () => {
    assert.match(
        authCss,
        /\.auth-header:has\(\.header-link span\) \.brand img\s*\{\s*width:\s*112px;/,
    );
    assert.match(authCss, /\.header-link\s*\{[^}]*white-space:\s*nowrap;/s);
});

test('landing campaign cards can shrink below their desktop aspect-ratio width on small screens', () => {
    const narrowRules = landingCss.match(/@media\s*\(max-width:\s*420px\)\s*\{([\s\S]*?)\n\}/)?.[1];

    assert.ok(narrowRules, 'the landing page has a narrow-phone breakpoint');
    assert.match(narrowRules, /\.lp-campaign\s*\{[^}]*min-width:\s*0;[^}]*aspect-ratio:\s*auto;/s);
    assert.match(narrowRules, /\.lp-hero-copy h1 br\s*\{\s*display:\s*none;/);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const registrationScript = await readFile(
    new URL('../../resources/js/rider.js', import.meta.url),
    'utf8',
);
const themeStyles = await readFile(
    new URL('../../resources/css/theme.css', import.meta.url),
    'utf8',
);

test('Rider partner options stay filtered to the selected city and stale partners are cleared', () => {
    assert.match(
        registrationScript,
        /const eligible = Boolean\(cityCode\)[\s\S]*?option\.dataset\.cityCode === cityCode;[\s\S]*?option\.disabled = !eligible;[\s\S]*?option\.hidden = !eligible;/,
    );
    assert.match(
        registrationScript,
        /selectedPartner\.dataset\.cityCode !== cityCode[\s\S]*?logisticsPartner\.value = '';/,
    );
});

test('Rider Address step blocks when no selected Logistics partner matches the city', () => {
    assert.match(
        registrationScript,
        /if \(step === 2\) \{[\s\S]*?const selectedPartnerIsEligible = Boolean\([\s\S]*?selectedPartner\.dataset\.cityCode === cityCode[\s\S]*?if \(!selectedPartnerIsEligible\)[\s\S]*?openSearchableSelect\(city\);[\s\S]*?return false;/,
    );
    assert.match(
        registrationScript,
        /No active Logistics partner serves this municipality\. Choose another municipality to continue\./,
    );
});

test('Dark Rider and Logistics registration styles make searchable address menus readable', () => {
    assert.match(
        themeStyles,
        /:root\[data-theme="dark"\] body\.registration-body\[data-module\] \.registration-main \.registration-address-grid \.searchable-select__panel\s*\{[^}]*background: var\(--theme-surface\)/,
    );
    assert.match(
        themeStyles,
        /:root\[data-theme="dark"\] body\.registration-body\[data-module\] \.registration-main \.registration-address-grid \.searchable-select__option\s*\{[^}]*color: var\(--theme-text\)/,
    );
    assert.match(
        themeStyles,
        /\.searchable-select__option:hover,[\s\S]*?\.searchable-select__option\.is-active\s*\{[^}]*background: #382a21/,
    );
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const buyerJs = await readFile(new URL('../../resources/js/buyer.js', import.meta.url), 'utf8');
const buyerCss = await readFile(new URL('../../resources/css/buyer.css', import.meta.url), 'utf8');
const homeView = await readFile(new URL('../../resources/views/buyer/Dashboard/home.blade.php', import.meta.url), 'utf8');

test('Buyer Home drawer interactivity follows its 980px responsive breakpoint', () => {
    const menuFunction = buyerJs.match(/function menu\(open\) \{([\s\S]*?)\n    \}/)?.[1];

    assert.ok(menuFunction, 'the Buyer Home drawer menu handler exists');
    assert.match(buyerJs, /const mobileQuery = window\.matchMedia\('\(max-width:980px\)'\)/);
    assert.match(menuFunction, /if \(mobileQuery\.matches\) \{\s*sidebar\.inert = !open;\s*\}/);
});

test('desktop sidebar controls sit at the edge and use unboxed chevrons', () => {
    assert.match(buyerCss, /body:not\(\.bc\)\.bh \.sidebar-collapse-button\{\s*margin-left:auto;\s*\}/);
    assert.match(
        buyerCss,
        /body:not\(\.bc\)\.bh \.sidebar-collapse-button,\s*body:not\(\.bc\)\.bh \.sidebar-expand-button\{[^}]*border:0;[^}]*background:transparent;/s,
    );
    assert.match(homeView, /id="sidebar-collapse"[\s\S]*?aria-hidden="true">chevron_left/);
    assert.match(homeView, /id="sidebar-expand"[\s\S]*?aria-hidden="true">chevron_right/);
});

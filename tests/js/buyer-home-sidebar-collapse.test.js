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

test('desktop sidebar controls sit at the edge and the restore handle is visible', () => {
    assert.match(buyerCss, /body:not\(\.bc\)\.bh \.sidebar-collapse-button\{\s*margin-left:auto;\s*\}/);
    assert.match(
        buyerCss,
        /body:not\(\.bc\)\.bh \.sidebar-collapse-button,\s*body:not\(\.bc\)\.bh \.sidebar-expand-button\{[^}]*border:0;[^}]*background:transparent;/s,
    );
    assert.match(homeView, /id="sidebar-collapse"[\s\S]*?aria-hidden="true">keyboard_double_arrow_left/);
    assert.match(homeView, /id="sidebar-expand"[\s\S]*?aria-hidden="true">keyboard_double_arrow_right/);
    assert.match(homeView, /<div id="editorial">[\s\S]*?id="sidebar-expand"[\s\S]*?id="bearly-promo-slider"/);
    assert.match(buyerCss, /body:not\(\.bc\)\.bh #editorial\{[^}]*position:relative;/);
    assert.match(buyerCss, /\.shell\.is-sidebar-collapsed #editorial > #sidebar-expand:not\(\[hidden\]\)\{[^}]*position:fixed;[^}]*top:calc\(var\(--header\) \+ 23px\);[^}]*left:max\(0px, calc\(50vw - 964px\)\);[^}]*width:36px;[^}]*height:38px;[^}]*background:transparent;[^}]*color:#6f3f22;[^}]*box-shadow:none;[^}]*z-index:35;/s);
    assert.match(buyerCss, /\.shell\.is-sidebar-collapsed #sidebar-expand \.material-symbols-outlined\{\s*font-size:23px;\s*transform:translateX\(-8px\);/);
    assert.match(buyerCss, /\.shell\.is-sidebar-collapsed #editorial > #sidebar-expand:not\(\[hidden\]\):hover\{[^}]*background:transparent;[^}]*box-shadow:none;/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.bh:not\(\.bc\) \.shell\.is-sidebar-collapsed #editorial > #sidebar-expand:not\(\[hidden\]\)\{[^}]*color:var\(--theme-text\);/s);
});

test('desktop sidebar can be manually collapsed and the restore handle can reopen it', () => {
    assert.match(buyerJs, /const sidebar = \$\('sidebar'\)/);
    assert.match(buyerJs, /sidebar\.inert\s*=\s*collapsed/);
    assert.match(buyerJs, /sidebarCollapse\.addEventListener\('click',\s*\(\)\s*=>\s*setSidebarCollapsed\(true, true\)\)/);
    assert.match(buyerJs, /sidebarExpand\.addEventListener\('click',\s*\(\)\s*=>\s*setSidebarCollapsed\(false, true\)\)/);
    assert.match(buyerJs, /sidebarExpand\.addEventListener\('pointerenter',\s*event\s*=>\s*\{\s*if\s*\(mobileQuery\.matches\s*\|\|\s*event\.pointerType\s*!==\s*'mouse'\)\s*return;\s*setSidebarCollapsed\(false\);/);
    assert.doesNotMatch(buyerJs, /handleDesktopSidebarPointerMove|document\.addEventListener\('pointermove'/);
    assert.match(buyerCss, /body:not\(\.bc\)\.bh \.shell\{\s*transition:grid-template-columns \.28s/);
    assert.match(buyerCss, /\.shell\.is-sidebar-collapsed\{\s*grid-template-columns:0 minmax\(0,1fr\)/);
    assert.match(buyerCss, /\.shell\.is-sidebar-collapsed > \.sidebar\{[^}]*opacity:0;[^}]*transform:translateX\(-12px\)/s);
    assert.match(buyerCss, /@media\s*\(prefers-reduced-motion:reduce\)\{\s*body:not\(\.bc\)\.bh \.shell,[\s\S]*?transition:none!important/);
});

test('moving the desktop pointer into Buyer content does not hide the open sidebar', () => {
    assert.doesNotMatch(buyerJs, /sidebarHoverCloseTimer|sidebarTransitionUntil|sidebarPointerPosition|pointerIsInsideSidebar|handleDesktopSidebarPointerMove/);
    assert.doesNotMatch(buyerJs, /document\.addEventListener\('pointermove'/);
});

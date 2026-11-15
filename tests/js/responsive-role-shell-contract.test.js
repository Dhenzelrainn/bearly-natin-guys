import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const buyerCss = await readFile(new URL('../../resources/css/buyer.css', import.meta.url), 'utf8');
const sellerCss = await readFile(new URL('../../resources/css/seller.css', import.meta.url), 'utf8');
const adminCss = await readFile(new URL('../../resources/css/admin.css', import.meta.url), 'utf8');

test('Buyer mobile header places account actions and search on separate full-width rows', () => {
    assert.match(buyerCss, /body:not\(\.bc\)\.bh\{--header:160px\}/);
    assert.match(buyerCss, /body:not\(\.bc\)\.bh \.header\{grid-template-columns:32px minmax\(0,1fr\);grid-template-rows:42px auto 44px;/);
    assert.match(buyerCss, /body:not\(\.bc\)\.bh \.header-actions\{grid-column:1\/-1;grid-row:2;width:100%;min-width:0;/);
    assert.match(buyerCss, /body:not\(\.bc\)\.bh \.search\{grid-column:1\/-1;grid-row:3;width:100%\}/);
});

test('Buyer desktop search can yield space to the complete account navigation', () => {
    assert.match(buyerCss, /body:not\(\.bc\)\.bh \.search\{\s*flex:1 1 auto;\s*width:auto;\s*min-width:0;/);
});

test('Buyer account headers use a compact tablet layout before desktop controls exceed available width', () => {
    assert.match(
        buyerCss,
        /@media\s*\(min-width:\s*981px\)\s*and\s*\(max-width:\s*1100px\)\s*\{[\s\S]*?body:not\(\.bc\)\.bh \.buyer-global-header\s*\{[^}]*grid-template-rows:\s*48px 42px;/s,
    );
    assert.match(
        buyerCss,
        /@media\s*\(min-width:\s*981px\)\s*and\s*\(max-width:\s*1100px\)\s*\{[\s\S]*?\.buyer-global-header \.buyer-global-search\s*\{[^}]*grid-column:\s*1 \/ -1;[^}]*min-width:\s*0;/s,
    );
});

test('Buyer category header gives account actions their own row below 540px', () => {
    assert.match(buyerCss, /@media\s*\(max-width:\s*540px\)\s*\{\s*body\.bc\s*\{\s*--header:\s*153px;/);
    assert.match(
        buyerCss,
        /@media\s*\(max-width:\s*540px\)\s*\{[\s\S]*?body\.bc \.buyer-global-header\.bc-header \.header-actions\s*\{[^}]*grid-row:\s*2;[^}]*width:\s*100%;[^}]*gap:\s*4px;/s,
    );
    assert.match(
        buyerCss,
        /@media\s*\(max-width:\s*540px\)\s*\{[\s\S]*?\.buyer-global-header\.bc-header \.buyer-global-search\s*\{[^}]*grid-row:\s*3;[^}]*min-width:\s*0;/s,
    );
});

test('Buyer category header stays in its two-row tablet layout until the desktop actions fit', () => {
    assert.match(
        buyerCss,
        /@media\s*\(min-width:\s*981px\)\s*and\s*\(max-width:\s*1023px\)\s*\{[\s\S]*?body\.bc \.buyer-global-header\.bc-header\s*\{[^}]*grid-template-rows:\s*48px 42px;/s,
    );
    assert.match(
        buyerCss,
        /@media\s*\(min-width:\s*981px\)\s*and\s*\(max-width:\s*1023px\)\s*\{[\s\S]*?\.buyer-global-header\.bc-header \.buyer-global-search\s*\{[^}]*grid-column:\s*1 \/ -1;[^}]*min-width:\s*0;/s,
    );
    assert.match(
        buyerCss,
        /@media\s*\(min-width:\s*981px\)\s*and\s*\(max-width:\s*1023px\)\s*\{[\s\S]*?body\.bc \.bc-sidebar\s*\{[^}]*top:\s*var\(--header\);[^}]*height:\s*calc\(100dvh - var\(--header\)\);/s,
    );
});

test('Seller compact dashboard topbars can wrap without losing date controls', () => {
    const rules = sellerCss.match(/@media\s*\(max-width:\s*1100px\)\s*\{([\s\S]*?)\n\}/g)?.at(-1);

    assert.ok(rules, 'the Seller shell has a compact-width topbar breakpoint');
    assert.match(rules, /\.seller-topbar\s*\{[^}]*flex-wrap:\s*wrap;[^}]*row-gap:\s*8px;/s);
    assert.match(rules, /\.seller-profile-name,[\s\S]*?display:\s*none;/);
    assert.match(rules, /\.seller-topbar-actions\s*\{\s*margin-left:\s*auto;/);
});

test('Seller product contribution tables shrink into their own horizontal scroll area', () => {
    assert.match(
        sellerCss,
        /\.report-analysis-sheet\s*>\s*\.report-product-contribution\s*\{\s*min-width:\s*0;/,
    );
    assert.match(sellerCss, /\.report-table-wrap\s*\{[^}]*overflow-x:\s*auto;/s);
    assert.match(sellerCss, /\.report-table\s*\{[^}]*min-width:\s*690px;/s);
});

test('Admin narrow topbar moves actions to a second row before they overlap the title', () => {
    const rules = adminCss.match(/@media\s*\(max-width:\s*390px\)\s*\{([\s\S]*?)\n\}/)?.[1];

    assert.ok(rules, 'the Admin shell has a narrow-phone topbar breakpoint');
    assert.match(rules, /\.admin-topbar\s*\{[^}]*flex-wrap:\s*wrap;/s);
    assert.match(rules, /\.admin-topbar-left,[\s\S]*?flex:\s*0 0 100%;/);
    assert.match(rules, /\.admin-topbar-actions\s*\{[^}]*justify-content:\s*flex-end;[^}]*margin-left:\s*0;/s);
    assert.match(rules, /\.admin-topbar \.topbar-popover\s*\{\s*top:\s*104px;/);
});

test('Admin page heroes stack actions when the fixed sidebar narrows the content area', () => {
    assert.match(
        adminCss,
        /@media\s*\(max-width:\s*900px\)\s*\{\s*\.admin-main \.admin-content \.page-hero\s*\{[^}]*flex-direction:\s*column;/s,
    );
    assert.match(
        adminCss,
        /@media\s*\(max-width:\s*900px\)\s*\{[\s\S]*?\.admin-main \.admin-content \.page-hero \.hero-actions\s*\{[^}]*width:\s*100%;[^}]*justify-content:\s*flex-start;/s,
    );
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const toggleView = await readFile(new URL('../../resources/views/partials/theme-toggle.blade.php', import.meta.url), 'utf8');
const preloadView = await readFile(new URL('../../resources/views/partials/theme-preload.blade.php', import.meta.url), 'utf8');
const logoView = await readFile(new URL('../../resources/views/partials/theme-aware-logo.blade.php', import.meta.url), 'utf8');
const themeScript = await readFile(new URL('../../resources/js/theme.js', import.meta.url), 'utf8');
const themeCss = await readFile(new URL('../../resources/css/theme.css', import.meta.url), 'utf8');
const buyerCss = await readFile(new URL('../../resources/css/buyer.css', import.meta.url), 'utf8');
const buyerStoreView = await readFile(new URL('../../resources/views/buyer/stores/show.blade.php', import.meta.url), 'utf8');
const buyerProductDetailCss = await readFile(new URL('../../resources/css/product-detail.css', import.meta.url), 'utf8');
const checkoutCss = await readFile(new URL('../../resources/css/checkout.css', import.meta.url), 'utf8');
const profileCss = await readFile(new URL('../../resources/css/profile.css', import.meta.url), 'utf8');
const profileView = await readFile(new URL('../../resources/views/buyer/profile.blade.php', import.meta.url), 'utf8');
const myPurchasesScript = await readFile(new URL('../../resources/js/my-purchases.js', import.meta.url), 'utf8');
const myLikesScript = await readFile(new URL('../../resources/js/my-likes.js', import.meta.url), 'utf8');
const authCss = await readFile(new URL('../../resources/css/bearly-auth.css', import.meta.url), 'utf8');
const loginView = await readFile(new URL('../../resources/views/auth/login.blade.php', import.meta.url), 'utf8');
const loginDarkArt = await Promise.all([
    'bearly-login-bear-cutout.png',
    'bearly-login-truck-cutout.png',
].map((file) => readFile(new URL(`../../public/images/${file}`, import.meta.url))));
const sellerLegibilityCss = await readFile(new URL('../../resources/css/seller-legibility.css', import.meta.url), 'utf8');
const notificationCenterCss = await readFile(new URL('../../resources/css/notification-center.css', import.meta.url), 'utf8');
const sellerWaybillView = await readFile(new URL('../../resources/views/seller/fulfillment/waybills.blade.php', import.meta.url), 'utf8');
const sellerLayout = await readFile(new URL('../../resources/views/layouts/seller.blade.php', import.meta.url), 'utf8');
const riderLayout = await readFile(new URL('../../resources/views/rider/layouts/app.blade.php', import.meta.url), 'utf8');
const viteConfig = await readFile(new URL('../../vite.config.js', import.meta.url), 'utf8');
const themeCssEntries = await Promise.all([
    'buyer.css', 'seller.css', 'admin.css', 'logistics.css', 'bearly-auth.css',
    'landing.css', 'about.css', 'contact.css', 'announcement.css', 'notification-center.css',
].map((file) => readFile(new URL(`../../resources/css/${file}`, import.meta.url), 'utf8')));
const registrationApplicationHeader = await readFile(
    new URL('../../resources/views/partials/registration-application-header.blade.php', import.meta.url),
    'utf8',
);
const themeHeaders = await Promise.all([
    'resources/views/buyer/partials/buyer-header.blade.php',
    'resources/views/buyer/Dashboard/home.blade.php',
    'resources/views/layouts/seller.blade.php',
    'resources/views/layouts/admin.blade.php',
    'resources/views/layouts/auth.blade.php',
    'resources/views/layouts/buyer.blade.php',
    'resources/views/logistics/layouts/app.blade.php',
    'resources/views/rider/layouts/app.blade.php',
    'resources/views/landing-page/landingpage.blade.php',
    'resources/views/landing-page/about/about.blade.php',
    'resources/views/landing-page/contact/contact.blade.php',
    'resources/views/announcements/show.blade.php',
    'resources/views/logistics/landing/index.blade.php',
    'resources/views/logistics/applications/create.blade.php',
    'resources/views/rider/landing/index.blade.php',
    'resources/views/rider/applications/create.blade.php',
].map((file) => readFile(new URL(`../../${file}`, import.meta.url), 'utf8')));
const adminLayout = themeHeaders[3];

test('the shared toggle is a keyboard-operable accessible switch', () => {
    assert.match(toggleView, /type="button"/);
    assert.match(toggleView, /role="switch"/);
    assert.match(toggleView, /aria-checked="false"/);
    assert.match(themeScript, /aria-checked/);
});

test('theme preference is applied before page styles and synchronized across browser tabs', () => {
    assert.match(preloadView, /localStorage\.getItem\(storageKey\)/);
    assert.match(preloadView, /prefers-color-scheme: dark/);
    assert.match(themeScript, /localStorage\.setItem/);
    assert.match(themeScript, /addEventListener\('storage'/);
});

test('toggle motion has a reduced-motion fallback', () => {
    assert.match(themeCss, /prefers-reduced-motion:\s*reduce/);
    assert.match(themeCss, /theme-switch-thumb/);
});

test('standalone registration screens replace their light background in dark mode', () => {
    assert.match(themeCss, /:root\[data-theme="dark"\]\s+body\.registration-body\s*\{[^}]*background:\s*var\(--theme-page\)/s);
});

test('Rider and Logistics draft notices use readable dark-mode surfaces and text', () => {
    assert.match(themeCss, /:root\[data-theme="dark"\]\s+body\.registration-body\[data-module="rider"\]\s+\.registration-draft-notice,[\s\S]*?body\.registration-body\[data-module="logistics"\]\s+\.registration-draft-notice\s*\{[^}]*background:\s*var\(--theme-surface-soft\);[^}]*color:\s*var\(--theme-text\);/);
    assert.match(themeCss, /:root\[data-theme="dark"\]\s+body\.registration-body\[data-module="rider"\]\s+\.registration-draft-notice\s*>\s*span,[\s\S]*?body\.registration-body\[data-module="logistics"\]\s+\.registration-draft-notice\s*>\s*span\s*\{[^}]*color:\s*var\(--theme-muted\);/);
});

test('dark Logistics mode keeps settlement metrics, table headers, and topbar controls legible', () => {
    assert.match(themeCss, /body\[data-module="logistics"\]\s+\.metric-strip,/);
    assert.match(themeCss, /\.metric-strip,\s*:root\[data-theme="dark"\]\s+body\[data-module="rider"\]\s+\.metric-strip\s*\{[^}]*background:\s*var\(--theme-surface\)/s);
    assert.match(themeCss, /body\[data-module="logistics"\]\s+\.cash-custody-table\s+th\s*\{[^}]*background:\s*var\(--theme-surface-soft\)/s);
    assert.match(themeCss, /body\[data-module="logistics"\]\s+\.ops-topbar-actions\s+\.profile-button/);
    assert.match(themeCss, /body\.ops-body\s+\.button:not\(\.button-primary\)/);
    assert.match(themeCss, /body\.ops-body\s+\.tab-button\.is-active\s*\{[^}]*background:\s*#45331d/s);
    assert.match(themeCss, /body\.ops-body\s+\.quick-action\s*\{[^}]*background:\s*#3b3027/s);
    assert.match(themeCss, /body\.ops-body\s+\.zone-create-details\s*\{[^}]*background:\s*#211c18/s);
    assert.match(themeCss, /body\.ops-body\s+\.nc-row\.is-unread\s*\{[^}]*background:\s*#34271e/s);
    assert.match(themeCss, /body\.ops-body\s+\.messages-standard-page\s*\{[^}]*background:\s*#211c18 !important/s);
    assert.match(themeCss, /body\.ops-body\s+\.messages-standard-page\s+\.conversation\s*\{[^}]*background:\s*#2b241f/s);
    assert.match(themeCss, /body\.ops-body\s+\.progress-track\s*\{[^}]*background:\s*#4a3b2e/s);
    assert.match(themeCss, /body\[data-module="rider"\]\s+\.rider-availability-control\s*\{[^}]*background:\s*#3b3027/s);
    assert.match(themeCss, /body\[data-module="rider"\]\s+\.history-detail-grid\s*>\s*div\s*\{[^}]*background:\s*#30241d/s);
});

test('dark Buyer profile, notification, cart, and offers views preserve text contrast', () => {
    assert.match(themeCss, /body\.profile-page\.bh\s+\.field-row\s*>\s*span/);
    assert.match(themeCss, /body\.profile-page\.bh \.help-chat-bearly\s*\{[^}]*background:\s*#efbd71;[^}]*color:\s*#2b1d16/s);
    assert.match(themeCss, /body\.profile-page\.bh \.help-chat-bearly \.help-head-icon\s*\{[^}]*color:\s*#2b1d16;[^}]*font-size:\s*24px/s);
    assert.match(themeCss, /body\.profile-page\.bh #likes-panel \.likes-count-badge\s*\{[^}]*background:\s*#efbd71;[^}]*color:\s*#2b1d16/s);
    assert.match(themeCss, /body\.profile-page\.bh #likes-panel \.likes-continue\s*\{[^}]*color:\s*#fff8ef/s);
    assert.match(themeCss, /body\.profile-page\.bh #likes-panel \.likes-category-tab\.is-active\s*\{[^}]*background:\s*#efbd71;[^}]*color:\s*#2b1d16/s);
    assert.match(themeCss, /body\.profile-page\.bh #likes-panel \.likes-category-tab\s*\{[^}]*background:\s*#30241d;[^}]*color:\s*#f1e9e1/s);
    assert.match(themeCss, /body\.profile-page\.bh #likes-panel \.likes-sort\s*\{[^}]*border-color:\s*transparent;[^}]*background:\s*transparent/s);
    assert.match(themeCss, /body\.profile-page\.bh #likes-panel \.likes-sort select\s*\{[^}]*appearance:\s*none;[^}]*position:\s*absolute;[^}]*color:\s*transparent/s);
    assert.match(themeCss, /body\.profile-page\.bh #likes-panel \.likes-sort-current\s*\{[^}]*display:\s*block/s);
    assert.match(themeCss, /body\.profile-page\.bh #likes-panel \.likes-sort select option:checked\s*\{[^}]*background:\s*#45331d;[^}]*color:\s*#efbd71/s);
    assert.match(themeCss, /body\.profile-page\.bh #likes-panel \.like-image\s*\{[^}]*aspect-ratio:\s*1 \/ 1;[^}]*background:\s*#fff/s);
    assert.match(themeCss, /body\.profile-page\.bh #likes-panel \.like-image img\s*\{[^}]*object-fit:\s*contain/s);
    assert.match(themeCss, /body\.profile-page\.bh\s+#purchases-panel\s+\.purchases-head h1/);
    assert.match(themeCss, /body\.profile-page\.bh\s+#reviews-panel\s+\.review-item-card/);
    assert.match(themeCss, /body\.profile-page\.bh\s+#vouchers-panel\s+\.voucher-section-heading h2/);
    assert.match(themeCss, /body\.profile-page\.bh #vouchers-panel \.vouchers-head h1\s*\{[^}]*color:\s*#fff8ef/s);
    assert.match(themeCss, /body\.profile-page\.bh #vouchers-panel \.voucher-filter\s*\{[^}]*background:\s*#30241d;[^}]*color:\s*#e4c39d/s);
    assert.match(themeCss, /body\.profile-page\.bh #vouchers-panel \.voucher-filter\.active\s*\{[^}]*background:\s*#efbd71;[^}]*color:\s*#2b1d16/s);
    assert.match(themeCss, /body\.profile-page\.bh\s+\.order-seller-progress-row/);
    assert.match(themeCss, /body\.profile-page\.bh #purchases-panel \.purchase-sort\s*\{[^}]*background:\s*#30241d;[^}]*color:\s*#efbd71/s);
    assert.match(profileCss, /#purchases-panel \.purchase-sort select\{position:absolute;inset:0;z-index:2;width:100%/);
    assert.match(profileCss, /body\.profile-page\.bh \.likes-sort-current\{display:none\}/);
    assert.match(profileCss, /#purchases-panel \.purchase-sort \.material-symbols-outlined\{pointer-events:none/);
    assert.match(themeCss, /body\.profile-page\.bh #purchases-panel \.purchase-sort select\s*\{[^}]*background:\s*transparent;[^}]*color:\s*transparent/s);
    assert.match(themeCss, /body\.profile-page\.bh #purchases-panel \.purchase-sort select option:checked\s*\{[^}]*background:\s*#45331d;[^}]*color:\s*#efbd71/s);
    assert.match(profileView, /data-purchase-sort-label/);
    assert.match(profileView, /data-likes-sort-label/);
    assert.match(themeCss, /body\.profile-page\.bh #purchases-panel \.purchase-filter\s*\{[^}]*color:\s*#f1e9e1/s);
    assert.match(themeCss, /body\.profile-page\.bh #purchases-panel \.purchase-filter\.active\s*\{[^}]*color:\s*#fff8ef/s);
    assert.match(themeCss, /body\.profile-page\.bh #purchases-panel \.purchase-filter\.active \.purchase-filter-count\s*\{[^}]*color:\s*#efbd71/s);
    assert.match(themeCss, /body\.profile-page\.bh #purchases-panel \.purchases-empty a\s*\{[^}]*background:\s*#efbd71;[^}]*color:\s*#2b1d16/s);
    assert.match(myPurchasesScript, /syncSortLabel\(\);\s*sort\.addEventListener\('change'/s);
    assert.match(myLikesScript, /syncSortLabel\(\);\s*sort\.addEventListener\('change'/s);
    assert.match(themeCss, /body\.notification-center-body\s+\.nc-row\.is-unread/);
    assert.match(themeCss, /body\.cart-page\s+\.cart-main/);
    assert.match(themeCss, /body\.bh:not\(\.bc\)\s+\.live-platform-offer-card/);
    assert.match(themeCss, /body\.flash-page\s+\.flash-campaign-group/);
    assert.match(themeCss, /body\.bearly-chat-page\s+\.bearly-chat-details h3/);
    assert.match(themeCss, /body\.bearly-chat-page \.bearly-chat-thread-order:not\(\.is-dispute\)\s*\{[^}]*background:\s*#efbd71;[^}]*color:\s*#2b1d16/s);
    assert.match(themeCss, /body\.bearly-chat-page \.bearly-chat-thread-order\.is-dispute\s*\{[^}]*background:\s*#efbd71;[^}]*color:\s*#2b1d16/s);
    assert.match(themeCss, /body\.bearly-chat-page \.bearly-chat-dispute-card\s*\{[^}]*background:\s*#211c18/s);
    assert.match(themeCss, /body\.bearly-chat-page \.bearly-chat-dispute-subject\s*\{[^}]*color:\s*#fff8ef/s);
});

test('dark Admin dashboards, review dialogs, tables, and messaging use readable brown/gold surfaces', () => {
    assert.match(themeCss, /body\.admin-body\s+:is\(\.user-summary-strip, \.account-summary-strip, \.user-stats-grid, \.kpi-grid\.kpi-grid-compact\)/);
    assert.match(themeCss, /body\.admin-body\s+\.modal-card \.detail-grid\s*>\s*div/);
    assert.match(themeCss, /body\.admin-body\s+\.modal-card\s*>\s*\.modal-footer/);
    assert.match(themeCss, /body\.admin-body\s+\.admin-user-profile-information/);
    assert.match(themeCss, /body\.admin-body\s+\.admin-category-review-table tbody tr/);
    assert.match(themeCss, /body\.admin-body\s+\.messages-standard-page :is\(\.message-row\.message-them \.message-bubble/);
    assert.match(themeCss, /body\.admin-body\s+\.admin-notification-popover \.notification-row\s*\{[^}]*background:\s*#211c18/s);
});

test('dark Landing, Sign In, and Buyer/Logistics/Rider registration surfaces preserve contrast', () => {
    assert.match(themeCss, /body\.bearly-landing\s+\.lp-slide::after/);
    assert.match(themeCss, /body\.bearly-landing\s+\.lp-header::before/);
    assert.match(themeCss, /body\.bearly-landing\s+\.lp-hero-wordmark\s*\{[^}]*display:\s*none/s);
    assert.match(themeCss, /body\.auth-body\s+\.login-surface/);
    assert.match(loginView, /class="login-bear(?:\s|\")/);
    assert.match(loginView, /class="login-truck(?:\s|\")/);
    assert.match(loginView, /asset\('images\/bearly-login-bear\.png'\)/);
    assert.match(loginView, /asset\('images\/bearly-login-truck\.png'\)/);
    assert.match(loginView, /asset\('images\/bearly-login-bear-cutout\.png'\)/);
    assert.match(loginView, /asset\('images\/bearly-login-truck-cutout\.png'\)/);
    assert.match(loginView, /class="login-mobile-bear"[\s\S]*?class="login-art-dark"/);
    for (const image of loginDarkArt) {
        assert.equal(image.subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
        assert.equal(image[25], 6, 'dark cutout must be an RGBA PNG with alpha');
    }
    assert.match(authCss, /\.login-page\s*\{[^}]*background:\s*#fffdfa/s);
    assert.match(authCss, /\.login-art-dark\s*\{\s*display:\s*none/s);
    assert.match(themeCss, /body\.auth-body\s+:is\(\.login-page, \.register-page, \.pending-page, \.reset-page\)/);
    assert.match(themeCss, /body\.auth-body:has\(\.login-bear\) \.login-page\s*\{[^}]*background:\s*var\(--theme-page\)/s);
    assert.match(themeCss, /body\.auth-body\s+\.login-art-light\s*\{[^}]*display:\s*none/s);
    assert.match(themeCss, /body\.auth-body\s+\.login-bear\.login-art-dark[^}]*display:\s*block/s);
    assert.match(themeCss, /@media\s*\(max-width:\s*900px\)\s*\{[^}]*login-bear\.login-art-dark[^}]*display:\s*none/s);
    assert.match(themeCss, /@media\s*\(max-width:\s*900px\)[\s\S]*?\.login-mobile-bear \.login-art-dark\s*\{[^}]*display:\s*block/s);
    assert.match(themeCss, /body\.auth-body:has\(\.login-bear\) \.login-landscape\s*\{[^}]*display:\s*block/s);
    assert.match(themeCss, /body\.auth-body:has\(\.login-bear\) \.login-landscape\s*\{[^}]*background:\s*radial-gradient/s);
    assert.doesNotMatch(themeCss, /bearly-auth-scene\.png/);
    assert.match(themeCss, /body\.auth-body\s+\.sign-in-button/);
    assert.match(themeCss, /body\.auth-body\s+\.role-card\.selected/);
    assert.match(themeCss, /body\.auth-body\s+\.password-guidance/);
    assert.match(themeCss, /body\.registration-body\[data-module="rider"\]\s+\.registration-password-guidance/);
});

test('dark status badges keep neutral Admin states gold and semantic role states readable', () => {
    assert.match(themeCss, /body\.admin-body\s+:is\(\.status-badge, \.badge\)\.badge-neutral\s*\{[^}]*color:\s*#efbd71/s);
    assert.match(themeCss, /body\.admin-body\s+:is\(\.status-badge, \.badge\)\.badge-success/);
    assert.match(themeCss, /body\.seller-body\s+\.order-status-badge\.order-status-new/);
    assert.match(themeCss, /body\.ops-body\s+\.status-badge\s*\{[^}]*background:\s*#3b3027/s);
    assert.match(themeCss, /body\.ops-body\s+\.status-badge\.is-muted\s*\{[^}]*color:\s*#efbd71/s);
});

test('dark Seller styling covers the full workspace and preserves status and image treatments', () => {
    const sellerDarkModeCss = sellerLegibilityCss.split('22. SELLER DARK MODE')[1] ?? '';

    assert.match(sellerDarkModeCss, /:root\[data-theme="dark"\]\s+body\.seller-body\s+\.seller-content/);
    [
        '.dashboard-ref-panel', '.orders-workspace', '.returns-workspace',
        '.fulfillment-workspace', '.inventory-workspace', '.pricing-workspace',
        '.product-form-section', '.storefront-panel', '.seller-wallet-summary',
        '.report-analysis-sheet', '.settings-panel', '.message-workspace',
        '.feedback-overview',
    ].forEach((surface) => assert.ok(sellerDarkModeCss.includes(surface), `Missing Seller dark surface: ${surface}`));
    assert.match(sellerDarkModeCss, /\.fulfillment-status\.is-failed[\s\S]*?background-color:\s*#452521/);
    assert.match(sellerDarkModeCss, /\.product-media-gallery-stage:has\(img:not\(\[hidden\]\)\)[\s\S]*?background-color:\s*#fff/);
    assert.match(sellerDarkModeCss, /\.campaign-card-media img/);
    assert.match(sellerDarkModeCss, /\.messages-standard-page[\s\S]*?background:\s*var\(--seller-surface\) !important/);
    assert.match(sellerDarkModeCss, /\.tracking-table td[\s\S]*?background:\s*var\(--seller-surface\) !important/);
    assert.match(sellerDarkModeCss, /\.message-order-reference:not\(\.is-dispute\)[\s\S]*?background:\s*#efbd71/);
    assert.match(sellerDarkModeCss, /body\.seller-body \.seller-popover[\s\S]*?background:\s*var\(--seller-surface\)/);
    assert.match(sellerDarkModeCss, /body\.seller-body \.seller-nav-link\.is-active[\s\S]*?background:\s*#45331d/);
    [
        '.orders-workflow-note', '.returns-customer-product', '.case-recovery-details',
        '.handover-check', '.store-document-status', '.product-payment-methods',
        '.product-mode-option', '.product-commission-preview', '.inventory-attention-bar',
        '.quick-reply-list button', '.feedback-review-context', '.product-draft-button',
        '.seller-modal .modal-heading > button', '.storefront-promo-drop',
        '.product-editor-note', '.marketplace-request-approved-category',
        '.product-variant-images-field', '.order-search', '.order-select', '.bearly-action-items',
        '.bearly-action-next', '.bearly-action-x', '.inventory-search', '.inventory-select',
        '.workspace-search', '.product-search', '.returns-search', '.returns-select',
        '.tracking-search', '.pricing-search-control', '.pricing-select', '.message-search',
        '.pickup-date-filter', '.report-date-control', '.seller-availability',
        '.finance-ledger-toolbar > label', '.report-detail-heading > label', '.feedback-toolbar > label',
        '.product-filter-select select', '.finance-ledger-toolbar > select',
        '.report-date-range input[type="date"]', '.seller-date-control',
    ].forEach((surface) => assert.ok(sellerDarkModeCss.includes(surface), `Missing Seller dark fix: ${surface}`));
    assert.match(sellerDarkModeCss, /\.pricing-discount-table td[\s\S]*?background:\s*var\(--seller-surface\) !important/);
    assert.match(sellerDarkModeCss, /\.orders-workflow-note[\s\S]*?background-color:\s*#3a2c20 !important/);
    assert.match(sellerDarkModeCss, /\.order-filter-reset[\s\S]*?background-color:\s*#45331d !important/);
    assert.match(sellerDarkModeCss, /\.bearly-action-items[\s\S]*?background-color:\s*#3a2c20 !important/);
    assert.match(sellerDarkModeCss, /\.finance-ledger-toolbar button\s*\{[^}]*background:\s*#30241d !important/s);
    assert.match(sellerDarkModeCss, /\.report-date-range\s*\{[^}]*background:\s*transparent !important/s);
    const dashboardDarkOverrides = sellerDarkModeCss.split('Dashboard detail rows stay flat')[1] ?? '';
    assert.match(dashboardDarkOverrides, /\.dashboard-ref-earnings-breakdown > div,[\s\S]*?background:\s*transparent !important/);
    assert.match(dashboardDarkOverrides, /\.dashboard-ref-health-row > span:nth-child\(2\)[\s\S]*?color:\s*#d5c2ad !important/);
    assert.match(dashboardDarkOverrides, /\.dashboard-ref-view-link\s*\{[^}]*color:\s*#efbd71 !important/s);
    const sellerFilterOverrides = sellerDarkModeCss.split('Shared dark Seller filter controls')[1] ?? '';
    assert.ok(sellerFilterOverrides.includes('.product-search'));
    assert.ok(sellerFilterOverrides.includes('.pricing-search-control'));
    assert.match(sellerFilterOverrides, /border-color:\s*transparent !important/);
    assert.match(sellerFilterOverrides, /background-color:\s*transparent !important/);
    assert.match(sellerFilterOverrides, /:focus-within[\s\S]*?box-shadow:\s*0 0 0 2px/);
    assert.match(sellerFilterOverrides, /@media \(prefers-reduced-motion: reduce\)[\s\S]*?transition:\s*none/);
    assert.match(sellerDarkModeCss, /input\[type="file"\]::file-selector-button/);
    assert.match(sellerDarkModeCss, /input\[type="search"\]::-webkit-search-cancel-button/);
    assert.match(sellerDarkModeCss, /\.product-media-gallery-stage:has\(img:not\(\[hidden\]\)\)/);
    assert.match(sellerDarkModeCss, /\.product-media-gallery-stage:not\(:has\(img:not\(\[hidden\]\)\)\)[\s\S]*?background-color:\s*#30241d/);
    assert.match(sellerDarkModeCss, /\.store-document-status > span:first-child[\s\S]*?background-color:\s*#45331d !important/);
    assert.match(sellerDarkModeCss, /\.store-document-status > span:first-child svg[\s\S]*?stroke:\s*currentColor/);
    assert.match(sellerDarkModeCss, /\.product-status-tab[\s\S]*?box-shadow:\s*none !important/);
    assert.match(sellerDarkModeCss, /\[class\*="-card"\][\s\S]*?box-shadow:\s*none !important/);
    assert.match(sellerDarkModeCss, /\.order-tab\s*>\s*span[\s\S]*?background:\s*transparent[\s\S]*?box-shadow:\s*none/);
    assert.match(sellerDarkModeCss, /@media print/);
});

test('dark Seller Notification Center keeps unread rows brown and hover gold', () => {
    assert.match(notificationCenterCss, /body\.seller-body\s+\.nc-page--seller\s+\.nc-row\.is-unread\s*\{[^}]*background:\s*#3a2c20/s);
    assert.match(notificationCenterCss, /body\.seller-body\s+\.nc-page--seller\s+\.nc-row:hover[\s\S]*?background:\s*#5a3b20/s);
    assert.match(notificationCenterCss, /body\.seller-body\s+\.nc-page--seller\s+\.nc-search\s*\{[^}]*background:\s*#241c16/s);
    assert.match(notificationCenterCss, /body\.seller-body\s+\.nc-page--seller\s+\.nc-link\s*\{[^}]*background:\s*#30241d/s);
    assert.match(notificationCenterCss, /\.nc-link:hover[\s\S]*?background:\s*#5a3b20/s);
    assert.match(notificationCenterCss, /\.nc-search input::-webkit-search-cancel-button/);
});

test('dark notification popovers and full-page centers cover every role', () => {
    assert.match(notificationCenterCss, /body\.notification-center-body \.nc-page--buyer \.nc-head-actions \.nc-link\s*\{[^}]*border-color:\s*transparent;[^}]*background:\s*transparent/s);
    assert.match(notificationCenterCss, /@media\s*\(min-width:\s*761px\)[\s\S]*?body\.notification-center-body \.nc-page--buyer \.nc-head-actions\s*\{[^}]*margin-right:\s*24px/s);
    assert.match(themeCss, /body\.notification-center-body \.nc-page \.nc-search:focus-within\s*\{[^}]*border-color:\s*#efbd71/);
    assert.match(themeCss, /body\.notification-center-body \.nc-page \.nc-search input:focus-visible\s*\{[^}]*outline:\s*none/);
    assert.match(buyerCss, /:root\[data-theme="dark"\] body:not\(\.bc\)\.bh \.buyer-notification-popover/);
    assert.match(buyerCss, /\.buyer-notification-item\.unread[\s\S]*?background:\s*#34271e/);
    assert.match(sellerLegibilityCss, /body\.seller-body \.seller-popover \.notification-row\.unread[\s\S]*?background:\s*#34271e/);
    assert.match(themeCss, /body\.admin-body \.admin-notification-popover \.notification-row\.unread[\s\S]*?background:\s*#34271e/);
    assert.match(themeCss, /body\.ops-body \.notification-popover \.notification-row\.unread[\s\S]*?background:\s*#34271e/);
    ['.nc-page--admin', '.nc-page--logistics', '.nc-page--rider'].forEach((rolePage) => {
        assert.ok(notificationCenterCss.includes(rolePage), `Missing dark Notification Center coverage for ${rolePage}`);
    });
    assert.match(notificationCenterCss, /\.nc-row\.is-unread\s*\{[^}]*background:\s*#34271e/s);
});

test('Buyer discovery and product-review ratings use readable dark-mode colors', () => {
    assert.match(buyerCss, /\.discover \.discovery-card \.product-copy h3[\s\S]*?color:\s*#fff8ef !important/);
    assert.match(buyerCss, /\.discover \.discovery-card \.discovery-meta b[\s\S]*?color:\s*#efbd71 !important/);
    assert.match(buyerCss, /\.card-rating b\s*\{[^}]*color:\s*#efbd71 !important/s);
    assert.match(buyerCss, /\.pd-review-copy > strong[\s\S]*?color:\s*#fff8ef/);
    assert.match(buyerProductDetailCss, /body\.product-detail-page \.detail-review-copy > strong[\s\S]*?color:\s*#fff8ef/);
    assert.match(buyerProductDetailCss, /body\.product-detail-page \.detail-review-meta \.detail-review-stars[\s\S]*?color:\s*#efbd71/);
});

test('Buyer Search Results use readable light-on-dark labels and keep gold pricing', () => {
    assert.match(buyerCss, /:root\[data-theme="dark"\]\s+body\.buyer-search-page\s+\.buyer-search-heading h1\s*\{[^}]*color:\s*#fff8ef/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\]\s+body\.buyer-search-page\s+\.buyer-search-eyebrow\s*\{[^}]*color:\s*#efbd71/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\]\s+body\.buyer-search-page\s+\.buyer-search-heading > p:last-child\s*\{[^}]*color:\s*#c0b2a5/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\]\s+body\.buyer-search-page\s+\.buyer-search-heading > p:last-child strong\s*\{[^}]*color:\s*#fff8ef/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\]\s+body\.buyer-search-page\s+\.buyer-search-section-heading h2\s*\{[^}]*color:\s*#fff8ef/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\]\s+body\.buyer-search-page\s+\.buyer-search-section-heading > span\s*\{[^}]*color:\s*#c0b2a5/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\]\s+body\.buyer-search-page\s+\.buyer-search-store-copy strong\s*\{[^}]*color:\s*#fff8ef/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\]\s+body\.buyer-search-page\s+\.buyer-search-store-copy > span\s*\{[^}]*color:\s*#c0b2a5/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\]\s+body\.buyer-search-page\s+\.buyer-search-store-link\s*\{[^}]*color:\s*#efbd71/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\]\s+body\.buyer-search-page\s+\.buyer-search-product-copy h3 a\s*\{[^}]*color:\s*#fff8ef/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\]\s+body\.buyer-search-page\s+\.buyer-search-product-copy > p\s*\{[^}]*color:\s*#c0b2a5/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\]\s+body\.buyer-search-page\s+\.buyer-search-seller\s*\{[^}]*color:\s*#c0b2a5/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\]\s+body\.buyer-search-page\s+\.buyer-search-price\s*\{[^}]*color:\s*#efbd71/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\]\s+body\.buyer-search-page\s+\.buyer-search-price-line\s+\.buyer-price-badge\s*\{[^}]*background:\s*#45331d;[^}]*color:\s*#efbd71/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\]\s+body\.buyer-search-page\s+\.buyer-search-price-line\s+\.buyer-price-campaign\s*\{[^}]*color:\s*#e4c39d/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\]\s+body\.buyer-search-page\s+\.buyer-search-pagination\s*\{[^}]*color:\s*#c0b2a5/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\]\s+body\.buyer-search-page\s+\.buyer-search-empty h3\s*\{[^}]*color:\s*#fff8ef/s);
    assert.match(buyerCss, /\.buyer-search-page \.buyer-search-product-image img\{[^}]*object-fit:cover/);
    assert.match(buyerCss, /\.buyer-search-page \.buyer-search-store-logo img\{[^}]*object-fit:cover/);
});

test('Buyer Store page uses warm dark surfaces in dark mode while preserving light mode and images', () => {
    assert.match(buyerStoreView, /<body class="bh store-page">/);

    // Light-mode inline page styles remain exactly as authored.
    assert.match(buyerStoreView, /\.store-hero\s*\{[^}]*background:#fffdfa/s);
    assert.match(buyerStoreView, /\.store-stats > div\s*\{[^}]*background:#fffaf4/s);
    assert.match(buyerStoreView, /\.store-meta-item\s*\{[^}]*background:#fffaf4/s);
    assert.match(buyerStoreView, /\.store-promo\s*\{[^}]*background:#fffdfa/s);
    assert.match(buyerStoreView, /\.store-product\s*\{[^}]*background:#fffdfa/s);
    assert.match(buyerStoreView, /\.store-product-variant\s*\{[^}]*background:#fffdfa/s);

    // Dark-mode hero, stats, meta cards, promotions, and product bodies.
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.store-page\s*\{[^}]*background:\s*var\(--theme-page\)/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.store-page \.store-hero\s*\{[^}]*background:\s*var\(--theme-surface\)/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.store-page \.store-stats > div,[\s\S]*?\.store-meta-item\s*\{[^}]*background:\s*var\(--theme-surface-soft\)/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.store-page \.store-promo,[\s\S]*?\.store-product\s*\{[^}]*background:\s*var\(--theme-surface\)/s);

    // Existing dark-mode copy rules remain intact.
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.store-page \.store-about h2,[\s\S]*?\.store-promotions h2\s*\{[^}]*color:\s*#fff8ef/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.store-page \.store-about-panel p,[\s\S]*?\.store-policy-list dd\s*\{[^}]*color:\s*#c0b2a5/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.store-page \.store-products-heading > span,[\s\S]*?\.store-pagination\s*\{[^}]*color:\s*#c0b2a5/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.store-page \.store-about-panel,[\s\S]*?\.store-toolbar\s*\{[^}]*background:\s*var\(--theme-surface\)/s);

    // Primary and secondary actions, controls, and disabled states stay legible.
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.store-page \.store-shop-actions a\s*\{[^}]*background:\s*#6f4a2e;[^}]*color:\s*#fff8ef/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.store-page \.store-shop-actions button\s*\{[^}]*background:\s*#3b2c21;[^}]*color:\s*#fff8ef/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.store-page \.store-product-add\s*\{[^}]*background:\s*#6f4a2e;[^}]*color:\s*#fff/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.store-page \.store-product-add:disabled\s*\{[^}]*background:\s*#3a312a/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.store-page \.store-product-variant\s*\{[^}]*background:\s*var\(--theme-input\);[^}]*color:\s*var\(--theme-text\)/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.store-page \.store-product \.buyer-price-badge\s*\{[^}]*background:\s*#45331d;[^}]*color:\s*#efbd71/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.store-page \.store-product-subcategory\s*\{[^}]*background:\s*#45331d;[^}]*color:\s*#e4c39d/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.store-page \.store-toolbar input,[\s\S]*?\.store-toolbar select\s*\{[^}]*background:\s*var\(--theme-input\)/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.store-page \.store-toolbar input,[\s\S]*?color-scheme:\s*dark/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.store-page \.store-product-variant\s*\{[^}]*color-scheme:\s*dark/s);

    // Native select popups (sort and variant) use the dark store palette instead of a white list.
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.store-page select option\s*\{[^}]*background:\s*#211c18;[^}]*color:\s*#f1e9e1/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.store-page select option:checked,[\s\S]*?select option:hover\s*\{[^}]*background:\s*#45331d;[^}]*color:\s*#efbd71/s);

    // The closed native select still paints a white arrow segment, so both Store
    // selects suppress native chrome and draw their own warm caret on the dark
    // input surface while keeping the border and the gold focus ring.
    const storeSelectCaretCss = buyerCss.split('The closed native control keeps painting')[1]?.split('/* Native <option>')[0] ?? '';
    assert.match(storeSelectCaretCss, /:root\[data-theme="dark"\] body\.store-page \.store-toolbar select,\s*:root\[data-theme="dark"\] body\.store-page \.store-product-variant\s*\{/s);
    assert.match(storeSelectCaretCss, /-webkit-appearance:\s*none;\s*-moz-appearance:\s*none;\s*appearance:\s*none;/s);
    assert.match(storeSelectCaretCss, /padding-right:\s*32px/);
    assert.match(storeSelectCaretCss, /background-image:\s*url\("data:image\/svg\+xml,/);
    assert.match(storeSelectCaretCss, /background-repeat:\s*no-repeat;\s*background-position:\s*right 10px center;\s*background-size:\s*12px/s);
    assert.match(storeSelectCaretCss, /stroke='%23d3ad88'/);
    assert.doesNotMatch(storeSelectCaretCss, /stroke='%23fff(?:fff)?'/i);
    assert.doesNotMatch(storeSelectCaretCss, /background(?:-color)?:\s*#fff/i);
    assert.match(storeSelectCaretCss, /:root\[data-theme="dark"\] body\.store-page \.store-toolbar select:focus-visible,\s*:root\[data-theme="dark"\] body\.store-page \.store-product-variant:focus-visible\s*\{[^}]*outline:\s*2px solid #efbd71/s);
    assert.match(storeSelectCaretCss, /:focus-visible[^{]*\{[^}]*stroke='%23efbd71'/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.store-page \.store-empty\s*\{[^}]*background:\s*var\(--theme-surface\)/s);
    assert.match(buyerCss, /:root\[data-theme="dark"\] body\.store-page \.store-pagination a\s*\{[^}]*background:\s*var\(--theme-surface\)/s);

    // Empty image stages darken, but real artwork keeps cover-fit and no filters.
    assert.match(buyerStoreView, /\.store-product img, \.store-product \.store-product-image\s*\{[^}]*object-fit:cover[^}]*background:#eee5da/s);
    const storeDarkCss = buyerCss.split('Buyer Store page:')[1] ?? '';
    assert.match(storeDarkCss, /\.store-product \.store-product-image\s*\{[^}]*background:\s*var\(--theme-surface-soft\)/s);
    assert.doesNotMatch(storeDarkCss, /(?:store-product|store-promo|store-avatar|store-banner)[^{]*\{[^}]*filter:/s);
    assert.doesNotMatch(storeDarkCss, /(?:store-product|store-promo|store-avatar|store-banner)[^{]*\{[^}]*object-fit:/s);
});

test('Finance and Rider navigation use a wallet icon instead of the card-shaped variant', () => {
    assert.match(sellerLayout, /\['key'\s*=>\s*'finance',[^\]]*'icon'\s*=>\s*'wallet'/s);
    assert.match(riderLayout, /data-lucide="wallet"[^>]*><\/i><span>Earnings/);
    assert.match(adminLayout, /'label'\s*=>\s*'Seller Payments',[\s\S]*?'icon'\s*=>\s*'wallet'/);
});

test('Seller Waybills search clear affordance uses a visible gold X', () => {
    const sellerDarkModeCss = sellerLegibilityCss.split('22. SELLER DARK MODE')[1] ?? '';
    assert.match(sellerDarkModeCss, /\.waybills-page \.waybill-toolbar input\[type="search"\]::-webkit-search-cancel-button[\s\S]*?mask-image:/);
    assert.match(sellerWaybillView, /Search order, item, SKU, waybill, courier, parcel, destination, or pickup/);
});

test('dark Admin legacy surfaces stay dark and category-review actions avoid a forced gold fill', () => {
    assert.match(themeCss, /body\.admin-body\s+\.page-hero\s*\{[^}]*background:\s*var\(--theme-surface\)/s);
    assert.match(themeCss, /body\.admin-body\s+\.admin-category-review-summary\s*\{[^}]*background:\s*#30241d/s);
    assert.match(themeCss, /body\.admin-body\s+\.announcement-post\s*\{[^}]*background:\s*#30241d/s);
    assert.match(themeCss, /body\.admin-body\s+\.finance-payout-step\s*\{[^}]*background:\s*#3a2c20/s);
    assert.match(themeCss, /body\.admin-body\s+\.finance-withdrawal-summary,[\s\S]*?background:\s*var\(--theme-surface\)/);
    assert.match(themeCss, /body\.admin-body\s+\.money-input\s*\{[^}]*background:\s*var\(--theme-input\)/s);
    assert.match(themeCss, /body\.admin-body\s+\.report-filter-summary\s*\{[^}]*background:\s*#2b241f/s);
    assert.match(themeCss, /body\.admin-body\s+\.admin-detail-grid\s*>\s*div\s*\{[^}]*background:\s*#30241d/s);
    assert.match(themeCss, /body\.admin-body\s+\.modal-card \.detail-note\s*\{[^}]*background:\s*#30241d/s);
    assert.match(themeCss, /body\.admin-body\s+\.security-callout\s*\{[^}]*background:\s*#30241d/s);
    assert.match(themeCss, /body\.admin-body\s+\.registration-action-menu\s*\{[^}]*background:\s*#3b3027/s);
    assert.match(themeCss, /body\.admin-body\s+\.returns-refunds-page\s+\.return-refund-summary/);
    assert.match(themeCss, /body\.admin-body\s+\.returns-refunds-page \.return-payout-disclosure-trigger\s*\{[^}]*background:\s*#45331d/s);
    assert.match(themeCss, /body\.admin-body\s+\.returns-refunds-page \.return-state-check\s*\{[^}]*background:\s*#1e3828/s);
});

test('Buyer dashboard, catalog, profile, and chat each receive page-specific dark surfaces', () => {
    assert.match(themeHeaders[1], /partials\.theme-toggle/);
    assert.match(themeCss, /body\.bc\s+#bc-main\s*\{[^}]*background:\s*var\(--theme-page\)/s);
    assert.match(themeCss, /body\.bc\s+#bc-main\s+:is\(\.sorts button, \.views button, \.saved-filter\)/);
    assert.match(themeCss, /body\.bh:not\(\.bc\)\s+\.home-trending-shell \.top-ranking-tabs\s*\{[^}]*background:\s*#30241d/s);
    assert.match(themeCss, /body\.bh:not\(\.bc\)\s+\.home-trending-shell \.top-ranking-tabs button\.is-active\s*\{[^}]*background:\s*#efbd71;[^}]*color:\s*#2b1d16/s);
    assert.match(themeCss, /body\.bh:not\(\.bc\)\s+\.discover \.section-heading h2\s*\{[^}]*color:\s*#fff8ef/s);
    assert.match(themeCss, /body\.bh:not\(\.bc\)\.buyer-campaign-page \.campaign-back\s*\{[^}]*color:\s*#fff8ef/s);
    assert.match(themeCss, /body\.bh:not\(\.bc\)\.buyer-campaign-page \.campaign-products \.buyer-search-section-heading h2\s*\{[^}]*color:\s*#fff8ef/s);
    assert.match(buyerCss, /\.buyer-campaign-page \.campaign-back\{[^}]*border:0[^}]*background:transparent/);
    assert.match(buyerCss, /\.buyer-campaign-page \.campaign-back:hover\{[^}]*text-decoration:none/);
    assert.match(themeCss, /body\.bh:not\(\.bc\)\.buyer-campaign-page \.campaign-back\s*\{[^}]*border-color:\s*transparent;[^}]*background:\s*transparent/s);
    assert.match(themeCss, /body\.profile-page\.bh\s*\{[^}]*--profile-ink:\s*var\(--theme-text\)/s);
    assert.match(themeCss, /body\.bearly-chat-page\s+\.bearly-chat-page-shell/);
    assert.match(themeCss, /body\.bh:not\(\.bc\)\s+\.home-campaign-showcase/);
});

test('the theme switch stays horizontal in role navigation headers', () => {
    assert.match(themeCss, /body\.bh:not\(\.bc\)\s+\.header-actions\s*>\s*button\.theme-switch[\s\S]*?flex-direction:\s*row/);
    assert.match(themeCss, /body\.bc\s+\.bc-header nav\s*>\s*button\.theme-switch/);
});

test('Buyer ranking products use one white square image stage in light and dark mode', () => {
    assert.match(buyerCss, /\.home-ranking-product \.top-product-image\{[^}]*aspect-ratio:1\/1[^}]*background:#fff/);
    assert.match(buyerCss, /\.home-ranking-product \.top-product-image img\{[^}]*object-fit:contain/);
    assert.match(themeCss, /body\.bh:not\(\.bc\)\s+\.home-ranking-product\s+\.top-product-image\s*\{[^}]*background:\s*#fff/s);
});

test('dark Product Detail and Checkout use dark surfaces with readable white text', () => {
    assert.match(buyerProductDetailCss, /:root\[data-theme="dark"\]\s+body\.product-detail-page\s+\.detail-card\s*\{[^}]*background:\s*var\(--theme-surface\)/s);
    assert.match(buyerProductDetailCss, /body\.product-detail-page \.product-back-link\{[^}]*border:0[^}]*background:transparent/);
    assert.match(buyerProductDetailCss, /:root\[data-theme="dark"\]\s+body\.product-detail-page \.product-back-link\s*\{[^}]*border-color:\s*transparent;[^}]*background:\s*transparent/s);
    assert.match(buyerProductDetailCss, /:root\[data-theme="dark"\]\s+body\.product-detail-page\s+\.detail-info h1,[\s\S]*?color:\s*#fff8ef/s);
    assert.match(buyerProductDetailCss, /body\.product-detail-page \.detail-primary-image\{[^}]*background:#fff/);
    assert.match(checkoutCss, /:root\[data-theme="dark"\]\s+body\.checkout-page\s*\{[^}]*background:\s*var\(--theme-page\)[^}]*color:\s*#f1e9e1/s);
    assert.match(checkoutCss, /:root\[data-theme="dark"\]\s+body\.checkout-page \.checkout-card\s*\{[^}]*background:\s*var\(--theme-surface\)[^}]*color:\s*#f1e9e1/s);
    assert.match(checkoutCss, /:root\[data-theme="dark"\]\s+body\.checkout-page \.seller-options[\s\S]*?background:\s*var\(--theme-surface-soft\)/);
    assert.match(checkoutCss, /:root\[data-theme="dark"\]\s+body\.checkout-page \.message-box input,[\s\S]*?\.checkout-voucher-fields select,[^}]*color:\s*#fff8ef/s);
    assert.match(checkoutCss, /:root\[data-theme="dark"\]\s+body\.checkout-page \.checkout-product-main img\s*\{[^}]*background:\s*#fff/s);
});

test('dark mode uses the official dark logo variant instead of recoloring the light logo', () => {
    assert.match(logoView, /bearly-logo\.png/);
    assert.match(logoView, /bearly-logo-2\.png/);
    assert.match(themeCss, /theme-logo-light/);
    assert.match(themeCss, /theme-logo-dark/);
    assert.doesNotMatch(themeCss, /filter:\s*brightness\(0\)\s*invert\(1\)/);
});

test('independent page styles and shared headers load the theme system', () => {
    assert.match(viteConfig, /resources\/js\/theme\.js/);
    themeCssEntries.forEach((css) => assert.match(css, /@import\s+["']\.\/theme\.css["']/));
    themeHeaders.forEach((view) => {
        assert.match(view, /partials\.theme-toggle|partials\.registration-application-header/);
    });
    assert.match(registrationApplicationHeader, /partials\.theme-toggle/);
});

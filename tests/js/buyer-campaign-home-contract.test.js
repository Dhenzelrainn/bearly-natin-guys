import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const read = relativePath => readFileSync(fileURLToPath(new URL(relativePath, import.meta.url)), 'utf8');
const homeView = read('../../resources/views/buyer/Dashboard/home.blade.php');
const campaignPartial = read('../../resources/views/buyer/partials/home-campaigns.blade.php');
const buyerCss = read('../../resources/css/buyer.css');
const promoCss = read('../../resources/css/bearly-promo-slider.css');
const promoJs = read('../../resources/js/bearly-promo-slider.js');
const themeCss = read('../../resources/css/theme.css');

test('Buyer campaign banners lead the hero and have matching indexed navigation dots', () => {
    assert.match(homeView, /@foreach\s*\(\$carouselCampaigns\s+as\s+\$carouselCampaign\)/);
    assert.match(homeView, /data-hero-campaign-id="\{\{\s*\$carouselCampaign\['id'\]\s*\}\}"/);
    assert.match(homeView, /data-slide="\{\{\s*\$loop->index\s*\}\}"/);
    assert.match(homeView, /data-slide="\{\{\s*count\(\$carouselCampaigns\)\s*\+\s*1\s*\}\}"/);
    assert.match(homeView, /href="\{\{\s*route\('campaigns\.show',\s*\$carouselCampaign\['id'\]\)\s*\}\}"/);
    assert.ok(homeView.indexOf('data-hero-campaign-id=') < homeView.indexOf('BEARLY PICKS'));
    assert.match(homeView, /has-carousel-campaigns/);
    assert.match(promoJs, /setInterval\(advance,\s*AUTO_DELAY\)/);
    assert.match(promoJs, /slide\.inert\s*=\s*!active/);
    assert.match(promoJs, /prev\?\.addEventListener\('click'/);
    assert.match(promoJs, /next\?\.addEventListener\('click'/);
    assert.match(homeView, /class="bearly-slider-arrow bearly-slider-prev"/);
    assert.match(homeView, /class="bearly-slider-arrow bearly-slider-next"/);
});

test('Campaigns & Offers cards pair their banner with a compact campaign details strip', () => {
    assert.match(campaignPartial, /Campaigns\s*&amp;\s*Offers/);
    assert.match(campaignPartial, /<img[\s\S]*?class="home-campaign-banner"[\s\S]*?alt="\{\{\s*\$campaign\['banner_title'\]/);
    assert.match(campaignPartial, /route\('campaigns\.show',\s*\$campaign\['id'\]\)/);
    assert.match(campaignPartial, /class="home-campaign-title-row"><strong>[\s\S]*?class="home-campaign-type">\{\{\s*\$campaign\['funding_label'\]\s*\}\}[\s\S]*?class="home-campaign-date"/);
    assert.match(campaignPartial, /class="home-campaign-title-row"><strong>\{\{\s*\$campaign\['name'\]\s*\}\}/);
    assert.match(campaignPartial, /class="home-campaign-date"[\s\S]*?calendar_month[\s\S]*?starts_on_label[\s\S]*?ends_on_label/);
    assert.match(campaignPartial, /class="home-campaign-cta"[\s\S]*?View Details/);
    assert.doesNotMatch(campaignPartial, /home-campaign-(?:status|description|facts)/);
    assert.doesNotMatch(homeView, /include\('buyer\.partials\.live-offers'/);
    assert.doesNotMatch(homeView, /BEARLY100|More finds, less checkout stress/);
    assert.match(homeView, /class="sidebar-perk-row" href="\{\{\s*url\('\/profile\?voucher=shipping#vouchers'\)\s*\}\}"[^>]*aria-label="Open shipping vouchers in My Vouchers"/);
    assert.match(homeView, /class="sidebar-perk-row" href="\{\{\s*url\('\/profile#vouchers'\)\s*\}\}"[^>]*aria-label="Open My Vouchers"/);
    assert.match(homeView, /class="bearly-promo-campaign-link"[\s\S]*?class="bearly-promo-campaign-image"/);
    assert.doesNotMatch(homeView, /bearly-promo-featured-(?:copy|overlay|meta|cta)/);
});

test('campaign art and cards follow responsive reference proportions', () => {
    assert.match(buyerCss, /\.home-campaign-track\{display:grid;grid-template-columns:repeat\(3,minmax\(0,1fr\)\)/);
    assert.match(buyerCss, /@media\s*\(max-width:1100px\)\{[\s\S]*?\.home-campaign-track\{grid-template-columns:repeat\(2,minmax\(0,1fr\)\)\}/);
    assert.match(buyerCss, /@media\s*\(max-width:720px\)\{[\s\S]*?\.home-campaign-track,\.home-campaign-track\.has-two-campaigns\{grid-template-columns:1fr/);
    assert.match(buyerCss, /\.home-campaign-banner\{[^}]*aspect-ratio:2\.3\/1;[^}]*object-fit:cover/s);
    assert.match(buyerCss, /\.home-campaign-track\.has-one-campaign\{/);
    assert.match(buyerCss, /\.home-campaign-track\.has-two-campaigns\{/);
    assert.match(buyerCss, /\.home-campaign-card\[hidden\]\{display:none!important\}/);
    assert.match(buyerCss, /\.home-campaign-showcase\{margin:14px 0 0/);
    assert.match(buyerCss, /\.home-campaign-meta\{display:grid;[^}]*grid-template-columns:minmax\(0,1fr\) auto/);
    assert.match(buyerCss, /\.home-campaign-card\{[^}]*border:1px solid #e8dfd7;border-radius:14px/);
    assert.match(buyerCss, /\.home-campaign-meta\{display:grid;[^}]*background:#fbf7f1/);
    assert.match(buyerCss, /\.home-campaign-meta\{display:grid;[^}]*align-items:center/);
    assert.match(buyerCss, /\.home-campaign-copy\{display:grid;[^}]*gap:3px/);
    assert.match(buyerCss, /\.home-campaign-date\{display:inline-flex;[^}]*color:#6e6259/);
    assert.match(buyerCss, /\.home-campaign-title-row strong\{[^}]*color:#30241d/);
    assert.match(buyerCss, /body:not\(\.bc\)\.bh \.home-campaign-cta\{display:inline-flex;[^}]*background:#4b3428;[^}]*color:#fff/);
    assert.match(themeCss, /:root\[data-theme="dark"\] body\.bh:not\(\.bc\) \.home-campaign-cta\s*\{[^}]*background:\s*#4b3428;[^}]*color:\s*#fff8ef/s);
    assert.match(themeCss, /:root\[data-theme="dark"\] body\.bh:not\(\.bc\) \.home-campaign-meta\s*\{[^}]*background:\s*var\(--theme-surface\);[^}]*color:\s*var\(--theme-text\)/s);
    assert.match(buyerCss, /body:not\(\.bc\)\.bh main\{padding:24px 32px 14px;overflow:hidden\}/);
    assert.match(buyerCss, /@media\s*\(max-width:1300px\)\s*\{[\s\S]*?body:not\(\.bc\)\.bh main\{padding-inline:24px\}/);
    assert.match(buyerCss, /@media\s*\(max-width:980px\)\s*\{[\s\S]*?body:not\(\.bc\)\.bh main\{padding:24px 24px 14px\}/);
    assert.match(buyerCss, /@media\s*\(max-width:720px\)\s*\{[\s\S]*?body:not\(\.bc\)\.bh main\{padding:20px 20px 14px\}/);
    const campaignBannerStyles = promoCss.slice(promoCss.indexOf('/* All Buyer-home slides share one wide 3:1 frame on desktop. */'));
    const campaignImageStyles = campaignBannerStyles.match(/\.bearly-promo-campaign-image\{([^}]*)\}/s)?.[1] ?? '';
    assert.match(campaignBannerStyles, /@media\s*\(min-width:1201px\)\s*\{[\s\S]*?\.bearly-promo-slider\{[^}]*aspect-ratio:3\s*\/\s*1;[^}]*min-height:0;/s);
    assert.match(campaignBannerStyles, /\.bearly-promo-slider \.bearly-promo-slide\{[^}]*position:absolute;[^}]*inset:0;[^}]*min-height:0;/s);
    assert.match(campaignBannerStyles, /\.bearly-promo-campaign-link\{[^}]*position:absolute;[^}]*inset:0;/s);
    assert.match(campaignImageStyles, /width:100%/);
    assert.match(campaignImageStyles, /height:100%/);
    assert.match(campaignImageStyles, /object-fit:contain/);
    assert.match(campaignBannerStyles, /@media\s*\(min-width:1201px\)\s*\{[\s\S]*?\.bearly-promo-campaign-image\{[^}]*object-fit:cover/s);
    assert.doesNotMatch(campaignBannerStyles, /height:360px/);
    assert.match(promoCss, /@media\s*\(max-width:720px\)\s*\{[\s\S]*?\.bearly-promo-slider,[\s\S]*?\.bearly-promo-slide\{min-height:320px\}/);
    assert.match(promoCss, /body:not\(\.bc\)\.bh \.bearly-slider-arrow\{[\s\S]*?opacity:0;[\s\S]*?pointer-events:none/s);
    assert.match(promoCss, /\.bearly-promo-slider:hover \.bearly-slider-arrow,[\s\S]*?\.bearly-promo-slider:focus-within \.bearly-slider-arrow[\s\S]*?opacity:1/s);
    assert.match(promoCss, /\.bearly-promo-copy\{width:min\(590px,54%\);padding:30px 44px 36px 68px\}/);
});

test('previous/next promo buttons change the active slide', () => {
    const makeElement = () => {
        const listeners = new Map();
        const classes = new Set();
        const attributes = new Map();

        return {
            attributes,
            classList: {
                add: name => classes.add(name),
                remove: name => classes.delete(name),
                contains: name => classes.has(name),
                toggle: (name, force) => {
                    if (force === undefined ? !classes.has(name) : force) classes.add(name);
                    else classes.delete(name);
                    return classes.has(name);
                },
            },
            listeners,
            addEventListener: (name, listener) => listeners.set(name, listener),
            setAttribute: (name, value) => attributes.set(name, value),
        };
    };

    const slides = [makeElement(), makeElement(), makeElement()];
    const dots = [makeElement(), makeElement(), makeElement()];
    const previous = makeElement();
    const next = makeElement();
    const slider = makeElement();
    slider.querySelectorAll = selector => selector === '.bearly-promo-slide' ? slides : dots;
    slider.querySelector = selector => selector === '.bearly-slider-prev' ? previous : next;
    slider.contains = () => false;
    slider.offsetWidth = 1;

    const documentListeners = new Map();
    const fakeDocument = {
        hidden: false,
        getElementById: () => slider,
        addEventListener: (name, listener) => documentListeners.set(name, listener),
    };
    const fakeWindow = { matchMedia: () => ({ matches: true }) };
    const runSlider = new Function('document', 'window', 'setInterval', 'clearInterval', promoJs);

    runSlider(fakeDocument, fakeWindow, () => 1, () => {});
    documentListeners.get('DOMContentLoaded')();
    assert.equal(slides[0].classList.contains('is-active'), true);

    next.listeners.get('click')();
    assert.equal(slides[1].classList.contains('is-active'), true);
    assert.equal(dots[1].attributes.get('aria-selected'), 'true');

    previous.listeners.get('click')();
    assert.equal(slides[0].classList.contains('is-active'), true);
    assert.equal(dots[0].attributes.get('aria-selected'), 'true');
});

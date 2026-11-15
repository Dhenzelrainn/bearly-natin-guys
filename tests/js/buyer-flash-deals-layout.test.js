import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const homeView = await readFile(new URL('../../resources/views/buyer/Dashboard/home.blade.php', import.meta.url), 'utf8');
const flashDealsView = await readFile(new URL('../../resources/views/buyer/flash-deals.blade.php', import.meta.url), 'utf8');
const salesIndicatorPartial = await readFile(new URL('../../resources/views/buyer/partials/campaign-sales-indicator.blade.php', import.meta.url), 'utf8');
const buyerCss = await readFile(new URL('../../resources/css/buyer.css', import.meta.url), 'utf8');
const themeCss = await readFile(new URL('../../resources/css/theme.css', import.meta.url), 'utf8');
const buyerController = await readFile(new URL('../../app/Http/Controllers/BuyerController.php', import.meta.url), 'utf8');

test('homepage Flash Deals places its countdown in the aligned section heading without an extra campaign panel', () => {
    const titleHeader = homeView.match(/<div class="flash-deals-title">([\s\S]*?)<\/div>/)?.[1];
    const sectionHeader = homeView.match(/<div class="flash-deals-heading">([\s\S]*?)<div class="home-flash-campaigns">/)?.[1];
    const campaignHeader = homeView.match(/<header class="home-flash-campaign-heading">([\s\S]*?)<\/header>/)?.[1];
    const darkCampaignSurfaceRule = themeCss.match(/:root\[data-theme="dark"\] body\.bh:not\(\.bc\) \.sidebar,[\s\S]*?\{[^}]*\}/)?.[0];

    assert.ok(titleHeader, 'the homepage Flash Deals title header exists');
    assert.ok(sectionHeader, 'the complete Flash Deals section header exists');
    assert.match(titleHeader, /<h2 id="home-flash-title">Flash Deals<\/h2>[\s\S]*data-campaign-countdown/);
    assert.match(sectionHeader, /class="home-flash-campaign-details"[\s\S]*route\('campaigns\.show',\s*\$nextFlashDealCampaign\['id'\]\)/);
    assert.doesNotMatch(homeView, /<a class="text-button top-view-all" href="\{\{ url\('\/flash-deals'\) \}\}">View all/);
    assert.ok(campaignHeader, 'the per-campaign details header exists');
    assert.doesNotMatch(campaignHeader, /data-campaign-countdown/);
    assert.match(campaignHeader, /if \(! \$nextFlashDealCampaign \|\| \(int\) \$campaign\['id'\] !== \(int\) \$nextFlashDealCampaign\['id'\]\)/);
    assert.match(buyerController, /buyerFlashDealProductGroups\(\$request, \$campaigns, \$catalog, 6\)/);

    assert.match(
        buyerCss,
        /body:not\(\.bc\)\.bh \.home-flash-deals \.flash-deals-heading\{\s*width:100%;\s*margin:0 0 8px;\s*padding:0;\s*\}/,
    );
    assert.match(buyerCss, /body:not\(\.bc\)\.bh \.home-flash-deals \.home-flash-campaign-details\{[^}]*display:inline-flex/);
    assert.match(themeCss, /:root\[data-theme="dark"\] body\.bh:not\(\.bc\) \.home-flash-campaign-details/);
    assert.match(
        buyerCss,
        /body:not\(\.bc\)\.bh \.home-flash-campaign\{padding:0;border:0;border-radius:0;background:transparent\}/,
    );
    assert.match(buyerCss, /body:not\(\.bc\)\.bh \.home-flash-product-grid\{display:grid;grid-template-columns:repeat\(6,minmax\(0,1fr\)\)/);
    assert.match(themeCss, /:root\[data-theme="dark"\] body\.bh:not\(\.bc\) \.home-flash-deals \.flash-deals-heading h2\s*\{\s*color:\s*#fff8ef;\s*\}/);
    assert.ok(darkCampaignSurfaceRule, 'dark-mode campaign surfaces are grouped');
    assert.doesNotMatch(darkCampaignSurfaceRule, /\.home-flash-campaign,/);
});

test('homepage and full Flash Deals pages share an accessible, data-backed campaign sales indicator', () => {
    assert.match(homeView, /@include\('buyer\.partials\.campaign-sales-indicator'/);
    assert.match(flashDealsView, /@include\('buyer\.partials\.campaign-sales-indicator'/);
    assert.match(salesIndicatorPartial, /aria-valuemax="\{\{ \$campaignStockLimit \}\}"/);
    assert.match(salesIndicatorPartial, /aria-valuenow="\{\{ \$campaignProgressUnits \}\}"/);
    assert.match(salesIndicatorPartial, /\{\{ number_format\(\$campaignSoldUnits\) \}\} SOLD/);
    assert.match(salesIndicatorPartial, /\$campaignStockLimit !== null/);
    assert.match(buyerCss, /\.campaign-sales-indicator-fill\{[^}]*width:var\(--campaign-sales-progress,0%\)/);
    assert.match(themeCss, /:root\[data-theme="dark"\] body\.bh:not\(\.bc\) \.campaign-sales-indicator--limited/);
});

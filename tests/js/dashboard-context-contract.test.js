import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const operationalDashboardViews = [
    'resources/views/admin/dashboard.blade.php',
    'resources/views/seller/Dashboard/dashboard.blade.php',
    'resources/views/logistics/dashboard/index.blade.php',
    'resources/views/rider/dashboard/pickups.blade.php',
    'resources/views/rider/dashboard/deliveries.blade.php',
    'resources/views/rider/dashboard/courier.blade.php',
];

const [dashboardViews, buyerDashboard, dashboardContextScript, adminScript, sellerScript, logisticsScript, riderScript, dashboardContextView, sharedThemeCss] = await Promise.all([
    Promise.all(operationalDashboardViews.map((file) => readFile(new URL(`../../${file}`, import.meta.url), 'utf8'))),
    readFile(new URL('../../resources/views/buyer/Dashboard/home.blade.php', import.meta.url), 'utf8'),
    readFile(new URL('../../resources/js/dashboard-context.js', import.meta.url), 'utf8'),
    readFile(new URL('../../resources/js/admin.js', import.meta.url), 'utf8'),
    readFile(new URL('../../resources/js/seller.js', import.meta.url), 'utf8'),
    readFile(new URL('../../resources/js/logistics.js', import.meta.url), 'utf8'),
    readFile(new URL('../../resources/js/rider.js', import.meta.url), 'utf8'),
    readFile(new URL('../../resources/views/partials/dashboard-context.blade.php', import.meta.url), 'utf8'),
    readFile(new URL('../../resources/css/theme.css', import.meta.url), 'utf8'),
]);

test('the shared PHT/weather context is present on Admin, Seller, Logistics, Rider, and Courier dashboards only', () => {
    for (const view of dashboardViews) {
        assert.match(view, /@include\('partials\.dashboard-context'/);
    }

    assert.doesNotMatch(buyerDashboard, /partials\.dashboard-context|dashboard\.weather\.current/);
});

test('operational role scripts share Manila time and the same protected weather widget behavior', () => {
    for (const script of [adminScript, sellerScript, logisticsScript, riderScript]) {
        assert.match(script, /dashboard-context\.js/);
    }

    assert.match(dashboardContextScript, /Asia\/Manila/);
    assert.match(dashboardContextScript, /data-dashboard-weather/);
    assert.match(dashboardContextScript, /credentials:\s*'same-origin'/);
    assert.match(dashboardContextScript, /setInterval\(loadWeather, 15 \* 60 \* 1000\)/);
});

test('the shared weather strip presents compact live readings in Bearly light and dark themes', () => {
    assert.match(dashboardContextView, /Philippine Time/);
    assert.match(dashboardContextView, /dashboard-context-divider/);
    assert.match(dashboardContextView, /data-ph-clock/);
    assert.match(dashboardContextView, /cloud-moon/);
    assert.match(dashboardContextView, /data-dashboard-weather-temperature/);
    assert.match(dashboardContextView, /data-dashboard-weather-reading/);
    assert.match(dashboardContextView, /data-dashboard-weather-updated/);
    assert.match(dashboardContextView, /WeatherAPI\.com/);
    assert.doesNotMatch(dashboardContextView, /More details|data-dashboard-weather-feels-like/);
    assert.match(sharedThemeCss, /@media \(max-width: 600px\)/);
    assert.match(sharedThemeCss, /html\[data-theme="dark"\] \.dashboard-context/);
    assert.match(sharedThemeCss, /background: #35251e/);
    assert.match(sharedThemeCss, /data-state="unavailable"\] \.dashboard-weather-condition/);
    assert.match(dashboardContextScript, /maximumFractionDigits: 1/);
    assert.match(dashboardContextScript, /Updated \$\{weather\.updated_at\} ·/);
});

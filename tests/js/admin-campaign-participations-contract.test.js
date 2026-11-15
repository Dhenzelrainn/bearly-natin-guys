import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const read = relativePath => readFileSync(fileURLToPath(new URL(relativePath, import.meta.url)), 'utf8');
const detailView = read('../../resources/views/admin/campaigns/show.blade.php');
const rowsPartial = read('../../resources/views/admin/campaigns/partials/participation-rows.blade.php');
const refreshModule = read('../../resources/js/admin-campaign-participations.js');
const adminEntry = read('../../resources/js/admin.js');
const realtimeEntry = read('../../resources/js/realtime.js');

test('Admin campaign detail exposes a protected refresh URL and stable row markers', () => {
    assert.match(detailView, /data-admin-campaign-participations/);
    assert.match(detailView, /data-campaign-id="\{\{\s*\(int\)\s*\$campaign->id\s*\}\}"/);
    assert.match(detailView, /route\('admin\.campaigns\.participations\.refresh',\s*\$campaign\)/);
    assert.match(rowsPartial, /<tbody data-campaign-participation-rows>/);
    assert.match(rowsPartial, /data-campaign-participation="\{\{\s*\(int\)\s*\$participation->id\s*\}\}"/);
    assert.match(rowsPartial, /data-campaign-review-form/);
});

test('participation rows refresh from realtime and polling without a page reload', () => {
    assert.match(adminEntry, /initAdminCampaignParticipations\(\)/);
    assert.match(realtimeEntry, /echo\.private\('admin\.campaigns'\)/);
    assert.match(realtimeEntry, /\.listen\('\.campaign\.participation-submitted'/);
    assert.match(refreshModule, /bearly:admin-campaign-participation-submitted/);
    assert.match(refreshModule, /window\.setInterval\(\(\) => refresh\(\), pollInterval\)/);
    assert.match(refreshModule, /visibilitychange/);
    assert.match(refreshModule, /captureReviewDrafts\(currentRows\)/);
    assert.match(refreshModule, /restoreReviewDrafts\(nextRows, drafts\)/);
    assert.doesNotMatch(refreshModule, /window\.location\.reload/);
});

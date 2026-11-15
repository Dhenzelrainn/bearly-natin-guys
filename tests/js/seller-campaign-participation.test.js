import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const participationScript = await readFile(new URL('../../resources/js/seller-campaign-participation.js', import.meta.url), 'utf8');
const realtimeScript = await readFile(new URL('../../resources/js/realtime.js', import.meta.url), 'utf8');
const campaignView = await readFile(new URL('../../resources/views/seller/Promotions/campaign-show.blade.php', import.meta.url), 'utf8');

test('campaign participation status listens for its private realtime event and checks current state', () => {
    assert.match(realtimeScript, /\.listen\('\.seller\.campaign-participation-updated'/);
    assert.match(realtimeScript, /bearly:seller-campaign-participation-updated/);
    assert.match(participationScript, /bearly:seller-campaign-participation-updated/);
    assert.match(participationScript, /Number\(update\.campaign_id \|\| 0\) !== campaignId/);
    assert.match(participationScript, /Number\(update\.participation_id \|\| 0\) !== participationId/);
    assert.match(participationScript, /fetch\(endpoint,\s*\{\s*cache: 'no-store'/);
});

test('pending participation uses foreground polling as a fallback and updates accessible status text', () => {
    assert.match(participationScript, /window\.setInterval\(refreshStatus, 15000\)/);
    assert.match(participationScript, /document\.addEventListener\('visibilitychange'/);
    assert.match(participationScript, /panel\.dataset\.participationStatus !== 'pending_review' \|\| document\.hidden/);
    assert.match(participationScript, /label\.textContent = String\(snapshot\.status_label/);
    assert.match(participationScript, /reason\.textContent = reviewReason/);
    assert.match(participationScript, /liveMessage\.textContent =/);
    assert.match(campaignView, /data-campaign-participation-live/);
    assert.match(campaignView, /data-campaign-participation-status/);
    assert.match(campaignView, /data-campaign-participation-live-message role="status" aria-live="polite"/);
});

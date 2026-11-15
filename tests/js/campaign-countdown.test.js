import test from 'node:test';
import assert from 'node:assert/strict';
import { formatCampaignTimeRemaining } from '../../resources/js/campaign-countdown.js';

test('campaign countdown formats an active time remaining in hours, minutes, and seconds', () => {
    const now = Date.parse('2026-11-15T10:00:00+08:00');
    assert.equal(formatCampaignTimeRemaining('2026-11-15T13:04:05+08:00', now), '03:04:05');
});

test('campaign countdown includes whole days for longer campaigns', () => {
    const now = Date.parse('2026-11-15T10:00:00+08:00');
    assert.equal(formatCampaignTimeRemaining('2026-11-16T12:03:04+08:00', now), '1d 02:03:04');
});

test('campaign countdown marks expired and invalid schedules clearly', () => {
    const now = Date.parse('2026-11-15T10:00:00+08:00');
    assert.equal(formatCampaignTimeRemaining('2026-11-15T09:59:59+08:00', now), 'Ended');
    assert.equal(formatCampaignTimeRemaining('', now), 'Unavailable');
});

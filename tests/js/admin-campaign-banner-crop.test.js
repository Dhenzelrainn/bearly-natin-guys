import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { getCampaignBannerCropGeometry } from '../../resources/js/admin-campaign-banner-crop.js';

const read = relativePath => readFileSync(fileURLToPath(new URL(relativePath, import.meta.url)), 'utf8');
const formView = read('../../resources/views/admin/campaigns/form.blade.php');
const cropCss = read('../../resources/css/admin.css');
const cropScript = read('../../resources/js/admin-campaign-banner-crop.js');

test('crop geometry covers the frame and produces the expected source crop for wide images', () => {
    const crop = getCampaignBannerCropGeometry(3000, 900, 900, 300);

    assert.ok(crop);
    assert.equal(crop.sourceWidth, 2700);
    assert.equal(crop.sourceHeight, 900);
    assert.equal(crop.sourceX, 150);
    assert.equal(crop.sourceY, 0);
    assert.equal(crop.overflowX, 100);
    assert.equal(crop.left, -50);
});

test('crop geometry supports repositioning, zoom, and tall image uploads', () => {
    const left = getCampaignBannerCropGeometry(3000, 900, 900, 300, 1, 0, 0.5);
    const right = getCampaignBannerCropGeometry(3000, 900, 900, 300, 1, 1, 0.5);
    const zoomed = getCampaignBannerCropGeometry(3000, 900, 900, 300, 2, 0.5, 0.5);
    const tall = getCampaignBannerCropGeometry(1000, 3000, 900, 300, 1, 0.5, 0.5);

    assert.equal(left.sourceX, 0);
    assert.equal(right.sourceX, 300);
    assert.equal(zoomed.sourceWidth, 1350);
    assert.ok(tall.sourceHeight < 3000);
    assert.ok(tall.sourceX >= 0 && tall.sourceY >= 0);
    assert.ok(tall.sourceX + tall.sourceWidth <= 1000);
    assert.ok(tall.sourceY + tall.sourceHeight <= 3000);
});

test('Admin campaign banner field exposes keyboard, drag, zoom, reset, and submit crop controls', () => {
    assert.match(formView, /data-campaign-banner-input/);
    assert.match(formView, /data-campaign-banner-stage[^>]*tabindex="0"[^>]*role="group"/);
    assert.match(formView, /data-campaign-banner-zoom[^>]*type="range"/);
    assert.match(formView, /data-campaign-banner-reset/);
    assert.match(formView, /arrow keys/);
    assert.match(formView, /Preview the 3:1 homepage banner crop/);
    assert.match(cropCss, /campaign-banner-crop-stage[^{}]*\{[^}]*aspect-ratio:\s*3\s*\/\s*1/s);
    assert.match(cropScript, /const outputWidth = 1800;\s*const outputHeight = 600;/);
});

test('invalid crop dimensions are rejected without throwing', () => {
    assert.equal(getCampaignBannerCropGeometry(0, 100, 940, 400), null);
    assert.equal(getCampaignBannerCropGeometry(100, 100, Number.NaN, 400), null);
});

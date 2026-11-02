import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const [themeCss, adminLayout, announcementsView, mediaPickerView] = await Promise.all([
    read('resources/css/theme.css'),
    read('resources/views/layouts/admin.blade.php'),
    read('resources/views/admin/communication/announcements.blade.php'),
    read('resources/views/admin/communication/partials/announcement-media-picker.blade.php'),
]);

test('Admin announcement composer replaces light-only notice, audience, and upload surfaces in dark mode', () => {
    assert.match(announcementsView, /class="modal-card modal-wide announcement-composer"/);
    assert.match(announcementsView, /class="announcement-retention-note"/);
    assert.match(mediaPickerView, /class="announcement-media-upload"/);
    assert.match(themeCss, /body\.admin-body \.announcement-composer \.announcement-retention-note\s*\{[\s\S]*?background:\s*#3b3027;[\s\S]*?color:\s*#d8cabd;/);
    assert.match(themeCss, /body\.admin-body \.announcement-composer \.announcement-audience-options label\s*\{[\s\S]*?background:\s*#3b3027;[\s\S]*?color:\s*#f1e9e1;/);
    assert.match(themeCss, /body\.admin-body \.announcement-composer \.announcement-media-upload\s*\{[\s\S]*?background:\s*#2b241f;[\s\S]*?color:\s*#f1e9e1;/);
    assert.match(themeCss, /body\.admin-body \.announcement-composer \.announcement-media-upload input\[type="file"\]\s*\{[\s\S]*?background:\s*#1b1714;/);
    assert.match(adminLayout, /body\.admin-body \.announcement-composer \.announcement-retention-note\s*\{[\s\S]*?background:\s*#3b3027 !important;[\s\S]*?color:\s*#d8cabd !important;/);
    assert.match(adminLayout, /body\.admin-body \.announcement-composer \.announcement-media-upload\s*\{[\s\S]*?background:\s*#2b241f !important;/);
});

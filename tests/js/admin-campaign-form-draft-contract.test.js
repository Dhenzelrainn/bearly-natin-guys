import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const campaignForm = await readFile(
    new URL('../../resources/views/admin/campaigns/form.blade.php', import.meta.url),
    'utf8',
);

test('Admin campaign form restores unsent values after a refresh', () => {
    assert.match(campaignForm, /data-campaign-form/);
    assert.match(campaignForm, /bearly:admin:campaign-form:v1:\$\{adminId\}:\$\{window\.location\.pathname\}/);
    assert.match(campaignForm, /window\.sessionStorage\.getItem\(draftKey\)/);
    assert.match(campaignForm, /window\.sessionStorage\.setItem\(draftKey, JSON\.stringify\(draft\)\)/);
    assert.match(campaignForm, /addEventListener\('input', saveDraft\)/);
    assert.match(campaignForm, /addEventListener\('change', saveDraft\)/);
    assert.match(campaignForm, /window\.addEventListener\('pagehide'/);
    assert.match(campaignForm, /dropdowns\.forEach\(updateSummary\)/);
});

test('campaign form drafts exclude files and clear after submission', () => {
    assert.match(campaignForm, /\['hidden', 'file', 'password', 'submit', 'button', 'reset', 'image'\]/);
    assert.match(campaignForm, /campaignForm\.addEventListener\('submit'/);
    assert.match(campaignForm, /window\.sessionStorage\.removeItem\(draftKey\)/);
    assert.match(campaignForm, /select the image again after a refresh/);
});

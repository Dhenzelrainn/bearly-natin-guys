import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const [policyView, adminCss] = await Promise.all([
    read('resources/views/admin/system/policies.blade.php'),
    read('resources/css/admin.css'),
]);

test('policy detail modal groups all actions into one footer', () => {
    const detailModalStart = policyView.indexOf('<div class="modal-shell" data-modal="policy-{{ $policy[\'id\'] }}"');
    const editorModalStart = policyView.indexOf('<div class="modal-shell" data-modal="policy-edit-{{ $policy[\'id\'] }}"');
    const detailModal = policyView.slice(detailModalStart, editorModalStart);

    assert.notEqual(detailModalStart, -1);
    assert.notEqual(editorModalStart, -1);
    assert.equal((detailModal.match(/class="modal-footer/g) ?? []).length, 1);
    assert.match(detailModal, /class="modal-footer policy-detail-footer"/);
    assert.match(detailModal, /class="policy-detail-publish-form"/);
    assert.match(detailModal, /class="policy-detail-archive-form"/);
    assert.match(detailModal, /data-open-modal="policy-edit-\{\{ \$policy\['id'\] \}\}"/);
});

test('policy detail actions stack cleanly on small screens', () => {
    assert.match(
        adminCss,
        /@media \(max-width: 680px\)\s*\{[\s\S]*?\.admin-body \.modal-card \.policy-detail-footer\s*\{[\s\S]*?flex-direction:\s*column-reverse;[\s\S]*?\.admin-body \.modal-card \.policy-detail-footer \.button\s*\{[\s\S]*?width:\s*100%;/,
    );
});

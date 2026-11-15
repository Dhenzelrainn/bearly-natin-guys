import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const draftScript = await readFile(
    new URL('../../resources/js/seller-product-draft.js', import.meta.url),
    'utf8',
);
const sellerScript = await readFile(
    new URL('../../resources/js/seller-polish.js', import.meta.url),
    'utf8',
);
const productForm = await readFile(
    new URL('../../resources/views/seller/Products/product-form.blade.php', import.meta.url),
    'utf8',
);

test('Seller product drafts persist restored image colors by draft image ID', () => {
    assert.match(draftScript, /draft_image_colors: draftImageColors\(form\)/);
    assert.match(draftScript, /const match = \/\^draft_image_colors/);
    assert.match(draftScript, /colors\[match\[1\]\]/);
    assert.match(sellerScript, /draft_image_colors\[\$\{imageId\}\]/);
    assert.match(sellerScript, /data-draft-image-color-select/);
    assert.match(productForm, /data-draft-image-id="\{\{ \$media\['draft_image_id'\] \?\? '' \}\}"/);
});

test('Seller product media uses one consistent custom file picker', () => {
    assert.equal((productForm.match(/data-product-file-picker(?:\s|>)/g) || []).length, 3);
    assert.equal((productForm.match(/class="product-file-picker-button"/g) || []).length, 3);
    assert.equal((productForm.match(/class="product-file-picker-input"/g) || []).length, 3);
    assert.equal((productForm.match(/data-product-file-picker-trigger/g) || []).length, 3);
    assert.equal((productForm.match(/\shidden\s+name="(?:image|gallery_images\[\]|variant_color_images\[\])"/g) || []).length, 3);
    assert.match(productForm, /body\.seller-body \.seller-content \.product-file-picker/);
    assert.match(productForm, /product-file-picker-input\[hidden\]/);
    assert.match(productForm, /\.variation-group, \.variant-matrix-empty, \.variant-matrix-table-wrap/);
    assert.match(productForm, /variant-package-details > summary/);
    assert.match(sellerScript, /syncProductFilePickerName/);
    assert.match(sellerScript, /data-product-file-picker-trigger/);
});

test('Seller product draft autosave initializes with the existing Seller editor', () => {
    assert.match(sellerScript, /import \{ setupSellerProductDraft \} from '\.\/seller-product-draft\.js';/);
    assert.match(sellerScript, /if \(productEditor\) setupSellerProductDraft\(productEditor\);/);
    assert.match(sellerScript, /seller:draft-images-updated/);
    assert.match(draftScript, /if \(!form\.checkValidity\(\)\)/);
});

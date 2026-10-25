/* Bearly Seller Polish — supplemental frontend behavior. */
import {
    VARIANT_PACKAGE_FIELDS,
    copyPackageFields,
    incompletePackageFields,
    packageValues,
    packageValuesAreComplete,
    packageValuesForVariant,
    packageVariantsForSubmission,
} from './variant-package-utils.js';

const initializeSellerPolish = () => {
    /*
     * Stable Seller sidebar navigation.
     * Laravel page navigation reloads the document, which normally resets the
     * sidebar/nav scroll position. Save the nav's scrollTop in sessionStorage
     * and restore it on the next Seller page.
     */
    const sellerSidebar = document.querySelector('[data-seller-sidebar]');
    const sellerNav = sellerSidebar?.querySelector('.seller-nav');
    const sellerNavScrollKey = 'bearlySellerNavScrollTop';

    if (sellerNav) {
        const saveSellerNavScroll = () => {
            try {
                window.sessionStorage.setItem(sellerNavScrollKey, String(sellerNav.scrollTop));
            } catch (_) {}
        };

        let restored = false;
        try {
            const saved = window.sessionStorage.getItem(sellerNavScrollKey);
            if (saved !== null) {
                const scrollTop = Number(saved);
                if (Number.isFinite(scrollTop)) {
                    window.requestAnimationFrame(() => {
                        sellerNav.scrollTop = scrollTop;
                    });
                    restored = true;
                }
            }
        } catch (_) {}

        /*
         * First visit only: keep the active page visible.
         * On later navigation, preserving the user's exact sidebar position
         * takes priority so the menu does not jump.
         */
        if (!restored) {
            const activeSellerNavItem = sellerNav.querySelector('.seller-nav-child.is-active, .seller-nav-link.is-active');
            if (activeSellerNavItem) {
                window.requestAnimationFrame(() => {
                    activeSellerNavItem.scrollIntoView({
                        block: 'nearest',
                        inline: 'nearest',
                    });
                });
            }
        }

        sellerNav.addEventListener('scroll', saveSellerNavScroll, { passive: true });

        sellerNav.querySelectorAll('a[href]').forEach((link) => {
            link.addEventListener('click', saveSellerNavScroll);
        });

        window.addEventListener('pagehide', saveSellerNavScroll);
    }

    const formatPeso = (value) => `₱${Math.max(0, Number(value || 0)).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;

    /* Product commission preview. Existing seller.js still owns buyer-price preview. */
    const productEditor = document.querySelector('[data-product-editor]');
    const productDiscountPercent = Math.min(90, Math.max(0, Number(productEditor?.dataset.productDiscountPercent || 0)));
    if (productEditor) {
        const priceInput = productEditor.querySelector('[data-product-price]');
        const commissionableNode = productEditor.querySelector('[data-product-commissionable]');
        const commissionNode = productEditor.querySelector('[data-product-commission]');
        const netNode = productEditor.querySelector('[data-product-net]');
        const rate = Number(productEditor.dataset.platformCommissionRate || 0.10);

        const updateCommissionPreview = () => {
            const price = Math.max(0, Number(priceInput?.value || 0));
            const commissionable = price * (1 - (productDiscountPercent / 100));
            const commission = commissionable * rate;
            const net = commissionable - commission;
            if (commissionableNode) commissionableNode.textContent = formatPeso(commissionable);
            if (commissionNode) commissionNode.textContent = `−${formatPeso(commission)}`;
            if (netNode) netNode.textContent = formatPeso(net);
        };

        priceInput?.addEventListener('input', updateCommissionPreview);
        updateCommissionPreview();
    }


    /* Product variant matrix: SKU / price / stock per option combination. */
    const variantMatrix = productEditor?.querySelector('[data-variant-matrix]');
    if (productEditor && variantMatrix) {
        const optionOneName = productEditor.querySelector('[data-variant-option-one-name]');
        const optionOneValues = productEditor.querySelector('[data-variant-option-one-values]');
        const optionTwoName = productEditor.querySelector('[data-variant-option-two-name]');
        const optionTwoValues = productEditor.querySelector('[data-variant-option-two-values]');
        const totalStockInput = productEditor.querySelector('[data-product-total-stock]');
        const globalThresholdControl = productEditor.querySelector('[data-product-global-threshold]');
        const globalThresholdInput = globalThresholdControl?.querySelector('input[name="low_stock_threshold"]');
        const singlePriceControl = productEditor.querySelector('[data-single-price-control]');
        const singlePricePreview = productEditor.querySelector('[data-single-price-preview]');
        const singleCommissionPreview = productEditor.querySelector('[data-single-commission-preview]');
        const variantPricingNote = productEditor.querySelector('[data-variant-pricing-note]');
        const singlePackageDetails = productEditor.querySelector('[data-single-package-details]');
        const basePriceInput = productEditor.querySelector('[data-product-price]');
        const rowsHost = variantMatrix.querySelector('[data-variant-rows]');
        const tableWrap = variantMatrix.querySelector('[data-variant-table-wrap]');
        const emptyState = variantMatrix.querySelector('[data-variant-empty]');
        const totalNode = variantMatrix.querySelector('[data-variant-total]');
        const generateButton = variantMatrix.querySelector('[data-generate-variants]');
        const existingScript = productEditor.querySelector('[data-existing-variants]');
        const productSkuInput = productEditor.querySelector('[data-product-sku]');
        const productSkuLabel = productEditor.querySelector('[data-product-sku-label]');
        const productSkuManaged = productEditor.querySelector('[data-product-sku-managed]');
        const productSkuHelp = productEditor.querySelector('[data-product-sku-help]');
        const productModePicker = productEditor.querySelector('[data-product-mode-picker]');
        const productModeChoices = [...(productModePicker?.querySelectorAll('[data-product-mode-choice]') || [])];
        const productModeValue = productEditor.querySelector('[data-product-mode-value]');
        const variantConfiguration = productEditor.querySelector('[data-variant-configuration]');
        const mediaGallery = productEditor.querySelector('[data-product-media-gallery]');
        const mediaGalleryStage = mediaGallery?.querySelector('[data-product-media-stage-image]');
        const mediaGalleryEmpty = mediaGallery?.querySelector('[data-product-media-empty]');
        const mediaGalleryThumbnails = mediaGallery?.querySelector('[data-product-media-thumbnails]');
        const mediaGalleryCount = mediaGallery?.querySelector('[data-product-media-count]');
        const mediaRemovalHost = mediaGallery?.querySelector('[data-product-media-removals]');
        const mediaStatus = mediaGallery?.querySelector('[data-product-media-status]');
        const variantColorImagesPanel = productEditor.querySelector('[data-variant-color-images]');
        const variantColorImageInput = variantColorImagesPanel?.querySelector('[data-variant-color-image-input]');
        const variantColorImageUploads = variantColorImagesPanel?.querySelector('[data-variant-color-image-uploads]');
        const variantColorImageEmpty = productEditor.querySelector('[data-variant-color-image-empty]');
        const savedMediaScript = productEditor.querySelector('[data-saved-product-media]');
        const primaryImageInput = productEditor.querySelector('[data-product-image-input]');
        const galleryImagesInput = productEditor.querySelector('input[name="gallery_images[]"]');
        const productNameInput = productEditor.querySelector('input[name="name"]');
        const productNameGuidance = productEditor.querySelector('[data-product-name-guidance]');
        const productDescriptionInput = productEditor.querySelector('[data-product-description]');
        const productDescriptionGuidance = productEditor.querySelector('[data-product-description-guidance]');
        const marketplaceSubcategorySelect = productEditor.querySelector('[name="marketplace_subcategory_id"]');
        const buyerPreview = productEditor.querySelector('[data-buyer-product-preview]');
        const buyerPreviewImage = buyerPreview?.querySelector('[data-buyer-preview-image]');
        const buyerPreviewImageEmpty = buyerPreview?.querySelector('[data-buyer-preview-image-empty]');
        const buyerPreviewName = buyerPreview?.querySelector('[data-buyer-preview-name]');
        const buyerPreviewPrice = buyerPreview?.querySelector('[data-buyer-preview-price]');
        const buyerPreviewRegular = buyerPreview?.querySelector('[data-buyer-preview-regular]');
        const buyerPreviewDiscount = buyerPreview?.querySelector('[data-buyer-preview-discount]');
        const buyerPreviewVariantField = buyerPreview?.querySelector('[data-buyer-preview-variant-field]');
        const buyerPreviewVariant = buyerPreview?.querySelector('[data-buyer-preview-variant]');
        const buyerPreviewVariantSummary = buyerPreview?.querySelector('[data-buyer-preview-variant-summary]');
        const buyerPreviewPayments = buyerPreview?.querySelector('[data-buyer-preview-payment-methods]');
        let productSkuValue = productSkuInput?.value || '';

        let savedProductMedia = [];
        try {
            savedProductMedia = JSON.parse(savedMediaScript?.textContent || '[]');
            if (!Array.isArray(savedProductMedia)) savedProductMedia = [];
        } catch (_) {
            savedProductMedia = [];
        }

        const localMediaUrls = new Map();
        const variantImageColorAssignments = new Map();
        let productMediaItems = [];
        let activeProductMediaId = null;
        let lastBuyerPreviewSelection = null;
        let lastBuyerPreviewMediaId = null;
        const removedProductImageIds = new Set(
            [...(mediaRemovalHost?.querySelectorAll('input[name="remove_product_images[]"]') || [])]
                .map((input) => String(input.value))
        );
        const renderedMediaById = new Map(
            savedProductMedia
                .filter((item) => item && item.id && item.url)
                .map((item) => [String(item.id), item])
        );

        mediaGalleryThumbnails?.querySelectorAll('[data-product-media-thumbnail]').forEach((button) => {
            const id = button.dataset.mediaId;
            const thumbnailImage = button.querySelector('img');

            if (id && !renderedMediaById.has(id) && thumbnailImage?.src) {
                renderedMediaById.set(id, {
                    id,
                    image_id: button.dataset.imageId || null,
                    kind: button.dataset.mediaKind || 'gallery',
                    color: button.dataset.color || '',
                    variant_id: button.dataset.variantId || null,
                    variant_label: button.dataset.variantLabel || '',
                    legacy_color: button.dataset.legacyColor === 'true',
                    color_shared: button.dataset.colorShared === 'true' || button.dataset.legacyColor === 'true',
                    label: button.getAttribute('aria-label')?.replace(/^Show\s+/, '') || 'Product image',
                    url: thumbnailImage.currentSrc || thumbnailImage.src,
                });
            }
        });

        productMediaItems = [...renderedMediaById.values()]
            .filter((item) => !removedProductImageIds.has(String(item.image_id || '')));

        const splitValues = (input) => (input?.value || '')
            .split(',')
            .map((value) => value.trim())
            .filter(Boolean)
            .filter((value, index, list) => list.indexOf(value) === index);

        const currentProductMode = () => productModeChoices.find((input) => input.checked)?.value
            || productModeValue?.value
            || 'single';

        const packageFieldLabels = {
            package_weight_kg: 'Weight (kg)',
            package_length_cm: 'Length (cm)',
            package_width_cm: 'Width (cm)',
            package_height_cm: 'Height (cm)',
        };

        const packageRowForVariant = (row) => row?.nextElementSibling?.matches('[data-variant-package-row]')
            ? row.nextElementSibling
            : null;

        const packageValuesForRow = (packageRow) => packageValues(Object.fromEntries(
            VARIANT_PACKAGE_FIELDS.map((field) => [
                field,
                packageRow?.querySelector(`[data-variant-package-input="${field}"]`)?.value ?? '',
            ]),
        ));

        const sharedPackageValuesNow = () => packageValues(Object.fromEntries(
            VARIANT_PACKAGE_FIELDS.map((field) => [field, sharedPackageInputs[field]?.value ?? '']),
        ));

        const sharedPackageModeEnabled = () => currentProductMode() === 'with_variants'
            && Boolean(sharedPackageMode?.checked);

        const packageOverrideEnabled = (packageRow) => Boolean(
            packageRow?.querySelector('[data-variant-package-override]')?.checked,
        );

        const effectivePackageValuesForRow = (row) => {
            const packageRow = packageRowForVariant(row);
            const perSkuValues = packageValuesForRow(packageRow);
            return packageValuesForVariant({
                ...perSkuValues,
                package_override: packageOverrideEnabled(packageRow),
            }, sharedPackageValuesNow(), sharedPackageModeEnabled());
        };

        const updateVariantPackageSummary = (details, values = null, usesShared = false) => {
            const summary = details.querySelector('[data-variant-package-summary]');
            if (!summary) return;

            const effectiveValues = values || packageValuesForRow(details.closest('[data-variant-package-row]'));
            const incomplete = incompletePackageFields(effectiveValues);
            const complete = incomplete.length === 0;
            const dimensions = [
                effectiveValues.package_length_cm,
                effectiveValues.package_width_cm,
                effectiveValues.package_height_cm,
            ].join(' × ');

            if (complete) {
                summary.textContent = `${usesShared ? 'Shared · ' : ''}${effectiveValues.package_weight_kg} kg · ${dimensions} cm`;
                summary.dataset.state = 'complete';
                return;
            }

            const entered = VARIANT_PACKAGE_FIELDS.length - incomplete.length;
            summary.textContent = usesShared
                ? `Shared package incomplete · ${entered}/${VARIANT_PACKAGE_FIELDS.length} values`
                : entered === 0
                    ? 'Required · not set'
                    : `Incomplete · ${entered}/${VARIANT_PACKAGE_FIELDS.length} values`;
            summary.dataset.state = 'incomplete';
        };

        const syncVariantPackageDetails = (details) => {
            const variantsEnabled = currentProductMode() === 'with_variants';
            const packageRow = details.closest('[data-variant-package-row]');
            const variantRow = packageRow?.previousElementSibling;
            const overrideLabel = packageRow?.querySelector('[data-variant-package-override-label]');
            const overrideInput = packageRow?.querySelector('[data-variant-package-override]');
            const packageGrid = details.querySelector('[data-variant-package-grid]');
            const usingShared = variantsEnabled
                && sharedPackageModeEnabled()
                && !overrideInput?.checked;
            const sharedValuesNote = details.querySelector('[data-variant-package-shared-note]');
            const effectiveValues = variantRow ? effectivePackageValuesForRow(variantRow) : packageValuesForRow(packageRow);

            if (overrideLabel) overrideLabel.hidden = !sharedPackageModeEnabled();
            if (overrideInput) overrideInput.disabled = !variantsEnabled;
            if (packageGrid) packageGrid.hidden = usingShared;
            if (sharedValuesNote) {
                sharedValuesNote.hidden = !usingShared;
                if (usingShared) {
                    const sharedValues = sharedPackageValuesNow();
                    sharedValuesNote.textContent = packageValuesAreComplete(sharedValues)
                        ? `Using shared package details: ${sharedValues.package_weight_kg} kg · ${sharedValues.package_length_cm} × ${sharedValues.package_width_cm} × ${sharedValues.package_height_cm} cm.`
                        : 'Using shared package details. Complete all four shared measurements above.';
                }
            }

            details.querySelectorAll('[data-variant-package-input]').forEach((input) => {
                input.disabled = !variantsEnabled || usingShared;
                input.required = variantsEnabled && details.open && !usingShared;

                if (variantsEnabled && details.open && !usingShared) {
                    input.min = input.dataset.packageMin;
                    input.max = input.dataset.packageMax;
                    input.step = input.dataset.packageStep;
                } else {
                    input.removeAttribute('min');
                    input.removeAttribute('max');
                    // A missing step defaults to 1 and rejects saved decimal
                    // measurements before the submit handler can open this row.
                    input.step = 'any';
                }
            });

            updateVariantPackageSummary(details, effectiveValues, usingShared);
        };
        const paymentMethodInputs = [...productEditor.querySelectorAll('input[name="payment_methods[]"]')];
        const paymentMethodClientError = productEditor.querySelector('[data-payment-method-client-error]');
        const variantClientError = variantMatrix.querySelector('[data-variant-client-error]');
        const sharedPackageMode = variantMatrix.querySelector('[data-package-shared-mode]');
        const sharedPackageFields = variantMatrix.querySelector('[data-shared-package-fields]');
        const sharedPackageInputs = Object.fromEntries(VARIANT_PACKAGE_FIELDS.map((field) => [
            field,
            variantMatrix.querySelector(`[data-shared-package-input="${field}"]`),
        ]));
        const packageProgressLabel = variantMatrix.querySelector('[data-package-progress-label]');
        const packageProgressBar = variantMatrix.querySelector('[data-package-progress-bar]');
        const findIncompletePackageButton = variantMatrix.querySelector('[data-find-incomplete-package]');
        const packageCopySource = variantMatrix.querySelector('[data-package-copy-source]');
        const packageCopyButton = variantMatrix.querySelector('[data-package-copy-selected]');
        const packageActionStatus = variantMatrix.querySelector('[data-package-action-status]');
        let lastIncompleteVariantKey = '';

        const variantMainRows = () => [...rowsHost.querySelectorAll('[data-variant-main-row]')];

        const variantIdentity = (row) => row?.querySelector('input[name$="[sku]"]')?.value.trim()
            || row?.dataset.variantLabel
            || row?.querySelector('input[type="hidden"]')?.value
            || 'Variant';

        const updatePackageCopyControls = () => {
            const hasTargets = variantMainRows().some((row) => row
                .querySelector('[data-variant-package-copy-target]')?.checked);
            const sourceExists = Boolean(packageCopySource?.value);
            const enabled = currentProductMode() === 'with_variants' && hasTargets && sourceExists;
            if (packageCopySource) packageCopySource.disabled = currentProductMode() !== 'with_variants' || variantMainRows().length === 0;
            if (packageCopyButton) packageCopyButton.disabled = !enabled;
        };

        const refreshPackageCopySource = () => {
            if (!packageCopySource) return;

            const previousValue = packageCopySource.value;
            packageCopySource.replaceChildren();
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = variantMainRows().length ? 'Choose a source SKU' : 'Generate variants first';
            packageCopySource.appendChild(placeholder);

            variantMainRows().forEach((row) => {
                const option = document.createElement('option');
                option.value = row.dataset.variantLabel || row.querySelector('input[type="hidden"]')?.value || '';
                const sku = row.querySelector('input[name$="[sku]"]')?.value.trim();
                option.textContent = sku ? `${option.value} · ${sku}` : option.value;
                packageCopySource.appendChild(option);
            });

            if (variantMainRows().some((row) => (row.dataset.variantLabel || '') === previousValue)) {
                packageCopySource.value = previousValue;
            }

            updatePackageCopyControls();
        };

        const updatePackageProgress = () => {
            const rows = variantMainRows();
            const sharedMode = sharedPackageModeEnabled();
            const sharedValues = sharedPackageValuesNow();
            let completeCount = 0;

            rows.forEach((row) => {
                const packageRow = packageRowForVariant(row);
                const details = packageRow?.querySelector('[data-variant-package-details]');
                const values = packageValuesForVariant({
                    ...packageValuesForRow(packageRow),
                    package_override: packageOverrideEnabled(packageRow),
                }, sharedValues, sharedMode);
                const complete = packageValuesAreComplete(values);
                const usesShared = sharedMode && !packageOverrideEnabled(packageRow);

                if (complete) completeCount += 1;
                row.classList.toggle('has-incomplete-package', !complete);
                packageRow?.classList.toggle('has-incomplete-package', !complete);
                packageRow?.classList.toggle('uses-shared-package', usesShared);
                if (details) updateVariantPackageSummary(details, values, usesShared);
            });

            if (packageProgressLabel) {
                packageProgressLabel.textContent = `${completeCount} of ${rows.length} ${rows.length === 1 ? 'variant' : 'variants'} complete`;
            }
            if (packageProgressBar) {
                packageProgressBar.max = Math.max(1, rows.length);
                packageProgressBar.value = completeCount;
                packageProgressBar.setAttribute('aria-valuenow', String(completeCount));
                packageProgressBar.setAttribute('aria-valuemax', String(rows.length));
            }
            if (findIncompletePackageButton) {
                findIncompletePackageButton.disabled = rows.length === 0 || completeCount === rows.length;
            }

            updatePackageCopyControls();
        };

        const syncPackageModeUI = () => {
            const variantsEnabled = currentProductMode() === 'with_variants';
            const sharedMode = sharedPackageModeEnabled();
            const sharedVariantCount = variantMainRows().filter((row) => !packageOverrideEnabled(packageRowForVariant(row))).length;

            if (sharedPackageMode) sharedPackageMode.disabled = !variantsEnabled;
            if (sharedPackageFields) sharedPackageFields.hidden = !sharedMode;
            VARIANT_PACKAGE_FIELDS.forEach((field) => {
                const input = sharedPackageInputs[field];
                if (!input) return;

                input.disabled = !sharedMode;
                input.required = sharedMode && sharedVariantCount > 0;
                if (sharedMode) {
                    input.min = '0.001';
                    input.max = '1000';
                    input.step = '0.001';
                }
            });

            variantMainRows().forEach((row) => {
                const packageRow = packageRowForVariant(row);
                const details = packageRow?.querySelector('[data-variant-package-details]');
                const target = row.querySelector('[data-variant-package-copy-target]');
                if (target) target.disabled = !variantsEnabled;
                if (details) syncVariantPackageDetails(details);
            });

            refreshPackageCopySource();
            updatePackageProgress();
        };

        const focusIncompletePackage = (rowToFocus = null) => {
            const rows = variantMainRows();
            const incompleteRows = rows.filter((row) => !packageValuesAreComplete(effectivePackageValuesForRow(row)));
            if (!incompleteRows.length) {
                if (packageActionStatus) {
                    packageActionStatus.textContent = 'All variant package details are complete.';
                    packageActionStatus.hidden = false;
                }
                return;
            }

            const requestedIndex = rowToFocus ? incompleteRows.indexOf(rowToFocus) : -1;
            const previousIndex = incompleteRows.findIndex((row) => {
                const key = row.dataset.variantId || row.dataset.variantLabel || '';
                return key === lastIncompleteVariantKey;
            });
            const nextIndex = requestedIndex >= 0
                ? requestedIndex
                : (previousIndex + 1) % incompleteRows.length;
            const row = incompleteRows[nextIndex];
            const key = row.dataset.variantId || row.dataset.variantLabel || '';
            lastIncompleteVariantKey = key;

            const packageRow = packageRowForVariant(row);
            const details = packageRow?.querySelector('[data-variant-package-details]');
            const usesShared = sharedPackageModeEnabled() && !packageOverrideEnabled(packageRow);
            const values = effectivePackageValuesForRow(row);
            const missingField = incompletePackageFields(values)[0];
            if (details) {
                details.open = true;
                syncVariantPackageDetails(details);
            }

            const input = usesShared
                ? sharedPackageInputs[missingField]
                : packageRow?.querySelector(`[data-variant-package-input="${missingField}"]`);
            input?.focus({ preventScroll: true });
            input?.reportValidity();
            (usesShared ? sharedPackageFields : row)?.scrollIntoView({ behavior: 'smooth', block: 'center' });

            if (packageActionStatus) {
                packageActionStatus.textContent = usesShared
                    ? `Complete the shared package details used by ${variantIdentity(row)}.`
                    : `Complete package details for ${variantIdentity(row)}.`;
                packageActionStatus.hidden = false;
            }
        };

        const copyPackageDetailsToSelected = () => {
            const rows = variantMainRows();
            const sourceRow = rows.find((row) => (row.dataset.variantLabel || '') === packageCopySource?.value);
            if (!sourceRow) {
                if (packageActionStatus) {
                    packageActionStatus.textContent = 'Choose a source SKU first.';
                    packageActionStatus.hidden = false;
                }
                return;
            }

            const sourceValues = effectivePackageValuesForRow(sourceRow);
            if (!packageValuesAreComplete(sourceValues)) {
                if (packageActionStatus) {
                    packageActionStatus.textContent = `Complete package details for ${variantIdentity(sourceRow)} before copying.`;
                    packageActionStatus.hidden = false;
                }
                focusIncompletePackage(sourceRow);
                return;
            }

            const targets = rows.filter((row) => row !== sourceRow
                && row.querySelector('[data-variant-package-copy-target]')?.checked);
            if (!targets.length) {
                if (packageActionStatus) {
                    packageActionStatus.textContent = 'Select one or more target SKUs using the checkboxes in the variant rows.';
                    packageActionStatus.hidden = false;
                }
                return;
            }

            const hasExistingValues = targets.some((row) => VARIANT_PACKAGE_FIELDS.some((field) =>
                String(effectivePackageValuesForRow(row)[field] || '').trim() !== ''));
            if (hasExistingValues && !window.confirm(
                `Overwrite package details for ${targets.length} selected SKU(s) from ${variantIdentity(sourceRow)}? Price and stock will not change.`,
            )) {
                return;
            }

            targets.forEach((row) => {
                const packageRow = packageRowForVariant(row);
                const details = packageRow?.querySelector('[data-variant-package-details]');
                const values = copyPackageFields(packageValuesForRow(packageRow), sourceValues);
                VARIANT_PACKAGE_FIELDS.forEach((field) => {
                    const input = packageRow?.querySelector(`[data-variant-package-input="${field}"]`);
                    if (input) input.value = values[field];
                });

                if (sharedPackageModeEnabled()) {
                    const override = packageRow?.querySelector('[data-variant-package-override]');
                    if (override) override.checked = true;
                }
                if (details) syncVariantPackageDetails(details);
                const targetCheckbox = row.querySelector('[data-variant-package-copy-target]');
                if (targetCheckbox) targetCheckbox.checked = false;
            });

            if (packageActionStatus) {
                packageActionStatus.textContent = `Copied package details to ${targets.length} SKU(s). Price, stock, and reserved quantities were not changed.`;
                packageActionStatus.hidden = false;
            }
            updatePackageProgress();
        };

        const prepareSharedPackageSubmission = () => {
            variantMatrix.querySelectorAll('[data-package-submission-input]').forEach((input) => input.remove());
            if (!sharedPackageModeEnabled()) return;

            const sharedValues = sharedPackageValuesNow();
            const rows = variantMainRows();
            const packageVariants = rows.map((row) => {
                const packageRow = packageRowForVariant(row);
                return {
                    ...packageValuesForRow(packageRow),
                    package_override: packageOverrideEnabled(packageRow),
                };
            });
            const submittedPackages = packageVariantsForSubmission(packageVariants, sharedValues, true);

            rows.forEach((row, index) => {
                const packageRow = packageRowForVariant(row);
                if (!packageRow) return;
                const perSkuValues = packageValuesForRow(packageRow);
                const usesShared = !packageOverrideEnabled(packageRow);

                VARIANT_PACKAGE_FIELDS.forEach((field) => {
                    const original = document.createElement('input');
                    original.type = 'hidden';
                    original.name = `package_originals[${index}][${field}]`;
                    original.value = perSkuValues[field];
                    original.dataset.packageSubmissionInput = '';
                    packageRow.appendChild(original);

                    if (!usesShared) return;
                    const submitted = document.createElement('input');
                    submitted.type = 'hidden';
                    submitted.name = `variants[${index}][${field}]`;
                    submitted.value = submittedPackages[index][field];
                    submitted.dataset.packageSubmissionInput = '';
                    packageRow.appendChild(submitted);
                });
            });
        };

        const updateSubmitPreflightErrors = () => {
            if (paymentMethodInputs.some((input) => input.checked)) {
                if (paymentMethodClientError) paymentMethodClientError.hidden = true;
                paymentMethodInputs.forEach((input) => input.removeAttribute('aria-invalid'));
            }

            const hasVariantRows = rowsHost.querySelector('[data-variant-main-row]') !== null;
            if (currentProductMode() !== 'with_variants' || hasVariantRows) {
                if (variantClientError) variantClientError.hidden = true;
            }
        };

        const localMediaItem = (id, label, kind, file, metadata = {}) => {
            if (!file) return null;

            const cached = localMediaUrls.get(id);
            if (cached?.file === file) {
                return { id, label, kind, ...metadata, url: cached.url };
            }

            if (cached?.url) URL.revokeObjectURL(cached.url);
            const url = URL.createObjectURL(file);
            localMediaUrls.set(id, { file, url });

            return { id, label, kind, ...metadata, url };
        };

        const setActiveProductMedia = (id) => {
            const selected = productMediaItems.find((item) => item.id === id)
                || productMediaItems.find((item) => item.kind === 'primary')
                || productMediaItems[0];

            if (!selected) {
                activeProductMediaId = null;
                if (mediaGalleryStage) {
                    mediaGalleryStage.removeAttribute('src');
                    mediaGalleryStage.alt = '';
                    mediaGalleryStage.hidden = true;
                }
                if (mediaGalleryEmpty) mediaGalleryEmpty.hidden = false;
                if (buyerPreviewImage) {
                    buyerPreviewImage.removeAttribute('src');
                    buyerPreviewImage.alt = 'Product image as buyers will see it';
                    buyerPreviewImage.hidden = true;
                }
                if (buyerPreviewImageEmpty) buyerPreviewImageEmpty.hidden = false;
                mediaGalleryThumbnails?.querySelectorAll('[data-product-media-thumbnail]').forEach((button) => {
                    button.classList.remove('is-active');
                    button.setAttribute('aria-pressed', 'false');
                });
                return;
            }

            activeProductMediaId = selected.id;
            if (mediaGalleryStage) {
                mediaGalleryStage.src = selected.url;
                mediaGalleryStage.alt = selected.label;
                mediaGalleryStage.hidden = false;
            }
            if (mediaGalleryEmpty) mediaGalleryEmpty.hidden = true;
            if (buyerPreviewImage) {
                buyerPreviewImage.src = selected.url;
                buyerPreviewImage.alt = `${productNameInput?.value.trim() || 'Product'} - ${selected.label}`;
                buyerPreviewImage.hidden = false;
            }
            if (buyerPreviewImageEmpty) buyerPreviewImageEmpty.hidden = true;

            mediaGalleryThumbnails?.querySelectorAll('[data-product-media-thumbnail]').forEach((button) => {
                const isActive = button.dataset.mediaId === selected.id;
                button.classList.toggle('is-active', isActive);
                button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
        };

        const initiallyActiveThumbnail = mediaGalleryThumbnails?.querySelector('[data-product-media-thumbnail].is-active');
        if (initiallyActiveThumbnail) {
            setActiveProductMedia(initiallyActiveThumbnail.dataset.mediaId);
        } else if (productMediaItems.length) {
            setActiveProductMedia(productMediaItems[0].id);
        }

        const renderProductMediaPreview = () => {
            if (!mediaGallery || !mediaGalleryThumbnails) return;

            const savedById = new Map(savedProductMedia
                .filter((item) => !removedProductImageIds.has(String(item.image_id || '')))
                .map((item) => [item.id, item]));
            const items = [];
            const localIds = new Set();
            const addLocal = (id, label, kind, file, metadata = {}) => {
                const item = localMediaItem(id, label, kind, file, metadata);
                if (!item) return null;
                localIds.add(id);
                items.push(item);

                return item;
            };

            const primaryFile = primaryImageInput?.files?.[0];
            if (primaryFile) {
                addLocal('primary', 'Primary image', 'primary', primaryFile);
            } else if (savedById.has('primary')) {
                items.push(savedById.get('primary'));
            }

            const galleryFiles = [...(galleryImagesInput?.files || [])];
            if (galleryFiles.length) {
                galleryFiles.forEach((file, index) => {
                    addLocal(`gallery-${index}`, `Gallery image ${index + 1}`, 'gallery', file);
                });
            } else {
                items.push(...savedProductMedia.filter((item) => item.kind === 'gallery'
                    && !removedProductImageIds.has(String(item.image_id || ''))));
            }

            if (currentProductMode() === 'with_variants') {
                const includedVariantImageIds = new Set();
                const uploadedColors = new Set();
                const addSavedVariantImage = (item) => {
                    if (!item) return;
                    const imageId = String(item.image_id || '');
                    if (imageId && includedVariantImageIds.has(imageId)) return;
                    if (imageId) includedVariantImageIds.add(imageId);
                    items.push(item);
                };

                [...(variantColorImageInput?.files || [])].forEach((file, index) => {
                    const color = variantImageColorAssignments.get(index) || '';
                    const normalizedColor = color.trim().toLocaleLowerCase();
                    if (normalizedColor) uploadedColors.add(normalizedColor);

                    const representative = color
                        ? [...rowsHost.querySelectorAll('[data-variant-main-row]')].find((row) =>
                            normalizedColorKey(colorForVariant(row.dataset.variantLabel || '')) === normalizedColor)
                        : null;
                    const variantId = representative?.dataset.variantId || null;
                    const variantLabel = representative?.dataset.variantLabel || '';

                    addLocal(
                        `variant-color-upload:${index}`,
                        color ? `${color} variant image` : `Variant image — ${file.name}`,
                        'variant',
                        file,
                        {
                            variant_id: variantId,
                            variant_label: variantLabel,
                            color,
                            color_shared: Boolean(normalizedColor),
                            legacy_color: false,
                        },
                    );
                });

                [...rowsHost.querySelectorAll('[data-variant-main-row]')].forEach((row) => {
                    const variantId = row.dataset.variantId || '';
                    const variantLabel = row.dataset.variantLabel || row.querySelector('input[type="hidden"]')?.value || 'Variation';

                    const savedImage = savedProductMedia.find((item) => item.kind === 'variant'
                        && !item.color_shared
                        && (variantId
                            ? String(item.variant_id || '') === variantId
                            : String(item.variant_label || '').toLocaleLowerCase() === variantLabel.toLocaleLowerCase()));
                    const color = normalizedColorKey(colorForVariant(variantLabel));
                    const colorFallback = savedProductMedia.find((item) => item.kind === 'variant'
                        && (item.color_shared || item.legacy_color)
                        && normalizedColorKey(item.color) === color);
                    const activeFallback = colorFallback
                        && !uploadedColors.has(color)
                        && !removedProductImageIds.has(String(colorFallback.image_id || ''))
                        ? colorFallback
                        : null;
                    const activeSavedImage = savedImage
                        && !removedProductImageIds.has(String(savedImage.image_id || ''))
                        ? savedImage
                        : null;

                    addSavedVariantImage(activeSavedImage);
                    addSavedVariantImage(activeFallback);
                });
            }

            localMediaUrls.forEach((cached, id) => {
                if (!localIds.has(id)) {
                    URL.revokeObjectURL(cached.url);
                    localMediaUrls.delete(id);
                }
            });

            productMediaItems = items;
            mediaGalleryThumbnails.replaceChildren();
            if (mediaGalleryCount) {
                mediaGalleryCount.textContent = `${items.length} ${items.length === 1 ? 'image' : 'images'}`;
            }

            items.forEach((item) => {
                const mediaItem = document.createElement('div');
                mediaItem.className = 'product-media-gallery-item';
                mediaItem.dataset.productMediaItem = '';

                const thumbnailButton = document.createElement('button');
                thumbnailButton.type = 'button';
                thumbnailButton.className = 'product-media-gallery-thumbnail';
                thumbnailButton.dataset.productMediaThumbnail = '';
                thumbnailButton.dataset.mediaId = item.id;
                thumbnailButton.dataset.imageId = item.image_id ? String(item.image_id) : '';
                thumbnailButton.dataset.mediaKind = item.kind;
                thumbnailButton.dataset.color = item.color || '';
                thumbnailButton.dataset.variantId = item.variant_id ? String(item.variant_id) : '';
                thumbnailButton.dataset.variantLabel = item.variant_label || '';
                thumbnailButton.dataset.legacyColor = item.legacy_color ? 'true' : 'false';
                thumbnailButton.dataset.colorShared = item.color_shared ? 'true' : 'false';
                thumbnailButton.setAttribute('aria-label', `Show ${item.label}`);
                thumbnailButton.setAttribute('aria-pressed', 'false');

                const image = document.createElement('img');
                image.src = item.url;
                image.alt = '';
                image.loading = 'lazy';
                const label = document.createElement('span');
                label.textContent = item.label;
                thumbnailButton.append(image, label);

                const removeButton = document.createElement('button');
                removeButton.className = 'product-media-gallery-remove';
                removeButton.type = 'button';
                removeButton.dataset.productMediaRemove = '';
                removeButton.dataset.mediaId = item.id;
                removeButton.setAttribute('aria-label', `Remove ${item.label}`);
                removeButton.title = `Remove ${item.label}`;
                removeButton.textContent = '×';

                mediaItem.append(thumbnailButton, removeButton);
                mediaGalleryThumbnails.appendChild(mediaItem);
            });

            if (mediaGalleryEmpty) mediaGalleryEmpty.hidden = items.length > 0;
            setActiveProductMedia(activeProductMediaId);
            syncVariantRowImages();
        };

        mediaGalleryThumbnails?.addEventListener('click', (event) => {
            const removeButton = event.target.closest('[data-product-media-remove]');
            if (removeButton && mediaGalleryThumbnails.contains(removeButton)) {
                event.preventDefault();
                const item = productMediaItems.find((media) => media.id === removeButton.dataset.mediaId);
                if (item) removeProductMedia(item);

                return;
            }

            const thumbnailButton = event.target.closest('[data-product-media-thumbnail]');
            if (!thumbnailButton || !mediaGalleryThumbnails.contains(thumbnailButton)) return;

            setActiveProductMedia(thumbnailButton.dataset.mediaId);
        });

        const combinationLabels = () => {
            const first = splitValues(optionOneValues);
            const second = splitValues(optionTwoValues);

            if (!first.length && !second.length) return [];
            if (first.length && !second.length) return first;
            if (!first.length && second.length) return second;

            return first.flatMap((one) => second.map((two) => `${one} / ${two}`));
        };

        const updateVariantTotal = () => {
            const stockInputs = [...rowsHost.querySelectorAll('[data-variant-stock]')];
            const hasVariants = currentProductMode() === 'with_variants';
            const total = stockInputs.reduce((sum, input) => sum + Math.max(0, Number(input.value || 0)), 0);
            const reservedTotal = [...rowsHost.querySelectorAll('[data-variant-main-row]')]
                .reduce((sum, row) => sum + Math.max(0, Number(row.dataset.reservedStock || 0)), 0);

            if (totalNode) totalNode.textContent = `${total} ${total === 1 ? 'unit' : 'units'}`;

            if (totalStockInput) {
                totalStockInput.min = String(hasVariants ? reservedTotal : Number(productEditor.dataset.previewReservedStock || 0));
                if (hasVariants) {
                    totalStockInput.value = String(total);
                    totalStockInput.readOnly = true;
                    totalStockInput.setAttribute('aria-readonly', 'true');
                    totalStockInput.title = 'Calculated from the variant stock rows below.';
                } else {
                    totalStockInput.readOnly = false;
                    totalStockInput.removeAttribute('aria-readonly');
                    totalStockInput.removeAttribute('title');
                }
            }
        };

        const syncProductSkuControl = () => {
            if (!productSkuInput || !productSkuLabel || !productSkuManaged || !productSkuHelp) {
                return;
            }

            const labels = [...rowsHost.querySelectorAll('input[type="hidden"]')]
                .map((input) => input.value.trim())
                .filter(Boolean);
            const hasVariantMatrix = currentProductMode() === 'with_variants';

            if (hasVariantMatrix) {
                if (!productSkuInput.hidden) {
                    productSkuValue = productSkuInput.value;
                }

                productSkuInput.hidden = true;
                productSkuLabel.textContent = 'SKU management';
                productSkuManaged.hidden = false;
                productSkuHelp.textContent = 'Each size/color combination has its own SKU in Variant Inventory below.';
            } else {
                if (productSkuInput.hidden) {
                    productSkuInput.value = productSkuValue;
                }

                productSkuInput.hidden = false;
                productSkuLabel.textContent = 'Product SKU';
                productSkuManaged.hidden = true;
                productSkuHelp.textContent = 'Used when this product has no variants.';
            }
        };

        const renderVariants = (variants) => {
            rowsHost.replaceChildren();
            const defaultThreshold = globalThresholdInput?.value.trim() || '5';

            variants.forEach((variant, index) => {
                const row = document.createElement('tr');
                row.dataset.variantMainRow = '';
                row.dataset.variantIndex = String(index);
                row.dataset.variantId = variant.id ? String(variant.id) : '';
                row.dataset.variantLabel = variant.label || `Variant ${index + 1}`;
                row.dataset.originalRegularPrice = String(variant.original_regular_price ?? variant.price ?? '');
                row.dataset.originalSalePrice = String(variant.original_sale_price ?? variant.sale_price ?? variant.price ?? '');
                row.dataset.legacyDiscountPercent = String(variant.legacy_discount_percent ?? 0);
                const reservedStock = Math.max(0, Number(variant.reserved_stock ?? variant.reservedStock ?? 0));
                row.dataset.reservedStock = String(reservedStock);

                const labelCell = document.createElement('td');
                const variantLabelDisplay = document.createElement('div');
                variantLabelDisplay.className = 'variant-row-label';
                const variantImageThumb = document.createElement('span');
                variantImageThumb.className = 'variant-row-image';
                variantImageThumb.dataset.variantRowImage = '';
                variantImageThumb.setAttribute('aria-hidden', 'true');
                const variantImage = document.createElement('img');
                variantImage.alt = '';
                variantImage.hidden = true;
                const variantImageEmpty = document.createElement('span');
                variantImageEmpty.className = 'variant-row-image-empty';
                variantImageEmpty.textContent = 'No photo';
                variantImageThumb.append(variantImage, variantImageEmpty);
                const labelStrong = document.createElement('strong');
                labelStrong.textContent = variant.label || `Variant ${index + 1}`;
                variantLabelDisplay.append(variantImageThumb, labelStrong);
                labelCell.appendChild(variantLabelDisplay);

                const packageTargetLabel = document.createElement('label');
                packageTargetLabel.className = 'variant-package-copy-target';
                const packageTarget = document.createElement('input');
                packageTarget.type = 'checkbox';
                packageTarget.checked = Boolean(variant.package_selected);
                packageTarget.dataset.variantPackageCopyTarget = '';
                packageTarget.setAttribute('aria-label', `Select ${variant.sku || variant.label || `variant ${index + 1}`} as a package-details copy target`);
                packageTarget.addEventListener('change', updatePackageCopyControls);
                const packageTargetText = document.createElement('span');
                packageTargetText.textContent = 'Select for package copy';
                packageTargetLabel.append(packageTarget, packageTargetText);
                labelCell.appendChild(packageTargetLabel);

                const hiddenLabel = document.createElement('input');
                hiddenLabel.type = 'hidden';
                hiddenLabel.name = `variants[${index}][label]`;
                hiddenLabel.value = variant.label || `Variant ${index + 1}`;
                labelCell.appendChild(hiddenLabel);

                const skuCell = document.createElement('td');
                const skuInput = document.createElement('input');
                skuInput.type = 'text';
                skuInput.name = `variants[${index}][sku]`;
                skuInput.value = variant.sku || '';
                skuInput.maxLength = 80;
                skuInput.placeholder = 'Auto-generated on save';
                skuCell.appendChild(skuInput);

                const priceCell = document.createElement('td');
                const priceInput = document.createElement('input');
                priceInput.type = 'number';
                priceInput.name = `variants[${index}][price]`;
                priceInput.min = '0';
                priceInput.step = '0.01';
                priceInput.value = variant.price ?? '';
                priceInput.placeholder = '0.00';
                priceInput.required = true;
                priceInput.setAttribute('aria-label', `Regular price for ${variant.label || `Variant ${index + 1}`}`);
                priceCell.appendChild(priceInput);

                const stockCell = document.createElement('td');
                const stockInput = document.createElement('input');
                stockInput.type = 'number';
                stockInput.name = `variants[${index}][stock]`;
                stockInput.min = String(reservedStock);
                stockInput.value = variant.stock ?? 0;
                stockInput.required = true;
                stockInput.dataset.variantStock = '';
                stockInput.addEventListener('input', updateVariantTotal);
                stockCell.appendChild(stockInput);
                const reservedNote = document.createElement('small');
                reservedNote.className = 'variant-reserved-stock-note';
                reservedNote.textContent = `Reserved: ${reservedStock}`;
                stockCell.appendChild(reservedNote);

                const thresholdCell = document.createElement('td');
                const thresholdInput = document.createElement('input');
                thresholdInput.type = 'number';
                thresholdInput.name = `variants[${index}][low_stock_threshold]`;
                thresholdInput.min = '0';
                thresholdInput.max = '4294967295';
                thresholdInput.step = '1';
                thresholdInput.value = variant.low_stock_threshold ?? defaultThreshold;
                thresholdInput.required = true;
                thresholdInput.dataset.variantThreshold = '';
                thresholdInput.setAttribute('aria-label', `Low-stock alert threshold for ${variant.label || `Variant ${index + 1}`}`);
                thresholdCell.appendChild(thresholdInput);

                const packageRow = document.createElement('tr');
                packageRow.className = 'variant-package-row';
                packageRow.dataset.variantPackageRow = '';
                const packageCell = document.createElement('td');
                packageCell.colSpan = 5;

                const packageToolbar = document.createElement('div');
                packageToolbar.className = 'variant-package-row-toolbar';
                const overrideLabel = document.createElement('label');
                overrideLabel.className = 'variant-package-override';
                overrideLabel.dataset.variantPackageOverrideLabel = '';
                overrideLabel.hidden = true;
                const overrideInput = document.createElement('input');
                overrideInput.type = 'checkbox';
                overrideInput.name = `package_overrides[${index}]`;
                overrideInput.value = '1';
                overrideInput.checked = Boolean(variant.package_override);
                overrideInput.dataset.variantPackageOverride = '';
                overrideInput.setAttribute('aria-label', `Use custom package details for ${variant.label || `variant ${index + 1}`}`);
                const overrideText = document.createElement('span');
                overrideText.textContent = 'Use custom package details for this SKU';
                overrideLabel.append(overrideInput, overrideText);
                overrideInput.addEventListener('change', () => {
                    if (overrideInput.checked) packageDetails.open = true;
                    syncVariantPackageDetails(packageDetails);
                    updatePackageProgress();
                });
                packageToolbar.appendChild(overrideLabel);

                const packageDetails = document.createElement('details');
                packageDetails.className = 'variant-package-details';
                packageDetails.dataset.variantPackageDetails = '';
                const packageValidationErrors = Array.isArray(variant.package_validation_errors)
                    ? variant.package_validation_errors
                    : [];
                row.classList.toggle('has-package-server-error', packageValidationErrors.length > 0);
                packageRow.classList.toggle('has-package-server-error', packageValidationErrors.length > 0);
                packageDetails.open = Boolean(variant.packageDetailsOpen) || packageValidationErrors.length > 0;
                const packageSummary = document.createElement('summary');
                const packageSummaryLabel = document.createElement('strong');
                packageSummaryLabel.textContent = 'SKU details';
                const packageSummaryStatus = document.createElement('span');
                packageSummaryStatus.dataset.variantPackageSummary = '';
                packageSummary.append(packageSummaryLabel, packageSummaryStatus);
                const packageGrid = document.createElement('div');
                packageGrid.className = 'variant-package-grid';
                packageGrid.dataset.variantPackageGrid = '';
                const sharedPackageNote = document.createElement('small');
                sharedPackageNote.className = 'variant-package-shared-note';
                sharedPackageNote.dataset.variantPackageSharedNote = '';
                sharedPackageNote.hidden = true;

                const packageInputs = [
                    ['package_weight_kg', 'Weight (kg)', '0.001', '1000', '0.001', '0.350'],
                    ['package_length_cm', 'Length (cm)', '0.001', '1000', '0.001', '24.000'],
                    ['package_width_cm', 'Width (cm)', '0.001', '1000', '0.001', '18.000'],
                    ['package_height_cm', 'Height (cm)', '0.001', '1000', '0.001', '8.000'],
                ].map(([field, labelText, minimum, maximum, step, placeholder]) => {
                    const label = document.createElement('label');
                    label.className = 'variant-package-field';
                    const labelCopy = document.createElement('span');
                    labelCopy.textContent = labelText;
                    const requiredMark = document.createElement('em');
                    requiredMark.setAttribute('aria-hidden', 'true');
                    requiredMark.textContent = ' *';
                    labelCopy.appendChild(requiredMark);

                    const input = document.createElement('input');
                    input.type = 'number';
                    input.name = `variants[${index}][${field}]`;
                    input.dataset.variantPackageInput = field;
                    input.dataset.packageMin = minimum;
                    input.dataset.packageMax = maximum;
                    input.dataset.packageStep = step;
                    input.value = variant[field] ?? '';
                    input.placeholder = `e.g. ${placeholder}`;
                    input.setAttribute('aria-label', `${labelText} for ${variant.label || `Variant ${index + 1}`}`);
                    input.addEventListener('input', () => {
                        updateVariantPackageSummary(packageDetails);
                        updatePackageProgress();
                    });
                    label.append(labelCopy, input);
                    return label;
                });

                packageDetails.addEventListener('toggle', () => syncVariantPackageDetails(packageDetails));
                row.append(labelCell, skuCell, priceCell, stockCell, thresholdCell);
                packageGrid.append(...packageInputs);
                packageDetails.append(packageSummary, sharedPackageNote, packageGrid);
                packageCell.append(packageToolbar, packageDetails);
                packageRow.appendChild(packageCell);
                rowsHost.append(row, packageRow);
                syncVariantPackageDetails(packageDetails);
                updateVariantPackageSummary(packageDetails);
            });

            const hasRows = variants.length > 0;
            if (tableWrap) tableWrap.hidden = !hasRows;
            if (emptyState) emptyState.hidden = hasRows;
            syncPackageModeUI();
            renderVariantColorImageUploads(variants);
            updateVariantTotal();
            syncProductSkuControl();
            renderProductMediaPreview();
            syncBuyerProductPreview();
            updateSubmitPreflightErrors();

            if (window.lucide?.createIcons) window.lucide.createIcons();
        };

        const syncProductMode = () => {
            const withVariants = currentProductMode() === 'with_variants';

            if (singlePriceControl) singlePriceControl.hidden = withVariants;
            if (basePriceInput) {
                basePriceInput.disabled = withVariants;
                basePriceInput.required = !withVariants;
            }
            if (singlePricePreview) singlePricePreview.hidden = withVariants;
            if (singleCommissionPreview) singleCommissionPreview.hidden = withVariants;
            if (variantPricingNote) variantPricingNote.hidden = !withVariants;

            if (singlePackageDetails) {
                singlePackageDetails.hidden = withVariants;
                singlePackageDetails.querySelectorAll('input').forEach((input) => {
                    input.disabled = withVariants;
                    input.required = !withVariants;
                });
            }

            if (globalThresholdControl) {
                globalThresholdControl.hidden = withVariants;
                if (globalThresholdInput) globalThresholdInput.disabled = withVariants;
            }

            if (variantConfiguration) {
                variantConfiguration.hidden = !withVariants;
                variantConfiguration.querySelectorAll('input, select, textarea, button').forEach((control) => {
                    if (control.matches('[data-variant-package-input]')) return;
                    control.disabled = !withVariants;
                });
            }
            syncPackageModeUI();

            productModePicker?.querySelectorAll('[data-product-mode-card]').forEach((card) => {
                card.classList.toggle('is-selected', card.dataset.productModeCard === currentProductMode());
            });

            updateVariantTotal();
            syncProductSkuControl();
            renderVariantColorImageUploads(readRenderedVariants());
            renderProductMediaPreview();
            syncBuyerProductPreview();
            updateSubmitPreflightErrors();

        };

        const updateProductFieldGuidance = () => {
            const selectedOption = marketplaceSubcategorySelect?.selectedOptions?.[0];
            const namePlaceholder = selectedOption?.dataset.namePlaceholder
                || productEditor.dataset.guidanceNamePlaceholder;
            const nameHint = selectedOption?.dataset.nameHint
                || productEditor.dataset.guidanceNameHint;
            const descriptionPlaceholder = selectedOption?.dataset.descriptionPlaceholder
                || productEditor.dataset.guidanceDescriptionPlaceholder;
            const descriptionHint = selectedOption?.dataset.descriptionHint
                || productEditor.dataset.guidanceDescriptionHint;

            if (productNameInput && namePlaceholder) productNameInput.placeholder = namePlaceholder;
            if (productNameGuidance && nameHint) productNameGuidance.textContent = nameHint;
            if (productDescriptionInput && descriptionPlaceholder) productDescriptionInput.placeholder = descriptionPlaceholder;
            if (productDescriptionGuidance && descriptionHint) productDescriptionGuidance.textContent = descriptionHint;
        };

        const readRenderedVariants = () => [...rowsHost.querySelectorAll('[data-variant-main-row]')]
            .map((row) => {
                const packageRow = row.nextElementSibling?.matches('[data-variant-package-row]')
                    ? row.nextElementSibling
                    : null;
                const packageDetails = packageRow?.querySelector('[data-variant-package-details]');

                return {
                    id: row.dataset.variantId || null,
                    label: row.querySelector('input[type="hidden"]')?.value || '',
                    package_override: packageOverrideEnabled(packageRow),
                    package_selected: Boolean(row.querySelector('[data-variant-package-copy-target]')?.checked),
                    sku: row.querySelector('input[name*="[sku]"]')?.value || '',
                    price: row.querySelector('input[name*="[price]"]')?.value || '',
                    original_regular_price: row.dataset.originalRegularPrice || '',
                    original_sale_price: row.dataset.originalSalePrice || '',
                    legacy_discount_percent: Number(row.dataset.legacyDiscountPercent || 0),
                    stock: row.querySelector('input[name*="[stock]"]')?.value || 0,
                    low_stock_threshold: row.querySelector('[data-variant-threshold]')?.value ?? '',
                    package_weight_kg: packageRow?.querySelector('input[name*="[package_weight_kg]"]')?.value ?? '',
                    package_length_cm: packageRow?.querySelector('input[name*="[package_length_cm]"]')?.value ?? '',
                    package_width_cm: packageRow?.querySelector('input[name*="[package_width_cm]"]')?.value ?? '',
                    package_height_cm: packageRow?.querySelector('input[name*="[package_height_cm]"]')?.value ?? '',
                    packageDetailsOpen: Boolean(packageDetails?.open),
                    reservedStock: Number(row.dataset.reservedStock || 0),
                };
            })
            .filter((variant) => variant.label.trim() !== '');

        const colorForVariant = (label) => {
            const parts = String(label || '').split('/').map((part) => part.trim());
            const firstName = String(optionOneName?.value || '').trim().toLocaleLowerCase();
            const secondName = String(optionTwoName?.value || '').trim().toLocaleLowerCase();

            if (['color', 'colour'].includes(firstName)) return parts[0] || '';
            if (['color', 'colour'].includes(secondName)) {
                // A one-option Group 2 variant is serialized as a one-part label.
                return splitValues(optionOneValues).length ? (parts[1] || '') : (parts[0] || '');
            }

            return '';
        };

        const normalizedColorKey = (color) => String(color || '').trim().toLocaleLowerCase();

        const colorValuesFromOptions = () => {
            const firstName = String(optionOneName?.value || '').trim().toLocaleLowerCase();
            const secondName = String(optionTwoName?.value || '').trim().toLocaleLowerCase();

            if (['color', 'colour'].includes(firstName)) return splitValues(optionOneValues);
            if (['color', 'colour'].includes(secondName)) return splitValues(optionTwoValues);

            return [];
        };

        const colorsForVariantImages = (variants) => {
            const generatedColors = variants
                .map((variant) => colorForVariant(variant.label))
                .filter(Boolean);
            const colors = [...colorValuesFromOptions(), ...generatedColors];

            return colors.filter((color, index) => colors.findIndex((candidate) =>
                normalizedColorKey(candidate) === normalizedColorKey(color)) === index);
        };

        const savedImageForColor = (color) => {
            const key = normalizedColorKey(color);
            if (!key) return null;

            return savedProductMedia.find((item) => item.kind === 'variant'
                && (item.color_shared || item.legacy_color)
                && normalizedColorKey(item.color) === key
                && !removedProductImageIds.has(String(item.image_id || '')))
                || savedProductMedia.find((item) => item.kind === 'variant'
                    && !(item.color_shared || item.legacy_color)
                    && normalizedColorKey(item.color) === key
                    && !removedProductImageIds.has(String(item.image_id || '')))
                || null;
        };

        const renderVariantColorImageUploads = (variants) => {
            if (!variantColorImageInput || !variantColorImageUploads) return;

            const variantsEnabled = currentProductMode() === 'with_variants';
            variantColorImageInput.disabled = !variantsEnabled;
            const colors = colorsForVariantImages(variants);
            const files = [...(variantColorImageInput.files || [])];
            const availableColors = new Set(colors.map(normalizedColorKey));
            variantColorImageUploads.replaceChildren();

            if (variantColorImageEmpty) {
                variantColorImageEmpty.hidden = variantsEnabled && colors.length > 0 && files.length > 0;
                variantColorImageEmpty.textContent = !variantsEnabled
                    ? 'Choose With Variants and add a Color option to use color-specific images.'
                    : colors.length === 0
                        ? 'Add a Color option and its values above. You can upload images here before generating variants.'
                        : 'No variant images selected yet. Each uploaded image can be assigned to a color.';
            }

            [...variantImageColorAssignments.keys()].forEach((index) => {
                if (index >= files.length) variantImageColorAssignments.delete(index);
            });

            const countsByColor = new Map();
            files.forEach((file, index) => {
                let color = variantImageColorAssignments.get(index) || '';
                const matchingColor = colors.find((candidate) => normalizedColorKey(candidate) === normalizedColorKey(color));
                if (matchingColor) color = matchingColor;
                if (!color && colors.length && !variantImageColorAssignments.has(index)) {
                    const filename = file.name.toLocaleLowerCase().replace(/[^a-z0-9]+/g, '');
                    const filenameMatch = colors.find((candidate) => filename.includes(
                        candidate.toLocaleLowerCase().replace(/[^a-z0-9]+/g, ''),
                    ));
                    const unusedColor = colors.find((candidate) => !countsByColor.has(normalizedColorKey(candidate)));
                    color = filenameMatch || unusedColor || '';
                    if (color) variantImageColorAssignments.set(index, color);
                }
                if (color) {
                    const key = normalizedColorKey(color);
                    countsByColor.set(key, (countsByColor.get(key) || 0) + 1);
                }

                const mediaId = `variant-color-upload:${index}`;
                const savedImage = savedImageForColor(color);
                const localImage = localMediaItem(mediaId, `${color || 'Unassigned'} variant image`, 'variant', file, {
                    variant_id: null,
                    variant_label: '',
                    color,
                    color_shared: Boolean(color),
                    legacy_color: false,
                });

                const row = document.createElement('div');
                row.className = 'product-variant-image-upload-row';

                const preview = document.createElement('div');
                preview.className = 'product-variant-image-preview';
                preview.setAttribute('aria-hidden', 'true');
                if (localImage?.url || savedImage?.url) {
                    const image = document.createElement('img');
                    image.src = localImage?.url || savedImage.url;
                    image.alt = '';
                    preview.appendChild(image);
                } else {
                    preview.textContent = 'No image';
                }

                const copy = document.createElement('div');
                copy.className = 'product-variant-image-copy';
                const filename = document.createElement('strong');
                filename.textContent = file.name;

                const assignmentLabel = document.createElement('label');
                assignmentLabel.className = 'product-variant-image-assignment';
                const assignmentText = document.createElement('span');
                assignmentText.textContent = 'Use for color';
                const colorSelect = document.createElement('select');
                colorSelect.name = `variant_image_colors[${index}]`;
                colorSelect.dataset.variantImageColorSelect = '';
                colorSelect.setAttribute('aria-label', `Color for variant image ${file.name}`);

                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = colors.length ? 'Choose a color' : 'Add Color option above';
                colorSelect.appendChild(placeholder);

                if (color && !availableColors.has(normalizedColorKey(color))) {
                    const removedColor = document.createElement('option');
                    removedColor.value = color;
                    removedColor.textContent = `${color} (not in current options)`;
                    colorSelect.appendChild(removedColor);
                }

                colors.forEach((availableColor) => {
                    const option = document.createElement('option');
                    option.value = availableColor;
                    option.textContent = availableColor;
                    colorSelect.appendChild(option);
                });
                colorSelect.value = color;
                colorSelect.disabled = !variantsEnabled || colors.length === 0;
                colorSelect.addEventListener('change', () => {
                    variantImageColorAssignments.set(index, colorSelect.value);
                    renderVariantColorImageUploads(readRenderedVariants());
                    renderProductMediaPreview();
                    syncBuyerProductPreview();
                    variantColorImageUploads
                        .querySelectorAll('[data-variant-image-color-select]')[index]
                        ?.focus();
                });

                const help = document.createElement('small');
                help.className = 'product-variant-image-help';
                const duplicateColor = color && countsByColor.get(normalizedColorKey(color)) > 1;
                help.textContent = duplicateColor
                    ? 'Only one image can be assigned to each color.'
                    : color
                        ? `Used for every generated variant with ${color}.`
                        : 'Assign a color after adding a Color option and its values.';
                if (duplicateColor) help.classList.add('is-error');

                assignmentLabel.append(assignmentText, colorSelect, help);
                copy.append(filename, assignmentLabel);

                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'product-variant-image-remove';
                removeButton.textContent = 'Remove';
                removeButton.setAttribute('aria-label', `Remove ${file.name} from variant images`);
                removeButton.addEventListener('click', () => removeVariantColorImage(index));

                row.append(preview, copy, removeButton);
                variantColorImageUploads.appendChild(row);
            });

            if (variantColorImageEmpty) {
                variantColorImageEmpty.hidden = variantsEnabled && colors.length > 0 && files.length > 0;
            }
        };

        const removeVariantColorImage = (removedIndex) => {
            if (!variantColorImageInput) return;

            const files = [...(variantColorImageInput.files || [])];
            if (removedIndex < 0 || removedIndex >= files.length) return;

            const assignments = new Map(variantImageColorAssignments);
            const transfer = new window.DataTransfer();
            files.forEach((file, index) => {
                if (index !== removedIndex) transfer.items.add(file);
            });
            variantColorImageInput.files = transfer.files;

            variantImageColorAssignments.clear();
            [...(variantColorImageInput.files || [])].forEach((_, newIndex) => {
                const oldIndex = newIndex < removedIndex ? newIndex : newIndex + 1;
                const assignedColor = assignments.get(oldIndex);
                if (assignedColor) variantImageColorAssignments.set(newIndex, assignedColor);
            });

            renderVariantColorImageUploads(readRenderedVariants());
            renderProductMediaPreview();
            syncBuyerProductPreview();
        };

        const syncVariantRowImages = () => {
            const productImage = productMediaItems.find((item) => item.kind === 'primary')
                || productMediaItems.find((item) => item.kind === 'gallery');

            rowsHost.querySelectorAll('[data-variant-main-row]').forEach((row) => {
                const variantId = row.dataset.variantId || '';
                const variantLabel = row.dataset.variantLabel || '';
                const color = normalizedColorKey(colorForVariant(variantLabel));
                const exactImage = productMediaItems.find((item) => item.kind === 'variant'
                    && !item.color_shared
                    && (variantId
                        ? String(item.variant_id || '') === variantId
                        : String(item.variant_label || '').toLocaleLowerCase() === variantLabel.toLocaleLowerCase()));
                const sharedColorImage = color
                    ? productMediaItems.find((item) => item.kind === 'variant'
                        && item.color_shared
                        && !item.legacy_color
                        && normalizedColorKey(item.color) === color)
                    : null;
                const legacyColorImage = color
                    ? productMediaItems.find((item) => item.kind === 'variant'
                        && item.legacy_color
                        && normalizedColorKey(item.color) === color)
                    : null;
                const selectedImage = sharedColorImage || exactImage || legacyColorImage || productImage;
                const thumbnail = row.querySelector('[data-variant-row-image]');
                const image = thumbnail?.querySelector('img');
                const empty = thumbnail?.querySelector('.variant-row-image-empty');

                if (image) {
                    image.hidden = !selectedImage;
                    if (selectedImage) image.src = selectedImage.url;
                }
                if (empty) empty.hidden = Boolean(selectedImage);
            });
        };

        const syncBuyerProductPreview = () => {
            if (!buyerPreview) return;

            const withVariants = currentProductMode() === 'with_variants';
            const variants = readRenderedVariants();
            let selectedVariant = null;

            if (buyerPreviewVariantField) buyerPreviewVariantField.hidden = !withVariants;

            if (withVariants && buyerPreviewVariant) {
                const previousLabel = buyerPreviewVariant.value;
                buyerPreviewVariant.replaceChildren();

                if (variants.length) {
                    variants.forEach((variant) => {
                        const option = document.createElement('option');
                        option.value = variant.label;
                        option.textContent = variant.label;
                        buyerPreviewVariant.appendChild(option);
                    });

                    buyerPreviewVariant.value = variants.some((variant) => variant.label === previousLabel)
                        ? previousLabel
                        : variants[0].label;
                    buyerPreviewVariant.disabled = false;
                    selectedVariant = variants.find((variant) => variant.label === buyerPreviewVariant.value) || variants[0];
                } else {
                    const option = document.createElement('option');
                    option.value = '';
                    option.textContent = 'Generate variants to preview options';
                    buyerPreviewVariant.appendChild(option);
                    buyerPreviewVariant.disabled = true;
                }
            }

            const variantPrice = withVariants
                ? (selectedVariant?.price ?? '')
                : basePriceInput?.value;
            const regularPrice = Math.max(0, Number(variantPrice || 0));
            const discount = withVariants
                ? Math.min(90, Math.max(0, Number(selectedVariant?.legacy_discount_percent || 0)))
                : productDiscountPercent;
            const storedRegular = Number(selectedVariant?.original_regular_price);
            const storedSale = Number(selectedVariant?.original_sale_price);
            const unchangedVariantPrice = withVariants
                && Number.isFinite(storedRegular)
                && regularPrice === storedRegular
                && Number.isFinite(storedSale);
            const salePrice = unchangedVariantPrice
                ? storedSale
                : Math.round((regularPrice * (1 - (discount / 100)) + Number.EPSILON) * 100) / 100;
            if (buyerPreviewPrice) buyerPreviewPrice.textContent = formatPeso(salePrice);
            if (buyerPreviewRegular) {
                buyerPreviewRegular.textContent = formatPeso(regularPrice);
                buyerPreviewRegular.hidden = discount <= 0;
            }
            if (buyerPreviewDiscount) {
                buyerPreviewDiscount.textContent = `${discount}% off`;
                buyerPreviewDiscount.hidden = discount <= 0;
            }
            if (buyerPreviewName) {
                buyerPreviewName.textContent = productNameInput?.value.trim() || 'Product name';
            }

            if (buyerPreviewVariantSummary) {
                buyerPreviewVariantSummary.textContent = selectedVariant?.label || 'Single product';
            }

            if (buyerPreviewPayments) {
                buyerPreviewPayments.replaceChildren();
                const selectedMethods = [...productEditor.querySelectorAll('input[name="payment_methods[]"]:checked')];
                selectedMethods.forEach((input) => {
                    const method = document.createElement('span');
                    method.textContent = input.value === 'cod' ? 'Cash on Delivery' : 'Online';
                    buyerPreviewPayments.appendChild(method);
                });
                if (!selectedMethods.length) {
                    const empty = document.createElement('span');
                    empty.textContent = 'No payment methods selected';
                    buyerPreviewPayments.appendChild(empty);
                }
            }

            const color = selectedVariant ? normalizedColorKey(colorForVariant(selectedVariant.label)) : '';
            const variantImage = selectedVariant
                ? productMediaItems.find((item) => item.kind === 'variant'
                    && !item.color_shared
                    && (selectedVariant.id
                        ? String(item.variant_id || '') === String(selectedVariant.id)
                        : String(item.variant_label || '').toLocaleLowerCase() === selectedVariant.label.toLocaleLowerCase()))
                : null;
            const sharedColorImage = color
                ? productMediaItems.find((item) => item.kind === 'variant'
                    && item.color_shared
                    && !item.legacy_color
                    && normalizedColorKey(item.color) === color)
                : null;
            const legacyColorImage = color
                ? productMediaItems.find((item) => item.kind === 'variant'
                    && item.legacy_color
                    && normalizedColorKey(item.color) === color)
                : null;
            const defaultImage = productMediaItems.find((item) => item.kind === 'primary') || productMediaItems[0];
            const selectionKey = withVariants ? `variant:${selectedVariant?.label || ''}` : 'single';
            const preferredImage = sharedColorImage || variantImage || legacyColorImage || defaultImage;

            if (selectionKey !== lastBuyerPreviewSelection
                || lastBuyerPreviewMediaId === null
                || (activeProductMediaId === lastBuyerPreviewMediaId && preferredImage?.id !== lastBuyerPreviewMediaId)) {
                lastBuyerPreviewSelection = selectionKey;
                if (preferredImage) setActiveProductMedia(preferredImage.id);
            }
            lastBuyerPreviewMediaId = preferredImage?.id || null;
        };

        const removeProductMedia = (selected) => {
            const localFile = localMediaUrls.get(selected.id);

            if (localFile) {
                if (selected.kind === 'primary' && primaryImageInput) {
                    primaryImageInput.value = '';
                } else if (selected.kind === 'gallery' && galleryImagesInput) {
                    const index = Number(selected.id.replace('gallery-', ''));
                    const transfer = new window.DataTransfer();
                    [...galleryImagesInput.files].forEach((file, fileIndex) => {
                        if (fileIndex !== index) transfer.items.add(file);
                    });
                    galleryImagesInput.files = transfer.files;
                } else if (selected.kind === 'variant') {
                    const index = Number(selected.id.replace('variant-color-upload:', ''));
                    removeVariantColorImage(index);
                }

                renderProductMediaPreview();
                syncBuyerProductPreview();
                if (mediaStatus) {
                    mediaStatus.textContent = 'Selected photo removed. Save the product to keep your changes.';
                    mediaStatus.hidden = false;
                }

                return;
            }

            const imageId = String(selected.image_id || '');
            if (!imageId || removedProductImageIds.has(imageId) || !mediaRemovalHost) return;

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'remove_product_images[]';
            input.value = imageId;
            mediaRemovalHost.appendChild(input);
            removedProductImageIds.add(imageId);

            if (selected.kind === 'variant') {
                renderVariants(readRenderedVariants());
            } else {
                renderProductMediaPreview();
            }
            syncBuyerProductPreview();
            if (mediaStatus) {
                mediaStatus.textContent = `${selected.label} marked for removal. Save the product to apply.`;
                mediaStatus.hidden = false;
            }
        };

        const mergeVariantsByLabel = (...groups) => {
            const variants = new Map();

            groups.flat().forEach((variant) => {
                const label = (variant.label || '').trim();
                const key = label.toLocaleLowerCase();

                if (!label || variants.has(key)) {
                    return;
                }

                variants.set(key, { ...variant, label });
            });

            return [...variants.values()];
        };

        let existingVariants = [];
        try {
            existingVariants = JSON.parse(existingScript?.textContent || '[]');
            if (!Array.isArray(existingVariants)) existingVariants = [];
        } catch (_) {
            existingVariants = [];
        }

        if (currentProductMode() === 'with_variants' && existingVariants.length) {
            renderVariants(existingVariants);
        } else {
            renderVariants([]);
            updateVariantTotal();
            syncProductSkuControl();
            syncBuyerProductPreview();
        }

        syncProductMode();
        updateProductFieldGuidance();
        productModeChoices.forEach((input) => input.addEventListener('change', () => {
            syncProductMode();
            if (input.value === 'with_variants' && rowsHost.querySelector('[data-variant-main-row]') === null) {
                renderVariants(existingVariants);
            }
        }));
        marketplaceSubcategorySelect?.addEventListener('change', updateProductFieldGuidance);
        sharedPackageMode?.addEventListener('change', () => {
            lastIncompleteVariantKey = '';
            syncPackageModeUI();
            setPackageActionStatus(sharedPackageModeEnabled()
                ? 'Per-SKU package values are preserved. Unchecked overrides will use the shared values on save.'
                : 'Per-SKU package details are active; existing values were kept.');
        });
        Object.values(sharedPackageInputs).forEach((input) => input?.addEventListener('input', () => {
            syncPackageModeUI();
            setPackageActionStatus('Shared package values updated. Each non-overridden SKU will save its own copy.');
        }));
        packageCopySource?.addEventListener('change', updatePackageCopyControls);
        packageCopyButton?.addEventListener('click', copyPackageDetailsToSelected);
        findIncompletePackageButton?.addEventListener('click', () => focusIncompletePackage());
        paymentMethodInputs.forEach((input) => input.addEventListener('change', updateSubmitPreflightErrors));
        productEditor.addEventListener('invalid', (event) => {
            const input = event.target;
            const details = input.closest('[data-variant-package-details]');
            if (details) {
                details.open = true;
                syncVariantPackageDetails(details);
                updateVariantPackageSummary(details);
            }
            input.setAttribute('aria-invalid', 'true');
        }, true);
        productEditor.addEventListener('input', (event) => {
            if (event.target.matches('input, select, textarea') && event.target.validity.valid) {
                event.target.removeAttribute('aria-invalid');
            }
        });
        variantColorImageInput?.addEventListener('change', () => {
            variantImageColorAssignments.clear();
            renderVariantColorImageUploads(readRenderedVariants());
            renderProductMediaPreview();
            syncBuyerProductPreview();
            if (mediaStatus) {
                mediaStatus.textContent = 'Variant image previews updated. Save the product to keep your changes.';
                mediaStatus.hidden = false;
            }
        });

        productEditor.addEventListener('submit', (event) => {
            const missingPaymentMethod = !paymentMethodInputs.some((input) => input.checked);
            const missingVariants = currentProductMode() === 'with_variants'
                && rowsHost.querySelector('[data-variant-main-row]') === null;

            if (paymentMethodClientError) paymentMethodClientError.hidden = !missingPaymentMethod;
            if (variantClientError) variantClientError.hidden = !missingVariants;
            paymentMethodInputs.forEach((input) => {
                if (missingPaymentMethod) input.setAttribute('aria-invalid', 'true');
                else input.removeAttribute('aria-invalid');
            });

            if (missingPaymentMethod || missingVariants) {
                event.preventDefault();

                if (missingPaymentMethod) {
                    paymentMethodInputs[0]?.focus();
                } else {
                    generateButton?.focus();
                }

                return;
            }

            if (currentProductMode() !== 'with_variants') return;

            const packageDetails = [...rowsHost.querySelectorAll('[data-variant-package-details]')];
            const originalDisclosureStates = new Map(packageDetails.map((details) => [details, details.open]));
            packageDetails.forEach((details) => {
                details.open = true;
                syncVariantPackageDetails(details);
            });

            const incompletePackageRow = variantMainRows().find((row) =>
                !packageValuesAreComplete(effectivePackageValuesForRow(row)));

            if (incompletePackageRow) {
                event.preventDefault();
                const incompleteDetails = packageRowForVariant(incompletePackageRow)?.querySelector('[data-variant-package-details]');
                packageDetails.forEach((details) => {
                    details.open = details === incompleteDetails;
                    syncVariantPackageDetails(details);
                });
                focusIncompletePackage(incompletePackageRow);
                return;
            }

            packageDetails.forEach((details) => {
                details.open = originalDisclosureStates.get(details) ?? false;
                syncVariantPackageDetails(details);
            });
            prepareSharedPackageSubmission();
        });
        [optionOneName, optionOneValues, optionTwoName, optionTwoValues].forEach((input) => {
            input?.addEventListener('input', () => {
                renderVariantColorImageUploads(readRenderedVariants());
                renderProductMediaPreview();
                syncBuyerProductPreview();
            });
        });

        buyerPreviewVariant?.addEventListener('change', syncBuyerProductPreview);
        productEditor.addEventListener('input', (event) => {
            if (event.target.matches(
                'input[name="name"], [data-product-description], [data-product-price], [data-product-total-stock], input[name$="[price]"], input[name$="[stock]"]'
            )) {
                syncBuyerProductPreview();
            }
        });
        productEditor.addEventListener('change', (event) => {
            if (event.target.matches('[data-variant-color-image-input]')) return;
            if (event.target.matches('input[type="file"], input[name="payment_methods[]"]')) {
                renderProductMediaPreview();
                syncBuyerProductPreview();
                updateSubmitPreflightErrors();
            }
        });

        generateButton?.addEventListener('click', () => {
            const currentVariants = readRenderedVariants();
            const labels = combinationLabels();
            const currentByLabel = new Map(
                currentVariants.map((variant) => [variant.label.toLocaleLowerCase(), variant])
            );

            if (!labels.length) {
                renderVariants(currentVariants);

                return;
            }

            const generated = labels.map((label) => currentByLabel.get(label.toLocaleLowerCase()) || {
                label,
                sku: '',
                price: '',
                sale_price: '',
                legacy_discount_percent: 0,
                stock: 0,
                package_weight_kg: '',
                package_length_cm: '',
                package_width_cm: '',
                package_height_cm: '',
            });

            const onlyDefaultVariant = currentVariants.length === 1
                && currentVariants[0].label.trim().toLowerCase() === 'default';
            const retained = onlyDefaultVariant
                ? currentVariants.filter((variant) => labels.includes(variant.label))
                : currentVariants;

            renderVariants(mergeVariantsByLabel(retained, generated));
        });

    }


    /* Rich order modal: use the row as the source of preview details. */
    const orderModal = document.querySelector('[data-modal="order-details"]');
    const fillOrderModal = (trigger) => {
        if (!orderModal) return;
        const row = trigger.closest('[data-order-row]');
        if (!row) return;

        const assign = (selector, value) => {
            const node = orderModal.querySelector(selector);
            if (node) node.textContent = value || '—';
        };

        assign('[data-order-detail-id]', trigger.dataset.orderDetails || row.querySelector('[data-order-details]')?.dataset.orderDetails);
        assign('[data-order-detail-customer]', row.dataset.orderCustomer);
        assign('[data-order-detail-items]', row.dataset.orderItems);
        assign('[data-order-detail-payment]', row.dataset.orderPaymentCopy);
        assign('[data-order-detail-total]', row.dataset.orderTotal);
        assign('[data-order-detail-canonical]', row.dataset.orderCanonical);
        assign('[data-order-detail-owner]', row.dataset.orderOwner);
        assign('[data-order-detail-next]', row.dataset.orderNext);

        const action = orderModal.querySelector('[data-order-modal-action]');
        const rowAction = row.querySelector('.order-row-action');
        if (action && rowAction) action.textContent = rowAction.textContent.trim();
    };

    document.querySelectorAll('[data-order-details]').forEach((trigger) => {
        trigger.addEventListener('click', () => fillOrderModal(trigger));
    });

    /* Finance transaction ledger filters. */
    const financeLedger = document.querySelector('[data-finance-ledger]');
    if (financeLedger) {
        const rows = [...financeLedger.querySelectorAll('[data-finance-row]')];
        const search = financeLedger.querySelector('[data-finance-search]');
        const status = financeLedger.querySelector('[data-finance-status]');
        const count = financeLedger.querySelector('[data-finance-count]');
        const empty = financeLedger.querySelector('[data-finance-empty]');

        const applyFinanceFilters = () => {
            const q = search?.value.trim().toLowerCase() || '';
            const selected = status?.value || '';
            let visible = 0;
            rows.forEach((row) => {
                const matches = (!q || row.dataset.search.includes(q)) && (!selected || row.dataset.status === selected);
                row.hidden = !matches;
                if (matches) visible += 1;
            });
            if (count) count.textContent = visible;
            if (empty) empty.hidden = visible > 0;
        };

        search?.addEventListener('input', applyFinanceFilters);
        status?.addEventListener('change', applyFinanceFilters);
        financeLedger.querySelector('[data-finance-reset]')?.addEventListener('click', () => {
            if (search) search.value = '';
            if (status) status.value = '';
            applyFinanceFilters();
        });
        applyFinanceFilters();
    }

    /* Re-run icons after supplemental markup has loaded. */
    if (window.lucide?.createIcons) window.lucide.createIcons();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeSellerPolish, { once: true });
} else {
    initializeSellerPolish();
}

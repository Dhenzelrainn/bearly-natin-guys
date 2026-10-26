export const VARIANT_PACKAGE_FIELDS = Object.freeze([
    'package_weight_kg',
    'package_length_cm',
    'package_width_cm',
    'package_height_cm',
]);

const isPositivePackageMeasurement = (value) => {
    const normalized = String(value ?? '').trim();
    if (!/^\d+(?:\.\d{1,3})?$/.test(normalized)) return false;

    const number = Number(normalized);
    return Number.isFinite(number) && number >= 0.001 && number <= 1000;
};

export const packageValues = (source = {}) => Object.fromEntries(
    VARIANT_PACKAGE_FIELDS.map((field) => [field, String(source?.[field] ?? '').trim()]),
);

export const incompletePackageFields = (values = {}) => VARIANT_PACKAGE_FIELDS
    .filter((field) => !isPositivePackageMeasurement(values[field]));

export const packageValuesAreComplete = (values = {}) => incompletePackageFields(values).length === 0;

export const packageValuesForVariant = (variant, sharedValues, sharedMode) => (
    sharedMode && !variant?.package_override
        ? packageValues(sharedValues)
        : packageValues(variant)
);

export const packageVariantsForSubmission = (variants, sharedValues, sharedMode) => variants.map((variant) => ({
    ...variant,
    ...packageValuesForVariant(variant, sharedValues, sharedMode),
}));

export const copyPackageFields = (target, source) => ({
    ...target,
    ...packageValues(source),
});

import test from 'node:test';
import assert from 'node:assert/strict';
import {
    copyPackageFields,
    incompletePackageFields,
    packageValuesAreComplete,
    packageVariantsForSubmission,
    packageValuesForVariant,
} from '../../resources/js/variant-package-utils.js';

const completePackage = {
    package_weight_kg: '0.177',
    package_length_cm: '14.960',
    package_width_cm: '7.150',
    package_height_cm: '0.795',
};

test('shared package values populate every non-overridden SKU submission', () => {
    const variants = [
        { label: 'Small / Black', sku: 'SM-BLACK', price: '699.90', stock: 5, ...completePackage },
        { label: 'Large / Black', sku: 'LG-BLACK', price: '749.90', stock: 7, ...completePackage },
    ];
    const sharedValues = {
        package_weight_kg: '0.250',
        package_length_cm: '25.000',
        package_width_cm: '12.500',
        package_height_cm: '0.795',
    };

    const submitted = packageVariantsForSubmission(variants, sharedValues, true);

    assert.deepEqual(submitted.map((variant) => ({
        ...Object.fromEntries(Object.keys(sharedValues).map((field) => [field, variant[field]])),
        price: variant.price,
        stock: variant.stock,
        sku: variant.sku,
    })), [
        { ...sharedValues, price: '699.90', stock: 5, sku: 'SM-BLACK' },
        { ...sharedValues, price: '749.90', stock: 7, sku: 'LG-BLACK' },
    ]);
    assert.deepEqual(variants[0], { label: 'Small / Black', sku: 'SM-BLACK', price: '699.90', stock: 5, ...completePackage });
});

test('shared packaging expands to every SKU in a 24-variant matrix', () => {
    const variants = Array.from({ length: 24 }, (_, index) => ({
        label: `SKU ${index + 1}`,
        sku: `MATRIX-${index + 1}`,
        price: String(100 + index),
        stock: index + 1,
        ...completePackage,
    }));
    const sharedValues = {
        package_weight_kg: '0.300',
        package_length_cm: '28.000',
        package_width_cm: '16.000',
        package_height_cm: '3.500',
    };

    const submitted = packageVariantsForSubmission(variants, sharedValues, true);

    assert.equal(submitted.length, 24);
    assert.equal(submitted.every((variant) => Object.entries(sharedValues)
        .every(([field, value]) => variant[field] === value)), true);
    assert.equal(submitted[23].sku, 'MATRIX-24');
    assert.equal(submitted[23].price, variants[23].price);
    assert.equal(submitted[23].stock, variants[23].stock);
});

test('per-SKU override wins over shared values without changing other SKUs', () => {
    const shared = { ...completePackage };
    const override = {
        label: 'Large / Black',
        package_override: true,
        package_weight_kg: '0.350',
        package_length_cm: '30.000',
        package_width_cm: '14.000',
        package_height_cm: '1.200',
    };
    const regular = { label: 'Small / Black', ...completePackage };

    assert.deepEqual(packageValuesForVariant(override, shared, true), {
        package_weight_kg: '0.350',
        package_length_cm: '30.000',
        package_width_cm: '14.000',
        package_height_cm: '1.200',
    });
    assert.deepEqual(packageValuesForVariant(regular, shared, true), shared);
});

test('turning shared mode off restores untouched per-SKU values', () => {
    const original = { label: 'Small / Black', ...completePackage };
    const shared = {
        package_weight_kg: '0.400',
        package_length_cm: '32.000',
        package_width_cm: '18.000',
        package_height_cm: '2.000',
    };

    assert.deepEqual(packageValuesForVariant(original, shared, true), shared);
    assert.deepEqual(packageValuesForVariant(original, shared, false), completePackage);
    assert.deepEqual(original, { label: 'Small / Black', ...completePackage });
});

test('package completeness requires four positive values within three-decimal precision', () => {
    assert.equal(packageValuesAreComplete(completePackage), true);
    assert.deepEqual(incompletePackageFields({
        ...completePackage,
        package_height_cm: '0.7951',
        package_width_cm: '',
    }), ['package_width_cm', 'package_height_cm']);
    assert.equal(packageValuesAreComplete({ ...completePackage, package_height_cm: '0' }), false);
    assert.equal(packageValuesAreComplete({ ...completePackage, package_height_cm: '1000.001' }), false);
});

test('bulk copy only changes package fields and leaves SKU price and stock intact', () => {
    const target = {
        label: 'Large / Black',
        sku: 'LG-BLACK',
        price: '749.90',
        stock: 7,
        package_weight_kg: '',
        package_length_cm: '',
        package_width_cm: '',
        package_height_cm: '',
    };

    assert.deepEqual(copyPackageFields(target, completePackage), {
        ...target,
        ...completePackage,
    });
    assert.equal(target.price, '749.90');
    assert.equal(target.stock, 7);
});

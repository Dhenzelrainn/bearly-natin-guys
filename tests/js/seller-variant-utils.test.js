import test from 'node:test';
import assert from 'node:assert/strict';
import {
    splitVariantValues,
    variantCombinationLabels,
    variantCombinationSummary,
} from '../../resources/js/seller-variant-utils.js';

test('comma-separated choices are trimmed and exact duplicates are ignored', () => {
    assert.deepEqual(splitVariantValues(' Black, White, Black, , '), ['Black', 'White']);
});

test('one option group creates one variant for each choice', () => {
    assert.deepEqual(variantCombinationLabels('Black, White', ''), ['Black', 'White']);
});

test('two option groups create every choice combination', () => {
    assert.deepEqual(variantCombinationLabels('Black, White', 'Small, Medium'), [
        'Black / Small',
        'Black / Medium',
        'White / Small',
        'White / Medium',
    ]);
});

test('combination summary explains the number of variants before generation', () => {
    assert.equal(
        variantCombinationSummary('Color', 'Black, White', 'Size', 'Small, Medium'),
        '2 choices for Color × 2 choices for Size = 4 variants.',
    );
    assert.equal(
        variantCombinationSummary('Color', 'Black, White', '', ''),
        '2 choices for Color = 2 variants.',
    );
    assert.equal(
        variantCombinationSummary('', '', '', ''),
        'Add choices above to see how many variants will be created.',
    );
});

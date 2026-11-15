export const splitVariantValues = (value) => {
    const values = String(value ?? '')
        .split(',')
        .map((item) => item.trim())
        .filter(Boolean);

    return values.filter((item, index) => values.indexOf(item) === index);
};

export const variantCombinationLabels = (optionOneValues, optionTwoValues) => {
    const first = splitVariantValues(optionOneValues);
    const second = splitVariantValues(optionTwoValues);

    if (!first.length && !second.length) return [];
    if (first.length && !second.length) return first;
    if (!first.length && second.length) return second;

    return first.flatMap((one) => second.map((two) => `${one} / ${two}`));
};

const choiceCount = (count) => `${count} ${count === 1 ? 'choice' : 'choices'}`;
const variantCount = (count) => `${count} ${count === 1 ? 'variant' : 'variants'}`;

export const variantCombinationSummary = (
    optionOneName,
    optionOneValues,
    optionTwoName,
    optionTwoValues,
) => {
    const first = splitVariantValues(optionOneValues);
    const second = splitVariantValues(optionTwoValues);
    const total = variantCombinationLabels(optionOneValues, optionTwoValues).length;

    if (!total) return 'Add choices above to see how many variants will be created.';

    const firstName = String(optionOneName ?? '').trim() || 'Option 1';
    const secondName = String(optionTwoName ?? '').trim() || 'Option 2';

    if (first.length && second.length) {
        return `${choiceCount(first.length)} for ${firstName} × ${choiceCount(second.length)} for ${secondName} = ${variantCount(total)}.`;
    }

    const values = first.length ? first : second;
    const name = first.length ? firstName : secondName;

    return `${choiceCount(values.length)} for ${name} = ${variantCount(total)}.`;
};

const toggleWaybillProductImages = (button, extraProducts) => {
    if (!extraProducts) return false;

    const expanded = button.getAttribute('aria-expanded') === 'true';
    const nextExpanded = !expanded;
    const moreCount = button.dataset.waybillMoreCount;

    extraProducts.hidden = !nextExpanded;
    button.setAttribute('aria-expanded', String(nextExpanded));
    button.classList.toggle('is-expanded', nextExpanded);
    button.textContent = nextExpanded
        ? button.dataset.waybillLessLabel
        : button.dataset.waybillMoreLabel;
    button.setAttribute('aria-label', nextExpanded
        ? 'Show fewer product images'
        : `Show ${moreCount} more product images`);

    return nextExpanded;
};

export const setupWaybillProductToggles = (root) => {
    root.querySelectorAll('[data-waybill-products-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const extraProducts = root.getElementById(button.getAttribute('aria-controls'));
            toggleWaybillProductImages(button, extraProducts);
        });
    });
};

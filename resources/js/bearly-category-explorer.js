(() => {
    'use strict';

    const openButton = document.querySelector('[data-bl-open-categories]');
    const explorer = document.getElementById('bl-category-explorer');

    if (!openButton || !explorer) {
        return;
    }

    const panel = explorer.querySelector('.bl-category-explorer-panel');
    const searchInput = explorer.querySelector('[data-bl-category-search]');
    const closeButtons = explorer.querySelectorAll('[data-bl-close-categories]');
    const tiles = Array.from(explorer.querySelectorAll('[data-bl-category-tile]'));
    const groups = Array.from(explorer.querySelectorAll('[data-bl-category-group]'));
    const emptyState = explorer.querySelector('[data-bl-category-empty]');

    let lastFocusedElement = null;

    const normalize = (value) =>
        String(value ?? '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim();

    const resetFilter = () => {
        if (searchInput) {
            searchInput.value = '';
        }

        tiles.forEach((tile) => tile.classList.remove('bl-category-filter-hidden'));

        groups.forEach((group) => {
            group.classList.remove('bl-category-filter-hidden');
            group.querySelectorAll('li').forEach((item) => {
                item.classList.remove('bl-category-filter-hidden');
            });
        });

        if (emptyState) {
            emptyState.hidden = true;
        }
    };

    const filterCategories = () => {
        const query = normalize(searchInput?.value);

        if (!query) {
            resetFilter();
            return;
        }

        let visibleTileCount = 0;
        let visibleGroupCount = 0;

        tiles.forEach((tile) => {
            const haystack = normalize(tile.dataset.search);
            const match = haystack.includes(query);

            tile.classList.toggle('bl-category-filter-hidden', !match);

            if (match) {
                visibleTileCount += 1;
            }
        });

        groups.forEach((group) => {
            const groupTitle = normalize(
                group.querySelector('.bl-category-explorer-group-title')?.textContent
            );

            const groupTitleMatches = groupTitle.includes(query);
            let visibleChildren = 0;

            group.querySelectorAll('li').forEach((item) => {
                const haystack = normalize(item.dataset.search || item.textContent);
                const match = groupTitleMatches || haystack.includes(query);

                item.classList.toggle('bl-category-filter-hidden', !match);

                if (match) {
                    visibleChildren += 1;
                }
            });

            const showGroup = groupTitleMatches || visibleChildren > 0;
            group.classList.toggle('bl-category-filter-hidden', !showGroup);

            if (showGroup) {
                visibleGroupCount += 1;
            }
        });

        if (emptyState) {
            emptyState.hidden = visibleTileCount > 0 || visibleGroupCount > 0;
        }
    };

    const openExplorer = () => {
        lastFocusedElement = document.activeElement;

        explorer.hidden = false;
        explorer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('bl-category-explorer-open');

        requestAnimationFrame(() => {
            panel?.focus();
            window.setTimeout(() => searchInput?.focus(), 80);
        });
    };

    const closeExplorer = () => {
        explorer.hidden = true;
        explorer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('bl-category-explorer-open');
        resetFilter();

        if (lastFocusedElement instanceof HTMLElement) {
            lastFocusedElement.focus();
        }
    };

    openButton.addEventListener('click', openExplorer);

    closeButtons.forEach((button) => {
        button.addEventListener('click', closeExplorer);
    });

    searchInput?.addEventListener('input', filterCategories);

    document.addEventListener('keydown', (event) => {
        if (explorer.hidden) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeExplorer();
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusable = Array.from(
            panel.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )
        ).filter((element) => !element.closest('[hidden]') && element.offsetParent !== null);

        if (!focusable.length) {
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
})();

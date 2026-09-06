const openButton = document.querySelector("[data-bl-open-categories]");
const explorer = document.getElementById("bl-category-explorer");

if (openButton && explorer) {
    const panel = explorer.querySelector(".bl-category-explorer-panel");
    const searchInput = explorer.querySelector("[data-bl-category-search]");
    const closeButtons = explorer.querySelectorAll("[data-bl-close-categories]");
    const tiles = [...explorer.querySelectorAll("[data-bl-category-tile]")];
    const groups = [...explorer.querySelectorAll("[data-bl-category-group]")];
    const emptyState = explorer.querySelector("[data-bl-category-empty]");

    let lastFocusedElement = null;

    const normalize = (value) =>
        String(value ?? "")
            .toLowerCase()
            .normalize("NFD")
            .replace(/[\u0300-\u036f]/g, "")
            .trim();

    const resetFilter = () => {
        if (searchInput) searchInput.value = "";

        tiles.forEach((tile) => tile.classList.remove("bl-category-filter-hidden"));
        groups.forEach((group) => {
            group.classList.remove("bl-category-filter-hidden");
            group.querySelectorAll("li").forEach((item) => {
                item.classList.remove("bl-category-filter-hidden");
            });
        });

        if (emptyState) emptyState.hidden = true;
    };

    const applyFilter = () => {
        const query = normalize(searchInput?.value);
        if (!query) {
            resetFilter();
            return;
        }

        let visibleCount = 0;
        tiles.forEach((tile) => {
            const match = normalize(tile.dataset.search).includes(query);
            tile.classList.toggle("bl-category-filter-hidden", !match);
            if (match) visibleCount += 1;
        });

        groups.forEach((group) => {
            const title = normalize(group.querySelector(".bl-category-explorer-group-title")?.textContent);
            const titleMatches = title.includes(query);
            let childMatches = 0;

            group.querySelectorAll("li").forEach((item) => {
                const match = titleMatches || normalize(item.dataset.search || item.textContent).includes(query);
                item.classList.toggle("bl-category-filter-hidden", !match);
                if (match) childMatches += 1;
            });

            const showGroup = titleMatches || childMatches > 0;
            group.classList.toggle("bl-category-filter-hidden", !showGroup);
            if (showGroup) visibleCount += 1;
        });

        if (emptyState) emptyState.hidden = visibleCount !== 0;
    };

    const openExplorer = () => {
        lastFocusedElement = document.activeElement;
        explorer.hidden = false;
        explorer.setAttribute("aria-hidden", "false");
        document.body.classList.add("bl-category-explorer-open");
        openButton.setAttribute("aria-expanded", "true");

        requestAnimationFrame(() => {
            if (panel) panel.focus();
            setTimeout(() => searchInput?.focus(), 50);
        });
    };

    const closeExplorer = () => {
        explorer.hidden = true;
        explorer.setAttribute("aria-hidden", "true");
        document.body.classList.remove("bl-category-explorer-open");
        openButton.setAttribute("aria-expanded", "false");
        resetFilter();

        if (lastFocusedElement instanceof HTMLElement) lastFocusedElement.focus();
    };

    openButton.addEventListener("click", (event) => {
        event.preventDefault();
        event.stopPropagation();
        openExplorer();
    });

    closeButtons.forEach((button) => {
        button.addEventListener("click", (event) => {
            event.preventDefault();
            closeExplorer();
        });
    });

    searchInput?.addEventListener("input", applyFilter);

    document.addEventListener("keydown", (event) => {
        if (!explorer.hidden && event.key === "Escape") {
            event.preventDefault();
            closeExplorer();
        }
    });
}
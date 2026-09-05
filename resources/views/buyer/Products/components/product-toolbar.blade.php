<section class="bp-toolbar" aria-label="Product controls">
    <div class="bp-toolbar-left">
        <span>Showing:</span>
        <div id="bp-toolbar-chips" class="bp-toolbar-chips">
            <span class="bp-toolbar-placeholder">All Men's Apparel</span>
        </div>
    </div>

    <div class="bp-toolbar-right">
        <label class="bp-sort">
            <span>Sort by:</span>
            <select id="bp-sort">
                <option value="relevance">Relevance</option>
                <option value="price-low">Price: Low to High</option>
                <option value="price-high">Price: High to Low</option>
                <option value="name">Name: A–Z</option>
            </select>
        </label>

        <button class="bp-view-toggle is-active" type="button" data-bp-view="grid" aria-label="Grid view">
            <span class="material-symbols-outlined">grid_view</span>
        </button>

        <button class="bp-view-toggle" type="button" data-bp-view="list" aria-label="List view">
            <span class="material-symbols-outlined">view_list</span>
        </button>
    </div>
</section>

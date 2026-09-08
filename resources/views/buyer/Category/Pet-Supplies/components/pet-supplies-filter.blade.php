<section id="bc-filter-panel" class="filter-panel" aria-label="Product filters">
    <div class="filter-heading"><h2><i class="mi">tune</i> Filters</h2><button id="bc-collapse" aria-expanded="true">Collapse all</button></div>
    <label class="category-search"><i class="mi">search</i><input type="search" id="bc-within" placeholder="Search within Pet Supplies" maxlength="120"></label>
    <div class="selected"><strong id="bc-selected">Selected (0)</strong><button data-reset>Clear all</button></div>
    <div id="bc-side-chips" class="chips"></div>

    <details open>
        <summary>Price range</summary>
        <div class="price-fields">
            <label><span>₱ Min</span><input id="bc-min" type="number" min="0" max="5000" placeholder="0"></label>
            <label><span>₱ Max</span><input id="bc-max" type="number" min="0" max="5000" placeholder="5000"></label>
        </div>
        <div class="ranges">
            <input id="bc-min-range" type="range" min="0" max="5000" value="0">
            <input id="bc-max-range" type="range" min="0" max="5000" value="5000">
        </div>
        <p id="bc-price-error" class="error" role="alert" hidden>Minimum price must not exceed maximum price.</p>
        <div class="presets">
            <button data-price="0,299">Under ₱300</button>
            <button data-price="300,699">₱300–₱699</button>
            <button data-price="700,5000">₱700 & up</button>
        </div>
    </details>

    <details open><summary>Color</summary><div class="colors" id="bc-color-options"></div></details>
    <details open><summary>Condition</summary><div class="checks" id="bc-condition-options"></div></details>
    <details open><summary>Seller location</summary><input type="search" id="bc-location-search" placeholder="Find a location"><div class="checks" id="bc-location-options"></div></details>

    <details open>
        <summary>Delivery & offers</summary>
        <div class="checks">
            <label><input type="checkbox" data-field="shipping" value="1"> Free shipping</label>
            <label><input type="checkbox" data-field="voucher" value="1"> Has voucher</label>
        </div>
    </details>

    <details open>
        <summary>Customer rating</summary>
        <div class="checks">
            <label><input type="radio" name="rating" data-field="rating" value="4"> 4 stars & up</label>
            <label><input type="radio" name="rating" data-field="rating" value="0" checked> Any rating</label>
        </div>
    </details>
</section>
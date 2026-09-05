<section class="bp-filter-section" aria-labelledby="bp-filter-title">
    <div class="bp-filter-heading">
        <h2 id="bp-filter-title">
            <span class="material-symbols-outlined">tune</span>
            Filters
        </h2>
        <button type="button" id="bp-hide-filters">Hide</button>
    </div>

    <label class="bp-filter-search">
        <span class="material-symbols-outlined">search</span>
        <input id="bp-filter-search" type="search" placeholder="Search this category" autocomplete="off">
    </label>

    <div class="bp-selected-block">
        <div>
            <strong>Selected (<span id="bp-selected-count">0</span>)</strong>
            <button type="button" data-bp-clear-all>Clear all</button>
        </div>
        <div class="bp-selected-chips" id="bp-selected-chips"></div>
    </div>

    <div class="bp-filter-group">
        <div class="bp-filter-group-title">
            <strong>Price range</strong>
            <span class="material-symbols-outlined">expand_less</span>
        </div>

        <div class="bp-price-inputs">
            <label>
                <span>₱</span>
                <input id="bp-min-price" type="number" min="0" placeholder="Min">
            </label>

            <label>
                <span>₱</span>
                <input id="bp-max-price" type="number" min="0" placeholder="Max">
            </label>
        </div>

        <div class="bp-range-visual" aria-hidden="true">
            <span></span>
            <i></i>
            <span></span>
        </div>

        <button class="bp-apply-price" id="bp-apply-price" type="button">Apply</button>
    </div>

    <div class="bp-filter-group">
        <div class="bp-filter-group-title">
            <strong>Size</strong>
            <span class="material-symbols-outlined">expand_less</span>
        </div>

        <div class="bp-size-options">
            @foreach(['S', 'M', 'L', 'XL', 'XXL'] as $size)
                <label>
                    <input class="sr-only bp-size-input" type="checkbox" value="{{ $size }}">
                    <span>{{ $size }}</span>
                </label>
            @endforeach
        </div>
    </div>

    <div class="bp-filter-group">
        <div class="bp-filter-group-title">
            <strong>Color</strong>
            <span class="material-symbols-outlined">expand_less</span>
        </div>

        <div class="bp-color-options" aria-label="Color">
            @php
                $colors = [
                    ['slug' => 'blue', 'label' => 'Blue', 'class' => 'is-blue'],
                    ['slug' => 'black', 'label' => 'Black', 'class' => 'is-black'],
                    ['slug' => 'white', 'label' => 'White', 'class' => 'is-white'],
                    ['slug' => 'beige', 'label' => 'Beige', 'class' => 'is-beige'],
                    ['slug' => 'navy', 'label' => 'Navy', 'class' => 'is-navy'],
                ];
            @endphp

            @foreach($colors as $color)
                <label title="{{ $color['label'] }}">
                    <input class="sr-only bp-color-input" type="checkbox" value="{{ $color['slug'] }}" data-label="{{ $color['label'] }}">
                    <span class="bp-color-dot {{ $color['class'] }}">
                        <span class="material-symbols-outlined">check</span>
                    </span>
                </label>
            @endforeach
        </div>
    </div>

    <div class="bp-filter-group">
        <div class="bp-filter-group-title">
            <strong>Condition</strong>
            <span class="material-symbols-outlined">expand_less</span>
        </div>

        <div class="bp-check-options">
            <label><input class="bp-condition-input" type="checkbox" value="new-with-tags" data-label="New with tags"> New with tags</label>
            <label><input class="bp-condition-input" type="checkbox" value="new-without-tags" data-label="New without tags"> New without tags</label>
            <label><input class="bp-condition-input" type="checkbox" value="pre-owned" data-label="Pre-owned"> Pre-owned</label>
        </div>
    </div>

    <div class="bp-filter-group">
        <div class="bp-filter-group-title bp-location-title">
            <strong>
                <span class="material-symbols-outlined">location_on</span>
                Delivery location
            </strong>
        </div>

        <button class="bp-location-button" type="button">
            <span class="material-symbols-outlined">location_on</span>
            Set location
        </button>
    </div>

    <div class="bp-filter-group">
        <div class="bp-filter-group-title">
            <strong>Shipping</strong>
            <span class="material-symbols-outlined">expand_less</span>
        </div>

        <label class="bp-free-shipping">
            <input id="bp-free-shipping" type="checkbox">
            <span>
                Free shipping
                <small>When offered by seller</small>
            </span>
        </label>
    </div>
</section>

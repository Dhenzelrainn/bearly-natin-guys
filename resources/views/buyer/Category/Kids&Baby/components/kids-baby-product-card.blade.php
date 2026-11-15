<template id="bc-card-template">
    <article class="card">
        <div class="card-image">
            <button class="image-open" data-quick>
                <span class="photo" role="img"></span>
            </button>
            <button class="save" data-save aria-pressed="false">
                <i class="mi" aria-hidden="true">favorite</i>
            </button>
        </div>
        <div class="card-copy">
            <h2></h2>
            <div class="card-meta">
                <span class="card-rating"><b aria-hidden="true">★</b> <span class="card-rating-value"></span></span>
                <span class="card-sold"></span>
            </div>
            <div class="price-row">
                <strong></strong>
                <span class="buyer-price-meta" data-price-meta hidden>
                    <s class="buyer-price-regular" data-price-regular><span class="sr-only">Regular price </span><span data-price-regular-amount></span></s>
                    <span class="buyer-price-badge" data-price-badge></span>
                </span>
            </div>
        </div>
    </article>
</template>

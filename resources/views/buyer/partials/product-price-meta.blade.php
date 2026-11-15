@php
    $priceMetaCurrent = (float) ($current ?? 0);
    $priceMetaRegular = (float) ($regular ?? 0);
    $priceMetaDiscount = (int) ($discount ?? 0);
    $priceMetaSource = (string) ($price_source ?? '');
    $priceMetaCampaignName = trim((string) ($campaign_name ?? ''));
    $priceMetaProductDiscountName = trim((string) ($product_discount_name ?? ''));
    $priceMetaPromotionName = $priceMetaSource === 'campaign'
        ? ($priceMetaCampaignName ?: $priceMetaProductDiscountName)
        : ($priceMetaProductDiscountName ?: $priceMetaCampaignName);
    $priceMetaHasDiscount = $priceMetaDiscount > 0 && $priceMetaRegular > $priceMetaCurrent;
    $priceMetaDisplayRegular = $priceMetaRegular > 0 ? $priceMetaRegular : $priceMetaCurrent;
@endphp
<span class="buyer-price-meta" data-price-meta{{ $priceMetaHasDiscount ? '' : ' hidden' }}>
    <s class="buyer-price-regular" data-price-regular><span class="sr-only">Regular price </span><span data-price-regular-amount>₱{{ number_format($priceMetaDisplayRegular, 2) }}</span></s>
    <span class="buyer-price-badge" data-price-badge>{{ $priceMetaHasDiscount ? $priceMetaDiscount.'% OFF' : '' }}</span>
    <span class="buyer-price-campaign" data-price-campaign{{ $priceMetaPromotionName && $priceMetaHasDiscount ? '' : ' hidden' }}>{{ $priceMetaPromotionName }}</span>
</span>

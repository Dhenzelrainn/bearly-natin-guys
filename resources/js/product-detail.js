import { galleryIndexForDirection, gallerySwipeDirection } from './product-gallery-utils.js';

(() => {
  const root = document.querySelector('[data-product-detail]');
  const dataNode = document.querySelector('[data-product-detail-data]');
  if (!root || !dataNode) return;

  let data;
  try {
    data = JSON.parse(dataNode.textContent || '{}');
  } catch {
    return;
  }

  const variants = Array.isArray(data.variants) ? data.variants : [];
  const optionGroups = Array.isArray(data.optionGroups) ? data.optionGroups : [];
  const specs = data.variantSpecs && typeof data.variantSpecs === 'object' ? data.variantSpecs : {};
  const select = root.querySelector('[data-detail-variant]');
  const quantity = root.querySelector('[data-detail-quantity]');
  const price = root.querySelector('[data-detail-price]');
  const priceMeta = root.querySelector('[data-price-meta]');
  const priceRegularAmount = root.querySelector('[data-price-regular-amount]');
  const priceBadge = root.querySelector('[data-price-badge]');
  const priceCaption = root.querySelector('[data-detail-price-caption]');
  const promotionNote = root.querySelector('[data-detail-promotion]');
  const promotionName = root.querySelector('[data-detail-promotion-name]');
  const promotionPeriod = root.querySelector('[data-detail-promotion-period]');
  const stock = root.querySelector('[data-detail-stock]');
  const stockSpec = document.querySelector('[data-detail-stock-spec]');
  const sku = document.querySelector('[data-detail-sku]');
  const weight = document.querySelector('[data-detail-weight]');
  const dimensions = document.querySelector('[data-detail-dimensions]');
  const addButton = root.querySelector('[data-detail-add]');
  const buyButton = root.querySelector('[data-detail-buy-now]');
  const toast = root.querySelector('[data-detail-toast]');
  const gallery = root.querySelector('.detail-primary-image');
  const thumbnails = [...root.querySelectorAll('[data-detail-thumbnail]')];
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  let selectedVariantId = Number(select?.value || variants[0]?.id || 0);
  let busy = false;
  let activeFilter = 'all';

  const currentVariant = () => variants.find(variant => Number(variant.id) === selectedVariantId)
    || variants.find(variant => Number(variant.id) === Number(select?.value))
    || variants[0]
    || null;
  const normalize = value => String(value ?? '').trim().toLocaleLowerCase();
  const normalizeGroupKey = value => {
    const key = normalize(value);
    return key === 'colour' ? 'color' : key;
  };
  const variantOptions = variant => {
    const options = {};
    const rawOptions = variant?.options && typeof variant.options === 'object' ? variant.options : {};
    for (const [name, value] of Object.entries(rawOptions)) {
      const key = normalizeGroupKey(name);
      if (key && key !== 'label' && value !== null && value !== undefined && String(value).trim() !== '') {
        options[key] = String(value).trim();
      }
    }
    if (!options.color && variant?.color) options.color = String(variant.color).trim();
    if (!options.size && variant?.size) options.size = String(variant.size).trim();
    return options;
  };
  const optionValue = (variant, group) => variantOptions(variant)[normalizeGroupKey(group)] || '';
  const money = value => `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  const formatPht = value => {
    if (!value || Number.isNaN(Date.parse(value))) return '';
    return new Intl.DateTimeFormat('en-PH', {
      timeZone: data.timeZone || 'Asia/Manila',
      month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
    }).format(new Date(value));
  };
  const setToast = (message, isError = false) => {
    if (!toast) return;
    toast.textContent = message;
    toast.classList.toggle('is-error', isError);
  };
  const syncCartBadge = result => {
    window.bearlyBuyerProfile = {
      ...(window.bearlyBuyerProfile || {}),
      cartCount: Number(result?.data?.cart_count ?? window.bearlyBuyerProfile?.cartCount ?? 0),
    };
    window.BearlyNavbarState?.sync?.();
  };
  const selectedOptions = () => {
    return variantOptions(currentVariant());
  };
  const updateOptionButtons = () => {
    const selected = selectedOptions();
    for (const button of root.querySelectorAll('[data-option-group][data-option-value]')) {
      const group = normalizeGroupKey(button.dataset.optionGroup);
      const value = button.dataset.optionValue;
      const candidates = variants.filter(variant => normalize(optionValue(variant, group)) === normalize(value));
      button.disabled = candidates.length === 0;
      button.dataset.optionAvailable = String(candidates.some(variant => Number(variant.stock) > 0));
      button.setAttribute('aria-pressed', String(normalize(selected[group]) === normalize(value)));
    }
    for (const label of root.querySelectorAll('[data-selected-option]')) {
      const group = normalizeGroupKey(label.dataset.selectedOption);
      label.textContent = selected[group] || 'Select';
    }
  };
  const selectVariant = variant => {
    if (!variant) return;
    selectedVariantId = Number(variant.id);
    if (select) select.value = String(selectedVariantId);
    updateOptionButtons();
    renderVariant();
  };
  const selectOption = (group, value) => {
    group = normalizeGroupKey(group);
    const selected = selectedOptions();
    const matches = variants.filter(variant => normalize(optionValue(variant, group)) === normalize(value));
    const matchingCurrentCombination = matches.find(variant => optionGroups.every(option =>
      normalizeGroupKey(option.key) === group
      || normalize(optionValue(variant, option.key)) === normalize(selected[normalizeGroupKey(option.key)]),
    ));
    const available = matches.find(variant => Number(variant.stock) > 0);
    selectVariant(matchingCurrentCombination || available || matches[0]);
  };
  const selectThumbnail = source => {
    thumbnails.forEach(thumbnail => thumbnail.setAttribute('aria-pressed', String(thumbnail.dataset.imageSrc === source)));
  };
  const showImage = (source, alt = data.productName) => {
    if (!gallery || !source) return;
    let image = gallery.querySelector('[data-detail-primary-image]');
    if (!image) {
      image = document.createElement('img');
      image.dataset.detailPrimaryImage = '';
      image.draggable = false;
      gallery.insertBefore(image, gallery.firstChild);
    }
    image.src = source;
    image.alt = alt || data.productName || 'Product image';
    gallery.classList.remove('is-empty');
    selectThumbnail(source);
  };
  const renderVariant = (syncImage = true) => {
    const variant = currentVariant();
    if (!variant) return;

    const available = Math.max(0, Number(variant.stock) || 0);
    const quantityValue = Math.max(1, Number.parseInt(quantity?.value || '1', 10) || 1);
    const spec = specs[String(variant.id)] || {};
    if (price) price.textContent = money(variant.price);

    const regular = Number(variant.regular_price) || Number(variant.price) || 0;
    const discount = Number(variant.discount_percent) || 0;
    const hasDiscount = discount > 0 && regular > Number(variant.price);
    if (priceMeta) priceMeta.hidden = !hasDiscount;
    if (priceRegularAmount) priceRegularAmount.textContent = money(regular);
    if (priceBadge) priceBadge.textContent = hasDiscount ? `${discount}% OFF` : '';
    if (priceCaption) priceCaption.hidden = hasDiscount;
    const isCampaign = String(variant.price_source || '') === 'campaign';
    const saleName = String((isCampaign ? variant.campaign_name : variant.product_discount_name) || '').trim();
    const saleStart = formatPht(isCampaign ? variant.campaign_starts_at : variant.product_discount_starts_at);
    const saleEnd = formatPht(isCampaign ? variant.campaign_ends_at : variant.product_discount_ends_at);
    const hasSalePeriod = saleStart !== '' && saleEnd !== '';
    const showSaleName = hasDiscount && saleName !== '';
    if (promotionName) promotionName.textContent = saleName;
    if (promotionPeriod) promotionPeriod.textContent = showSaleName && hasSalePeriod ? `${saleStart} – ${saleEnd} PHT` : '';
    if (promotionNote) promotionNote.hidden = !showSaleName;
    if (stock) {
      stock.textContent = available > 0 ? `${available} available` : 'Out of stock';
      stock.classList.toggle('is-unavailable', available < 1);
    }
    if (stockSpec) stockSpec.textContent = String(available);
    if (sku) sku.textContent = spec.sku || '—';
    if (weight) weight.textContent = spec.weight_kg !== null && spec.weight_kg !== undefined && spec.weight_kg !== ''
      ? `${Number(spec.weight_kg).toFixed(3)} kg`
      : '—';
    if (dimensions) {
      const measures = [spec.length_cm, spec.width_cm, spec.height_cm];
      dimensions.textContent = measures.every(value => value !== null && value !== undefined && value !== '')
        ? `${measures.map(value => Number(value).toFixed(2)).join(' × ')} cm`
        : '—';
    }
    if (quantity) {
      quantity.max = String(Math.max(available, 1));
      quantity.disabled = available < 1;
      quantity.value = String(Math.min(quantityValue, Math.max(available, 1)));
    }
    if (addButton) addButton.disabled = available < 1 || busy;
    if (buyButton) buyButton.disabled = available < 1 || busy;
    const decrement = root.querySelector('[data-detail-quantity-down]');
    const increment = root.querySelector('[data-detail-quantity-up]');
    if (decrement) decrement.disabled = available < 1 || Number(quantity?.value || 1) <= 1;
    if (increment) increment.disabled = available < 1 || Number(quantity?.value || 1) >= available;

    if (syncImage && gallery) {
      const imageUrl = variant.image || data.defaultImage || '';
      if (imageUrl) {
        showImage(imageUrl, `${data.productName || 'Product'} - ${variant.name || ''}`.trim());
      } else {
        gallery.querySelector('[data-detail-primary-image]')?.remove();
        gallery.classList.add('is-empty');
        selectThumbnail('');
      }
    }
    if (select) select.value = String(variant.id);
    updateOptionButtons();
  };

  const sendCartRequest = async (url, body) => {
    const response = await fetch(url, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify(body),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
      const validationMessage = Object.values(result.errors || {}).flat()[0];
      throw new Error(validationMessage || result.message || 'Unable to update your cart.');
    }
    return result;
  };
  const selectedQuantity = () => Math.max(1, Number.parseInt(quantity?.value || '1', 10) || 1);
  const beginAction = () => {
    if (busy) return false;
    busy = true;
    renderVariant(false);
    return true;
  };
  const endAction = () => {
    busy = false;
    renderVariant(false);
  };

  for (const button of root.querySelectorAll('[data-option-group][data-option-value]')) {
    button.addEventListener('click', () => selectOption(button.dataset.optionGroup, button.dataset.optionValue));
  }
  select?.addEventListener('change', () => {
    selectedVariantId = Number(select.value);
    updateOptionButtons();
    renderVariant();
  });
  quantity?.addEventListener('input', () => renderVariant(false));
  quantity?.addEventListener('change', () => renderVariant(false));
  root.querySelector('[data-detail-quantity-down]')?.addEventListener('click', () => {
    if (quantity) quantity.value = String(Math.max(1, Number(quantity.value || 1) - 1));
    renderVariant(false);
  });
  root.querySelector('[data-detail-quantity-up]')?.addEventListener('click', () => {
    const variant = currentVariant();
    if (quantity && variant) quantity.value = String(Math.min(Number(variant.stock) || 1, Number(quantity.value || 1) + 1));
    renderVariant(false);
  });

  const chooseThumbnail = thumbnail => {
    const variantId = Number(thumbnail.dataset.variantId || 0);
    if (variantId) {
      const variant = variants.find(item => Number(item.id) === variantId);
      if (variant) {
        selectVariant(variant);
        return;
      }
    }
    showImage(thumbnail.dataset.imageSrc, thumbnail.dataset.imageAlt);
  };
  thumbnails.forEach(thumbnail => thumbnail.addEventListener('click', () => chooseThumbnail(thumbnail)));
  const showAdjacentGalleryImage = direction => {
    if (!thumbnails.length) return;

    const index = thumbnails.findIndex(thumbnail => thumbnail.getAttribute('aria-pressed') === 'true');
    const nextIndex = galleryIndexForDirection(index, direction, thumbnails.length);

    if (nextIndex >= 0) chooseThumbnail(thumbnails[nextIndex]);
  };
  root.querySelector('[data-gallery-prev]')?.addEventListener('click', () => showAdjacentGalleryImage(-1));
  root.querySelector('[data-gallery-next]')?.addEventListener('click', () => showAdjacentGalleryImage(1));

  let gallerySwipeStart = null;
  const clearGallerySwipe = event => {
    if (!gallerySwipeStart || (event?.pointerId !== undefined && event.pointerId !== gallerySwipeStart.pointerId)) return;
    gallerySwipeStart = null;
    gallery?.classList.remove('is-swiping');
  };

  gallery?.addEventListener('pointerdown', event => {
    if (!event.isPrimary || event.button !== 0 || event.target.closest?.('button') || thumbnails.length < 2) return;

    gallerySwipeStart = {
      pointerId: event.pointerId,
      x: event.clientX,
      y: event.clientY,
    };
    gallery.classList.add('is-swiping');
    gallery.setPointerCapture?.(event.pointerId);
  });

  gallery?.addEventListener('pointerup', event => {
    if (!gallerySwipeStart || event.pointerId !== gallerySwipeStart.pointerId) return;

    const direction = gallerySwipeDirection(
      gallerySwipeStart.x,
      gallerySwipeStart.y,
      event.clientX,
      event.clientY,
    );
    clearGallerySwipe(event);
    if (direction !== 0) showAdjacentGalleryImage(direction);
  });
  gallery?.addEventListener('pointercancel', clearGallerySwipe);
  gallery?.addEventListener('lostpointercapture', clearGallerySwipe);

  addButton?.addEventListener('click', async () => {
    const variant = currentVariant();
    const count = selectedQuantity();
    if (!variant || Number(variant.stock) < count || !beginAction()) return;
    setToast('');
    addButton.textContent = 'Adding…';
    try {
      const result = await sendCartRequest(data.addUrl, { product_variant_id: variant.id, quantity: count });
      syncCartBadge(result);
      setToast(`Added ${count} ${count === 1 ? 'item' : 'items'} to your cart.`);
    } catch (error) {
      setToast(error.message || 'Unable to add this product.', true);
    } finally {
      addButton.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">add_shopping_cart</span>Add to cart';
      endAction();
    }
  });

  buyButton?.addEventListener('click', async () => {
    const variant = currentVariant();
    const count = selectedQuantity();
    if (!variant || Number(variant.stock) < count || !beginAction()) return;
    setToast('');
    buyButton.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">progress_activity</span>Preparing…';
    try {
      const result = await sendCartRequest(data.buyNowUrl, { product_variant_id: variant.id, quantity: count });
      const cartItemId = result.data?.item?.id;
      if (!cartItemId) throw new Error('Could not prepare this item for checkout. Try again from your cart.');
      syncCartBadge(result);
      const checkout = new URL(data.checkoutUrl, window.location.origin);
      if (checkout.origin !== window.location.origin) throw new Error('Checkout must remain on Bearly.');
      checkout.searchParams.set('item', String(cartItemId));
      window.location.assign(checkout.toString());
    } catch (error) {
      setToast(error.message || 'Unable to prepare checkout.', true);
      buyButton.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">shopping_bag</span>Buy now';
      endAction();
    }
  });

  const reviewList = document.querySelector('[data-review-list]');
  const reviewCards = reviewList ? [...reviewList.querySelectorAll('[data-review-card]')] : [];
  const reviewEmpty = document.querySelector('[data-review-empty]');
  const reviewFilterButtons = [...document.querySelectorAll('[data-review-filter]')];
  const reviewSort = document.querySelector('[data-review-sort]');
  const matchesFilter = card => {
    if (activeFilter === 'all') return true;
    if (activeFilter.startsWith('rating-')) return Number(card.dataset.rating) === Number(activeFilter.slice(7));
    if (activeFilter === 'comments') return card.dataset.hasComment === 'true';
    if (activeFilter === 'media') return card.dataset.hasPhoto === 'true';
    return true;
  };
  const sortReviews = () => {
    if (!reviewList) return;
    const sortBy = reviewSort?.value || 'newest';
    const sorted = [...reviewCards].sort((left, right) => {
      if (sortBy === 'highest' && Number(left.dataset.rating) !== Number(right.dataset.rating)) {
        return Number(right.dataset.rating) - Number(left.dataset.rating);
      }
      if (sortBy === 'lowest' && Number(left.dataset.rating) !== Number(right.dataset.rating)) {
        return Number(left.dataset.rating) - Number(right.dataset.rating);
      }
      return Date.parse(String(right.dataset.date || '').replace(' ', 'T'))
        - Date.parse(String(left.dataset.date || '').replace(' ', 'T'));
    });
    for (const card of sorted) reviewList.append(card);
    let visibleCount = 0;
    for (const card of reviewCards) {
      const visible = matchesFilter(card);
      card.hidden = !visible;
      if (visible) visibleCount += 1;
    }
    if (reviewEmpty) reviewEmpty.hidden = visibleCount > 0;
  };
  reviewFilterButtons.forEach(button => button.addEventListener('click', () => {
    activeFilter = button.dataset.reviewFilter || 'all';
    reviewFilterButtons.forEach(filter => filter.setAttribute('aria-pressed', String(filter === button)));
    sortReviews();
  }));
  reviewSort?.addEventListener('change', sortReviews);

  renderVariant(false);
  sortReviews();
})();

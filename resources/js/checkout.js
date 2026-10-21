(() => {
  const SELECTION_KEY = window.bearlyStorageKey?.('checkout-selection') || 'bearly-checkout-selection-v1';
  const PREVIEW_KEY = window.bearlyStorageKey?.('preview-cart') || 'bearly-preview-cart-v1';
  const $ = id => document.getElementById(id);
  const money = minor => '₱' + (Number(minor || 0) / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[char]));
  const imageUrl = value => { const image = String(value || '').trim(); return !image ? '/images/bearly-logo.png' : /^(https?:|\/|data:)/.test(image) ? image : `/${image.replace(/^\.\//, '')}`; };
  let allItems = [], items = [], addresses = [], address = null, quote = null, quoting = false, quoteRequest = 0;
  let promotionSelection = { platform_voucher_id: null, store_voucher_id: null, voucher_code: null };

  function storedKeys() {
    try {
      const value = JSON.parse(localStorage.getItem(SELECTION_KEY) || '[]');
      return Array.isArray(value) ? value.map(String) : [];
    } catch { return []; }
  }

  function hasPreviewItems() {
    try {
      const value = JSON.parse(localStorage.getItem(PREVIEW_KEY) || '[]');
      return Array.isArray(value) && value.length > 0;
    } catch { return false; }
  }

  function showError(message) {
    const node = $('checkout-error');
    node.textContent = message || 'Unable to calculate shipping. Review your address and selected products.';
    node.hidden = false;
  }

  function renderAddress() {
    if (!address) return;
    $('checkout-address-id').value = address.id;
    $('address-name').textContent = address.name || 'Recipient';
    $('address-phone').textContent = address.phone || 'No phone saved';
    $('address-text').textContent = [address.street, address.barangay, address.city, address.province, address.postal].filter(Boolean).join(', ');
    $('address-default-tag').hidden = !address.isDefault;
  }

  function syncPromotionSelection() {
    $('checkout-platform-voucher-id').value = promotionSelection.platform_voucher_id || '';
    $('checkout-store-voucher-id').value = promotionSelection.store_voucher_id || '';
    $('checkout-voucher-code').value = promotionSelection.voucher_code || '';
    const active = Boolean(promotionSelection.platform_voucher_id || promotionSelection.store_voucher_id || promotionSelection.voucher_code);
    $('checkout-voucher-clear').hidden = !active;
  }

  function setVoucherStatus(message, tone = '') {
    const node = $('checkout-voucher-status');
    if (!node) return;
    node.textContent = message || '';
    node.dataset.tone = tone;
    node.hidden = !message;
  }

  function discountText(value) {
    return Number(value || 0) > 0 ? `-${money(value)}` : '—';
  }

  function setDiscountRow(rowId, valueId, value) {
    const row = $(rowId);
    const target = $(valueId);
    if (!row || !target) return;
    row.hidden = Number(value || 0) < 1;
    target.textContent = discountText(value);
  }

  function renderAppliedVouchers() {
    const container = $('checkout-applied-vouchers');
    if (!container) return;
    const rows = Array.isArray(quote?.applied_promotions) ? quote.applied_promotions : [];
    if (!rows.length) {
      container.innerHTML = '<span class="checkout-voucher-empty">No voucher applied yet. Eligible Bearly offers appear here automatically.</span>';
      return;
    }
    container.innerHTML = rows.map(row => {
      const amount = Number(row.discount_minor || 0) + Number(row.shipping_discount_minor || 0);
      return `<span class="checkout-applied-voucher"><span class="material-symbols-outlined" aria-hidden="true">confirmation_number</span><span>${escapeHtml(row.name)}</span><strong>-${money(amount)}</strong></span>`;
    }).join('');
  }

  function voucherOptionLabel(voucher) {
    const title = voucher.title ? ` · ${voucher.title}` : '';
    const saving = Number(voucher.shipping_discount_minor || 0) > 0
      ? ` (${money(voucher.shipping_discount_minor)} off shipping)`
      : Number(voucher.discount_minor || 0) > 0
        ? ` (${money(voucher.discount_minor)} off)`
        : '';
    const reason = voucher.eligible ? saving : ` (${voucher.reason || 'Not eligible'})`;
    return `${voucher.name || 'Voucher'}${title}${reason}`;
  }

  function renderVoucherOptions() {
    const platformSelect = $('checkout-platform-voucher');
    const storeSelect = $('checkout-store-voucher');
    if (!platformSelect || !storeSelect) return;
    const selection = quote?.promotion_selection || promotionSelection;
    const platformRows = Array.isArray(quote?.promotions?.platform) ? quote.promotions.platform : [];
    const storeRows = Array.isArray(quote?.promotions?.store) ? quote.promotions.store : [];

    const fill = (select, rows, emptyLabel, selectedId) => {
      const selected = Number(selectedId || 0);
      select.innerHTML = `<option value="">${escapeHtml(emptyLabel)}</option>` + rows.map(voucher => {
        const id = Number(voucher.id);
        const isSelected = id === selected;
        const unavailable = !voucher.eligible && !isSelected;
        return `<option value="${id}"${isSelected ? ' selected' : ''}${unavailable ? ' disabled' : ''}>${escapeHtml(voucherOptionLabel(voucher))}</option>`;
      }).join('');
    };

    fill(platformSelect, platformRows, 'No platform voucher', selection.platform_voucher_id);
    fill(storeSelect, storeRows, 'No Store voucher', selection.store_voucher_id);
  }

  function clearPromotionSelection() {
    promotionSelection = { platform_voucher_id: null, store_voucher_id: null, voucher_code: null };
    $('checkout-platform-voucher').value = '';
    $('checkout-store-voucher').value = '';
    $('checkout-voucher-code-input').value = '';
    setVoucherStatus('');
    syncPromotionSelection();
    requestQuote();
  }

  $('checkout-platform-voucher').addEventListener('change', event => {
    promotionSelection = {
      platform_voucher_id: Number(event.target.value) || null,
      store_voucher_id: promotionSelection.store_voucher_id,
      voucher_code: null,
    };
    $('checkout-voucher-code-input').value = '';
    setVoucherStatus('');
    syncPromotionSelection();
    requestQuote();
  });

  $('checkout-store-voucher').addEventListener('change', event => {
    promotionSelection = {
      platform_voucher_id: promotionSelection.platform_voucher_id,
      store_voucher_id: Number(event.target.value) || null,
      voucher_code: null,
    };
    $('checkout-voucher-code-input').value = '';
    setVoucherStatus('');
    syncPromotionSelection();
    requestQuote();
  });

  $('checkout-voucher-code-apply').addEventListener('click', () => {
    const input = $('checkout-voucher-code-input');
    const code = String(input.value || '').trim().toUpperCase();
    if (!code) {
      setVoucherStatus('Enter a voucher code first.', 'error');
      input.focus();
      return;
    }
    input.value = code;
    promotionSelection = { platform_voucher_id: null, store_voucher_id: null, voucher_code: code };
    $('checkout-platform-voucher').value = '';
    $('checkout-store-voucher').value = '';
    setVoucherStatus('');
    syncPromotionSelection();
    requestQuote();
  });

  $('checkout-voucher-code-input').addEventListener('keydown', event => {
    if (event.key === 'Enter') {
      event.preventDefault();
      $('checkout-voucher-code-apply').click();
    }
  });

  $('checkout-voucher-clear').addEventListener('click', clearPromotionSelection);
  syncPromotionSelection();
  renderAppliedVouchers();

  function renderItems() {
    $('checkout-products').innerHTML = items.map(item => {
      const productName = item.product_name || item.name || 'Product';
      const unitPrice = Number(item.unit_price_minor ?? item.price_minor ?? 0);
      const subtotal = Number(item.subtotal_minor ?? unitPrice * Number(item.quantity || 0));
      const campaignLabel = item.campaign_name
        ? `<small class="checkout-campaign-label">${item.campaign_funding === 'platform' ? 'Bearly-funded campaign' : 'Seller campaign'}: ${escapeHtml(item.campaign_name)}</small>`
        : '';
      const options = item.options && typeof item.options === 'object'
        ? Object.entries(item.options).map(([name, value]) => `${name}: ${value}`).join(' · ')
        : '';

      return `<article class="checkout-product">
        <div class="checkout-product-main"><img src="${escapeHtml(imageUrl(item.image))}" onerror="this.onerror=null;this.src='/images/bearly-logo.png'" alt="${escapeHtml(productName)}">
          <div><div class="checkout-product-name">${escapeHtml(productName)}</div><div class="checkout-product-meta">${escapeHtml(item.variant_name || '')}${options ? ` · ${escapeHtml(options)}` : ''}</div><small>${escapeHtml(item.seller_name || 'Bearly Seller')}</small>${campaignLabel}</div>
        </div>
        <div>${money(unitPrice)}</div><div>${Number(item.quantity || 0)}</div><div class="checkout-product-subtotal">${money(subtotal)}</div>
      </article>`;
    }).join('');

    const quantity = items.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
    $('order-items').textContent = quantity;
    $('summary-subtotal').textContent = money(items.reduce((sum, item) => {
      const unitPrice = Number(item.unit_price_minor ?? item.price_minor ?? 0);
      return sum + Number(item.subtotal_minor ?? unitPrice * Number(item.quantity || 0));
    }, 0));
  }

  function renderQuote() {
    if (!quote) {
      $('shipping-price').textContent = '—';
      $('summary-shipping').textContent = '—';
      $('summary-total').textContent = '—';
      $('order-total').textContent = '—';
      setDiscountRow('summary-seller-discount-row', 'summary-seller-discount', 0);
      setDiscountRow('summary-platform-discount-row', 'summary-platform-discount', 0);
      setDiscountRow('summary-shipping-discount-row', 'summary-shipping-discount', 0);
      $('shipping-breakdown').replaceChildren();
      renderAppliedVouchers();
      return;
    }

    $('shipping-price').textContent = money(quote.shipping_fee_minor);
    $('summary-shipping').textContent = money(quote.shipping_fee_minor);
    setDiscountRow('summary-seller-discount-row', 'summary-seller-discount', quote.seller_discount_minor);
    setDiscountRow('summary-platform-discount-row', 'summary-platform-discount', quote.platform_discount_minor);
    setDiscountRow('summary-shipping-discount-row', 'summary-shipping-discount', quote.shipping_discount_minor);
    $('summary-total').textContent = money(quote.total_minor);
    $('order-total').textContent = money(quote.total_minor);
    renderAppliedVouchers();
    $('shipping-breakdown').innerHTML = (quote.seller_orders || []).map(sellerOrder => {
      const route = sellerOrder.quote || {};
      const dimensions = route.parcel_dimensions_cm || {};
      const size = [dimensions.length, dimensions.width, dimensions.height].map(value => Number(value || 0).toLocaleString('en-PH', { maximumFractionDigits: 2 })).join(' × ');
      return `<div class="shipping-estimate-row"><span>${escapeHtml(sellerOrder.store_name)} · ${escapeHtml(route.route_label || 'Shipping')} · ${Number(route.billable_weight_kg || 0)} kg (${size} cm)</span><strong>${money(sellerOrder.shipping_fee_minor)}</strong></div>`;
    }).join('');
  }

  async function requestQuote() {
    const requestNumber = ++quoteRequest;
    quote = null;
    renderQuote();
    $('checkout-quote-token').value = '';
    $('checkout-error').hidden = true;
    const button = $('place-order');
    button.disabled = true;

    if (!items.length || !address) {
      $('checkout-status').textContent = !items.length ? 'Select saved cart items to continue.' : 'Add a saved delivery address to continue.';
      return;
    }

    quoting = true;
    $('checkout-status').textContent = 'Calculating estimated shipping…';
    try {
      const response = await fetch('/checkout/quote', {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({
          address_id: address.id,
          cart_item_ids: items.map(item => Number(item.id)),
          platform_voucher_id: promotionSelection.platform_voucher_id,
          store_voucher_id: promotionSelection.store_voucher_id,
          voucher_code: promotionSelection.voucher_code,
        }),
      });
      const result = await response.json().catch(() => ({}));
      if (requestNumber !== quoteRequest) return;
      if (!response.ok) {
        const message = Object.values(result.errors || {}).flat()[0] || result.message || 'Unable to calculate shipping.';
        throw new Error(message);
      }
      quote = result.data;
      const resolvedSelection = quote.promotion_selection || {};
      promotionSelection = {
        platform_voucher_id: Number(resolvedSelection.platform_voucher_id) || null,
        store_voucher_id: Number(resolvedSelection.store_voucher_id) || null,
        voucher_code: resolvedSelection.voucher_code || null,
      };
      syncPromotionSelection();
      if (promotionSelection.voucher_code) $('checkout-voucher-code-input').value = promotionSelection.voucher_code;
      setVoucherStatus(promotionSelection.voucher_code ? 'Voucher code applied to this order.' : '');
      const quotedItems = new Map((quote.items || []).map(item => [String(item.cart_item_id), item]));
      if (quotedItems.size !== items.length || items.some(item => !quotedItems.has(String(item.id)))) {
        quote = null;
        throw new Error('Your saved cart changed. Return to your cart and review the selected items.');
      }
      items = items.map(item => ({ ...item, ...quotedItems.get(String(item.id)) }));
      if (quote.delivery_address && Number(quote.delivery_address.id) === Number(address.id)) {
        address = { ...address, ...quote.delivery_address };
        renderAddress();
      }
      $('checkout-quote-token').value = String(quote.quote_token || '');
      renderItems();
      renderVoucherOptions();
      renderQuote();
      $('checkout-status').textContent = 'A flat shipping fee applies to each Seller Order. Package measurements are shown for delivery planning.';
      button.disabled = false;
    } catch (error) {
      if (requestNumber !== quoteRequest) return;
      $('checkout-status').textContent = 'Checkout needs attention before the order can be placed.';
      showError(error.message);
      if (promotionSelection.platform_voucher_id || promotionSelection.store_voucher_id || promotionSelection.voucher_code) {
        setVoucherStatus(error.message, 'error');
      }
    } finally {
      if (requestNumber === quoteRequest) quoting = false;
    }
  }

  function chooseAddress() {
    if (!addresses.length) {
      window.location.href = '/addresses';
      return;
    }
    $('choice-title').textContent = 'Choose delivery address';
    $('choice-options').innerHTML = addresses.map((value, index) => {
      const details = [value.street, value.barangay, value.city, value.province, value.postal].filter(Boolean).join(', ');
      return `<button class="choice-option" data-address-choice="${index}" type="button"><span>${escapeHtml(value.name)} · ${escapeHtml(value.phone)}</span><strong>${escapeHtml(details)}${value.isDefault ? ' · Default' : ''}</strong></button>`;
    }).join('');
    $('choice-modal').hidden = false;
  }

  $('change-address').addEventListener('click', chooseAddress);
  $('choice-options').addEventListener('click', event => {
    const button = event.target.closest('[data-address-choice]');
    if (!button) return;
    address = addresses[Number(button.dataset.addressChoice)] || null;
    $('choice-modal').hidden = true;
    renderAddress();
    requestQuote();
  });
  document.querySelector('[data-close]').addEventListener('click', () => { $('choice-modal').hidden = true; });

  $('checkout-form').addEventListener('submit', event => {
    if (!quote || !$('checkout-quote-token').value || quoting || !address || !items.length) {
      event.preventDefault();
      if (!quote && !quoting) requestQuote();
      return;
    }

    const fields = $('checkout-cart-item-ids');
    fields.replaceChildren(...items.map(item => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'cart_item_ids[]';
      input.value = String(item.id);
      return input;
    }));
    try { localStorage.removeItem(SELECTION_KEY); } catch { /* storage is optional */ }
    const button = $('place-order');
    button.disabled = true;
    button.textContent = 'Placing order…';
  });

  async function load() {
    try {
      const [cartResponse, addressResponse] = await Promise.all([
        fetch('/cart/data', { headers: { Accept: 'application/json' } }),
        fetch('/addresses/data', { headers: { Accept: 'application/json' } }),
      ]);
      if (!cartResponse.ok || !addressResponse.ok) throw new Error('Unable to load your cart or saved addresses.');
      const cartResult = await cartResponse.json();
      const addressResult = await addressResponse.json();
      allItems = Array.isArray(cartResult.data?.items) ? cartResult.data.items : [];
      addresses = Array.isArray(addressResult.data) ? addressResult.data : [];

      const keys = storedKeys();
      const directItemId = new URLSearchParams(window.location.search).get('item');
      items = directItemId !== null
        ? allItems.filter(item => String(item.id) === directItemId)
        : keys.length
          ? allItems.filter(item => keys.includes(`live:${item.id}`))
          : allItems;
      address = addresses.find(value => value.isDefault) || addresses[0] || null;

      if (hasPreviewItems()) $('local-preview-warning').hidden = false;
      renderItems();
      renderAddress();

      if (!items.length) {
        $('checkout-empty').hidden = false;
        $('checkout-status').textContent = 'No saved cart items are selected.';
        return;
      }
      if (!address) {
        $('checkout-status').textContent = 'Add a saved delivery address to see shipping.';
        showError('You need a saved delivery address before checking out.');
        return;
      }

      await requestQuote();
    } catch (error) {
      $('checkout-status').textContent = 'Your checkout details could not be loaded.';
      showError(error.message);
    }
  }

  load();
})();

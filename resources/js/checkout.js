(() => {
  const SELECTION_KEY = window.bearlyStorageKey?.('checkout-selection') || 'bearly-checkout-selection-v1';
  const PREVIEW_KEY = window.bearlyStorageKey?.('preview-cart') || 'bearly-preview-cart-v1';
  const $ = id => document.getElementById(id);
  const money = minor => '₱' + (Number(minor || 0) / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[char]));
  const imageUrl = value => { const image = String(value || '').trim(); return !image ? '/images/bearly-logo.png' : /^(https?:|\/|data:)/.test(image) ? image : `/${image.replace(/^\.\//, '')}`; };
  let allItems = [], items = [], addresses = [], address = null, quote = null, quoting = false, quoteRequest = 0;

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

  function renderItems() {
    $('checkout-products').innerHTML = items.map(item => {
      const productName = item.product_name || item.name || 'Product';
      const unitPrice = Number(item.unit_price_minor ?? item.price_minor ?? 0);
      const subtotal = Number(item.subtotal_minor ?? unitPrice * Number(item.quantity || 0));
      const options = item.options && typeof item.options === 'object'
        ? Object.entries(item.options).map(([name, value]) => `${name}: ${value}`).join(' · ')
        : '';

      return `<article class="checkout-product">
        <div class="checkout-product-main"><img src="${escapeHtml(imageUrl(item.image))}" onerror="this.onerror=null;this.src='/images/bearly-logo.png'" alt="${escapeHtml(productName)}">
          <div><div class="checkout-product-name">${escapeHtml(productName)}</div><div class="checkout-product-meta">${escapeHtml(item.variant_name || '')}${options ? ` · ${escapeHtml(options)}` : ''}</div><small>${escapeHtml(item.seller_name || 'Bearly Seller')}</small></div>
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
      $('shipping-breakdown').replaceChildren();
      return;
    }

    $('shipping-price').textContent = money(quote.shipping_fee_minor);
    $('summary-shipping').textContent = money(quote.shipping_fee_minor);
    $('summary-total').textContent = money(quote.total_minor);
    $('order-total').textContent = money(quote.total_minor);
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
        body: JSON.stringify({ address_id: address.id, cart_item_ids: items.map(item => Number(item.id)) }),
      });
      const result = await response.json().catch(() => ({}));
      if (requestNumber !== quoteRequest) return;
      if (!response.ok) {
        const message = Object.values(result.errors || {}).flat()[0] || result.message || 'Unable to calculate shipping.';
        throw new Error(message);
      }
      quote = result.data;
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
      renderQuote();
      $('checkout-status').textContent = 'A flat shipping fee applies to each Seller Order. Package measurements are shown for delivery planning.';
      button.disabled = false;
    } catch (error) {
      if (requestNumber !== quoteRequest) return;
      $('checkout-status').textContent = 'Checkout needs attention before the order can be placed.';
      showError(error.message);
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

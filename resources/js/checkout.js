(() => {
  const PREVIEW_KEY = window.bearlyStorageKey?.('preview-cart') || 'bearly-preview-cart-v1';
  const SELECTION_KEY = window.bearlyStorageKey?.('checkout-selection') || 'bearly-checkout-selection-v1';
  const VOUCHER_KEY = window.bearlyStorageKey?.('claimed-vouchers') || 'bearly-claimed-vouchers-v1';
  const $ = id => document.getElementById(id);
  const money = value => '₱' + Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[char]));
  const imageUrl = value => { const image = String(value || '').trim(); return !image ? '/images/bearly-logo.png' : /^(https?:|\/|data:)/.test(image) ? image : `/${image.replace(/^\.\//, '')}`; };
  let shipping = 50, shippingBase = 50, discount = 0, payment = 'Cash on Delivery', voucher = null, allItems = [], items = [], addresses = [];

  function readPreview() { try { const value = JSON.parse(localStorage.getItem(PREVIEW_KEY) || '[]'); return Array.isArray(value) ? value.map(item => ({ ...item, source: 'preview', key: `preview:${item.key || item.product_id}` })) : []; } catch { return []; } }
  function selectedKeys() { try { const value = JSON.parse(localStorage.getItem(SELECTION_KEY) || '[]'); return Array.isArray(value) ? value : []; } catch { return []; } }
  function toast(message) { const element = $('checkout-toast'); element.textContent = message; element.classList.add('show'); clearTimeout(window.__checkoutToast); window.__checkoutToast = setTimeout(() => element.classList.remove('show'), 1700); }
  function subtotal() { return items.reduce((sum, item) => sum + Number(item.price || 0) * Number(item.quantity || 1), 0); }
  function syncShipping() { shipping = voucher?.shippingVoucher ? Math.max(0, shippingBase - Number(voucher.discount || 0)) : shippingBase; }
  function renderAddress(address) { const tag = $('address-default-tag'); if (tag) tag.hidden = !address?.isDefault; if (!address) return; $('address-name').textContent = address.name; $('address-phone').textContent = address.phone; $('address-text').textContent = [address.street, address.barangay, address.city, address.province, address.postal].filter(Boolean).join(', '); }
  async function loadAddresses() { try { const response = await fetch('/addresses/data', { headers: { 'Accept': 'application/json' } }); const result = await response.json(); addresses = Array.isArray(result.data) ? result.data : []; renderAddress(addresses.find(address => address.isDefault) || addresses[0]); } catch { addresses = []; } }
  function render() {
    if (!items.length) { $('checkout-empty').hidden = false; return; }
    $('checkout-empty').hidden = true;
    $('checkout-products').innerHTML = items.map(item => `<article class="checkout-product"><div class="checkout-product-main"><img src="${escapeHtml(imageUrl(item.image || item.photo))}" onerror="this.onerror=null;this.src='/images/bearly-logo.png'" alt="${escapeHtml(item.name)}"><div><div class="checkout-product-name">${escapeHtml(item.name)}</div><div class="checkout-product-meta">${item.variant_name ? escapeHtml(item.variant_name) : ''}${item.color ? `Variation: ${escapeHtml(item.color)}` : ''}${item.size ? ` · ${escapeHtml(item.size)}` : ''}</div></div></div><div>${money(item.price)}</div><div>${Number(item.quantity || 1)}</div><div class="checkout-product-subtotal">${money(Number(item.price || 0) * Number(item.quantity || 1))}</div></article>`).join('');
    const quantity = items.reduce((sum, item) => sum + Number(item.quantity || 1), 0), sub = subtotal(), total = Math.max(0, sub + shipping - discount);
    $('order-items').textContent = quantity; $('summary-subtotal').textContent = money(sub); $('summary-shipping').textContent = money(shipping); $('summary-discount').textContent = `−${money(discount)}`; $('summary-total').textContent = money(total); $('order-total').textContent = money(total); $('shipping-price').textContent = money(shipping); $('payment-label').textContent = payment; $('voucher-status').textContent = voucher ? voucher.label : 'No voucher selected';
  }
  function choose(title, options, onPick) { $('choice-title').textContent = title; $('choice-options').innerHTML = options.map((option, index) => `<button class="choice-option" data-choice="${index}" type="button"><span>${escapeHtml(option.label)}</span><strong>${escapeHtml(option.note || '')}</strong></button>`).join(''); $('choice-modal').hidden = false; $('choice-options').onclick = event => { const button = event.target.closest('[data-choice]'); if (!button) return; onPick(options[Number(button.dataset.choice)]); $('choice-modal').hidden = true; render(); }; }
  document.querySelector('[data-close]').onclick = () => $('choice-modal').hidden = true;
  $('change-shipping').onclick = () => choose('Choose Shipping Option', [{ label:'Standard Delivery', note:'₱50', price:50, days:'Estimated 3–7 days' }, { label:'Economy Delivery', note:'₱35', price:35, days:'Estimated 5–10 days' }, { label:'Express Delivery', note:'₱120', price:120, days:'Estimated 1–2 days' }], option => { shippingBase = option.price; syncShipping(); $('shipping-label').textContent = option.label; $('shipping-label').nextElementSibling.textContent = option.days; });
  $('voucher-btn').onclick = () => {
    let claimed = []; try { claimed = JSON.parse(localStorage.getItem(VOUCHER_KEY) || '[]'); } catch {}
    const catalog = [{ id:'BEARLY50', label:'BEARLY50', note:'₱50 off · Min ₱399', discount:50, min:399 }, { id:'BEARLY100', label:'BEARLY100', note:'₱100 off · Min ₱799', discount:100, min:799 }, { id:'BEARLYSHIP', label:'BEARLYSHIP', note:'Free shipping up to ₱50 · Min ₱499', discount:50, min:499, shippingVoucher:true }, { id:'PAYDAY30', label:'PAYDAY30', note:'30% off up to ₱150 · Min ₱599', percent:30, cap:150, min:599 }];
    const eligible = catalog.filter(option => claimed.includes(option.id)); if (!eligible.length) { toast('Claim a preview voucher from My Vouchers first.'); setTimeout(() => window.location.href = '/profile#vouchers', 700); return; }
     choose('Select Bearly Voucher', [{ label:'No Voucher', note:'₱0 off', discount:0 }, ...eligible], option => { if (!option.id) { discount = 0; voucher = null; syncShipping(); return; } if (subtotal() < option.min) { discount = 0; voucher = null; syncShipping(); toast(`Minimum spend is ₱${option.min}.`); return; } voucher = option; discount = option.shippingVoucher ? 0 : option.percent ? Math.min(option.cap, subtotal() * (option.percent / 100)) : Math.min(option.discount, subtotal()); syncShipping(); });
  };
  $('change-address').onclick = () => { if (!addresses.length) { window.location.href = '/addresses'; return; } choose('Choose Delivery Address', addresses.map(address => ({ ...address, label: `${address.name} · ${address.phone}`, note: [address.street, address.barangay, address.city, address.province, address.postal].filter(Boolean).join(', ') })), renderAddress); };
  $('place-order').onclick = () => toast('Checkout preview only. No order or payment was created.');

  async function load() {
    try {
      const response = await fetch('/cart/data', { headers: { 'Accept': 'application/json' } });
      const result = await response.json();
      const live = Array.isArray(result.data?.items) ? result.data.items.map(item => ({ ...item, source:'live', key:`live:${item.id}`, price:Number(item.price_minor || 0) / 100 })) : [];
      allItems = [...live, ...readPreview()];
    } catch { allItems = readPreview(); }
    const keys = selectedKeys(); items = keys.length ? allItems.filter(item => keys.includes(item.key)) : allItems;
    await loadAddresses(); render();
  }
  load();
})();

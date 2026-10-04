(() => {
  const PREVIEW_KEY = window.bearlyStorageKey?.('preview-cart') || 'bearly-preview-cart-v1';
  const $ = id => document.getElementById(id);
  const money = value => '₱' + Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[char]));
  const imageUrl = value => { const image = String(value || '').trim(); return !image ? '/images/bearly-logo.png' : /^(https?:|\/|data:)/.test(image) ? image : `/${image.replace(/^\.\//, '')}`; };
  let liveItems = Array.isArray(window.bearlyCartItems) ? window.bearlyCartItems.map(item => ({ ...item, key: `live:${item.id}`, source: 'live', price: Number(item.price_minor || 0) / 100 })) : [];
  let previewItems = readPreview();
  let items = [], selected = new Set();

  function readPreview() { try { const value = JSON.parse(localStorage.getItem(PREVIEW_KEY) || '[]'); return Array.isArray(value) ? value.map(item => ({ ...item, source: 'preview', key: `preview:${item.key || item.product_id}` })) : []; } catch { return []; } }
  function writePreview() { localStorage.setItem(PREVIEW_KEY, JSON.stringify(previewItems.map(({ source, key, ...item }) => item))); }
  function toast(message) { const element = $('cart-toast'); element.textContent = message; element.classList.add('show'); clearTimeout(window.__cartToast); window.__cartToast = setTimeout(() => element.classList.remove('show'), 1600); }
  function itemKey(item) { return String(item.key); }
  function syncItems() {
    items = [...liveItems, ...previewItems].filter(item => item && item.name && Number(item.quantity) > 0);
    const keys = new Set(items.map(itemKey));
    selected = new Set([...selected].filter(key => keys.has(key)));
    if (!selected.size) selected = new Set(items.map(itemKey));
  }
  function syncChecks() {
    const all = items.length > 0 && items.every(item => selected.has(itemKey(item)));
    $('select-all-top').checked = all; $('select-all-bottom').checked = all;
  }
  function totals() {
    const chosen = items.filter(item => selected.has(itemKey(item)));
    const count = chosen.reduce((sum, item) => sum + Number(item.quantity || 1), 0);
    const total = chosen.reduce((sum, item) => sum + Number(item.price || 0) * Number(item.quantity || 1), 0);
    $('selected-count').textContent = count; $('cart-total').textContent = money(total); $('cart-checkout').disabled = count === 0;
  }
  function render() {
    syncItems();
    const quantity = items.reduce((sum, item) => sum + Number(item.quantity || 1), 0);
    $('cart-count').textContent = quantity; $('cart-header-count').textContent = quantity; $('select-all-label').textContent = `Select All (${items.length})`;
    $('cart-content').hidden = !items.length; $('cart-empty').hidden = Boolean(items.length); $('cart-bar').hidden = !items.length;
    $('cart-preview-notice').hidden = !previewItems.length;
    $('cart-list').innerHTML = items.map(item => `<section class="seller-card" data-key="${escapeHtml(itemKey(item))}"><div class="seller-head"><span class="material-symbols-outlined">storefront</span>${escapeHtml(item.seller_name || 'Bearly Seller')}${item.source === 'preview' ? '<small class="preview-badge">Preview</small>' : ''}</div><div class="cart-row"><label class="check-wrap"><input class="item-check" type="checkbox" ${selected.has(itemKey(item)) ? 'checked' : ''}><span></span></label><div class="product-cell"><div class="product-img"><img src="${escapeHtml(imageUrl(item.image || item.photo))}" alt="${escapeHtml(item.name)}" onerror="this.onerror=null;this.src='/images/bearly-logo.png'"></div><div><div class="product-name">${escapeHtml(item.name)}</div><div class="product-meta">${item.variant_name ? escapeHtml(item.variant_name) : ''}${item.color ? `Variation: ${escapeHtml(item.color)}` : ''}${item.size ? ` · ${escapeHtml(item.size)}` : ''}</div></div></div><div class="unit-price">${money(item.price)}</div><div class="qty-control"><button type="button" data-minus aria-label="Decrease quantity">−</button><span>${Number(item.quantity || 1)}</span><button type="button" data-plus aria-label="Increase quantity">+</button></div><div class="line-total">${money(Number(item.price || 0) * Number(item.quantity || 1))}</div><div class="action-cell"><button type="button" data-remove>Delete</button><button type="button" class="find-similar">Find Similar</button></div></div></section>`).join('');
    syncChecks(); totals();
  }
  async function liveRequest(item, method, quantity) {
    const response = await fetch(`/cart/${encodeURIComponent(item.id)}`, { method, headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: method === 'PATCH' ? JSON.stringify({ quantity }) : undefined });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(Object.values(result.errors || {}).flat()[0] || result.message || 'Unable to update cart.');
    return result;
  }
  async function updateItem(item, quantity) {
    if (item.source === 'preview') { item.quantity = quantity; writePreview(); render(); return; }
    try { await liveRequest(item, 'PATCH', quantity); item.quantity = quantity; render(); } catch (error) { toast(error.message); }
  }
  async function removeItem(item) {
    if (item.source === 'preview') { previewItems = previewItems.filter(value => itemKey(value) !== itemKey(item)); writePreview(); render(); toast('Item removed'); return; }
    try { await liveRequest(item, 'DELETE'); liveItems = liveItems.filter(value => itemKey(value) !== itemKey(item)); render(); toast('Item removed'); } catch (error) { toast(error.message); }
  }
  $('cart-list').addEventListener('click', event => {
    const row = event.target.closest('.seller-card'); if (!row) return;
    const item = items.find(value => itemKey(value) === row.dataset.key); if (!item) return;
    if (event.target.closest('[data-plus]')) return updateItem(item, Number(item.quantity || 1) + 1);
    if (event.target.closest('[data-minus]')) return updateItem(item, Math.max(1, Number(item.quantity || 1) - 1));
    if (event.target.closest('[data-remove]')) { selected.delete(itemKey(item)); return removeItem(item); }
  });
  $('cart-list').addEventListener('change', event => { if (!event.target.classList.contains('item-check')) return; const row = event.target.closest('.seller-card'); event.target.checked ? selected.add(row.dataset.key) : selected.delete(row.dataset.key); syncChecks(); totals(); });
  function selectAll(on) { selected = new Set(on ? items.map(itemKey) : []); syncChecks(); totals(); render(); }
  $('select-all-top').addEventListener('change', event => selectAll(event.target.checked)); $('select-all-bottom').addEventListener('change', event => selectAll(event.target.checked)); $('select-all-label').addEventListener('click', () => selectAll(true));
  $('delete-selected').addEventListener('click', async () => { const chosen = items.filter(item => selected.has(itemKey(item))); if (!chosen.length) return toast('Select an item first'); await Promise.all(chosen.map(removeItem)); toast('Selected items deleted'); });
  $('cart-checkout').addEventListener('click', () => { const keys = items.filter(item => selected.has(itemKey(item))).map(itemKey); if (!keys.length) return toast('Select at least one item'); localStorage.setItem(window.bearlyStorageKey?.('checkout-selection') || 'bearly-checkout-selection-v1', JSON.stringify(keys)); window.location.href = '/checkout'; });
  render();
  window.addEventListener('storage', event => { if (event.key === PREVIEW_KEY) { previewItems = readPreview(); render(); } });
})();

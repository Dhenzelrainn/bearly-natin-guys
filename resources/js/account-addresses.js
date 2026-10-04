(() => {
  const profileForm = document.getElementById('address-editing-id') !== null;
  const ids = profileForm ? {
    editing: 'address-editing-id', name: 'address-full-name', phone: 'address-phone-number', province: 'address-province', city: 'address-city', barangay: 'address-barangay', postal: 'address-postal-code', street: 'address-street', default: 'address-set-default'
  } : {
    editing: 'editing-id', name: 'full-name', phone: 'phone-number', province: 'province', city: 'city', barangay: 'barangay', postal: 'postal-code', street: 'street', default: 'set-default'
  };
  const $ = id => document.getElementById(id);
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
  let addresses = [], activeLabel = 'Home', pendingDelete = null;
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[char]));
  const value = key => $(ids[key])?.value || '';
  const setValue = (key, data) => { if ($(ids[key])) $(ids[key]).value = data ?? ''; };

  function toast(message) {
    const element = $('address-toast'); if (!element) return;
    element.textContent = message; element.classList.add('show'); clearTimeout(window.__accountAddressToast);
    window.__accountAddressToast = setTimeout(() => element.classList.remove('show'), 1600);
  }
  function fullAddress(address) { return [address.street, address.barangay, address.city, address.province, address.postal].filter(Boolean).join(', '); }
  function render() {
    const list = $('address-list'); if (!list) return;
    list.innerHTML = addresses.map(address => `<article class="address-item" data-id="${escapeHtml(address.id)}"><div><div class="address-name-line"><strong>${escapeHtml(address.name)}</strong><span class="address-phone">${escapeHtml(address.phone)}</span></div><div class="address-text">${escapeHtml(fullAddress(address))}</div><div class="address-tags"><span class="address-tag">${escapeHtml(address.label || 'Home')}</span>${address.isDefault ? '<span class="address-tag default-badge">Default</span>' : ''}</div></div><div class="address-actions"><div class="address-actions-top"><button class="edit-btn" data-edit type="button">Edit</button><button class="delete-btn" data-delete type="button">Delete</button></div><button class="set-default-btn" data-default type="button" ${address.isDefault ? 'disabled' : ''}>${address.isDefault ? 'Default Address' : 'Set as Default'}</button></div></article>`).join('');
    $('address-empty').hidden = addresses.length > 0;
  }
  async function request(url, options = {}) {
    const response = await fetch(url, { ...options, headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), ...(options.headers || {}) } });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(Object.values(result.errors || {}).flat()[0] || result.message || 'Unable to save address.');
    return result;
  }
  async function load() {
    try { const result = await request('/addresses/data', { method: 'GET' }); addresses = Array.isArray(result.data) ? result.data : []; render(); }
    catch (error) { toast(error.message); }
  }
  function setLabel(label) { activeLabel = label; document.querySelectorAll('.label-choice').forEach(button => button.classList.toggle('active', button.dataset.label === label)); }
  function openForm(address = null) {
    $('address-modal-title').textContent = address ? 'Edit Address' : 'New Address'; setValue('editing', address?.id || '');
    setValue('name', address?.name || ''); setValue('phone', address?.phone || ''); setValue('province', address?.province || ''); setValue('city', address?.city || ''); setValue('barangay', address?.barangay || ''); setValue('postal', address?.postal || ''); setValue('street', address?.street || '');
    $(ids.default).checked = Boolean(address?.isDefault); setLabel(address?.label || 'Home'); $('address-modal').hidden = false; setTimeout(() => $(ids.name)?.focus(), 30);
  }
  function closeForm() { $('address-modal').hidden = true; $('address-form').reset(); setValue('editing', ''); setLabel('Home'); }
  function formData() { return { name: value('name').trim(), phone: value('phone').trim(), province: value('province').trim(), city: value('city').trim(), barangay: value('barangay').trim(), postal: value('postal').trim(), street: value('street').trim(), label: activeLabel, is_default: Boolean($(ids.default)?.checked) }; }

  $('add-address').onclick = () => openForm(); $('empty-add-address').onclick = () => openForm(); $('close-address-modal').onclick = closeForm; $('cancel-address').onclick = closeForm;
  document.querySelectorAll('.label-choice').forEach(button => button.onclick = () => setLabel(button.dataset.label));
  $('address-form').onsubmit = async event => { event.preventDefault(); const id = value('editing'); try { await request(id ? `/addresses/${encodeURIComponent(id)}` : '/addresses', { method: id ? 'PATCH' : 'POST', body: JSON.stringify(formData()) }); closeForm(); await load(); toast(id ? 'Address updated' : 'Address added'); } catch (error) { toast(error.message); } };
  $('address-list').onclick = async event => { const row = event.target.closest('.address-item'); if (!row) return; const id = row.dataset.id; const address = addresses.find(item => String(item.id) === id); if (event.target.closest('[data-edit]')) return openForm(address); if (event.target.closest('[data-delete]')) { pendingDelete = id; $('delete-modal').hidden = false; return; } if (event.target.closest('[data-default]')) { try { await request(`/addresses/${encodeURIComponent(id)}/default`, { method: 'PATCH', body: '{}' }); await load(); toast('Default address updated'); } catch (error) { toast(error.message); } } };
  $('cancel-delete').onclick = () => { $('delete-modal').hidden = true; pendingDelete = null; };
  $('confirm-delete').onclick = async () => { if (!pendingDelete) return; try { await request(`/addresses/${encodeURIComponent(pendingDelete)}`, { method: 'DELETE' }); $('delete-modal').hidden = true; pendingDelete = null; await load(); toast('Address deleted'); } catch (error) { toast(error.message); } };
  $('address-modal').onclick = event => { if (event.target === $('address-modal')) closeForm(); }; $('delete-modal').onclick = event => { if (event.target === $('delete-modal')) { $('delete-modal').hidden = true; pendingDelete = null; } };
  load();
})();

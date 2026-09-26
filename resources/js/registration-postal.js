export function setupPostal(form) {
    const input = form.elements.postal_code;
    const cityCode = form.elements.city_code;
    const manualFlag = form.elements.postal_manual;
    const choices = form.querySelector('[data-postal-choices]');
    const help = form.querySelector('[data-postal-help]');
    const correct = form.querySelector('[data-postal-manual]');
    let request;
    let generation = 0;
    const previous = input.value;
    const wasManual = manualFlag.value === '1';
    let restoring = true;
    input.addEventListener('input', () => { input.value = input.value.replace(/[^0-9]/g, '').slice(0, 4); });
    choices.addEventListener('change', () => { input.value = choices.value; });
    function manual() {
        request?.abort();
        generation++;
        input.readOnly = false;
        manualFlag.value = '1';
        choices.hidden = true;
        help.textContent = 'Enter the four-digit postal code for your area.';
    }
    correct.addEventListener('click', () => { manual(); input.focus(); });
    function clear() {
        request?.abort();
        generation++;
        input.value = '';
        input.readOnly = false;
        cityCode.value = '';
        choices.hidden = true;
        choices.replaceChildren();
        manualFlag.value = '0';
        help.textContent = 'Select a city or municipality to find its postal code.';
    }
    async function lookup() {
        clear();
        const city = form.elements.city;
        const province = form.elements.province;
        if (!city?.value || !province?.value) return;
        cityCode.value = city.selectedOptions?.[0]?.dataset.code || '';
        const version = generation;
        request = new AbortController();
        const controller = request;
        const timeout = setTimeout(() => controller.abort(), 10000);
        help.textContent = 'Finding postal codes…';
        try {
            const query = new URLSearchParams({ city_code: cityCode.value, city: city.value, province: province.value });
            const response = await fetch(`${form.dataset.postalUrl}?${query}`, { headers: { Accept: 'application/json' }, signal: controller.signal });
            if (!response.ok) throw new Error('Postal lookup failed');
            const data = await response.json();
            if (version !== generation) return;
            if (restoring && wasManual && previous) {
                manual();
                input.value = previous;
            } else if (data.options.length === 1) {
                input.value = data.options[0].code;
                input.readOnly = true;
                help.textContent = 'Filled from PHLPost data. Use manual entry if your district has a different code.';
            } else if (data.options.length > 1) {
                choices.add(new Option('Choose your postal area', ''));
                data.options.forEach(({ area, code }) => choices.add(new Option(`${area} — ${code}`, code)));
                choices.hidden = false;
                input.readOnly = true;
                help.textContent = 'This municipality has multiple listed postal areas. Choose yours.';
                if (restoring && data.options.some(x => x.code === previous)) {
                    choices.value = previous;
                    input.value = previous;
                }
            } else {
                manual();
                help.textContent = 'No confirmed match in our postal list. Enter your area’s four-digit code.';
                if (restoring) input.value = previous;
            }
            restoring = false;
        } catch (error) {
            if (version !== generation) return;
            manual();
            help.textContent = 'Postal lookup is unavailable. You can enter your code manually.';
        } finally { clearTimeout(timeout); }
    }
    return { lookup, clear, manual };
}

function setSelect(select, placeholder, rows = []) {
    select.replaceChildren(new Option(placeholder, ''));

    rows
        .filter(item => item && item.name && item.code)
        .sort((left, right) => String(left.name).localeCompare(String(right.name)))
        .forEach(item => {
            const option = new Option(String(item.name), String(item.name));
            option.dataset.code = String(item.code);
            select.add(option);
        });

    select.disabled = false;
}

async function fetchAddressRows(path, signal) {
    const response = await fetch(`/api/psgc${path}`, {
        headers: { Accept: 'application/json' },
        signal,
    });
    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(payload.message || 'Unable to load PSGC address data.');
    }

    const rows = Array.isArray(payload) ? payload : payload.data;
    if (!Array.isArray(rows)) throw new Error('The PSGC address response was invalid.');

    return rows;
}

export function setupPsgcAddressSelector({ province, city, cityCode, status }) {
    if (!province || !city || !cityCode) return null;

    let provinceRequest = null;
    let cityRequest = null;
    let generation = 0;

    const setStatus = message => {
        if (status) status.textContent = message;
    };

    const loadCities = async selectedCityCode => {
        const requestNumber = ++generation;
        cityRequest?.abort();
        cityRequest = new AbortController();
        cityCode.value = '';
        city.disabled = true;
        setSelect(city, 'Loading cities…');
        city.disabled = true;

        const provinceCode = province.selectedOptions[0]?.dataset.code;
        if (!provinceCode) {
            setSelect(city, 'Select province first');
            city.disabled = true;
            setStatus('Select a province, then select your city or municipality from the PSGC list.');
            return;
        }

        try {
            const rows = await fetchAddressRows(
                `/provinces/${encodeURIComponent(provinceCode)}/cities`,
                cityRequest.signal,
            );
            if (requestNumber !== generation) return;

            setSelect(city, 'Select city or municipality', rows);
            const selected = [...city.options].find(option => option.dataset.code === String(selectedCityCode || ''));
            if (selected && selectedCityCode) {
                city.value = selected.value;
                cityCode.value = selected.dataset.code;
                setStatus('PSGC city verified. Saved addresses without a city code must be re-verified here before checkout.');
            } else {
                setStatus(selectedCityCode
                    ? 'This saved city could not be matched. Select it again from the PSGC list to verify the address.'
                    : 'Select your city or municipality from the PSGC list. GPS and typed names do not verify a city.');
            }
        } catch (error) {
            if (error.name === 'AbortError' || requestNumber !== generation) return;
            setSelect(city, 'Unable to load cities');
            city.disabled = true;
            setStatus(`${error.message} Retry by selecting the province again. The address cannot be saved until a PSGC city is selected.`);
        }
    };

    const loadProvinces = async selectedProvince => {
        provinceRequest?.abort();
        provinceRequest = new AbortController();
        province.disabled = true;
        setSelect(province, 'Loading provinces…');
        province.disabled = true;

        try {
            const rows = await fetchAddressRows('/provinces', provinceRequest.signal);
            setSelect(province, 'Select province', rows);
            const match = [...province.options].find(option =>
                option.value.toLocaleLowerCase() === String(selectedProvince || '').trim().toLocaleLowerCase(),
            );
            if (match && selectedProvince) province.value = match.value;
            if (province.value) {
                await loadCities('');
            } else {
                setSelect(city, 'Select province first');
                city.disabled = true;
                setStatus(selectedProvince
                    ? 'This saved province is not in the current PSGC list. Select a province and city again to verify it.'
                    : 'Select a province, then select your city or municipality from the PSGC list.');
            }
        } catch (error) {
            setSelect(province, 'Unable to load provinces');
            province.disabled = true;
            setSelect(city, 'Select province first');
            city.disabled = true;
            setStatus(`${error.message} Reload the address page and try again.`);
        }
    };

    province.addEventListener('change', () => {
        cityCode.value = '';
        city.removeAttribute('aria-invalid');
        void loadCities('');
    });

    city.addEventListener('change', () => {
        cityCode.value = city.selectedOptions[0]?.dataset.code || '';
        city.removeAttribute('aria-invalid');
        setStatus(cityCode.value
            ? 'PSGC city selected. Verify the address details before saving.'
            : 'Select your city or municipality from the PSGC list.');
    });

    return {
        load: () => loadProvinces(''),
        setAddress: async (provinceName, selectedCityCode) => {
            await loadProvinces(provinceName);
            if (province.value) {
                await loadCities(selectedCityCode || '');
                if (!selectedCityCode && status) {
                    setStatus('This saved address has no verified PSGC city code. Select its city again from the list before saving or checking out.');
                }
            }
        },
        clearCityCode: () => { cityCode.value = ''; },
    };
}

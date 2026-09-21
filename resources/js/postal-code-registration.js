import postalData from '../data/ph-postal-codes.json';

(() => {
    'use strict';

    const registration = document.querySelector('[data-registration]');
    if (!registration) return;

    const provinceSelect = registration.querySelector('[data-province-select]');
    const citySelect = registration.querySelector('[data-city-select]');
    const postalInput = registration.querySelector('#postal-code');

    if (!provinceSelect || !citySelect || !postalInput) return;

    const normalize = (value = '') =>
        String(value)
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/\bcity of\b/g, '')
            .replace(/\bcity\b/g, '')
            .replace(/\bmunicipality of\b/g, '')
            .replace(/\bmunicipality\b/g, '')
            .replace(/\bprovince of\b/g, '')
            .replace(/\(.*?\)/g, '')
            .replace(/\bsta\.\b/g, 'santa')
            .replace(/\bsto\.\b/g, 'santo')
            .replace(/\bgen\.\b/g, 'general')
            .replace(/[^a-z0-9]+/g, ' ')
            .trim()
            .replace(/\s+/g, ' ');

    const provinceAliases = new Map([
        ['metropolitan manila', 'metro manila'],
        ['national capital region', 'metro manila'],
        ['ncr', 'metro manila'],
        ['davao de oro', 'davao de oro formerly compostela valley'],
        ['compostela valley', 'davao de oro formerly compostela valley'],
        ['north cotabato', 'cotabato north'],
        ['cotabato', 'cotabato north'],
        ['camarines sur', 'camarines sur camsur'],
    ]);

    const normalizeProvince = (value = '') => {
        const cleaned = normalize(value);
        return provinceAliases.get(cleaned) || cleaned;
    };

    const findProvinceKey = (provinceName) => {
        const target = normalizeProvince(provinceName);

        return Object.keys(postalData).find(
            (key) => normalizeProvince(key) === target
        ) || null;
    };

    const findCityKey = (provinceKey, cityName) => {
        const cities = postalData[provinceKey] || {};
        const target = normalize(cityName);

        let match = Object.keys(cities).find(
            (key) => normalize(key) === target
        );

        if (match) return match;

        match = Object.keys(cities).find((key) => {
            const normalizedKey = normalize(key);

            return (
                normalizedKey.startsWith(`${target} `) ||
                target.startsWith(`${normalizedKey} `)
            );
        });

        return match || null;
    };

    const setPostalCode = (value = '', placeholder = 'Select municipality first') => {
        postalInput.value = value;
        postalInput.placeholder = placeholder;
        postalInput.readOnly = true;
    };

    const updatePostalCode = () => {
        const province = provinceSelect.value?.trim() || '';
        const city = citySelect.value?.trim() || '';

        if (!province || !city) {
            setPostalCode('', 'Select municipality first');
            return;
        }

        const provinceKey = findProvinceKey(province);

        if (!provinceKey) {
            setPostalCode('', 'Postal code unavailable');
            return;
        }

        const cityKey = findCityKey(provinceKey, city);

        if (!cityKey) {
            setPostalCode('', 'Postal code unavailable');
            return;
        }

        const zip = postalData[provinceKey][cityKey];

        if (Array.isArray(zip)) {
            postalInput.readOnly = false;
            postalInput.value = '';
            postalInput.placeholder = 'Enter your postal code';
            return;
        }

        const zipValue = String(zip || '');

        if (!/^\d{4}$/.test(zipValue)) {
            setPostalCode('', 'Postal code unavailable');
            return;
        }

        setPostalCode(zipValue, zipValue);
        postalInput.dispatchEvent(new Event('input', { bubbles: true }));
        postalInput.dispatchEvent(new Event('change', { bubbles: true }));
    };

    provinceSelect.addEventListener('change', () => {
        setPostalCode('', 'Select municipality first');
    });

    citySelect.addEventListener('change', updatePostalCode);

    registration.addEventListener('click', (event) => {
        if (!event.target.closest('[data-address-manual]')) return;

        window.setTimeout(() => {
            postalInput.readOnly = false;
            postalInput.placeholder = 'Enter postal code';
        }, 0);
    });

    updatePostalCode();
})();

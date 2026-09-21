(() => {
    'use strict';

    const registration = document.querySelector('[data-registration]');
    if (!registration) return;

    const provinceSelect = registration.querySelector('[data-province-select]');
    const citySelect = registration.querySelector('[data-city-select]');
    const postalInput = registration.querySelector('input[name="postal_code"]');

    if (!provinceSelect || !citySelect || !postalInput) return;

    const POSTAL_DATA_URL = '/data/ph-postal-codes.json';
    let postalData = {};

    const normalize = (value = '') =>
        value
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/\bcity of\b/g, '')
            .replace(/\bcity\b/g, '')
            .replace(/\bmunicipality of\b/g, '')
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
        );
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
                normalizedKey.startsWith(target + ' ') ||
                target.startsWith(normalizedKey + ' ')
            );
        });

        return match || null;
    };

    const setPostalState = ({
        value = '',
        readonly = false,
        placeholder = 'Enter postal code',
    } = {}) => {
        postalInput.value = value;
        postalInput.readOnly = readonly;
        postalInput.placeholder = placeholder;

        postalInput.title = readonly
            ? 'Postal code was filled automatically from the selected city / municipality.'
            : 'Enter the 4-digit Philippine postal code.';
    };

    const updatePostalCode = () => {
        if (!provinceSelect.value || !citySelect.value) {
            setPostalState({
                value: '',
                readonly: true,
                placeholder: 'Select city first',
            });
            return;
        }

        const provinceKey = findProvinceKey(provinceSelect.value);

        if (!provinceKey) {
            setPostalState({
                value: '',
                readonly: false,
                placeholder: 'Enter postal code',
            });
            return;
        }

        const cityKey = findCityKey(provinceKey, citySelect.value);

        if (!cityKey) {
            setPostalState({
                value: '',
                readonly: false,
                placeholder: 'Postal code unavailable',
            });
            return;
        }

        const zip = postalData[provinceKey][cityKey];

        if (Array.isArray(zip)) {
            setPostalState({
                value: '',
                readonly: false,
                placeholder: 'Multiple ZIP codes — enter yours',
            });
            return;
        }

        if (/^\d{4}$/.test(String(zip))) {
            setPostalState({
                value: String(zip),
                readonly: true,
                placeholder: String(zip),
            });

            postalInput.dispatchEvent(new Event('input', { bubbles: true }));
            postalInput.dispatchEvent(new Event('change', { bubbles: true }));
            return;
        }

        setPostalState({
            value: '',
            readonly: false,
            placeholder: 'Enter postal code',
        });
    };

    const loadPostalData = async () => {
        try {
            const response = await fetch(POSTAL_DATA_URL, {
                headers: { Accept: 'application/json' },
                cache: 'force-cache',
            });

            if (!response.ok) {
                throw new Error(`Postal-code data returned HTTP ${response.status}`);
            }

            const payload = await response.json();

            if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
                throw new Error('Postal-code JSON must be an object.');
            }

            postalData = payload;
            updatePostalCode();
        } catch (error) {
            console.error('Unable to load postal-code data:', error);

            setPostalState({
                value: postalInput.value,
                readonly: false,
                placeholder: 'Enter postal code',
            });
        }
    };

    provinceSelect.addEventListener('change', () => {
        setPostalState({
            value: '',
            readonly: true,
            placeholder: 'Select city first',
        });
    });

    citySelect.addEventListener('change', updatePostalCode);

    registration.addEventListener('click', (event) => {
        if (!event.target.closest('[data-address-manual]')) return;

        window.setTimeout(() => {
            setPostalState({
                value: postalInput.value,
                readonly: false,
                placeholder: 'Enter postal code',
            });
        }, 0);
    });

    setPostalState({
        value: postalInput.value,
        readonly: true,
        placeholder: 'Select city first',
    });

    loadPostalData();
})();

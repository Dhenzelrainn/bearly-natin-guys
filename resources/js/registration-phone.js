import { getCountries, getCountryCallingCode, getExampleNumber, parsePhoneNumberFromString } from 'libphonenumber-js/max';
import examples from 'libphonenumber-js/mobile/examples';
import metadata from 'libphonenumber-js/metadata.max.json';

export function setupPhone(form, role) {
    const country = form.elements.phone_country;
    const national = form.querySelector('[data-phone-national]');
    const stored = form.elements.contact_number;
    const help = form.querySelector('[data-phone-help]');
    let selected = country.dataset.oldValue || 'PH';
    const names = new Intl.DisplayNames(['en'], { type: 'region' });
    getCountries().sort((a, b) => names.of(a).localeCompare(names.of(b))).forEach((region) => {
        country.add(new Option(`${names.of(region)} (+${getCountryCallingCode(region)})`, region));
    });
    country.value = getCountries().includes(selected) ? selected : 'PH';

    function parse() {
        const phone = parsePhoneNumberFromString(national.value, country.value);
        return phone?.country === country.value && phone.isValid() &&
            ['MOBILE', 'FIXED_LINE_OR_MOBILE'].includes(phone.getType()) ? phone : null;
    }
    function updateExample() {
        const example = getExampleNumber(country.value, examples);
        national.placeholder = example?.nationalNumber || 'Mobile number';
        // Country metadata supplies the longest supported national number, including
        // a domestic trunk prefix when users type or paste a local-format number.
        const countryData = metadata.countries[country.value];
        const mobileLengths = countryData[11]?.[1]?.[1] || countryData[3];
        national.maxLength = Math.min(15 - getCountryCallingCode(country.value).length, Math.max(...mobileLengths));
        help.textContent = `Country code +${getCountryCallingCode(country.value)}. Example: ${national.placeholder}.`;
    }
    function normalize(value) {
        const digits = value.replace(/[^0-9]/g, '');
        const input = value.trim().startsWith('+') ? `+${digits}` : digits;
        const phone = parsePhoneNumberFromString(input, country.value);
        if (phone?.country === country.value && phone.isPossible()) return phone.nationalNumber;
        return digits;
    }
    function inputChanged() {
        national.value = national.value.replace(/[^0-9]/g, '');
        const prefix = metadata.countries[country.value][5];
        if (prefix && /^[0-9]+$/.test(prefix) && national.value.startsWith(prefix)) national.value = national.value.slice(prefix.length);
        national.value = national.value.slice(0, national.maxLength);
        national.setCustomValidity('');
        stored.value = parse()?.number || '';
    }
    national.addEventListener('input', inputChanged);
    national.addEventListener('paste', (event) => {
        event.preventDefault();
        const pasted = event.clipboardData.getData('text').trim();
        if (pasted.startsWith('+')) {
            const international = parsePhoneNumberFromString(pasted);
            if (!international?.country || !international.isValid()) {
                national.value = '';
                stored.value = '';
                national.setCustomValidity('Check the international country code and number.');
                national.reportValidity();
                return;
            }
            country.value = international.country;
            updateExample();
        }
        national.value = normalize(pasted);
        inputChanged();
    });
    national.addEventListener('blur', () => {
        national.value = normalize(national.value);
        stored.value = parse()?.number || '';
    });
    country.addEventListener('change', () => {
        updateExample();
        stored.value = '';
        national.value = '';
        national.focus();
    });
    function validPhone() {
        const phone = parse();
        national.setCustomValidity(phone ? '' : 'Enter a valid mobile number for the selected country.');
        if (!phone) { national.reportValidity(); national.focus(); return false; }
        national.value = phone.nationalNumber;
        stored.value = phone.number;
        return true;
    }
    updateExample();
    if (stored.value) {
        national.value = normalize(stored.value);
        stored.value = parse()?.number || '';
    }
    return { updateRole: () => {}, validate: validPhone };
}

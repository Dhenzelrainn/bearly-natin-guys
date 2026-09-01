document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-toggle-password]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.togglePassword);
            if (!input) return;

            input.type = input.type === 'password' ? 'text' : 'password';
            button.setAttribute(
                'aria-label',
                input.type === 'password' ? 'Show password' : 'Hide password'
            );
        });
    });

    const demoLogin = document.querySelector('[data-demo-login]');
    demoLogin?.addEventListener('submit', (event) => {
        event.preventDefault();

        if (!demoLogin.checkValidity()) {
            demoLogin.reportValidity();
            return;
        }

        const message = demoLogin.querySelector('[data-login-message]');
        if (message) message.hidden = false;
    });

    const registration = document.querySelector('[data-registration]');
    if (!registration) return;

    const form = registration.querySelector('form');
    const next = form.querySelector('[data-next]');
    const back = form.querySelector('[data-back]');
    const submit = form.querySelector('[data-submit]');

    const PSGC_API = '/api/psgc';
    const provinceSelect = registration.querySelector('[data-province-select]');
    const citySelect = registration.querySelector('[data-city-select]');
    const barangaySelect = registration.querySelector('[data-barangay-select]');
    const addressMessage = registration.querySelector('[data-address-message]');
    const addressRetry = registration.querySelector('[data-address-retry]');
    const addressManual = registration.querySelector('[data-address-manual]');

    let currentStep = 1;
    let addressRequest = null;
    const searchableSelects = new Map();

    function normalizeSearchText(value) {
        return String(value)
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLocaleLowerCase('en-PH')
            .trim();
    }

    function closeSearchableSelect(select, returnFocus = false) {
        const widget = searchableSelects.get(select);
        if (!widget || widget.panel.hidden) return;

        widget.panel.hidden = true;
        widget.trigger.setAttribute('aria-expanded', 'false');
        widget.wrapper.classList.remove('is-open');
        widget.search.value = '';
        widget.renderOptions();

        if (returnFocus) widget.trigger.focus();
    }

    function closeOtherSearchableSelects(currentSelect) {
        searchableSelects.forEach((widget, select) => {
            if (select !== currentSelect) closeSearchableSelect(select);
        });
    }

    function openSearchableSelect(select) {
        const widget = searchableSelects.get(select);
        if (!widget || select.disabled) return;

        closeOtherSearchableSelects(select);
        widget.panel.hidden = false;
        widget.trigger.setAttribute('aria-expanded', 'true');
        widget.wrapper.classList.add('is-open');
        widget.search.value = '';
        widget.renderOptions();

        window.requestAnimationFrame(() => widget.search.focus());
    }

    function syncSearchableSelect(select) {
        const widget = searchableSelects.get(select);
        if (!widget) return;

        const selectedOption = select.selectedOptions[0];
        widget.value.textContent = selectedOption?.textContent || 'Select an option';
        widget.value.classList.toggle('is-placeholder', !select.value);
        widget.trigger.disabled = select.disabled;
        widget.trigger.setAttribute('aria-disabled', String(select.disabled));
        widget.wrapper.classList.toggle('is-disabled', select.disabled);
        widget.wrapper.classList.remove('is-invalid');

        if (select.disabled) closeSearchableSelect(select);
        widget.renderOptions();
    }

    function createSearchableSelect(select) {
        if (!select || searchableSelects.has(select)) return;

        const wrapper = document.createElement('div');
        wrapper.className = 'searchable-select';

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'searchable-select__trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.innerHTML = `
            <span class="searchable-select__value"></span>
            <svg viewBox="0 0 20 20" aria-hidden="true">
                <path d="m5 7.5 5 5 5-5"/>
            </svg>
        `;

        const panel = document.createElement('div');
        panel.className = 'searchable-select__panel';
        panel.hidden = true;
        panel.innerHTML = `
            <div class="searchable-select__search-wrap">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="11" cy="11" r="7"></circle>
                    <path d="m20 20-4-4"></path>
                </svg>
                <input
                    type="search"
                    class="searchable-select__search"
                    autocomplete="off"
                    spellcheck="false"
                >
            </div>
            <ul class="searchable-select__options" role="listbox"></ul>
            <p class="searchable-select__empty" hidden>No locations found</p>
            <p class="searchable-select__count" aria-live="polite"></p>
        `;

        select.parentNode.insertBefore(wrapper, select);
        wrapper.append(select, trigger, panel);
        select.classList.add('searchable-select__native');

        const value = trigger.querySelector('.searchable-select__value');
        const search = panel.querySelector('.searchable-select__search');
        const options = panel.querySelector('.searchable-select__options');
        const empty = panel.querySelector('.searchable-select__empty');
        const count = panel.querySelector('.searchable-select__count');

        search.placeholder = select.dataset.searchPlaceholder || 'Search location';
        search.setAttribute('aria-label', search.placeholder);

        const widget = {
            wrapper,
            trigger,
            panel,
            value,
            search,
            options,
            empty,
            count,
            activeIndex: -1,
            visibleOptions: [],
            renderOptions: () => {},
        };

        widget.renderOptions = () => {
            const query = normalizeSearchText(search.value);
            const availableOptions = [...select.options].filter(
                (option) => option.value && !option.disabled
            );
            const matches = availableOptions.filter((option) =>
                normalizeSearchText(option.textContent).includes(query)
            );

            options.replaceChildren();
            widget.visibleOptions = matches;
            widget.activeIndex = -1;

            matches.forEach((option) => {
                const item = document.createElement('li');
                const button = document.createElement('button');
                const isSelected = option.value === select.value;

                button.type = 'button';
                button.className = 'searchable-select__option';
                button.setAttribute('role', 'option');
                button.setAttribute('aria-selected', String(isSelected));
                button.dataset.value = option.value;
                button.innerHTML = `<span></span><span aria-hidden="true">${isSelected ? '✓' : ''}</span>`;
                button.firstElementChild.textContent = option.textContent;

                button.addEventListener('click', () => {
                    select.value = option.value;
                    select.setCustomValidity('');
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    syncSearchableSelect(select);
                    closeSearchableSelect(select, true);
                });

                item.append(button);
                options.append(item);
            });

            empty.hidden = matches.length !== 0;
            count.textContent = `${matches.length} ${matches.length === 1 ? 'location' : 'locations'} found`;
        };

        searchableSelects.set(select, widget);

        trigger.addEventListener('click', () => {
            panel.hidden ? openSearchableSelect(select) : closeSearchableSelect(select);
        });

        trigger.addEventListener('keydown', (event) => {
            if (['ArrowDown', 'Enter', ' '].includes(event.key)) {
                event.preventDefault();
                openSearchableSelect(select);
            }
        });

        search.addEventListener('input', widget.renderOptions);

        search.addEventListener('keydown', (event) => {
            const optionButtons = [...options.querySelectorAll('button')];

            if (event.key === 'Escape') {
                event.preventDefault();
                closeSearchableSelect(select, true);
                return;
            }

            if (!['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key)) return;
            event.preventDefault();

            if (event.key === 'Enter') {
                optionButtons[widget.activeIndex]?.click();
                return;
            }

            const direction = event.key === 'ArrowDown' ? 1 : -1;
            widget.activeIndex = Math.max(
                0,
                Math.min(optionButtons.length - 1, widget.activeIndex + direction)
            );

            optionButtons.forEach((button, index) => {
                button.classList.toggle('is-active', index === widget.activeIndex);
            });
            optionButtons[widget.activeIndex]?.scrollIntoView({ block: 'nearest' });
        });

        syncSearchableSelect(select);
    }

    registration
        .querySelectorAll('[data-searchable-address]')
        .forEach(createSearchableSelect);

    document.addEventListener('pointerdown', (event) => {
        searchableSelects.forEach((widget, select) => {
            if (!widget.wrapper.contains(event.target)) closeSearchableSelect(select);
        });
    });

    const selectedRole = () =>
        form.querySelector('input[name="role"]:checked')?.value || 'seller';

    const activeSteps = () =>
        selectedRole() === 'buyer' ? [1, 2, 4] : [1, 2, 3, 4];

    const roleRequired = {
        seller: [
            'business_name',
            'business_category',
            'business_permit',
        ],
        courier: [
            'vehicle_type',
            'vehicle_model',
            'plate_number',
            'drivers_license_number',
            'or_cr',
        ],
    };

    function updateRoleUI() {
        const role = selectedRole();
        const stepLabel = registration.querySelector('[data-role-step-label]');
        const idLabel = registration.querySelector('[data-valid-id-label]');
        const idHelp = registration.querySelector('[data-valid-id-help]');

        if (stepLabel) {
            stepLabel.textContent =
                role === 'seller'
                    ? 'Business Details'
                    : role === 'courier'
                        ? 'Vehicle Details'
                        : 'Buyer Details';
        }

        if (idLabel) {
            idLabel.textContent =
                role === 'courier'
                    ? 'Valid ID or driver’s license'
                    : 'Valid government ID';
        }

        if (idHelp) {
            idHelp.textContent =
                role === 'courier'
                    ? 'Upload a government ID or a valid driver’s license.'
                    : 'Passport, driver’s license, national ID, or another government-issued ID.';
        }

        registration.querySelectorAll('.role-card').forEach((card) => {
            card.classList.toggle('selected', card.querySelector('input').checked);
        });

        registration.querySelectorAll('[data-role-fields]').forEach((group) => {
            group.classList.toggle('active', group.dataset.roleFields === role);
        });

        registration.querySelectorAll('[data-seller-document]').forEach((element) => {
            const inactive = role !== 'seller';
            element.hidden = inactive;
            element.querySelectorAll('input').forEach((input) => {
                input.disabled = inactive;
            });
        });

        registration.querySelectorAll('[data-courier-document]').forEach((element) => {
            const inactive = role !== 'courier';
            element.hidden = inactive;
            element.querySelectorAll('input').forEach((input) => {
                input.disabled = inactive;
            });
        });

        Object.values(roleRequired)
            .flat()
            .forEach((name) => form.elements[name]?.removeAttribute('required'));

        (roleRequired[role] || []).forEach((name) => {
            form.elements[name]?.setAttribute('required', 'required');
        });
    }

    function buildReview() {
        const summary = registration.querySelector('[data-review-summary]');
        if (!summary) return;

        summary.innerHTML = '';
        const role = selectedRole();
        const valueOf = (name) => form.elements[name]?.value?.trim() || '';
        const fullName = [
            valueOf('first_name'),
            valueOf('middle_initial'),
            valueOf('last_name'),
        ].filter(Boolean).join(' ');
        const streetLine = [valueOf('house_number'), valueOf('street_name')]
            .filter(Boolean)
            .join(' ');

        const groups = [
            {
                title: 'Account',
                editStep: 1,
                fields: [
                    ['Account type', role.charAt(0).toUpperCase() + role.slice(1)],
                    ['Name', fullName],
                    ['Email', valueOf('email')],
                    ['Contact', valueOf('contact_number')],
                ],
            },
            {
                title: 'Address',
                editStep: 2,
                fields: [
                    ['Province', valueOf('province')],
                    ['City / Municipality', valueOf('city')],
                    ['Barangay', valueOf('barangay')],
                    ['Street / Unit', streetLine],
                    ['Postal code', valueOf('postal_code')],
                ],
            },
        ];

        if (role === 'seller') {
            groups.push({
                title: 'Business',
                editStep: 3,
                fields: [
                    ['Business name', valueOf('business_name')],
                    ['Line of business', valueOf('business_category')],
                ],
            });
        }

        if (role === 'courier') {
            groups.push({
                title: 'Vehicle',
                editStep: 3,
                fields: [
                    ['Vehicle type', valueOf('vehicle_type')],
                    ['Vehicle model', valueOf('vehicle_model')],
                    ['Plate number', valueOf('plate_number')],
                    ['License number', valueOf('drivers_license_number')],
                ],
            });
        }

        groups.forEach(({ title, editStep, fields }) => {
            const group = document.createElement('section');
            group.className = 'review-group';

            const header = document.createElement('header');
            header.className = 'review-group__header';
            const heading = document.createElement('h4');
            const edit = document.createElement('button');
            heading.textContent = title;
            edit.type = 'button';
            edit.textContent = 'Edit';
            edit.dataset.editStep = String(editStep);
            edit.setAttribute('aria-label', `Edit ${title.toLowerCase()} details`);
            header.append(heading, edit);

            const list = document.createElement('dl');
            fields.filter(([, value]) => value).forEach(([label, value]) => {
                const row = document.createElement('div');
                const term = document.createElement('dt');
                const description = document.createElement('dd');
                term.textContent = label;
                description.textContent = value;
                row.append(term, description);
                list.appendChild(row);
            });

            group.append(header, list);
            summary.appendChild(group);
        });

        const approvalNotice = registration.querySelector('[data-approval-notice]');

        if (approvalNotice) {
            approvalNotice.textContent =
                `An administrator will review your ${role} application. ` +
                'We’ll email the decision to your registered email address.';
        }
    }

    function showStep(step) {
        const steps = activeSteps();
        currentStep = steps.includes(step)
            ? step
            : step === 3 && selectedRole() === 'buyer'
                ? 4
                : steps[0];

        registration.querySelectorAll('[data-step]').forEach((panel) => {
            panel.classList.toggle(
                'active',
                Number(panel.dataset.step) === currentStep
            );
        });

        registration.querySelectorAll('[data-step-marker]').forEach((marker) => {
            const markerStep = Number(marker.dataset.stepMarker);
            const markerIndex = steps.indexOf(markerStep);
            const currentIndex = steps.indexOf(currentStep);
            marker.hidden = markerIndex === -1;
            marker.classList.toggle('active', markerStep === currentStep);
            marker.classList.toggle(
                'complete',
                markerIndex !== -1 && markerIndex < currentIndex
            );

            const number = marker.querySelector('span');
            if (number && markerIndex !== -1) {
                number.textContent = String(markerIndex + 1);
            }
        });

        const currentIndex = steps.indexOf(currentStep);
        registration.querySelector('[data-mobile-step]').textContent =
            `Step ${currentIndex + 1} of ${steps.length}`;
        registration.querySelector('[data-progress-bar]').style.width =
            `${((currentIndex + 1) / steps.length) * 100}%`;

        back.disabled = currentStep === 1;
        next.hidden = currentStep === 4;
        submit.hidden = currentStep !== 4;

        if (currentStep === 4) buildReview();
        updateRoleUI();
    }

    function validateStep() {
        const panel = registration.querySelector(`[data-step="${currentStep}"]`);
        const controls = [...panel.querySelectorAll('input, select, textarea')]
            .filter((element) => !element.hidden && element.offsetParent !== null);

        for (const input of controls) {
            input.setCustomValidity('');

            if (
                ['first_name', 'last_name', 'middle_initial'].includes(input.name) &&
                /\d/.test(input.value)
            ) {
                input.setCustomValidity('Names cannot contain numbers.');
            }

            if (
                input.name === 'contact_number' &&
                !/^(?:\+639|09)\d{9}$/.test(input.value)
            ) {
                input.setCustomValidity('Use 09XXXXXXXXX or +639XXXXXXXXX.');
            }

            if (
                input.name === 'password_confirmation' &&
                input.value !== form.elements.password.value
            ) {
                input.setCustomValidity('Passwords do not match.');
            }

            if (input.type === 'file' && input.files[0]) {
                const file = input.files[0];
                const allowedTypes = [
                    'image/png',
                    'image/jpeg',
                    'application/pdf',
                ];

                if (!allowedTypes.includes(file.type)) {
                    input.setCustomValidity('Upload a PNG, JPG, JPEG, or PDF file.');
                } else if (file.size > 5 * 1024 * 1024) {
                    input.setCustomValidity('The file must not exceed 5 MB.');
                }
            }

            if (!input.checkValidity()) {
                const searchableWidget = searchableSelects.get(input);
                if (searchableWidget) {
                    searchableWidget.wrapper.classList.add('is-invalid');
                    setAddressMessage(
                        `Please select your ${input.name === 'city' ? 'city or municipality' : input.name}.`,
                        'error'
                    );
                    searchableWidget.trigger.focus();
                    openSearchableSelect(input);
                    return false;
                }

                input.reportValidity();
                input.focus();
                return false;
            }
        }

        return true;
    }

    function setAddressMessage(message, type = 'info') {
        if (!addressMessage) return;
        addressMessage.textContent = message;
        addressMessage.dataset.status = type;
    }

    function setSelectState(select, message, disabled = true) {
        if (!select) return;
        select.disabled = disabled;
        select.replaceChildren(new Option(message, ''));
        syncSearchableSelect(select);
    }

    function populateSelect(select, items, placeholder, oldValue = '') {
        select.replaceChildren(new Option(placeholder, ''));

        [...items]
            .sort((a, b) => a.name.localeCompare(b.name))
            .forEach((item) => {
                const option = new Option(item.name, item.name);
                option.dataset.code = item.code;
                select.add(option);
            });

        select.disabled = false;

        if (oldValue) {
            const match = [...select.options].find(
                (option) => option.value.toLowerCase() === oldValue.toLowerCase()
            );
            if (match) select.value = match.value;
        }

        syncSearchableSelect(select);
    }

    async function fetchAddressData(endpoint) {
        addressRequest?.abort();
        addressRequest = new AbortController();

        const timeout = window.setTimeout(() => addressRequest.abort(), 12000);

        try {
            const response = await fetch(`${PSGC_API}${endpoint}`, {
                headers: { Accept: 'application/json' },
                signal: addressRequest.signal,
            });

            if (!response.ok) {
                throw new Error(`Address API returned HTTP ${response.status}`);
            }

            const payload = await response.json();

            const result = Array.isArray(payload)
                  ? payload
                  : payload.data;

            if (!Array.isArray(result)) {
                throw new Error('Unexpected address API response.');
          }

return result;
        } finally {
            window.clearTimeout(timeout);
        }
    }

    async function loadProvinces() {
        if (!provinceSelect || !citySelect || !barangaySelect) return;

        setSelectState(provinceSelect, 'Loading provinces...');
        setSelectState(citySelect, 'Select province first');
        setSelectState(barangaySelect, 'Select city first');
        addressRetry.hidden = true;
        setAddressMessage('Loading Philippine address data...');

        try {
            const provinces = await fetchAddressData('/provinces');
            populateSelect(
                provinceSelect,
                provinces,
                'Select province',
                provinceSelect.dataset.oldValue
            );
            setAddressMessage('Address service is ready.', 'success');

            if (provinceSelect.value) await loadCities();
        } catch (error) {
            if (error.name === 'AbortError') return;
            console.error('Unable to load provinces:', error);
            setSelectState(provinceSelect, 'Address service unavailable');
            setAddressMessage(
                'Address service is unavailable. Retry or enter your address manually.',
                'error'
            );
            addressRetry.hidden = false;
        }
    }

    async function loadCities() {
        const provinceCode = provinceSelect.selectedOptions[0]?.dataset.code;
        setSelectState(citySelect, 'Loading cities...');
        setSelectState(barangaySelect, 'Select city first');

        if (!provinceCode) {
            setSelectState(citySelect, 'Select province first');
            return;
        }

        try {
            const cities = await fetchAddressData(
                `/provinces/${encodeURIComponent(provinceCode)}/cities`
            );
            populateSelect(
                citySelect,
                cities,
                'Select city or municipality',
                citySelect.dataset.oldValue
            );
            setAddressMessage('Cities and municipalities loaded.', 'success');

            if (citySelect.value) await loadBarangays();
        } catch (error) {
            if (error.name === 'AbortError') return;
            console.error('Unable to load cities:', error);
            setSelectState(citySelect, 'Unable to load cities');
            setAddressMessage('Unable to load cities. Please retry.', 'error');
            addressRetry.hidden = false;
        }
    }

    async function loadBarangays() {
        const cityCode = citySelect.selectedOptions[0]?.dataset.code;
        setSelectState(barangaySelect, 'Loading barangays...');

        if (!cityCode) {
            setSelectState(barangaySelect, 'Select city first');
            return;
        }

        try {
            const barangays = await fetchAddressData(
                `/cities/${encodeURIComponent(cityCode)}/barangays`
            );
            populateSelect(
                barangaySelect,
                barangays,
                'Select barangay',
                barangaySelect.dataset.oldValue
            );
            setAddressMessage('Barangays loaded.', 'success');
        } catch (error) {
            if (error.name === 'AbortError') return;
            console.error('Unable to load barangays:', error);
            setSelectState(barangaySelect, 'Unable to load barangays');
            setAddressMessage('Unable to load barangays. Please retry.', 'error');
            addressRetry.hidden = false;
        }
    }

    function enableManualAddress() {
        [
            [provinceSelect, 'Enter province'],
            [citySelect, 'Enter city or municipality'],
            [barangaySelect, 'Enter barangay'],
        ].forEach(([select, placeholder]) => {
            if (!select?.isConnected) return;

            const input = document.createElement('input');
            input.type = 'text';
            input.name = select.name;
            input.required = true;
            input.placeholder = placeholder;
            input.value = select.value || select.dataset.oldValue || '';
            input.dataset.manualAddress = '';

            const widget = searchableSelects.get(select);
            if (widget) {
                widget.wrapper.replaceWith(input);
                searchableSelects.delete(select);
            } else {
                select.replaceWith(input);
            }
        });

        addressRequest?.abort();
        addressRetry.hidden = true;
        addressManual.hidden = true;
        setAddressMessage(
            'Manual address entry enabled. Check the spelling before continuing.'
        );
    }

    form.querySelectorAll('input[name="role"]').forEach((input) => {
        input.addEventListener('change', () => showStep(currentStep));
    });

    next.addEventListener('click', () => {
        if (!validateStep()) return;
        const steps = activeSteps();
        const nextStep = steps[steps.indexOf(currentStep) + 1];
        if (nextStep) showStep(nextStep);
    });
    back.addEventListener('click', () => {
        const steps = activeSteps();
        const previousStep = steps[steps.indexOf(currentStep) - 1];
        if (previousStep) showStep(previousStep);
    });

    registration.addEventListener('click', (event) => {
        const edit = event.target.closest('[data-edit-step]');
        if (!edit) return;
        showStep(Number(edit.dataset.editStep));
    });

    provinceSelect?.addEventListener('change', async () => {
        citySelect.dataset.oldValue = '';
        barangaySelect.dataset.oldValue = '';
        await loadCities();
    });

    citySelect?.addEventListener('change', async () => {
        barangaySelect.dataset.oldValue = '';
        await loadBarangays();
    });

    addressRetry?.addEventListener('click', loadProvinces);
    addressManual?.addEventListener('click', enableManualAddress);

    const birthday = form.elements.birthday;
    const age = form.elements.age;

    function calculateAge() {
        if (!birthday.value) {
            age.value = '--';
            return;
        }

        const born = new Date(`${birthday.value}T00:00:00`);
        const today = new Date();
        let years = today.getFullYear() - born.getFullYear();

        if (today < new Date(today.getFullYear(), born.getMonth(), born.getDate())) {
            years--;
        }

        age.value = Math.max(0, years);
    }

    birthday.addEventListener('change', calculateAge);
    calculateAge();

    form.elements.contact_number.addEventListener('input', (event) => {
        event.target.value = event.target.value
            .replace(/(?!^\+)[^\d]/g, '')
            .slice(0, 13);
    });

    form.querySelectorAll('[data-uppercase]').forEach((input) => {
        input.addEventListener('input', () => {
            input.value = input.value.toUpperCase();
        });
    });

    const password = form.elements.password;
    password.addEventListener('input', () => {
        const score = [
            password.value.length >= 8,
            /[A-Z]/.test(password.value),
            /[a-z]/.test(password.value),
            /\d/.test(password.value),
        ].filter(Boolean).length;

        form.querySelector('.password-meter')
            .style.setProperty('--strength', `${score * 25}%`);
    });

    const allowedDocumentTypes = [
        'image/png',
        'image/jpeg',
        'application/pdf',
    ];
    const maxDocumentSize = 5 * 1024 * 1024;

    const formatFileSize = (bytes) =>
        bytes < 1048576
            ? `${Math.max(1, Math.round(bytes / 1024))} KB`
            : `${(bytes / 1048576).toFixed(1)} MB`;

    function renderFileState(input, file = null, error = '') {
        const card = input.closest('[data-upload-card]');
        const status = card?.querySelector('[data-file-status]');
        const name = card?.querySelector('[data-file-name]');
        const meta = card?.querySelector('[data-file-meta]');

        card?.classList.toggle('has-file', Boolean(file) && !error);
        card?.classList.toggle('has-error', Boolean(error));
        if (!status) return;

        status.hidden = !file && !error;
        if (name) name.textContent = error || file?.name || '';
        if (meta) {
            meta.textContent = file && !error
                ? `${file.type === 'application/pdf' ? 'PDF' : 'Image'} · ${formatFileSize(file.size)}`
                : error
                    ? 'Choose another file to continue.'
                    : '';
        }
    }

    function validateDocument(input, file) {
        let error = '';
        if (!allowedDocumentTypes.includes(file.type)) {
            error = 'Upload a PNG, JPG, JPEG, or PDF file.';
        } else if (file.size > maxDocumentSize) {
            error = 'The file must not exceed 5 MB.';
        }

        input.setCustomValidity(error);
        renderFileState(input, file, error);
        if (error) input.reportValidity();
        return !error;
    }

    function assignDroppedFile(input, file) {
        const transfer = new DataTransfer();
        transfer.items.add(file);
        input.files = transfer.files;
        validateDocument(input, file);
    }

    form.querySelectorAll('[data-file-preview]').forEach((input) => {
        const dropzone = input.closest('[data-drop-zone]');
        const card = input.closest('[data-upload-card]');

        input.addEventListener('change', () => {
            input.setCustomValidity('');
            const file = input.files[0];
            file ? validateDocument(input, file) : renderFileState(input);
        });

        ['dragenter', 'dragover'].forEach((type) => {
            dropzone?.addEventListener(type, (event) => {
                event.preventDefault();
                if (!input.disabled) dropzone.classList.add('is-dragging');
            });
        });

        ['dragleave', 'drop'].forEach((type) => {
            dropzone?.addEventListener(type, (event) => {
                event.preventDefault();
                dropzone.classList.remove('is-dragging');
            });
        });

        dropzone?.addEventListener('drop', (event) => {
            if (input.disabled) return;
            const file = event.dataTransfer?.files?.[0];
            if (file) assignDroppedFile(input, file);
        });

        card?.querySelector('[data-file-action="preview"]')?.addEventListener('click', () => {
            const file = input.files[0];
            if (!file || !validateDocument(input, file)) return;
            const url = URL.createObjectURL(file);
            window.open(url, '_blank', 'noopener,noreferrer');
            window.setTimeout(() => URL.revokeObjectURL(url), 60000);
        });

        card?.querySelector('[data-file-action="replace"]')?.addEventListener('click', () => {
            input.click();
        });

        card?.querySelector('[data-file-action="remove"]')?.addEventListener('click', () => {
            input.value = '';
            input.setCustomValidity('');
            renderFileState(input);
        });
    });

    submit.addEventListener('click', () => {
        if (!validateStep()) return;
        const message = form.querySelector('[data-register-message]');
        if (message) message.hidden = false;
    });

    showStep(1);
    loadProvinces();
});

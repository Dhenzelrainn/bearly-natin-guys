document.addEventListener('DOMContentLoaded', () => {
    /* =========================================================
       PASSWORD VISIBILITY
       ========================================================= */
    document.querySelectorAll('[data-toggle-password]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.getElementById(
                button.dataset.togglePassword
            );

            if (!input) return;

            const isPassword = input.type === 'password';

            input.type = isPassword
                ? 'text'
                : 'password';

            button.setAttribute(
                'aria-label',
                isPassword
                    ? 'Hide password'
                    : 'Show password'
            );
        });
    });


    /* =========================================================
       LOGIN PREVIEW
       ========================================================= */
    const demoLogin =
        document.querySelector('[data-demo-login]');

    demoLogin?.addEventListener(
        'submit',
        (event) => {
            event.preventDefault();

            if (!demoLogin.checkValidity()) {
                demoLogin.reportValidity();
                return;
            }

            const message =
                demoLogin.querySelector(
                    '[data-login-message]'
                );

            if (message) {
                message.hidden = false;
            }
        }
    );


    /* =========================================================
       REGISTRATION
       ========================================================= */
    const registration =
        document.querySelector('[data-registration]');

    if (!registration) return;

    const form =
        registration.querySelector('form');

    if (!form) return;

    const next =
        form.querySelector('[data-next]');

    const back =
        form.querySelector('[data-back]');

    const submit =
        form.querySelector('[data-submit]');

    const PSGC_API = '/api/psgc';

    const provinceSelect =
        registration.querySelector(
            '[data-province-select]'
        );

    const citySelect =
        registration.querySelector(
            '[data-city-select]'
        );

    const barangaySelect =
        registration.querySelector(
            '[data-barangay-select]'
        );

    const addressMessage =
        registration.querySelector(
            '[data-address-message]'
        );

    const addressRetry =
        registration.querySelector(
            '[data-address-retry]'
        );

    const addressManual =
        registration.querySelector(
            '[data-address-manual]'
        );

    const steps = [1, 2, 3];

    let currentStep = 1;
    let addressRequest = null;

    const searchableSelects = new Map();


    /* =========================================================
       GENERAL NORMALIZATION
       ========================================================= */
    function normalizeSearchText(value) {
        return String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLocaleLowerCase('en-PH')
            .trim();
    }


    /* =========================================================
       SEARCHABLE ADDRESS SELECT
       ========================================================= */
    function closeSearchableSelect(
        select,
        returnFocus = false
    ) {
        const widget =
            searchableSelects.get(select);

        if (
            !widget ||
            widget.panel.hidden
        ) {
            return;
        }

        widget.panel.hidden = true;

        widget.trigger.setAttribute(
            'aria-expanded',
            'false'
        );

        widget.wrapper.classList.remove(
            'is-open'
        );

        widget.search.value = '';

        widget.renderOptions();

        if (returnFocus) {
            widget.trigger.focus();
        }
    }


    function closeOtherSearchableSelects(
        currentSelect
    ) {
        searchableSelects.forEach(
            (widget, select) => {
                if (select !== currentSelect) {
                    closeSearchableSelect(
                        select
                    );
                }
            }
        );
    }


    function openSearchableSelect(select) {
        const widget =
            searchableSelects.get(select);

        if (
            !widget ||
            select.disabled
        ) {
            return;
        }

        closeOtherSearchableSelects(select);

        widget.panel.hidden = false;

        widget.trigger.setAttribute(
            'aria-expanded',
            'true'
        );

        widget.wrapper.classList.add(
            'is-open'
        );

        widget.search.value = '';

        widget.renderOptions();

        window.requestAnimationFrame(
            () => {
                widget.search.focus();
            }
        );
    }


    function syncSearchableSelect(select) {
        const widget =
            searchableSelects.get(select);

        if (!widget) return;

        const selectedOption =
            select.selectedOptions[0];

        widget.value.textContent =
            selectedOption?.textContent ||
            'Select an option';

        widget.value.classList.toggle(
            'is-placeholder',
            !select.value
        );

        widget.trigger.disabled =
            select.disabled;

        widget.trigger.setAttribute(
            'aria-disabled',
            String(select.disabled)
        );

        widget.wrapper.classList.toggle(
            'is-disabled',
            select.disabled
        );

        widget.wrapper.classList.remove(
            'is-invalid'
        );

        if (select.disabled) {
            closeSearchableSelect(select);
        }

        widget.renderOptions();
    }


    function createSearchableSelect(select) {
        if (
            !select ||
            searchableSelects.has(select)
        ) {
            return;
        }

        const wrapper =
            document.createElement('div');

        wrapper.className =
            'searchable-select';

        const trigger =
            document.createElement('button');

        trigger.type = 'button';

        trigger.className =
            'searchable-select__trigger';

        trigger.setAttribute(
            'aria-haspopup',
            'listbox'
        );

        trigger.setAttribute(
            'aria-expanded',
            'false'
        );

        trigger.innerHTML = `
            <span class="searchable-select__value"></span>

            <svg viewBox="0 0 20 20" aria-hidden="true">
                <path d="m5 7.5 5 5 5-5"/>
            </svg>
        `;

        const panel =
            document.createElement('div');

        panel.className =
            'searchable-select__panel';

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

            <ul
                class="searchable-select__options"
                role="listbox"
            ></ul>

            <p
                class="searchable-select__empty"
                hidden
            >
                No locations found
            </p>

            <p
                class="searchable-select__count"
                aria-live="polite"
            ></p>
        `;

        select.parentNode.insertBefore(
            wrapper,
            select
        );

        wrapper.append(
            select,
            trigger,
            panel
        );

        select.classList.add(
            'searchable-select__native'
        );

        const value =
            trigger.querySelector(
                '.searchable-select__value'
            );

        const search =
            panel.querySelector(
                '.searchable-select__search'
            );

        const options =
            panel.querySelector(
                '.searchable-select__options'
            );

        const empty =
            panel.querySelector(
                '.searchable-select__empty'
            );

        const count =
            panel.querySelector(
                '.searchable-select__count'
            );

        search.placeholder =
            select.dataset.searchPlaceholder ||
            'Search location';

        search.setAttribute(
            'aria-label',
            search.placeholder
        );

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
            const query =
                normalizeSearchText(
                    search.value
                );

            const availableOptions =
                [...select.options].filter(
                    (option) =>
                        option.value &&
                        !option.disabled
                );

            const matches =
                availableOptions.filter(
                    (option) =>
                        normalizeSearchText(
                            option.textContent
                        ).includes(query)
                );

            options.replaceChildren();

            widget.visibleOptions =
                matches;

            widget.activeIndex = -1;

            matches.forEach(
                (option) => {
                    const item =
                        document.createElement(
                            'li'
                        );

                    const button =
                        document.createElement(
                            'button'
                        );

                    const isSelected =
                        option.value ===
                        select.value;

                    button.type =
                        'button';

                    button.className =
                        'searchable-select__option';

                    button.setAttribute(
                        'role',
                        'option'
                    );

                    button.setAttribute(
                        'aria-selected',
                        String(isSelected)
                    );

                    button.dataset.value =
                        option.value;

                    button.innerHTML = `
                        <span></span>

                        <span aria-hidden="true">
                            ${
                                isSelected
                                    ? '✓'
                                    : ''
                            }
                        </span>
                    `;

                    button.firstElementChild
                        .textContent =
                        option.textContent;

                    button.addEventListener(
                        'click',
                        () => {
                            select.value =
                                option.value;

                            select.setCustomValidity(
                                ''
                            );

                            /*
                             * IMPORTANT:
                             * Native change event.
                             *
                             * PSGC listener uses this.
                             * postal-code-registration.js
                             * also listens for this event.
                             */
                            select.dispatchEvent(
                                new Event(
                                    'change',
                                    {
                                        bubbles:
                                            true,
                                    }
                                )
                            );

                            syncSearchableSelect(
                                select
                            );

                            closeSearchableSelect(
                                select,
                                true
                            );
                        }
                    );

                    item.append(button);

                    options.append(item);
                }
            );

            empty.hidden =
                matches.length !== 0;

            count.textContent =
                `${matches.length} ${
                    matches.length === 1
                        ? 'location'
                        : 'locations'
                } found`;
        };


        searchableSelects.set(
            select,
            widget
        );


        trigger.addEventListener(
            'click',
            () => {
                if (panel.hidden) {
                    openSearchableSelect(
                        select
                    );
                } else {
                    closeSearchableSelect(
                        select
                    );
                }
            }
        );


        trigger.addEventListener(
            'keydown',
            (event) => {
                if (
                    [
                        'ArrowDown',
                        'Enter',
                        ' ',
                    ].includes(
                        event.key
                    )
                ) {
                    event.preventDefault();

                    openSearchableSelect(
                        select
                    );
                }
            }
        );


        search.addEventListener(
            'input',
            widget.renderOptions
        );


        search.addEventListener(
            'keydown',
            (event) => {
                const optionButtons =
                    [
                        ...options
                            .querySelectorAll(
                                'button'
                            ),
                    ];

                if (
                    event.key ===
                    'Escape'
                ) {
                    event.preventDefault();

                    closeSearchableSelect(
                        select,
                        true
                    );

                    return;
                }

                if (
                    ![
                        'ArrowDown',
                        'ArrowUp',
                        'Enter',
                    ].includes(
                        event.key
                    )
                ) {
                    return;
                }

                event.preventDefault();

                if (
                    event.key ===
                    'Enter'
                ) {
                    optionButtons[
                        widget.activeIndex
                    ]?.click();

                    return;
                }

                const direction =
                    event.key ===
                    'ArrowDown'
                        ? 1
                        : -1;

                widget.activeIndex =
                    Math.max(
                        0,
                        Math.min(
                            optionButtons.length -
                                1,
                            widget.activeIndex +
                                direction
                        )
                    );

                optionButtons.forEach(
                    (
                        button,
                        index
                    ) => {
                        button.classList.toggle(
                            'is-active',
                            index ===
                                widget.activeIndex
                        );
                    }
                );

                optionButtons[
                    widget.activeIndex
                ]?.scrollIntoView({
                    block: 'nearest',
                });
            }
        );


        syncSearchableSelect(select);
    }


    registration
        .querySelectorAll(
            '[data-searchable-address]'
        )
        .forEach(
            createSearchableSelect
        );


    document.addEventListener(
        'pointerdown',
        (event) => {
            searchableSelects.forEach(
                (widget, select) => {
                    if (
                        !widget.wrapper.contains(
                            event.target
                        )
                    ) {
                        closeSearchableSelect(
                            select
                        );
                    }
                }
            );
        }
    );


    /* =========================================================
       ADDRESS SERVICE
       PSGC ONLY
       ========================================================= */
    function setAddressMessage(
        message,
        type = 'info'
    ) {
        if (!addressMessage) return;

        addressMessage.textContent =
            message;

        addressMessage.dataset.status =
            type;
    }


    function setSelectState(
        select,
        message,
        disabled = true
    ) {
        if (!select) return;

        select.disabled =
            disabled;

        select.replaceChildren(
            new Option(
                message,
                ''
            )
        );

        syncSearchableSelect(
            select
        );
    }


    function populateSelect(
        select,
        items,
        placeholder,
        oldValue = ''
    ) {
        if (!select) return;

        select.replaceChildren(
            new Option(
                placeholder,
                ''
            )
        );

        [...items]
            .sort(
                (a, b) =>
                    a.name.localeCompare(
                        b.name
                    )
            )
            .forEach(
                (item) => {
                    const option =
                        new Option(
                            item.name,
                            item.name
                        );

                    option.dataset.code =
                        item.code;

                    select.add(option);
                }
            );

        select.disabled = false;

        if (oldValue) {
            const match =
                [...select.options].find(
                    (option) =>
                        option.value
                            .toLowerCase() ===
                        oldValue
                            .toLowerCase()
                );

            if (match) {
                select.value =
                    match.value;
            }
        }

        syncSearchableSelect(
            select
        );
    }


    async function fetchAddressData(
        endpoint
    ) {
        addressRequest?.abort();

        addressRequest =
            new AbortController();

        const timeout =
            window.setTimeout(
                () =>
                    addressRequest.abort(),
                12000
            );

        try {
            const response =
                await fetch(
                    `${PSGC_API}${endpoint}`,
                    {
                        headers: {
                            Accept:
                                'application/json',
                        },
                        signal:
                            addressRequest.signal,
                    }
                );

            if (!response.ok) {
                throw new Error(
                    `Address API returned HTTP ${response.status}`
                );
            }

            const payload =
                await response.json();

            const result =
                Array.isArray(payload)
                    ? payload
                    : payload.data;

            if (
                !Array.isArray(result)
            ) {
                throw new Error(
                    'Unexpected address API response.'
                );
            }

            return result;
        } finally {
            window.clearTimeout(
                timeout
            );
        }
    }


    async function loadProvinces() {
        if (
            !provinceSelect ||
            !citySelect ||
            !barangaySelect
        ) {
            return;
        }

        setSelectState(
            provinceSelect,
            'Loading provinces...'
        );

        setSelectState(
            citySelect,
            'Select province first'
        );

        setSelectState(
            barangaySelect,
            'Select city first'
        );

        if (addressRetry) {
            addressRetry.hidden = true;
        }

        setAddressMessage(
            'Loading Philippine address data...'
        );

        try {
            const provinces =
                await fetchAddressData(
                    '/provinces'
                );

            populateSelect(
                provinceSelect,
                provinces,
                'Select province',
                provinceSelect.dataset
                    .oldValue
            );

            setAddressMessage(
                'Address service is ready.',
                'success'
            );

            if (
                provinceSelect.value
            ) {
                await loadCities();
            }
        } catch (error) {
            if (
                error.name ===
                'AbortError'
            ) {
                return;
            }

            console.error(
                'Unable to load provinces:',
                error
            );

            setSelectState(
                provinceSelect,
                'Address service unavailable'
            );

            setAddressMessage(
                'Address service is unavailable. Retry or enter your address manually.',
                'error'
            );

            if (addressRetry) {
                addressRetry.hidden = false;
            }
        }
    }


    async function loadCities() {
        const provinceCode =
            provinceSelect
                ?.selectedOptions?.[0]
                ?.dataset
                ?.code;

        setSelectState(
            citySelect,
            'Loading cities...'
        );

        setSelectState(
            barangaySelect,
            'Select city first'
        );

        if (!provinceCode) {
            setSelectState(
                citySelect,
                'Select province first'
            );

            return;
        }

        try {
            const cities =
                await fetchAddressData(
                    `/provinces/${encodeURIComponent(
                        provinceCode
                    )}/cities`
                );

            populateSelect(
                citySelect,
                cities,
                'Select municipality or city',
                citySelect.dataset
                    .oldValue
            );

            setAddressMessage(
                'Cities and municipalities loaded.',
                'success'
            );

            if (citySelect.value) {
                /*
                 * Important for restored
                 * old form values.
                 */
                citySelect.dispatchEvent(
                    new Event(
                        'change',
                        {
                            bubbles: true,
                        }
                    )
                );
            }
        } catch (error) {
            if (
                error.name ===
                'AbortError'
            ) {
                return;
            }

            console.error(
                'Unable to load cities:',
                error
            );

            setSelectState(
                citySelect,
                'Unable to load cities'
            );

            setAddressMessage(
                'Unable to load cities. Please retry.',
                'error'
            );

            if (addressRetry) {
                addressRetry.hidden = false;
            }
        }
    }


    async function loadBarangays() {
        const cityCode =
            citySelect
                ?.selectedOptions?.[0]
                ?.dataset
                ?.code;

        setSelectState(
            barangaySelect,
            'Loading barangays...'
        );

        if (!cityCode) {
            setSelectState(
                barangaySelect,
                'Select city first'
            );

            return;
        }

        try {
            const barangays =
                await fetchAddressData(
                    `/cities/${encodeURIComponent(
                        cityCode
                    )}/barangays`
                );

            populateSelect(
                barangaySelect,
                barangays,
                'Select barangay',
                barangaySelect.dataset
                    .oldValue
            );

            setAddressMessage(
                'Barangays loaded.',
                'success'
            );
        } catch (error) {
            if (
                error.name ===
                'AbortError'
            ) {
                return;
            }

            console.error(
                'Unable to load barangays:',
                error
            );

            setSelectState(
                barangaySelect,
                'Unable to load barangays'
            );

            setAddressMessage(
                'Unable to load barangays. Please retry.',
                'error'
            );

            if (addressRetry) {
                addressRetry.hidden = false;
            }
        }
    }


    /* =========================================================
       MANUAL ADDRESS MODE
       ========================================================= */
    function enableManualAddress() {
        const addressFields = [
            [
                provinceSelect,
                'Enter province',
            ],
            [
                citySelect,
                'Enter city or municipality',
            ],
            [
                barangaySelect,
                'Enter barangay',
            ],
        ];

        addressFields.forEach(
            (
                [
                    select,
                    placeholder,
                ]
            ) => {
                if (
                    !select ||
                    !select.isConnected
                ) {
                    return;
                }

                const input =
                    document.createElement(
                        'input'
                    );

                input.type = 'text';

                input.name =
                    select.name;

                input.required = true;

                input.placeholder =
                    placeholder;

                input.value =
                    select.value ||
                    select.dataset
                        .oldValue ||
                    '';

                input.dataset
                    .manualAddress =
                    '';

                const widget =
                    searchableSelects.get(
                        select
                    );

                if (widget) {
                    widget.wrapper
                        .replaceWith(
                            input
                        );

                    searchableSelects.delete(
                        select
                    );
                } else {
                    select.replaceWith(
                        input
                    );
                }
            }
        );

        addressRequest?.abort();

        if (addressRetry) {
            addressRetry.hidden = true;
        }

        if (addressManual) {
            addressManual.hidden = true;
        }

        const postal =
            form.elements.postal_code;

        if (postal) {
            postal.readOnly = false;

            postal.placeholder =
                'Enter postal code';
        }

        setAddressMessage(
            'Manual address entry enabled. Check the spelling before continuing.'
        );
    }


    /* =========================================================
       AGE
       ========================================================= */
    const birthday =
        form.elements.birthday;

    const age =
        form.elements.age;


    function calculateAge() {
        if (
            !birthday ||
            !age
        ) {
            return;
        }

        if (!birthday.value) {
            age.value = '--';
            return;
        }

        const born =
            new Date(
                `${birthday.value}T00:00:00`
            );

        const today =
            new Date();

        let years =
            today.getFullYear() -
            born.getFullYear();

        const birthdayThisYear =
            new Date(
                today.getFullYear(),
                born.getMonth(),
                born.getDate()
            );

        if (
            today <
            birthdayThisYear
        ) {
            years--;
        }

        age.value =
            Math.max(
                0,
                years
            );
    }


    birthday?.addEventListener(
        'change',
        calculateAge
    );

    calculateAge();


    /* =========================================================
       CONTACT NUMBER
       ========================================================= */
    const contactNumber =
        form.elements.contact_number;

    contactNumber?.addEventListener(
        'input',
        (event) => {
            let value =
                event.target.value;

            if (
                value.startsWith('+')
            ) {
                value =
                    '+' +
                    value
                        .slice(1)
                        .replace(
                            /\D/g,
                            ''
                        );
            } else {
                value =
                    value.replace(
                        /\D/g,
                        ''
                    );
            }

            event.target.value =
                value.slice(
                    0,
                    13
                );
        }
    );


    /* =========================================================
       PASSWORD STRENGTH
       ========================================================= */
    const password =
        form.elements.password;

    password?.addEventListener(
        'input',
        () => {
            const checks = [
                password.value
                    .length >= 8,
                /[A-Z]/.test(
                    password.value
                ),
                /[a-z]/.test(
                    password.value
                ),
                /\d/.test(
                    password.value
                ),
            ];

            const score =
                checks.filter(
                    Boolean
                ).length;

            const meter =
                form.querySelector(
                    '.password-meter'
                );

            meter?.style.setProperty(
                '--strength',
                `${score * 25}%`
            );
        }
    );


    /* =========================================================
       FILE UPLOAD
       ========================================================= */
    const allowedDocumentTypes = [
        'image/png',
        'image/jpeg',
        'application/pdf',
    ];

    const maxDocumentSize =
        5 * 1024 * 1024;


    function formatFileSize(
        bytes
    ) {
        if (
            bytes <
            1024 * 1024
        ) {
            return `${Math.max(
                1,
                Math.round(
                    bytes / 1024
                )
            )} KB`;
        }

        return `${(
            bytes /
            (1024 * 1024)
        ).toFixed(1)} MB`;
    }


    function renderFileState(
        input,
        file = null,
        error = ''
    ) {
        const card =
            input.closest(
                '[data-upload-card]'
            );

        if (!card) return;

        const status =
            card.querySelector(
                '[data-file-status]'
            );

        const name =
            card.querySelector(
                '[data-file-name]'
            );

        const meta =
            card.querySelector(
                '[data-file-meta]'
            );

        card.classList.toggle(
            'has-file',
            Boolean(file) &&
                !error
        );

        card.classList.toggle(
            'has-error',
            Boolean(error)
        );

        if (!status) return;

        status.hidden =
            !file &&
            !error;

        if (name) {
            name.textContent =
                error ||
                file?.name ||
                '';
        }

        if (meta) {
            if (
                file &&
                !error
            ) {
                meta.textContent =
                    `${
                        file.type ===
                        'application/pdf'
                            ? 'PDF'
                            : 'Image'
                    } · ${formatFileSize(
                        file.size
                    )}`;
            } else if (error) {
                meta.textContent =
                    'Choose another file to continue.';
            } else {
                meta.textContent =
                    '';
            }
        }
    }


    function validateDocument(
        input,
        file
    ) {
        let error = '';

        if (
            !allowedDocumentTypes.includes(
                file.type
            )
        ) {
            error =
                'Upload a PNG, JPG, JPEG, or PDF file.';
        } else if (
            file.size >
            maxDocumentSize
        ) {
            error =
                'The file must not exceed 5 MB.';
        }

        input.setCustomValidity(
            error
        );

        renderFileState(
            input,
            file,
            error
        );

        if (error) {
            input.reportValidity();
        }

        return !error;
    }


    function assignDroppedFile(
        input,
        file
    ) {
        const transfer =
            new DataTransfer();

        transfer.items.add(
            file
        );

        input.files =
            transfer.files;

        validateDocument(
            input,
            file
        );
    }


    form
        .querySelectorAll(
            '[data-file-preview]'
        )
        .forEach(
            (input) => {
                const dropzone =
                    input.closest(
                        '[data-drop-zone]'
                    );

                const card =
                    input.closest(
                        '[data-upload-card]'
                    );


                input.addEventListener(
                    'change',
                    () => {
                        input.setCustomValidity(
                            ''
                        );

                        const file =
                            input.files[0];

                        if (file) {
                            validateDocument(
                                input,
                                file
                            );
                        } else {
                            renderFileState(
                                input
                            );
                        }
                    }
                );


                [
                    'dragenter',
                    'dragover',
                ].forEach(
                    (type) => {
                        dropzone?.addEventListener(
                            type,
                            (
                                event
                            ) => {
                                event.preventDefault();

                                if (
                                    !input.disabled
                                ) {
                                    dropzone.classList.add(
                                        'is-dragging'
                                    );
                                }
                            }
                        );
                    }
                );


                [
                    'dragleave',
                    'drop',
                ].forEach(
                    (type) => {
                        dropzone?.addEventListener(
                            type,
                            (
                                event
                            ) => {
                                event.preventDefault();

                                dropzone.classList.remove(
                                    'is-dragging'
                                );
                            }
                        );
                    }
                );


                dropzone?.addEventListener(
                    'drop',
                    (event) => {
                        if (
                            input.disabled
                        ) {
                            return;
                        }

                        const file =
                            event
                                .dataTransfer
                                ?.files?.[0];

                        if (file) {
                            assignDroppedFile(
                                input,
                                file
                            );
                        }
                    }
                );


                card
                    ?.querySelector(
                        '[data-file-action="preview"]'
                    )
                    ?.addEventListener(
                        'click',
                        () => {
                            const file =
                                input
                                    .files[0];

                            if (
                                !file ||
                                !validateDocument(
                                    input,
                                    file
                                )
                            ) {
                                return;
                            }

                            const url =
                                URL.createObjectURL(
                                    file
                                );

                            window.open(
                                url,
                                '_blank',
                                'noopener,noreferrer'
                            );

                            window.setTimeout(
                                () =>
                                    URL.revokeObjectURL(
                                        url
                                    ),
                                60000
                            );
                        }
                    );


                card
                    ?.querySelector(
                        '[data-file-action="replace"]'
                    )
                    ?.addEventListener(
                        'click',
                        () => {
                            input.click();
                        }
                    );


                card
                    ?.querySelector(
                        '[data-file-action="remove"]'
                    )
                    ?.addEventListener(
                        'click',
                        () => {
                            input.value =
                                '';

                            input.setCustomValidity(
                                ''
                            );

                            renderFileState(
                                input
                            );
                        }
                    );
            }
        );


    /* =========================================================
       VALIDATION
       ========================================================= */
    function validateStep() {
        const panel =
            registration.querySelector(
                `[data-step="${currentStep}"]`
            );

        if (!panel) {
            return false;
        }

        const controls = [
            ...panel.querySelectorAll(
                'input, select, textarea'
            ),
        ].filter(
            (element) =>
                !element.hidden &&
                element.offsetParent !==
                    null
        );


        for (
            const input
            of controls
        ) {
            input.setCustomValidity(
                ''
            );


            if (
                [
                    'first_name',
                    'last_name',
                    'middle_initial',
                ].includes(
                    input.name
                ) &&
                /\d/.test(
                    input.value
                )
            ) {
                input.setCustomValidity(
                    'Names cannot contain numbers.'
                );
            }


            if (
                input.name ===
                    'contact_number' &&
                !/^(?:09|\+639)\d{9}$/.test(
                    input.value
                )
            ) {
                input.setCustomValidity(
                    'Use 09XXXXXXXXX or +639XXXXXXXXX.'
                );
            }


            if (
                input.name ===
                    'password' &&
                !(
                    input.value
                        .length >= 8 &&
                    /[A-Z]/.test(
                        input.value
                    ) &&
                    /[a-z]/.test(
                        input.value
                    ) &&
                    /\d/.test(
                        input.value
                    )
                )
            ) {
                input.setCustomValidity(
                    'Use at least 8 characters with uppercase, lowercase, and a number.'
                );
            }


            if (
                input.name ===
                    'password_confirmation' &&
                input.value !==
                    form.elements
                        .password
                        .value
            ) {
                input.setCustomValidity(
                    'Passwords do not match.'
                );
            }


            if (
                input.name ===
                    'postal_code' &&
                !/^\d{4}$/.test(
                    input.value
                )
            ) {
                input.setCustomValidity(
                    'Select a municipality with an available postal code.'
                );
            }


            if (
                input.type ===
                    'file' &&
                input.files[0]
            ) {
                validateDocument(
                    input,
                    input.files[0]
                );
            }


            if (
                !input.checkValidity()
            ) {
                const searchableWidget =
                    searchableSelects.get(
                        input
                    );

                if (
                    searchableWidget
                ) {
                    searchableWidget
                        .wrapper
                        .classList
                        .add(
                            'is-invalid'
                        );

                    setAddressMessage(
                        `Please select your ${
                            input.name ===
                            'city'
                                ? 'city or municipality'
                                : input.name
                        }.`,
                        'error'
                    );

                    searchableWidget
                        .trigger
                        .focus();

                    openSearchableSelect(
                        input
                    );

                    return false;
                }

                input.reportValidity();
                input.focus();

                return false;
            }
        }

        return true;
    }


    /* =========================================================
       REVIEW
       ========================================================= */
    function getValue(name) {
        const field =
            form.elements[name];

        if (!field) {
            return '';
        }

        return String(
            field.value ||
            ''
        ).trim();
    }


    function formatSex(value) {
        const labels = {
            female:
                'Female',

            male:
                'Male',

            prefer_not_to_say:
                'Prefer not to say',
        };

        return (
            labels[value] ||
            value ||
            ''
        );
    }


    function formatBirthday(
        value
    ) {
        if (!value) return '';

        const date =
            new Date(
                `${value}T00:00:00`
            );

        if (
            Number.isNaN(
                date.getTime()
            )
        ) {
            return value;
        }

        return date.toLocaleDateString(
            'en-PH',
            {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
            }
        );
    }


    function buildReviewGroup(
        title,
        editStep,
        fields
    ) {
        const group =
            document.createElement(
                'section'
            );

        group.className =
            'review-group';


        const header =
            document.createElement(
                'header'
            );

        header.className =
            'review-group__header';


        const heading =
            document.createElement(
                'h4'
            );

        heading.textContent =
            title;


        const edit =
            document.createElement(
                'button'
            );

        edit.type =
            'button';

        edit.textContent =
            'Edit';

        edit.dataset.editStep =
            String(editStep);

        edit.setAttribute(
            'aria-label',
            `Edit ${title.toLowerCase()}`
        );

        header.append(
            heading,
            edit
        );


        const list =
            document.createElement(
                'dl'
            );


        fields
            .filter(
                ([, value]) =>
                    value
            )
            .forEach(
                (
                    [
                        label,
                        value,
                    ]
                ) => {
                    const row =
                        document.createElement(
                            'div'
                        );

                    const term =
                        document.createElement(
                            'dt'
                        );

                    const description =
                        document.createElement(
                            'dd'
                        );

                    term.textContent =
                        label;

                    description.textContent =
                        value;

                    row.append(
                        term,
                        description
                    );

                    list.appendChild(
                        row
                    );
                }
            );


        group.append(
            header,
            list
        );

        return group;
    }


    function buildReview() {
        const summary =
            registration.querySelector(
                '[data-review-summary]'
            );

        if (!summary) return;

        summary.replaceChildren();

        const validId =
            form.elements
                .valid_id
                ?.files?.[0];

        const street = [
            getValue(
                'house_number'
            ),
            getValue(
                'street_name'
            ),
        ]
            .filter(Boolean)
            .join(' ');


        const personalGroup =
            buildReviewGroup(
                'Personal Information',
                1,
                [
                    [
                        'First name',
                        getValue(
                            'first_name'
                        ),
                    ],

                    [
                        'Last name',
                        getValue(
                            'last_name'
                        ),
                    ],

                    [
                        'Middle initial',
                        getValue(
                            'middle_initial'
                        ),
                    ],

                    [
                        'Sex',
                        formatSex(
                            getValue(
                                'sex'
                            )
                        ),
                    ],

                    [
                        'Email',
                        getValue(
                            'email'
                        ),
                    ],

                    [
                        'Contact',
                        getValue(
                            'contact_number'
                        ),
                    ],

                    [
                        'Birthday',
                        formatBirthday(
                            getValue(
                                'birthday'
                            )
                        ),
                    ],

                    [
                        'Age',
                        getValue(
                            'age'
                        ),
                    ],
                ]
            );


        const addressGroup =
            buildReviewGroup(
                'Address',
                2,
                [
                    [
                        'Province',
                        getValue(
                            'province'
                        ),
                    ],

                    [
                        'Municipality / City',
                        getValue(
                            'city'
                        ),
                    ],

                    [
                        'Barangay',
                        getValue(
                            'barangay'
                        ),
                    ],

                    [
                        'Street / Unit',
                        street,
                    ],

                    [
                        'Postal code',
                        getValue(
                            'postal_code'
                        ),
                    ],
                ]
            );


        const verificationGroup =
            buildReviewGroup(
                'Verification',
                2,
                [
                    [
                        'Uploaded ID',
                        validId?.name ||
                            'No file selected',
                    ],
                ]
            );


        summary.append(
            personalGroup,
            addressGroup,
            verificationGroup
        );
    }


    /* =========================================================
       STEP NAVIGATION
       ========================================================= */
    function showStep(step) {
        currentStep =
            Math.max(
                1,
                Math.min(
                    3,
                    Number(step)
                )
            );


        registration
            .querySelectorAll(
                '[data-step]'
            )
            .forEach(
                (panel) => {
                    panel.classList.toggle(
                        'active',
                        Number(
                            panel.dataset
                                .step
                        ) ===
                            currentStep
                    );
                }
            );


        registration
            .querySelectorAll(
                '[data-step-marker]'
            )
            .forEach(
                (marker) => {
                    const markerStep =
                        Number(
                            marker.dataset
                                .stepMarker
                        );

                    marker.classList.toggle(
                        'active',
                        markerStep ===
                            currentStep
                    );

                    marker.classList.toggle(
                        'complete',
                        markerStep <
                            currentStep
                    );

                    const number =
                        marker.querySelector(
                            'span'
                        );

                    if (number) {
                        number.textContent =
                            String(
                                markerStep
                            );
                    }
                }
            );


        const mobileStep =
            registration.querySelector(
                '[data-mobile-step]'
            );

        if (mobileStep) {
            mobileStep.textContent =
                `Step ${currentStep} of 3`;
        }


        const progressBar =
            registration.querySelector(
                '[data-progress-bar]'
            );

        if (progressBar) {
            progressBar.style.width =
                `${
                    (
                        currentStep /
                        3
                    ) * 100
                }%`;
        }


        if (back) {
            back.disabled =
                currentStep === 1;
        }

        if (next) {
            next.hidden =
                currentStep === 3;
        }

        if (submit) {
            submit.hidden =
                currentStep !== 3;
        }


        if (
            currentStep === 3
        ) {
            buildReview();
        }


        registration.scrollIntoView({
            behavior: 'smooth',
            block: 'start',
        });
    }


    next?.addEventListener(
        'click',
        () => {
            if (
                !validateStep()
            ) {
                return;
            }

            showStep(
                currentStep + 1
            );
        }
    );


    back?.addEventListener(
        'click',
        () => {
            showStep(
                currentStep - 1
            );
        }
    );


    registration.addEventListener(
        'click',
        (event) => {
            const edit =
                event.target.closest(
                    '[data-edit-step]'
                );

            if (!edit) return;

            showStep(
                Number(
                    edit.dataset
                        .editStep
                )
            );
        }
    );


    /* =========================================================
       ADDRESS EVENTS

       IMPORTANT:
       NO POSTAL CODE LOGIC HERE.

       resources/js/postal-code-registration.js
       is now the ONLY file responsible for postal codes.
       ========================================================= */
    provinceSelect?.addEventListener(
        'change',
        async () => {
            if (citySelect) {
                citySelect.dataset
                    .oldValue =
                    '';
            }

            if (barangaySelect) {
                barangaySelect.dataset
                    .oldValue =
                    '';
            }

            await loadCities();
        }
    );


    citySelect?.addEventListener(
        'change',
        async () => {
            if (barangaySelect) {
                barangaySelect.dataset
                    .oldValue =
                    '';
            }

            /*
             * Postal code JS also catches
             * this same city change event.
             */
            await loadBarangays();
        }
    );


    addressRetry?.addEventListener(
        'click',
        loadProvinces
    );


    addressManual?.addEventListener(
        'click',
        enableManualAddress
    );


    /* =========================================================
       FRONT-END-ONLY SUBMISSION PREVIEW
       ========================================================= */
    form.addEventListener(
        'submit',
        (event) => {
            event.preventDefault();

            if (
                currentStep !== 3
            ) {
                return;
            }

            if (
                !validateStep()
            ) {
                return;
            }

            const message =
                form.querySelector(
                    '[data-register-message]'
                );

            if (message) {
                message.hidden =
                    false;
            }
        }
    );


    /* =========================================================
       INITIAL STATE
       ========================================================= */
    showStep(1);

    /*
     * Only PSGC data is initialized here.
     *
     * Postal-code-registration.js imports:
     * resources/data/ph-postal-codes.json
     *
     * So there is no second postal system anymore.
     */
    loadProvinces().catch(
        (error) => {
            console.error(
                'Registration initialization error:',
                error
            );
        }
    );
});
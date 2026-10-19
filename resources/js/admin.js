import './notification-center.js';

    const ready = (callback) => {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
        } else {
            callback();
        }
    };

    /*
    |--------------------------------------------------------------------------
    | Accessible chart tooltips
    |--------------------------------------------------------------------------
    | Server-rendered chart points and bars carry their real period label and
    | amount in data attributes. Hover, keyboard focus, and touch reveal the
    | same tooltip; amounts are never derived from pixel positions.
    */

    const initChartTooltips = () => {
        document.querySelectorAll('[data-chart]').forEach((chart) => {
            const points = [...chart.querySelectorAll('[data-chart-point]')];

            if (!points.length) return;

            const tooltip = document.createElement('div');
            tooltip.className = 'chart-tooltip';
            tooltip.hidden = true;
            tooltip.innerHTML = '<strong></strong><span></span>';
            chart.appendChild(tooltip);

            const valueNode = tooltip.querySelector('strong');
            const labelNode = tooltip.querySelector('span');

            const position = (point) => {
                const anchor = (point.querySelector('[data-chart-bar]') || point).getBoundingClientRect();
                const root = chart.getBoundingClientRect();
                const width = tooltip.offsetWidth;
                const height = tooltip.offsetHeight;
                const center = anchor.left - root.left + anchor.width / 2;
                const left = Math.min(Math.max(center - width / 2, 4), Math.max(root.width - width - 4, 4));
                let top = anchor.top - root.top - height - 8;

                if (top < 4) {
                    top = Math.min(anchor.bottom - root.top + 8, Math.max(root.height - height - 4, 4));
                }

                tooltip.style.left = `${left}px`;
                tooltip.style.top = `${Math.max(4, top)}px`;
            };

            const show = (point) => {
                valueNode.textContent = point.dataset.value || '';
                labelNode.textContent = point.dataset.label || '';
                tooltip.hidden = false;
                position(point);
            };

            const hide = () => {
                tooltip.hidden = true;
            };

            const focusPoint = (index) => {
                const point = points[(index + points.length) % points.length];
                points.forEach((item) => { item.tabIndex = item === point ? 0 : -1; });
                point.focus();
                show(point);
            };

            const pointFrom = (event) => (event.target instanceof Element ? event.target.closest('[data-chart-point]') : null);

            chart.addEventListener('pointerover', (event) => {
                const point = pointFrom(event);

                if (point) show(point);
            });
            chart.addEventListener('pointerout', (event) => {
                const point = pointFrom(event);

                if (!point || event.pointerType === 'touch') return;

                const next = event.relatedTarget;

                if (!(next instanceof Element) || !point.contains(next)) hide();
            });
            chart.addEventListener('focusin', (event) => {
                const point = pointFrom(event);

                if (point) show(point);
            });
            chart.addEventListener('focusout', () => hide());
            chart.addEventListener('pointerdown', (event) => {
                const point = pointFrom(event);

                if (point && event.pointerType === 'touch') show(point);
            });
            chart.addEventListener('keydown', (event) => {
                const point = pointFrom(event);

                if (!point) return;

                const index = points.indexOf(point);

                if (index < 0) return;

                if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                    event.preventDefault();
                    focusPoint(index + 1);
                } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    focusPoint(index - 1);
                } else if (event.key === 'Home') {
                    event.preventDefault();
                    focusPoint(0);
                } else if (event.key === 'End') {
                    event.preventDefault();
                    focusPoint(points.length - 1);
                } else if (event.key === 'Escape') {
                    hide();
                    point.blur();
                }
            });

            // Touch: tapping anywhere outside this chart dismisses the tooltip.
            document.addEventListener('pointerdown', (event) => {
                if (event.pointerType !== 'touch') return;

                const target = event.target instanceof Element ? event.target.closest('[data-chart]') : null;

                if (target !== chart) hide();
            });
        });
    };

    ready(() => {
        const body = document.body;

        const refreshIcons = () => {
            if (window.lucide?.createIcons) window.lucide.createIcons();
        };
        refreshIcons();
        setTimeout(refreshIcons, 150);

        document.querySelectorAll('[data-announcement-media-editor]').forEach((editor) => {
            const input = editor.querySelector('[data-announcement-media-input]');
            const preview = editor.querySelector('[data-announcement-media-preview]');
            const count = editor.querySelector('[data-announcement-media-count]');
            const form = editor.closest('form');
            const existingCount = Number(editor.dataset.existingMediaCount || 0);
            let previewUrls = [];

            if (!input || !preview) return;

            const revokePreviewUrls = () => {
                previewUrls.forEach((url) => URL.revokeObjectURL(url));
                previewUrls = [];
            };

            const updateCountAndValidity = () => {
                const removedCount = form?.querySelectorAll('[name="remove_media_ids[]"]:checked').length || 0;
                const selectedFiles = [...(input.files || [])];
                const totalCount = Math.max(0, existingCount - removedCount) + selectedFiles.length;
                let validityMessage = '';

                if (totalCount > 5) {
                    validityMessage = 'An announcement can contain up to five media files.';
                } else {
                    const oversized = selectedFiles.find((file) => {
                        const image = ['image/jpeg', 'image/png', 'image/webp'].includes(file.type);
                        const video = ['video/mp4', 'video/webm'].includes(file.type);
                        return (image && file.size > 10 * 1024 * 1024)
                            || (video && file.size > 50 * 1024 * 1024)
                            || (!image && !video);
                    });

                    if (oversized) {
                        const image = ['image/jpeg', 'image/png', 'image/webp'].includes(oversized.type);
                        validityMessage = image
                            ? 'Each image must be 10 MB or smaller.'
                            : 'Choose JPG, PNG, WebP, MP4, or WebM files; videos must be 50 MB or smaller.';
                    }
                }

                input.setCustomValidity(validityMessage);
                if (count) count.textContent = `${totalCount} of 5 media files selected`;
            };

            const renderPreview = () => {
                revokePreviewUrls();
                preview.replaceChildren();

                [...(input.files || [])].forEach((file, index) => {
                    const url = URL.createObjectURL(file);
                    previewUrls.push(url);

                    const card = document.createElement('figure');
                    card.className = 'announcement-upload-preview-item';
                    let media;

                    if (file.type.startsWith('video/')) {
                        media = document.createElement('video');
                        media.controls = true;
                        media.muted = true;
                        media.preload = 'metadata';
                        media.src = url;
                        media.setAttribute('aria-label', `Preview of ${file.name}`);
                    } else {
                        media = document.createElement('img');
                        media.src = url;
                        media.alt = `Preview of ${file.name}`;
                    }

                    const caption = document.createElement('figcaption');
                    caption.textContent = `${file.name} · ${(file.size / (1024 * 1024)).toFixed(1)} MB`;

                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'announcement-upload-remove';
                    remove.textContent = 'Remove';
                    remove.setAttribute('aria-label', `Remove ${file.name}`);
                    remove.addEventListener('click', () => {
                        const transfer = new DataTransfer();
                        [...(input.files || [])].forEach((selected, selectedIndex) => {
                            if (selectedIndex !== index) transfer.items.add(selected);
                        });
                        input.files = transfer.files;
                        renderPreview();
                        updateCountAndValidity();
                    });

                    card.append(media, caption, remove);
                    preview.append(card);
                });

                updateCountAndValidity();
            };

            input.addEventListener('change', renderPreview);
            form?.querySelectorAll('[name="remove_media_ids[]"]').forEach((checkbox) => {
                checkbox.addEventListener('change', updateCountAndValidity);
            });
            form?.addEventListener('reset', () => window.setTimeout(renderPreview, 0));
            updateCountAndValidity();
        });

        document.querySelectorAll('[data-transfer-outcome]').forEach((select) => {
            const form = select.closest('[data-withdrawal-transfer-form]');
            const failureFields = form?.querySelector('[data-transfer-failure-fields]');
            const bankReference = form?.querySelector('[name="bank_reference"]');
            const failureReason = form?.querySelector('[name="failure_reason"]');
            const noTransferConfirmation = form?.querySelector('[name="no_successful_transfer_confirmed"]');
            const updateOutcomeFields = () => {
                const failed = select.value === 'failed';
                if (failureFields) failureFields.hidden = !failed;
                if (bankReference) bankReference.required = !failed;
                if (failureReason) failureReason.required = failed;
                if (noTransferConfirmation) noTransferConfirmation.required = failed;
            };
            select.addEventListener('change', updateOutcomeFields);
            updateOutcomeFields();
        });

        document.querySelectorAll('[data-finance-cash-amount]').forEach((input) => {
            const format = (preserveCaret = true, padDecimals = false) => {
                const current = input.value;
                if (!current.trim()) return;

                const caret = input.selectionStart ?? current.length;
                const digitsBeforeCaret = (current.slice(0, caret).match(/\d/g) || []).length;
                const caretAfterDecimal = current.slice(0, caret).includes('.');
                const ungrouped = current.replace(/,/g, '').replace(/[^\d.]/g, '');
                const decimalIndex = ungrouped.indexOf('.');
                const integer = decimalIndex === -1 ? ungrouped : ungrouped.slice(0, decimalIndex);
                const decimals = decimalIndex === -1
                    ? ''
                    : ungrouped.slice(decimalIndex + 1).replace(/\./g, '');
                const groupedInteger = integer.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                const formattedDecimals = padDecimals ? decimals.padEnd(2, '0') : decimals;
                const formatted = decimalIndex === -1 && !padDecimals
                    ? groupedInteger
                    : `${groupedInteger || '0'}.${formattedDecimals}`;

                input.value = formatted;
                if (!preserveCaret || document.activeElement !== input) return;

                let position = 0;
                let digitsFound = 0;
                while (position < formatted.length && digitsFound < digitsBeforeCaret) {
                    if (/\d/.test(formatted[position])) digitsFound += 1;
                    position += 1;
                }
                if (caretAfterDecimal && formatted.includes('.')) {
                    position = Math.max(position, formatted.indexOf('.') + 1);
                }
                input.setSelectionRange(position, position);
            };

            format(false);
            input.addEventListener('input', () => format());
            input.addEventListener('blur', () => format(false, true));
        });

        document.querySelectorAll('[data-finance-payout-details]').forEach((details) => {
            const summary = details.querySelector(':scope > summary');
            const closeButton = details.querySelector('[data-finance-payout-close]');
            const syncDisclosureControls = () => {
                if (summary) summary.hidden = details.open;
                if (closeButton) closeButton.hidden = !details.open;
            };

            details.addEventListener('toggle', () => {
                syncDisclosureControls();

                if (details.open) {
                    details.querySelector('input:not([type="hidden"]), select, textarea')?.focus();
                }
            });

            closeButton?.addEventListener('click', () => {
                details.open = false;
                syncDisclosureControls();
                summary?.focus();
            });

            syncDisclosureControls();
        });

        document.querySelectorAll('[data-registration-decision]').forEach((form) => {
            let submitting = false;

            form.addEventListener('submit', (event) => {
                if (submitting) {
                    event.preventDefault();
                    return;
                }

                submitting = true;
                form.setAttribute('aria-busy', 'true');
                form.querySelectorAll('button[type="submit"]').forEach((button) => {
                    button.disabled = true;
                });
            });
        });

        // Sidebar state
        const DESKTOP_BREAKPOINT = 820;
        const savedSidebar = localStorage.getItem('bearly-admin-sidebar');

        if (savedSidebar === 'collapsed' && window.innerWidth > DESKTOP_BREAKPOINT) {
            body.classList.add('sidebar-collapsed');
        }

        // Remember Admin sidebar scroll position between page navigations
        const adminNav = document.querySelector('.admin-nav');
        const SIDEBAR_SCROLL_KEY = 'bearly-admin-sidebar-scroll';

        if (adminNav) {
            const savedScrollPosition = sessionStorage.getItem(SIDEBAR_SCROLL_KEY);

            if (savedScrollPosition !== null) {
                requestAnimationFrame(() => {
                    adminNav.scrollTop = Number(savedScrollPosition);
                });
            }

            adminNav.addEventListener('scroll', () => {
                sessionStorage.setItem(
                    SIDEBAR_SCROLL_KEY,
                    String(adminNav.scrollTop)
                );
            });
        }

        const syncMobileMenuAria = () => {
            const isOpen = body.classList.contains('mobile-sidebar-open');

            document.querySelectorAll('[data-admin-mobile-menu]').forEach((button) => {
                button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
        };

        document.querySelectorAll('[data-admin-menu]').forEach((button) => {
            button.addEventListener('click', () => {
                if (window.innerWidth <= DESKTOP_BREAKPOINT) {
                    body.classList.add('mobile-sidebar-open');
                    syncMobileMenuAria();
                    return;
                }

                body.classList.toggle('sidebar-collapsed');

                localStorage.setItem(
                    'bearly-admin-sidebar',
                    body.classList.contains('sidebar-collapsed') ? 'collapsed' : 'expanded'
                );
            });
        });

        document.querySelectorAll('[data-admin-mobile-menu]').forEach((button) => {
            button.addEventListener('click', () => {
                body.classList.add('mobile-sidebar-open');
                syncMobileMenuAria();
            });
        });

        document.querySelectorAll('[data-admin-overlay]').forEach((overlay) => {
            overlay.addEventListener('click', () => {
                body.classList.remove('mobile-sidebar-open');
                syncMobileMenuAria();
            });
        });

        syncMobileMenuAria();

        window.addEventListener('resize', () => {
            if (window.innerWidth > DESKTOP_BREAKPOINT) {
                body.classList.remove('mobile-sidebar-open');
                syncMobileMenuAria();
            }
        });

        // Toasts / mock feedback
        const toastStack = document.querySelector('[data-toast-stack]');
        const showToast = (message, title = 'Preview updated') => {
            if (!toastStack) return;
            const toast = document.createElement('div');
            toast.className = 'admin-toast';
            toast.innerHTML = `
                <span><i data-lucide="circle-check"></i></span>
                <div><strong>${title}</strong><small>${message}</small></div>
                <button type="button" aria-label="Dismiss"><i data-lucide="x"></i></button>
            `;
            toastStack.appendChild(toast);
            refreshIcons();
            const remove = () => toast.remove();
            toast.querySelector('button')?.addEventListener('click', remove);
            setTimeout(remove, 3400);
        };

        document.querySelectorAll('[data-mock-action]').forEach((element) => {

            element.addEventListener('click', (event) => {
                if (element.dataset.functionalAction) return;
                if (element.tagName === 'A') event.preventDefault();
                showToast(element.dataset.mockAction);
            });
        });

        document.querySelectorAll('[data-dismiss-flash]').forEach((button) => {
            button.addEventListener('click', () => button.closest('.flash-message')?.remove());
        });

        // Dashboard refresh and chart period controls
        document.querySelector('[data-dashboard-refresh]')?.addEventListener('click', (event) => {
            const button = event.currentTarget;
            button.disabled = true;
            setTimeout(() => {
                button.disabled = false;
                showToast(`Dashboard refreshed at ${new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}.`, 'Dashboard updated');
            }, 350);
        });
        const dashboardPeriodButtons = [...document.querySelectorAll('[data-dashboard-period]')];
        const dashboardPeriodViews = [...document.querySelectorAll('[data-dashboard-period-view]')];
        dashboardPeriodButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const period = button.dataset.dashboardPeriod;
                const periodView = dashboardPeriodViews.find(
                    (view) => view.dataset.dashboardPeriodView === period
                );

                // Never hide every chart when the control and view keys do not
                // match; the server-rendered default stays visible instead.
                if (!periodView) return;

                dashboardPeriodButtons.forEach((item) => {
                    const active = item === button;
                    item.classList.toggle('is-active', active);
                    item.setAttribute('aria-pressed', active ? 'true' : 'false');
                });
                dashboardPeriodViews.forEach((view) => {
                    view.hidden = view !== periodView;
                });
                showToast(`${button.textContent.trim()} net sales view selected.`, 'Chart period updated');
            });
        });

        initChartTooltips();


        // Registration row action menu
        const closeRegistrationMenus = (except = null) => {
            document.querySelectorAll('[data-registration-menu]').forEach((menu) => {
                if (menu === except) return;
                menu.hidden = true;
            });

            document.querySelectorAll('[data-registration-menu-toggle]').forEach((button) => {
                const menu = document.querySelector(`[data-registration-menu="${button.dataset.registrationMenuToggle}"]`);
                if (menu !== except) button.setAttribute('aria-expanded', 'false');
            });
        };

        const positionRegistrationMenu = (button, menu) => {
            const rect = button.getBoundingClientRect();
            const menuWidth = 190;
            const gap = 7;
            const viewportPadding = 12;
            const left = Math.min(
                window.innerWidth - menuWidth - viewportPadding,
                Math.max(viewportPadding, rect.right - menuWidth)
            );

            menu.style.left = `${left}px`;
            menu.style.top = `${rect.bottom + gap}px`;
        };

        document.querySelectorAll('[data-registration-menu-toggle]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.stopPropagation();
                const menu = document.querySelector(`[data-registration-menu="${button.dataset.registrationMenuToggle}"]`);
                if (!menu) return;

                const opening = menu.hidden;
                closeRegistrationMenus(menu);

                if (opening) {
                    positionRegistrationMenu(button, menu);
                    menu.hidden = false;
                    button.setAttribute('aria-expanded', 'true');
                } else {
                    menu.hidden = true;
                    button.setAttribute('aria-expanded', 'false');
                }
            });
        });

        document.querySelectorAll('[data-registration-menu]').forEach((menu) => {
            menu.addEventListener('click', (event) => event.stopPropagation());
        });

        document.addEventListener('click', () => closeRegistrationMenus());
        window.addEventListener('resize', () => closeRegistrationMenus());
        window.addEventListener('scroll', () => closeRegistrationMenus(), true);

        const REGISTRATION_STATUS_STORAGE_KEY = 'bearlyAdminRegistrationStatuses';
        const registrationStatuses = (() => {
            try {
                return JSON.parse(localStorage.getItem(REGISTRATION_STATUS_STORAGE_KEY) || '{}');
            } catch {
                return {};
            }
        })();
        let pendingRegistrationDecision = null;

        const updateRegistrationRow = (applicationId, status) => {
            const row = [...document.querySelectorAll('tr[data-table-row]')]
                .find((item) => (item.dataset.search || '').includes(applicationId.toLowerCase()));
            const badge = row?.querySelector('.status-badge');
            if (!row || !badge) return;
            row.dataset.status = status;
            badge.textContent = status;
            badge.className = `status-badge ${status === 'Approved' ? 'badge-success' : status === 'Disapproved' ? 'badge-danger' : status === 'Needs Review' ? 'badge-info' : 'badge-warning'}`;
            document.querySelectorAll('[data-modal]').forEach((modal) => {
                if (modal.querySelector('.eyebrow')?.textContent.trim() !== applicationId) return;
                [...modal.querySelectorAll('.detail-grid > div')].forEach((detail) => {
                    const label = detail.querySelector('span')?.textContent.trim().toLowerCase() || '';
                    if (label === 'review status' || label === 'current status') {
                        const value = detail.querySelector('strong');
                        if (value) value.textContent = status;
                    }
                });
            });
        };

        Object.entries(registrationStatuses).forEach(([applicationId, status]) => {
            updateRegistrationRow(applicationId, status);
        });

        document.querySelectorAll('[data-registration-needs-review]').forEach((button) => {
            button.addEventListener('click', () => {
                const row = button.closest('tr');
                const badge = row?.querySelector('.status-badge');
                if (!row || !badge) return;

                row.dataset.status = 'Needs Review';
                badge.textContent = 'Needs Review';
                badge.className = 'status-badge badge-info';
                registrationStatuses[button.dataset.applicationId] = 'Needs Review';
                localStorage.setItem(REGISTRATION_STATUS_STORAGE_KEY, JSON.stringify(registrationStatuses));

                closeRegistrationMenus();
                showToast(
                    `${button.dataset.applicationId} marked as Needs Review.`,
                    'Application updated'
                );
            });
        });

        document.querySelectorAll('[data-copy-application-id]').forEach((button) => {
            button.addEventListener('click', async () => {
                const applicationId = button.dataset.copyApplicationId;

                try {
                    await navigator.clipboard.writeText(applicationId);
                    showToast(`${applicationId} copied to clipboard.`, 'Application ID copied');
                } catch {
                    const helper = document.createElement('textarea');
                    helper.value = applicationId;
                    helper.style.position = 'fixed';
                    helper.style.opacity = '0';
                    document.body.appendChild(helper);
                    helper.select();
                    document.execCommand('copy');
                    helper.remove();
                    showToast(`${applicationId} copied to clipboard.`, 'Application ID copied');
                }

                closeRegistrationMenus();
            });
        });

        // Popovers
        const closePopovers = (except = null) => {
            document.querySelectorAll('[data-popover]').forEach((popover) => {
                if (popover !== except) popover.hidden = true;
            });
        };
        document.querySelectorAll('[data-popover-toggle]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.stopPropagation();
                const popover = document.querySelector(`[data-popover="${button.dataset.popoverToggle}"]`);
                if (!popover) return;
                const opening = popover.hidden;
                closePopovers(popover);
                popover.hidden = !opening;
            });
        });
        document.addEventListener('click', (event) => {
            if (!event.target.closest('.topbar-popover-wrap')) closePopovers();
        });

        // Command palette / simulated global search
        const command = document.querySelector('[data-command-palette]');
        const commandInput = document.querySelector('[data-command-input]');
        const commandItems = [...document.querySelectorAll('[data-command-item]')];
        const openCommand = () => {
            if (!command) return;
            command.hidden = false;
            setTimeout(() => commandInput?.focus(), 20);
        };
        const closeCommand = () => {
            if (!command) return;
            command.hidden = true;
            if (commandInput) commandInput.value = '';
            commandItems.forEach((item) => item.hidden = false);
        };
        document.querySelectorAll('[data-command-close]').forEach((button) => button.addEventListener('click', closeCommand));
        document.querySelectorAll('[data-global-search]').forEach((input) => {
            input.addEventListener('focus', () => { input.blur(); openCommand(); });
        });
        document.addEventListener('keydown', (event) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault(); openCommand();
            }
            if (event.key === 'Escape') {
                if (document.querySelector('[data-return-evidence-dialog][open]')) return;

                closeCommand();
                closePopovers();
                closeRegistrationMenus();
                body.classList.remove('mobile-sidebar-open');
                syncMobileMenuAria();

                document.querySelectorAll('[data-modal]').forEach((modal) => {
                    modal.hidden = true;
                });

                document.querySelectorAll('[data-drawer]').forEach((drawer) => {
                    drawer.hidden = true;
                });
            }
        });
        commandInput?.addEventListener('input', () => {
            const query = commandInput.value.trim().toLowerCase();
            commandItems.forEach((item) => item.hidden = !item.dataset.searchText.includes(query));
        });

        // Generic modal system
        const closeModal = (modal) => { if (modal) modal.hidden = true; };
        document.querySelectorAll('[data-close-modal]').forEach((button) => {
            button.addEventListener('click', () => closeModal(button.closest('[data-modal]')));
        });
        document.querySelectorAll('[data-open-modal]').forEach((button) => {
            button.addEventListener('click', () => {
                const modal = document.querySelector(`[data-modal="${button.dataset.openModal}"]`);
                if (!modal) return;

                if (button.dataset.applicant) {
                    const sourceModal = button.closest('[data-modal]');
                    const applicationId = sourceModal?.querySelector('.eyebrow')?.textContent.trim()
                        || button.dataset.applicationId
                        || '';
                    pendingRegistrationDecision = {
                        id: applicationId,
                        applicant: button.dataset.applicant,
                        status: button.dataset.decision || 'Reviewed',
                    };
                    const recipient = modal.querySelector('[data-decision-recipient]');
                    const status = modal.querySelector('[data-decision-status]');
                    const message = modal.querySelector('[data-decision-message]');
                    if (recipient) recipient.value = button.dataset.applicant;
                    if (status) status.value = button.dataset.decision || 'Reviewed';
                    if (message) {
                        const decision = (button.dataset.decision || 'reviewed').toLowerCase();
                        message.value = `Hello ${button.dataset.applicant},\n\nThank you for submitting your Bearly registration. Your application has been ${decision}. This is a front-end preview of the email notification workflow.`;
                    }
                }

                modal.hidden = false;
                refreshIcons();
            });
        });

        document.querySelectorAll('[data-mock-action]').forEach((button) => {
            if (!button.dataset.mockAction.toLowerCase().includes('decision saved')) return;
            button.dataset.functionalAction = 'registration-decision';
            button.addEventListener('click', () => {
                if (!pendingRegistrationDecision?.id) {
                    showToast('Open an application and choose a decision first.', 'Decision not saved');
                    return;
                }

                registrationStatuses[pendingRegistrationDecision.id] = pendingRegistrationDecision.status;
                localStorage.setItem(REGISTRATION_STATUS_STORAGE_KEY, JSON.stringify(registrationStatuses));
                updateRegistrationRow(pendingRegistrationDecision.id, pendingRegistrationDecision.status);
                showToast(
                    `${pendingRegistrationDecision.id} changed to ${pendingRegistrationDecision.status}. The email is a front-end preview.`,
                    'Registration decision saved'
                );
                pendingRegistrationDecision = null;
            });
        });

        // Drawer system + compliance data injection
        let activeComplianceCard = null;
        const COMPLIANCE_STORAGE_KEY = 'bearlyAdminComplianceState';
        let complianceState = {};
        try { complianceState = JSON.parse(localStorage.getItem(COMPLIANCE_STORAGE_KEY) || '{}'); } catch {}
        const saveComplianceState = (id, state) => {
            if (!id) return;
            complianceState[id] = state;
            localStorage.setItem(COMPLIANCE_STORAGE_KEY, JSON.stringify(complianceState));
        };

        const closeDrawer = (drawer) => {
            if (!drawer) return;
            drawer.hidden = true;
        };

        document.querySelectorAll('[data-open-drawer]').forEach((button) => {
            button.addEventListener('click', () => {
                const drawer = document.querySelector(`[data-drawer="${button.dataset.openDrawer}"]`);
                if (!drawer) return;

                const setText = (selector, value) => {
                    const el = drawer.querySelector(selector);
                    if (el) el.textContent = value;
                };

                setText('[data-drawer-product]', button.dataset.flagProduct || 'Flagged product');
                setText('[data-drawer-seller]', button.dataset.flagSeller || 'Seller');
                setText('[data-drawer-reason]', button.dataset.flagReason || 'Review required.');
                setText('[data-drawer-risk]', button.dataset.flagRisk || 'Medium');
                setText('[data-drawer-warnings]', button.dataset.flagWarnings || '0');

                if (button.matches('[data-flag-card]')) {
                    activeComplianceCard = button;
                    drawer.dataset.complianceProduct = button.dataset.flagProduct || '';
                    drawer.dataset.complianceSeller = button.dataset.flagSeller || '';
                    drawer.dataset.complianceId = button.dataset.flagId || '';

                    const notes = drawer.querySelector('[data-compliance-notes]');
                    const noteError = drawer.querySelector('[data-compliance-note-error]');
                    if (notes) notes.value = button.dataset.adminNotes || '';
                    if (noteError) noteError.hidden = true;
                    const decisionForm = drawer.querySelector('[data-compliance-decision-form]');
                    if (decisionForm) decisionForm.action = button.dataset.decisionUrl || '';
                }

                drawer.hidden = false;
                refreshIcons();
            });
        });

        document.querySelectorAll('[data-close-drawer]').forEach((button) => {
            button.addEventListener('click', () => closeDrawer(button.closest('[data-drawer]')));
        });

        const findComplianceAuditRow = (product) => {
            return [...document.querySelectorAll('#compliance-table [data-table-row]')].find(
                (row) => (row.dataset.complianceProduct || '').trim().toLowerCase() === product.trim().toLowerCase()
            ) || null;
        };

        const updateComplianceFlagCount = () => {
            const counter = document.querySelector('[data-compliance-flag-count]');
            if (!counter) return;

            const remaining = document.querySelectorAll('[data-flag-card]').length;
            counter.textContent = remaining;
        };

        document.querySelectorAll('[data-flag-card]').forEach((card) => {
            const saved = complianceState[card.dataset.flagId];
            if (!saved) return;
            card.dataset.adminNotes = saved.notes || '';
            if (saved.warnings != null) card.dataset.flagWarnings = String(saved.warnings);
            if (saved.action === 'suspend') card.dataset.sellerSuspended = 'true';
            if (saved.action === 'compliant') {
                const badge = findComplianceAuditRow(card.dataset.flagProduct || '')?.querySelector('.js-compliance-status');
                if (badge) {
                    badge.textContent = 'Compliant';
                    badge.className = 'status-badge js-compliance-status badge-success';
                }
                card.remove();
            }
        });
        updateComplianceFlagCount();

        document.querySelectorAll('[data-compliance-action]').forEach((button) => {
            button.addEventListener('click', () => {
                const drawer = button.closest('[data-drawer]');
                if (!drawer || !activeComplianceCard) return;

                const action = button.dataset.complianceAction;
                const notes = drawer.querySelector('[data-compliance-notes]');
                const noteError = drawer.querySelector('[data-compliance-note-error]');
                const noteText = notes?.value.trim() || '';

                if ((action === 'warning' || action === 'suspend') && !noteText) {
                    if (noteError) noteError.hidden = false;
                    notes?.focus();
                    return;
                }

                if (noteError) noteError.hidden = true;
                activeComplianceCard.dataset.adminNotes = noteText;

                const product = activeComplianceCard.dataset.flagProduct || '';
                const seller = activeComplianceCard.dataset.flagSeller || '';
                const complianceId = activeComplianceCard.dataset.flagId || '';
                const auditRow = findComplianceAuditRow(product);
                const statusBadge = auditRow?.querySelector('.js-compliance-status');

                if (action === 'compliant') {
                    saveComplianceState(complianceId, { action, notes: noteText });
                    if (statusBadge) {
                        statusBadge.textContent = 'Compliant';
                        statusBadge.className = 'status-badge js-compliance-status badge-success';
                    }

                    const removedCard = activeComplianceCard;
                    activeComplianceCard = null;
                    removedCard.remove();
                    updateComplianceFlagCount();
                    closeDrawer(drawer);

                    showToast(
                        `${product} was marked compliant and removed from the manual review queue.`,
                        'Listing marked compliant'
                    );
                    return;
                }

                if (action === 'warning') {
                    const currentWarnings = Number(activeComplianceCard.dataset.flagWarnings || 0);
                    const nextWarnings = currentWarnings + 1;

                    activeComplianceCard.dataset.flagWarnings = String(nextWarnings);
                    saveComplianceState(complianceId, { action, notes: noteText, warnings: nextWarnings });

                    const drawerWarnings = drawer.querySelector('[data-drawer-warnings]');
                    if (drawerWarnings) drawerWarnings.textContent = String(nextWarnings);

                    if (statusBadge) {
                        statusBadge.textContent = 'Flagged';
                        statusBadge.className = 'status-badge js-compliance-status badge-danger';
                    }

                    closeDrawer(drawer);

                    showToast(
                        `${seller} now has ${nextWarnings} warning${nextWarnings === 1 ? '' : 's'} for ${product}.`,
                        'Warning issued'
                    );
                    return;
                }

                if (action === 'suspend') {
                    activeComplianceCard.dataset.sellerSuspended = 'true';
                    saveComplianceState(complianceId, { action, notes: noteText });

                    if (statusBadge) {
                        statusBadge.textContent = 'Flagged';
                        statusBadge.className = 'status-badge js-compliance-status badge-danger';
                    }

                    closeDrawer(drawer);

                    showToast(
                        `${seller} was marked suspended in this front-end preview.`,
                        'Seller suspended'
                    );
                }
            });
        });

        // Reusable table filtering
        const runTableFilter = (tableId) => {
            const table = document.getElementById(tableId);
            if (!table) return;
            const search = document.querySelector(`[data-table-search="${tableId}"]`)?.value.trim().toLowerCase() || '';
            const filters = [...document.querySelectorAll(`[data-table-filter="${tableId}"]`)].map((select) => ({
                key: select.dataset.filterKey,
                value: select.value.toLowerCase(),
            }));
            let visible = 0;
            table.querySelectorAll('[data-table-row]').forEach((row) => {
                const matchesSearch = !search || (row.dataset.search || row.textContent.toLowerCase()).includes(search);
                const matchesFilters = filters.every((filter) => !filter.value || (row.dataset[filter.key] || '').toLowerCase() === filter.value);
                row.hidden = !(matchesSearch && matchesFilters);
                if (!row.hidden) visible += 1;
            });
            const empty = document.querySelector(`[data-table-empty="${tableId}"]`);
            if (empty) empty.hidden = visible !== 0;
        };
        document.querySelectorAll('[data-table-search]').forEach((input) => input.addEventListener('input', () => runTableFilter(input.dataset.tableSearch)));
        document.querySelectorAll('[data-table-filter]').forEach((select) => select.addEventListener('change', () => runTableFilter(select.dataset.tableFilter)));

        // User list CSV export
        const escapeCsvValue = (value = '') => {
            const normalized = String(value).replace(/\r?\n|\r/g, ' ').trim();
            return `\"${normalized.replaceAll('\"', '\"\"')}\"`;
        };

        document.querySelectorAll('[data-export-users]').forEach((button) => {
            button.addEventListener('click', () => {
                const tableId = button.dataset.exportUsers;
                const table = document.getElementById(tableId);

                if (!table) {
                    showToast('The user table could not be found.', 'Export failed');
                    return;
                }

                const rows = [...table.querySelectorAll('[data-table-row]')]
                    .filter((row) => !row.hidden);

                if (!rows.length) {
                    showToast('There are no visible users to export.', 'Nothing to export');
                    return;
                }

                const csvRows = [
                    ['User ID', 'Name', 'Email', 'Role', 'Joined', 'Status'],
                    ...rows.map((row) => [
                        row.dataset.userId || '',
                        row.dataset.userName || '',
                        row.dataset.userEmail || '',
                        row.dataset.role || '',
                        row.dataset.userJoined || '',
                        row.dataset.status || row.querySelector('.js-status-badge')?.textContent?.trim() || '',
                    ]),
                ];

                const csv = '\uFEFF' + csvRows
                    .map((row) => row.map(escapeCsvValue).join(','))
                    .join('\r\n');

                const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const download = document.createElement('a');
                const dateStamp = new Date().toISOString().slice(0, 10);

                download.href = url;
                download.download = `bearly-user-list-${dateStamp}.csv`;
                document.body.appendChild(download);
                download.click();
                download.remove();
                URL.revokeObjectURL(url);

                const filtered = rows.length !== table.querySelectorAll('[data-table-row]').length;
                showToast(
                    `${rows.length} ${rows.length === 1 ? 'user' : 'users'} exported${filtered ? ' from the current filtered view' : ''}.`,
                    'User list exported'
                );
            });
        });


        // Report filters are server-authoritative: the filter bar is a GET form
        // and the page renders only the selected report's dataset. Client code
        // never re-filters rows; it only builds the print view from the
        // rendered server values.

        const getReportExportData = () => {
            const context = document.querySelector('[data-report-context]');

            const kpis = [...document.querySelectorAll('.report-kpi')].map((card) => ({
                label: card.querySelector('p')?.textContent?.trim() || '',
                value: card.querySelector('strong')?.textContent?.trim() || '',
                note: card.querySelector('small')?.textContent?.trim() || '',
            }));

            const table = document.querySelector('[data-report-export-table]');
            const tableHeaders = table
                ? [...table.querySelectorAll('thead th')].map((cell) => cell.textContent.trim())
                : [];
            const tableRows = table
                ? [...table.querySelectorAll('tbody tr')].map((row) =>
                    [...row.querySelectorAll('td')].map((cell) => cell.textContent.trim().replace(/\s+/g, ' '))
                )
                : [];

            return {
                reportType: context?.dataset.reportLabel || 'Financial Report',
                period: context?.dataset.reportPeriod || '',
                startDate: context?.dataset.reportFrom || '',
                endDate: context?.dataset.reportTo || '',
                basis: context?.dataset.reportBasis || '',
                kpis,
                tableHeaders,
                tableRows,
            };
        };

        const escapeReportHtml = (value = '') => String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

        const openReportPrintView = () => {
            const report = getReportExportData();
            const printWindow = window.open('', '_blank', 'width=1000,height=760');

            if (!printWindow) {
                showToast('Allow pop-ups for this site, then try Export PDF again.', 'PDF export blocked');
                return;
            }

            const kpiHtml = report.kpis.map((item) => `
                <div class="metric">
                    <span>${escapeReportHtml(item.label)}</span>
                    <strong>${escapeReportHtml(item.value)}</strong>
                    <small>${escapeReportHtml(item.note)}</small>
                </div>
            `).join('');

            const detailHeadings = report.tableHeaders
                .map((heading) => `<th>${escapeReportHtml(heading)}</th>`)
                .join('');
            const detailRows = report.tableRows.map((row) => `
                <tr>${row.map((cell) => `<td>${escapeReportHtml(cell)}</td>`).join('')}</tr>
            `).join('');

            printWindow.document.write(`
                <!doctype html>
                <html>
                <head>
                    <meta charset="utf-8">
                    <title>Bearly Admin Report</title>
                    <style>
                        * { box-sizing: border-box; }
                        body {
                            margin: 0;
                            padding: 32px;
                            color: #292421;
                            background: #fff;
                            font-family: Arial, sans-serif;
                        }
                        header {
                            padding-bottom: 18px;
                            border-bottom: 2px solid #4a2c20;
                        }
                        h1 { margin: 0 0 8px; font-size: 25px; color: #2c1a14; }
                        .meta { margin: 4px 0; color: #6a625d; font-size: 12px; }
                        h2 { margin: 28px 0 12px; font-size: 17px; color: #4a2c20; }
                        .metrics {
                            display: grid;
                            grid-template-columns: repeat(2, 1fr);
                            gap: 10px;
                        }
                        .metric {
                            padding: 13px;
                            border: 1px solid #e5cfb7;
                            border-radius: 8px;
                        }
                        .metric span, .metric small { display: block; color: #6a625d; }
                        .metric span { font-size: 11px; }
                        .metric strong { display: block; margin: 5px 0; font-size: 20px; }
                        .metric small { font-size: 10px; }
                        table { width: 100%; border-collapse: collapse; font-size: 11px; }
                        th, td {
                            padding: 9px 8px;
                            border-bottom: 1px solid #e5cfb7;
                            text-align: left;
                        }
                        th { color: #4a2c20; background: #fff8ef; }
                        footer { margin-top: 26px; color: #8a7970; font-size: 9px; }
                        @media print {
                            body { padding: 0; }
                            @page { margin: 14mm; }
                        }
                    </style>
                </head>
                <body>
                    <header>
                        <h1>Bearly Admin Report</h1>
                        <div class="meta"><strong>Report type:</strong> ${escapeReportHtml(report.reportType)}</div>
                        <div class="meta"><strong>Period:</strong> ${escapeReportHtml(report.period || `${report.startDate || '—'} to ${report.endDate || '—'}`)}</div>
                        <div class="meta"><strong>Basis:</strong> ${escapeReportHtml(report.basis)}</div>
                    </header>

                    <h2>Summary Metrics</h2>
                    <div class="metrics">${kpiHtml}</div>

                    <h2>Report Details</h2>
                    <table>
                        <thead><tr>${detailHeadings}</tr></thead>
                        <tbody>${detailRows}</tbody>
                    </table>

                    <footer>Generated from the Bearly Admin reporting interface.</footer>
                </body>
                </html>
            `);

            printWindow.document.close();
            printWindow.focus();

            setTimeout(() => {
                printWindow.print();
            }, 250);

            showToast('Print view opened. Choose “Save as PDF” in the browser print dialog.', 'PDF report ready');
        };

        document.querySelectorAll('[data-export-report]').forEach((button) => {
            button.addEventListener('click', () => {
                if (button.dataset.exportReport === 'pdf') {
                    openReportPrintView();
                }
            });
        });

        // Finance ledgers use server-authoritative values. Client code only filters
        // and exports the rendered rows.

        const applyFinanceFilters = (module) => {
            const rows = [...module.querySelectorAll('[data-finance-row]')];
            const query = module.querySelector('[data-finance-search]')?.value.trim().toLowerCase() || '';
            const status = module.querySelector('[data-finance-status]')?.value || '';
            const start = module.querySelector('[data-finance-start]')?.value || '';
            const end = module.querySelector('[data-finance-end]')?.value || '';
            let visible = 0;

            rows.forEach((row) => {
                const matchesQuery = !query || (row.dataset.search || '').includes(query);
                const matchesStatus = !status || row.dataset.status === status;
                const rowDate = row.dataset.date || '';
                const matchesStart = !start || !rowDate || rowDate >= start;
                const matchesEnd = !end || !rowDate || rowDate <= end;
                const matches = matchesQuery && matchesStatus && matchesStart && matchesEnd;
                row.hidden = !matches;
                if (matches) visible += 1;
            });

            const visibleCount = module.querySelector('[data-finance-visible]');
            const empty = module.querySelector('[data-finance-empty]');
            if (visibleCount) visibleCount.textContent = String(visible);
            if (empty) empty.hidden = visible !== 0;
        };

        document.querySelectorAll('[data-finance-module]').forEach((module) => {
            const filterControls = module.querySelectorAll('[data-finance-search], [data-finance-status], [data-finance-start], [data-finance-end]');
            filterControls.forEach((control) => {
                control.addEventListener(control.matches('[type="search"]') ? 'input' : 'change', () => {
                    const start = module.querySelector('[data-finance-start]')?.value || '';
                    const end = module.querySelector('[data-finance-end]')?.value || '';
                    if (start && end && start > end) {
                        showToast('The start date cannot be later than the end date.', 'Invalid date range');
                        return;
                    }
                    applyFinanceFilters(module);
                });
            });

            module.querySelector('[data-finance-reset]')?.addEventListener('click', () => {
                filterControls.forEach((control) => { control.value = ''; });
                applyFinanceFilters(module);
            });
        });

        document.querySelectorAll('[data-finance-export]').forEach((button) => {
            button.addEventListener('click', () => {
                const table = document.querySelector(`[data-finance-table="${button.dataset.financeExport}"]`);
                if (!table) return;

                const rows = [
                    [...table.querySelectorAll('thead th')].map((cell) => cell.textContent.trim()),
                    ...[...table.querySelectorAll('tbody tr')]
                        .filter((row) => !row.hidden)
                        .map((row) => [...row.querySelectorAll('td')].map((cell) => cell.textContent.trim().replace(/\s+/g, ' '))),
                ];
                const csv = '\uFEFF' + rows.map((row) => row.map(escapeCsvValue).join(',')).join('\r\n');
                const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
                const link = document.createElement('a');
                link.href = url;
                link.download = `bearly-${button.dataset.financeExport}-${new Date().toISOString().slice(0, 10)}.csv`;
                document.body.appendChild(link);
                link.click();
                link.remove();
                URL.revokeObjectURL(url);
                showToast('The currently visible rows were exported.', 'Finance export ready');
            });
        });

        // Account status changes are server-authoritative and audited.
        try {
            localStorage.removeItem('bearlyAdminUserStatuses');
        } catch {
            // Status rendering never depends on browser storage.
        }

        const userStatusLabels = {
            active: 'Active',
            suspended: 'Suspended',
            deactivated: 'Deactivated',
        };
        const userStatusDialog = document.querySelector('[data-user-status-dialog]');
        const userStatusForm = userStatusDialog?.querySelector('[data-user-status-form]');
        const userStatusReason = userStatusForm?.querySelector('[name="reason"]');
        const userStatusValue = userStatusForm?.querySelector('[data-user-status-value]');
        const userStatusSubmit = userStatusForm?.querySelector('[data-user-status-submit]');
        const userStatusError = userStatusForm?.querySelector('[data-user-status-error]');
        const userStatusDescription = userStatusDialog?.querySelector('[data-user-status-description]');
        const userStatusCsrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        let activeUserStatusTrigger = null;
        let activeUserStatusContext = null;

        const normalizeUserStatus = (status) => String(status || '').trim().toLowerCase();
        const userStatusLabel = (status) => userStatusLabels[normalizeUserStatus(status)] || 'Unknown';
        const parseAllowedStatuses = (context) => new Set(
            (context.dataset.allowedStatuses || '')
                .split(',')
                .map(normalizeUserStatus)
                .filter(Boolean)
        );

        const syncUserStatusControls = (context, status) => {
            const currentStatus = normalizeUserStatus(status);
            const allowedStatuses = parseAllowedStatuses(context);
            const primaryButton = context.querySelector('[data-user-primary-action]');
            const primaryTarget = currentStatus === 'active' ? 'suspended' : 'active';

            if (primaryButton) {
                primaryButton.dataset.userStatus = primaryTarget;
                primaryButton.hidden = !allowedStatuses.has(primaryTarget);

                const icon = primaryButton.querySelector('i');
                const label = primaryButton.querySelector('span');
                const primaryLabel = primaryTarget === 'active' ? 'Activate' : 'Suspend';
                icon?.setAttribute('data-lucide', primaryTarget === 'active' ? 'circle-check-big' : 'pause-circle');
                if (label) label.textContent = primaryLabel;
                primaryButton.setAttribute(
                    'aria-label',
                    `${primaryLabel} ${context.dataset.userName || 'user'} account`
                );
            }

            context.querySelectorAll('[data-user-status]').forEach((button) => {
                if (button === primaryButton) return;

                const targetStatus = normalizeUserStatus(button.dataset.userStatus);
                button.hidden = targetStatus === currentStatus
                    || !allowedStatuses.has(targetStatus)
                    || (primaryButton && targetStatus === primaryTarget);
            });

            context.dataset.currentStatus = currentStatus;
        };

        const updateUserStatusCounts = (previousStatus, nextStatus) => {
            const previousCount = document.querySelector(`[data-user-kpi="${previousStatus}"]`);
            const nextCount = document.querySelector(`[data-user-kpi="${nextStatus}"]`);

            if (previousStatus === nextStatus || !previousCount || !nextCount) return;

            previousCount.textContent = String(Math.max(0, Number(previousCount.textContent) - 1));
            nextCount.textContent = String(Number(nextCount.textContent) + 1);
        };

        const applyServerUserStatus = (userId, status, allowedStatuses) => {
            const normalizedStatus = normalizeUserStatus(status);
            const label = userStatusLabel(normalizedStatus);
            const contexts = [...document.querySelectorAll('[data-user-status-context]')]
                .filter((context) => context.dataset.userId === String(userId));
            const row = contexts.find((context) => context.matches('tr[data-user-id]'));
            const previousStatus = normalizeUserStatus(row?.dataset.currentStatus || contexts[0]?.dataset.currentStatus);

            updateUserStatusCounts(previousStatus, normalizedStatus);

            contexts.forEach((context) => {
                context.dataset.currentStatus = normalizedStatus;
                context.dataset.allowedStatuses = allowedStatuses.join(',');
                syncUserStatusControls(context, normalizedStatus);

                if (context.matches('tr[data-user-id]')) {
                    context.dataset.status = label;
                    const badge = context.querySelector('.js-status-badge, .status-badge');

                    if (badge) {
                        badge.textContent = label;
                        badge.className = `status-badge js-status-badge ${normalizedStatus === 'active' ? 'badge-success' : normalizedStatus === 'suspended' ? 'badge-warning' : 'badge-danger'}`;
                    }

                    const tableId = context.closest('table')?.id;
                    if (tableId) runTableFilter(tableId);
                }

                const profileModal = context.closest('[data-modal]');

                profileModal?.querySelectorAll('[data-user-current-status]').forEach((currentStatusValue) => {
                    currentStatusValue.textContent = label;
                });

                const statusClass = `is-${normalizedStatus}`;
                const statusClasses = ['is-active', 'is-suspended', 'is-deactivated', 'is-unknown'];

                profileModal?.querySelectorAll('[data-user-status-indicator], [data-user-status-card], [data-user-status-dot]').forEach((indicator) => {
                    indicator.classList.remove(...statusClasses);
                    indicator.classList.add(statusClass);
                    indicator.dataset.status = normalizedStatus;
                });

                const statusIndicatorIcon = profileModal?.querySelector('[data-user-status-indicator] i');
                statusIndicatorIcon?.setAttribute(
                    'data-lucide',
                    normalizedStatus === 'active'
                        ? 'circle-check-big'
                        : normalizedStatus === 'suspended'
                            ? 'pause-circle'
                            : 'user-x'
                );
            });

            refreshIcons();
        };

        document.querySelectorAll('[data-user-status-context]').forEach((context) => {
            syncUserStatusControls(context, context.dataset.currentStatus || context.dataset.status);
        });

        const closeUserStatusDialog = (restoreFocus = true) => {
            if (!userStatusDialog) return;

            userStatusDialog.hidden = true;
            if (userStatusReason) userStatusReason.value = '';
            if (userStatusError) {
                userStatusError.textContent = '';
                userStatusError.hidden = true;
            }

            if (restoreFocus) {
                const row = activeUserStatusContext?.matches('tr')
                    ? activeUserStatusContext
                    : activeUserStatusContext?.closest('tr[data-user-id]');
                const focusTarget = row?.querySelector('.status-badge')
                    || activeUserStatusContext?.closest('[data-modal]')?.querySelector('[data-user-current-status]')
                    || activeUserStatusTrigger;

                if (focusTarget instanceof HTMLElement) {
                    if (!focusTarget.matches('button, a, input, select, textarea, [tabindex]')) {
                        focusTarget.tabIndex = -1;
                    }
                    focusTarget.focus();
                }
            }

            activeUserStatusTrigger = null;
            activeUserStatusContext = null;
        };

        userStatusDialog?.querySelectorAll('[data-close-modal]').forEach((button) => {
            button.addEventListener('click', () => closeUserStatusDialog());
        });

        userStatusDialog?.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                closeUserStatusDialog();
                return;
            }

            if (event.key !== 'Tab') return;

            const focusable = [...userStatusDialog.querySelectorAll(
                'a[href], button:not([disabled]):not(.modal-backdrop), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )].filter((element) => !element.hidden && element.getClientRects().length > 0);
            const first = focusable[0];
            const last = focusable.at(-1);

            if (!first || !last) {
                event.preventDefault();
                return;
            }

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });

        document.querySelectorAll('[data-user-status]').forEach((button) => {
            button.addEventListener('click', () => {
                const context = button.closest('[data-user-status-context]');
                const targetStatus = normalizeUserStatus(button.dataset.userStatus);
                const currentStatus = normalizeUserStatus(context?.dataset.currentStatus);
                const allowedStatuses = context ? parseAllowedStatuses(context) : new Set();

                if (!context || !userStatusDialog || !userStatusForm || !allowedStatuses.has(targetStatus) || targetStatus === currentStatus) return;

                activeUserStatusTrigger = button;
                activeUserStatusContext = context;
                userStatusForm.dataset.statusUrl = context.dataset.statusUrl || '';
                if (userStatusValue) userStatusValue.value = targetStatus;
                if (userStatusDescription) {
                    userStatusDescription.textContent = `Change ${context.dataset.userName || 'this account'} from ${userStatusLabel(currentStatus)} to ${userStatusLabel(targetStatus)}?`;
                }
                if (userStatusError) {
                    userStatusError.textContent = '';
                    userStatusError.hidden = true;
                }
                if (userStatusReason) userStatusReason.value = '';

                closeRegistrationMenus();
                userStatusDialog.hidden = false;
                refreshIcons();
                userStatusReason?.focus();
            });
        });

        userStatusForm?.addEventListener('submit', async (event) => {
            event.preventDefault();

            const endpoint = userStatusForm.dataset.statusUrl;
            const reason = userStatusReason?.value.trim() || '';
            const targetStatus = normalizeUserStatus(userStatusValue?.value);

            if (userStatusForm.getAttribute('aria-busy') === 'true') return;

            if (!endpoint || !activeUserStatusContext || !reason || !Object.hasOwn(userStatusLabels, targetStatus)) {
                    userStatusError.textContent = 'Enter a reason before confirming this account status change.';
                    userStatusError.hidden = false;
                    userStatusReason?.focus();
                    return;
                }

                const statusContext = activeUserStatusContext;
                const targetUserId = statusContext.dataset.userId;

            userStatusError.textContent = '';
            userStatusError.hidden = true;
            userStatusForm.setAttribute('aria-busy', 'true');
            if (userStatusSubmit) {
                userStatusSubmit.disabled = true;
                userStatusSubmit.textContent = 'Saving…';
            }

            try {
                const response = await fetch(endpoint, {
                    method: 'PATCH',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': userStatusCsrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ status: targetStatus, reason }),
                });
                const payload = await response.json().catch(() => null);

                if (!response.ok) {
                    const validationMessage = Object.values(payload?.errors || {}).flat()[0];
                    throw new Error(validationMessage || payload?.message || 'The account status could not be updated. Try again.');
                }

                const updatedStatus = normalizeUserStatus(payload?.data?.status);
                const allowedStatuses = payload?.data?.allowed_statuses;

                if (
                    String(payload?.data?.user_id) !== targetUserId
                    || !Object.hasOwn(userStatusLabels, updatedStatus)
                    || !Array.isArray(allowedStatuses)
                ) {
                    throw new Error('The server returned an unexpected account status. Reload the page and try again.');
                }

                applyServerUserStatus(targetUserId, updatedStatus, allowedStatuses);
                closeUserStatusDialog(false);
                showToast(`Account status updated to ${userStatusLabel(updatedStatus)}.`, 'Account status updated');

                const row = [...document.querySelectorAll('tr[data-user-id]')]
                    .find((item) => item.dataset.userId === targetUserId);
                const focusTarget = row && !row.hidden
                    ? row.querySelector('.status-badge')
                    : statusContext.closest('[data-modal]')?.querySelector('[data-user-current-status]');
                if (focusTarget instanceof HTMLElement) {
                    if (!focusTarget.matches('button, a, input, select, textarea, [tabindex]')) focusTarget.tabIndex = -1;
                    focusTarget.focus();
                }
            } catch (error) {
                userStatusError.textContent = error instanceof Error
                    ? error.message
                    : 'The account status could not be updated. Try again.';
                userStatusError.hidden = false;
            } finally {
                userStatusForm.removeAttribute('aria-busy');
                if (userStatusSubmit) {
                    userStatusSubmit.disabled = false;
                    userStatusSubmit.textContent = 'Confirm change';
                }
            }
        });

        // Product Violation decisions are submitted to the audited Admin route.

        // Persist table-based Admin workflow decisions
        const WORKFLOW_STATUS_STORAGE_KEY = 'bearlyAdminWorkflowStatuses';
        const workflowStatuses = (() => {
            try {
                return JSON.parse(localStorage.getItem(WORKFLOW_STATUS_STORAGE_KEY) || '{}');
            } catch {
                return {};
            }
        })();
        const workflowBadgeClass = (status) => {
            if (['Published', 'Active', 'Approved', 'Completed', 'Resolved'].includes(status)) return 'badge-success';
            if (['Archived', 'Rejected', 'Removal Required', 'Escalated'].includes(status)) return 'badge-danger';
            if (['Scheduled', 'Awaiting Evidence', 'Awaiting Seller', 'Warning Issued'].includes(status)) return 'badge-warning';
            return 'badge-info';
        };

        // Announcement modal + workflow synchronization
        const syncAnnouncementActions = (modal, status) => {
            if (!modal) return;

            const publishButton = modal.querySelector(
                '[data-announcement-action="publish"]'
            );

            const editButton = modal.querySelector(
                '[data-announcement-action="edit"]'
            );

            const archiveButton = modal.querySelector(
                '[data-announcement-action="archive"]'
            );

            const indicator = modal.querySelector(
                '[data-announcement-state-indicator]'
            );

            const indicatorLabel = modal.querySelector(
                '[data-announcement-state-label]'
            );


            if (
                status === 'Draft' ||
                status === 'Scheduled'
            ) {
                if (publishButton) publishButton.hidden = false;
                if (editButton) editButton.hidden = false;
                if (archiveButton) archiveButton.hidden = false;

                if (indicator) indicator.hidden = true;

                return;
            }


            if (status === 'Published') {
                if (publishButton) publishButton.hidden = true;
                if (editButton) editButton.hidden = false;
                if (archiveButton) archiveButton.hidden = false;

                if (indicator) indicator.hidden = true;

                return;
            }


            if (status === 'Archived') {
                if (publishButton) publishButton.hidden = true;
                if (editButton) editButton.hidden = true;
                if (archiveButton) archiveButton.hidden = true;

                if (indicator) {
                    indicator.hidden = false;
                }

                if (indicatorLabel) {
                    indicatorLabel.textContent =
                        'Announcement archived';
                }
            }
        };


        const findAnnouncementModal = (announcementId) => {
            return [
                ...document.querySelectorAll(
                    '[data-announcement-modal]'
                )
            ].find(
                (modal) =>
                    modal.dataset.announcementId ===
                    announcementId
            ) || null;
        };


        const syncAnnouncementModal = (
            announcementId,
            record = {}
        ) => {
            const modal =
                findAnnouncementModal(
                    announcementId
                );

            if (!modal) return;


            const title =
                modal.querySelector(
                    '[data-announcement-title]'
                );

            const audience =
                modal.querySelector(
                    '[data-announcement-audience]'
                );

            const status =
                modal.querySelector(
                    '[data-announcement-status]'
                );

            const author =
                modal.querySelector(
                    '[data-announcement-author]'
                );

            const date =
                modal.querySelector(
                    '[data-announcement-date]'
                );

            const time =
                modal.querySelector(
                    '[data-announcement-time]'
                );

            const message =
                modal.querySelector(
                    '[data-announcement-message]'
                );


            if (
                record.title !== undefined &&
                title
            ) {
                title.textContent =
                    record.title;
            }


            if (
                record.audience !== undefined &&
                audience
            ) {
                audience.textContent =
                    record.audience;
            }


            if (
                record.status !== undefined &&
                status
            ) {
                status.textContent =
                    record.status;
            }


            if (
                record.author !== undefined &&
                author
            ) {
                author.textContent =
                    record.author;
            }


            if (
                record.date !== undefined &&
                date
            ) {
                date.textContent =
                    record.date || '—';
            }


            if (
                record.time !== undefined &&
                time
            ) {
                time.textContent =
                    record.time || '—';
            }


            if (
                record.message !== undefined &&
                message
            ) {
                message.textContent =
                    record.message;
            }


            if (record.status) {
                syncAnnouncementActions(
                    modal,
                    record.status
                );
            }
        };

        // Platform Policy modal + workflow synchronization
        const syncPolicyActions = (modal, status) => {
            if (!modal) return;

            const editButton =
                modal.querySelector('[data-policy-action="edit"]');

            const publishButton =
                modal.querySelector('[data-policy-action="publish"]');

            const archiveButton =
                modal.querySelector('[data-policy-action="archive"]');

            const indicator =
                modal.querySelector('[data-policy-state-indicator]');

            const indicatorLabel =
                modal.querySelector('[data-policy-state-label]');


            if (status === 'Draft') {
                if (editButton) editButton.hidden = false;
                if (publishButton) publishButton.hidden = false;
                if (archiveButton) archiveButton.hidden = false;
                if (indicator) indicator.hidden = true;
                return;
            }


            if (status === 'Active') {
                if (editButton) editButton.hidden = false;
                if (publishButton) publishButton.hidden = true;
                if (archiveButton) archiveButton.hidden = false;
                if (indicator) indicator.hidden = true;
                return;
            }


            if (status === 'Archived') {
                if (editButton) editButton.hidden = true;
                if (publishButton) publishButton.hidden = true;
                if (archiveButton) archiveButton.hidden = true;

                if (indicator) {
                    indicator.hidden = false;
                }

                if (indicatorLabel) {
                    indicatorLabel.textContent =
                        'Policy archived';
                }
            }
        };


        const findPolicyModal = (policyId) =>
            [
                ...document.querySelectorAll(
                    '[data-policy-modal]'
                ),
            ].find(
                (modal) =>
                    modal.dataset.policyId === policyId
            ) || null;


        const syncPolicyModal = (
            policyId,
            record = {}
        ) => {
            const modal =
                findPolicyModal(policyId);

            if (!modal) return;


            const title =
                modal.querySelector('[data-policy-title]');

            const category =
                modal.querySelector('[data-policy-category]');

            const version =
                modal.querySelector('[data-policy-version]');

            const status =
                modal.querySelector('[data-policy-status]');

            const updated =
                modal.querySelector('[data-policy-updated]');

            const author =
                modal.querySelector('[data-policy-author]');

            const summary =
                modal.querySelector('[data-policy-summary]');

            const body =
                modal.querySelector('[data-policy-body]');


            if (record.title !== undefined && title) {
                title.textContent = record.title;
            }

            if (record.category !== undefined && category) {
                category.textContent = record.category;
            }

            if (record.version !== undefined && version) {
                version.textContent = record.version;
            }

            if (record.status !== undefined && status) {
                status.textContent = record.status;
            }

            if (record.updated !== undefined && updated) {
                updated.textContent = record.updated;
            }

            if (record.author !== undefined && author) {
                author.textContent = record.author;
            }

            if (record.summary !== undefined && summary) {
                summary.textContent = record.summary;
            }

            if (record.body !== undefined && body) {
                body.textContent = record.body;
            }


            if (record.status) {
                syncPolicyActions(
                    modal,
                    record.status
                );
            }
        };            

        // Returns & Refunds modal action visibility
        const syncReturnActions = (modal, status) => {
            if (!modal) return;

            const approveButton = modal.querySelector(
                '[data-return-button="approve"]'
            );

            const rejectButton = modal.querySelector(
                '[data-return-button="reject"]'
            );

            const evidenceButton = modal.querySelector(
                '[data-return-button="evidence"]'
            );

            const indicator = modal.querySelector(
                '[data-return-state-indicator]'
            );

            const indicatorLabel = modal.querySelector(
                '[data-return-state-label]'
            );

            const showNormalActions = () => {
                if (approveButton) approveButton.hidden = false;
                if (rejectButton) rejectButton.hidden = false;
                if (evidenceButton) evidenceButton.hidden = false;

                if (indicator) indicator.hidden = true;
            };


            /*
            * Cases that still require an Admin decision.
            */
            if (
                status === 'Escalated' ||
                status === 'Under Review' ||
                status === 'Awaiting Seller'
            ) {
                showNormalActions();
                return;
            }


            /*
            * Evidence has already been requested.
            */
            if (status === 'Awaiting Evidence') {
                if (approveButton) approveButton.hidden = true;
                if (rejectButton) rejectButton.hidden = true;
                if (evidenceButton) evidenceButton.hidden = true;

                if (indicator) indicator.hidden = false;

                if (indicatorLabel) {
                    indicatorLabel.textContent =
                        'Evidence requested';
                }

                return;
            }


            /*
            * Refund approved.
            */
            if (status === 'Approved') {
                if (approveButton) approveButton.hidden = true;
                if (rejectButton) rejectButton.hidden = true;
                if (evidenceButton) evidenceButton.hidden = true;

                if (indicator) indicator.hidden = false;

                if (indicatorLabel) {
                    indicatorLabel.textContent =
                        'Refund approved';
                }

                return;
            }


            /*
            * Refund rejected.
            */
            if (status === 'Rejected') {
                if (approveButton) approveButton.hidden = true;
                if (rejectButton) rejectButton.hidden = true;
                if (evidenceButton) evidenceButton.hidden = true;

                if (indicator) indicator.hidden = false;

                if (indicatorLabel) {
                    indicatorLabel.textContent =
                        'Request rejected';
                }

                return;
            }


            /*
            * Already completed.
            */
            if (status === 'Resolved') {
                if (approveButton) approveButton.hidden = true;
                if (rejectButton) rejectButton.hidden = true;
                if (evidenceButton) evidenceButton.hidden = true;

                if (indicator) indicator.hidden = false;

                if (indicatorLabel) {
                    indicatorLabel.textContent =
                        'Case resolved';
                }
            }
        };
        const updateWorkflowRow = (recordId, status) => {
            const row = [
                ...document.querySelectorAll(
                    'tr[data-table-row]'
                )
            ].find((item) =>
                (item.dataset.search || '')
                    .includes(recordId.toLowerCase())
            );

            const badge =
                row?.querySelector('.status-badge');

            if (!row || !badge) return;


            /*
            * Update table row state.
            */
            row.dataset.status = status;

            badge.textContent = status;

            badge.className =
                `status-badge ${workflowBadgeClass(status)}`;


            /*
            * Update matching modal.
            */
            document
                .querySelectorAll('[data-modal]')
                .forEach((modal) => {

                    if (
                        modal
                            .querySelector('.eyebrow')
                            ?.textContent
                            .trim() !== recordId
                    ) {
                        return;
                    }

                    [
                        ...modal.querySelectorAll(
                            '.detail-grid > div'
                        )
                    ].forEach((detail) => {

                        const label =
                            detail
                                .querySelector('span')
                                ?.textContent
                                .trim()
                                .toLowerCase();

                        if (
                            label !== 'current status' &&
                            label !== 'status'
                        ) {
                            return;
                        }

                        const value =
                            detail.querySelector('strong');

                        if (value) {
                            value.textContent = status;
                        }
                    });


                    /*
                    * Returns & Refunds footer state.
                    */
                    if (modal.matches('[data-return-modal]')) {
                        const modalStatus =
                            modal.querySelector(
                                '[data-return-modal-status]'
                            );

                        if (modalStatus) {
                            modalStatus.textContent =
                                status;
                        }

                        syncReturnActions(
                            modal,
                            status
                        );
                    }

                    /*
                    * Announcement modal state.
                    */
                    if (
                        recordId.startsWith('ANN-') &&
                        modal.matches('[data-announcement-modal]')
                    ) {
                        const announcementStatus =
                            modal.querySelector(
                                '[data-announcement-status]'
                            );

                        if (announcementStatus) {
                            announcementStatus.textContent =
                                status;
                        }

                        syncAnnouncementActions(
                            modal,
                            status
                        );
                    }
                    
                    /*
                    * Platform Policy modal state.
                    */
                    if (
                        recordId.startsWith('POL-') &&
                        modal.matches('[data-policy-modal]')
                    ) {
                        const policyStatus =
                            modal.querySelector(
                                '[data-policy-status]'
                            );

                        if (policyStatus) {
                            policyStatus.textContent =
                                status;
                        }

                        syncPolicyActions(
                            modal,
                            status
                        );
                    }
                    
                });

                
            /*
            * Keep Returns & Refunds filter results
            * synchronized after a decision.
            */
            if (recordId.startsWith('REF-')) {
                runTableFilter('returns-table');
            }


            /*
            * Keep Platform Policies filters
            * synchronized after a status change.
            */
            if (recordId.startsWith('POL-')) {
                runTableFilter('policies-table');
            }


            refreshIcons();
        };

        Object.entries(workflowStatuses).forEach(([recordId, status]) => {

            // Product Violations use their own dedicated state system.
            if (recordId.startsWith('VIO-')) {
                return;
            }

            // Returns & Refunds are now server/database backed.
            if (recordId.startsWith('REF-')) {
                return;
            }

            updateWorkflowRow(recordId, status);
        });

        // Initialize server-rendered Announcement modal actions
        document
            .querySelectorAll(
                '[data-announcement-modal]'
            )
            .forEach((modal) => {

                const announcementId =
                    modal.dataset.announcementId;

                if (!announcementId) return;


                const savedStatus =
                    workflowStatuses[
                        announcementId
                    ];


                const initialStatus =
                    savedStatus ||
                    modal
                        .querySelector(
                            '[data-announcement-status]'
                        )
                        ?.textContent
                        .trim() ||
                    'Draft';


                syncAnnouncementActions(
                    modal,
                    initialStatus
                );
            });

        // Initialize server-rendered Platform Policy modal actions
        document
            .querySelectorAll(
                '[data-policy-modal]'
            )
            .forEach((modal) => {

                const policyId =
                    modal.dataset.policyId;

                if (!policyId) return;


                const savedStatus =
                    workflowStatuses[
                        policyId
                    ];


                const initialStatus =
                    savedStatus ||
                    modal
                        .querySelector(
                            '[data-policy-status]'
                        )
                        ?.textContent
                        .trim() ||
                    'Draft';


                syncPolicyActions(
                    modal,
                    initialStatus
                );
            });

        // Initialize Returns & Refunds modal controls
        document
            .querySelectorAll('[data-return-modal]')
            .forEach((modal) => {

                const returnId =
                    modal.dataset.returnId;

                if (!returnId) return;

                const row = document.querySelector(
                    `tr[data-return-id="${returnId}"]`
                );

                const initialStatus =
                    row?.dataset.status ||
                    modal
                        .querySelector(
                            '[data-return-modal-status]'
                        )
                        ?.textContent
                        .trim() ||
                    'Under Review';

                syncReturnActions(
                    modal,
                    initialStatus
                );
            });

        const returnModalTriggers = new Map();
        let activeReturnModal = null;
        const returnModalFocusableSelector = [
            'a[href]',
            'button:not([disabled])',
            'input:not([disabled])',
            'select:not([disabled])',
            'textarea:not([disabled])',
            '[tabindex]:not([tabindex="-1"])',
        ].join(',');

        document.querySelectorAll('[data-open-modal^="return-case-"]').forEach((trigger) => {
            const modal = document.getElementById(trigger.dataset.openModal);
            if (!modal) return;

            returnModalTriggers.set(modal, trigger);
            trigger.addEventListener('click', () => {
                if (modal.hidden) return;
                activeReturnModal = modal;
                modal.querySelector('.return-review-dialog [data-close-modal]')?.focus();
            });
        });

        document.querySelectorAll('[data-return-modal]').forEach((modal) => {
            modal.querySelectorAll('[data-close-modal]').forEach((closeControl) => {
                closeControl.addEventListener('click', () => {
                    if (!modal.hidden) return;
                    returnModalTriggers.get(modal)?.focus();
                    if (activeReturnModal === modal) activeReturnModal = null;
                });
            });

            modal.addEventListener('keydown', (event) => {
                if (event.target.closest?.('[data-return-evidence-dialog][open]')) return;
                if (event.key !== 'Tab') return;

                const dialog = modal.querySelector('.return-review-dialog');
                const focusable = [...(dialog?.querySelectorAll(returnModalFocusableSelector) || [])]
                    .filter((element) => !element.hidden && element.getClientRects().length > 0);
                if (!focusable.length) {
                    event.preventDefault();
                    dialog?.focus();
                    return;
                }

                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog)) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            });
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape' || !activeReturnModal?.hidden) return;
            returnModalTriggers.get(activeReturnModal)?.focus();
            activeReturnModal = null;
        });

        document.querySelectorAll('[data-return-evidence-dialog]').forEach((dialog) => {
            const returnModal = dialog.closest('[data-return-modal]');
            const image = dialog.querySelector('[data-return-evidence-full]');
            const title = dialog.querySelector('[data-return-evidence-title]');
            let activeEvidenceTrigger = null;

            returnModal?.querySelectorAll('[data-return-evidence-preview]').forEach((trigger) => {
                trigger.addEventListener('click', () => {
                    if (!trigger.dataset.previewUrl || typeof dialog.showModal !== 'function') return;

                    activeEvidenceTrigger = trigger;
                    const name = trigger.dataset.previewName || 'Evidence image';
                    if (title) title.textContent = name;
                    if (image) {
                        image.src = trigger.dataset.previewUrl;
                        image.alt = name;
                    }
                    dialog.showModal();
                    dialog.querySelector('[data-return-evidence-close]')?.focus();
                });
            });

            dialog.querySelector('[data-return-evidence-close]')?.addEventListener('click', () => dialog.close());
            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) dialog.close();
            });
            dialog.addEventListener('close', () => {
                image?.removeAttribute('src');
                image?.removeAttribute('alt');
                activeEvidenceTrigger?.focus();
                activeEvidenceTrigger = null;
            });
        });

        document.querySelectorAll('[data-mock-action]').forEach((button) => {

            // Product Violations use their own dedicated state system.
            if (button.dataset.violationAction) {
                return;
            }

            const action = button.dataset.mockAction.toLowerCase();

            let status = null;

            if (action.includes('warning issued')) {
                status = 'Warning Issued';
            }

            else if (action.includes('marked for removal')) {
                status = 'Removal Required';
            }

            else if (action.includes('escalated for account review')) {
                status = 'Escalated';
            }


            const recordId =
                button
                    .closest('[data-modal]')
                    ?.querySelector('.eyebrow')
                    ?.textContent
                    .trim() || '';


            if (action.endsWith(' published.')) {
                status =
                    recordId.startsWith('POL-')
                        ? 'Active'
                        : 'Published';
            }

            else if (action.endsWith(' archived.')) {
                status = 'Archived';
            }


            if (!status) return;
            if (!/^[A-Z]+-\d+/i.test(recordId)) return;

            button.dataset.functionalAction = 'workflow-status';
            button.addEventListener('click', () => {
                workflowStatuses[recordId] = status;
                localStorage.setItem(WORKFLOW_STATUS_STORAGE_KEY, JSON.stringify(workflowStatuses));
                updateWorkflowRow(recordId, status);
                showToast(`${recordId} changed to ${status}.`, 'Workflow status saved');
            });
        });

        // Announcement and policy create/edit workflows
        const MANAGED_RECORDS_STORAGE_KEY = 'bearlyAdminManagedRecords';
        let managedRecords = (() => {
            try {
                return JSON.parse(localStorage.getItem(MANAGED_RECORDS_STORAGE_KEY) || '[]');
            } catch {
                return [];
            }
        })();
        const persistManagedRecords = () => {
            localStorage.setItem(MANAGED_RECORDS_STORAGE_KEY, JSON.stringify(managedRecords));
        };
        const recordModal = (type) => document.querySelector(`[data-modal="create-${type === 'announcement' ? 'announcement' : 'policy'}"]`);
        const setRecordFormValues = (type, record = {}) => {
            const modal = recordModal(type);
            if (!modal) return;
            modal.dataset.editingRecordId = record.id || '';
            modal.querySelectorAll('[data-record-field]').forEach((field) => {
                field.value = record[field.dataset.recordField] || '';
            });
            const heading = modal.querySelector('.modal-heading h2');
            if (heading) heading.textContent = `${record.id ? 'Edit' : 'Create'} ${type}`;
        };
        const collectRecordForm = (type, status) => {
            const modal = recordModal(type);

            if (!modal) return null;


            const record = {
                type,
                status,
                id:
                    modal.dataset.editingRecordId ||
                    '',
            };


            modal
                .querySelectorAll(
                    '[data-record-field]'
                )
                .forEach((field) => {

                    const key =
                        field.dataset.recordField;


                    /*
                    * The save button controls
                    * the final workflow status.
                    *
                    * Save Draft     → Draft
                    * Publish Policy → Active
                    */
                    if (
                        key === 'status' &&
                        status
                    ) {
                        return;
                    }


                    record[key] =
                        field.value.trim();
                });


            return record;
        };
        const ensureManagedAnnouncementModal = (record) => {
            if (record.type !== 'announcement') return null;

            const modalId =
                `managed-announcement-${record.id}`;

            let modal = document.querySelector(
                `[data-modal="${modalId}"]`
            );


            if (!modal) {
                modal = document.createElement('div');

                modal.className = 'modal-shell';

                modal.dataset.modal = modalId;
                modal.dataset.announcementModal = '';
                modal.dataset.announcementId = record.id;

                modal.hidden = true;


                modal.innerHTML = `
                    <button
                        type="button"
                        class="modal-backdrop"
                        data-close-modal
                        aria-label="Close announcement"
                    ></button>


                    <section
                        class="modal-card modal-wide"
                        role="dialog"
                        aria-modal="true"
                    >

                        <div class="modal-heading">

                            <div>

                                <span class="eyebrow">
                                    ${escapeReportHtml(record.id)}
                                </span>

                                <h2 data-announcement-title></h2>

                            </div>


                            <button
                                type="button"
                                class="icon-button"
                                data-close-modal
                                aria-label="Close"
                            >
                                <i data-lucide="x"></i>
                            </button>

                        </div>


                        <div class="review-details">

                            <h3 class="section-subtitle">
                                Announcement information
                            </h3>


                            <div class="detail-grid">

                                <div>
                                    <span>Audience</span>
                                    <strong data-announcement-audience></strong>
                                </div>


                                <div>
                                    <span>Status</span>
                                    <strong data-announcement-status></strong>
                                </div>


                                <div>
                                    <span>Author</span>
                                    <strong data-announcement-author></strong>
                                </div>


                                <div>
                                    <span>Publish date</span>
                                    <strong data-announcement-date></strong>
                                </div>


                                <div>
                                    <span>Publish time</span>
                                    <strong data-announcement-time></strong>
                                </div>

                            </div>


                            <div class="detail-note">

                                <span>
                                    Announcement message
                                </span>

                                <p data-announcement-message></p>

                            </div>

                        </div>


                        <div
                            class="modal-footer decision-footer"
                            data-announcement-actions
                        >

                            <button
                                type="button"
                                class="button button-primary"
                                data-announcement-action="publish"
                            >
                                <i data-lucide="send"></i>
                                Publish Now
                            </button>


                            <button
                                type="button"
                                class="button button-secondary"
                                data-announcement-action="edit"
                            >
                                <i data-lucide="pencil"></i>
                                Edit
                            </button>


                            <button
                                type="button"
                                class="button button-danger-soft"
                                data-announcement-action="archive"
                            >
                                <i data-lucide="archive"></i>
                                Archive
                            </button>


                            <button
                                type="button"
                                class="button button-secondary"
                                data-announcement-state-indicator
                                hidden
                                disabled
                            >
                                <i data-lucide="circle-check"></i>

                                <span data-announcement-state-label>
                                    Status updated
                                </span>
                            </button>

                        </div>

                    </section>
                `;


                document.body.appendChild(modal);


                /*
                * Dynamic modal close controls.
                */
                modal
                    .querySelectorAll('[data-close-modal]')
                    .forEach((button) => {

                        button.addEventListener(
                            'click',
                            () => {
                                modal.hidden = true;
                            }
                        );

                    });


                /*
                * Edit latest version of this announcement.
                */
                modal
                    .querySelector(
                        '[data-announcement-action="edit"]'
                    )
                    ?.addEventListener('click', () => {

                        const currentRecord =
                            managedRecords.find(
                                (item) =>
                                    item.id ===
                                    modal.dataset.announcementId
                            );

                        if (!currentRecord) return;


                        setRecordFormValues(
                            'announcement',
                            currentRecord
                        );


                        modal.hidden = true;


                        const editor =
                            recordModal(
                                'announcement'
                            );

                        if (editor) {
                            editor.hidden = false;
                        }
                    });


                /*
                * Publish latest version.
                */
                modal
                    .querySelector(
                        '[data-announcement-action="publish"]'
                    )
                    ?.addEventListener('click', () => {

                        const currentRecord =
                            managedRecords.find(
                                (item) =>
                                    item.id ===
                                    modal.dataset.announcementId
                            );

                        if (!currentRecord) return;


                        currentRecord.status =
                            'Published';


                        persistManagedRecords();

                        renderManagedRecord(
                            currentRecord
                        );


                        showToast(
                            `${currentRecord.id} changed to Published.`,
                            'Announcement published'
                        );
                    });


                /*
                * Archive latest version.
                */
                modal
                    .querySelector(
                        '[data-announcement-action="archive"]'
                    )
                    ?.addEventListener('click', () => {

                        const currentRecord =
                            managedRecords.find(
                                (item) =>
                                    item.id ===
                                    modal.dataset.announcementId
                            );

                        if (!currentRecord) return;


                        currentRecord.status =
                            'Archived';


                        persistManagedRecords();

                        renderManagedRecord(
                            currentRecord
                        );


                        showToast(
                            `${currentRecord.id} changed to Archived.`,
                            'Announcement archived'
                        );
                    });
            }


            return modal;
        };

        const ensureManagedPolicyModal = (record) => {
            if (record.type !== 'policy') return null;

            const modalId =
                `managed-policy-${record.id}`;

            let modal =
                document.querySelector(
                    `[data-modal="${modalId}"]`
                );


            if (!modal) {
                modal =
                    document.createElement('div');

                modal.className =
                    'modal-shell';

                modal.dataset.modal =
                    modalId;

                modal.dataset.policyModal =
                    '';

                modal.dataset.policyId =
                    record.id;

                modal.hidden =
                    true;


                modal.innerHTML = `
                    <button
                        type="button"
                        class="modal-backdrop"
                        data-close-modal
                        aria-label="Close policy"
                    ></button>

                    <section
                        class="modal-card modal-wide"
                        role="dialog"
                        aria-modal="true"
                    >

                        <div class="modal-heading">

                            <div>
                                <span class="eyebrow">
                                    ${escapeReportHtml(record.id)}
                                </span>

                                <h2 data-policy-title></h2>
                            </div>

                            <button
                                type="button"
                                class="icon-button"
                                data-close-modal
                                aria-label="Close"
                            >
                                <i data-lucide="x"></i>
                            </button>

                        </div>


                        <div class="review-details">

                            <h3 class="section-subtitle">
                                Policy information
                            </h3>


                            <div class="detail-grid">

                                <div>
                                    <span>Category</span>
                                    <strong data-policy-category></strong>
                                </div>

                                <div>
                                    <span>Version</span>
                                    <strong data-policy-version></strong>
                                </div>

                                <div>
                                    <span>Status</span>
                                    <strong data-policy-status></strong>
                                </div>

                                <div>
                                    <span>Last updated</span>
                                    <strong data-policy-updated></strong>
                                </div>

                                <div>
                                    <span>Updated by</span>
                                    <strong data-policy-author></strong>
                                </div>

                            </div>


                            <div class="detail-note">
                                <span>Summary</span>
                                <p data-policy-summary></p>
                            </div>


                            <div class="detail-note">
                                <span>Policy content</span>
                                <p data-policy-body></p>
                            </div>

                        </div>


                        <div
                            class="modal-footer decision-footer"
                            data-policy-actions
                        >

                            <button
                                type="button"
                                class="button button-secondary"
                                data-policy-action="edit"
                            >
                                <i data-lucide="pencil"></i>
                                Edit Policy
                            </button>


                            <button
                                type="button"
                                class="button button-primary"
                                data-policy-action="publish"
                            >
                                <i data-lucide="send"></i>
                                Publish
                            </button>


                            <button
                                type="button"
                                class="button button-danger-soft"
                                data-policy-action="archive"
                            >
                                <i data-lucide="archive"></i>
                                Archive
                            </button>


                            <button
                                type="button"
                                class="button button-secondary"
                                data-policy-state-indicator
                                hidden
                                disabled
                            >
                                <i data-lucide="circle-check"></i>

                                <span data-policy-state-label>
                                    Policy updated
                                </span>
                            </button>

                        </div>

                    </section>
                `;


                document.body.appendChild(
                    modal
                );


                /*
                * Dynamic modal close controls
                */
                modal
                    .querySelectorAll(
                        '[data-close-modal]'
                    )
                    .forEach((button) => {

                        button.addEventListener(
                            'click',
                            () => {
                                modal.hidden =
                                    true;
                            }
                        );

                    });


                /*
                * Edit policy
                */
                modal
                    .querySelector(
                        '[data-policy-action="edit"]'
                    )
                    ?.addEventListener(
                        'click',
                        () => {

                            const currentRecord =
                                managedRecords.find(
                                    (item) =>
                                        item.id ===
                                        modal.dataset.policyId
                                );

                            if (!currentRecord) return;


                            setRecordFormValues(
                                'policy',
                                currentRecord
                            );


                            modal.hidden =
                                true;


                            const editor =
                                recordModal(
                                    'policy'
                                );

                            if (editor) {
                                editor.hidden =
                                    false;
                            }
                        }
                    );


                /*
                * Publish policy
                */
                modal
                    .querySelector(
                        '[data-policy-action="publish"]'
                    )
                    ?.addEventListener(
                        'click',
                        () => {

                            const currentRecord =
                                managedRecords.find(
                                    (item) =>
                                        item.id ===
                                        modal.dataset.policyId
                                );

                            if (!currentRecord) return;


                            currentRecord.status =
                                'Active';


                            persistManagedRecords();

                            renderManagedRecord(
                                currentRecord
                            );


                            showToast(
                                `${currentRecord.id} changed to Active.`,
                                'Policy published'
                            );
                        }
                    );


                /*
                * Archive policy
                */
                modal
                    .querySelector(
                        '[data-policy-action="archive"]'
                    )
                    ?.addEventListener(
                        'click',
                        () => {

                            const currentRecord =
                                managedRecords.find(
                                    (item) =>
                                        item.id ===
                                        modal.dataset.policyId
                                );

                            if (!currentRecord) return;


                            currentRecord.status =
                                'Archived';


                            persistManagedRecords();

                            renderManagedRecord(
                                currentRecord
                            );


                            showToast(
                                `${currentRecord.id} archived.`,
                                'Policy archived'
                            );
                        }
                    );
            }


            return modal;
        };
        const recordBadge = (status) =>
            `status-badge ${workflowBadgeClass(status)}`;


        const renderManagedRecord = (record) => {
            const target = document.querySelector(
                `[data-record-rows="${record.type}"]`
            );

            if (!target) return;


            let row = [
                ...target.querySelectorAll(
                    'tr[data-table-row]'
                )
            ].find((item) =>
                (item.dataset.search || '')
                    .includes(
                        record.id.toLowerCase()
                    )
            );


            if (!row) {
                row = document.createElement('tr');
                row.dataset.tableRow = '';

                target.prepend(row);
            }


            row.dataset.status =
                record.status;


            if (record.type === 'announcement') {

                row.dataset.audience =
                    record.audience;

                row.dataset.search =
                    `${record.id} ${record.title} ${record.audience} ${record.message}`
                        .toLowerCase();


                row.innerHTML = `
                    <td>

                        <div class="identity-cell">

                            <span class="avatar avatar-soft">
                                <i data-lucide="megaphone"></i>
                            </span>

                            <div class="table-primary-secondary">

                                <strong>
                                    ${escapeReportHtml(record.title)}
                                </strong>

                                <small>
                                    ${escapeReportHtml(record.id)}
                                </small>

                            </div>

                        </div>

                    </td>


                    <td>
                        ${escapeReportHtml(record.audience)}
                    </td>


                    <td>
                        Current Admin
                    </td>


                    <td>

                        <div class="table-primary-secondary">

                            <strong>
                                ${escapeReportHtml(
                                    record.date ||
                                    'Not scheduled'
                                )}
                            </strong>

                            ${
                                record.time
                                    ? `
                                        <small>
                                            ${escapeReportHtml(record.time)}
                                        </small>
                                    `
                                    : ''
                            }

                        </div>

                    </td>


                    <td>

                        <span class="${recordBadge(record.status)}">
                            ${escapeReportHtml(record.status)}
                        </span>

                    </td>


                    <td class="align-right">

                        <button
                            class="button button-ghost button-small"
                            type="button"
                            data-open-managed-announcement="${escapeReportHtml(record.id)}"
                        >
                            <i data-lucide="eye"></i>
                            View
                        </button>

                    </td>
                `;


                /*
                * Create the View modal if this is
                * a newly created announcement.
                */
                ensureManagedAnnouncementModal(
                    record
                );


                /*
                * Synchronize the latest announcement
                * information into its View modal.
                */
                syncAnnouncementModal(
                    record.id,
                    {
                        title:
                            record.title,

                        audience:
                            record.audience,

                        status:
                            record.status,

                        author:
                            'Current Admin',

                        date:
                            record.date || '—',

                        time:
                            record.time || '—',

                        message:
                            record.message,
                    }
                );

            } else {

                row.dataset.category =
                    record.category;

                row.dataset.search =
                    `${record.id} ${record.title} ${record.category} ${record.version} ${record.summary}`
                        .toLowerCase();


                const updatedDate =
                    record.updated ||
                    new Date().toLocaleDateString(
                        'en-PH',
                        {
                            month: 'short',
                            day: 'numeric',
                            year: 'numeric',
                        }
                    );


                row.innerHTML = `
                    <td>

                        <div class="identity-cell">

                            <span class="avatar avatar-soft">
                                <i data-lucide="file-text"></i>
                            </span>

                            <div class="table-primary-secondary">

                                <strong>
                                    ${escapeReportHtml(record.title)}
                                </strong>

                                <small>
                                    ${escapeReportHtml(record.id)}
                                </small>

                            </div>

                        </div>

                    </td>


                    <td>
                        ${escapeReportHtml(record.category)}
                    </td>


                    <td>
                        <strong>
                            ${escapeReportHtml(
                                record.version || 'v1.0'
                            )}
                        </strong>
                    </td>


                    <td>
                        Current Admin
                    </td>


                    <td>
                        ${escapeReportHtml(updatedDate)}
                    </td>


                    <td>
                        <span class="${recordBadge(record.status)}">
                            ${escapeReportHtml(record.status)}
                        </span>
                    </td>


                    <td class="align-right">

                        <button
                            class="button button-ghost button-small"
                            type="button"
                            data-open-managed-policy="${escapeReportHtml(record.id)}"
                        >
                            <i data-lucide="eye"></i>
                            View
                        </button>

                    </td>
                `;


                ensureManagedPolicyModal(
                    record
                );


                syncPolicyModal(
                    record.id,
                    {
                        title:
                            record.title,

                        category:
                            record.category,

                        version:
                            record.version || 'v1.0',

                        status:
                            record.status,

                        updated:
                            updatedDate,

                        author:
                            'Current Admin',

                        summary:
                            record.summary,

                        body:
                            record.body,
                    }
                );
            }


            refreshIcons();
        };

        /*
        * Open dynamically created Announcement View modal.
        */
        document.addEventListener(
            'click',
            (event) => {

                const button =
                    event.target.closest(
                        '[data-open-managed-announcement]'
                    );

                if (!button) return;


                const recordId =
                    button.dataset
                        .openManagedAnnouncement;


                const modal =
                    document.querySelector(
                        `[data-announcement-modal][data-announcement-id="${recordId}"]`
                    );


                if (!modal) return;


                modal.hidden = false;

                refreshIcons();
            }
        );

        /*
        * Open dynamically created Policy View modal.
        */
        document.addEventListener(
            'click',
            (event) => {

                const button =
                    event.target.closest(
                        '[data-open-managed-policy]'
                    );

                if (!button) return;


                const recordId =
                    button.dataset
                        .openManagedPolicy;


                const modal =
                    document.querySelector(
                        `[data-policy-modal][data-policy-id="${recordId}"]`
                    );


                if (!modal) return;


                modal.hidden = false;

                refreshIcons();
            }
        );

        managedRecords.forEach(renderManagedRecord);

        document.querySelectorAll('[data-open-modal="create-announcement"], [data-open-modal="create-policy"]').forEach((button) => {
            button.addEventListener('click', () => {
                setRecordFormValues(button.dataset.openModal === 'create-announcement' ? 'announcement' : 'policy');
            });
        });

        const syncPolicyRecordCount = () => {
            const counter =
                document.querySelector(
                    '[data-policy-record-count]'
                );

            const table =
                document.getElementById(
                    'policies-table'
                );

            if (!counter || !table) return;

            counter.textContent =
                table.querySelectorAll(
                    'tbody tr[data-table-row]'
                ).length;
        };

        syncPolicyRecordCount();

        document.querySelectorAll('[data-mock-action]').forEach((button) => {
            if (!button.dataset.mockAction.toLowerCase().includes('opened for editing')) return;

            const sourceModal = button.closest('[data-modal]');

            const recordId =
                sourceModal?.querySelector('.eyebrow')
                    ?.textContent
                    .trim() || '';

            const type =
                recordId.startsWith('ANN-')
                    ? 'announcement'
                    : recordId.startsWith('POL-')
                        ? 'policy'
                        : '';

            if (!type) return;

            button.dataset.functionalAction =
                'record-edit';

            button.addEventListener('click', () => {

                const details =
                    Object.fromEntries(
                        [...sourceModal.querySelectorAll('.detail-grid > div')]
                            .map((item) => [
                                item.querySelector('span')
                                    ?.textContent
                                    .trim()
                                    .toLowerCase(),

                                item.querySelector('strong')
                                    ?.textContent
                                    .trim() || '',
                            ])
                    );

                const notes =
                    [...sourceModal.querySelectorAll('.detail-note p')]
                        .map((item) =>
                            item.textContent.trim()
                        );


                // REPLACE FROM HERE
                const record =
                    type === 'announcement'
                        ? {
                            id: recordId,

                            title:
                                sourceModal
                                    .querySelector('[data-announcement-title]')
                                    ?.textContent
                                    .trim() || '',

                            audience:
                                sourceModal
                                    .querySelector('[data-announcement-audience]')
                                    ?.textContent
                                    .trim() || 'All Users',

                            date:
                                sourceModal
                                    .querySelector('[data-announcement-date]')
                                    ?.textContent
                                    .trim() || '',

                            time:
                                sourceModal
                                    .querySelector('[data-announcement-time]')
                                    ?.textContent
                                    .trim() || '',

                            message:
                                sourceModal
                                    .querySelector('[data-announcement-message]')
                                    ?.textContent
                                    .trim() || '',
                        }

                        : {
                            id: recordId,

                            title:
                                sourceModal
                                    .querySelector('.modal-heading h2')
                                    ?.textContent
                                    .trim(),

                            category:
                                details.category,

                            version:
                                details.version,

                            status:
                                details.status,

                            summary:
                                notes[0] || '',

                            body:
                                notes[1] || '',
                        };
                // REPLACE UNTIL HERE


                setRecordFormValues(
                    type,
                    record
                );

                sourceModal.hidden =
                    true;

                const editor =
                    recordModal(type);

                if (editor) {
                    editor.hidden =
                        false;
                }
            });
        });

        document.querySelectorAll('[data-save-record]').forEach((button) => {
            button.dataset.functionalAction = 'record-save';
            button.addEventListener('click', () => {
                const type = button.dataset.saveRecord;
                const record = collectRecordForm(type, button.dataset.recordStatus);
                if (!record?.title || (type === 'announcement' && !record.message) || (type === 'policy' && !record.body)) {
                    showToast('Complete the title and main content before saving.', 'Record not saved');
                    return;
                }
                if (!record.id) record.id = `${type === 'announcement' ? 'ANN' : 'POL'}-D${Date.now().toString().slice(-6)}`;
                managedRecords = managedRecords.filter((item) => item.id !== record.id);
                managedRecords.push(record);
                persistManagedRecords();
                renderManagedRecord(record);
                syncPolicyRecordCount();
                closeModal(recordModal(type));
                showToast(`${record.id} saved as ${record.status}.`, `${type === 'announcement' ? 'Announcement' : 'Policy'} saved`);
            });
        });

        document.addEventListener('click', (event) => {
            const editButton = event.target.closest('[data-edit-managed-record]');
            const archiveButton = event.target.closest('[data-archive-managed-record]');
            const id = editButton?.dataset.editManagedRecord || archiveButton?.dataset.archiveManagedRecord;
            if (!id) return;
            const record = managedRecords.find((item) => item.id === id);
            if (!record) return;

            if (editButton) {
                setRecordFormValues(record.type, record);
                const modal = recordModal(record.type);
                if (modal) modal.hidden = false;
                return;
            }

            record.status = 'Archived';
            persistManagedRecords();
            renderManagedRecord(record);
            showToast(`${record.id} archived.`, 'Record updated');
        });

        // Commission calculator
        const commissionInput = document.querySelector('[data-commission-input]');
        const commissionOutput = document.querySelector('[data-commission-output]');
        const sellerNet = document.querySelector('[data-seller-net]');
        const commissionRate = Number(document.querySelector('[data-commission-rate]')?.dataset.commissionRate || 10) / 100;
        const formatPeso = (number) => `₱${Number(number).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        const calculateCommission = () => {
            const gross = Math.max(0, Number(commissionInput?.value || 0));
            if (commissionOutput) commissionOutput.textContent = formatPeso(gross * commissionRate);
            if (sellerNet) sellerNet.textContent = formatPeso(gross * (1 - commissionRate));
        };
        commissionInput?.addEventListener('input', calculateCommission);

        // Commission ledger date range filtering
        const commissionDateStart = document.querySelector('[data-commission-date-start]');
        const commissionDateEnd = document.querySelector('[data-commission-date-end]');
        const commissionDateApply = document.querySelector('[data-commission-date-apply]');
        const commissionLedgerRows = [...document.querySelectorAll('[data-commission-ledger-row]')];
        const commissionEmpty = document.querySelector('[data-commission-empty]');

        commissionDateApply?.addEventListener('click', () => {
            const start = commissionDateStart?.value || '';
            const end = commissionDateEnd?.value || '';

            if (!start || !end) {
                showToast('Choose both a start date and an end date.', 'Date range required');
                return;
            }

            if (start > end) {
                showToast('The start date cannot be later than the end date.', 'Invalid date range');
                return;
            }

            let visibleRows = 0;

            commissionLedgerRows.forEach((row) => {
                const rowDate = row.dataset.commissionDate || '';
                const isVisible = rowDate >= start && rowDate <= end;
                row.hidden = !isVisible;

                if (isVisible) visibleRows += 1;
            });

            if (commissionEmpty) {
                commissionEmpty.hidden = visibleRows !== 0;
            }

            showToast(
                visibleRows === 1
                    ? '1 commission transaction matches the selected date range.'
                    : `${visibleRows} commission transactions match the selected date range.`,
                'Commission ledger filtered'
            );
        });
        
        // Platform settings configuration + persistence
        const PLATFORM_CONFIG_STORAGE_KEY =
            'bearlyPlatformConfigPreview';


        const marketplaceNameInput =
            document.querySelector(
                '[data-setting-marketplace-name]'
            );

        const commissionRateInput =
            document.querySelector(
                '[data-setting-commission-rate]'
            );

        const marketplaceDescriptionInput =
            document.querySelector(
                '[data-setting-marketplace-description]'
            );

        const platformActiveInput =
            document.querySelector(
                '[data-setting-platform-active]'
            );

        const maintenanceModeInput =
            document.querySelector(
                '[data-setting-maintenance-mode]'
            );

        const registrationInputs = [
            ...document.querySelectorAll(
                '[data-setting-registration]'
            ),
        ];

        const cancellationHoursInput =
            document.querySelector(
                '[data-setting-cancellation-hours]'
            );

        const settlementPeriodInput =
            document.querySelector(
                '[data-setting-settlement-period]'
            );

        const platformSettingsSaveButton =
            document.querySelector(
                '[data-settings-save]'
            );

        const platformSettingsResetConfirmButton =
            document.querySelector(
                '[data-settings-reset-confirm]'
            );


        /*
        * Settings status UI
        */
        const settingsState =
            document.querySelector(
                '[data-settings-state]'
            );

        const settingsStateTitle =
            document.querySelector(
                '[data-settings-state-title]'
            );

        const settingsStateMessage =
            document.querySelector(
                '[data-settings-state-message]'
            );

        const settingsStateIcon =
            settingsState?.querySelector(
                '.settings-save-state-icon i'
            );


        /*
        * Platform availability UI
        */
        const availabilityState =
            document.querySelector(
                '[data-platform-availability-state]'
            );

        const availabilityMessage =
            document.querySelector(
                '[data-platform-availability-message]'
            );

        const availabilityIcon =
            document.querySelector(
                '[data-platform-availability-icon]'
            );


        const PLATFORM_CONFIG_DEFAULTS = {
            marketplaceName:
                'Bearly',

            commissionRate:
                10,

            marketplaceDescription:
                'Bearly is an e-commerce marketplace connecting Buyers, Sellers, Logistics Centers, and Riders.',

            platformActive:
                true,

            maintenanceMode:
                false,

            registrations: {
                buyer: true,
                seller: true,
                logistics: true,
                rider: true,
            },

            cancellationHours:
                24,

            settlementPeriod:
                '7',
        };


        /*
        * Safely clone the default object.
        */
        const clonePlatformDefaults = () =>
            JSON.parse(
                JSON.stringify(
                    PLATFORM_CONFIG_DEFAULTS
                )
            );


        const getPlatformConfig = () => {
            try {
                const raw =
                    localStorage.getItem(
                        PLATFORM_CONFIG_STORAGE_KEY
                    );

                if (!raw) {
                    return clonePlatformDefaults();
                }

                const saved =
                    JSON.parse(raw);

                return {
                    ...clonePlatformDefaults(),
                    ...saved,

                    registrations: {
                        ...PLATFORM_CONFIG_DEFAULTS
                            .registrations,

                        ...(
                            saved.registrations ||
                            {}
                        ),
                    },
                };
            } catch {
                return clonePlatformDefaults();
            }
        };


        const applyPlatformConfig = (
            config
        ) => {
            if (marketplaceNameInput) {
                marketplaceNameInput.value =
                    config.marketplaceName;
            }

            if (commissionRateInput) {
                commissionRateInput.value =
                    config.commissionRate;
            }

            if (
                marketplaceDescriptionInput
            ) {
                marketplaceDescriptionInput.value =
                    config.marketplaceDescription;
            }

            if (platformActiveInput) {
                platformActiveInput.checked =
                    Boolean(
                        config.platformActive
                    );
            }

            if (maintenanceModeInput) {
                maintenanceModeInput.checked =
                    Boolean(
                        config.maintenanceMode
                    );
            }


            registrationInputs.forEach(
                (input) => {

                    const role =
                        input.dataset
                            .settingRegistration;

                    input.checked =
                        config.registrations?.[
                            role
                        ] !== false;
                }
            );


            if (cancellationHoursInput) {
                cancellationHoursInput.value =
                    config.cancellationHours;
            }

            if (settlementPeriodInput) {
                settlementPeriodInput.value =
                    String(
                        config.settlementPeriod
                    );
            }
        };


        const collectPlatformConfig = () => {
            const registrations = {};

            registrationInputs.forEach(
                (input) => {

                    registrations[
                        input.dataset
                            .settingRegistration
                    ] =
                        input.checked;
                }
            );


            return {
                marketplaceName:
                    marketplaceNameInput
                        ?.value
                        .trim() ||
                    '',

                commissionRate:
                    Number(
                        commissionRateInput
                            ?.value ||
                        0
                    ),

                marketplaceDescription:
                    marketplaceDescriptionInput
                        ?.value
                        .trim() ||
                    '',

                platformActive:
                    platformActiveInput
                        ?.checked ??
                    true,

                maintenanceMode:
                    maintenanceModeInput
                        ?.checked ??
                    false,

                registrations,

                cancellationHours:
                    Number(
                        cancellationHoursInput
                            ?.value ||
                        24
                    ),

                settlementPeriod:
                    settlementPeriodInput
                        ?.value ||
                    '7',
            };
        };


        /*
        * Creates a predictable comparison
        * value for dirty-state detection.
        */
        const platformConfigSignature = (
            config
        ) =>
            JSON.stringify({
                marketplaceName:
                    config.marketplaceName,

                commissionRate:
                    Number(
                        config.commissionRate
                    ),

                marketplaceDescription:
                    config.marketplaceDescription,

                platformActive:
                    Boolean(
                        config.platformActive
                    ),

                maintenanceMode:
                    Boolean(
                        config.maintenanceMode
                    ),

                registrations: {
                    buyer:
                        Boolean(
                            config.registrations
                                ?.buyer
                        ),

                    seller:
                        Boolean(
                            config.registrations
                                ?.seller
                        ),

                    logistics:
                        Boolean(
                            config.registrations
                                ?.logistics
                        ),

                    rider:
                        Boolean(
                            config.registrations
                                ?.rider
                        ),
                },

                cancellationHours:
                    Number(
                        config.cancellationHours
                    ),

                settlementPeriod:
                    String(
                        config.settlementPeriod
                    ),
            });


        /*
        * Configuration state indicator
        *
        * saved
        * dirty
        * default
        */
        const setPlatformSettingsState = (
            state
        ) => {
            if (settingsState) {
                settingsState.dataset.state =
                    state;
            }


            if (state === 'dirty') {
                if (settingsStateTitle) {
                    settingsStateTitle.textContent =
                        'Unsaved changes';
                }

                if (settingsStateMessage) {
                    settingsStateMessage.textContent =
                        'Save your changes before leaving this page.';
                }

                if (settingsStateIcon) {
                    settingsStateIcon.setAttribute(
                        'data-lucide',
                        'circle-alert'
                    );
                }

                refreshIcons();

                return;
            }


            if (state === 'default') {
                if (settingsStateTitle) {
                    settingsStateTitle.textContent =
                        'Defaults restored';
                }

                if (settingsStateMessage) {
                    settingsStateMessage.textContent =
                        'Platform settings were restored to their default configuration.';
                }

                if (settingsStateIcon) {
                    settingsStateIcon.setAttribute(
                        'data-lucide',
                        'rotate-ccw'
                    );
                }

                refreshIcons();

                return;
            }


            if (settingsStateTitle) {
                settingsStateTitle.textContent =
                    'Configuration saved';
            }

            if (settingsStateMessage) {
                settingsStateMessage.textContent =
                    'Current settings match the saved platform configuration.';
            }

            if (settingsStateIcon) {
                settingsStateIcon.setAttribute(
                    'data-lucide',
                    'circle-check'
                );
            }

            refreshIcons();
        };


        /*
        * Live marketplace availability
        * feedback.
        */
        const syncPlatformAvailability = () => {
            if (
                !availabilityState ||
                !availabilityMessage
            ) {
                return;
            }


            /*
            * Maintenance takes precedence.
            */
            if (
                maintenanceModeInput
                    ?.checked
            ) {
                availabilityState.dataset.state =
                    'maintenance';

                availabilityMessage.textContent =
                    'Marketplace access is restricted by Maintenance Mode.';

                if (availabilityIcon) {
                    availabilityIcon.setAttribute(
                        'data-lucide',
                        'triangle-alert'
                    );
                }

                refreshIcons();

                return;
            }


            /*
            * Marketplace manually disabled.
            */
            if (
                platformActiveInput &&
                !platformActiveInput.checked
            ) {
                availabilityState.dataset.state =
                    'inactive';

                availabilityMessage.textContent =
                    'Marketplace operations are currently disabled.';

                if (availabilityIcon) {
                    availabilityIcon.setAttribute(
                        'data-lucide',
                        'circle-off'
                    );
                }

                refreshIcons();

                return;
            }


            /*
            * Normal operations.
            */
            availabilityState.dataset.state =
                'active';

            availabilityMessage.textContent =
                'Marketplace is available to users.';

            if (availabilityIcon) {
                availabilityIcon.setAttribute(
                    'data-lucide',
                    'circle-check'
                );
            }

            refreshIcons();
        };


        let platformConfig =
            getPlatformConfig();

        let platformSettingsStateMode =
            'saved';


        applyPlatformConfig(
            platformConfig
        );

        syncPlatformAvailability();

        setPlatformSettingsState(
            'saved'
        );


        /*
        * Dirty-state detection
        */
        const syncPlatformDirtyState = () => {
            const current =
                collectPlatformConfig();

            const isDirty =
                platformConfigSignature(
                    current
                ) !==
                platformConfigSignature(
                    platformConfig
                );


            if (isDirty) {
                platformSettingsStateMode =
                    'dirty';

                setPlatformSettingsState(
                    'dirty'
                );

                return;
            }


            /*
            * Preserve "Defaults restored"
            * when reset was the latest action.
            */
            setPlatformSettingsState(
                platformSettingsStateMode ===
                    'default'
                    ? 'default'
                    : 'saved'
            );
        };


        /*
        * Text / number inputs
        */
        [
            marketplaceNameInput,
            commissionRateInput,
            marketplaceDescriptionInput,
            cancellationHoursInput,
        ]
            .filter(Boolean)
            .forEach((input) => {

                input.addEventListener(
                    'input',
                    syncPlatformDirtyState
                );

            });


        /*
        * Registration toggles
        */
        registrationInputs.forEach(
            (input) => {

                input.addEventListener(
                    'change',
                    syncPlatformDirtyState
                );

            }
        );


        /*
        * Settlement period
        */
        settlementPeriodInput
            ?.addEventListener(
                'change',
                syncPlatformDirtyState
            );


        /*
        * Marketplace operations and
        * Maintenance Mode should never
        * both be enabled.
        */
        platformActiveInput
            ?.addEventListener(
                'change',
                () => {

                    if (
                        platformActiveInput.checked &&
                        maintenanceModeInput
                    ) {
                        maintenanceModeInput.checked =
                            false;
                    }


                    syncPlatformAvailability();

                    syncPlatformDirtyState();
                }
            );


        maintenanceModeInput
            ?.addEventListener(
                'change',
                () => {

                    if (
                        maintenanceModeInput.checked &&
                        platformActiveInput
                    ) {
                        platformActiveInput.checked =
                            false;
                    }


                    syncPlatformAvailability();

                    syncPlatformDirtyState();
                }
            );


        /*
        * Save settings
        */
        platformSettingsSaveButton
            ?.addEventListener(
                'click',
                () => {

                    const config =
                        collectPlatformConfig();


                    if (!config.marketplaceName) {
                        showToast(
                            'Enter a marketplace name.',
                            'Settings not saved'
                        );

                        marketplaceNameInput
                            ?.focus();

                        return;
                    }


                    if (
                        !Number.isFinite(
                            config.commissionRate
                        ) ||
                        config.commissionRate < 0 ||
                        config.commissionRate > 100
                    ) {
                        showToast(
                            'Commission rate must be between 0% and 100%.',
                            'Settings not saved'
                        );

                        commissionRateInput
                            ?.focus();

                        return;
                    }


                    if (
                        !Number.isFinite(
                            config.cancellationHours
                        ) ||
                        config.cancellationHours < 1
                    ) {
                        showToast(
                            'Cancellation window must be at least 1 hour.',
                            'Settings not saved'
                        );

                        cancellationHoursInput
                            ?.focus();

                        return;
                    }


                    platformConfig =
                        config;

                    platformSettingsStateMode =
                        'saved';


                    localStorage.setItem(
                        PLATFORM_CONFIG_STORAGE_KEY,
                        JSON.stringify(
                            platformConfig
                        )
                    );


                    syncPlatformAvailability();

                    setPlatformSettingsState(
                        'saved'
                    );


                    showToast(
                        'Platform configuration was saved.',
                        'Settings updated'
                    );
                }
            );


        /*
        * Confirm reset to defaults.
        */
        platformSettingsResetConfirmButton
            ?.addEventListener(
                'click',
                () => {

                    localStorage.removeItem(
                        PLATFORM_CONFIG_STORAGE_KEY
                    );


                    platformConfig =
                        clonePlatformDefaults();


                    platformSettingsStateMode =
                        'default';


                    applyPlatformConfig(
                        platformConfig
                    );


                    syncPlatformAvailability();

                    setPlatformSettingsState(
                        'default'
                    );


                    closeModal(
                        document.querySelector(
                            '[data-modal="reset-platform-settings"]'
                        )
                    );


                    showToast(
                        'Platform settings were restored to their defaults.',
                        'Settings reset'
                    );
                }
            );
       

        // Dispute queue search + database-backed master-detail workspace
        const disputeSearch =
            document.querySelector('[data-dispute-search]');

        const disputeDataNode =
            document.getElementById('dispute-preview-data');

        let disputeData = {};

        try {
            disputeData = disputeDataNode
                ? JSON.parse(disputeDataNode.textContent)
                : {};
        } catch {
            disputeData = {};
        }


        const csrfToken =
            document.querySelector(
                'meta[name="csrf-token"]'
            )?.content || '';


        const setDisputeText = (selector, value) => {
            const el = document.querySelector(selector);

            if (el) {
                el.textContent = value ?? '';
            }
        };


        const escapeDisputeHtml = (value = '') =>
            String(value)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');


        const renderEvidence = (items = []) => {
            const box =
                document.querySelector(
                    '[data-dispute-evidence]'
                );

            if (!box) return;


            if (!items.length) {
                box.innerHTML = `
                    <div class="table-empty">
                        <i data-lucide="file-x"></i>

                        <strong>
                            No evidence submitted
                        </strong>

                        <span>
                            Supporting files will appear here
                            once participants submit evidence.
                        </span>
                    </div>
                `;

                refreshIcons();

                return;
            }


            box.innerHTML = items
                .map(
                    (item) => `
                        <button
                            type="button"
                            class="evidence-card${
                                item.preview_url ? ' has-preview' : ''
                            }"
                            data-evidence-id="${escapeDisputeHtml(
                                item.id
                            )}"
                        >
                            <span>
                                ${item.preview_url
                                    ? `<img src="${escapeDisputeHtml(
                                          item.preview_url
                                      )}" alt="Preview of ${escapeDisputeHtml(
                                          item.label
                                      )}">`
                                    : '<i data-lucide="file-text"></i>'}
                            </span>

                            <div>
                                <strong>
                                    ${escapeDisputeHtml(
                                        item.label
                                    )}
                                </strong>

                                <small>
                                    ${escapeDisputeHtml(
                                        item.meta
                                    )}
                                </small>
                            </div>

                            <i data-lucide="external-link"></i>
                        </button>
                    `
                )
                .join('');


            box
                .querySelectorAll(
                    '[data-evidence-id]'
                )
                .forEach((button) => {
                    button.addEventListener(
                        'click',
                        () => {
                            const item =
                                items.find(
                                    (evidence) =>
                                        String(
                                            evidence.id
                                        ) ===
                                        button.dataset
                                            .evidenceId
                                );

                            if (
                                !item ||
                                !item.download_url
                            ) {
                                showToast(
                                    'This evidence file is not available for download.',
                                    'Evidence unavailable'
                                );

                                return;
                            }

                            window.location.href =
                                item.download_url;
                        }
                    );
                });


            refreshIcons();
        };


        const renderTimeline = (items = []) => {
            const box =
                document.querySelector(
                    '[data-dispute-timeline]'
                );

            if (!box) return;


            if (!items.length) {
                box.innerHTML = `
                    <div class="table-empty">
                        <i data-lucide="history"></i>

                        <strong>
                            No case activity yet
                        </strong>

                        <span>
                            Dispute events will appear here
                            as the case progresses.
                        </span>
                    </div>
                `;

                refreshIcons();

                return;
            }


            box.innerHTML = items
                .map(
                    (item) => `
                        <div class="timeline-row">

                            <span>
                                ${escapeDisputeHtml(
                                    item.time
                                )}
                            </span>

                            <div>
                                <i></i>

                                <p>
                                    ${escapeDisputeHtml(
                                        item.text
                                    )}
                                </p>
                            </div>

                        </div>
                    `
                )
                .join('');
        };


        const renderDispute = (
            id,
            notify = true
        ) => {
            const dispute =
                disputeData[id];

            if (!dispute) return;


            setDisputeText(
                '[data-dispute-id]',
                dispute.id
            );

            setDisputeText(
                '[data-dispute-subject]',
                dispute.subject
            );

            setDisputeText(
                '[data-dispute-status]',
                dispute.status
            );

            setDisputeText(
                '[data-dispute-opened]',
                dispute.opened
            );

            setDisputeText(
                '[data-dispute-buyer]',
                dispute.buyer
            );

            setDisputeText(
                '[data-dispute-seller]',
                dispute.seller
            );

            setDisputeText(
                '[data-dispute-rider]',
                dispute.rider ?? dispute.courier ?? 'Unavailable'
            );

            setDisputeText(
                '[data-dispute-logistics]',
                dispute.logistics ?? 'Unavailable'
            );

            setDisputeText(
                '[data-dispute-amount]',
                dispute.amount
            );

            setDisputeText(
                '[data-dispute-summary]',
                dispute.summary
            );


            document
                .querySelectorAll(
                    '[data-party-buyer]'
                )
                .forEach((element) => {
                    element.textContent =
                        dispute.buyer ?? '—';
                });


            document
                .querySelectorAll(
                    '[data-party-seller]'
                )
                .forEach((element) => {
                    element.textContent =
                        dispute.seller ?? '—';
                });


            document
                .querySelectorAll(
                    '[data-party-rider]'
                )
                .forEach((element) => {
                    element.textContent =
                        dispute.rider ?? dispute.courier ?? 'Unavailable';
                });

            document
                .querySelectorAll(
                    '[data-party-logistics]'
                )
                .forEach((element) => {
                    element.textContent =
                        dispute.logistics ?? 'Unavailable';
                });

            document
                .querySelectorAll('[data-party-message]')
                .forEach((button) => {
                    const role = button.dataset.partyMessage;
                    const participantId = dispute[`${role}_user_id`];
                    button.disabled = !participantId;
                });


            const priority =
                document.querySelector(
                    '[data-dispute-priority]'
                );

            if (priority) {
                priority.textContent =
                    `${dispute.priority} priority`;

                const normalized =
                    String(
                        dispute.priority
                    ).toLowerCase();

                priority.className =
                    'status-badge ' +
                    (
                        normalized === 'high' ||
                        normalized === 'urgent'
                            ? 'badge-danger'
                            : normalized === 'medium'
                                ? 'badge-warning'
                                : 'badge-info'
                    );
            }


            const note =
                document.querySelector(
                    '[data-dispute-note]'
                );

            if (note) {
                note.value =
                    dispute.internalNote || '';
            }

            const update = document.querySelector('[data-dispute-update]');

            if (update) {
                update.value = '';
            }


            renderEvidence(
                dispute.evidence || []
            );

            renderTimeline(
                dispute.timeline || []
            );


            if (notify) {
                showToast(
                    `${dispute.id} — ${dispute.subject} loaded.`,
                    'Dispute workspace'
                );
            }
        };


        const getActiveDisputeId = () =>
            document
                .querySelector(
                    '[data-case-card].is-active'
                )
                ?.dataset.caseId || '';


        const getActiveDispute = () => {
            const id =
                getActiveDisputeId();

            return id
                ? disputeData[id]
                : null;
        };


        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        disputeSearch?.addEventListener(
            'input',
            () => {
                const query =
                    disputeSearch
                        .value
                        .trim()
                        .toLowerCase();


                document
                    .querySelectorAll(
                        '[data-case-card]'
                    )
                    .forEach((card) => {
                        card.hidden =
                            !!query &&
                            !card
                                .dataset
                                .caseSearch
                                .includes(query);
                    });
            }
        );


        /*
        |--------------------------------------------------------------------------
        | Select dispute
        |--------------------------------------------------------------------------
        */

        document
            .querySelectorAll(
                '[data-case-card]'
            )
            .forEach((card) => {
                card.addEventListener(
                    'click',
                    () => {
                        if (
                            card.classList.contains(
                                'is-active'
                            )
                        ) {
                            return;
                        }


                        document
                            .querySelectorAll(
                                '[data-case-card]'
                            )
                            .forEach(
                                (item) =>
                                    item.classList.remove(
                                        'is-active'
                                    )
                            );


                        card.classList.add(
                            'is-active'
                        );


                        renderDispute(
                            card.dataset.caseId
                        );
                    }
                );
            });


        const remainingDisputeCards = [
            ...document.querySelectorAll(
                '[data-case-card]'
            ),
        ];


        if (
            remainingDisputeCards.length &&
            !remainingDisputeCards.some(
                (card) =>
                    card.classList.contains(
                        'is-active'
                    )
            )
        ) {
            remainingDisputeCards[0]
                .classList
                .add('is-active');
        }


        const restoredOpenCount =
            document.querySelector(
                '[data-dispute-open-count]'
            );

        if (restoredOpenCount) {
            restoredOpenCount.textContent =
                String(
                    remainingDisputeCards.length
                );
        }


        /*
        |--------------------------------------------------------------------------
        | Refresh
        |--------------------------------------------------------------------------
        */

        document
            .querySelector(
                '[data-dispute-refresh]'
            )
            ?.addEventListener(
                'click',
                () => {
                    window.location.reload();
                }
            );


        /*
        |--------------------------------------------------------------------------
        | Dispute participant messaging
        |--------------------------------------------------------------------------
        */

        document
            .querySelectorAll(
                '[data-party-message]'
            )
            .forEach((button) => {
                button.addEventListener(
                    'click',
                    async () => {
                        const dispute =
                            getActiveDispute();

                        if (
                            !dispute ||
                            !dispute.message_url
                        ) {
                            return;
                        }

                        const selectedRole =
                            button.dataset.partyMessage;

                        let recipientId = null;
                        let recipientRole = null;

                        if (
                            selectedRole === 'buyer'
                        ) {
                            recipientId =
                                dispute.buyer_user_id;

                            recipientRole =
                                'buyer';
                        }

                        if (
                            selectedRole === 'seller'
                        ) {
                            recipientId =
                                dispute.seller_user_id;

                            recipientRole =
                                'seller';
                        }

                        if (['rider', 'logistics'].includes(selectedRole)) {
                            recipientId = dispute[`${selectedRole}_user_id`] ?? null;
                            recipientRole = selectedRole;
                        }

                        if (
                            !recipientId ||
                            !recipientRole
                        ) {
                            showToast(
                                'This participant does not have a linked messaging account.',
                                'Conversation unavailable'
                            );

                            return;
                        }

                        button.disabled = true;

                        try {
                            const response =
                                await fetch(
                                    dispute.message_url,
                                    {
                                        method: 'POST',

                                        headers: {
                                            'Content-Type':
                                                'application/json',

                                            'Accept':
                                                'application/json',

                                            'X-CSRF-TOKEN':
                                                csrfToken,
                                        },

                                        body: JSON.stringify({
                                            recipient_id:
                                                recipientId,

                                            recipient_role:
                                                recipientRole,
                                        }),
                                    }
                                );

                            const result =
                                await response.json();

                            if (!response.ok) {
                                const firstError =
                                    Object.values(
                                        result.errors || {}
                                    )
                                        .flat()
                                        .find(Boolean);

                                throw new Error(
                                    firstError ||
                                    result.message ||
                                    'Unable to open conversation.'
                                );
                            }

                            window.location.href =
                                result.conversation
                                    .messages_url;
                        } catch (error) {
                            button.disabled =
                                false;

                            showToast(
                                error.message ||
                                'Unable to open the participant conversation.',
                                'Conversation unavailable'
                            );
                        }
                    }
                );
            });


        /*
        |--------------------------------------------------------------------------
        | Save internal note
        |--------------------------------------------------------------------------
        */

        document
            .querySelector(
                '[data-dispute-save-note]'
            )
            ?.addEventListener(
                'click',
                async () => {
                    const dispute =
                        getActiveDispute();

                    const noteElement =
                        document.querySelector(
                            '[data-dispute-note]'
                        );

                    const note =
                        noteElement
                            ?.value
                            .trim() || '';


                    if (
                        !dispute ||
                        !dispute.note_url
                    ) {
                        return;
                    }


                    if (!note) {
                        showToast(
                            'Enter an internal note first.',
                            'Note not saved'
                        );

                        noteElement?.focus();

                        return;
                    }


                    try {
                        const response =
                            await fetch(
                                dispute.note_url,
                                {
                                    method: 'POST',

                                    headers: {
                                        'Content-Type':
                                            'application/json',

                                        'Accept':
                                            'application/json',

                                        'X-CSRF-TOKEN':
                                            csrfToken,
                                    },

                                    body: JSON.stringify({
                                        note,
                                    }),
                                }
                            );


                        const result =
                            await response.json();


                        if (!response.ok) {
                            throw new Error(
                                result.message ||
                                'Unable to save note.'
                            );
                        }


                        dispute.internalNote =
                            note;


                        if (result.event) {
                            dispute.timeline =
                                dispute.timeline || [];

                            dispute.timeline.push(
                                result.event
                            );

                            renderTimeline(
                                dispute.timeline
                            );
                        }


                        showToast(
                            `${dispute.id} internal note saved.`,
                            'Resolution note'
                        );
                    } catch (error) {
                        showToast(
                            error.message ||
                            'Unable to save the internal note.',
                            'Note not saved'
                        );
                    }
                }
            );


        /*
        |--------------------------------------------------------------------------
        | Send participant case update
        |--------------------------------------------------------------------------
        */

        document
            .querySelector(
                '[data-dispute-send-update]'
            )
            ?.addEventListener(
                'click',
                async (event) => {
                    const button =
                        event.currentTarget;

                    const dispute =
                        getActiveDispute();

                    const messageElement =
                        document.querySelector(
                            '[data-dispute-update]'
                        );

                    const message =
                        messageElement
                            ?.value
                            .trim() || '';


                    if (
                        !dispute ||
                        !dispute.update_url
                    ) {
                        showToast(
                            'This dispute does not have an update endpoint.',
                            'Update not sent'
                        );

                        return;
                    }


                    if (!message) {
                        showToast(
                            'Enter a case update first.',
                            'Update not sent'
                        );

                        messageElement?.focus();

                        return;
                    }


                    button.disabled = true;


                    try {
                        const response =
                            await fetch(
                                dispute.update_url,
                                {
                                    method: 'POST',

                                    headers: {
                                        'Content-Type':
                                            'application/json',

                                        'Accept':
                                            'application/json',

                                        'X-CSRF-TOKEN':
                                            csrfToken,
                                    },

                                    body: JSON.stringify({
                                        message,
                                    }),
                                }
                            );


                        const result =
                            await response.json();


                        if (!response.ok) {
                            const validationMessage =
                                result.errors
                                    ? Object.values(
                                        result.errors
                                    )
                                        .flat()
                                        .find(Boolean)
                                    : null;

                            throw new Error(
                                validationMessage ||
                                result.message ||
                                'Unable to send case update.'
                            );
                        }


                        if (result.event) {
                            dispute.timeline =
                                dispute.timeline || [];

                            dispute.timeline.push(
                                result.event
                            );

                            renderTimeline(
                                dispute.timeline
                            );
                        }


                        showToast(
                            `Update sent to ${result.recipient_count} participant${
                                result.recipient_count === 1
                                    ? ''
                                    : 's'
                            }.`,
                            'Case update sent'
                        );

                        if (messageElement) {
                            messageElement.value = '';
                        }
                    } catch (error) {
                        showToast(
                            error.message ||
                            'Unable to send the case update.',
                            'Update not sent'
                        );
                    } finally {
                        button.disabled = false;
                    }
                }
            );


        /*
        |--------------------------------------------------------------------------
        | Resolve dispute
        |--------------------------------------------------------------------------
        */

        const resolveModal =
            document.querySelector(
                '[data-modal="resolve-dispute"]'
            );


        document
            .querySelector(
                '[data-dispute-resolve]'
            )
            ?.addEventListener(
                'click',
                () => {
                    const dispute =
                        getActiveDispute();

                    if (
                        !dispute ||
                        !resolveModal
                    ) {
                        return;
                    }


                    setDisputeText(
                        '[data-resolve-case-id]',
                        dispute.id
                    );


                    const outcome =
                        resolveModal.querySelector(
                            '[data-resolution-outcome]'
                        );

                    const note =
                        resolveModal.querySelector(
                            '[data-resolution-note]'
                        );

                    const error =
                        resolveModal.querySelector(
                            '[data-resolution-error]'
                        );


                    if (outcome) {
                        outcome.value = '';
                    }


                    if (note) {
                        note.value =
                            dispute.internalNote || '';
                    }


                    if (error) {
                        error.hidden = true;
                    }


                    resolveModal.hidden =
                        false;


                    refreshIcons();
                }
            );


        document
            .querySelector(
                '[data-confirm-resolution]'
            )
            ?.addEventListener(
                'click',
                async () => {
                    const dispute =
                        getActiveDispute();

                    if (
                        !dispute ||
                        !dispute.resolve_url ||
                        !resolveModal
                    ) {
                        return;
                    }


                    const outcome =
                        resolveModal.querySelector(
                            '[data-resolution-outcome]'
                        )
                            ?.value || '';

                    const note =
                        resolveModal.querySelector(
                            '[data-resolution-note]'
                        )
                            ?.value
                            .trim() || '';

                    const error =
                        resolveModal.querySelector(
                            '[data-resolution-error]'
                        );


                    if (
                        !outcome ||
                        !note
                    ) {
                        if (error) {
                            error.hidden =
                                false;
                        }

                        return;
                    }


                    try {
                        const response =
                            await fetch(
                                dispute.resolve_url,
                                {
                                    method: 'POST',

                                    headers: {
                                        'Content-Type':
                                            'application/json',

                                        'Accept':
                                            'application/json',

                                        'X-CSRF-TOKEN':
                                            csrfToken,
                                    },

                                    body: JSON.stringify({
                                        outcome,
                                        note,
                                    }),
                                }
                            );


                        const result =
                            await response.json();


                        if (!response.ok) {
                            throw new Error(
                                result.message ||
                                'Unable to resolve dispute.'
                            );
                        }


                        resolveModal.hidden =
                            true;


                        showToast(
                            `${dispute.id} was resolved.`,
                            'Case resolved'
                        );


                        /*
                        * Server is now the source of truth.
                        * Reload to remove resolved case and
                        * refresh its database-backed timeline.
                        */
                        window.location.reload();
                    } catch (error) {
                        if (error) {
                            const validationError =
                                resolveModal.querySelector(
                                    '[data-resolution-error]'
                                );

                            if (
                                validationError
                            ) {
                                validationError.hidden =
                                    false;

                                validationError.textContent =
                                    error.message ||
                                    'Unable to resolve this case.';
                            }
                        }
                    }
                }
            );


        /*
        |--------------------------------------------------------------------------
        | Initial dispute
        |--------------------------------------------------------------------------
        */

        const initialDispute =
            document.querySelector(
                '[data-case-card].is-active'
            )?.dataset.caseId;


        if (initialDispute) {
            renderDispute(
                initialDispute,
                false
            );
        }

        /// Chat filtering + database-backed conversation thread state
        const conversationSearch =
            document.querySelector(
                '[data-conversation-search]'
            );

        let conversationItems = [
            ...document.querySelectorAll(
                '[data-conversation-item]'
            ),
        ];

        const conversationList =
            document.querySelector(
                '[data-conversation-list]'
            );

        const messageThread =
            document.querySelector(
                '[data-message-thread]'
            );

        const messageInput =
            document.querySelector(
                '[data-message-input]'
            );

        const attachmentInput =
            document.querySelector(
                '[data-chat-attachment]'
            );

        const attachmentName =
            document.querySelector(
                '[data-chat-attachment-name]'
            );

        const unreadSummary =
            document.querySelector(
                '[data-unread-summary]'
            );

        const conversationDataNode =
            document.getElementById(
                'admin-conversation-data'
            );

        let conversationData = {};

        try {
            conversationData =
                conversationDataNode
                    ? JSON.parse(
                        conversationDataNode.textContent
                    )
                    : {};
        } catch {
            conversationData = {};
        }


        const messageConfigNode =
            document.getElementById(
                'admin-message-config'
            );

        let messageConfig = {};

        try {
            messageConfig =
                messageConfigNode
                    ? JSON.parse(
                        messageConfigNode.textContent
                    )
                    : {};
        } catch {
            messageConfig = {};
        }


        const escapeChatHtml = (
            value = ''
        ) =>
            String(value)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');


        const getConversationId = (
            item
        ) =>
            item?.dataset
                .conversationId || '';


        const getActiveConversationItem =
            () =>
                document.querySelector(
                    '[data-conversation-item].is-active'
                );


        const getActiveConversation =
            () => {
                const item =
                    getActiveConversationItem();

                const id =
                    getConversationId(item);

                return id
                    ? conversationData[id]
                    : null;
            };


        const updateUnreadSummary = () => {
            if (!unreadSummary) {
                return;
            }

            const totalUnread = [
                ...document.querySelectorAll(
                    '[data-conversation-item] .unread-count'
                ),
            ].reduce(
                (total, badge) =>
                    total +
                    Number(
                        badge.textContent
                            ?.trim() || 0
                    ),
                0
            );

            unreadSummary.textContent =
                `${totalUnread} unread`;

            unreadSummary.hidden =
                totalUnread === 0;
        };


        const setConversationUnread = (
            item,
            unread
        ) => {
            if (!item) {
                return;
            }

            let badge =
                item.querySelector(
                    '.unread-count'
                );

            const count =
                Number(unread || 0);

            if (count <= 0) {
                badge?.remove();

                updateUnreadSummary();

                return;
            }

            if (!badge) {
                badge =
                    document.createElement(
                        'span'
                    );

                badge.className =
                    'unread-count';

                item.appendChild(badge);
            }

            badge.textContent =
                String(count);

            updateUnreadSummary();
        };


        const updateConversationPreview = (
            item,
            text,
            time
        ) => {
            if (!item) {
                return;
            }

            const preview =
                item.querySelector(
                    '.conversation-preview > span:last-child'
                );

            const timeElement =
                item.querySelector(
                    '.conversation-meta time'
                );

            if (preview) {
                preview.textContent =
                    text;
            }

            if (timeElement) {
                timeElement.textContent =
                    time;
            }

            item.dataset.preview =
                text;

            item.dataset.time =
                time;

            item.dataset.search =
                [
                    item.dataset.name || '',
                    item.dataset.role || '',
                    text,
                ]
                    .join(' ')
                    .toLowerCase();

            const id =
                getConversationId(item);

            if (conversationData[id]) {
                conversationData[id].preview =
                    text;

                conversationData[id].time =
                    time;
            }
        };


        const clearAttachmentPreview =
            () => {
                if (attachmentInput) {
                    attachmentInput.value = '';
                }

                if (attachmentName) {
                    attachmentName.textContent =
                        '';

                    attachmentName.hidden =
                        true;
                }
            };

        const formatAttachmentSize = (
            bytes = 0
        ) => {
            const size = Number(bytes || 0);

            if (size < 1024) {
                return `${size} B`;
            }

            if (size < 1024 * 1024) {
                return `${(
                    size / 1024
                ).toFixed(1)} KB`;
            }

            return `${(
                size /
                (1024 * 1024)
            ).toFixed(1)} MB`;
        };


        const attachmentIcon = (
            mimeType = ''
        ) => {
            if (
                String(mimeType)
                    .startsWith('image/')
            ) {
                return 'image';
            }

            if (
                String(mimeType)
                    .includes('pdf')
            ) {
                return 'file-text';
            }

            return 'paperclip';
        };


        const renderMessageAttachments = (
            attachments = []
        ) => {
            if (!attachments.length) {
                return '';
            }

            return `
                <div class="message-attachments">
                    ${attachments
                        .map(
                            (attachment) => `
                                <a
                                    class="message-attachment"
                                    href="${escapeChatHtml(
                                        attachment.download_url || '#'
                                    )}"
                                    ${
                                        attachment.download_url
                                            ? ''
                                            : 'aria-disabled="true"'
                                    }
                                >
                                    <i
                                        data-lucide="${attachmentIcon(
                                            attachment.mime_type
                                        )}"
                                    ></i>

                                    <span>
                                        <strong>
                                            ${escapeChatHtml(
                                                attachment.name ||
                                                'Attachment'
                                            )}
                                        </strong>

                                        <small>
                                            ${escapeChatHtml(
                                                formatAttachmentSize(
                                                    attachment.size_bytes
                                                )
                                            )}
                                        </small>
                                    </span>

                                    <i
                                        data-lucide="download"
                                    ></i>
                                </a>
                            `
                        )
                        .join('')}
                </div>
            `;
        };


        const renderChatThread = (
            id
        ) => {
            if (!messageThread) {
                return;
            }

            const conversation =
                conversationData[id];

            if (!conversation) {
                messageThread.innerHTML = `
                    <div class="table-empty">

                        <i
                            data-lucide="message-square-off"
                        ></i>

                        <strong>
                            Select a conversation
                        </strong>

                        <span>
                            Choose a conversation from
                            the inbox to view its messages.
                        </span>

                    </div>
                `;

                refreshIcons();

                return;
            }

            const messages =
                conversation.messages || [];

            if (!messages.length) {
                messageThread.innerHTML = `
                    <div class="thread-date">
                        Conversation
                    </div>

                    <div class="table-empty">

                        <i
                            data-lucide="message-circle"
                        ></i>

                        <strong>
                            No messages yet
                        </strong>

                        <span>
                            This conversation has not
                            received any messages yet.
                        </span>

                    </div>
                `;

                refreshIcons();

                return;
            }

            messageThread.innerHTML = `
                <div class="thread-date">
                    Conversation
                </div>

                ${messages
                    .map(
                        (message) => `
                            <div
                                class="
                                    message-row
                                    message-${
                                        message.from === 'me'
                                            ? 'me'
                                            : 'them'
                                    }
                                "
                            >

                                <div
                                    class="message-bubble"
                                >

                                    ${
                                        message.text
                                            ? `
                                                <p>
                                                    ${escapeChatHtml(
                                                        message.text
                                                    )}
                                                </p>
                                            `
                                            : ''
                                    }

                                    ${renderMessageAttachments(
                                        message.attachments || []
                                    )}

                                    <span>
                                        ${escapeChatHtml(
                                            message.time
                                        )}
                                    </span>

                                </div>

                            </div>
                        `
                    )
                    .join('')}
            `;

            refreshIcons();

            messageThread.scrollTop =
                messageThread.scrollHeight;
        };


        const selectConversation = async (
            item
        ) => {
            if (!item) {
                return;
            }

            conversationItems.forEach(
                (other) =>
                    other.classList.remove(
                        'is-active'
                    )
            );

            item.classList.add(
                'is-active'
            );

            const id =
                getConversationId(item);

            const conversation =
                conversationData[id];

            if (!conversation) {
                return;
            }

            const name =
                document.querySelector(
                    '[data-chat-name]'
                );

            const role =
                document.querySelector(
                    '[data-chat-role]'
                );

            const avatar =
                document.querySelector(
                    '[data-chat-avatar]'
                );

            if (name) {
                name.textContent =
                    conversation.name || '';
            }

            if (role) {
                role.textContent =
                    conversation.role || '';
            }

            if (avatar) {
                avatar.textContent =
                    conversation.initials || '';
            }

            clearAttachmentPreview();

            renderChatThread(id);


            /*
            |--------------------------------------------------------------------------
            | Persist read state
            |--------------------------------------------------------------------------
            */

            if (!conversation.read_url) {
                return;
            }

            try {
                const response =
                    await fetch(
                        conversation.read_url,
                        {
                            method: 'POST',

                            headers: {
                                'Accept':
                                    'application/json',

                                'X-CSRF-TOKEN':
                                    csrfToken,
                            },
                        }
                    );

                if (!response.ok) {
                    return;
                }

                conversation.unread = 0;

                setConversationUnread(
                    item,
                    0
                );
            } catch {
                /*
                 * Keep the server-rendered unread state
                 * if the request cannot be completed.
                 */
            }
        };


        /*
        |--------------------------------------------------------------------------
        | Conversation search
        |--------------------------------------------------------------------------
        */

        conversationSearch
            ?.addEventListener(
                'input',
                () => {
                    const query =
                        conversationSearch
                            .value
                            .trim()
                            .toLowerCase();

                    conversationItems.forEach(
                        (item) => {
                            item.hidden =
                                Boolean(query) &&
                                !(
                                    item.dataset
                                        .search || ''
                                ).includes(query);
                        }
                    );
                }
            );


        /*
        |--------------------------------------------------------------------------
        | Select conversation
        |--------------------------------------------------------------------------
        */

        const bindConversation = (
            item
        ) => {
            item.addEventListener(
                'click',
                () =>
                    selectConversation(
                        item
                    )
            );
        };


        conversationItems.forEach(
            bindConversation
        );


        /*
        |--------------------------------------------------------------------------
        | Send message
        |--------------------------------------------------------------------------
        */

        const sendMessage = async () => {
            const conversation =
                getActiveConversation();

            const text =
                messageInput
                    ?.value
                    .trim() || '';

            const attachment =
                attachmentInput
                    ?.files?.[0] || null;

            if (
                !conversation ||
                !conversation.send_url ||
                (
                    !text &&
                    !attachment
                )
            ) {
                return;
            }

            const sendButton =
                document.querySelector(
                    '[data-send-message]'
                );

            if (sendButton) {
                sendButton.disabled =
                    true;
            }

            try {
                const formData =
                    new FormData();

                if (text) {
                    formData.append(
                        'message',
                        text
                    );
                }

                if (attachment) {
                    formData.append(
                        'attachment',
                        attachment
                    );
                }

                const response =
                    await fetch(
                        conversation.send_url,
                        {
                            method: 'POST',

                            headers: {
                                'Accept':
                                    'application/json',

                                'X-CSRF-TOKEN':
                                    csrfToken,
                            },

                            body: formData,
                        }
                    );

                const result =
                    await response.json();

                if (!response.ok) {
                    const firstError =
                        Object.values(
                            result.errors || {}
                        )
                            .flat()
                            .find(Boolean);

                    throw new Error(
                        firstError ||
                        result.message ||
                        'Unable to send message.'
                    );
                }

                conversation.messages =
                    conversation.messages || [];

                conversation.messages.push(
                    result.data
                );

                const previewText =
                    result.data.text ||
                    result.data.attachments?.[0]
                        ?.name ||
                    'Attachment';

                conversation.preview =
                    previewText;

                conversation.time =
                    result.data.time;

                if (messageInput) {
                    messageInput.value =
                        '';
                }

                clearAttachmentPreview();

                renderChatThread(
                    String(
                        conversation.database_id
                    )
                );

                const activeItem =
                    getActiveConversationItem();

                updateConversationPreview(
                    activeItem,
                    previewText,
                    result.data.time
                );

                showToast(
                    attachment
                        ? `Message and attachment sent to ${conversation.name}.`
                        : `Message sent to ${conversation.name}.`,
                    'Message sent'
                );
            } catch (error) {
                showToast(
                    error.message ||
                    'Unable to send the message.',
                    'Message not sent'
                );
            } finally {
                if (sendButton) {
                    sendButton.disabled =
                        false;
                }
            }
        };


        document
            .querySelector(
                '[data-send-message]'
            )
            ?.addEventListener(
                'click',
                sendMessage
            );


        messageInput
            ?.addEventListener(
                'keydown',
                (event) => {
                    if (
                        event.key ===
                            'Enter' &&
                        !event.shiftKey
                    ) {
                        event.preventDefault();

                        sendMessage();
                    }
                }
            );


        /*
        |--------------------------------------------------------------------------
        | Live refresh (inbox previews + active thread)
        |--------------------------------------------------------------------------
        */

        const ADMIN_POLL_MS = 5000;
        const ADMIN_INBOX_EVERY_TICKS = 3;

        let adminPollTimer = null;
        let adminPollTick = 0;
        let adminVisibilityHandler = null;

        const conversationItemById = (id) =>
            conversationItems.find(
                (item) =>
                    getConversationId(item) === String(id)
            ) || null;

        const refreshAdminInbox = async () => {
            if (!messageConfig.inbox_url) {
                return;
            }

            const response = await fetch(
                messageConfig.inbox_url,
                {
                    headers: {
                        'Accept': 'application/json',
                    },
                }
            );

            if (!response.ok) {
                return;
            }

            const result = await response.json();

            const entries = result?.data?.conversations || [];

            const activeId = getConversationId(
                getActiveConversationItem()
            );

            entries.forEach((entry) => {
                const id = String(entry.id ?? '');
                const item = conversationItemById(id);

                if (!item) {
                    return;
                }

                updateConversationPreview(
                    item,
                    entry.preview ?? '',
                    entry.time ?? ''
                );

                if (id !== activeId) {
                    const conversation = conversationData[id];

                    if (conversation) {
                        conversation.unread = Number(entry.unread || 0);
                    }

                    setConversationUnread(item, Number(entry.unread || 0));
                }
            });

            /*
             * The snapshot is ordered newest-first; mirror that
             * order in the rendered inbox.
             */

            if (conversationList) {
                entries.forEach((entry) => {
                    const item = conversationItemById(
                        String(entry.id ?? '')
                    );

                    if (item) {
                        conversationList.appendChild(item);
                    }
                });
            }
        };

        const pollAdminThread = async () => {
            const item = getActiveConversationItem();

            const conversation =
                item
                    ? conversationData[getConversationId(item)]
                    : null;

            if (!item || !conversation?.thread_url) {
                return;
            }

            const url = new URL(
                conversation.thread_url,
                window.location.origin
            );

            const messages = conversation.messages || [];

            const lastMessageId = messages.length
                ? Number(messages[messages.length - 1].id || 0)
                : 0;

            if (lastMessageId > 0) {
                url.searchParams.set('after', String(lastMessageId));
            }

            const response = await fetch(
                url.toString(),
                {
                    headers: {
                        'Accept': 'application/json',
                    },
                }
            );

            if (!response.ok) {
                return;
            }

            const result = await response.json();

            const incoming = result?.data?.messages || [];

            if (!incoming.length) {
                return;
            }

            const nearBottom = messageThread
                ? (messageThread.scrollHeight - messageThread.scrollTop - messageThread.clientHeight) < 96
                : true;

            const previousScrollTop = messageThread?.scrollTop ?? 0;

            conversation.messages = [
                ...messages,
                ...incoming,
            ];

            renderChatThread(String(conversation.database_id));

            if (!nearBottom && messageThread) {
                messageThread.scrollTop = previousScrollTop;
            }

            const latest = incoming[incoming.length - 1];

            const previewText =
                latest.text ||
                latest.attachments?.[0]?.name ||
                'Attachment';

            updateConversationPreview(item, previewText, latest.time);

            /*
             * The active conversation is on screen, so live
             * arrivals count as read.
             */

            conversation.unread = 0;

            setConversationUnread(item, 0);

            if (
                conversation.read_url &&
                incoming.some((message) => message.from === 'them')
            ) {
                fetch(conversation.read_url, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                }).catch(() => {});
            }
        };

        const startAdminPolling = () => {
            if (adminPollTimer || !conversationDataNode) {
                return;
            }

            adminPollTimer = window.setInterval(async () => {
                if (document.hidden) {
                    return;
                }

                adminPollTick += 1;

                try {
                    await pollAdminThread();

                    if (adminPollTick % ADMIN_INBOX_EVERY_TICKS === 0) {
                        await refreshAdminInbox();
                    }
                } catch {
                    /*
                     * Transient polling failures are ignored;
                     * the next tick retries.
                     */
                }
            }, ADMIN_POLL_MS);

            adminVisibilityHandler = () => {
                if (document.hidden) {
                    return;
                }

                pollAdminThread().catch(() => {});
                refreshAdminInbox().catch(() => {});
            };

            document.addEventListener(
                'visibilitychange',
                adminVisibilityHandler
            );
        };

        startAdminPolling();

        window.addEventListener('bearly:message-created', (event) => {
            const conversationId = String(event.detail?.conversation_id || '');
            const activeId = getConversationId(getActiveConversationItem());

            if (conversationId && conversationId === activeId) {
                pollAdminThread().catch(() => {});
            } else {
                refreshAdminInbox().catch(() => {});
            }
        });


        /*
        |--------------------------------------------------------------------------
        | New conversation
        |--------------------------------------------------------------------------
        */

        document
            .querySelector(
                '[data-create-conversation]'
            )
            ?.addEventListener(
                'click',
                async (event) => {
                    const button =
                        event.currentTarget;

                    const recipientInput =
                        document.querySelector(
                            '[data-new-chat-recipient]'
                        );

                    const messageField =
                        document.querySelector(
                            '[data-new-chat-message]'
                        );

                    const recipientId =
                        recipientInput?.value || '';

                    const selectedOption =
                        recipientInput
                            ?.selectedOptions?.[0];

                    const recipientRole =
                        selectedOption
                            ?.dataset.role || '';

                    const message =
                        messageField
                            ?.value
                            .trim() || '';

                    if (
                        !recipientId ||
                        !recipientRole ||
                        !message
                    ) {
                        showToast(
                            'Select a recipient and enter a first message.',
                            'Conversation not started'
                        );

                        return;
                    }

                    if (!messageConfig.create_url) {
                        showToast(
                            'The conversation endpoint is unavailable.',
                            'Conversation not started'
                        );

                        return;
                    }

                    button.disabled =
                        true;

                    try {
                        const response =
                            await fetch(
                                messageConfig.create_url,
                                {
                                    method: 'POST',

                                    headers: {
                                        'Content-Type':
                                            'application/json',

                                        'Accept':
                                            'application/json',

                                        'X-CSRF-TOKEN':
                                            csrfToken,
                                    },

                                    body: JSON.stringify({
                                        recipient_id:
                                            Number(
                                                recipientId
                                            ),

                                        recipient_role:
                                            recipientRole,

                                        message,
                                    }),
                                }
                            );

                        const result =
                            await response.json();

                        if (!response.ok) {
                            const firstError =
                                Object.values(
                                    result.errors || {}
                                )
                                    .flat()
                                    .find(Boolean);

                            throw new Error(
                                firstError ||
                                result.message ||
                                'Unable to start conversation.'
                            );
                        }

                        showToast(
                            `Conversation with ${result.conversation.participant.name} started.`,
                            'Conversation started'
                        );

                        /*
                         * Reload from the database so the
                         * new thread is rendered from server truth.
                         */
                        window.location.reload();
                    } catch (error) {
                        button.disabled =
                            false;

                        showToast(
                            error.message ||
                            'Unable to start the conversation.',
                            'Conversation not started'
                        );
                    }
                }
            );


                /*
                |--------------------------------------------------------------------------
                | Attachment selection
                |--------------------------------------------------------------------------
                */

                document
                    .querySelector(
                        '[data-chat-attachment-button]'
                    )
                    ?.addEventListener(
                        'click',
                        () => {
                            attachmentInput
                                ?.click();
                        }
                    );


                attachmentInput
                    ?.addEventListener(
                        'change',
                        (event) => {
                            const file =
                                event.target
                                    .files?.[0];

                            if (!file) {
                                clearAttachmentPreview();

                                return;
                            }

                            const maxSize =
                                10 * 1024 * 1024;

                            if (file.size > maxSize) {
                                showToast(
                                    'Attachments must not exceed 10 MB.',
                                    'Attachment too large'
                                );

                                clearAttachmentPreview();

                                return;
                            }

                            const allowedExtensions = [
                                'jpg',
                                'jpeg',
                                'png',
                                'webp',
                                'pdf',
                                'doc',
                                'docx',
                                'xls',
                                'xlsx',
                                'txt',
                                'csv',
                                'zip',
                            ];

                            const extension =
                                file.name
                                    .split('.')
                                    .pop()
                                    ?.toLowerCase() || '';

                            if (
                                !allowedExtensions
                                    .includes(extension)
                            ) {
                                showToast(
                                    'This file type is not supported.',
                                    'Attachment not allowed'
                                );

                                clearAttachmentPreview();

                                return;
                            }

                            if (attachmentName) {
                                attachmentName.textContent =
                                    `${file.name} · ${formatAttachmentSize(
                                        file.size
                                    )}`;

                                attachmentName.hidden =
                                    false;
                            }

                            showToast(
                                `${file.name} is ready to send.`,
                                'Attachment selected'
                            );
                        }
                    );


        /*
        |--------------------------------------------------------------------------
        | Emoji shortcut
        |--------------------------------------------------------------------------
        */

        document
            .querySelector(
                '[data-chat-emoji]'
            )
            ?.addEventListener(
                'click',
                () => {
                    if (!messageInput) {
                        return;
                    }

                    messageInput.value +=
                        '🙂';

                    messageInput.focus();
                }
            );


        /*
        |--------------------------------------------------------------------------
        | Conversation details
        |--------------------------------------------------------------------------
        */

        document
            .querySelector(
                '[data-chat-details]'
            )
            ?.addEventListener(
                'click',
                () => {
                    const conversation =
                        getActiveConversation();

                    if (!conversation) {
                        return;
                    }

                    const count =
                        (
                            conversation.messages ||
                            []
                        ).length;

                    showToast(
                        `${conversation.name} · ${conversation.role} · ${count} message${count === 1 ? '' : 's'}`,
                        'Conversation details'
                    );
                }
            );


        /*
        |--------------------------------------------------------------------------
        | Mark unread
        |--------------------------------------------------------------------------
        */

        document
            .querySelector(
                '[data-chat-mark-unread]'
            )
            ?.addEventListener(
                'click',
                async () => {
                    const conversation =
                        getActiveConversation();

                    const activeItem =
                        getActiveConversationItem();

                    if (
                        !conversation ||
                        !conversation.unread_url ||
                        !activeItem
                    ) {
                        return;
                    }

                    try {
                        const response =
                            await fetch(
                                conversation.unread_url,
                                {
                                    method: 'POST',

                                    headers: {
                                        'Accept':
                                            'application/json',

                                        'X-CSRF-TOKEN':
                                            csrfToken,
                                    },
                                }
                            );

                        const result =
                            await response.json();

                        if (!response.ok) {
                            throw new Error(
                                result.message ||
                                'Unable to change read status.'
                            );
                        }

                        conversation.unread =
                            Number(
                                result.unread || 0
                            );

                        setConversationUnread(
                            activeItem,
                            conversation.unread
                        );

                        showToast(
                            result.message,
                            conversation.unread > 0
                                ? 'Marked unread'
                                : 'Read state unchanged'
                        );
                    } catch (error) {
                        showToast(
                            error.message ||
                            'Unable to update the conversation.',
                            'Read state not changed'
                        );
                    }
                }
            );


        /*
        |--------------------------------------------------------------------------
        | Initial inbox state
        |--------------------------------------------------------------------------
        */

        updateUnreadSummary();


        const requestedConversationId =
            new URLSearchParams(
                window.location.search
            ).get('conversation');

        const requestedConversation =
            requestedConversationId
                ? conversationItems.find(
                    (item) =>
                        getConversationId(
                            item
                        )
                        === String(
                            requestedConversationId
                        )
                )
                : null;

        const initialConversation =
            requestedConversation
            || document.querySelector(
                '[data-conversation-item].is-active'
            );

        if (initialConversation) {
            selectConversation(
                initialConversation
            );
        }
    });

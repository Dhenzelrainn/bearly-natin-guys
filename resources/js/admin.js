    const ready = (callback) => {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
        } else {
            callback();
        }
    };

    ready(() => {
        const body = document.body;

        const refreshIcons = () => {
            if (window.lucide?.createIcons) window.lucide.createIcons();
        };
        refreshIcons();
        setTimeout(refreshIcons, 150);

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
        const dashboardBars = [...document.querySelectorAll('[data-dashboard-bar]')];
        document.querySelectorAll('[data-dashboard-period]').forEach((button) => {
            button.addEventListener('click', () => {
                const count = Number(button.dataset.dashboardPeriod || 12);
                document.querySelectorAll('[data-dashboard-period]').forEach((item) => item.classList.toggle('is-active', item === button));
                dashboardBars.forEach((bar, index) => {
                    bar.hidden = index < dashboardBars.length - count;
                });
                showToast(`${button.textContent.trim()} sales view selected.`, 'Chart period updated');
            });
        });


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


        // Change password validation (front-end preview only)
        const currentPasswordInput =
            document.querySelector('[data-password-current]');

        const newPasswordInput =
            document.querySelector('[data-password-new]');

        const confirmPasswordInput =
            document.querySelector('[data-password-confirm]');

        const passwordUpdateButton =
            document.querySelector('[data-password-update]');

        const passwordLengthRule =
            document.querySelector('[data-password-rule="length"]');

        const passwordMixedRule =
            document.querySelector('[data-password-rule="mixed"]');

        const passwordMatchRule =
            document.querySelector('[data-password-rule="match"]');


        const isMixedPassword = (value) =>
            /[A-Za-z]/.test(value) &&
            /\d/.test(value);


        const setPasswordRuleState = (
            rule,
            valid
        ) => {
            if (!rule) return;

            rule.dataset.valid =
                valid ? 'true' : 'false';


            /*
            * Lucide converts the original <i>
            * into an <svg>, so replace whichever
            * icon element currently exists.
            */
            const currentIcon =
                rule.querySelector('i, svg');

            if (currentIcon) {
                const nextIcon =
                    document.createElement('i');

                nextIcon.setAttribute(
                    'data-lucide',
                    valid
                        ? 'circle-check'
                        : 'circle'
                );

                currentIcon.replaceWith(
                    nextIcon
                );
            }


            refreshIcons();
        };


        const syncPasswordValidation = () => {
            const currentPassword =
                currentPasswordInput?.value || '';

            const newPassword =
                newPasswordInput?.value || '';

            const confirmPassword =
                confirmPasswordInput?.value || '';


            const validLength =
                newPassword.length >= 8;

            const validMixed =
                isMixedPassword(newPassword);

            /*
            * Keep this false while both fields
            * are empty so the page starts neutral.
            */
            const validMatch =
                Boolean(newPassword) &&
                Boolean(confirmPassword) &&
                newPassword === confirmPassword;


            setPasswordRuleState(
                passwordLengthRule,
                validLength
            );

            setPasswordRuleState(
                passwordMixedRule,
                validMixed
            );

            setPasswordRuleState(
                passwordMatchRule,
                validMatch
            );


            if (passwordUpdateButton) {
                passwordUpdateButton.disabled =
                    !currentPassword ||
                    !validLength ||
                    !validMixed ||
                    !validMatch;
            }


        };


        [
            currentPasswordInput,
            newPasswordInput,
            confirmPasswordInput,
        ]
            .filter(Boolean)
            .forEach((input) => {
                input.addEventListener(
                    'input',
                    syncPasswordValidation
                );
            });


        passwordUpdateButton
            ?.addEventListener(
                'click',
                () => {

                    const currentPassword =
                        currentPasswordInput?.value || '';

                    const newPassword =
                        newPasswordInput?.value || '';

                    const confirmPassword =
                        confirmPasswordInput?.value || '';


                    if (!currentPassword) {
                        showToast(
                            'Enter your current password first.',
                            'Password not updated'
                        );

                        currentPasswordInput?.focus();
                        return;
                    }


                    if (newPassword.length < 8) {
                        showToast(
                            'New password must be at least 8 characters.',
                            'Password not updated'
                        );

                        newPasswordInput?.focus();
                        return;
                    }


                    if (!isMixedPassword(newPassword)) {
                        showToast(
                            'New password must contain both letters and numbers.',
                            'Password not updated'
                        );

                        newPasswordInput?.focus();
                        return;
                    }


                    if (
                        newPassword !==
                        confirmPassword
                    ) {
                        showToast(
                            'New password and confirmation do not match.',
                            'Password not updated'
                        );

                        confirmPasswordInput?.focus();
                        return;
                    }


                    if (
                        newPassword ===
                        currentPassword
                    ) {
                        showToast(
                            'Choose a new password that is different from the current password.',
                            'Password not updated'
                        );

                        newPasswordInput?.focus();
                        return;
                    }


                    /*
                    * Front-end prototype only.
                    * Never persist real password values.
                    */
                    currentPasswordInput.value = '';
                    newPasswordInput.value = '';
                    confirmPasswordInput.value = '';


                    syncPasswordValidation();


                    showToast(
                        'Password validation passed. Backend authentication can handle the real update later.',
                        'Password check passed'
                    );
                }
            );


        /*
        * Initialize password controls.
        */
        syncPasswordValidation();

        // Current admin identity sync across Admin pages
        const ADMIN_PROFILE_STORAGE_KEY = 'bearlyAdminProfilePreview';

        const getStoredAdminProfile = () => {
            try {
                const raw = localStorage.getItem(ADMIN_PROFILE_STORAGE_KEY);
                return raw ? JSON.parse(raw) : null;
            } catch (error) {
                return null;
            }
        };

        const applyCurrentAdminIdentity = (profile) => {
            if (!profile) return;

            const firstName = String(profile.first_name || '').trim();
            const lastName = String(profile.last_name || '').trim();
            const fullName = `${firstName} ${lastName}`.trim();
            const email = String(profile.email || '').trim();
            const initials = `${firstName.charAt(0)}${lastName.charAt(0)}`.toUpperCase();

            if (fullName) {
                document.querySelectorAll('[data-current-admin-name]').forEach((element) => {
                    element.textContent = fullName;
                });
            }

            if (email) {
                document.querySelectorAll('[data-current-admin-email]').forEach((element) => {
                    element.textContent = email;
                });
            }

            if (initials) {
                document.querySelectorAll('[data-current-admin-avatar]').forEach((element) => {
                    element.textContent = initials;
                });
            }

            // Keep account form fields in sync after revisiting the page.
            document.querySelectorAll('[data-profile-field]').forEach((field) => {
                const key = field.dataset.profileField;
                if (profile[key] !== undefined && profile[key] !== null) {
                    field.value = profile[key];
                }
            });
        };

        const storedAdminProfile = getStoredAdminProfile();
        if (storedAdminProfile) {
            applyCurrentAdminIdentity(storedAdminProfile);
        }

        // Account profile edit / save preview
        const profileEditButton =
            document.querySelector(
                '[data-profile-edit]'
            );

        const profileSaveButton =
            document.querySelector(
                '[data-profile-save]'
            );

        const profileFields = [
            ...document.querySelectorAll(
                '[data-profile-field]'
            ),
        ];

        const profileState =
            document.querySelector(
                '[data-profile-state]'
            );

        const profileStateIcon =
            document.querySelector(
                '[data-profile-state-icon]'
            );

        const profileStateLabel =
            document.querySelector(
                '[data-profile-state-label]'
            );


        let profileSnapshot = {};


        /*
        * Read the current profile form.
        */
        const collectProfileValues = () =>
            Object.fromEntries(
                profileFields.map(
                    (field) => [
                        field.dataset.profileField,
                        field.value.trim(),
                    ]
                )
            );


        const profileSignature = (
            profile
        ) =>
            JSON.stringify({
                first_name:
                    profile.first_name || '',

                last_name:
                    profile.last_name || '',

                email:
                    profile.email || '',

                phone:
                    profile.phone || '',
            });


        const setProfileState = (
            state
        ) => {
            if (profileState) {
                profileState.dataset.state =
                    state;
            }


            if (state === 'dirty') {
                if (profileStateLabel) {
                    profileStateLabel.textContent =
                        'Unsaved changes';
                }

                if (profileStateIcon) {
                    profileStateIcon.setAttribute(
                        'data-lucide',
                        'circle-alert'
                    );
                }

                refreshIcons();
                return;
            }


            if (profileStateLabel) {
                profileStateLabel.textContent =
                    'Profile saved';
            }

            if (profileStateIcon) {
                profileStateIcon.setAttribute(
                    'data-lucide',
                    'circle-check'
                );
            }

            refreshIcons();
        };


        const setProfileEditMode = (
            editing
        ) => {
            profileFields.forEach(
                (field) => {
                    field.readOnly =
                        !editing;
                }
            );


            if (!editing && profileSaveButton) {
                profileSaveButton.disabled =
                    true;
            }


            if (profileEditButton) {
                profileEditButton.innerHTML =
                    editing
                        ? `
                            <i data-lucide="x"></i>
                            Cancel
                        `
                        : `
                            <i data-lucide="pencil"></i>
                            Edit
                        `;
            }


            refreshIcons();
        };


        /*
        * Check whether an editable field
        * actually differs from the snapshot.
        */
        const syncProfileDirtyState = () => {
            const current =
                collectProfileValues();

            const changed =
                profileSignature(current) !==
                profileSignature(
                    profileSnapshot
                );


            if (profileSaveButton) {
                profileSaveButton.disabled =
                    !changed;
            }


            setProfileState(
                changed
                    ? 'dirty'
                    : 'saved'
            );
        };


        profileFields.forEach(
            (field) => {
                field.addEventListener(
                    'input',
                    syncProfileDirtyState
                );
            }
        );


        profileEditButton
            ?.addEventListener(
                'click',
                () => {

                    const currentlyEditing =
                        profileFields.some(
                            (field) =>
                                !field.readOnly
                        );


                    /*
                    * Enter edit mode.
                    */
                    if (!currentlyEditing) {

                        profileSnapshot =
                            collectProfileValues();


                        setProfileEditMode(
                            true
                        );


                        setProfileState(
                            'saved'
                        );


                        if (profileSaveButton) {
                            profileSaveButton.disabled =
                                true;
                        }


                        profileFields[0]
                            ?.focus();


                        return;
                    }


                    /*
                    * Cancel edit mode.
                    * Restore original field values.
                    */
                    profileFields.forEach(
                        (field) => {

                            const key =
                                field.dataset
                                    .profileField;

                            if (
                                Object.prototype
                                    .hasOwnProperty
                                    .call(
                                        profileSnapshot,
                                        key
                                    )
                            ) {
                                field.value =
                                    profileSnapshot[key];
                            }

                        }
                    );


                    setProfileEditMode(
                        false
                    );


                    setProfileState(
                        'saved'
                    );


                    showToast(
                        'Unsaved profile changes were discarded.',
                        'Changes cancelled'
                    );
                }
            );


        profileSaveButton
            ?.addEventListener(
                'click',
                () => {

                    const values =
                        collectProfileValues();


                    if (
                        !values.first_name ||
                        !values.last_name ||
                        !values.email
                    ) {
                        showToast(
                            'First name, last name, and email are required.',
                            'Profile not saved'
                        );

                        return;
                    }


                    const emailField =
                        profileFields.find(
                            (field) =>
                                field.dataset
                                    .profileField ===
                                'email'
                        );


                    if (
                        emailField &&
                        !emailField.checkValidity()
                    ) {
                        showToast(
                            'Enter a valid email address before saving.',
                            'Profile not saved'
                        );

                        emailField.focus();
                        return;
                    }


                    localStorage.setItem(
                        ADMIN_PROFILE_STORAGE_KEY,
                        JSON.stringify(values)
                    );


                    applyCurrentAdminIdentity(
                        values
                    );


                    profileSnapshot = {
                        ...values,
                    };


                    setProfileEditMode(
                        false
                    );


                    setProfileState(
                        'saved'
                    );


                    showToast(
                        'Admin profile changes were saved in this preview.',
                        'Profile updated'
                    );
                }
            );


        /*
        * Initial Account page state.
        */
        profileSnapshot =
            collectProfileValues();

        setProfileEditMode(
            false
        );

        setProfileState(
            'saved'
        );

        // Report filters
        const reportStartInput = document.querySelector('[data-report-start]');
        const reportEndInput = document.querySelector('[data-report-end]');
        const reportTypeInput = document.querySelector('[data-report-type]');
        const reportFilterButton = document.querySelector('[data-apply-report-filters]');
        const reportFilterSummary = document.querySelector('[data-report-filter-summary]');

        const formatReportDate = (value) => {
            if (!value) return 'Not set';

            const date = new Date(`${value}T00:00:00`);
            if (Number.isNaN(date.getTime())) return value;

            return new Intl.DateTimeFormat('en-PH', {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
            }).format(date);
        };

        const applyReportFilters = () => {
            if (!reportFilterButton || !reportTypeInput) return;

            const startValue = reportStartInput?.value || '';
            const endValue = reportEndInput?.value || '';

            if (startValue && endValue && startValue > endValue) {
                showToast('The start date must be on or before the end date.', 'Invalid report period');
                reportStartInput?.focus();
                return;
            }

            const reportType = reportTypeInput.value;
            const sectionByType = {
                'Sales Summary': 'sales',
                'Commission Report': 'commission',
                'Seller Settlement Report': 'settlement',
                'Refund Report': 'refund',
            };
            const activeSection = sectionByType[reportType] || 'sales';

            document.querySelectorAll('[data-report-section]').forEach((section) => {
                section.hidden = section.dataset.reportSection !== activeSection;
            });

            if (reportFilterSummary) {
                reportFilterSummary.hidden = false;
                reportFilterSummary.textContent =
                    `${reportType} • ${formatReportDate(startValue)} to ${formatReportDate(endValue)}`;
            }

            showToast(
                `${reportType} view applied for the selected report period.`,
                'Report filters applied'
            );
        };

        reportFilterButton?.addEventListener('click', applyReportFilters);

        // Report exports
        const getReportExportData = () => {
            const reportType = reportTypeInput?.value || 'Sales Summary';

            const kpis = [...document.querySelectorAll('.report-kpi')].map((card) => ({
                label: card.querySelector('p')?.textContent?.trim() || '',
                value: card.querySelector('strong')?.textContent?.trim() || '',
                note: card.querySelector('small')?.textContent?.trim() || '',
            }));

            const activeTable = [...document.querySelectorAll('[data-report-section]')]
                .find((section) => !section.hidden)
                ?.querySelector('[data-report-export-table]');
            const tableHeaders = activeTable
                ? [...activeTable.querySelectorAll('thead th')].map((cell) => cell.textContent.trim())
                : [];
            const tableRows = activeTable
                ? [...activeTable.querySelectorAll('tbody tr')].map((row) =>
                    [...row.querySelectorAll('td')].map((cell) => cell.textContent.trim().replace(/\s+/g, ' '))
                )
                : [];

            return {
                reportType,
                startDate: reportStartInput?.value || '',
                endDate: reportEndInput?.value || '',
                kpis,
                tableHeaders,
                tableRows,
            };
        };

        const downloadReportCsv = () => {
            const report = getReportExportData();

            const rows = [
                ['Bearly Admin Report'],
                ['Report Type', report.reportType],
                ['Start Date', report.startDate],
                ['End Date', report.endDate],
                [],
                ['Summary Metrics'],
                ['Metric', 'Value', 'Note'],
                ...report.kpis.map((item) => [item.label, item.value, item.note]),
                [],
                ['Report Details'],
                report.tableHeaders,
                ...report.tableRows,
            ];

            const csv = '\uFEFF' + rows
                .map((row) => row.map(escapeCsvValue).join(','))
                .join('\r\n');

            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            const dateStamp = new Date().toISOString().slice(0, 10);

            link.href = url;
            link.download = `bearly-admin-report-${dateStamp}.csv`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);

            showToast('The current report data was exported as CSV.', 'Report exported');
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
                        <div class="meta"><strong>Period:</strong> ${escapeReportHtml(report.startDate || '—')} to ${escapeReportHtml(report.endDate || '—')}</div>
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
                const type = button.dataset.exportReport;

                if (type === 'csv') {
                    downloadReportCsv();
                    return;
                }

                if (type === 'pdf') {
                    openReportPrintView();
                }
            });
        });

        // Finance ledgers and seller payment status
        const PAYMENT_STATUS_STORAGE_KEY = 'bearlyAdminPaymentStatuses';
        const paymentStatuses = (() => {
            try {
                return JSON.parse(localStorage.getItem(PAYMENT_STATUS_STORAGE_KEY) || '{}');
            } catch {
                return {};
            }
        })();

        const financeStatusClass = (status) => {
            if (status === 'Completed' || status === 'Paid') return 'badge-success';
            if (status === 'Refunded' || status === 'Failed' || status === 'On Hold') return 'badge-danger';
            if (status === 'Processing') return 'badge-info';
            return 'badge-warning';
        };

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

        document.querySelectorAll('[data-payment-id]').forEach((row) => {
            const id = row.dataset.paymentId;
            const select = row.querySelector('[data-payment-status]');
            const badge = row.querySelector('.js-payment-status');
            const savedPayment = paymentStatuses[id];
            const savedStatus = typeof savedPayment === 'string' ? savedPayment : savedPayment?.status;
            const savedReference = typeof savedPayment === 'object' ? savedPayment?.reference : '';
            const applyPaymentStatus = (status) => {
                row.dataset.status = status;
                if (select) select.value = status;
                if (badge) {
                    badge.textContent = status;
                    badge.className = `status-badge js-payment-status ${financeStatusClass(status)}`;
                }
            };

            applyPaymentStatus(savedStatus || row.dataset.status || 'Pending');
            const reference = row.querySelector('[data-payment-reference]');
            if (savedReference && reference) reference.textContent = savedReference;
            select?.addEventListener('change', () => {
                applyPaymentStatus(select.value);
                if (select.value === 'Paid' && reference?.textContent.trim() === '—') {
                    reference.textContent = `DEMO-${Date.now().toString().slice(-6)}`;
                }
                paymentStatuses[id] = {
                    status: select.value,
                    reference: reference?.textContent.trim() || '—',
                };
                localStorage.setItem(PAYMENT_STATUS_STORAGE_KEY, JSON.stringify(paymentStatuses));

                const module = row.closest('[data-finance-module]');
                if (module) applyFinanceFilters(module);
                showToast(`${id} changed to ${select.value}.`, 'Payment status saved');
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

        // User account status persistence
        const USER_STATUS_STORAGE_KEY = 'bearlyAdminUserStatuses';
        const userStatuses = (() => {
            try {
                return JSON.parse(localStorage.getItem(USER_STATUS_STORAGE_KEY) || '{}');
            } catch {
                return {};
            }
        })();

        const syncUserProfileModalActions = (userId, status) => {
            if (!userId) return;

            document
                .querySelectorAll(
                    `[data-user-modal-actions][data-user-id="${userId}"]`
                )
                .forEach((actions) => {

                    actions
                        .querySelectorAll('[data-modal-user-status]')
                        .forEach((button) => {

                            const targetStatus =
                                button.dataset.modalUserStatus;

                            if (status === 'Active') {
                                button.hidden =
                                    targetStatus === 'Active';
                            }

                            else if (status === 'Suspended') {
                                button.hidden =
                                    targetStatus === 'Suspended';
                            }

                            else if (status === 'Deactivated') {
                                button.hidden =
                                    targetStatus !== 'Active';
                            }

                        });

                });
        };


        const syncUserAccountControls = (row, status) => {
            if (!row) return;

            const primaryButton =
                row.querySelector('[data-user-primary-action]');

            const primaryIcon =
                primaryButton?.querySelector('i');

            const primaryLabel =
                primaryButton?.querySelector('span');


            if (primaryButton) {

                if (status === 'Active') {

                    primaryButton.dataset.userStatus =
                        'Suspended';

                    if (primaryIcon) {
                        primaryIcon.setAttribute(
                            'data-lucide',
                            'pause-circle'
                        );
                    }

                    if (primaryLabel) {
                        primaryLabel.textContent =
                            'Suspend';
                    }

                }

                else {

                    primaryButton.dataset.userStatus =
                        'Active';

                    if (primaryIcon) {
                        primaryIcon.setAttribute(
                            'data-lucide',
                            'circle-check-big'
                        );
                    }

                    if (primaryLabel) {
                        primaryLabel.textContent =
                            'Activate';
                    }

                }

            }


            row
                .querySelectorAll('[data-user-menu-action]')
                .forEach((button) => {

                    const targetStatus =
                        button.dataset.userMenuAction;

                    const primaryTarget =
                        primaryButton?.dataset.userStatus || '';

                    button.hidden =
                        targetStatus === status ||
                        targetStatus === primaryTarget;

                });


            refreshIcons();
        };


        const applyUserStatus = (row, status) => {
            const badge = row?.querySelector('.js-status-badge, .status-badge');

            if (!row || !badge) return;

            row.dataset.status = status;

            badge.textContent = status;

            badge.className =
                'status-badge js-status-badge ' +
                (
                    status === 'Active'
                        ? 'badge-success'
                        : status === 'Suspended'
                            ? 'badge-danger'
                            : 'badge-neutral'
                );

            syncUserAccountControls(row, status);

            if (row.dataset.userId) {
                syncUserProfileModalActions(
                    row.dataset.userId,
                    status
                );
            }
        };

        document
            .querySelectorAll('[data-modal-user-status]')
            .forEach((button) => {

                button.dataset.functionalAction = 'user-status';

                button.addEventListener('click', () => {

                    const modal = button.closest('[data-modal]');
                    const userId =
                        modal
                            ?.querySelector('.eyebrow')
                            ?.textContent
                            .trim();

                    if (!userId) return;

                    const status =
                        button.dataset.modalUserStatus;

                    const row = document.querySelector(
                        `tr[data-user-id="${userId}"]`
                    );

                    if (row) {
                        applyUserStatus(row, status);
                    }

                    updateUserProfileModal(
                        userId,
                        status
                    );

                    userStatuses[userId] = status;

                    localStorage.setItem(
                        USER_STATUS_STORAGE_KEY,
                        JSON.stringify(userStatuses)
                    );

                    showToast(
                        `${userId} changed to ${status}.`,
                        'Account status saved'
                    );
                });

            });

        const updateUserProfileModal = (userId, status) => {
            document.querySelectorAll('[data-modal]').forEach((modal) => {
                if (modal.querySelector('.eyebrow')?.textContent.trim() !== userId) return;
                [...modal.querySelectorAll('.detail-grid > div')].forEach((detail) => {
                    if (detail.querySelector('span')?.textContent.trim().toLowerCase() !== 'current status') return;
                    const value = detail.querySelector('strong');
                    if (value) value.textContent = status;
                });
            });
        };

        document.querySelectorAll('tr[data-user-id]').forEach((row) => {
            const savedStatus = userStatuses[row.dataset.userId];

            const initialStatus =
                savedStatus ||
                row.dataset.status ||
                row.querySelector('.js-status-badge')?.textContent.trim() ||
                'Active';

            applyUserStatus(row, initialStatus);
        });
        Object.entries(userStatuses).forEach(([userId, status]) => {
            const row = [...document.querySelectorAll('tr[data-table-row]')]
                .find((item) => (item.dataset.search || '').includes(userId.toLowerCase()));
            if (row) {
                row.dataset.userId = userId;
                applyUserStatus(row, status);
            }
            updateUserProfileModal(userId, status);
        });

        document.querySelectorAll('[data-user-status]').forEach((button) => {
            button.addEventListener('click', () => {
                const row = button.closest('tr[data-user-id]');

                if (!row) return;

                const status = button.dataset.userStatus;

                if (!status) return;

                applyUserStatus(row, status);

                if (row.dataset.userId) {
                    userStatuses[row.dataset.userId] = status;

                    localStorage.setItem(
                        USER_STATUS_STORAGE_KEY,
                        JSON.stringify(userStatuses)
                    );
                }

                closeRegistrationMenus();

                showToast(
                    `Account status changed to ${status}.`,
                    'Account status saved'
                );
            });
        });

        document
            .querySelectorAll(
                '[data-mock-action]:not([data-modal-user-status])'
            )
            .forEach((button) => {

                const action =
                    button.dataset.mockAction.toLowerCase();

                if (
                    !action.includes('account activated') &&
                    !action.includes('account suspended') &&
                    !action.includes('account deactivated')
                ) return;

                button.dataset.functionalAction =
                    'user-status';

                button.addEventListener('click', () => {

                    const userId =
                        button
                            .closest('[data-modal]')
                            ?.querySelector('.eyebrow')
                            ?.textContent
                            .trim();

                    if (!userId) return;

                    const status =
                        action.includes('deactivated')
                            ? 'Deactivated'
                            : action.includes('suspended')
                                ? 'Suspended'
                                : 'Active';

                    const row = [
                        ...document.querySelectorAll(
                            'tr[data-table-row]'
                        )
                    ].find((item) =>
                        (item.dataset.search || '')
                            .includes(userId.toLowerCase())
                    );

                    if (row) {
                        row.dataset.userId = userId;
                        applyUserStatus(row, status);
                    }

                    updateUserProfileModal(
                        userId,
                        status
                    );

                    userStatuses[userId] = status;

                    localStorage.setItem(
                        USER_STATUS_STORAGE_KEY,
                        JSON.stringify(userStatuses)
                    );

                    showToast(
                        `${userId} changed to ${status}.`,
                        'Account status saved'
                    );

                });

            });
        
        // Product violation enforcement state + persistence
        const VIOLATION_STATE_STORAGE_KEY = 'bearlyAdminViolationStates';

        const violationStates = (() => {
            try {
                return JSON.parse(
                    localStorage.getItem(VIOLATION_STATE_STORAGE_KEY) || '{}'
                );
            } catch {
                return {};
            }
        })();

        const saveViolationStates = () => {
            localStorage.setItem(
                VIOLATION_STATE_STORAGE_KEY,
                JSON.stringify(violationStates)
            );
        };

        const violationStatusClass = (status) => {
            if (status === 'Resolved') {
                return 'badge-success';
            }

            if (
                status === 'Escalated' ||
                status === 'Removal Required'
            ) {
                return 'badge-danger';
            }

            if (status === 'Pending Review') {
                return 'badge-warning';
            }

            return 'badge-info';
        };

        const syncViolationActions = (modal, status) => {
            if (!modal) return;

            const warningButton = modal.querySelector(
                '[data-violation-button="warning"]'
            );

            const removalButton = modal.querySelector(
                '[data-violation-button="removal"]'
            );

            const escalateButton = modal.querySelector(
                '[data-violation-button="escalate"]'
            );

            const indicator = modal.querySelector(
                '[data-violation-state-indicator]'
            );

            const indicatorLabel = modal.querySelector(
                '[data-violation-state-label]'
            );

            /*
            * Default state:
            * Pending Review / Under Review
            *
            * Show all enforcement actions.
            */
            if (
                status === 'Pending Review' ||
                status === 'Under Review'
            ) {
                if (warningButton) warningButton.hidden = false;
                if (removalButton) removalButton.hidden = false;
                if (escalateButton) escalateButton.hidden = false;

                if (indicator) indicator.hidden = true;

                return;
            }

            /*
            * Removal Required:
            *
            * The removal action has already happened,
            * so only escalation remains available.
            */
            if (status === 'Removal Required') {
                if (warningButton) warningButton.hidden = true;
                if (removalButton) removalButton.hidden = true;
                if (escalateButton) escalateButton.hidden = false;

                if (indicator) indicator.hidden = false;

                if (indicatorLabel) {
                    indicatorLabel.textContent = 'Removal required';
                }

                return;
            }

            /*
            * Escalated:
            *
            * No further enforcement actions are shown.
            */
            if (status === 'Escalated') {
                if (warningButton) warningButton.hidden = true;
                if (removalButton) removalButton.hidden = true;
                if (escalateButton) escalateButton.hidden = true;

                if (indicator) indicator.hidden = false;

                if (indicatorLabel) {
                    indicatorLabel.textContent = 'Seller escalated';
                }

                return;
            }

            /*
            * Resolved:
            *
            * Record is already complete.
            */
            if (status === 'Resolved') {
                if (warningButton) warningButton.hidden = true;
                if (removalButton) removalButton.hidden = true;
                if (escalateButton) escalateButton.hidden = true;

                if (indicator) indicator.hidden = false;

                if (indicatorLabel) {
                    indicatorLabel.textContent = 'Violation resolved';
                }
            }
        };

        const syncViolationState = (violationId) => {
            if (!violationId) return;

            const row = document.querySelector(
                `tr[data-violation-id="${violationId}"]`
            );

            if (!row) return;

            const saved = violationStates[violationId] || {};

            const status =
                saved.status ||
                row.dataset.status ||
                'Under Review';

            const warnings =
                saved.warnings !== undefined
                    ? Number(saved.warnings)
                    : Number(row.dataset.warnings || 0);

            /*
            * Keep the row's data attributes updated.
            * This is important because the reusable filters
            * read data-status directly.
            */
            row.dataset.status = status;
            row.dataset.warnings = String(warnings);

            /*
            * Update warning count in the table.
            */
            const warningCount = row.querySelector(
                '[data-violation-warning-count]'
            );

            if (warningCount) {
                warningCount.textContent = String(warnings);
            }

            /*
            * Update status badge in the table.
            */
            const statusBadge = row.querySelector(
                '.js-violation-status'
            );

            if (statusBadge) {
                statusBadge.textContent = status;

                statusBadge.className =
                    `status-badge js-violation-status ${violationStatusClass(status)}`;
            }

            /*
            * Update matching review modal.
            */
            const modal = document.querySelector(
                `[data-violation-modal][data-violation-id="${violationId}"]`
            );

            if (modal) {
                const modalWarnings = modal.querySelector(
                    '[data-modal-violation-warnings]'
                );

                const modalStatus = modal.querySelector(
                    '[data-modal-violation-status]'
                );

                if (modalWarnings) {
                    modalWarnings.textContent = String(warnings);
                }

                if (modalStatus) {
                    modalStatus.textContent = status;
                }

                syncViolationActions(
                    modal,
                    status
                );
            }

            /*
            * Re-run the Product Violations filters after
            * a status changes.
            */
            runTableFilter('violations-table');

            refreshIcons();
        };

        /*
        * Initialize Product Violations state.
        *
        * If no saved state exists yet, use the values
        * rendered by Laravel as the starting state.
        */
        document
            .querySelectorAll('tr[data-violation-id]')
            .forEach((row) => {
                const violationId = row.dataset.violationId;

                if (!violationId) return;

                if (!violationStates[violationId]) {
                    violationStates[violationId] = {
                        status:
                            row.dataset.status ||
                            'Under Review',

                        warnings:
                            Number(row.dataset.warnings || 0),
                    };
                }

                syncViolationState(violationId);
            });

        saveViolationStates();


        /*
        * Product violation enforcement actions
        */
        document
            .querySelectorAll('[data-violation-action]')
            .forEach((button) => {

                /*
                * This prevents the generic data-mock-action
                * handler near the top of admin.js from also
                * firing for these buttons.
                */
                button.dataset.functionalAction =
                    'product-violation';

                button.addEventListener('click', () => {

                    const modal = button.closest(
                        '[data-violation-modal]'
                    );

                    const violationId =
                        modal?.dataset.violationId;

                    if (!violationId) return;

                    const row = document.querySelector(
                        `tr[data-violation-id="${violationId}"]`
                    );

                    if (!row) return;

                    const current =
                        violationStates[violationId] || {
                            status:
                                row.dataset.status ||
                                'Under Review',

                            warnings:
                                Number(row.dataset.warnings || 0),
                        };

                    const action =
                        button.dataset.violationAction;


                    /*
                    * ISSUE WARNING
                    *
                    * Warning count increases.
                    * Status stays in its current review state.
                    */
                    if (action === 'warning') {
                        current.warnings =
                            Number(current.warnings || 0) + 1;

                        violationStates[violationId] =
                            current;

                        saveViolationStates();

                        syncViolationState(
                            violationId
                        );

                        showToast(
                            `${violationId} now has ${current.warnings} warning${current.warnings === 1 ? '' : 's'}.`,
                            'Warning issued'
                        );

                        return;
                    }


                    /*
                    * REQUIRE REMOVAL
                    */
                    if (action === 'removal') {
                        current.status =
                            'Removal Required';

                        violationStates[violationId] =
                            current;

                        saveViolationStates();

                        syncViolationState(
                            violationId
                        );

                        showToast(
                            `${violationId} was marked for product removal.`,
                            'Removal required'
                        );

                        return;
                    }


                    /*
                    * ESCALATE SELLER
                    */
                    if (action === 'escalate') {
                        current.status =
                            'Escalated';

                        violationStates[violationId] =
                            current;

                        saveViolationStates();

                        syncViolationState(
                            violationId
                        );

                        showToast(
                            `${violationId} was escalated for seller account review.`,
                            'Seller escalated'
                        );
                    }
                });
            });

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

            // Product Violations are now handled separately.
            if (recordId.startsWith('VIO-')) {
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

                const savedStatus =
                    workflowStatuses[returnId];

                const initialStatus =
                    savedStatus ||
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

        document.querySelectorAll('[data-mock-action]').forEach((button) => {

            // Product Violations use their own dedicated state system.
            if (button.dataset.violationAction) {
                return;
            }

            const action = button.dataset.mockAction.toLowerCase();

            let status = null;

            if (action.includes('approved for refund')) {
                status = 'Approved';
            }

            else if (action.includes('refund request rejected')) {
                status = 'Rejected';
            }

            else if (action.includes('additional evidence requested')) {
                status = 'Awaiting Evidence';
            }

            else if (action.includes('warning issued')) {
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
        const formatPeso = (number) => `₱${Number(number).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        const calculateCommission = () => {
            const gross = Math.max(0, Number(commissionInput?.value || 0));
            if (commissionOutput) commissionOutput.textContent = formatPeso(gross * .10);
            if (sellerNet) sellerNet.textContent = formatPeso(gross * .90);
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
       

        // Dispute queue search + dynamic master-detail workspace
        const disputeSearch = document.querySelector('[data-dispute-search]');
        const disputeDataNode = document.getElementById('dispute-preview-data');
        let disputeData = {};
        try { disputeData = disputeDataNode ? JSON.parse(disputeDataNode.textContent) : {}; } catch {}
        const DISPUTE_STORAGE_KEY = 'bearlyAdminDisputeState';
        let savedDisputeState = {};
        try { savedDisputeState = JSON.parse(localStorage.getItem(DISPUTE_STORAGE_KEY) || '{}'); } catch {}
        Object.entries(savedDisputeState).forEach(([id, state]) => {
            if (disputeData[id]) Object.assign(disputeData[id], state);
        });
        const saveDisputeState = (id) => {
            if (!disputeData[id]) return;
            savedDisputeState[id] = {
                status: disputeData[id].status,
                internalNote: disputeData[id].internalNote || '',
                resolutionOutcome: disputeData[id].resolutionOutcome || '',
            };
            localStorage.setItem(DISPUTE_STORAGE_KEY, JSON.stringify(savedDisputeState));
        };

        const setDisputeText = (selector, value) => {
            const el = document.querySelector(selector);
            if (el) el.textContent = value ?? '';
        };
        const escapeDisputeHtml = (value = '') => String(value)
            .replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;')
            .replaceAll('"','&quot;').replaceAll("'",'&#039;');

        const renderEvidence = (items = []) => {
            const box = document.querySelector('[data-dispute-evidence]');
            if (!box) return;
            box.innerHTML = items.map(item => `
                <button type="button" class="evidence-card" data-dynamic-evidence="${escapeDisputeHtml(item.label)}">
                    <span><i data-lucide="${item.type === 'Image' ? 'image' : 'file-text'}"></i></span>
                    <div><strong>${escapeDisputeHtml(item.label)}</strong><small>${escapeDisputeHtml(item.meta)}</small></div>
                    <i data-lucide="external-link"></i>
                </button>`).join('');
            box.querySelectorAll('[data-dynamic-evidence]').forEach(button => button.addEventListener('click', () =>
                showToast(`${button.dataset.dynamicEvidence} preview opened.`, 'Evidence preview')
            ));
            refreshIcons();
        };

        const renderTimeline = (items = []) => {
            const box = document.querySelector('[data-dispute-timeline]');
            if (!box) return;
            box.innerHTML = items.map(item => `
                <div class="timeline-row"><span>${escapeDisputeHtml(item.time)}</span>
                <div><i></i><p>${escapeDisputeHtml(item.text)}</p></div></div>`).join('');
        };

        const renderDispute = (id, notify = true) => {
            const d = disputeData[id];
            if (!d) return;
            setDisputeText('[data-dispute-id]', d.id);
            setDisputeText('[data-dispute-subject]', d.subject);
            setDisputeText('[data-dispute-status]', d.status);
            setDisputeText('[data-dispute-opened]', d.opened);
            setDisputeText('[data-dispute-buyer]', d.buyer);
            setDisputeText('[data-dispute-seller]', d.seller);
            setDisputeText('[data-dispute-courier]', d.courier);
            setDisputeText('[data-dispute-amount]', d.amount);
            setDisputeText('[data-dispute-summary]', d.summary);
            const priority = document.querySelector('[data-dispute-priority]');
            if (priority) {
                priority.textContent = `${d.priority} priority`;
                priority.className = 'status-badge ' + (
                    String(d.priority).toLowerCase() === 'high' ? 'badge-danger' :
                    String(d.priority).toLowerCase() === 'medium' ? 'badge-warning' : 'badge-success'
                );
                priority.setAttribute('data-dispute-priority','');
            }
            setDisputeText('[data-party-buyer]', d.buyer);
            setDisputeText('[data-party-seller]', d.seller);
            setDisputeText('[data-party-courier]', d.courier);

            const note = document.querySelector('[data-dispute-note]');
            if (note) note.value = d.internalNote || '';

            renderEvidence(d.evidence);
            renderTimeline(d.timeline);
            if (notify) showToast(`${d.id} — ${d.subject} loaded.`, 'Dispute workspace');
        };

        disputeSearch?.addEventListener('input', () => {
            const query = disputeSearch.value.trim().toLowerCase();
            document.querySelectorAll('[data-case-card]').forEach(card => {
                card.hidden = !!query && !card.dataset.caseSearch.includes(query);
            });
        });
        document.querySelectorAll('[data-case-card]').forEach(card => {
            card.addEventListener('click', () => {
                if (card.classList.contains('is-active')) return;
                document.querySelectorAll('[data-case-card]').forEach(item => item.classList.remove('is-active'));
                card.classList.add('is-active');
                renderDispute(card.dataset.caseId);
            });
        });
        document.querySelectorAll('[data-case-card]').forEach((card) => {
            if (disputeData[card.dataset.caseId]?.status === 'Resolved') card.remove();
        });
        const remainingDisputeCards = [...document.querySelectorAll('[data-case-card]')];
        if (remainingDisputeCards.length && !remainingDisputeCards.some((card) => card.classList.contains('is-active'))) {
            remainingDisputeCards[0].classList.add('is-active');
        }
        const restoredOpenCount = document.querySelector('[data-dispute-open-count]');
        if (restoredOpenCount) restoredOpenCount.textContent = String(remainingDisputeCards.length);
        const getActiveDisputeId = () =>
            document.querySelector('[data-case-card].is-active')?.dataset.caseId || '';

        document.querySelector('[data-dispute-refresh]')?.addEventListener('click', () => {
            const id = getActiveDisputeId();
            if (id) renderDispute(id, false);
            showToast(`${document.querySelectorAll('[data-case-card]').length} open cases loaded.`, 'Dispute queue refreshed');
        });

        document.querySelectorAll('[data-party-message]').forEach((button) => {
            button.addEventListener('click', () => {
                const role = button.dataset.partyMessage;
                const name = document.querySelector(`[data-party-${role}]`)?.textContent?.trim() || role;
                showToast(`Message panel opened for ${name}.`, 'Participant message');
            });
        });

        document.querySelector('[data-dispute-save-note]')?.addEventListener('click', () => {
            const id = getActiveDisputeId();
            const note = document.querySelector('[data-dispute-note]');
            if (!id || !note) return;

            disputeData[id].internalNote = note.value.trim();
            saveDisputeState(id);
            showToast(
                disputeData[id].internalNote ? `Internal note saved for ${id}.` : `Internal note cleared for ${id}.`,
                'Resolution note'
            );
        });

        document.querySelector('[data-dispute-send-update]')?.addEventListener('click', () => {
            const id = getActiveDisputeId();
            if (!id) return;

            const note = document.querySelector('[data-dispute-note]')?.value.trim() || '';
            if (note) disputeData[id].internalNote = note;
            saveDisputeState(id);

            showToast(`Participant update prepared for ${id}.`, 'Case update');
        });

        const resolveModal = document.querySelector('[data-modal="resolve-dispute"]');

        document.querySelector('[data-dispute-resolve]')?.addEventListener('click', () => {
            const id = getActiveDisputeId();
            if (!id || !resolveModal) return;

            setDisputeText('[data-resolve-case-id]', id);

            const outcome = resolveModal.querySelector('[data-resolution-outcome]');
            const note = resolveModal.querySelector('[data-resolution-note]');
            const error = resolveModal.querySelector('[data-resolution-error]');

            if (outcome) outcome.value = '';
            if (note) note.value = disputeData[id]?.internalNote || '';
            if (error) error.hidden = true;

            resolveModal.hidden = false;
            refreshIcons();
        });

        document.querySelector('[data-confirm-resolution]')?.addEventListener('click', () => {
            const id = getActiveDisputeId();
            if (!id || !resolveModal) return;

            const outcome = resolveModal.querySelector('[data-resolution-outcome]')?.value || '';
            const note = resolveModal.querySelector('[data-resolution-note]')?.value.trim() || '';
            const error = resolveModal.querySelector('[data-resolution-error]');

            if (!outcome || !note) {
                if (error) error.hidden = false;
                return;
            }

            const card = document.querySelector(`[data-case-card][data-case-id="${id}"]`);
            if (!card) return;

            disputeData[id].status = 'Resolved';
            disputeData[id].resolutionOutcome = outcome;
            disputeData[id].internalNote = note;
            saveDisputeState(id);

            card.remove();

            const openCount = document.querySelector('[data-dispute-open-count]');
            if (openCount) {
                openCount.textContent = String(document.querySelectorAll('[data-case-card]').length);
            }

            resolveModal.hidden = true;

            const nextCard = document.querySelector('[data-case-card]');
            if (nextCard) {
                document.querySelectorAll('[data-case-card]').forEach(item => item.classList.remove('is-active'));
                nextCard.classList.add('is-active');
                renderDispute(nextCard.dataset.caseId, false);
            } else {
                setDisputeText('[data-dispute-id]', 'No open cases');
                setDisputeText('[data-dispute-subject]', 'Resolution queue complete');
                setDisputeText('[data-dispute-status]', 'Resolved');
                setDisputeText('[data-dispute-opened]', '');
                setDisputeText('[data-dispute-buyer]', '—');
                setDisputeText('[data-dispute-seller]', '—');
                setDisputeText('[data-dispute-courier]', '—');
                setDisputeText('[data-dispute-amount]', '—');
                setDisputeText('[data-dispute-summary]', 'There are no remaining open disputes in this front-end preview.');
                setDisputeText('[data-party-buyer]', '—');
                setDisputeText('[data-party-seller]', '—');
                setDisputeText('[data-party-courier]', '—');
                renderEvidence([]);
                renderTimeline([]);
            }

            showToast(`${id} resolved: ${outcome}.`, 'Case resolved');
        });

        const initialDispute = document.querySelector('[data-case-card].is-active')?.dataset.caseId;
        if (initialDispute) renderDispute(initialDispute, false);

        /// Chat filtering + per-conversation thread state
        const conversationSearch = document.querySelector('[data-conversation-search]');
        let conversationItems = [...document.querySelectorAll('[data-conversation-item]')];

        const conversationList = document.querySelector('[data-conversation-list]');
        const messageThread = document.querySelector('[data-message-thread]');
        const messageInput = document.querySelector('[data-message-input]');
        const attachmentInput = document.querySelector('[data-chat-attachment]');
        const attachmentName = document.querySelector('[data-chat-attachment-name]');
        const unreadSummary = document.querySelector('[data-unread-summary]');

        const CHAT_STORAGE_KEY = 'bearlyAdminChatThreads';


        const escapeChatHtml = (value = '') =>
            String(value)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');


        const defaultChatThreads = {
            'mara-home-goods': [
                {
                    from: 'them',
                    text: 'Good afternoon. We uploaded the additional photos requested for case DSP-1048.',
                    time: '6:34 PM',
                },
                {
                    from: 'me',
                    text: 'Received. We are reviewing the evidence from all parties now.',
                    time: '6:36 PM',
                },
                {
                    from: 'them',
                    text: 'Thank you. Please let us know if you need a clearer copy of the packing photo.',
                    time: '6:42 PM',
                },
            ],

            'karen-yu': [
                {
                    from: 'them',
                    text: 'Hello. I wanted to follow up regarding the complaint I submitted for my recent order.',
                    time: '5:12 PM',
                },
                {
                    from: 'me',
                    text: 'Hi Karen. Your complaint is currently under review. We will update you once the seller response has been checked.',
                    time: '5:16 PM',
                },
                {
                    from: 'them',
                    text: 'Thank you for reviewing my complaint.',
                    time: '5:18 PM',
                },
            ],

            'jared-molina': [
                {
                    from: 'them',
                    text: 'Hi Admin, the delivery proof for the completed order has been uploaded.',
                    time: '3:27 PM',
                },
                {
                    from: 'me',
                    text: 'Thanks, Jared. We received the delivery proof and added it to the order review.',
                    time: '3:29 PM',
                },
                {
                    from: 'them',
                    text: 'Delivery proof has been uploaded.',
                    time: '3:31 PM',
                },
            ],

            'techvault-ph': [
                {
                    from: 'them',
                    text: 'Good afternoon. We received a compliance notice for one of our listings.',
                    time: '1:48 PM',
                },
                {
                    from: 'me',
                    text: 'The notice was triggered by a listing detail that requires manual verification.',
                    time: '1:51 PM',
                },
                {
                    from: 'them',
                    text: 'Can we clarify the compliance notice?',
                    time: '1:54 PM',
                },
            ],
        };


        const chatThreads = (() => {
            try {
                return JSON.parse(
                    localStorage.getItem(CHAT_STORAGE_KEY) || '{}'
                );
            } catch {
                return {};
            }
        })();


        const saveChatThreads = () => {
            localStorage.setItem(
                CHAT_STORAGE_KEY,
                JSON.stringify(chatThreads)
            );
        };


        const getConversationId = (item) =>
            item?.dataset.conversationId ||
            item?.dataset.name
                ?.trim()
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-|-$/g, '') ||
            'conversation';


        const updateUnreadSummary = () => {
            if (!unreadSummary) return;

            const totalUnread = [
                ...document.querySelectorAll(
                    '[data-conversation-item] .unread-count'
                )
            ].reduce((total, badge) => {
                return total + Number(
                    badge.textContent?.trim() || 0
                );
            }, 0);

            unreadSummary.textContent =
                `${totalUnread} unread`;

            unreadSummary.hidden =
                totalUnread === 0;
        };


        const updateConversationPreview = (
            item,
            text,
            time
        ) => {
            if (!item) return;

            item.dataset.preview = text;
            item.dataset.time = time;

            const preview = item.querySelector(
                '.conversation-preview span:last-child'
            );

            const timeElement = item.querySelector(
                '.conversation-meta time'
            );

            if (preview) {
                preview.textContent = text;
            }

            if (timeElement) {
                timeElement.textContent = time;
            }

            item.dataset.search =
                `${item.dataset.name || ''} ` +
                `${item.dataset.role || ''} ` +
                `${text}`.toLowerCase();
        };


        /*
        * Restore conversations created during
        * previous front-end preview sessions.
        */
        (chatThreads.__contacts || []).forEach((contact) => {

            if (!conversationList) return;

            const alreadyExists =
                conversationItems.some(
                    (item) =>
                        getConversationId(item) ===
                        contact.conversationId
                );

            if (alreadyExists) return;


            const item =
                document.createElement('button');

            item.type = 'button';
            item.className = 'conversation-item';

            item.dataset.conversationItem = '';
            item.dataset.conversationId =
                contact.conversationId;

            item.dataset.search =
                contact.search || '';

            item.dataset.name =
                contact.name || '';

            item.dataset.role =
                contact.role || '';

            item.dataset.initials =
                contact.initials || '';

            item.dataset.preview =
                contact.preview || '';

            item.dataset.time =
                contact.time || '';


            item.innerHTML = `
                <span class="avatar avatar-soft">
                    ${escapeChatHtml(contact.initials)}
                </span>

                <span class="conversation-copy">

                    <span class="conversation-meta">
                        <strong>
                            ${escapeChatHtml(contact.name)}
                        </strong>

                        <time>
                            ${escapeChatHtml(contact.time)}
                        </time>
                    </span>

                    <small class="conversation-preview">
                        <span class="conversation-role">
                            ${escapeChatHtml(contact.role)}
                        </span>

                        <span aria-hidden="true">•</span>

                        <span>
                            ${escapeChatHtml(contact.preview)}
                        </span>
                    </small>

                </span>
            `;

            conversationList.prepend(item);

            conversationItems.push(item);
        });


        /*
        * Create thread data for every
        * visible conversation.
        */
        conversationItems.forEach((item, index) => {

            const id =
                getConversationId(item);

            item.dataset.conversationId =
                id;


            chatThreads[id] =
                chatThreads[id] ||
                (
                    defaultChatThreads[id] ||
                    [
                        {
                            from: 'them',
                            text:
                                item.dataset.preview ||
                                'This is the beginning of this support conversation.',
                            time:
                                item.dataset.time || '',
                        },
                    ]
                ).map((message) => ({
                    ...message,
                }));


            /*
            * Preserve the first Blade-rendered thread
            * the first time the chat preview is used.
            */
            if (
                index === 0 &&
                messageThread &&
                !localStorage.getItem(CHAT_STORAGE_KEY)
            ) {
                const bladeMessages = [
                    ...messageThread.querySelectorAll(
                        '.message-row'
                    )
                ]
                    .map((row) => ({
                        from:
                            row.classList.contains('message-me')
                                ? 'me'
                                : 'them',

                        text:
                            row
                                .querySelector('p')
                                ?.textContent
                                ?.trim() || '',

                        time:
                            row
                                .querySelector('span')
                                ?.textContent
                                ?.trim() || '',
                    }))
                    .filter((message) =>
                        message.text
                    );


                if (bladeMessages.length) {
                    chatThreads[id] =
                        bladeMessages;
                }
            }
        });


        saveChatThreads();


        const renderChatThread = (id) => {
            if (!messageThread) return;

            const messages =
                chatThreads[id] || [];


            messageThread.innerHTML = `
                <div class="thread-date">
                    Today
                </div>

                ${messages
                    .map((message) => `
                        <div
                            class="message-row
                            message-${message.from === 'me' ? 'me' : 'them'}"
                        >
                            <div class="message-bubble">

                                <p>
                                    ${escapeChatHtml(message.text)}
                                </p>

                                <span>
                                    ${escapeChatHtml(message.time)}
                                </span>

                            </div>
                        </div>
                    `)
                    .join('')}
            `;


            messageThread.scrollTop =
                messageThread.scrollHeight;
        };


        const clearAttachmentPreview = () => {
            if (attachmentInput) {
                attachmentInput.value = '';
            }

            if (attachmentName) {
                attachmentName.textContent = '';
                attachmentName.hidden = true;
            }
        };


        const selectConversation = (item) => {
            if (!item) return;


            conversationItems.forEach(
                (other) =>
                    other.classList.remove('is-active')
            );

            item.classList.add('is-active');


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
                    item.dataset.name || '';
            }

            if (role) {
                role.textContent =
                    item.dataset.role || '';
            }

            if (avatar) {
                avatar.textContent =
                    item.dataset.initials || '';
            }


            /*
            * Opening the conversation marks
            * its unread messages as read.
            */
            const unread =
                item.querySelector(
                    '.unread-count'
                );

            if (unread) {
                unread.remove();
            }

            updateUnreadSummary();


            clearAttachmentPreview();

            renderChatThread(
                getConversationId(item)
            );
        };


        conversationSearch?.addEventListener(
            'input',
            () => {

                const query =
                    conversationSearch.value
                        .trim()
                        .toLowerCase();


                conversationItems.forEach(
                    (item) => {

                        item.hidden =
                            Boolean(query) &&
                            !(
                                item.dataset.search || ''
                            ).includes(query);

                    }
                );
            }
        );


        const bindConversation = (item) => {
            item.addEventListener(
                'click',
                () =>
                    selectConversation(item)
            );
        };


        conversationItems.forEach(
            bindConversation
        );


        /*
        * Send message
        */
        const sendMessage = () => {

            const text =
                messageInput?.value.trim();

            const activeItem =
                document.querySelector(
                    '[data-conversation-item].is-active'
                );


            if (
                !text ||
                !activeItem ||
                !messageThread
            ) {
                return;
            }


            const id =
                getConversationId(activeItem);

            const time =
                new Date()
                    .toLocaleTimeString(
                        [],
                        {
                            hour: 'numeric',
                            minute: '2-digit',
                        }
                    );


            if (!chatThreads[id]) {
                chatThreads[id] = [];
            }


            chatThreads[id].push({
                from: 'me',
                text,
                time,
            });


            updateConversationPreview(
                activeItem,
                text,
                time
            );


            const storedContact =
                (chatThreads.__contacts || [])
                    .find(
                        (contact) =>
                            contact.conversationId === id
                    );

            if (storedContact) {
                storedContact.preview =
                    text;

                storedContact.time =
                    time;

                storedContact.search =
                    `${activeItem.dataset.name || ''} ` +
                    `${activeItem.dataset.role || ''} ` +
                    `${text}`.toLowerCase();
            }


            saveChatThreads();


            messageInput.value = '';

            clearAttachmentPreview();

            renderChatThread(id);


            showToast(
                `Message added to ${activeItem.dataset.name}'s conversation preview.`,
                'Message sent'
            );
        };


        document
            .querySelector('[data-send-message]')
            ?.addEventListener(
                'click',
                sendMessage
            );


        messageInput?.addEventListener(
            'keydown',
            (event) => {

                if (
                    event.key === 'Enter' &&
                    !event.shiftKey
                ) {
                    event.preventDefault();
                    sendMessage();
                }
            }
        );


        /*
        * Create new conversation
        */
        document
            .querySelector(
                '[data-create-conversation]'
            )
            ?.addEventListener(
                'click',
                () => {

                    const nameInput =
                        document.querySelector(
                            '[data-new-chat-name]'
                        );

                    const roleInput =
                        document.querySelector(
                            '[data-new-chat-role]'
                        );

                    const firstMessage =
                        document.querySelector(
                            '[data-new-chat-message]'
                        );


                    const name =
                        nameInput?.value
                            .trim() || '';

                    const role =
                        roleInput?.value ||
                        'Buyer';

                    const text =
                        firstMessage?.value
                            .trim() || '';


                    if (
                        !name ||
                        !text ||
                        !conversationList
                    ) {
                        showToast(
                            'Enter a recipient and a first message.',
                            'Conversation not started'
                        );

                        return;
                    }


                    let id =
                        name
                            .toLowerCase()
                            .replace(
                                /[^a-z0-9]+/g,
                                '-'
                            )
                            .replace(
                                /^-|-$/g,
                                ''
                            ) ||
                        `conversation-${Date.now()}`;


                    if (chatThreads[id]) {
                        id =
                            `${id}-${Date.now()
                                .toString()
                                .slice(-4)}`;
                    }


                    const initials =
                        name
                            .split(/\s+/)
                            .map(
                                (part) =>
                                    part[0]
                            )
                            .join('')
                            .slice(0, 2)
                            .toUpperCase();


                    const time =
                        new Date()
                            .toLocaleTimeString(
                                [],
                                {
                                    hour: 'numeric',
                                    minute: '2-digit',
                                }
                            );


                    const item =
                        document.createElement(
                            'button'
                        );


                    item.type = 'button';

                    item.className =
                        'conversation-item';

                    item.dataset.conversationItem =
                        '';

                    item.dataset.conversationId =
                        id;

                    item.dataset.search =
                        `${name} ${role} ${text}`
                            .toLowerCase();

                    item.dataset.name =
                        name;

                    item.dataset.role =
                        role;

                    item.dataset.initials =
                        initials;

                    item.dataset.preview =
                        text;

                    item.dataset.time =
                        time;


                    item.innerHTML = `
                        <span class="avatar avatar-soft">
                            ${escapeChatHtml(initials)}
                        </span>

                        <span class="conversation-copy">

                            <span class="conversation-meta">

                                <strong>
                                    ${escapeChatHtml(name)}
                                </strong>

                                <time>
                                    ${escapeChatHtml(time)}
                                </time>

                            </span>

                            <small class="conversation-preview">

                                <span class="conversation-role">
                                    ${escapeChatHtml(role)}
                                </span>

                                <span aria-hidden="true">
                                    •
                                </span>

                                <span>
                                    ${escapeChatHtml(text)}
                                </span>

                            </small>

                        </span>
                    `;


                    conversationList.prepend(
                        item
                    );


                    conversationItems.push(
                        item
                    );


                    bindConversation(
                        item
                    );


                    chatThreads[id] = [
                        {
                            from: 'me',
                            text,
                            time,
                        },
                    ];


                    chatThreads.__contacts = [
                        {
                            conversationId:
                                id,

                            search:
                                item.dataset.search,

                            name,
                            role,
                            initials,

                            preview:
                                text,

                            time,
                        },

                        ...(
                            chatThreads.__contacts ||
                            []
                        ).filter(
                            (contact) =>
                                contact.conversationId !==
                                id
                        ),
                    ];


                    saveChatThreads();


                    selectConversation(
                        item
                    );


                    closeModal(
                        document.querySelector(
                            '[data-modal="new-conversation"]'
                        )
                    );


                    if (nameInput) {
                        nameInput.value = '';
                    }

                    if (roleInput) {
                        roleInput.value = 'Buyer';
                    }

                    if (firstMessage) {
                        firstMessage.value = '';
                    }


                    showToast(
                        `Conversation with ${name} was created.`,
                        'Conversation started'
                    );
                }
            );


        /*
        * Attachment preview
        */
        document
            .querySelector(
                '[data-chat-attachment-button]'
            )
            ?.addEventListener(
                'click',
                () => {
                    attachmentInput?.click();
                }
            );


        attachmentInput?.addEventListener(
            'change',
            (event) => {

                const file =
                    event.target.files?.[0];


                if (!file) {
                    clearAttachmentPreview();
                    return;
                }


                if (attachmentName) {
                    attachmentName.textContent =
                        `Attached: ${file.name}`;

                    attachmentName.hidden =
                        false;
                }


                showToast(
                    `${file.name} is ready to attach to your next message.`,
                    'Attachment selected'
                );
            }
        );


        /*
        * Emoji shortcut
        */
        document
            .querySelector(
                '[data-chat-emoji]'
            )
            ?.addEventListener(
                'click',
                () => {

                    if (!messageInput) return;

                    messageInput.value += '🙂';

                    messageInput.focus();
                }
            );


        /*
        * Conversation details
        */
        document
            .querySelector(
                '[data-chat-details]'
            )
            ?.addEventListener(
                'click',
                () => {

                    const activeItem =
                        document.querySelector(
                            '[data-conversation-item].is-active'
                        );


                    if (!activeItem) return;


                    const count =
                        (
                            chatThreads[
                                getConversationId(
                                    activeItem
                                )
                            ] || []
                        ).length;


                    showToast(
                        `${activeItem.dataset.name} · ${activeItem.dataset.role} · ${count} messages`,
                        'Conversation details'
                    );
                }
            );


        /*
        * Mark current conversation unread
        */
        document
            .querySelector(
                '[data-chat-mark-unread]'
            )
            ?.addEventListener(
                'click',
                () => {

                    const activeItem =
                        document.querySelector(
                            '[data-conversation-item].is-active'
                        );


                    if (!activeItem) return;


                    let unread =
                        activeItem.querySelector(
                            '.unread-count'
                        );


                    if (!unread) {
                        unread =
                            document.createElement(
                                'span'
                            );

                        unread.className =
                            'unread-count';

                        unread.textContent =
                            '1';

                        activeItem.appendChild(
                            unread
                        );
                    } else {
                        unread.textContent =
                            String(
                                Number(
                                    unread.textContent ||
                                    0
                                ) + 1
                            );
                    }


                    updateUnreadSummary();


                    showToast(
                        `${activeItem.dataset.name}'s conversation was marked unread.`,
                        'Inbox updated'
                    );
                }
            );


        /*
        * Initial unread counter.
        */
        updateUnreadSummary();


        /*
        * Render initial active thread.
        */
        const initialConversation =
            document.querySelector(
                '[data-conversation-item].is-active'
            );


        if (initialConversation) {
            renderChatThread(
                getConversationId(
                    initialConversation
                )
            );
        }
    });

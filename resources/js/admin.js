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
                if (element.tagName === 'A') event.preventDefault();
                showToast(element.dataset.mockAction);
            });
        });

        document.querySelectorAll('[data-dismiss-flash]').forEach((button) => {
            button.addEventListener('click', () => button.closest('.flash-message')?.remove());
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

        document.querySelectorAll('[data-registration-needs-review]').forEach((button) => {
            button.addEventListener('click', () => {
                const row = button.closest('tr');
                const badge = row?.querySelector('.status-badge');
                if (!row || !badge) return;

                row.dataset.status = 'Needs Review';
                badge.textContent = 'Needs Review';
                badge.className = 'status-badge badge-info';

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

        // Drawer system + compliance data injection
        let activeComplianceCard = null;

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
                const auditRow = findComplianceAuditRow(product);
                const statusBadge = auditRow?.querySelector('.js-compliance-status');

                if (action === 'compliant') {
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







        // Manage admin accounts (front-end preview)
        const ADMIN_ACCOUNTS_STORAGE_KEY = 'bearlyAdminAccountsPreview';
        const ADMIN_PERMISSIONS_STORAGE_KEY = 'bearlyAdminPermissionsPreview';

        const adminAccountsBody = document.querySelector('[data-admin-accounts-body]');
        const newAdminModal = document.querySelector('[data-modal="new-admin"]');
        const permissionsModal = document.querySelector('[data-modal="admin-permissions"]');
        const removeAdminModal = document.querySelector('[data-modal="remove-admin"]');

        const getStoredJson = (key, fallback) => {
            try {
                const value = localStorage.getItem(key);
                return value ? JSON.parse(value) : fallback;
            } catch {
                return fallback;
            }
        };

        const setStoredJson = (key, value) => {
            localStorage.setItem(key, JSON.stringify(value));
        };

        const getAdminInitials = (name = '') =>
            name
                .trim()
                .split(/\s+/)
                .filter(Boolean)
                .slice(0, 2)
                .map((part) => part.charAt(0).toUpperCase())
                .join('') || 'AD';

        const escapeAdminHtml = (value = '') => String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

        const defaultPermissionsForRole = (role) => {
            const map = {
                'Super Admin': ['users', 'registrations', 'compliance', 'reports', 'messages', 'settings'],
                'Operations Admin': ['users', 'registrations', 'reports'],
                'Support Admin': ['users', 'messages'],
                'Compliance Admin': ['registrations', 'compliance', 'messages'],
            };

            return map[role] || [];
        };

        const renderPreviewAdminRow = (admin) => {
            if (!adminAccountsBody) return null;

            const row = document.createElement('tr');
            row.dataset.adminAccount = '';
            row.dataset.adminName = admin.name;
            row.dataset.adminEmail = admin.email;
            row.dataset.adminRole = admin.role;
            row.dataset.adminStatus = admin.status || 'Active';
            row.dataset.previewAdmin = 'true';

            row.innerHTML = `
                <td>
                    <div class="identity-cell">
                        <span class="avatar avatar-soft">${escapeAdminHtml(getAdminInitials(admin.name))}</span>
                        <div>
                            <strong>${escapeAdminHtml(admin.name)}</strong>
                            <small>${escapeAdminHtml(admin.email)}</small>
                        </div>
                    </div>
                </td>
                <td data-admin-role-cell>${escapeAdminHtml(admin.role)}</td>
                <td><span class="status-badge badge-success">${escapeAdminHtml(admin.status || 'Active')}</span></td>
                <td class="align-right">
                    <div class="row-actions">
                        <button
                            class="button button-ghost button-small"
                            type="button"
                            data-admin-permissions
                            data-admin-email="${escapeAdminHtml(admin.email)}"
                            data-admin-name="${escapeAdminHtml(admin.name)}"
                        >
                            <i data-lucide="key-round"></i> Permissions
                        </button>
                        <button
                            class="icon-button danger-icon"
                            type="button"
                            data-admin-remove
                            data-admin-email="${escapeAdminHtml(admin.email)}"
                            data-admin-name="${escapeAdminHtml(admin.name)}"
                            aria-label="Remove ${escapeAdminHtml(admin.name)}"
                        >
                            <i data-lucide="trash-2"></i>
                        </button>
                    </div>
                </td>
            `;

            adminAccountsBody.appendChild(row);
            refreshIcons();
            return row;
        };

        const getPreviewAdmins = () =>
            getStoredJson(ADMIN_ACCOUNTS_STORAGE_KEY, { added: [], removed: [] });

        const savePreviewAdmins = (state) => {
            setStoredJson(ADMIN_ACCOUNTS_STORAGE_KEY, state);
        };

        // Re-apply added/removed sub-admins after reload.
        if (adminAccountsBody) {
            const previewState = getPreviewAdmins();
            const removedEmails = new Set(
                (previewState.removed || []).map((email) => String(email).toLowerCase())
            );

            adminAccountsBody.querySelectorAll('[data-admin-account]').forEach((row) => {
                const email = String(row.dataset.adminEmail || '').toLowerCase();
                if (removedEmails.has(email) && !row.hasAttribute('data-current-admin-row')) {
                    row.remove();
                }
            });

            (previewState.added || []).forEach((admin) => {
                const alreadyExists = [...adminAccountsBody.querySelectorAll('[data-admin-account]')]
                    .some((row) =>
                        String(row.dataset.adminEmail || '').toLowerCase() ===
                        String(admin.email || '').toLowerCase()
                    );

                if (!alreadyExists) renderPreviewAdminRow(admin);
            });
        }

        document.querySelector('[data-create-admin]')?.addEventListener('click', () => {
            const firstInput = document.querySelector('[data-new-admin-first]');
            const lastInput = document.querySelector('[data-new-admin-last]');
            const emailInput = document.querySelector('[data-new-admin-email]');
            const roleInput = document.querySelector('[data-new-admin-role]');

            const firstName = firstInput?.value.trim() || '';
            const lastName = lastInput?.value.trim() || '';
            const email = emailInput?.value.trim() || '';
            const role = roleInput?.value || 'Operations Admin';

            if (!firstName || !lastName || !email) {
                showToast('First name, last name, and email are required.', 'Admin not created');
                return;
            }

            if (emailInput && !emailInput.checkValidity()) {
                showToast('Enter a valid admin email address.', 'Admin not created');
                emailInput.focus();
                return;
            }

            const duplicate = [...document.querySelectorAll('[data-admin-account]')].some(
                (row) =>
                    String(row.dataset.adminEmail || '').toLowerCase() === email.toLowerCase()
            );

            if (duplicate) {
                showToast('An administrator with that email already exists.', 'Admin not created');
                emailInput?.focus();
                return;
            }

            const admin = {
                name: `${firstName} ${lastName}`.trim(),
                email,
                role,
                status: 'Active',
            };

            renderPreviewAdminRow(admin);

            const state = getPreviewAdmins();
            state.added = [...(state.added || []), admin];
            state.removed = (state.removed || []).filter(
                (removedEmail) => String(removedEmail).toLowerCase() !== email.toLowerCase()
            );
            savePreviewAdmins(state);

            const permissionState = getStoredJson(ADMIN_PERMISSIONS_STORAGE_KEY, {});
            permissionState[email.toLowerCase()] = {
                role,
                permissions: defaultPermissionsForRole(role),
            };
            setStoredJson(ADMIN_PERMISSIONS_STORAGE_KEY, permissionState);

            if (firstInput) firstInput.value = '';
            if (lastInput) lastInput.value = '';
            if (emailInput) emailInput.value = '';
            if (roleInput) roleInput.value = 'Operations Admin';

            closeModal(newAdminModal);
            showToast(`${admin.name} was added as ${admin.role}.`, 'Admin created');
        });

        let activePermissionsEmail = '';

        document.addEventListener('click', (event) => {
            const permissionButton = event.target.closest('[data-admin-permissions]');
            if (permissionButton) {
                const email = permissionButton.dataset.adminEmail || '';
                const name = permissionButton.dataset.adminName || 'Administrator';
                const row = permissionButton.closest('[data-admin-account]');
                const currentRole = row?.dataset.adminRole || 'Operations Admin';

                activePermissionsEmail = email.toLowerCase();

                const label = permissionsModal?.querySelector('[data-permissions-admin-label]');
                const roleSelect = permissionsModal?.querySelector('[data-permissions-role]');
                const permissionBoxes = [...(permissionsModal?.querySelectorAll('[data-permission-option]') || [])];

                const saved = getStoredJson(ADMIN_PERMISSIONS_STORAGE_KEY, {});
                const current = saved[activePermissionsEmail] || {
                    role: currentRole,
                    permissions: defaultPermissionsForRole(currentRole),
                };

                if (label) label.textContent = `${name} • ${email}`;
                if (roleSelect) roleSelect.value = current.role || currentRole;

                permissionBoxes.forEach((box) => {
                    box.checked = (current.permissions || []).includes(box.value);
                });

                if (permissionsModal) {
                    permissionsModal.hidden = false;
                    refreshIcons();
                }

                return;
            }

            const removeButton = event.target.closest('[data-admin-remove]');
            if (removeButton) {
                const row = removeButton.closest('[data-admin-account]');
                if (!row || row.hasAttribute('data-current-admin-row')) return;

                removeAdminModal.dataset.targetEmail = removeButton.dataset.adminEmail || '';
                removeAdminModal.dataset.targetName = removeButton.dataset.adminName || '';

                const label = removeAdminModal.querySelector('[data-remove-admin-name]');
                if (label) label.textContent = removeButton.dataset.adminName || 'this sub-admin';

                removeAdminModal.hidden = false;
                refreshIcons();
            }
        });

        permissionsModal?.querySelector('[data-permissions-role]')?.addEventListener('change', (event) => {
            const defaults = defaultPermissionsForRole(event.target.value);
            permissionsModal.querySelectorAll('[data-permission-option]').forEach((box) => {
                box.checked = defaults.includes(box.value);
            });
        });

        permissionsModal?.querySelector('[data-save-admin-permissions]')?.addEventListener('click', () => {
            if (!activePermissionsEmail) return;

            const roleSelect = permissionsModal.querySelector('[data-permissions-role]');
            const role = roleSelect?.value || 'Operations Admin';
            const permissions = [...permissionsModal.querySelectorAll('[data-permission-option]:checked')]
                .map((box) => box.value);

            const state = getStoredJson(ADMIN_PERMISSIONS_STORAGE_KEY, {});
            state[activePermissionsEmail] = { role, permissions };
            setStoredJson(ADMIN_PERMISSIONS_STORAGE_KEY, state);

            const row = [...document.querySelectorAll('[data-admin-account]')].find(
                (item) =>
                    String(item.dataset.adminEmail || '').toLowerCase() === activePermissionsEmail
            );

            if (row) {
                row.dataset.adminRole = role;
                const roleCell = row.querySelector('[data-admin-role-cell]');
                if (roleCell) roleCell.textContent = role;

                if (row.dataset.previewAdmin === 'true') {
                    const previewState = getPreviewAdmins();
                    previewState.added = (previewState.added || []).map((admin) =>
                        String(admin.email || '').toLowerCase() === activePermissionsEmail
                            ? { ...admin, role }
                            : admin
                    );
                    savePreviewAdmins(previewState);
                }
            }

            const name = row?.dataset.adminName || 'Administrator';
            closeModal(permissionsModal);
            showToast(
                `${name}'s role and ${permissions.length} permission${permissions.length === 1 ? '' : 's'} were saved.`,
                'Permissions updated'
            );
        });

        removeAdminModal?.querySelector('[data-confirm-admin-remove]')?.addEventListener('click', () => {
            const email = String(removeAdminModal.dataset.targetEmail || '').trim();
            const name = String(removeAdminModal.dataset.targetName || 'Sub-admin').trim();

            if (!email) return;

            const row = [...document.querySelectorAll('[data-admin-account]')].find(
                (item) =>
                    String(item.dataset.adminEmail || '').toLowerCase() === email.toLowerCase()
            );

            if (!row || row.hasAttribute('data-current-admin-row')) {
                closeModal(removeAdminModal);
                return;
            }

            const state = getPreviewAdmins();
            const isPreviewAdmin = row.dataset.previewAdmin === 'true';

            if (isPreviewAdmin) {
                state.added = (state.added || []).filter(
                    (admin) =>
                        String(admin.email || '').toLowerCase() !== email.toLowerCase()
                );
            } else if (!(state.removed || []).some(
                (removedEmail) => String(removedEmail).toLowerCase() === email.toLowerCase()
            )) {
                state.removed = [...(state.removed || []), email];
            }

            savePreviewAdmins(state);

            const permissionState = getStoredJson(ADMIN_PERMISSIONS_STORAGE_KEY, {});
            delete permissionState[email.toLowerCase()];
            setStoredJson(ADMIN_PERMISSIONS_STORAGE_KEY, permissionState);

            row.remove();
            closeModal(removeAdminModal);
            showToast(`${name} was removed from admin access.`, 'Admin removed');
        });

        // Change password validation (front-end preview only)
        const currentPasswordInput = document.querySelector('[data-password-current]');
        const newPasswordInput = document.querySelector('[data-password-new]');
        const confirmPasswordInput = document.querySelector('[data-password-confirm]');
        const passwordUpdateButton = document.querySelector('[data-password-update]');

        const isMixedPassword = (value) =>
            /[A-Za-z]/.test(value) && /\d/.test(value);

        passwordUpdateButton?.addEventListener('click', () => {
            const currentPassword = currentPasswordInput?.value || '';
            const newPassword = newPasswordInput?.value || '';
            const confirmPassword = confirmPasswordInput?.value || '';

            if (!currentPassword) {
                showToast('Enter your current password first.', 'Password not updated');
                currentPasswordInput?.focus();
                return;
            }

            if (newPassword.length < 8) {
                showToast('New password must be at least 8 characters.', 'Password not updated');
                newPasswordInput?.focus();
                return;
            }

            if (!isMixedPassword(newPassword)) {
                showToast('New password must contain both letters and numbers.', 'Password not updated');
                newPasswordInput?.focus();
                return;
            }

            if (newPassword !== confirmPassword) {
                showToast('New password and confirmation do not match.', 'Password not updated');
                confirmPasswordInput?.focus();
                return;
            }

            if (newPassword === currentPassword) {
                showToast('Choose a new password that is different from the current password.', 'Password not updated');
                newPasswordInput?.focus();
                return;
            }

            // Front-end prototype only: do not store real passwords anywhere.
            newPasswordInput.value = '';
            confirmPasswordInput.value = '';

            showToast(
                'Password validation passed. Backend authentication can handle the real update later.',
                'Password check passed'
            );
        });

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
        const profileEditButton = document.querySelector('[data-profile-edit]');
        const profileSaveButton = document.querySelector('[data-profile-save]');
        const profileFields = [...document.querySelectorAll('[data-profile-field]')];

        const setProfileEditMode = (editing) => {
            profileFields.forEach((field) => {
                field.readOnly = !editing;
            });

            if (profileSaveButton) {
                profileSaveButton.disabled = !editing;
            }

            if (profileEditButton) {
                profileEditButton.innerHTML = editing
                    ? '<i data-lucide="x"></i> Cancel'
                    : '<i data-lucide="pencil"></i> Edit';
            }

            refreshIcons();
        };

        let profileSnapshot = {};

        profileEditButton?.addEventListener('click', () => {
            const currentlyEditing = profileFields.some((field) => !field.readOnly);

            if (!currentlyEditing) {
                profileSnapshot = Object.fromEntries(
                    profileFields.map((field) => [field.dataset.profileField, field.value])
                );
                setProfileEditMode(true);
                profileFields[0]?.focus();
                showToast('Profile fields are now editable.', 'Edit mode enabled');
                return;
            }

            profileFields.forEach((field) => {
                const key = field.dataset.profileField;
                if (Object.prototype.hasOwnProperty.call(profileSnapshot, key)) {
                    field.value = profileSnapshot[key];
                }
            });

            setProfileEditMode(false);
            showToast('Unsaved profile changes were discarded.', 'Changes cancelled');
        });

        profileSaveButton?.addEventListener('click', () => {
            const values = Object.fromEntries(
                profileFields.map((field) => [
                    field.dataset.profileField,
                    field.value.trim(),
                ])
            );

            if (!values.first_name || !values.last_name || !values.email) {
                showToast('First name, last name, and email are required.', 'Profile not saved');
                return;
            }

            const emailField = profileFields.find((field) => field.dataset.profileField === 'email');
            if (emailField && !emailField.checkValidity()) {
                showToast('Enter a valid email address before saving.', 'Profile not saved');
                emailField.focus();
                return;
            }

            localStorage.setItem(ADMIN_PROFILE_STORAGE_KEY, JSON.stringify(values));
            applyCurrentAdminIdentity(values);

            profileSnapshot = values;
            setProfileEditMode(false);
            showToast('Admin profile changes were saved in this preview.', 'Profile updated');
        });

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
            const showSales = reportType === 'Sales Summary';

            document.querySelectorAll('[data-report-section="sales"]').forEach((section) => {
                section.hidden = !showSales;
            });

            document.querySelectorAll('[data-report-section="commission"]').forEach((section) => {
                section.hidden = showSales;
            });

            if (reportFilterSummary) {
                reportFilterSummary.hidden = false;
                reportFilterSummary.textContent =
                    `${reportType} • ${formatReportDate(startValue)} to ${formatReportDate(endValue)}`;
            }

            showToast(
                showSales
                    ? 'Sales Summary view applied for the selected report period.'
                    : 'Commission Report view applied for the selected report period.',
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

            const sellers = [...document.querySelectorAll('.admin-table tbody tr')].map((row) => {
                const cells = row.querySelectorAll('td');
                return {
                    seller: row.querySelector('.identity-cell strong')?.textContent?.trim() || '',
                    grossSales: cells[1]?.textContent?.trim() || '',
                    commission: cells[2]?.textContent?.trim() || '',
                };
            });

            return {
                reportType,
                startDate: reportStartInput?.value || '',
                endDate: reportEndInput?.value || '',
                kpis,
                sellers,
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
                ['Top Seller Contribution'],
                ['Seller', 'Gross Sales', 'Platform Commission'],
                ...report.sellers.map((seller) => [
                    seller.seller,
                    seller.grossSales,
                    seller.commission,
                ]),
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

            const sellerRows = report.sellers.map((seller) => `
                <tr>
                    <td>${escapeReportHtml(seller.seller)}</td>
                    <td>${escapeReportHtml(seller.grossSales)}</td>
                    <td>${escapeReportHtml(seller.commission)}</td>
                </tr>
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

                    <h2>Top Seller Contribution</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Seller</th>
                                <th>Gross Sales</th>
                                <th>Platform Commission</th>
                            </tr>
                        </thead>
                        <tbody>${sellerRows}</tbody>
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

        // User status mock actions
        document.querySelectorAll('[data-user-status]').forEach((button) => {
            button.addEventListener('click', () => {
                const row = button.closest('tr');
                const badge = row?.querySelector('.js-status-badge');
                if (!row || !badge) return;
                const status = button.dataset.userStatus;
                row.dataset.status = status;
                badge.textContent = status;
                badge.className = 'status-badge js-status-badge ' + (status === 'Active' ? 'badge-success' : status === 'Suspended' ? 'badge-danger' : 'badge-neutral');
                showToast(`Account status changed to ${status}.`, 'Mock account update');
            });
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

        // Platform settings preview + persistence
        const SETTINGS_STORAGE_KEY = 'bearlyPlatformSettingsPreview';

        const announcementTitle = document.querySelector('[data-announcement-title]');
        const announcementAudience = document.querySelector('[data-announcement-audience]');
        const announcementBody = document.querySelector('[data-announcement-body]');
        const recentAnnouncements = document.querySelector('[data-recent-announcements]');

        const policyTitle = document.querySelector('[data-policy-title]');
        const policyEditor = document.querySelector('[data-policy-editor]');
        const policyPreviewTitle = document.querySelector('[data-policy-preview-title]');
        const policyPreview = document.querySelector('[data-policy-preview]');
        const policyHistoryModal = document.querySelector('[data-modal="policy-history"]');
        const policyHistoryList = document.querySelector('[data-policy-history-list]');

        const SETTINGS_DEFAULTS = {
            announcement: {
                title: 'Weekend Shipping Advisory',
                audience: 'All users',
                body: 'Courier pickup times may be slightly longer this weekend due to expected order volume. Sellers are encouraged to prepare parcels early.',
            },
            drafts: [],
            publishedAnnouncements: [],
            policy: {
                title: policyTitle?.defaultValue || policyTitle?.value || 'Marketplace Policy',
                body: policyEditor?.defaultValue || policyEditor?.value || '',
            },
            policyDraft: null,
            policyHistory: [],
        };

        const getSettingsState = () => {
            try {
                const raw = localStorage.getItem(SETTINGS_STORAGE_KEY);
                if (!raw) return structuredClone(SETTINGS_DEFAULTS);

                const parsed = JSON.parse(raw);
                return {
                    ...structuredClone(SETTINGS_DEFAULTS),
                    ...parsed,
                    announcement: {
                        ...SETTINGS_DEFAULTS.announcement,
                        ...(parsed.announcement || {}),
                    },
                    policy: {
                        ...SETTINGS_DEFAULTS.policy,
                        ...(parsed.policy || {}),
                    },
                    drafts: parsed.drafts || [],
                    publishedAnnouncements: parsed.publishedAnnouncements || [],
                    policyHistory: parsed.policyHistory || [],
                };
            } catch {
                return structuredClone(SETTINGS_DEFAULTS);
            }
        };

        let settingsState = getSettingsState();

        const saveSettingsState = () => {
            localStorage.setItem(SETTINGS_STORAGE_KEY, JSON.stringify(settingsState));
        };

        const formatSettingsTimestamp = () =>
            new Intl.DateTimeFormat('en-PH', {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
            }).format(new Date());

        const escapeSettingsHtml = (value = '') => String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

        const updateAnnouncement = () => {
            const title = document.querySelector('[data-preview-title]');
            const audience = document.querySelector('[data-preview-audience]');
            const bodyPreview = document.querySelector('[data-preview-body]');

            if (title && announcementTitle) {
                title.textContent = announcementTitle.value || 'Untitled announcement';
            }

            if (audience && announcementAudience) {
                audience.textContent = announcementAudience.value;
            }

            if (bodyPreview && announcementBody) {
                bodyPreview.textContent = announcementBody.value || 'Announcement message preview.';
            }
        };

        const updatePolicyPreview = () => {
            if (policyPreviewTitle && policyTitle) {
                policyPreviewTitle.textContent = policyTitle.value || 'Untitled policy';
            }

            if (policyPreview && policyEditor) {
                policyPreview.textContent = policyEditor.value || 'Policy content preview.';
            }
        };

        const renderPublishedAnnouncements = () => {
            if (!recentAnnouncements) return;

            recentAnnouncements.querySelectorAll('[data-preview-announcement]').forEach((item) => item.remove());

            settingsState.publishedAnnouncements.forEach((announcement) => {
                const row = document.createElement('div');
                row.dataset.previewAnnouncement = '';
                row.innerHTML = `
                    <strong>${escapeSettingsHtml(announcement.title)}</strong>
                    <small>${escapeSettingsHtml(announcement.audience)} • ${escapeSettingsHtml(announcement.date)}</small>
                    <span class="status-badge badge-success">Published</span>
                `;

                const label = recentAnnouncements.querySelector('.section-label');
                if (label?.nextSibling) {
                    label.after(row);
                } else {
                    recentAnnouncements.appendChild(row);
                }
            });
        };

        const renderPolicyHistory = () => {
            if (!policyHistoryList) return;

            policyHistoryList.querySelectorAll('[data-policy-history-item]').forEach((item) => item.remove());

            if (!settingsState.policyHistory.length) {
                const empty = document.createElement('div');
                empty.dataset.policyHistoryItem = '';
                empty.innerHTML = `
                    <strong>No saved versions yet</strong>
                    <small>Publish a policy update to create version history.</small>
                    <span class="status-badge badge-info">Preview</span>
                `;
                policyHistoryList.appendChild(empty);
                return;
            }

            settingsState.policyHistory.forEach((version, index) => {
                const row = document.createElement('div');
                row.dataset.policyHistoryItem = '';
                row.innerHTML = `
                    <strong>${escapeSettingsHtml(version.title)}</strong>
                    <small>${escapeSettingsHtml(version.date)}</small>
                    <span class="status-badge ${index === 0 ? 'badge-success' : 'badge-info'}">${index === 0 ? 'Current' : 'Published'}</span>
                `;
                policyHistoryList.appendChild(row);
            });
        };

        const applySettingsState = () => {
            if (announcementTitle) announcementTitle.value = settingsState.announcement.title;
            if (announcementAudience) announcementAudience.value = settingsState.announcement.audience;
            if (announcementBody) announcementBody.value = settingsState.announcement.body;

            if (policyTitle) policyTitle.value = settingsState.policy.title;
            if (policyEditor) policyEditor.value = settingsState.policy.body;

            updateAnnouncement();
            updatePolicyPreview();
            renderPublishedAnnouncements();
        };

        applySettingsState();

        [announcementTitle, announcementAudience, announcementBody].forEach((field) =>
            field?.addEventListener('input', updateAnnouncement)
        );
        announcementAudience?.addEventListener('change', updateAnnouncement);

        policyTitle?.addEventListener('input', updatePolicyPreview);
        policyEditor?.addEventListener('input', updatePolicyPreview);

        document.querySelector('[data-announcement-save-draft]')?.addEventListener('click', () => {
            const title = announcementTitle?.value.trim() || '';
            const body = announcementBody?.value.trim() || '';

            if (!title || !body) {
                showToast('Announcement title and message are required.', 'Draft not saved');
                return;
            }

            const draft = {
                title,
                audience: announcementAudience?.value || 'All users',
                body,
                date: formatSettingsTimestamp(),
            };

            settingsState.announcement = {
                title: draft.title,
                audience: draft.audience,
                body: draft.body,
            };

            settingsState.drafts = [draft, ...(settingsState.drafts || [])].slice(0, 10);
            saveSettingsState();

            showToast('Announcement draft saved in this browser preview.', 'Draft saved');
        });

        document.querySelector('[data-announcement-publish]')?.addEventListener('click', () => {
            const title = announcementTitle?.value.trim() || '';
            const body = announcementBody?.value.trim() || '';

            if (!title || !body) {
                showToast('Announcement title and message are required.', 'Announcement not published');
                return;
            }

            const published = {
                title,
                audience: announcementAudience?.value || 'All users',
                body,
                date: formatSettingsTimestamp(),
            };

            settingsState.announcement = {
                title: published.title,
                audience: published.audience,
                body: published.body,
            };

            settingsState.publishedAnnouncements = [
                published,
                ...(settingsState.publishedAnnouncements || []),
            ].slice(0, 10);

            saveSettingsState();
            renderPublishedAnnouncements();

            showToast(`${published.title} was published in this preview.`, 'Announcement published');
        });

        document.querySelector('[data-policy-save-draft]')?.addEventListener('click', () => {
            const title = policyTitle?.value.trim() || '';
            const body = policyEditor?.value.trim() || '';

            if (!title || !body) {
                showToast('Policy title and content are required.', 'Policy draft not saved');
                return;
            }

            settingsState.policyDraft = {
                title,
                body,
                date: formatSettingsTimestamp(),
            };

            saveSettingsState();
            showToast('Policy draft saved in this browser preview.', 'Policy draft saved');
        });

        document.querySelector('[data-policy-publish]')?.addEventListener('click', () => {
            const title = policyTitle?.value.trim() || '';
            const body = policyEditor?.value.trim() || '';

            if (!title || !body) {
                showToast('Policy title and content are required.', 'Policy not published');
                return;
            }

            const version = {
                title,
                body,
                date: formatSettingsTimestamp(),
            };

            settingsState.policy = { title, body };
            settingsState.policyDraft = null;
            settingsState.policyHistory = [
                version,
                ...(settingsState.policyHistory || []),
            ].slice(0, 20);

            saveSettingsState();
            updatePolicyPreview();

            showToast(`${title} was published in this preview.`, 'Policy updated');
        });

        document.querySelector('[data-policy-history-open]')?.addEventListener('click', () => {
            renderPolicyHistory();

            if (policyHistoryModal) {
                policyHistoryModal.hidden = false;
                refreshIcons();
            }
        });

        document.querySelector('[data-settings-reset]')?.addEventListener('click', () => {
            localStorage.removeItem(SETTINGS_STORAGE_KEY);
            settingsState = structuredClone(SETTINGS_DEFAULTS);
            applySettingsState();

            showToast('Platform settings were reset to the original preview defaults.', 'Preview reset');
        });

        // Dispute queue search + dynamic master-detail workspace
        const disputeSearch = document.querySelector('[data-dispute-search]');
        const disputeDataNode = document.getElementById('dispute-preview-data');
        let disputeData = {};
        try { disputeData = disputeDataNode ? JSON.parse(disputeDataNode.textContent) : {}; } catch {}

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
        const getActiveDisputeId = () =>
            document.querySelector('[data-case-card].is-active')?.dataset.caseId || '';

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

        // Chat filtering + per-conversation thread state
        const conversationSearch = document.querySelector('[data-conversation-search]');
        const conversationItems = [...document.querySelectorAll('[data-conversation-item]')];
        const messageThread = document.querySelector('[data-message-thread]');
        const messageInput = document.querySelector('[data-message-input]');

        const escapeChatHtml = (value = '') => String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

        const defaultChatThreads = {
            'mara-home-goods': [
                { from: 'them', text: 'Good afternoon. We uploaded the additional photos requested for case DSP-1048.', time: '6:34 PM' },
                { from: 'me', text: 'Received. We are reviewing the evidence from all parties now.', time: '6:36 PM' },
                { from: 'them', text: 'Thank you. Please let us know if you need a clearer copy of the packing photo.', time: '6:42 PM' },
            ],
            'karen-yu': [
                { from: 'them', text: 'Hello. I wanted to follow up regarding the complaint I submitted for my recent order.', time: '5:12 PM' },
                { from: 'me', text: 'Hi Karen. Your complaint is currently under review. We will update you once the seller response has been checked.', time: '5:16 PM' },
                { from: 'them', text: 'Thank you for reviewing my complaint.', time: '5:18 PM' },
            ],
            'jared-molina': [
                { from: 'them', text: 'Hi Admin, the delivery proof for the completed order has been uploaded.', time: '3:27 PM' },
                { from: 'me', text: 'Thanks, Jared. We received the delivery proof and added it to the order review.', time: '3:29 PM' },
                { from: 'them', text: 'Delivery proof has been uploaded.', time: '3:31 PM' },
            ],
            'techvault-ph': [
                { from: 'them', text: 'Good afternoon. We received a compliance notice for one of our listings.', time: '1:48 PM' },
                { from: 'me', text: 'The notice was triggered by a listing detail that requires manual verification.', time: '1:51 PM' },
                { from: 'them', text: 'Can we clarify the compliance notice?', time: '1:54 PM' },
            ],
        };

        const chatThreads = {};

        const getConversationId = (item) =>
            item?.dataset.conversationId ||
            item?.dataset.name?.trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') ||
            'conversation';

        conversationItems.forEach((item, index) => {
            const id = getConversationId(item);
            item.dataset.conversationId = id;

            chatThreads[id] = (defaultChatThreads[id] || [
                {
                    from: 'them',
                    text: item.dataset.preview || 'This is the beginning of this support conversation.',
                    time: item.dataset.time || '',
                },
            ]).map((message) => ({ ...message }));

            if (index === 0 && messageThread) {
                const bladeMessages = [...messageThread.querySelectorAll('.message-row')].map((row) => ({
                    from: row.classList.contains('message-me') ? 'me' : 'them',
                    text: row.querySelector('p')?.textContent?.trim() || '',
                    time: row.querySelector('span')?.textContent?.trim() || '',
                })).filter((message) => message.text);

                if (bladeMessages.length) chatThreads[id] = bladeMessages;
            }
        });

        const renderChatThread = (id) => {
            if (!messageThread) return;

            const messages = chatThreads[id] || [];
            messageThread.innerHTML = `
                <div class="thread-date">Today</div>
                ${messages.map((message) => `
                    <div class="message-row message-${message.from === 'me' ? 'me' : 'them'}">
                        <div class="message-bubble">
                            <p>${escapeChatHtml(message.text)}</p>
                            <span>${escapeChatHtml(message.time)}</span>
                        </div>
                    </div>
                `).join('')}
            `;
            messageThread.scrollTop = messageThread.scrollHeight;
        };

        const selectConversation = (item) => {
            if (!item) return;

            conversationItems.forEach((other) => other.classList.remove('is-active'));
            item.classList.add('is-active');

            const name = document.querySelector('[data-chat-name]');
            const role = document.querySelector('[data-chat-role]');
            const avatar = document.querySelector('[data-chat-avatar]');

            if (name) name.textContent = item.dataset.name || '';
            if (role) role.textContent = item.dataset.role || '';
            if (avatar) avatar.textContent = item.dataset.initials || '';

            const unread = item.querySelector('.unread-count');
            if (unread) unread.remove();

            renderChatThread(getConversationId(item));
        };

        conversationSearch?.addEventListener('input', () => {
            const query = conversationSearch.value.trim().toLowerCase();

            conversationItems.forEach((item) => {
                item.hidden = !!query && !(item.dataset.search || '').includes(query);
            });
        });

        conversationItems.forEach((item) => {
            item.addEventListener('click', () => selectConversation(item));
        });

        const sendMessage = () => {
            const text = messageInput?.value.trim();
            const activeItem = document.querySelector('[data-conversation-item].is-active');

            if (!text || !activeItem || !messageThread) return;

            const id = getConversationId(activeItem);
            const time = new Date().toLocaleTimeString([], {
                hour: 'numeric',
                minute: '2-digit',
            });

            if (!chatThreads[id]) chatThreads[id] = [];
            chatThreads[id].push({ from: 'me', text, time });

            messageInput.value = '';
            renderChatThread(id);

            showToast(
                `Message added to ${activeItem.dataset.name}'s conversation preview.`,
                'Message sent'
            );
        };

        document.querySelector('[data-send-message]')?.addEventListener('click', sendMessage);

        messageInput?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                sendMessage();
            }
        });

        const initialConversation = document.querySelector('[data-conversation-item].is-active');
        if (initialConversation) {
            renderChatThread(getConversationId(initialConversation));
        }
    });

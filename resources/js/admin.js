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

        // Settings live preview
        const announcementTitle = document.querySelector('[data-announcement-title]');
        const announcementAudience = document.querySelector('[data-announcement-audience]');
        const announcementBody = document.querySelector('[data-announcement-body]');
        const updateAnnouncement = () => {
            const title = document.querySelector('[data-preview-title]');
            const audience = document.querySelector('[data-preview-audience]');
            const bodyPreview = document.querySelector('[data-preview-body]');
            if (title && announcementTitle) title.textContent = announcementTitle.value || 'Untitled announcement';
            if (audience && announcementAudience) audience.textContent = announcementAudience.value;
            if (bodyPreview && announcementBody) bodyPreview.textContent = announcementBody.value || 'Announcement message preview.';
        };
        [announcementTitle, announcementAudience, announcementBody].forEach((field) => field?.addEventListener('input', updateAnnouncement));
        announcementAudience?.addEventListener('change', updateAnnouncement);

        const policyEditor = document.querySelector('[data-policy-editor]');
        const policyPreview = document.querySelector('[data-policy-preview]');
        policyEditor?.addEventListener('input', () => { if (policyPreview) policyPreview.textContent = policyEditor.value; });

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

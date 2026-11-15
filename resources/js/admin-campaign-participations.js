export function initAdminCampaignParticipations() {
    const panel = document.querySelector('[data-admin-campaign-participations]');
    if (!panel || panel.dataset.liveInitialized === 'true') return;
    panel.dataset.liveInitialized = 'true';

    const endpoint = panel.dataset.refreshUrl || '';
    const campaignId = Number(panel.dataset.campaignId || 0);
    const liveStatus = panel.querySelector('[data-campaign-participation-live-status]');
    const pollInterval = Math.max(5000, Number(panel.dataset.pollInterval) || 15000);
    let requestInFlight = false;
    let refreshQueued = false;

    if (!endpoint || campaignId < 1) return;

    const captureReviewDrafts = (rows) => {
        const focusedElement = document.activeElement;
        const focusedRow = focusedElement?.closest('[data-campaign-participation]');

        return {
            drafts: [...rows.querySelectorAll('input[name="reason"]')].map((input) => ({
                participationId: input.closest('[data-campaign-participation]')?.dataset.campaignParticipation || '',
                value: input.value,
            })),
            focus: focusedRow ? {
                participationId: focusedRow.dataset.campaignParticipation || '',
                name: focusedElement.getAttribute('name') || '',
                value: 'value' in focusedElement ? String(focusedElement.value) : '',
                selectionStart: typeof focusedElement.selectionStart === 'number'
                    ? focusedElement.selectionStart
                    : null,
                selectionEnd: typeof focusedElement.selectionEnd === 'number'
                    ? focusedElement.selectionEnd
                    : null,
            } : null,
        };
    };

    const restoreReviewDrafts = (rows, state) => {
        state.drafts.forEach((draft) => {
            if (!draft.participationId) return;

            const row = [...rows.querySelectorAll('[data-campaign-participation]')]
                .find((item) => item.dataset.campaignParticipation === draft.participationId);
            const input = row?.querySelector('input[name="reason"]');
            if (!input) return;

            input.value = draft.value;
        });

        const focus = state.focus;
        if (!focus?.participationId || !focus.name) return;

        const row = [...rows.querySelectorAll('[data-campaign-participation]')]
            .find((item) => item.dataset.campaignParticipation === focus.participationId);
        const control = [...(row?.querySelectorAll('input, button, select, textarea') || [])]
            .find((item) => item.name === focus.name && String(item.value) === focus.value);
        if (!control) return;

        control.focus({ preventScroll: true });
        if (focus.selectionStart !== null && focus.selectionEnd !== null) {
            control.setSelectionRange(focus.selectionStart, focus.selectionEnd);
        }
    };

    const refresh = async (force = false) => {
        if (document.hidden && !force) return;
        if (requestInFlight) {
            refreshQueued = true;
            return;
        }

        requestInFlight = true;

        try {
            const response = await fetch(endpoint, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const result = await response.json().catch(() => ({}));
            const rowsHtml = result?.data?.html;
            if (!response.ok || typeof rowsHtml !== 'string') return;

            const parsedTable = document.createElement('table');
            parsedTable.innerHTML = rowsHtml;
            const nextRows = parsedTable.querySelector('[data-campaign-participation-rows]');
            const currentRows = panel.querySelector('[data-campaign-participation-rows]');
            if (!nextRows || !currentRows || currentRows.innerHTML === nextRows.innerHTML) return;

            const drafts = captureReviewDrafts(currentRows);
            currentRows.replaceWith(nextRows);
            restoreReviewDrafts(nextRows, drafts);
            if (liveStatus) liveStatus.textContent = 'Seller participation list updated.';
        } catch {
            // Retry on the next realtime signal, focus/visibility change, or fallback poll.
        } finally {
            requestInFlight = false;
            if (refreshQueued && !document.hidden) {
                refreshQueued = false;
                refresh(true);
            }
        }
    };

    window.addEventListener('bearly:admin-campaign-participation-submitted', (event) => {
        if (Number(event.detail?.campaign_id || 0) === campaignId) refresh(true);
    });
    window.addEventListener('focus', () => refresh(true));
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refresh(true);
    });

    window.setInterval(() => refresh(), pollInterval);
    refresh(true);
}

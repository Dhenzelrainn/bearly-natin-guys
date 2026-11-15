const initCampaignParticipationUpdates = () => {
    const panel = document.querySelector('[data-campaign-participation-live]');
    if (!panel || panel.dataset.liveInitialized === 'true') return;
    panel.dataset.liveInitialized = 'true';

    const endpoint = panel.dataset.participationStatusUrl;
    const campaignId = Number(panel.dataset.campaignId || 0);
    const participationId = Number(panel.dataset.participationId || 0);
    const label = panel.querySelector('[data-campaign-participation-status]');
    const reasonWrapper = panel.querySelector('[data-campaign-review-reason-wrapper]');
    const reason = panel.querySelector('[data-campaign-review-reason]');
    const withdrawForm = panel.querySelector('[data-campaign-participation-withdraw]');
    const liveMessage = panel.querySelector('[data-campaign-participation-live-message]');

    if (!endpoint || campaignId < 1 || participationId < 1 || !label) return;

    let inFlight = false;
    let queued = false;
    let pollTimer = null;

    const stopPolling = () => {
        if (pollTimer === null) return;
        window.clearInterval(pollTimer);
        pollTimer = null;
    };

    const applyStatus = (snapshot) => {
        if (Number(snapshot?.id || 0) !== participationId) return;

        const status = String(snapshot.status || '');
        const allowedStatuses = new Set(['pending_review', 'approved', 'rejected', 'withdrawn']);
        if (!allowedStatuses.has(status)) return;

        const previousStatus = panel.dataset.participationStatus || '';
        label.textContent = String(snapshot.status_label || status.replaceAll('_', ' '));
        panel.dataset.participationStatus = status;

        const reviewReason = String(snapshot.review_reason || '');
        if (reason) reason.textContent = reviewReason;
        if (reasonWrapper) reasonWrapper.hidden = reviewReason === '';

        if (withdrawForm) withdrawForm.hidden = snapshot.can_withdraw !== true;

        if (liveMessage && previousStatus !== status) {
            liveMessage.textContent = status === 'approved'
                ? 'Bearly approved your campaign participation.'
                : status === 'rejected'
                    ? 'Bearly reviewed your campaign participation. See the decision reason above.'
                    : status === 'withdrawn'
                        ? 'This campaign participation was withdrawn.'
                        : 'Your campaign participation status was updated.';
            liveMessage.hidden = false;
        }

        if (status !== 'pending_review') stopPolling();
    };

    const refreshStatus = async () => {
        if (panel.dataset.participationStatus !== 'pending_review' || document.hidden) return;
        if (inFlight) {
            queued = true;
            return;
        }

        inFlight = true;
        queued = false;
        try {
            const response = await fetch(endpoint, {
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const result = await response.json().catch(() => ({}));
            const snapshot = result?.data?.participation;
            if (response.ok && snapshot) applyStatus(snapshot);
        } catch {
            // The next Reverb event, visibility change, or fallback poll retries.
        } finally {
            inFlight = false;
            if (queued && !document.hidden && panel.dataset.participationStatus === 'pending_review') {
                queued = false;
                refreshStatus();
            }
        }
    };

    window.addEventListener('bearly:seller-campaign-participation-updated', (event) => {
        const update = event.detail || {};
        if (Number(update.campaign_id || 0) !== campaignId
            || Number(update.participation_id || 0) !== participationId) return;

        refreshStatus();
    });

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refreshStatus();
    });

    if (panel.dataset.participationStatus === 'pending_review') {
        refreshStatus();
        pollTimer = window.setInterval(refreshStatus, 15000);
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCampaignParticipationUpdates, { once: true });
} else {
    initCampaignParticipationUpdates();
}

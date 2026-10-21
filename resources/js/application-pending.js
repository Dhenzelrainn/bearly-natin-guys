const root = document.querySelector('[data-application-status]');

if (root && root.dataset.final !== '1' && root.dataset.statusUrl) {
    const statusUrl = root.dataset.statusUrl;
    const interval = Math.max(5000, Number(root.dataset.pollInterval || 12000));
    const liveRegion = root.querySelector('[data-application-live]');
    let timer = null;
    let inFlight = false;

    const updateLiveRegion = (message) => {
        if (liveRegion) {
            liveRegion.textContent = message;
        }
    };

    const poll = async () => {
        if (inFlight || document.visibilityState === 'hidden') {
            return;
        }

        inFlight = true;

        try {
            const response = await fetch(statusUrl, {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                cache: 'no-store',
            });

            if (!response.ok) {
                if (response.status === 401 || response.status === 404) {
                    window.clearInterval(timer);
                }

                return;
            }

            const payload = await response.json();
            const status = payload?.data;

            if (!status) {
                return;
            }

            if (status.is_final) {
                window.clearInterval(timer);
                window.location.reload();

                return;
            }

            updateLiveRegion(`Current status: ${status.status}. Checking for updates automatically.`);
        } catch {
            // A temporary network failure should not interrupt the pending page.
        } finally {
            inFlight = false;
        }
    };

    timer = window.setInterval(poll, interval);

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            poll();
        }
    });
}

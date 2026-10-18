import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const userId = Number(window.bearlyRealtimeUserId || document.body?.dataset.realtimeUserId || 0);
const key = import.meta.env.VITE_REVERB_APP_KEY;
const host = import.meta.env.VITE_REVERB_HOST || window.location.hostname;
const port = Number(import.meta.env.VITE_REVERB_PORT || 8080);
const scheme = import.meta.env.VITE_REVERB_SCHEME || 'http';
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

window.BearlyRealtime = {
    enabled: false,
    echo: null,
};

if (userId > 0 && key) {
    try {
        const echo = new Echo({
            broadcaster: 'reverb',
            key,
            wsHost: host,
            wsPort: port,
            wssPort: port,
            forceTLS: scheme === 'https',
            enabledTransports: ['ws', 'wss'],
            authEndpoint: '/broadcasting/auth',
            auth: {
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
            },
        });

        window.BearlyRealtime = { enabled: true, echo };

        echo.private(`users.${userId}`)
            .listen('.notification.created', (payload) => {
                window.BearlyNotifications?.refresh?.();
                window.dispatchEvent(new CustomEvent('bearly:notification-created', { detail: payload }));
            })
            .listen('.message.created', (payload) => {
                window.dispatchEvent(new CustomEvent('bearly:message-created', { detail: payload }));
            });
    } catch {
        // Existing polling remains the safe fallback when Reverb is offline.
    }
}

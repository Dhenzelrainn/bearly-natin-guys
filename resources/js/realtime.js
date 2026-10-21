import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { acknowledgeDelivery } from './chat-receipts.js';
import { initChatPresence } from './chat-presence.js';

window.Pusher = Pusher;

/*
 * Realtime transport for Bearly.
 *
 * Identity comes from the shared server-rendered partial and is used only to
 * pick the private channels to join. routes/channels.php authorizes every
 * private channel again from the authenticated session, so nothing here is a
 * security boundary.
 *
 * Browser-facing connection values are isolated in VITE_REVERB_*; the
 * server-to-Reverb broadcaster uses REVERB_BROADCAST_* in config/broadcasting.php.
 */
const identity = window.bearlyRealtimeIdentity || {};
const allowedRoles = ['buyer', 'seller', 'logistics', 'rider'];
const userId = Number(
    identity.user_id
    || window.bearlyRealtimeUserId
    || document.body?.dataset.realtimeUserId
    || 0,
);
const roles = (Array.isArray(identity.roles) ? identity.roles : [])
    .map((role) => String(role))
    .filter((role, index, list) => allowedRoles.includes(role) && list.indexOf(role) === index);
const key = import.meta.env.VITE_REVERB_APP_KEY;
const host = import.meta.env.VITE_REVERB_HOST || window.location.hostname;
const port = Number(import.meta.env.VITE_REVERB_PORT || 8080);
const scheme = import.meta.env.VITE_REVERB_SCHEME || 'http';
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

window.BearlyRealtime = {
    enabled: false,
    echo: null,
    connected: false,
    status: 'disabled',
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
            disableStats: true,
            authEndpoint: '/broadcasting/auth',
            auth: {
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
            },
        });

        window.BearlyRealtime = {
            enabled: true,
            echo,
            connected: false,
            status: 'connecting',
        };

        /*
         * Presence is additive to polling. The manager only joins channels for
         * conversation avatars rendered by the current Messages surface and
         * leaves them when the page or conversation disappears.
         */
        window.BearlyPresence = initChatPresence({ echo, userId });

        echo.private(`users.${userId}`)
            .listen('.notification.created', (payload) => {
                window.BearlyNotifications?.refresh?.();
                window.dispatchEvent(new CustomEvent('bearly:notification-created', { detail: payload }));
            })
            .listen('.message.created', (payload) => {
                window.dispatchEvent(new CustomEvent('bearly:message-created', { detail: payload }));

                /*
                 * A delivered receipt means this authenticated browser
                 * actually received the event, not just that the sender
                 * pressed send. The endpoint re-checks membership and the
                 * write is idempotent, so retries are safe.
                 */
                const conversationId = Number(payload?.conversation_id || 0);
                const messageId = Number(payload?.message_id || 0);

                if (conversationId > 0 && messageId > 0) {
                    acknowledgeDelivery(
                        `/messages/conversations/${conversationId}/delivered`,
                        [messageId],
                        csrfToken,
                    ).catch(() => {});
                }
            })
            .listen('.message.receipt', (payload) => {
                window.dispatchEvent(new CustomEvent('bearly:message-receipt', { detail: payload }));
            });

        roles.forEach((role) => {
            echo.private(`announcements.${role}`)
                .listen('.announcement.published', (payload) => {
                    window.dispatchEvent(new CustomEvent('bearly:announcement-published', { detail: payload }));
                });
        });

        /*
         * Expose connection state for diagnostics without exposing keys,
         * secrets, or the CSRF token. Polling remains the fallback on every
         * non-connected state.
         */
        const connection = echo.connector?.pusher?.connection;
        const setStatus = (status, connected) => {
            window.BearlyRealtime.status = status;
            window.BearlyRealtime.connected = connected;
            window.dispatchEvent(new CustomEvent('bearly:realtime-status', {
                detail: { status, connected },
            }));
        };

        if (connection) {
            connection.bind('connected', () => setStatus('connected', true));
            connection.bind('connecting', () => setStatus('connecting', false));
            connection.bind('disconnected', () => setStatus('disconnected', false));
            connection.bind('unavailable', () => setStatus('unavailable', false));
            connection.bind('failed', () => setStatus('failed', false));
        }
    } catch {
        /* Existing polling remains the safe fallback when Reverb is offline. */
        window.BearlyRealtime.status = 'failed';
    }
}

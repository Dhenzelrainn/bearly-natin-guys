/*
 * Conversation-scoped online presence for Bearly Messages.
 *
 * Each channel is authorized again by routes/channels.php using the same
 * participant and seller Store rules as the message thread endpoints. This
 * module only consumes the authorized member list and updates the online
 * indicators rendered by the role-specific chat clients.
 */

const CHANNEL_PREFIX = 'conversation-presence.';
const TARGET_SELECTOR = '[data-presence-conversation]';
const SURFACE_SELECTOR = [
    '[data-chat-list]',
    '[data-conversation-list]',
    '[data-message-workspace]',
    '[data-standard-role]',
    '.customer-messages-page',
].join(',');

const positiveInt = (value) => {
    const number = Number(value);

    return Number.isInteger(number) && number > 0 ? number : 0;
};

const parseUserIds = (value) => String(value ?? '')
    .split(',')
    .map(positiveInt)
    .filter(Boolean);

const conversationIdOf = (target) => positiveInt(target?.dataset?.presenceConversation);

const collectConversationIds = () => new Set(
    [...document.querySelectorAll(TARGET_SELECTOR)]
        .map(conversationIdOf)
        .filter(Boolean),
);

const memberId = (member) => positiveInt(member?.id ?? member?.user_id);

const memberName = (member) => String(member?.name ?? '').trim() || 'Participant';

export function initChatPresence({ echo, userId } = {}) {
    const selfId = positiveInt(userId);

    if (!echo || !selfId || !document.querySelector(SURFACE_SELECTOR)) {
        return null;
    }

    const subscriptions = new Map();
    const members = new Map();
    let observer = null;
    let syncTimer = null;
    let destroyed = false;

    const channelName = (conversationId) => `${CHANNEL_PREFIX}${conversationId}`;

    const setMembers = (conversationId, incoming) => {
        const next = new Map();

        (Array.isArray(incoming) ? incoming : []).forEach((member) => {
            const id = memberId(member);

            if (id > 0) {
                next.set(id, member);
            }
        });

        members.set(conversationId, next);
    };

    const addMember = (conversationId, member) => {
        const id = memberId(member);

        if (id <= 0) return;

        const current = members.get(conversationId) ?? new Map();
        current.set(id, member);
        members.set(conversationId, current);
    };

    const removeMember = (conversationId, member) => {
        const id = memberId(member);

        if (id <= 0) return;

        const current = members.get(conversationId);

        if (!current) return;

        current.delete(id);

        if (current.size === 0) {
            members.delete(conversationId);
        }
    };

    const onlineMembersFor = (conversationId, userIds) => {
        const audience = userIds.length ? new Set(userIds) : null;
        const current = members.get(conversationId);

        if (!current) return [];

        return [...current.entries()]
            .filter(([id]) => id !== selfId && (!audience || audience.has(id)))
            .map(([, member]) => member);
    };

    const refreshTargets = () => {
        if (destroyed) return;

        document.querySelectorAll(TARGET_SELECTOR).forEach((target) => {
            const conversationId = conversationIdOf(target);
            const online = conversationId
                ? onlineMembersFor(conversationId, parseUserIds(target.dataset.presenceUsers))
                : [];
            const isOnline = online.length > 0;
            const inline = target.classList.contains('chat-presence-indicator');

            target.classList.toggle('is-online', isOnline);
            target.dataset.presenceState = isOnline ? 'online' : 'offline';

            if (!isOnline) {
                target.removeAttribute('title');

                if (inline) {
                    target.hidden = true;
                    target.setAttribute('aria-label', 'Offline');
                }

                return;
            }

            const label = online.length === 1
                ? `${memberName(online[0])} is online`
                : `${online.length} participants online`;

            target.title = label;

            if (inline) {
                target.hidden = false;
                target.setAttribute('aria-label', label);
            }
        });
    };

    const leave = (conversationId) => {
        subscriptions.delete(conversationId);
        members.delete(conversationId);

        try {
            echo.leave(channelName(conversationId));
        } catch {
            /* Echo may already have removed the failed subscription. */
        }
    };

    const subscribe = (conversationId) => {
        if (destroyed || subscriptions.has(conversationId)) return;

        try {
            const channel = echo.join(channelName(conversationId));

            subscriptions.set(conversationId, channel);

            channel
                .here((incoming) => {
                    setMembers(conversationId, incoming);
                    refreshTargets();
                })
                .joining((member) => {
                    addMember(conversationId, member);
                    refreshTargets();
                })
                .leaving((member) => {
                    removeMember(conversationId, member);
                    refreshTargets();
                })
                .error(() => {
                    if (subscriptions.get(conversationId) === channel) {
                        leave(conversationId);
                    }

                    refreshTargets();
                });
        } catch {
            subscriptions.delete(conversationId);
            members.delete(conversationId);
            refreshTargets();
        }
    };

    const syncSubscriptions = () => {
        if (destroyed) return;

        const desired = collectConversationIds();

        desired.forEach(subscribe);

        [...subscriptions.keys()].forEach((conversationId) => {
            if (!desired.has(conversationId)) {
                leave(conversationId);
            }
        });

        refreshTargets();
    };

    const scheduleSync = () => {
        if (destroyed) return;

        if (syncTimer) {
            window.clearTimeout(syncTimer);
        }

        syncTimer = window.setTimeout(() => {
            syncTimer = null;
            syncSubscriptions();
        }, 120);
    };

    const onRealtimeStatus = (event) => {
        if (destroyed) return;

        if (!event.detail?.connected) {
            members.clear();
            refreshTargets();
            return;
        }

        scheduleSync();
    };

    const onPageHide = (event) => {
        if (!event.persisted) {
            destroy();
        }
    };

    const onPageShow = (event) => {
        if (destroyed || !event.persisted) return;

        [...subscriptions.keys()].forEach((conversationId) => {
            leave(conversationId);
        });

        members.clear();
        scheduleSync();
    };

    function destroy() {
        if (destroyed) return;

        destroyed = true;

        if (syncTimer) {
            window.clearTimeout(syncTimer);
            syncTimer = null;
        }

        observer?.disconnect();
        observer = null;

        window.removeEventListener('bearly:realtime-status', onRealtimeStatus);
        window.removeEventListener('pagehide', onPageHide);
        window.removeEventListener('pageshow', onPageShow);

        [...subscriptions.keys()].forEach(leave);
        members.clear();

        document.querySelectorAll(TARGET_SELECTOR).forEach((target) => {
            target.classList.remove('is-online');
            target.dataset.presenceState = 'offline';
            target.removeAttribute('title');

            if (target.classList.contains('chat-presence-indicator')) {
                target.hidden = true;
                target.setAttribute('aria-label', 'Offline');
            }
        });
    }

    if (typeof MutationObserver !== 'undefined' && document.body) {
        observer = new MutationObserver(() => scheduleSync());
        observer.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['data-presence-conversation', 'data-presence-users'],
        });
    }

    window.addEventListener('bearly:realtime-status', onRealtimeStatus);
    window.addEventListener('pagehide', onPageHide);
    window.addEventListener('pageshow', onPageShow);

    syncSubscriptions();

    return {
        refresh: () => {
            scheduleSync();
            refreshTargets();
        },
        destroy,
    };
}

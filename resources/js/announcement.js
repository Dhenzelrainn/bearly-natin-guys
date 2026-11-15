/*
 * Bearly announcement page live updates.
 *
 * Realtime events arrive from the private announcements.{role} channels in
 * realtime.js. The same feed endpoint is polled as a fallback so an
 * announcement still appears when Reverb is unavailable. Cards are de-duped by
 * announcement id before they are inserted.
 */
(() => {
    const body = document.body;
    const feedUrl = body?.dataset.announcementFeedUrl || '';
    const currentId = Number(body?.dataset.currentAnnouncementId || 0);
    const liveRegion = document.querySelector('[data-announcement-live]');
    const historyList = document.querySelector('[data-announcement-history-list]');

    if (!feedUrl || !historyList) return;

    const POLL_MS = 20000;
    const ROLE_LABELS = {
        buyer: 'Buyer',
        seller: 'Seller',
        logistics: 'Logistics',
        rider: 'Rider',
    };

    const knownIds = new Set();
    let latestPublishedAt = body.dataset.latestPublishedAt || '';
    let polling = false;
    let pollAgain = false;

    const sameOriginUrl = (value) => {
        try {
            const url = new URL(String(value || ''), window.location.origin);

            return url.origin === window.location.origin ? url : null;
        } catch {
            return null;
        }
    };

    const element = (tag, className = '', text = '') => {
        const node = document.createElement(tag);

        if (className) node.className = className;
        if (text !== '') node.textContent = text;

        return node;
    };

    const formatPublished = (iso) => {
        if (!iso) return 'Published recently';

        const date = new Date(iso);

        if (Number.isNaN(date.getTime())) return 'Published recently';

        return `Published ${date.toLocaleString('en-PH', {
            timeZone: 'Asia/Manila',
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        })}`;
    };

    const noteLatest = (iso) => {
        if (!iso) return;

        const incoming = new Date(iso);
        const current = latestPublishedAt ? new Date(latestPublishedAt) : null;

        if (Number.isNaN(incoming.getTime())) return;
        if (!current || Number.isNaN(current.getTime()) || incoming > current) {
            latestPublishedAt = iso;
        }
    };

    const showLiveNotice = (message, url) => {
        if (!liveRegion) return;

        const text = element('span', '', message);
        liveRegion.replaceChildren(text);

        const link = sameOriginUrl(url);

        if (link) {
            const anchor = element('a', '', 'Open announcement');
            anchor.href = link.toString();
            liveRegion.append(anchor);
        }

        liveRegion.hidden = false;
    };

    const mediaNode = (media) => {
        const url = sameOriginUrl(media?.url);
        const type = media?.media_type === 'video' ? 'video' : 'image';

        if (!url) return null;

        const figure = element('figure', 'announcement-history-media-item');

        if (type === 'image') {
            const image = document.createElement('img');
            image.src = url.toString();
            image.alt = `${media.original_name || 'Announcement'} image`;
            image.loading = 'lazy';
            figure.append(image);
        } else {
            const video = document.createElement('video');
            video.controls = true;
            video.preload = 'none';
            video.playsInline = true;
            video.setAttribute('aria-label', `Video attached to ${media.original_name || 'announcement'}`);
            const source = document.createElement('source');
            source.src = url.toString();
            source.type = media.mime_type || '';
            video.append(source, document.createTextNode('Your browser cannot play this video.'));
            figure.append(video);
        }

        figure.append(element('figcaption', '', media.original_name || 'Attachment'));

        return figure;
    };

    const historyCard = (announcement) => {
        const article = element('article', 'announcement-history-post');
        article.setAttribute('data-announcement-id', String(announcement.id));

        const header = element('header', 'announcement-history-post-header');
        const avatar = element('span', 'announcement-history-avatar');
        avatar.setAttribute('aria-hidden', 'true');
        const logo = document.createElement('img');
        logo.src = '/images/bearly-logo-2.png';
        logo.alt = '';
        avatar.append(logo);

        const author = element('div', 'announcement-history-author');
        author.append(
            element('strong', '', announcement.creator_name || 'Bearly Admin'),
            (() => {
                const time = document.createElement('time');
                const publishedAt = announcement.published_at || '';

                if (publishedAt) time.dateTime = publishedAt;
                time.textContent = announcement.published_label
                    ? `Published ${announcement.published_label}`
                    : formatPublished(publishedAt);

                return time;
            })(),
        );

        const role = element(
            'span',
            'announcement-history-role',
            announcement.audience_label || 'Platform announcement',
        );

        header.append(avatar, author, role);

        const content = element('div', 'announcement-history-post-content');
        content.append(
            element('div', 'announcement-history-number', announcement.announcement_no || ''),
            element('h3', '', announcement.title || 'Platform announcement'),
            element('div', 'announcement-history-message', announcement.body || announcement.excerpt || ''),
        );

        const media = Array.isArray(announcement.media) ? announcement.media : [];
        if (media.length) {
            const grid = element('div', 'announcement-history-media-grid');
            grid.setAttribute('aria-label', `Media attached to ${announcement.title || 'announcement'}`);
            media.forEach((item) => {
                const node = mediaNode(item);
                if (node) grid.append(node);
            });
            if (grid.children.length) content.append(grid);
        }

        const footer = element('footer', 'announcement-history-post-footer');
        footer.append(element('span', '', 'Platform announcement'));

        const openUrl = sameOriginUrl(announcement.url);
        if (openUrl) {
            const open = element('a', '', 'Open post');
            open.href = openUrl.toString();
            open.setAttribute('aria-label', `Open full page for ${announcement.title || 'announcement'}`);
            footer.append(open);
        }

        article.append(header, content, footer);

        return article;
    };

    const addAnnouncement = (announcement) => {
        const id = Number(announcement?.id || 0);

        if (!id || knownIds.has(id)) return;

        knownIds.add(id);

        if (id !== currentId) {
            historyList.querySelector('[data-announcement-history-empty]')?.remove();
            historyList.prepend(historyCard(announcement));
            showLiveNotice(`New announcement: ${announcement.title || 'Platform update'}`, announcement.url);
        } else {
            showLiveNotice('This announcement is now published.', announcement.url);
        }

        noteLatest(announcement.published_at);
    };

    const eventAnnouncement = (detail) => {
        const roles = Array.isArray(detail?.audience_roles) ? detail.audience_roles : [];
        const labels = roles.map((role) => ROLE_LABELS[role]).filter(Boolean);

        return {
            id: detail?.id,
            announcement_no: detail?.announcement_no,
            title: detail?.title,
            excerpt: detail?.excerpt,
            body: detail?.excerpt,
            url: detail?.url,
            published_at: detail?.published_at,
            audience_label: labels.length === 4 ? 'All Users' : labels.join(', '),
            creator_name: 'Bearly Admin',
            media: [],
        };
    };

    const poll = async () => {
        if (polling) {
            pollAgain = true;
            return;
        }

        polling = true;

        try {
            const url = new URL(feedUrl, window.location.origin);
            if (latestPublishedAt) url.searchParams.set('after', latestPublishedAt);

            const excludeIds = [...knownIds].slice(-30);
            excludeIds.forEach((id) => {
                url.searchParams.append('exclude_ids[]', String(id));
            });

            const response = await fetch(url.toString(), {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) return;

            const payload = await response.json().catch(() => ({}));
            const announcements = payload?.data?.announcements;

            if (!Array.isArray(announcements)) return;

            announcements.forEach(addAnnouncement);
        } catch {
            /* Polling and realtime both remain available for the next tick. */
        } finally {
            polling = false;

            if (pollAgain) {
                pollAgain = false;
                poll();
            }
        }
    };

    document.querySelectorAll('[data-announcement-id]').forEach((card) => {
        const id = Number(card.dataset.announcementId || 0);
        if (id) knownIds.add(id);
    });

    if (currentId) knownIds.add(currentId);

    window.addEventListener('bearly:announcement-published', (event) => {
        addAnnouncement(eventAnnouncement(event.detail || {}));
        poll();
    });

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) poll();
    });

    window.setInterval(() => {
        if (!document.hidden) poll();
    }, POLL_MS);
})();

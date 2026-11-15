export function formatCampaignTimeRemaining(endsAt, now = Date.now()) {
    const endTimestamp = Date.parse(String(endsAt ?? ''));
    if (!Number.isFinite(endTimestamp)) return 'Unavailable';

    const remainingSeconds = Math.max(0, Math.ceil((endTimestamp - now) / 1000));
    if (remainingSeconds === 0) return 'Ended';

    const days = Math.floor(remainingSeconds / 86_400);
    const hours = Math.floor((remainingSeconds % 86_400) / 3_600);
    const minutes = Math.floor((remainingSeconds % 3_600) / 60);
    const seconds = remainingSeconds % 60;
    const clock = [hours, minutes, seconds]
        .map(value => String(value).padStart(2, '0'))
        .join(':');

    return days > 0 ? `${days}d ${clock}` : clock;
}

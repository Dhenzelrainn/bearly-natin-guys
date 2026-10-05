const previewNotificationKey = window.bearlyStorageKey?.('preview-notifications') || 'bearly-notifications-v1';

export const PREVIEW_NOTIFICATION_KEY = previewNotificationKey;

export const previewNotificationDefaults = [
    {
        id: 'notif-preview-order',
        group: 'Today',
        type: 'orders',
        icon: 'inventory_2',
        title: 'Order Shipped',
        message: 'Your order #BRY-102341 has been shipped and is on its way.',
        detail: 'Estimated delivery: Oct 8, 2024',
        time: '2 hours ago',
        read: false,
        target: 'purchases',
        image: '/images/products/men/mens-01-01.jpg',
    },
    {
        id: 'notif-preview-payment',
        group: 'Today',
        type: 'payment',
        icon: 'account_balance_wallet',
        title: 'Payment Successful',
        message: 'Your payment of ₱449.00 for order #BRY-102341 was successful.',
        detail: 'Thank you for shopping with Bearly!',
        time: '3 hours ago',
        read: false,
        target: 'purchases',
    },
    {
        id: 'notif-preview-delivery',
        group: 'Today',
        type: 'orders',
        icon: 'local_shipping',
        title: 'Out for Delivery',
        message: 'Your order #BRY-102198 is out for delivery.',
        detail: 'Our rider is on the way to your address.',
        time: '5 hours ago',
        read: false,
        target: 'purchases',
        image: '/images/products/health-beauty/health-beauty-01-01.jpg',
    },
    {
        id: 'notif-voucher',
        group: 'Yesterday',
        type: 'promos',
        icon: 'confirmation_number',
        title: 'Special Promo Just for You',
        message: 'Get 10% off on selected items this week only!',
        detail: 'Use code BEARLY10 at checkout.',
        time: 'Oct 03, 2024 · 9:15 PM',
        read: true,
        target: 'vouchers',
        actionLabel: 'View Vouchers',
        actionHref: '/profile#vouchers',
    },
    {
        id: 'notif-review',
        group: 'Yesterday',
        type: 'orders',
        icon: 'description',
        title: 'Review Your Purchase',
        message: 'How was your experience with Classic Tote Bag?',
        detail: 'Share your thoughts and help other buyers.',
        time: 'Oct 03, 2024 · 4:22 PM',
        read: true,
        target: 'reviews',
        actionLabel: 'Write Review',
        actionHref: '/profile#reviews',
    },
    {
        id: 'notif-delivered',
        group: 'Earlier',
        type: 'orders',
        icon: 'inventory_2',
        title: 'Order Delivered',
        message: 'Your order #BRY-101972 has been delivered.',
        detail: 'We hope you enjoy your purchase!',
        time: 'Oct 02, 2024 · 1:45 PM',
        read: true,
        target: 'purchases',
        image: '/images/products/electronics/electronics-07-01.jpg',
    },
    {
        id: 'notif-arrivals',
        group: 'Earlier',
        type: 'promos',
        icon: 'campaign',
        title: 'New Arrivals',
        message: 'Check out the latest products from your favorite brands.',
        detail: 'Discover fresh finds now on Bearly.',
        time: 'Oct 01, 2024 · 10:30 AM',
        read: true,
        target: 'home',
        actionLabel: 'View Products',
        actionHref: '/home#results',
    },
];

const cloneNotifications = items => items.map(item => ({ ...item }));

export function getPreviewNotifications() {
    try {
        const saved = JSON.parse(localStorage.getItem(PREVIEW_NOTIFICATION_KEY) || 'null');

        if (!Array.isArray(saved)) {
            return cloneNotifications(previewNotificationDefaults);
        }

        const savedById = new Map(saved.map(item => [item.id, item]));
        const defaultIds = new Set(previewNotificationDefaults.map(item => item.id));
        const defaults = previewNotificationDefaults.map(item => ({
            ...item,
            read: savedById.get(item.id)?.read ?? item.read,
        }));

        return defaults.concat(saved.filter(item => !defaultIds.has(item.id)));
    } catch {
        return cloneNotifications(previewNotificationDefaults);
    }
}

export function savePreviewNotifications(items) {
    try {
        localStorage.setItem(PREVIEW_NOTIFICATION_KEY, JSON.stringify(items));
    } catch {
        // Preview state is best-effort when browser storage is unavailable.
    }

    window.dispatchEvent(new CustomEvent('bearly:notifications-changed'));
}

export function countUnreadPreviewNotifications(items = getPreviewNotifications()) {
    return items.filter(item => !item.read).length;
}

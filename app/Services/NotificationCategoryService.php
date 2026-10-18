<?php

namespace App\Services;

/**
 * Role-aware history categories for the full-page Notification Center.
 *
 * Every category maps to real registry event keys from config/notifications.php
 * so filters never depend on notification title text. Account and security
 * events intentionally have no tab: they remain visible in All and Unread.
 */
class NotificationCategoryService
{
    /**
     * Role → ordered categories, each with the registry events it owns.
     *
     * @var array<string, array<string, array{label: string, events: list<string>}>>
     */
    private const CATEGORIES = [
        'buyer' => [
            'orders' => [
                'label' => 'Order updates',
                'events' => ['buyer_order_updates'],
            ],
            'shipments' => [
                'label' => 'Shipments',
                'events' => ['buyer_shipment_updates'],
            ],
            'returns' => [
                'label' => 'Returns & refunds',
                'events' => ['buyer_return_updates'],
            ],
            'messages' => [
                'label' => 'Messages',
                'events' => ['buyer_message_received'],
            ],
            'announcements' => [
                'label' => 'Announcements',
                'events' => ['announcement_published'],
            ],
        ],
        'seller' => [
            'orders' => [
                'label' => 'Orders',
                'events' => ['seller_order_updates'],
            ],
            'cancellations' => [
                'label' => 'Cancellations',
                'events' => ['seller_order_cancelled'],
            ],
            'returns' => [
                'label' => 'Return & refund requests',
                'events' => ['seller_return_updates'],
            ],
            'compliance' => [
                'label' => 'Compliance',
                'events' => ['seller_compliance_flagged'],
            ],
            'pickups' => [
                'label' => 'Pickup requests',
                'events' => ['seller_pickup_updates'],
            ],
            'shipments' => [
                'label' => 'Shipment tracking',
                'events' => ['seller_shipment_updates'],
            ],
            'messages' => [
                'label' => 'Messages',
                'events' => ['seller_buyer_message'],
            ],
            'finance' => [
                'label' => 'Finance & withdrawals',
                'events' => ['seller_withdrawal_status_changed'],
            ],
            'announcements' => [
                'label' => 'Announcements',
                'events' => ['announcement_published'],
            ],
        ],
        'logistics' => [
            'pickups' => [
                'label' => 'Pickup requests',
                'events' => ['logistics_pickup_requested'],
            ],
            'handoffs' => [
                'label' => 'Handoffs',
                'events' => ['logistics_handoff_sent', 'logistics_handoff_accepted'],
            ],
            'cash' => [
                'label' => 'COD & refund cash',
                'events' => ['logistics_cod_collected', 'logistics_refund_cash_issued'],
            ],
            'riders' => [
                'label' => 'Rider applications',
                'events' => ['logistics_rider_application_submitted'],
            ],
            'messages' => [
                'label' => 'Messages',
                'events' => ['logistics_message_received'],
            ],
            'announcements' => [
                'label' => 'Announcements',
                'events' => ['announcement_published'],
            ],
        ],
        'rider' => [
            'pickups' => [
                'label' => 'Pickup assignments',
                'events' => ['rider_pickup_assigned'],
            ],
            'deliveries' => [
                'label' => 'Delivery assignments',
                'events' => ['rider_dispatch_assigned'],
            ],
            'refund_cash' => [
                'label' => 'Refund cash assignments',
                'events' => ['rider_refund_cash_assigned'],
            ],
            'announcements' => [
                'label' => 'Announcements',
                'events' => ['announcement_published'],
            ],
        ],
        'admin' => [
            'registrations' => [
                'label' => 'Registrations',
                'events' => ['admin_registration_submitted'],
            ],
            'compliance' => [
                'label' => 'Compliance',
                'events' => ['admin_compliance_flagged'],
            ],
            'returns' => [
                'label' => 'Returns & refunds',
                'events' => ['admin_return_submitted'],
            ],
            'finance' => [
                'label' => 'Finance & COD',
                'events' => ['admin_withdrawal_submitted', 'admin_cod_remitted', 'admin_cod_discrepancy'],
            ],
            'messages' => [
                'label' => 'Support messages',
                'events' => ['admin_message_received'],
            ],
        ],
    ];

    public function supports(string $role): bool
    {
        return isset(self::CATEGORIES[$role]);
    }

    /**
     * Ordered filter tabs for the role: All, Unread, then the role categories.
     *
     * @return list<array{key: string, label: string}>
     */
    public function tabsFor(string $role): array
    {
        $tabs = [
            ['key' => 'all', 'label' => 'All'],
            ['key' => 'unread', 'label' => 'Unread'],
        ];

        foreach (self::CATEGORIES[$role] ?? [] as $key => $category) {
            $tabs[] = ['key' => $key, 'label' => $category['label']];
        }

        return $tabs;
    }

    /**
     * The registry event keys owned by a role category. Returns null when the
     * category means "everything" (all, unread, empty, or unknown) so callers
     * simply skip the event filter.
     *
     * @return list<string>|null
     */
    public function eventsFor(string $role, ?string $category): ?array
    {
        $category = trim((string) $category);

        if ($category === '' || $category === 'all' || $category === 'unread') {
            return null;
        }

        return self::CATEGORIES[$role][$category]['events'] ?? null;
    }

    public function isUnreadOnly(string $role, ?string $category): bool
    {
        return trim((string) $category) === 'unread';
    }

    /**
     * The category key for one notification event, or null when the event has
     * no dedicated tab (for example account alerts).
     */
    public function categoryForEvent(string $role, ?string $event): ?string
    {
        if ($event === null || $event === '') {
            return null;
        }

        foreach (self::CATEGORIES[$role] ?? [] as $key => $category) {
            if (in_array($event, $category['events'], true)) {
                return $key;
            }
        }

        return null;
    }
}

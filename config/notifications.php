<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notification events
    |--------------------------------------------------------------------------
    |
    | Registry of every notification event Bearly can deliver. Each event has:
    |
    | - label/description: shown in role preference UIs.
    | - group:  coarse category used by history filters.
    | - audience: roles that may receive the event.
    | - mandatory: events that always deliver in-app and cannot be disabled
    |   by the user (including platform-wide Admin announcements).
    | - defaults: channel values applied when the user has no stored row.
    |
    | Rows in `user_notification_preferences` override the defaults.
    |
    */

    'events' => [

        /*
        |------------------------------------------------------------------
        | Shared account events
        |------------------------------------------------------------------
        */

        'account_status_changed' => [
            'label' => 'Account and security alerts',
            'description' => 'Decisions and status changes on your account, such as approval, suspension, or reactivation.',
            'group' => 'account',
            'audience' => ['buyer', 'seller', 'admin', 'logistics', 'rider'],
            'mandatory' => true,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'announcement_published' => [
            'label' => 'Platform announcements',
            'description' => 'Announcements published for your Buyer, Seller, Logistics, or Rider audience.',
            'group' => 'announcements',
            'audience' => ['buyer', 'seller', 'logistics', 'rider'],
            'mandatory' => true,
            'defaults' => ['in_app' => true, 'email' => false],
        ],

        /*
        |------------------------------------------------------------------
        | Buyer events
        |------------------------------------------------------------------
        */

        'buyer_order_updates' => [
            'label' => 'Order updates',
            'description' => 'Confirmations and progress for orders you place, including payment confirmation and Seller status changes.',
            'group' => 'orders',
            'audience' => ['buyer'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'buyer_shipment_updates' => [
            'label' => 'Shipment tracking',
            'description' => 'Pickup, out-for-delivery, delivered, and failed-delivery updates for your parcels.',
            'group' => 'shipments',
            'audience' => ['buyer'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'buyer_return_updates' => [
            'label' => 'Returns and refunds',
            'description' => 'Progress on your return and refund cases.',
            'group' => 'returns',
            'audience' => ['buyer'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'buyer_message_received' => [
            'label' => 'Messages',
            'description' => 'New replies from Sellers or Bearly support in your conversations.',
            'group' => 'messages',
            'audience' => ['buyer'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        /*
        |------------------------------------------------------------------
        | Seller events
        |------------------------------------------------------------------
        */

        'seller_order_updates' => [
            'label' => 'Order updates',
            'description' => 'New orders and payment confirmations for your store.',
            'group' => 'orders',
            'audience' => ['seller'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'seller_order_cancelled' => [
            'label' => 'Order cancellations',
            'description' => 'Orders cancelled by the Buyer before your store confirmed them.',
            'group' => 'orders',
            'audience' => ['seller'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'seller_return_updates' => [
            'label' => 'Return and refund requests',
            'description' => 'Return and refund cases filed against your store and updates on them.',
            'group' => 'returns',
            'audience' => ['seller'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'seller_buyer_message' => [
            'label' => 'Buyer messages',
            'description' => 'New messages from Buyers, Logistics, or Bearly Support in your conversations.',
            'group' => 'messages',
            'audience' => ['seller'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'seller_pickup_updates' => [
            'label' => 'Pickup updates',
            'description' => 'Rider and Logistics changes to your pickup requests.',
            'group' => 'fulfillment',
            'audience' => ['seller'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'seller_shipment_updates' => [
            'label' => 'Shipment updates',
            'description' => 'Delivery milestones for your shipped orders, including completed deliveries.',
            'group' => 'fulfillment',
            'audience' => ['seller'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'seller_withdrawal_status_changed' => [
            'label' => 'Withdrawal updates',
            'description' => 'Finance decisions and processing updates for your Seller withdrawals.',
            'group' => 'wallet',
            'audience' => ['seller'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'seller_cod_cash_reconciled' => [
            'label' => 'COD cash reconciliation',
            'description' => 'Finance confirmation of COD cash remitted for your orders.',
            'group' => 'wallet',
            'audience' => ['seller'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => false],
        ],

        'seller_compliance_flagged' => [
            'label' => 'Compliance reviews',
            'description' => 'Products held or blocked by compliance scanning, and compliance warnings issued on your listings.',
            'group' => 'compliance',
            'audience' => ['seller'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        /*
        |------------------------------------------------------------------
        | Admin events
        |------------------------------------------------------------------
        */

        'admin_registration_submitted' => [
            'label' => 'New registrations',
            'description' => 'Buyer, Seller, and Logistics applications waiting for review.',
            'group' => 'operations',
            'audience' => ['admin'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'admin_compliance_flagged' => [
            'label' => 'Compliance flags',
            'description' => 'Products held or blocked by compliance scanning that need moderation.',
            'group' => 'compliance',
            'audience' => ['admin'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'admin_return_submitted' => [
            'label' => 'Return and refund cases',
            'description' => 'New Buyer return and refund cases awaiting review.',
            'group' => 'returns',
            'audience' => ['admin'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'admin_withdrawal_submitted' => [
            'label' => 'Withdrawal reviews',
            'description' => 'Seller withdrawals waiting for Finance review.',
            'group' => 'finance',
            'audience' => ['admin'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'admin_cod_remitted' => [
            'label' => 'COD reconciliations',
            'description' => 'COD cash remitted by Logistics and waiting for Finance reconciliation.',
            'group' => 'finance',
            'audience' => ['admin'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'admin_cod_discrepancy' => [
            'label' => 'COD discrepancies',
            'description' => 'COD collections where the cash received differs from the amount due.',
            'group' => 'finance',
            'audience' => ['admin'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'admin_message_received' => [
            'label' => 'Support messages',
            'description' => 'New buyer or seller messages in your support conversations.',
            'group' => 'messages',
            'audience' => ['admin'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        /*
        |------------------------------------------------------------------
        | Logistics events
        |------------------------------------------------------------------
        */

        'logistics_rider_application_submitted' => [
            'label' => 'Rider applications',
            'description' => 'Rider applications submitted under your Logistics account.',
            'group' => 'registrations',
            'audience' => ['logistics'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'logistics_pickup_requested' => [
            'label' => 'Pickup requests',
            'description' => 'Seller and return pickups scheduled with your facility.',
            'group' => 'pickups',
            'audience' => ['logistics'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'logistics_handoff_sent' => [
            'label' => 'Incoming handoffs',
            'description' => 'Parcels sent to your facility through a cross-city Logistics handoff.',
            'group' => 'shipments',
            'audience' => ['logistics'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'logistics_handoff_accepted' => [
            'label' => 'Handoff confirmations',
            'description' => 'Handoffs from your facility accepted by the receiving Logistics partner.',
            'group' => 'shipments',
            'audience' => ['logistics'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'logistics_cod_collected' => [
            'label' => 'COD custody',
            'description' => 'COD cash collected by your Riders that needs receiving and remittance.',
            'group' => 'cash',
            'audience' => ['logistics'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'logistics_refund_cash_issued' => [
            'label' => 'Refund cash custody',
            'description' => 'Refund cash issued to your facility for delivery to buyers.',
            'group' => 'cash',
            'audience' => ['logistics'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'logistics_message_received' => [
            'label' => 'Messages',
            'description' => 'New messages from Sellers and Bearly Support in your Logistics conversations.',
            'group' => 'messages',
            'audience' => ['logistics'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        /*
        |------------------------------------------------------------------
        | Rider events
        |------------------------------------------------------------------
        */

        'rider_pickup_assigned' => [
            'label' => 'Pickup assignments',
            'description' => 'New Seller pickups assigned to you.',
            'group' => 'assignments',
            'audience' => ['rider'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'rider_dispatch_assigned' => [
            'label' => 'Delivery assignments',
            'description' => 'New delivery batches dispatched to you.',
            'group' => 'assignments',
            'audience' => ['rider'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

        'rider_refund_cash_assigned' => [
            'label' => 'Refund cash assignments',
            'description' => 'Refund cash handovers assigned for delivery to buyers.',
            'group' => 'cash',
            'audience' => ['rider'],
            'mandatory' => false,
            'defaults' => ['in_app' => true, 'email' => true],
        ],

    ],

];

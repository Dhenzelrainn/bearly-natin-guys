<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class SellerWorkflowController extends Controller
{
    private const COMMISSION_RATE = 0.10;

    private function seller(): array
    {
        return [
            'name' => 'Bea Rivera',
            'first_name' => 'Bea',
            'initials' => 'BR',
            'email' => 'bea@juansclothing.test',
            'store' => "Juan's Clothing Shop",
            'business_category' => 'Fashion and Apparel',
        ];
    }

    private function notifications(): array
    {
        return [
            ['title' => '2 new orders need confirmation', 'time' => '5 minutes ago', 'type' => 'warning'],
            ['title' => 'Classic Linen Shirt is low in stock', 'time' => '42 minutes ago', 'type' => 'danger'],
            ['title' => '2 parcels are ready for pickup request', 'time' => '1 hour ago', 'type' => 'success'],
        ];
    }

    private function money(float $amount): string
    {
        return '₱'.number_format($amount, 2);
    }

    private function financeSnapshot(): array
    {
        $grossSales = 128450.00;
        $sellerDiscounts = 4430.00;
        $refunds = 1299.00;
        $commissionBaseBeforeReversal = $grossSales - $sellerDiscounts;
        $commissionEarnedBeforeReversal = $commissionBaseBeforeReversal * self::COMMISSION_RATE;
        $commissionReversal = $refunds * self::COMMISSION_RATE;
        $netCommission = $commissionEarnedBeforeReversal - $commissionReversal;
        $netCommissionableSales = $grossSales - $sellerDiscounts - $refunds;
        $netEarnings = $grossSales - $sellerDiscounts - $refunds - $netCommission;
        $totalDeductions = $sellerDiscounts + $refunds + $netCommission;

        return compact(
            'grossSales',
            'sellerDiscounts',
            'refunds',
            'commissionBaseBeforeReversal',
            'commissionEarnedBeforeReversal',
            'commissionReversal',
            'netCommission',
            'netCommissionableSales',
            'netEarnings',
            'totalDeductions'
        );
    }

    private function ordersData(): array
    {
        return [
            [
                'id' => '#BR-1060', 'customer' => 'Angela Cruz', 'items' => '1 item',
                'item_detail' => 'Classic Linen Shirt · Olive / Medium',
                'payment' => 'GCash · Paid', 'payment_key' => 'paid', 'total' => '₱1,299',
                'deadline' => 'Today · 12:00 PM', 'date_key' => 'today', 'urgent' => true,
                'status' => 'Placed', 'canonical_status' => 'PLACED', 'status_key' => 'new', 'tone' => 'new',
                'responsibility' => 'Seller', 'next_step' => 'Review the order and confirm stock before accepting.',
                'action' => 'Review & Confirm', 'action_url' => null,
            ],
            [
                'id' => '#BR-1059', 'customer' => 'Noah Reyes', 'items' => '2 items',
                'item_detail' => 'Canvas Tote Bag · Natural ×2',
                'payment' => 'Cash on Delivery', 'payment_key' => 'cod', 'total' => '₱1,798',
                'deadline' => 'Today · 12:30 PM', 'date_key' => 'today', 'urgent' => true,
                'status' => 'Placed', 'canonical_status' => 'PLACED', 'status_key' => 'new', 'tone' => 'new',
                'responsibility' => 'Seller', 'next_step' => 'Review the order and confirm stock before accepting.',
                'action' => 'Review & Confirm', 'action_url' => null,
            ],
            [
                'id' => '#BR-1058', 'customer' => 'Maria Santos', 'items' => '2 items',
                'item_detail' => 'Classic Linen Shirt · Cream / Large + Canvas Tote Bag',
                'payment' => 'GCash · Paid', 'payment_key' => 'paid', 'total' => '₱1,850',
                'deadline' => 'Today · 1:00 PM', 'date_key' => 'today', 'urgent' => true,
                'status' => 'Confirmed', 'canonical_status' => 'CONFIRMED', 'status_key' => 'to-prepare', 'tone' => 'prepare',
                'responsibility' => 'Seller', 'next_step' => 'Start preparing and packing the confirmed order.',
                'action' => 'Start Preparing', 'action_url' => null,
            ],
            [
                'id' => '#BR-1057', 'customer' => 'Carlo Reyes', 'items' => '1 item',
                'item_detail' => 'Everyday Sneakers · White / Size 39',
                'payment' => 'Cash on Delivery', 'payment_key' => 'cod', 'total' => '₱1,780',
                'deadline' => 'Today · 1:30 PM', 'date_key' => 'today', 'urgent' => true,
                'status' => 'Preparing', 'canonical_status' => 'PREPARING', 'status_key' => 'to-prepare', 'tone' => 'prepare',
                'responsibility' => 'Seller', 'next_step' => 'Finish packing, print the waybill, then mark the parcel ready for pickup.',
                'action' => 'Continue Packing', 'action_url' => null,
            ],
            [
                'id' => '#BR-1056', 'customer' => 'Ana Cruz', 'items' => '3 items',
                'item_detail' => 'Classic Linen Shirt bundle · 3 pieces',
                'payment' => 'Maya · Paid', 'payment_key' => 'paid', 'total' => '₱2,450',
                'deadline' => 'Today · 3:00 PM', 'date_key' => 'today', 'urgent' => false,
                'status' => 'Ready for Pickup', 'canonical_status' => 'READY_FOR_PICKUP', 'status_key' => 'ready-pickup', 'tone' => 'pickup',
                'responsibility' => 'Seller / Logistics', 'next_step' => 'Submit the labeled parcel for logistics approval and rider assignment.',
                'action' => 'Arrange Pickup', 'action_url' => route('seller.fulfillment.pickups'),
            ],
            [
                'id' => '#BR-1055', 'customer' => 'Sofia Mendoza', 'items' => '1 item',
                'item_detail' => 'Canvas Tote Bag · Natural',
                'payment' => 'Cash on Delivery', 'payment_key' => 'cod', 'total' => '₱899',
                'deadline' => 'Today · 3:00 PM', 'date_key' => 'today', 'urgent' => false,
                'status' => 'Ready for Pickup', 'canonical_status' => 'READY_FOR_PICKUP', 'status_key' => 'ready-pickup', 'tone' => 'pickup',
                'responsibility' => 'Seller / Logistics', 'next_step' => 'Submit the labeled parcel for logistics approval and rider assignment.',
                'action' => 'Arrange Pickup', 'action_url' => route('seller.fulfillment.pickups'),
            ],
            [
                'id' => '#BR-1054', 'customer' => 'Jamie Lim', 'items' => '2 items',
                'item_detail' => 'Everyday Sneakers + Canvas Tote Bag',
                'payment' => 'GCash · Paid', 'payment_key' => 'paid', 'total' => '₱2,679',
                'deadline' => 'Picked up today', 'date_key' => 'today', 'urgent' => false,
                'status' => 'At Sorting Center', 'canonical_status' => 'AT_SORTING_CENTER', 'status_key' => 'shipped', 'tone' => 'transit',
                'responsibility' => 'Logistics', 'next_step' => 'Sorting center scans and routes the parcel by destination.',
                'action' => 'Track Shipment', 'action_url' => route('seller.fulfillment.tracking'),
            ],
            [
                'id' => '#BR-1053', 'customer' => 'Miguel Garcia', 'items' => '1 item',
                'item_detail' => 'Classic Linen Shirt · Cream / Large',
                'payment' => 'GCash · Paid', 'payment_key' => 'paid', 'total' => '₱1,299',
                'deadline' => 'In logistics network', 'date_key' => 'upcoming', 'urgent' => false,
                'status' => 'Sorted', 'canonical_status' => 'SORTED', 'status_key' => 'shipped', 'tone' => 'transit',
                'responsibility' => 'Logistics', 'next_step' => 'Logistics assigns the correct delivery rider for the destination area.',
                'action' => 'Track Shipment', 'action_url' => route('seller.fulfillment.tracking'),
            ],
            [
                'id' => '#BR-1052', 'customer' => 'Liam Reyes', 'items' => '1 item',
                'item_detail' => 'Canvas Tote Bag · Natural',
                'payment' => 'GCash · Paid', 'payment_key' => 'paid', 'total' => '₱899',
                'deadline' => 'ETA today', 'date_key' => 'today', 'urgent' => false,
                'status' => 'Out for Delivery', 'canonical_status' => 'OUT_FOR_DELIVERY', 'status_key' => 'shipped', 'tone' => 'transit',
                'responsibility' => 'Courier', 'next_step' => 'Courier delivers the parcel to the buyer.',
                'action' => 'Track Shipment', 'action_url' => route('seller.fulfillment.tracking'),
            ],
            [
                'id' => '#BR-1051', 'customer' => 'Kim Chua', 'items' => '1 item',
                'item_detail' => 'Everyday Sneakers · Black / Size 40',
                'payment' => 'Maya · Paid', 'payment_key' => 'paid', 'total' => '₱1,780',
                'deadline' => 'Delivered today', 'date_key' => 'today', 'urgent' => false,
                'status' => 'Delivered', 'canonical_status' => 'DELIVERED', 'status_key' => 'shipped', 'tone' => 'transit',
                'responsibility' => 'Buyer', 'next_step' => 'Wait for buyer confirmation. Earnings stay pending until the order becomes COMPLETED.',
                'action' => 'View Delivery', 'action_url' => route('seller.fulfillment.tracking'),
            ],
            [
                'id' => '#BR-1050', 'customer' => 'Paolo Ramos', 'items' => '1 item',
                'item_detail' => 'Classic Linen Shirt · Olive / Medium',
                'payment' => 'GCash · Paid', 'payment_key' => 'paid', 'total' => '₱1,299',
                'deadline' => 'Completed Sep 10', 'date_key' => 'upcoming', 'urgent' => false,
                'status' => 'Completed', 'canonical_status' => 'COMPLETED', 'status_key' => 'completed', 'tone' => 'completed',
                'responsibility' => 'System', 'next_step' => '10% platform commission is finalized and seller net earnings become available.',
                'action' => 'View Earnings', 'action_url' => route('seller.finance.transactions'),
            ],
            [
                'id' => '#BR-1049', 'customer' => 'Daniel Tan', 'items' => '2 items',
                'item_detail' => 'Canvas Tote Bag ×2',
                'payment' => 'Cash on Delivery', 'payment_key' => 'cod', 'total' => '₱1,798',
                'deadline' => 'Failed delivery yesterday', 'date_key' => 'upcoming', 'urgent' => true,
                'status' => 'Delivery Failed', 'canonical_status' => 'DELIVERY_FAILED', 'status_key' => 'shipped', 'tone' => 'cancelled',
                'responsibility' => 'Courier / Logistics', 'next_step' => 'Review the failure reason. Logistics may reschedule delivery or return the parcel.',
                'action' => 'Review Exception', 'action_url' => route('seller.fulfillment.tracking'),
            ],
            [
                'id' => '#BR-1048', 'customer' => 'Mae Villanueva', 'items' => '1 item',
                'item_detail' => 'Classic Linen Shirt · Cream / Small',
                'payment' => 'Cancelled', 'payment_key' => 'paid', 'total' => '₱1,299',
                'deadline' => 'Cancelled Sep 8', 'date_key' => 'upcoming', 'urgent' => false,
                'status' => 'Cancelled', 'canonical_status' => 'CANCELLED', 'status_key' => 'cancelled-returned', 'tone' => 'cancelled',
                'responsibility' => 'System', 'next_step' => 'No platform commission and no seller earnings are recorded for a cancelled order.',
                'action' => 'View Details', 'action_url' => null,
            ],
            [
                'id' => '#BR-1047', 'customer' => 'Anne Cruz', 'items' => '1 item',
                'item_detail' => 'Classic Linen Shirt · Olive / Medium',
                'payment' => 'Refunded', 'payment_key' => 'paid', 'total' => '₱1,299',
                'deadline' => 'Returned Sep 7', 'date_key' => 'upcoming', 'urgent' => false,
                'status' => 'Returned / Refunded', 'canonical_status' => 'RETURNED', 'status_key' => 'cancelled-returned', 'tone' => 'cancelled',
                'responsibility' => 'Platform / Logistics', 'next_step' => 'The refunded sale reverses both seller earnings and the related platform commission.',
                'action' => 'View Return Case', 'action_url' => route('seller.orders.returns'),
            ],
        ];
    }

    public function dashboard(): View
    {
        $finance = $this->financeSnapshot();
        $orders = collect($this->ordersData());

        $newOrders = $orders->where('status_key', 'new')->count();
        $toPrepare = $orders->where('status_key', 'to-prepare')->count();
        $readyPickup = $orders->where('status_key', 'ready-pickup')->count();
        $waybillToPrint = 1;

        return view('seller.Dashboard.dashboard', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'dashboard' => [
                'updated_at' => '5:42 PM',
                'action_count' => $newOrders + $toPrepare + $readyPickup + $waybillToPrint,
                'pickup_time' => '3:00 PM',
                'actions' => [
                    ['count' => $newOrders, 'label' => 'new orders', 'detail' => 'Review and confirm stock', 'action' => 'Review', 'icon' => 'clipboard-list', 'tone' => 'amber', 'target' => route('seller.orders.new')],
                    ['count' => $toPrepare, 'label' => 'orders to prepare', 'detail' => 'Confirmed / preparing', 'action' => 'Prepare', 'icon' => 'package', 'tone' => 'olive', 'target' => route('seller.orders.prepare')],
                    ['count' => $waybillToPrint, 'label' => 'waybill to print', 'detail' => 'Required before ready for pickup', 'action' => 'Print', 'icon' => 'printer', 'tone' => 'brown', 'target' => route('seller.fulfillment.waybills')],
                    ['count' => $readyPickup, 'label' => 'parcels ready for pickup', 'detail' => 'Submit to logistics', 'action' => 'Arrange pickup', 'icon' => 'truck', 'tone' => 'green', 'target' => route('seller.fulfillment.pickups')],
                ],
            ],
            'stats' => [
                ['label' => 'Gross Sales', 'value' => $this->money($finance['grossSales']), 'change' => '+12.5% this month', 'icon' => 'philippine-peso', 'tone' => 'gold'],
                ['label' => 'Net Earnings', 'value' => $this->money($finance['netEarnings']), 'change' => 'After discounts, refunds & commission', 'icon' => 'package-check', 'tone' => 'olive'],
                ['label' => 'Platform Commission', 'value' => $this->money($finance['netCommission']), 'change' => '10% after refund reversal', 'icon' => 'badge-percent', 'tone' => 'brown'],
                ['label' => 'Fulfillment Rate', 'value' => '96.8%', 'change' => '+1.4% vs last month', 'icon' => 'circle-check-big', 'tone' => 'olive'],
            ],
            'sales' => [9800, 13400, 13800, 18100, 22800, 15700, 24100],
            'topProducts' => [
                ['name' => 'Classic Linen Shirt', 'sku' => 'CLS-LINEN-SHIRT', 'sold' => 42, 'revenue' => '₱54,600', 'percent' => 100, 'icon' => 'shirt'],
                ['name' => 'Canvas Tote Bag', 'sku' => 'CNV-TOTE-BAG', 'sold' => 31, 'revenue' => '₱27,869', 'percent' => 74, 'icon' => 'shopping-bag'],
                ['name' => 'Everyday Sneakers', 'sku' => 'EV-SNKRS-WHT', 'sold' => 18, 'revenue' => '₱23,382', 'percent' => 43, 'icon' => 'footprints'],
            ],
            'payoutSummary' => [
                ['label' => 'Gross completed sales', 'value' => $this->money($finance['grossSales'])],
                ['label' => 'Seller discounts + refunds', 'value' => '−'.$this->money($finance['sellerDiscounts'] + $finance['refunds'])],
                ['label' => 'Platform commission (10%)', 'value' => '−'.$this->money($finance['netCommission'])],
                ['label' => 'Net earnings', 'value' => $this->money($finance['netEarnings'])],
            ],
            'pickupSummary' => [
                'time' => '3:00 PM',
                'date' => 'Today · Laguna route',
                'ready' => $readyPickup,
                'not_ready' => $toPrepare + $newOrders,
            ],
            'sellerHealth' => [
                'status' => 'Good standing',
                'score' => '96 / 100',
                'summary' => 'No category violations or account warnings. One customer case is still open.',
                'items' => [
                    ['label' => 'Product compliance', 'value' => '0 violations', 'note' => 'All active products match the registered business category.', 'tone' => 'success', 'icon' => 'shield-check'],
                    ['label' => 'Open disputes', 'value' => '1 case', 'note' => 'Respond before the case deadline to protect your seller record.', 'tone' => 'warning', 'icon' => 'messages-square'],
                    ['label' => 'Response rate', 'value' => '91%', 'note' => 'Buyer messages answered within the expected response window.', 'tone' => 'info', 'icon' => 'message-circle-reply'],
                    ['label' => 'Fulfillment reliability', 'value' => '96.8%', 'note' => 'Orders prepared and handed over within seller deadlines.', 'tone' => 'success', 'icon' => 'package-check'],
                ],
            ],
        ]);
    }

    public function orders(Request $request): View
    {
        $orders = $this->ordersData();
        $allowed = ['all', 'new', 'to-prepare', 'ready-pickup', 'shipped', 'completed', 'cancelled-returned'];
        $requestedStatus = (string) $request->query('status', 'all');
        $defaultStatus = in_array($requestedStatus, $allowed, true) ? $requestedStatus : 'all';
        $count = fn (string $status) => collect($orders)->where('status_key', $status)->count();

        return view('seller.Orders.orders', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'defaultOrderStatus' => $defaultStatus,
            'orderQueue' => [
                ['label' => 'New orders', 'count' => $count('new'), 'note' => 'Confirm stock before accepting', 'icon' => 'clipboard-list', 'tone' => 'gold'],
                ['label' => 'To prepare', 'count' => $count('to-prepare'), 'note' => 'Confirmed and preparing', 'icon' => 'package', 'tone' => 'olive'],
                ['label' => 'Waybill to print', 'count' => 1, 'note' => 'Print before marking ready', 'icon' => 'printer', 'tone' => 'blue'],
                ['label' => 'Ready for pickup', 'count' => $count('ready-pickup'), 'note' => 'Submit to logistics', 'icon' => 'truck', 'tone' => 'brown'],
            ],
            'orderTabs' => [
                ['key' => 'all', 'label' => 'All Orders', 'count' => count($orders)],
                ['key' => 'new', 'label' => 'New', 'count' => $count('new')],
                ['key' => 'to-prepare', 'label' => 'To Prepare', 'count' => $count('to-prepare')],
                ['key' => 'ready-pickup', 'label' => 'Ready for Pickup', 'count' => $count('ready-pickup')],
                ['key' => 'shipped', 'label' => 'Shipped', 'count' => $count('shipped')],
                ['key' => 'completed', 'label' => 'Completed', 'count' => $count('completed')],
                ['key' => 'cancelled-returned', 'label' => 'Cancelled / Returned', 'count' => $count('cancelled-returned')],
            ],
            'orders' => $orders,
        ]);
    }

    public function waybills(): View
    {
        return view('seller.fulfillment.waybills', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'summary' => [
                ['label' => 'Ready to Print', 'value' => '1', 'note' => 'Preparing order is packed', 'icon' => 'printer', 'tone' => 'gold'],
                ['label' => 'Printed Today', 'value' => '2', 'note' => 'Ready for pickup', 'icon' => 'file-check-2', 'tone' => 'olive'],
                ['label' => 'Reprint Required', 'value' => '0', 'note' => 'No damaged labels', 'icon' => 'refresh-cw', 'tone' => 'brown'],
                ['label' => 'Pickup Cutoff', 'value' => '2:30 PM', 'note' => 'For today’s collection', 'icon' => 'clock-3', 'tone' => 'warning'],
            ],
            'waybills' => [
                ['order' => '#BR-1057', 'tracking' => 'Pending generation', 'customer' => 'Carlo Reyes', 'destination' => 'Calamba, Laguna', 'courier' => 'Bearly Logistics', 'packages' => 1, 'weight' => '1.0 kg', 'size' => '34 × 24 × 13 cm', 'pickup' => 'Today · 3:00 PM', 'status' => 'Ready to Print', 'status_key' => 'ready', 'action' => 'Print Waybill'],
                ['order' => '#BR-1056', 'tracking' => 'BRLY-784231', 'customer' => 'Ana Cruz', 'destination' => 'Los Baños, Laguna', 'courier' => 'Bearly Logistics', 'packages' => 1, 'weight' => '0.9 kg', 'size' => '30 × 22 × 10 cm', 'pickup' => 'Today · 3:00 PM', 'status' => 'Printed', 'status_key' => 'printed', 'action' => 'View Label'],
                ['order' => '#BR-1055', 'tracking' => 'BRLY-784230', 'customer' => 'Sofia Mendoza', 'destination' => 'Cabuyao, Laguna', 'courier' => 'Bearly Logistics', 'packages' => 1, 'weight' => '0.5 kg', 'size' => '24 × 18 × 7 cm', 'pickup' => 'Today · 3:00 PM', 'status' => 'Printed', 'status_key' => 'printed', 'action' => 'View Label'],
            ],
            'history' => [
                ['time' => 'Today · 1:08 PM', 'order' => '#BR-1056', 'tracking' => 'BRLY-784231', 'action' => 'Waybill printed', 'actor' => 'Bea Rivera'],
                ['time' => 'Today · 12:52 PM', 'order' => '#BR-1055', 'tracking' => 'BRLY-784230', 'action' => 'Waybill printed', 'actor' => 'Bea Rivera'],
            ],
        ]);
    }

    public function pickupRequests(): View
    {
        return view('seller.fulfillment.pickups', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'summary' => [
                ['label' => 'Ready to Request', 'value' => '2', 'note' => 'Labeled parcels', 'icon' => 'package-check', 'tone' => 'gold'],
                ['label' => 'Awaiting Approval', 'value' => '1', 'note' => 'Submitted to logistics', 'icon' => 'hourglass', 'tone' => 'brown'],
                ['label' => 'Rider Assigned', 'value' => '1', 'note' => 'Pickup scheduled', 'icon' => 'bike', 'tone' => 'olive'],
                ['label' => 'Picked Up Today', 'value' => '1', 'note' => 'Handover confirmed', 'icon' => 'truck', 'tone' => 'info'],
            ],
            'eligibleOrders' => [
                ['order' => '#BR-1056', 'tracking' => 'BRLY-784231', 'packages' => 1, 'weight' => '0.9 kg'],
                ['order' => '#BR-1055', 'tracking' => 'BRLY-784230', 'packages' => 1, 'weight' => '0.5 kg'],
            ],
            'requests' => [
                ['id' => 'PU-0911-04', 'orders' => '1 order', 'packages' => 1, 'provider' => 'Bearly Logistics', 'schedule' => 'Today · 3:00–5:00 PM', 'address' => 'Juan’s Clothing Shop, Santa Rosa', 'rider' => 'Marco Dela Cruz · Rider 014', 'status' => 'Rider Assigned', 'status_key' => 'assigned'],
                ['id' => 'PU-0911-03', 'orders' => '1 order', 'packages' => 1, 'provider' => 'Bearly Logistics', 'schedule' => 'Tomorrow · 10:00 AM–12:00 PM', 'address' => 'Juan’s Clothing Shop, Santa Rosa', 'rider' => 'Awaiting assignment', 'status' => 'Pending Approval', 'status_key' => 'pending'],
                ['id' => 'PU-0911-01', 'orders' => '1 order', 'packages' => 1, 'provider' => 'Bearly Logistics', 'schedule' => 'Today · 11:00 AM', 'address' => 'Juan’s Clothing Shop, Santa Rosa', 'rider' => 'Paolo Reyes · Rider 008', 'status' => 'Picked Up', 'status_key' => 'picked-up'],
            ],
        ]);
    }

    public function shipmentTracking(): View
    {
        return view('seller.fulfillment.tracking', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'summary' => [
                ['label' => 'Sorting / Routed', 'value' => '2', 'note' => 'At center or already sorted', 'icon' => 'scan-line', 'tone' => 'info'],
                ['label' => 'Out for Delivery', 'value' => '1', 'note' => 'With delivery rider', 'icon' => 'bike', 'tone' => 'gold'],
                ['label' => 'Delivered', 'value' => '1', 'note' => 'Waiting buyer confirmation', 'icon' => 'badge-check', 'tone' => 'olive'],
                ['label' => 'Needs Attention', 'value' => '1', 'note' => 'Delivery exception', 'icon' => 'triangle-alert', 'tone' => 'warning'],
            ],
            'shipments' => [
                ['tracking' => 'BRLY-784228', 'order' => '#BR-1054', 'customer' => 'Jamie Lim', 'destination' => 'Biñan, Laguna', 'rider' => 'Pickup rider: Paolo Reyes', 'latest' => 'Parcel received at Santa Rosa Sorting Center', 'updated' => 'Today · 2:18 PM', 'eta' => 'Sep 12', 'status' => 'At Sorting Center', 'status_key' => 'sorting', 'canonical_status' => 'AT_SORTING_CENTER'],
                ['tracking' => 'BRLY-784218', 'order' => '#BR-1053', 'customer' => 'Miguel Garcia', 'destination' => 'Calamba, Laguna', 'rider' => 'Delivery rider assignment pending', 'latest' => 'Parcel sorted for Calamba delivery area', 'updated' => 'Today · 1:42 PM', 'eta' => 'Sep 12', 'status' => 'Sorted', 'status_key' => 'transit', 'canonical_status' => 'SORTED'],
                ['tracking' => 'BRLY-784188', 'order' => '#BR-1052', 'customer' => 'Liam Reyes', 'destination' => 'Los Baños, Laguna', 'rider' => 'Paolo Reyes', 'latest' => 'Rider is delivering the parcel', 'updated' => 'Today · 9:15 AM', 'eta' => 'Today', 'status' => 'Out for Delivery', 'status_key' => 'out-delivery', 'canonical_status' => 'OUT_FOR_DELIVERY'],
                ['tracking' => 'BRLY-784176', 'order' => '#BR-1051', 'customer' => 'Kim Chua', 'destination' => 'Calamba, Laguna', 'rider' => 'John Ramos', 'latest' => 'Delivered to customer; awaiting buyer confirmation', 'updated' => 'Today · 10:05 AM', 'eta' => 'Delivered', 'status' => 'Delivered', 'status_key' => 'delivered', 'canonical_status' => 'DELIVERED'],
                ['tracking' => 'BRLY-784160', 'order' => '#BR-1049', 'customer' => 'Daniel Tan', 'destination' => 'Pagsanjan, Laguna', 'rider' => 'Alex Santos', 'latest' => 'Delivery failed: customer unavailable', 'updated' => 'Yesterday · 5:40 PM', 'eta' => 'Reschedule', 'status' => 'Delivery Failed', 'status_key' => 'failed', 'canonical_status' => 'DELIVERY_FAILED'],
            ],
        ]);
    }

    public function returns(): View
    {
        return view('seller.Orders.returns-refunds.returns', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'summary' => [
                ['label' => 'Action Required', 'value' => '2', 'note' => 'Seller must respond', 'icon' => 'triangle-alert', 'tone' => 'warning'],
                ['label' => 'Under Review', 'value' => '2', 'note' => 'Platform/admin reviewing', 'icon' => 'scan-search', 'tone' => 'neutral'],
                ['label' => 'Return in Progress', 'value' => '1', 'note' => 'Logistics handling return', 'icon' => 'package-open', 'tone' => 'info'],
                ['label' => 'Refunded This Month', 'value' => '₱1,299', 'note' => 'Commission reversed', 'icon' => 'badge-check', 'tone' => 'success'],
            ],
            'tabs' => [
                ['key' => 'all', 'label' => 'All Cases', 'count' => 6],
                ['key' => 'action-required', 'label' => 'Action Required', 'count' => 2],
                ['key' => 'under-review', 'label' => 'Under Review', 'count' => 2],
                ['key' => 'return-shipping', 'label' => 'Return Shipping', 'count' => 1],
                ['key' => 'resolved', 'label' => 'Resolved', 'count' => 1],
            ],
            'returns' => $this->returnCases(),
        ]);
    }

    public function returnDetails(string $caseId): View
    {
        $case = collect($this->returnCases())->firstWhere('id', strtoupper($caseId));
        abort_if($case === null, 404);

        return view('seller.Orders.returns-refunds.return-details', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'case' => $case,
        ]);
    }

    private function returnCases(): array
    {
        return [
            ['id' => 'RR-2041', 'order' => '#BR-1046', 'customer' => 'Maria Santos', 'product' => 'Classic Linen Shirt · Olive / M', 'sku' => 'CLS-OLV-M', 'request' => 'Return & Refund', 'reason' => 'Wrong size received', 'amount' => '₱1,299', 'submitted' => 'Today · 9:20 AM', 'deadline' => '1h 42m left', 'status' => 'Action Required', 'status_key' => 'action-required', 'tone' => 'warning', 'evidence' => '3 photos', 'buyer_note' => 'The tag says Medium, but the actual fit and measurements are smaller than the listed size.', 'seller_response' => 'No response submitted yet.', 'resolution' => 'Awaiting seller response', 'action' => 'Review Case', 'tracking' => 'Not yet created', 'commission_effect' => 'No adjustment yet', 'earnings_effect' => 'No adjustment yet'],
            ['id' => 'RR-2040', 'order' => '#BR-1045', 'customer' => 'Paolo Ramos', 'product' => 'Canvas Tote Bag · Natural', 'sku' => 'CTB-NAT-OS', 'request' => 'Refund Only', 'reason' => 'Missing item', 'amount' => '₱899', 'submitted' => 'Today · 8:05 AM', 'deadline' => '3h 10m left', 'status' => 'Action Required', 'status_key' => 'action-required', 'tone' => 'warning', 'evidence' => '1 unboxing video', 'buyer_note' => 'The parcel arrived sealed, but the tote bag was not inside the package.', 'seller_response' => 'No response submitted yet.', 'resolution' => 'Awaiting seller response', 'action' => 'Review Case', 'tracking' => 'Not yet created', 'commission_effect' => 'No adjustment yet', 'earnings_effect' => 'No adjustment yet'],
            ['id' => 'RR-2038', 'order' => '#BR-1044', 'customer' => 'Carlo Reyes', 'product' => 'Everyday Sneakers · White / 39', 'sku' => 'ES-WHT-39', 'request' => 'Return & Refund', 'reason' => 'Item not as described', 'amount' => '₱1,780', 'submitted' => 'Yesterday · 4:10 PM', 'deadline' => 'Responded', 'status' => 'Under Review', 'status_key' => 'under-review', 'tone' => 'review', 'evidence' => '4 photos', 'buyer_note' => 'The color and sole pattern do not match the product listing.', 'seller_response' => 'Requested platform review; warehouse packing photo was attached.', 'resolution' => 'Platform reviewing both parties’ evidence', 'action' => 'View Case', 'tracking' => 'Not yet created', 'commission_effect' => 'Pending platform decision', 'earnings_effect' => 'Pending platform decision'],
            ['id' => 'RR-2035', 'order' => '#BR-1041', 'customer' => 'Sofia Mendoza', 'product' => 'Classic Linen Shirt · Cream / L', 'sku' => 'CLS-CRM-L', 'request' => 'Refund Only', 'reason' => 'Damaged item', 'amount' => '₱1,299', 'submitted' => 'Aug 30, 2026', 'deadline' => 'Responded', 'status' => 'Under Review', 'status_key' => 'under-review', 'tone' => 'review', 'evidence' => '2 photos', 'buyer_note' => 'There is a visible tear near the left sleeve seam.', 'seller_response' => 'Accepted buyer evidence and approved refund without return.', 'resolution' => 'Refund approval being processed', 'action' => 'View Case', 'tracking' => 'Not required', 'commission_effect' => 'Will reverse if refund is finalized', 'earnings_effect' => 'Will reverse if refund is finalized'],
            ['id' => 'RR-2031', 'order' => '#BR-1036', 'customer' => 'Jamie Lim', 'product' => 'Everyday Sneakers · Black / 40', 'sku' => 'ES-BLK-40', 'request' => 'Return & Refund', 'reason' => 'Damaged during delivery', 'amount' => '₱1,780', 'submitted' => 'Aug 29, 2026', 'deadline' => 'Return by Sep 16', 'status' => 'Return Shipping', 'status_key' => 'return-shipping', 'tone' => 'shipping', 'evidence' => '3 photos', 'buyer_note' => 'The shoe box and right shoe were crushed when delivered.', 'seller_response' => 'Return approved after reviewing courier damage evidence.', 'resolution' => 'Return parcel is in transit to seller', 'action' => 'Track Return', 'tracking' => 'BRLY-RET-20431', 'commission_effect' => 'Pending return completion', 'earnings_effect' => 'Pending return completion'],
            ['id' => 'RR-2025', 'order' => '#BR-1047', 'customer' => 'Anne Cruz', 'product' => 'Classic Linen Shirt · Olive / M', 'sku' => 'CLS-OLV-M', 'request' => 'Refund Only', 'reason' => 'Seller approved refund', 'amount' => '₱1,299', 'submitted' => 'Sep 7, 2026', 'deadline' => 'Closed Sep 8', 'status' => 'Refunded', 'status_key' => 'resolved', 'tone' => 'resolved', 'evidence' => '2 photos', 'buyer_note' => 'The item arrived with a manufacturing defect.', 'seller_response' => 'Refund approved. No item return required.', 'resolution' => '₱1,299 returned to buyer via GCash', 'action' => 'View Details', 'tracking' => 'Not required', 'commission_effect' => '+₱129.90 commission reversal', 'earnings_effect' => '−₱1,169.10 seller earnings reversal'],
        ];
    }

    private function financeTransactionRows(): array
    {
        return [
            ['date' => 'Sep 10, 2026', 'order' => '#BR-1050', 'gross' => '₱1,299.00', 'discount' => '₱0.00', 'refund' => '₱0.00', 'commissionable' => '₱1,299.00', 'commission' => '−₱129.90', 'commission_reversal' => '—', 'net' => '₱1,169.10', 'status' => 'Available', 'status_key' => 'available', 'note' => 'Order completed; 10% commission finalized.'],
            ['date' => 'Sep 11, 2026', 'order' => '#BR-1051', 'gross' => '₱1,780.00', 'discount' => '₱0.00', 'refund' => '₱0.00', 'commissionable' => '₱1,780.00', 'commission' => 'Estimated −₱178.00', 'commission_reversal' => '—', 'net' => 'Estimated ₱1,602.00', 'status' => 'Pending confirmation', 'status_key' => 'pending', 'note' => 'Delivered; waits for buyer confirmation before settlement.'],
            ['date' => 'Sep 11, 2026', 'order' => '#BR-1052', 'gross' => '₱899.00', 'discount' => '₱90.00', 'refund' => '₱0.00', 'commissionable' => '₱809.00', 'commission' => 'Estimated −₱80.90', 'commission_reversal' => '—', 'net' => 'Estimated ₱728.10', 'status' => 'Pending', 'status_key' => 'pending', 'note' => 'Out for delivery; commission is not final yet.'],
            ['date' => 'Sep 8, 2026', 'order' => '#BR-1047', 'gross' => '₱1,299.00', 'discount' => '₱0.00', 'refund' => '−₱1,299.00', 'commissionable' => '₱0.00', 'commission' => '₱0.00', 'commission_reversal' => '+₱129.90', 'net' => '₱0.00', 'status' => 'Reversed', 'status_key' => 'reversed', 'note' => 'Full refund reversed the seller earnings and platform commission.'],
            ['date' => 'Sep 8, 2026', 'order' => '#BR-1048', 'gross' => '₱1,299.00', 'discount' => '₱0.00', 'refund' => 'Cancelled', 'commissionable' => '₱0.00', 'commission' => '₱0.00', 'commission_reversal' => '—', 'net' => '₱0.00', 'status' => 'Cancelled', 'status_key' => 'cancelled', 'note' => 'Cancelled before completion; no commission is charged.'],
        ];
    }

    public function financeEarnings(): View
    {
        $finance = $this->financeSnapshot();

        return view('seller.Finance.earnings', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'commissionRate' => self::COMMISSION_RATE * 100,
            'summary' => [
                ['label' => 'Gross Sales', 'value' => $this->money($finance['grossSales']), 'note' => 'Completed sales before deductions'],
                ['label' => 'Seller Discounts + Refunds', 'value' => '−'.$this->money($finance['sellerDiscounts'] + $finance['refunds']), 'note' => 'Seller-funded discounts and finalized refunds'],
                ['label' => 'Platform Commission', 'value' => '−'.$this->money($finance['netCommission']), 'note' => '10% after refund commission reversal'],
                ['label' => 'Net Earnings', 'value' => $this->money($finance['netEarnings']), 'note' => 'Amount attributable to the seller'],
            ],
            'finance' => $finance,
            'recentTransactions' => array_slice($this->financeTransactionRows(), 0, 4),
        ]);
    }

    public function financeTransactions(): View
    {
        return view('seller.Finance.transactions', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'transactions' => $this->financeTransactionRows(),
            'commissionRate' => self::COMMISSION_RATE * 100,
        ]);
    }

    public function reportsOverview(): View
    {
        $finance = $this->financeSnapshot();

        return view('seller.Reports.overview', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'period' => 'Aug 1 – Aug 31, 2026',
            'metrics' => [
                ['label' => 'Net Earnings', 'value' => $this->money($finance['netEarnings']), 'change' => '+10.8%', 'context' => 'after deductions', 'primary' => true],
                ['label' => 'Gross Sales', 'value' => $this->money($finance['grossSales']), 'change' => '+12.5%', 'context' => 'vs July'],
                ['label' => 'Orders', 'value' => '91', 'change' => '+8.3%', 'context' => 'vs July'],
                ['label' => 'Average Order', 'value' => '₱1,411', 'change' => '+3.9%', 'context' => 'vs July'],
            ],
            'salesTrend' => [14200, 17850, 19600, 18100, 16900, 20700, 21100],
            'salesLabels' => ['Aug 1–4', 'Aug 5–9', 'Aug 10–14', 'Aug 15–19', 'Aug 20–24', 'Aug 25–28', 'Aug 29–31'],
            'financialBridge' => [
                ['label' => 'Gross Sales', 'value' => $this->money($finance['grossSales']), 'negative' => false],
                ['label' => 'Seller-funded Discounts', 'value' => '−'.$this->money($finance['sellerDiscounts']), 'negative' => true],
                ['label' => 'Refunds', 'value' => '−'.$this->money($finance['refunds']), 'negative' => true],
                ['label' => 'Platform Commission (10%)', 'value' => '−'.$this->money($finance['netCommission']), 'negative' => true],
            ],
            'insights' => [
                ['title' => 'Best seller', 'value' => 'Classic Linen Shirt · 38 sold', 'detail' => 'Your top product by unit volume this month.', 'icon' => 'shirt'],
                ['title' => 'Strongest period', 'value' => 'Aug 25–31 · ₱41,800', 'detail' => 'This period captured the highest sales.', 'icon' => 'trending-up'],
                ['title' => 'Refund rate', 'value' => '1.0% · Stable', 'detail' => 'Full refunds reverse the related platform commission.', 'icon' => 'rotate-ccw'],
            ],
            'products' => [
                ['name' => 'Classic Linen Shirt', 'units' => 38, 'revenue' => '₱52,440.00', 'share' => 40.8, 'icon' => 'shirt'],
                ['name' => 'Canvas Tote Bag', 'units' => 29, 'revenue' => '₱34,018.00', 'share' => 26.5, 'icon' => 'shopping-bag'],
                ['name' => 'Everyday Sneakers', 'units' => 24, 'revenue' => '₱25,320.00', 'share' => 19.7, 'icon' => 'footprints'],
            ],
        ]);
    }

    public function financialReport(Request $request): View
    {
        $finance = $this->financeSnapshot();

        $from = (string) $request->query('from', '2026-08-01');
        $to = (string) $request->query('to', '2026-08-31');

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = '2026-08-01';
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = '2026-08-31';
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $commissionPercent = $finance['totalDeductions'] > 0 ? ($finance['netCommission'] / $finance['totalDeductions']) * 100 : 0;
        $discountPercent = $finance['totalDeductions'] > 0 ? ($finance['sellerDiscounts'] / $finance['totalDeductions']) * 100 : 0;
        $refundPercent = $finance['totalDeductions'] > 0 ? ($finance['refunds'] / $finance['totalDeductions']) * 100 : 0;

        return view('seller.Reports.financial', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'dateRange' => ['from' => $from, 'to' => $to],
            'summary' => [
                ['label' => 'Gross Sales', 'value' => $this->money($finance['grossSales']), 'note' => 'Before deductions'],
                ['label' => 'Commissionable Sales', 'value' => $this->money($finance['netCommissionableSales']), 'note' => 'After seller discounts and finalized refunds'],
                ['label' => 'Platform Commission', 'value' => $this->money($finance['netCommission']), 'note' => '10% after reversal'],
                ['label' => 'Net Earnings', 'value' => $this->money($finance['netEarnings']), 'note' => 'Seller earnings after deductions'],
            ],
            'currentStatement' => [
                ['label' => 'Gross Sales', 'value' => $this->money($finance['grossSales'])],
                ['label' => 'Seller-funded Discounts', 'value' => '−'.$this->money($finance['sellerDiscounts'])],
                ['label' => 'Refunds', 'value' => '−'.$this->money($finance['refunds'])],
                ['label' => 'Platform Commission (10%)', 'value' => '−'.$this->money($finance['netCommission'])],
            ],
            'statementNetEarnings' => $this->money($finance['netEarnings']),
            'statementTotalDeductions' => $this->money($finance['totalDeductions']),
            'deductionRate' => number_format(($finance['totalDeductions'] / $finance['grossSales']) * 100, 1).'%',
            'deductions' => [
                ['label' => 'Platform Commission', 'value' => $this->money($finance['netCommission']), 'percent' => round($commissionPercent, 1), 'tone' => 'brown'],
                ['label' => 'Seller Discounts', 'value' => $this->money($finance['sellerDiscounts']), 'percent' => round($discountPercent, 1), 'tone' => 'gold'],
                ['label' => 'Refunds', 'value' => $this->money($finance['refunds']), 'percent' => round($refundPercent, 1), 'tone' => 'olive'],
            ],
            'rows' => [
                ['period' => 'August 2026', 'gross' => '₱128,450.00', 'discounts' => '₱4,430.00', 'refunds' => '₱1,299.00', 'commission' => '₱12,272.10', 'net' => '₱110,448.90', 'status' => 'Processing'],
                ['period' => 'July 2026', 'gross' => '₱112,800.00', 'discounts' => '₱3,820.00', 'refunds' => '₱0', 'commission' => '₱10,898.00', 'net' => '₱98,082.00', 'status' => 'Paid'],
                ['period' => 'June 2026', 'gross' => '₱106,240.00', 'discounts' => '₱3,140.00', 'refunds' => '₱899.00', 'commission' => '₱10,220.10', 'net' => '₱91,980.90', 'status' => 'Paid'],
            ],
        ]);
    }
}

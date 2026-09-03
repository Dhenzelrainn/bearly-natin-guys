<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SellerController extends Controller
{
    private function seller(): array
    {
        return [
            'name' => 'Bea Rivera',
            'first_name' => 'Bea',
            'initials' => 'BR',
            'email' => 'bea@juansclothing.test',
            'store' => "Juan's Clothing Shop",
        ];
    }

    private function notifications(): array
    {
        return [
            ['title' => '8 new orders need review', 'time' => '5 minutes ago', 'type' => 'warning'],
            ['title' => 'Classic Linen Shirt is low in stock', 'time' => '42 minutes ago', 'type' => 'danger'],
            ['title' => 'Order #BR-1047 is ready for pickup', 'time' => '1 hour ago', 'type' => 'success'],
        ];
    }

    public function dashboard(): View
    {
        return view('seller.dashboard', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'dashboard' => [
                'updated_at' => '10:42 AM',
                'action_count' => 18,
                'pickup_time' => '3:00 PM',
                'actions' => [
                    ['count' => 8, 'label' => 'new orders', 'detail' => 'Review within 2 hours', 'action' => 'Review', 'icon' => 'clipboard-list', 'tone' => 'amber', 'target' => route('seller.orders.new')],
                    ['count' => 5, 'label' => 'orders to prepare', 'detail' => 'Pack before 1:30 PM', 'action' => 'Prepare', 'icon' => 'package', 'tone' => 'olive', 'target' => route('seller.orders.prepare')],
                    ['count' => 3, 'label' => 'waybills to print', 'detail' => "For today's pickup", 'action' => 'Print', 'icon' => 'printer', 'tone' => 'brown', 'target' => route('seller.fulfillment.waybills')],
                    ['count' => 2, 'label' => 'pickup requests', 'detail' => 'Awaiting logistics approval', 'action' => 'View requests', 'icon' => 'truck', 'tone' => 'green', 'target' => route('seller.fulfillment.pickups')],
                ],
            ],
            'stats' => [
                ['label' => 'Gross Sales', 'value' => '₱128,450', 'change' => '+12.5% this month', 'icon' => 'philippine-peso', 'tone' => 'gold'],
                ['label' => 'Net Revenue', 'value' => '₱115,605', 'change' => 'After 10% commission', 'icon' => 'package-check', 'tone' => 'olive'],
                ['label' => 'Average Order Value', 'value' => '₱1,420', 'change' => 'Per completed order', 'icon' => 'shopping-bag', 'tone' => 'brown'],
                ['label' => 'Fulfillment Rate', 'value' => '96.8%', 'change' => '+1.4% vs last month', 'icon' => 'circle-check-big', 'tone' => 'olive'],
            ],
            'sales' => [9800, 13400, 13800, 18100, 22800, 15700, 24100],
            'topProducts' => [
                ['name' => 'Classic Linen Shirt', 'sku' => 'CLS-LINEN-SHIRT', 'sold' => 42, 'revenue' => '₱54,600', 'percent' => 100, 'icon' => 'shirt'],
                ['name' => 'Canvas Tote Bag', 'sku' => 'CNV-TOTE-BAG', 'sold' => 31, 'revenue' => '₱27,869', 'percent' => 74, 'icon' => 'shopping-bag'],
                ['name' => 'Everyday Sneakers', 'sku' => 'EV-SNKRS-WHT', 'sold' => 18, 'revenue' => '₱23,382', 'percent' => 43, 'icon' => 'footprints'],
            ],
            'payoutSummary' => [
                ['label' => 'Gross sales', 'value' => '₱128,450'],
                ['label' => 'Platform commission (10%)', 'value' => '−₱12,845'],
                ['label' => 'Net revenue', 'value' => '₱115,605'],
                ['label' => 'Next payout', 'value' => 'September 5'],
            ],
            'pickupSummary' => [
                'time' => '3:00 PM',
                'date' => 'Today · Laguna route',
                'ready' => 5,
                'not_ready' => 2,
            ],
        ]);
    }

    public function workspace(Request $request): View
    {
        $key = (string) $request->route('workspace');
        $pages = $this->workspacePages();
        abort_unless(isset($pages[$key]), 404);

        return view('seller.workspace', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'page' => $pages[$key],
        ]);
    }

    public function account(): View
    {
        return view('seller.account', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'account' => [
                'full_name' => 'Bea Rivera',
                'email' => 'bea@juansclothing.test',
                'phone' => '+63 917 123 4567',
                'birthday' => 'May 18, 1998',
                'sex' => 'Female',
                'address' => 'Blk 12 Lot 8, Barangay Balibago, Santa Rosa City, Laguna',
                'status' => 'Active',
                'verification' => 'Verified',
                'member_since' => 'August 20, 2026',
                'last_updated' => 'August 31, 2026 · 4:18 PM',
                'last_sign_in' => 'Today · 10:42 AM',
            ],
        ]);
    }

    public function security(): View
    {
        return view('seller.security', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'security' => [
                'two_factor_enabled' => false,
                'last_password_change' => 'August 20, 2026',
                'sessions' => [
                    ['device' => 'Windows · Microsoft Edge', 'location' => 'Santa Rosa, Laguna', 'activity' => 'Active now', 'current' => true],
                    ['device' => 'Android · Chrome', 'location' => 'Santa Rosa, Laguna', 'activity' => 'August 29, 2026 · 8:16 PM', 'current' => false],
                ],
                'activity' => [
                    ['event' => 'Successful sign-in', 'detail' => 'Windows · Microsoft Edge', 'time' => 'Today · 10:42 AM', 'tone' => 'success'],
                    ['event' => 'Password changed', 'detail' => 'Account password updated', 'time' => 'August 20, 2026', 'tone' => 'neutral'],
                    ['event' => 'New device verified', 'detail' => 'Android · Chrome', 'time' => 'August 18, 2026', 'tone' => 'warning'],
                ],
            ],
        ]);
    }

    public function notificationSettings(): View
    {
        return view('seller.notifications', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'preferences' => [
                ['key' => 'new_orders', 'title' => 'New orders', 'description' => 'Notify me when a buyer places a new order.', 'icon' => 'clipboard-list', 'in_app' => true, 'email' => true],
                ['key' => 'preparation_deadlines', 'title' => 'Preparation deadlines', 'description' => 'Remind me when an order is nearing its packing deadline.', 'icon' => 'clock-3', 'in_app' => true, 'email' => true],
                ['key' => 'inventory_alerts', 'title' => 'Inventory alerts', 'description' => 'Notify me when available stock reaches its threshold.', 'icon' => 'triangle-alert', 'in_app' => true, 'email' => true],
                ['key' => 'pickup_updates', 'title' => 'Pickup request updates', 'description' => 'Receive logistics approval and rider handover updates.', 'icon' => 'truck', 'in_app' => true, 'email' => true],
                ['key' => 'shipment_updates', 'title' => 'Shipment exceptions', 'description' => 'Notify me about failed, delayed, or returned deliveries.', 'icon' => 'package-search', 'in_app' => true, 'email' => true],
                ['key' => 'buyer_messages', 'title' => 'Buyer messages', 'description' => 'Notify me when a buyer sends a new message.', 'icon' => 'message-circle-more', 'in_app' => true, 'email' => false],
                ['key' => 'account_security', 'title' => 'Account and security alerts', 'description' => 'Critical sign-in and account status notifications cannot be disabled in-app.', 'icon' => 'shield-alert', 'in_app' => true, 'email' => true, 'required' => true],
            ],
        ]);
    }

    private function workspacePages(): array
    {
        $orders = [
            ['#BR-1058', 'Maria Santos', '2 items', 'GCash · Paid', '₱1,850', 'Today · 11:30 AM', 'New'],
            ['#BR-1057', 'Carlo Reyes', '1 item', 'Cash on Delivery', '₱899', 'Today · 1:30 PM', 'To Prepare'],
            ['#BR-1056', 'Ana Cruz', '3 items', 'Maya · Paid', '₱2,450', 'Today · 3:00 PM', 'Ready'],
        ];

        return [
            'orders-new' => ['title' => 'New Orders', 'subtitle' => 'Review newly placed orders before they enter preparation.', 'kpis' => [['Awaiting review','8'],['Paid orders','6'],['Cash on delivery','2'],['Oldest waiting','42 min']], 'columns' => ['Order','Customer','Items','Payment','Total','Placed','Status'], 'rows' => [$orders[0], ['#BR-1059','Jamie Lim','1 item','GCash · Paid','₱1,299','Today · 10:47 AM','New']], 'action' => 'Review Order'],
            'orders-prepare' => ['title' => 'To Prepare', 'subtitle' => 'Pack confirmed orders accurately before their deadline.', 'kpis' => [['To prepare','5'],['Due within 1 hour','2'],['Packed today','11'],['Late','0']], 'columns' => ['Order','Customer','Items','Payment','Total','Deadline','Status'], 'rows' => [$orders[1], ['#BR-1060','Sofia Mendoza','2 items','GCash · Paid','₱2,120','Today · 2:00 PM','To Prepare']], 'action' => 'Open Packing'],
            'orders-ready' => ['title' => 'Ready for Pickup', 'subtitle' => 'Review packed parcels and confirm handover when the assigned pickup rider arrives.', 'kpis' => [['Ready orders','3'],['Packages','4'],['Approved pickups','3'],['Missing label','0']], 'columns' => ['Order','Customer','Packages','Payment','Total','Pickup Request','Status'], 'rows' => [[$orders[2][0],$orders[2][1],'2 packages',$orders[2][3],$orders[2][4],'Approved · 3:00 PM','Awaiting Handover'], ['#BR-1055','Miguel Garcia','1 package','Cash on Delivery','₱1,299','Awaiting approval','Ready']], 'action' => 'Confirm Handover'],
            'orders-history' => ['title' => 'Order History', 'subtitle' => 'Review completed and cancelled orders without mixing them with active work.', 'kpis' => [['Completed this month','86'],['Cancelled','3'],['Returned','1'],['Completion rate','96.8%']], 'columns' => ['Order','Customer','Items','Payment','Total','Completed','Result'], 'rows' => [['#BR-1045','Miguel Tan','1 item','GCash · Paid','₱1,299','Aug 30, 2026','Completed'], ['#BR-1038','Ryan Cruz','2 items','Refunded','₱1,780','Aug 28, 2026','Cancelled']], 'action' => 'View Details'],
            'fulfillment-waybills' => ['title' => 'Waybills', 'subtitle' => 'Generate and print shipping labels for packed orders.', 'kpis' => [['Ready to print','3'],['Printed today','12'],['Reprint needed','1'],['Pickup cutoff','2:30 PM']], 'columns' => ['Order','Courier','Packages','Destination','Label Status','Pickup','Status'], 'rows' => [['#BR-1057','Bearly Logistics','1','Santa Rosa, Laguna','Ready to print','Today · 3:00 PM','Pending'], ['#BR-1056','Bearly Logistics','2','Calamba, Laguna','Printed','Today · 3:00 PM','Ready']], 'action' => 'Print Waybill'],
            'fulfillment-pickups' => ['title' => 'Pickup Requests', 'subtitle' => 'Submit labeled and packed parcels for logistics approval and pickup assignment.', 'kpis' => [['Ready to request','2'],['Approved today','5'],['Awaiting approval','1'],['Next approved pickup','3:00 PM']], 'columns' => ['Request','Orders','Packages','Logistics Provider','Preferred Time','Pickup Location','Status'], 'rows' => [['PU-0901-01','3 orders','4','Bearly Logistics','Today · 3:00 PM','Juan’s Clothing Shop','Approved'], ['Draft request','2 orders','2','Bearly Logistics','Choose preferred time','Store address','Draft']], 'action' => 'View Request'],
            'fulfillment-tracking' => ['title' => 'Shipment Tracking', 'subtitle' => 'Monitor parcels after rider handover through sorting and final delivery.', 'kpis' => [['At sorting center','3'],['Assigned to rider','2'],['Out for delivery','2'],['Delivered today','9']], 'columns' => ['Tracking No.','Order','Logistics Provider','Destination','Latest Update','Updated','Status'], 'rows' => [['BRLY-784201','#BR-1054','Bearly Logistics','Biñan, Laguna','Parcel received at sorting center','Today · 8:40 AM','At Sorting Center'], ['BRLY-784188','#BR-1052','Bearly Logistics','Cabuyao, Laguna','Assigned to delivery rider','Today · 9:15 AM','Assigned to Rider'], ['BRLY-784176','#BR-1051','Bearly Logistics','Calamba, Laguna','Rider is delivering the parcel','Today · 10:05 AM','Out for Delivery']], 'action' => 'Track'],
            'products-pricing' => ['title' => 'Pricing & Promotions', 'subtitle' => 'Manage product prices, discounts, and voucher eligibility.', 'kpis' => [['Active products','24'],['Discounted','6'],['Voucher eligible','12'],['Ending soon','2']], 'columns' => ['Product','SKU','Regular Price','Sale Price','Discount','Voucher','Status'], 'rows' => [['Classic Linen Shirt','CLS-LINEN-SHIRT','₱1,299','₱1,169','10%','Eligible','Active'], ['Canvas Tote Bag','CNV-TOTE-BAG','₱899','₱899','—','Not eligible','Regular']], 'action' => 'Edit Pricing'],
            'store-appearance' => ['title' => 'Store Appearance', 'subtitle' => 'Manage the buyer-facing profile photo, cover, and storefront description.', 'kpis' => [['Profile photo','Added'],['Cover photo','Missing'],['Description','Added'],['Preview','Available']], 'columns' => ['Store Element','Current State','Recommended Size','Visibility','Last Updated','Owner','Status'], 'rows' => [['Profile photo','Uploaded','1:1 square','Public','Aug 31, 2026','Bea Rivera','Complete'], ['Cover photo','Not uploaded','16:5 landscape','Public','—','Bea Rivera','Required']], 'action' => 'Update'],
            'store-publication' => ['title' => 'Publication Settings', 'subtitle' => 'Control whether the completed storefront is visible to buyers.', 'kpis' => [['Current status','Draft'],['Required fields','4 of 5'],['Pending review','0'],['Buyer visibility','Hidden']], 'columns' => ['Requirement','Current Value','Required','Review','Visibility','Updated','Status'], 'rows' => [['Business information','Verified','Yes','Approved','Private','Registration','Complete'], ['Cover photo','Missing','Yes','Not submitted','Public','—','Required']], 'action' => 'Review Setting'],
            'reports-sales' => ['title' => 'Sales Report', 'subtitle' => 'Analyze sales and product performance within a selected period.', 'kpis' => [['Gross sales','₱128,450'],['Orders','91'],['Average order','₱1,420'],['Units sold','146']], 'columns' => ['Period','Orders','Units Sold','Gross Sales','Discounts','Returns','Net Sales'], 'rows' => [['Aug 25–31','32','51','₱48,900','₱2,450','₱0','₱46,450'], ['Aug 18–24','28','44','₱39,600','₱1,980','₱899','₱36,721']], 'action' => 'Export'],
            'reports-financial' => ['title' => 'Financial Report', 'subtitle' => 'Review gross sales, commission, refunds, and net revenue.', 'kpis' => [['Gross sales','₱128,450'],['Commission','₱12,845'],['Refunds','₱1,299'],['Net revenue','₱114,306']], 'columns' => ['Period','Gross Sales','Commission','Discounts','Refunds','Net Revenue','Payout Status'], 'rows' => [['August 2026','₱128,450','₱12,845','₱4,430','₱1,299','₱109,876','Processing'], ['July 2026','₱112,800','₱11,280','₱3,820','₱0','₱97,700','Paid']], 'action' => 'Export'],
            'support-messages' => ['title' => 'Messages', 'subtitle' => 'Respond to buyer questions about products and active orders.', 'kpis' => [['Unread','4'],['Open conversations','7'],['Replied today','12'],['Average response','18 min']], 'columns' => ['Customer','Regarding','Last Message','Received','Assigned To','Priority','Status'], 'rows' => [['Maria Santos','#BR-1058','Can I change the size?','8 min ago','Bea Rivera','Normal','Unread'], ['Carlo Reyes','Canvas Tote Bag','Is this available in black?','32 min ago','Bea Rivera','Normal','Open']], 'action' => 'Open Chat'],
            'support-feedback' => ['title' => 'Customer Feedback', 'subtitle' => 'Review ratings and reply to customer feedback after delivery.', 'kpis' => [['Average rating','4.7'],['New feedback','3'],['Replied','18'],['Total reviews','126']], 'columns' => ['Customer','Order','Product','Rating','Comment','Received','Status'], 'rows' => [['Maria Santos','#BR-1048','Classic Linen Shirt','5.0','Great quality and fit.','Today','New'], ['Carlo Reyes','#BR-1047','Canvas Tote Bag','4.0','Good material.','Yesterday','Replied']], 'action' => 'Reply'],
            'settings-account' => ['title' => 'Account', 'subtitle' => 'Manage the seller contact information used for account communication.', 'kpis' => [['Account status','Active'],['Email','Verified'],['Phone','Verified'],['Role','Seller']], 'columns' => ['Field','Current Value','Visibility','Verification','Last Updated','Managed By','Status'], 'rows' => [['Full name','Bea Rivera','Private','Verified','Registration','Seller','Locked'], ['Email','bea@juansclothing.test','Private','Verified','Aug 31, 2026','Seller','Active']], 'action' => 'Edit'],
            'settings-security' => ['title' => 'Security', 'subtitle' => 'Protect account access and review recent sign-in activity.', 'kpis' => [['Password','Set'],['Two-step verification','Off'],['Active sessions','1'],['Security alerts','0']], 'columns' => ['Security Item','Current State','Recommendation','Last Updated','Device','Location','Status'], 'rows' => [['Password','Set','Change regularly','Aug 20, 2026','—','—','Protected'], ['Current session','Active','Recognized device','Now','Windows · Edge','Laguna','Active']], 'action' => 'Manage'],
            'settings-notifications' => ['title' => 'Notifications', 'subtitle' => 'Choose which seller events are shown in-app or sent by email.', 'kpis' => [['Order alerts','On'],['Stock alerts','On'],['Pickup alerts','On'],['Marketing','Off']], 'columns' => ['Notification','In-app','Email','Trigger','Priority','Last Sent','Status'], 'rows' => [['New order','Enabled','Enabled','Order placed','High','5 min ago','Active'], ['Low stock','Enabled','Enabled','Below threshold','Medium','42 min ago','Active']], 'action' => 'Configure'],
        ];
    }

    public function inventory(): View
    {
        return view('seller.inventory', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'inventoryTotal' => 48,
            'inventorySummary' => [
                ['label' => 'Total SKUs', 'value' => '48', 'note' => '24 active products', 'icon' => 'box', 'tone' => 'neutral'],
                ['label' => 'Available Units', 'value' => '1,246', 'note' => 'Across all variations', 'icon' => 'package-check', 'tone' => 'success'],
                ['label' => 'Low Stock', 'value' => '5', 'note' => 'Below threshold', 'icon' => 'triangle-alert', 'tone' => 'warning'],
                ['label' => 'Out of Stock', 'value' => '2', 'note' => 'Needs restocking', 'icon' => 'circle-x', 'tone' => 'danger'],
            ],
            'inventoryAttention' => ['total' => 7, 'low' => 5, 'out' => 2],
            'inventoryTabs' => [
                ['key' => 'all', 'label' => 'All Inventory', 'count' => 48],
                ['key' => 'low-stock', 'label' => 'Low Stock', 'count' => 5],
                ['key' => 'out-of-stock', 'label' => 'Out of Stock', 'count' => 2],
                ['key' => 'archived', 'label' => 'Archived', 'count' => null],
            ],
            'inventoryCategories' => ['Fashion and Apparel'],
            'inventoryItems' => [
                ['id' => 'inv-1', 'parent' => 'classic-linen-shirt', 'variation_row' => false, 'product' => 'Classic Linen Shirt', 'sku' => 'CLS-OLV-M', 'variation' => 'Olive / Medium', 'category' => 'Fashion and Apparel', 'icon' => 'shirt', 'on_hand' => 12, 'reserved' => 3, 'available' => 9, 'threshold' => 5, 'status' => 'In Stock', 'status_key' => 'in-stock'],
                ['id' => 'inv-2', 'parent' => 'classic-linen-shirt', 'variation_row' => true, 'product' => 'Classic Linen Shirt', 'sku' => 'CLS-CRM-L', 'variation' => 'Cream / Large', 'category' => 'Fashion and Apparel', 'icon' => 'shirt', 'on_hand' => 6, 'reserved' => 2, 'available' => 4, 'threshold' => 5, 'status' => 'Low Stock', 'status_key' => 'low-stock'],
                ['id' => 'inv-3', 'parent' => 'canvas-tote-bag', 'variation_row' => false, 'product' => 'Canvas Tote Bag', 'sku' => 'CTB-NAT-OS', 'variation' => 'Natural / One Size', 'category' => 'Fashion and Apparel', 'icon' => 'shopping-bag', 'on_hand' => 7, 'reserved' => 5, 'available' => 2, 'threshold' => 4, 'status' => 'Low Stock', 'status_key' => 'low-stock'],
                ['id' => 'inv-4', 'parent' => 'everyday-sneakers', 'variation_row' => false, 'product' => 'Everyday Sneakers', 'sku' => 'ES-WHT-38', 'variation' => 'White / Size 38', 'category' => 'Fashion and Apparel', 'icon' => 'footprints', 'on_hand' => 0, 'reserved' => 0, 'available' => 0, 'threshold' => 3, 'status' => 'Out of Stock', 'status_key' => 'out-of-stock'],
                ['id' => 'inv-5', 'parent' => 'everyday-sneakers', 'variation_row' => true, 'product' => 'Everyday Sneakers', 'sku' => 'ES-BLK-40', 'variation' => 'Black / Size 40', 'category' => 'Fashion and Apparel', 'icon' => 'footprints', 'on_hand' => 18, 'reserved' => 4, 'available' => 14, 'threshold' => 3, 'status' => 'In Stock', 'status_key' => 'in-stock'],
            ],
            'stockMovements' => [
                ['date' => 'Aug 31, 2026 · 10:30 AM', 'type' => 'Order Deduction', 'tone' => 'order', 'product' => 'Classic Linen Shirt', 'sku' => 'CLS-OLV-M', 'variation' => 'Olive / Medium', 'reference' => 'Order #BR-1058', 'quantity' => '−2', 'direction' => 'negative', 'balance' => 12, 'actor' => 'System'],
                ['date' => 'Aug 31, 2026 · 9:15 AM', 'type' => 'Reservation', 'tone' => 'reservation', 'product' => 'Canvas Tote Bag', 'sku' => 'CTB-NAT-OS', 'variation' => 'Natural / One Size', 'reference' => 'Order #BR-1057', 'quantity' => '−1', 'direction' => 'negative', 'balance' => 7, 'actor' => 'System'],
                ['date' => 'Aug 30, 2026 · 4:42 PM', 'type' => 'Manual Addition', 'tone' => 'addition', 'product' => 'Everyday Sneakers', 'sku' => 'ES-BLK-40', 'variation' => 'Black / Size 40', 'reference' => 'Stock count', 'quantity' => '+8', 'direction' => 'positive', 'balance' => 18, 'actor' => 'Bea Rivera'],
                ['date' => 'Aug 30, 2026 · 2:10 PM', 'type' => 'Damage Adjustment', 'tone' => 'damage', 'product' => 'Classic Linen Shirt', 'sku' => 'CLS-CRM-L', 'variation' => 'Cream / Large', 'reference' => 'Damaged item', 'quantity' => '−1', 'direction' => 'negative', 'balance' => 6, 'actor' => 'Bea Rivera'],
            ],
        ]);
    }


    public function orders(): View
    {
        return view('seller.orders', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'orderQueue' => [
                ['label' => 'New orders', 'count' => 8, 'note' => 'Review within 2 hrs', 'icon' => 'clipboard-list', 'tone' => 'gold'],
                ['label' => 'To prepare', 'count' => 5, 'note' => 'Pack before 1:30 PM', 'icon' => 'package', 'tone' => 'olive'],
                ['label' => 'Waybills to print', 'count' => 3, 'note' => "For today's pickup", 'icon' => 'printer', 'tone' => 'blue'],
                ['label' => 'Ready for pickup', 'count' => 2, 'note' => 'Courier pickup at 3:00 PM', 'icon' => 'truck', 'tone' => 'brown'],
            ],
            'orderTabs' => [
                ['key' => 'all', 'label' => 'All Orders', 'count' => 23],
                ['key' => 'new', 'label' => 'New', 'count' => 8],
                ['key' => 'to-prepare', 'label' => 'To Prepare', 'count' => 5],
                ['key' => 'ready-pickup', 'label' => 'Ready for Pickup', 'count' => 3],
                ['key' => 'in-transit', 'label' => 'In Transit', 'count' => 4],
                ['key' => 'completed', 'label' => 'Completed', 'count' => null],
                ['key' => 'cancelled', 'label' => 'Cancelled', 'count' => null],
            ],
            'orders' => [
                ['id' => '#BR-1058', 'customer' => 'Maria Santos', 'items' => '2 items', 'payment' => 'GCash · Paid', 'payment_key' => 'paid', 'total' => '₱1,850', 'deadline' => 'Today · 11:30 AM', 'date_key' => 'today', 'urgent' => true, 'status' => 'New', 'status_key' => 'new', 'tone' => 'new', 'action' => 'Review'],
                ['id' => '#BR-1057', 'customer' => 'Carlo Reyes', 'items' => '1 item', 'payment' => 'Cash on Delivery', 'payment_key' => 'cod', 'total' => '₱899', 'deadline' => 'Today · 1:30 PM', 'date_key' => 'today', 'urgent' => true, 'status' => 'To Prepare', 'status_key' => 'to-prepare', 'tone' => 'prepare', 'action' => 'Prepare'],
                ['id' => '#BR-1056', 'customer' => 'Ana Cruz', 'items' => '3 items', 'payment' => 'Maya · Paid', 'payment_key' => 'paid', 'total' => '₱2,450', 'deadline' => 'Today · 3:00 PM', 'date_key' => 'today', 'urgent' => false, 'status' => 'Waybill Ready', 'status_key' => 'to-prepare', 'tone' => 'waybill', 'action' => 'Print Waybill'],
                ['id' => '#BR-1055', 'customer' => 'Miguel Garcia', 'items' => '1 item', 'payment' => 'Cash on Delivery', 'payment_key' => 'cod', 'total' => '₱1,299', 'deadline' => 'Today · 3:00 PM', 'date_key' => 'today', 'urgent' => false, 'status' => 'Ready for Pickup', 'status_key' => 'ready-pickup', 'tone' => 'pickup', 'action' => 'Schedule Pickup'],
                ['id' => '#BR-1054', 'customer' => 'Jamie Lim', 'items' => '2 items', 'payment' => 'GCash · Paid', 'payment_key' => 'paid', 'total' => '₱1,720', 'deadline' => 'September 1', 'date_key' => 'upcoming', 'urgent' => false, 'status' => 'In Transit', 'status_key' => 'in-transit', 'tone' => 'transit', 'action' => 'Track'],
                ['id' => '#BR-1053', 'customer' => 'Sofia Mendoza', 'items' => '1 item', 'payment' => 'GCash · Paid', 'payment_key' => 'paid', 'total' => '₱1,050', 'deadline' => 'Delivered Aug 30', 'date_key' => 'upcoming', 'urgent' => false, 'status' => 'Completed', 'status_key' => 'completed', 'tone' => 'completed', 'action' => 'View'],
            ],
        ]);
    }

    public function store(Request $request): View
    {
        $store = array_merge([
            'name' => "Juan's Clothing Shop",
            'location' => 'Santa Rosa City, Laguna',
            'category' => 'Fashion and Apparel',
            'description' => '',
            'email' => 'juan@example.com',
            'phone' => '+63 917 123 4567',
            'profile_photo' => null,
            'cover_photo' => null,
            'published' => false,
        ], $request->session()->get('seller.store', []));

        $completed = 2 + (int) filled($store['profile_photo']) + (int) filled($store['cover_photo']) + (int) filled($store['description']);

        return view('seller.store', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'store' => $store,
            'completion' => $completed * 20,
        ]);
    }

    public function saveStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:500'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'cover_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'intent' => ['required', 'in:draft,publish'],
        ]);

        $store = array_merge($request->session()->get('seller.store', []), [
            'description' => $validated['description'] ?? '',
            'email' => $validated['email'],
            'phone' => $validated['phone'],
        ]);

        foreach (['profile_photo', 'cover_photo'] as $photo) {
            if ($request->hasFile($photo)) {
                $store[$photo] = $request->file($photo)->store('seller-store', 'public');
            }
        }

        $complete = filled($store['description'] ?? null)
            && filled($store['profile_photo'] ?? null)
            && filled($store['cover_photo'] ?? null);
        $store['published'] = $validated['intent'] === 'publish' && $complete;
        $request->session()->put('seller.store', $store);

        $message = $store['published'] ? 'Your store profile is now published.' : 'Your store profile was saved as a draft.';
        return redirect()->route('seller.store')->with('success', $message);
    }

    private function productCategories(): array
    {
        return [
            'Fashion and Apparel',
            'Jewelry and Watches',
            'Electronics and Gadgets',
            'Home and Furniture',
            'Beauty and Personal Care',
            'Food and Gourmet',
            'Sports and Outdoors',
            'Books and Stationery',
            'Automotive and Parts',
            'Toys and Hobbies',
            'Other',
        ];
    }

    private function normalizeProduct(array $product): array
    {
        return array_merge([
            'id' => '',
            'name' => '',
            'category' => '',
            'description' => '',
            'sku' => '',
            'price' => 0,
            'discount_percent' => 0,
            'voucher_eligible' => false,
            'stock' => 0,
            'low_stock_threshold' => 5,
            'option_one_name' => '',
            'option_one_values' => '',
            'option_two_name' => '',
            'option_two_values' => '',
            'status' => 'Draft',
            'previous_status' => 'Active',
            'image' => null,
            'gallery_images' => [],
        ], $product);
    }

    private function validateProduct(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sku' => ['nullable', 'string', 'max:60'],
            'price' => ['required', 'numeric', 'min:0'],
            'discount_percent' => ['nullable', 'integer', 'min:0', 'max:90'],
            'voucher_eligible' => ['nullable', 'boolean'],
            'stock' => ['required', 'integer', 'min:0'],
            'low_stock_threshold' => ['required', 'integer', 'min:0'],
            'option_one_name' => ['nullable', 'string', 'max:40'],
            'option_one_values' => ['nullable', 'string', 'max:250'],
            'option_two_name' => ['nullable', 'string', 'max:40'],
            'option_two_values' => ['nullable', 'string', 'max:250'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'gallery_images' => ['nullable', 'array', 'max:4'],
            'gallery_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'intent' => ['required', 'in:draft,publish'],
        ]);
    }

    public function products(Request $request): View
    {
        $products = collect($request->session()->get('seller.products', []))
            ->map(fn (array $product) => $this->normalizeProduct($product));

        return view('seller.products', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'products' => $products,
            'categories' => $products->pluck('category')->filter()->unique()->sort()->values(),
            'statuses' => $products->pluck('status')->filter()->unique()->sort()->values(),
            'counts' => [
                'all' => $products->count(),
                'active' => $products->where('status', 'Active')->count(),
                'low' => $products->filter(fn (array $product) => $product['status'] === 'Active'
                    && (int) $product['stock'] <= (int) $product['low_stock_threshold'])->count(),
                'archived' => $products->where('status', 'Archived')->count(),
            ],
        ]);
    }

    public function createProduct(): View
    {
        return view('seller.product-form', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'categories' => $this->productCategories(),
            'product' => $this->normalizeProduct([]),
            'mode' => 'create',
        ]);
    }

    public function addProduct(Request $request): RedirectResponse
    {
        $validated = $this->validateProduct($request);
        $products = $request->session()->get('seller.products', []);
        $id = (string) ((int) collect($products)->max(fn ($item) => (int) ($item['id'] ?? 0)) + 1);

        $galleryImages = [];
        foreach ($request->file('gallery_images', []) as $image) {
            $galleryImages[] = $image->store('seller-products', 'public');
        }

        $products[] = $this->normalizeProduct([
            'id' => $id,
            'name' => $validated['name'],
            'category' => $validated['category'],
            'description' => $validated['description'] ?? '',
            'sku' => ($validated['sku'] ?? '') ?: 'BR-'.str_pad($id, 4, '0', STR_PAD_LEFT),
            'price' => (float) $validated['price'],
            'discount_percent' => (int) ($validated['discount_percent'] ?? 0),
            'voucher_eligible' => (bool) ($validated['voucher_eligible'] ?? false),
            'stock' => (int) $validated['stock'],
            'low_stock_threshold' => (int) $validated['low_stock_threshold'],
            'option_one_name' => $validated['option_one_name'] ?? '',
            'option_one_values' => $validated['option_one_values'] ?? '',
            'option_two_name' => $validated['option_two_name'] ?? '',
            'option_two_values' => $validated['option_two_values'] ?? '',
            'status' => $validated['intent'] === 'publish' ? 'Active' : 'Draft',
            'previous_status' => $validated['intent'] === 'publish' ? 'Active' : 'Draft',
            'image' => $request->hasFile('image')
                ? $request->file('image')->store('seller-products', 'public')
                : null,
            'gallery_images' => $galleryImages,
        ]);

        $request->session()->put('seller.products', $products);

        return redirect()->route('seller.products')->with(
            'success',
            $validated['intent'] === 'publish' ? 'Product published successfully.' : 'Product saved as a draft.'
        );
    }

    public function editProduct(Request $request, string $product): View
    {
        $item = collect($request->session()->get('seller.products', []))
            ->first(fn (array $item) => (string) ($item['id'] ?? '') === $product);

        abort_if(!$item, 404);

        return view('seller.product-form', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'categories' => $this->productCategories(),
            'product' => $this->normalizeProduct($item),
            'mode' => 'edit',
        ]);
    }

    public function updateProduct(Request $request, string $product): RedirectResponse
    {
        $validated = $this->validateProduct($request);
        $found = false;

        $products = collect($request->session()->get('seller.products', []))
            ->map(function (array $item) use ($request, $validated, $product, &$found) {
                if ((string) ($item['id'] ?? '') !== $product) {
                    return $item;
                }

                $found = true;
                $item = $this->normalizeProduct($item);
                $newGalleryImages = $request->file('gallery_images', []);
                $galleryImages = $item['gallery_images'];
                if (count($newGalleryImages) > 0) {
                    $galleryImages = [];
                    foreach ($newGalleryImages as $image) {
                        $galleryImages[] = $image->store('seller-products', 'public');
                    }
                }

                return array_merge($item, [
                    'name' => $validated['name'],
                    'category' => $validated['category'],
                    'description' => $validated['description'] ?? '',
                    'sku' => ($validated['sku'] ?? '') ?: $item['sku'],
                    'price' => (float) $validated['price'],
                    'discount_percent' => (int) ($validated['discount_percent'] ?? 0),
                    'voucher_eligible' => (bool) ($validated['voucher_eligible'] ?? false),
                    'stock' => (int) $validated['stock'],
                    'low_stock_threshold' => (int) $validated['low_stock_threshold'],
                    'option_one_name' => $validated['option_one_name'] ?? '',
                    'option_one_values' => $validated['option_one_values'] ?? '',
                    'option_two_name' => $validated['option_two_name'] ?? '',
                    'option_two_values' => $validated['option_two_values'] ?? '',
                    'status' => $validated['intent'] === 'publish' ? 'Active' : 'Draft',
                    'previous_status' => $validated['intent'] === 'publish' ? 'Active' : 'Draft',
                    'image' => $request->hasFile('image')
                        ? $request->file('image')->store('seller-products', 'public')
                        : $item['image'],
                    'gallery_images' => $galleryImages,
                ]);
            })->values()->all();

        abort_unless($found, 404);
        $request->session()->put('seller.products', $products);

        return redirect()->route('seller.products')->with(
            'success',
            $validated['intent'] === 'publish' ? 'Product changes published.' : 'Product changes saved as a draft.'
        );
    }

    public function toggleProductArchive(Request $request, string $product): RedirectResponse
    {
        $products = collect($request->session()->get('seller.products', []))
            ->map(function (array $item) use ($product) {
                if ((string) ($item['id'] ?? '') === $product) {
                    $item = $this->normalizeProduct($item);
                    if ($item['status'] === 'Archived') {
                        $item['status'] = $item['previous_status'] ?: 'Draft';
                    } else {
                        $item['previous_status'] = $item['status'];
                        $item['status'] = 'Archived';
                    }
                }
                return $item;
            })->values()->all();

        $request->session()->put('seller.products', $products);
        return redirect()->route('seller.products')->with('success', 'Product status updated.');
    }
}

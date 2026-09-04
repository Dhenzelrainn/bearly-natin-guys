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
        return view('seller.Dashboard.dashboard', [
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

    public function waybills(): View
    {
        return view('seller.fulfillment.waybills', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'summary' => [
                ['label' => 'Ready to Print', 'value' => '3', 'note' => 'Packed and verified', 'icon' => 'printer', 'tone' => 'gold'],
                ['label' => 'Printed Today', 'value' => '12', 'note' => 'Labels generated', 'icon' => 'file-check-2', 'tone' => 'olive'],
                ['label' => 'Reprint Required', 'value' => '1', 'note' => 'Damaged label reported', 'icon' => 'refresh-cw', 'tone' => 'brown'],
                ['label' => 'Pickup Cutoff', 'value' => '2:30 PM', 'note' => 'For today’s collection', 'icon' => 'clock-3', 'tone' => 'warning'],
            ],
            'waybills' => [
                ['order' => '#BR-1058', 'tracking' => 'Pending generation', 'customer' => 'Maria Santos', 'destination' => 'Santa Rosa, Laguna', 'courier' => 'Bearly Logistics', 'packages' => 1, 'weight' => '0.6 kg', 'size' => '25 × 20 × 8 cm', 'pickup' => 'Today · 3:00 PM', 'status' => 'Ready to Print', 'status_key' => 'ready', 'action' => 'Print Waybill'],
                ['order' => '#BR-1057', 'tracking' => 'Pending generation', 'customer' => 'Carlo Reyes', 'destination' => 'Calamba, Laguna', 'courier' => 'Bearly Logistics', 'packages' => 2, 'weight' => '1.4 kg', 'size' => '35 × 25 × 15 cm', 'pickup' => 'Today · 3:00 PM', 'status' => 'Ready to Print', 'status_key' => 'ready', 'action' => 'Print Waybill'],
                ['order' => '#BR-1056', 'tracking' => 'Pending generation', 'customer' => 'Jamie Lim', 'destination' => 'Biñan, Laguna', 'courier' => 'Bearly Logistics', 'packages' => 1, 'weight' => '0.8 kg', 'size' => '30 × 22 × 10 cm', 'pickup' => 'Tomorrow · 10:00 AM', 'status' => 'Ready to Print', 'status_key' => 'ready', 'action' => 'Print Waybill'],
                ['order' => '#BR-1055', 'tracking' => 'BRLY-784220', 'customer' => 'Sofia Mendoza', 'destination' => 'Cabuyao, Laguna', 'courier' => 'Bearly Logistics', 'packages' => 1, 'weight' => '0.5 kg', 'size' => '24 × 18 × 7 cm', 'pickup' => 'Today · 3:00 PM', 'status' => 'Printed', 'status_key' => 'printed', 'action' => 'View Label'],
                ['order' => '#BR-1052', 'tracking' => 'BRLY-784188', 'customer' => 'Ana Cruz', 'destination' => 'Los Baños, Laguna', 'courier' => 'Bearly Logistics', 'packages' => 1, 'weight' => '0.7 kg', 'size' => '28 × 20 × 9 cm', 'pickup' => 'Today · 3:00 PM', 'status' => 'Reprint Required', 'status_key' => 'reprint', 'action' => 'Reprint'],
            ],
            'history' => [
                ['time' => 'Today · 11:24 AM', 'order' => '#BR-1055', 'tracking' => 'BRLY-784220', 'action' => 'Waybill printed', 'actor' => 'Bea Rivera'],
                ['time' => 'Today · 10:46 AM', 'order' => '#BR-1052', 'tracking' => 'BRLY-784188', 'action' => 'Reprint requested', 'actor' => 'Bea Rivera'],
                ['time' => 'Today · 9:18 AM', 'order' => '#BR-1051', 'tracking' => 'BRLY-784176', 'action' => 'Waybill printed', 'actor' => 'Bea Rivera'],
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
                ['label' => 'Picked Up Today', 'value' => '5', 'note' => 'Handover confirmed', 'icon' => 'truck', 'tone' => 'info'],
            ],
            'eligibleOrders' => [
                ['order' => '#BR-1058', 'tracking' => 'BRLY-784231', 'packages' => 1, 'weight' => '0.6 kg'],
                ['order' => '#BR-1057', 'tracking' => 'BRLY-784230', 'packages' => 2, 'weight' => '1.4 kg'],
            ],
            'requests' => [
                ['id' => 'PU-0903-04', 'orders' => '2 orders', 'packages' => 3, 'provider' => 'Bearly Logistics', 'schedule' => 'Today · 3:00–5:00 PM', 'address' => 'Juan’s Clothing Shop, Santa Rosa', 'rider' => 'Marco Dela Cruz · Rider 014', 'status' => 'Rider Assigned', 'status_key' => 'assigned'],
                ['id' => 'PU-0903-03', 'orders' => '1 order', 'packages' => 1, 'provider' => 'Bearly Logistics', 'schedule' => 'Tomorrow · 10:00 AM–12:00 PM', 'address' => 'Juan’s Clothing Shop, Santa Rosa', 'rider' => 'Awaiting assignment', 'status' => 'Pending Approval', 'status_key' => 'pending'],
                ['id' => 'PU-0902-08', 'orders' => '4 orders', 'packages' => 5, 'provider' => 'Bearly Logistics', 'schedule' => 'Sep 2 · 3:00 PM', 'address' => 'Juan’s Clothing Shop, Santa Rosa', 'rider' => 'Paolo Reyes · Rider 008', 'status' => 'Picked Up', 'status_key' => 'picked-up'],
                ['id' => 'PU-0901-06', 'orders' => '2 orders', 'packages' => 2, 'provider' => 'Bearly Logistics', 'schedule' => 'Sep 1 · 1:00 PM', 'address' => 'Juan’s Clothing Shop, Santa Rosa', 'rider' => 'Not assigned', 'status' => 'Cancelled', 'status_key' => 'cancelled'],
            ],
        ]);
    }

    public function shipmentTracking(): View
    {
        return view('seller.fulfillment.tracking', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'summary' => [
                ['label' => 'In Transit', 'value' => '6', 'note' => 'Moving between hubs', 'icon' => 'truck', 'tone' => 'info'],
                ['label' => 'Out for Delivery', 'value' => '2', 'note' => 'With delivery riders', 'icon' => 'bike', 'tone' => 'gold'],
                ['label' => 'Delivered Today', 'value' => '9', 'note' => 'Proof recorded', 'icon' => 'badge-check', 'tone' => 'olive'],
                ['label' => 'Needs Attention', 'value' => '1', 'note' => 'Delivery exception', 'icon' => 'triangle-alert', 'tone' => 'warning'],
            ],
            'shipments' => [
                ['tracking' => 'BRLY-784220', 'order' => '#BR-1055', 'customer' => 'Sofia Mendoza', 'destination' => 'Cabuyao, Laguna', 'rider' => 'Marco Dela Cruz', 'latest' => 'Parcel received at Santa Rosa Sorting Center', 'updated' => 'Today · 12:18 PM', 'eta' => 'Sep 4', 'status' => 'At Sorting Center', 'status_key' => 'sorting'],
                ['tracking' => 'BRLY-784201', 'order' => '#BR-1054', 'customer' => 'Jamie Lim', 'destination' => 'Biñan, Laguna', 'rider' => 'Rider assignment pending', 'latest' => 'Departed origin sorting center', 'updated' => 'Today · 11:42 AM', 'eta' => 'Sep 4', 'status' => 'In Transit', 'status_key' => 'transit'],
                ['tracking' => 'BRLY-784188', 'order' => '#BR-1052', 'customer' => 'Ana Cruz', 'destination' => 'Los Baños, Laguna', 'rider' => 'Paolo Reyes', 'latest' => 'Rider is delivering the parcel', 'updated' => 'Today · 9:15 AM', 'eta' => 'Today', 'status' => 'Out for Delivery', 'status_key' => 'out-delivery'],
                ['tracking' => 'BRLY-784176', 'order' => '#BR-1051', 'customer' => 'Miguel Garcia', 'destination' => 'Calamba, Laguna', 'rider' => 'John Ramos', 'latest' => 'Delivered to customer; proof uploaded', 'updated' => 'Today · 10:05 AM', 'eta' => 'Delivered', 'status' => 'Delivered', 'status_key' => 'delivered'],
                ['tracking' => 'BRLY-784160', 'order' => '#BR-1049', 'customer' => 'Paolo Ramos', 'destination' => 'Pagsanjan, Laguna', 'rider' => 'Alex Santos', 'latest' => 'Delivery failed: customer unavailable', 'updated' => 'Yesterday · 5:40 PM', 'eta' => 'Reschedule', 'status' => 'Delivery Failed', 'status_key' => 'failed'],
            ],
        ]);
    }

    public function account(): View
    {
        return view('seller.Settings.account', [
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
        return view('seller.Settings.security', [
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
        return view('seller.Settings.notifications', [
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
        return [
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
        return view('seller.Products.inventory', [
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


    public function orders(Request $request): View
    {
        return view('seller.Orders.orders', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'defaultOrderStatus' => in_array((string) $request->query('status', 'all'), ['all', 'new', 'to-prepare', 'ready-pickup', 'in-transit', 'history', 'completed', 'cancelled'], true)
                ? (string) $request->query('status', 'all')
                : 'all',
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
                ['key' => 'history', 'label' => 'History', 'count' => null],
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
                ['id' => '#BR-1049', 'customer' => 'Paolo Ramos', 'items' => '2 items', 'payment' => 'Refunded', 'payment_key' => 'paid', 'total' => '₱1,780', 'deadline' => 'Cancelled Aug 28', 'date_key' => 'upcoming', 'urgent' => false, 'status' => 'Cancelled', 'status_key' => 'cancelled', 'tone' => 'cancelled', 'action' => 'View'],
            ],
        ]);
    }

    public function returns(): View
    {
        return view('seller.Orders.returns-refunds.returns', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'summary' => [
                ['label' => 'Action Required', 'value' => '2', 'note' => 'Respond before deadline', 'icon' => 'triangle-alert', 'tone' => 'warning'],
                ['label' => 'Under Review', 'value' => '3', 'note' => 'Awaiting platform decision', 'icon' => 'scan-search', 'tone' => 'neutral'],
                ['label' => 'Return in Progress', 'value' => '1', 'note' => 'Parcel returning to store', 'icon' => 'package-open', 'tone' => 'info'],
                ['label' => 'Refunded This Month', 'value' => '₱3,079', 'note' => '2 resolved requests', 'icon' => 'badge-check', 'tone' => 'success'],
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
            ['id' => 'RR-2041', 'order' => '#BR-1048', 'customer' => 'Maria Santos', 'product' => 'Classic Linen Shirt · Olive / M', 'sku' => 'CLS-OLV-M', 'request' => 'Return & Refund', 'reason' => 'Wrong size received', 'amount' => '₱1,299', 'submitted' => 'Today · 9:20 AM', 'deadline' => '1h 42m left', 'status' => 'Action Required', 'status_key' => 'action-required', 'tone' => 'warning', 'evidence' => '3 photos', 'buyer_note' => 'The tag says Medium, but the actual fit and measurements are smaller than the listed size.', 'seller_response' => 'No response submitted yet.', 'resolution' => 'Awaiting seller response', 'action' => 'Review Case', 'tracking' => 'Not yet created'],
            ['id' => 'RR-2040', 'order' => '#BR-1046', 'customer' => 'Paolo Ramos', 'product' => 'Canvas Tote Bag · Natural', 'sku' => 'CTB-NAT-OS', 'request' => 'Refund Only', 'reason' => 'Missing item', 'amount' => '₱899', 'submitted' => 'Today · 8:05 AM', 'deadline' => '3h 10m left', 'status' => 'Action Required', 'status_key' => 'action-required', 'tone' => 'warning', 'evidence' => '1 unboxing video', 'buyer_note' => 'The parcel arrived sealed, but the tote bag was not inside the package.', 'seller_response' => 'No response submitted yet.', 'resolution' => 'Awaiting seller response', 'action' => 'Review Case', 'tracking' => 'Not yet created'],
            ['id' => 'RR-2038', 'order' => '#BR-1044', 'customer' => 'Carlo Reyes', 'product' => 'Everyday Sneakers · White / 39', 'sku' => 'ES-WHT-39', 'request' => 'Return & Refund', 'reason' => 'Item not as described', 'amount' => '₱1,780', 'submitted' => 'Yesterday · 4:10 PM', 'deadline' => 'Responded', 'status' => 'Under Review', 'status_key' => 'under-review', 'tone' => 'review', 'evidence' => '4 photos', 'buyer_note' => 'The color and sole pattern do not match the product listing.', 'seller_response' => 'Requested platform review; warehouse packing photo was attached.', 'resolution' => 'Platform reviewing both parties’ evidence', 'action' => 'View Case', 'tracking' => 'Not yet created'],
            ['id' => 'RR-2035', 'order' => '#BR-1041', 'customer' => 'Sofia Mendoza', 'product' => 'Classic Linen Shirt · Cream / L', 'sku' => 'CLS-CRM-L', 'request' => 'Refund Only', 'reason' => 'Damaged item', 'amount' => '₱1,299', 'submitted' => 'Aug 30, 2026', 'deadline' => 'Responded', 'status' => 'Under Review', 'status_key' => 'under-review', 'tone' => 'review', 'evidence' => '2 photos', 'buyer_note' => 'There is a visible tear near the left sleeve seam.', 'seller_response' => 'Accepted buyer evidence and approved refund without return.', 'resolution' => 'Refund approval being processed', 'action' => 'View Case', 'tracking' => 'Not required'],
            ['id' => 'RR-2031', 'order' => '#BR-1036', 'customer' => 'Jamie Lim', 'product' => 'Everyday Sneakers · Black / 40', 'sku' => 'ES-BLK-40', 'request' => 'Return & Refund', 'reason' => 'Damaged during delivery', 'amount' => '₱1,780', 'submitted' => 'Aug 29, 2026', 'deadline' => 'Return by Sep 6', 'status' => 'Return Shipping', 'status_key' => 'return-shipping', 'tone' => 'shipping', 'evidence' => '3 photos', 'buyer_note' => 'The shoe box and right shoe were crushed when delivered.', 'seller_response' => 'Return approved after reviewing courier damage evidence.', 'resolution' => 'Return parcel is in transit to seller', 'action' => 'Track Return', 'tracking' => 'BRLY-RET-20431'],
            ['id' => 'RR-2025', 'order' => '#BR-1029', 'customer' => 'Ana Cruz', 'product' => 'Canvas Tote Bag · Natural', 'sku' => 'CTB-NAT-OS', 'request' => 'Refund Only', 'reason' => 'Seller approved refund', 'amount' => '₱1,299', 'submitted' => 'Aug 27, 2026', 'deadline' => 'Closed Aug 29', 'status' => 'Refunded', 'status_key' => 'resolved', 'tone' => 'resolved', 'evidence' => '2 photos', 'buyer_note' => 'The printed design was incomplete on one side.', 'seller_response' => 'Refund approved. No item return required.', 'resolution' => '₱1,299 returned to buyer via GCash', 'action' => 'View Details', 'tracking' => 'Not required'],
        ];
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

        return view('seller.Store.store', [
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

        return view('seller.Products.products', [
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

    public function pricing(Request $request): View
    {
        $storedProducts = collect($request->session()->get('seller.products', []))
            ->map(fn (array $product) => $this->normalizeProduct($product));

        $products = $storedProducts->isNotEmpty() ? $storedProducts : collect([
            ['id' => 'demo-1', 'name' => 'Classic Linen Shirt', 'sku' => 'CLS-LINEN-SHIRT', 'category' => 'Fashion and Apparel', 'price' => 1299, 'discount_percent' => 10, 'voucher_eligible' => true, 'stock' => 42, 'status' => 'Active', 'image' => null],
            ['id' => 'demo-2', 'name' => 'Canvas Tote Bag', 'sku' => 'CNV-TOTE-BAG', 'category' => 'Fashion and Apparel', 'price' => 899, 'discount_percent' => 0, 'voucher_eligible' => true, 'stock' => 31, 'status' => 'Active', 'image' => null],
            ['id' => 'demo-3', 'name' => 'Everyday Sneakers', 'sku' => 'EV-SNKRS-WHT', 'category' => 'Fashion and Apparel', 'price' => 1780, 'discount_percent' => 15, 'voucher_eligible' => false, 'stock' => 18, 'status' => 'Active', 'image' => null],
            ['id' => 'demo-4', 'name' => 'Minimalist Crossbody Bag', 'sku' => 'MCB-BRN-01', 'category' => 'Fashion and Apparel', 'price' => 1050, 'discount_percent' => 0, 'voucher_eligible' => false, 'stock' => 24, 'status' => 'Draft', 'image' => null],
        ])->map(fn (array $product) => $this->normalizeProduct($product));

        return view('seller.Products.pricing', [
            'seller' => $this->seller(),
            'notifications' => $this->notifications(),
            'products' => $products,
            'summary' => [
                ['label' => 'Active Promotions', 'value' => '2', 'note' => 'Across 2 products', 'icon' => 'badge-percent', 'tone' => 'gold'],
                ['label' => 'Discounted Products', 'value' => (string) $products->where('discount_percent', '>', 0)->count(), 'note' => 'Current sale prices', 'icon' => 'tags', 'tone' => 'olive'],
                ['label' => 'Voucher Eligible', 'value' => (string) $products->where('voucher_eligible', true)->count(), 'note' => 'Can join store vouchers', 'icon' => 'ticket-percent', 'tone' => 'brown'],
                ['label' => 'Ending Soon', 'value' => '1', 'note' => 'Within the next 48 hours', 'icon' => 'clock-3', 'tone' => 'warning'],
            ],
            'campaigns' => [
                ['name' => 'September Payday Sale', 'type' => 'Product Discount', 'period' => 'Sep 1–5, 2026', 'products' => 2, 'sales' => '₱18,420', 'status' => 'Active', 'status_key' => 'active'],
                ['name' => 'New Buyer Voucher', 'type' => 'Store Voucher', 'period' => 'Sep 1–30, 2026', 'products' => 3, 'sales' => '₱6,390', 'status' => 'Active', 'status_key' => 'active'],
                ['name' => 'August Weekend Deal', 'type' => 'Product Discount', 'period' => 'Aug 24–25, 2026', 'products' => 4, 'sales' => '₱27,860', 'status' => 'Ended', 'status_key' => 'ended'],
            ],
        ]);
    }

    public function createProduct(): View
    {
        return view('seller.Products.product-form', [
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

        return view('seller.Products.product-form', [
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

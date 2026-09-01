<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class AdminController extends Controller
{
    private function base(array $data = []): array
    {
        return array_merge([
            'admin' => [
                'name' => 'Alex Rivera',
                'role' => 'Super Admin',
                'email' => 'admin@bearly.test',
                'initials' => 'AR',
            ],
            'topNotifications' => [
                ['title' => '4 registrations awaiting review', 'time' => '8 min ago', 'type' => 'warning'],
                ['title' => 'Seller compliance alert detected', 'time' => '24 min ago', 'type' => 'danger'],
                ['title' => 'Commission report is ready', 'time' => '1 hr ago', 'type' => 'success'],
            ],
        ], $data);
    }

    public function dashboard(): View
    {
        return view('admin.dashboard', $this->base([
            'kpis' => [
                ['label' => 'Total Users', 'value' => '24,860', 'change' => '+8.4%', 'trend' => 'up', 'icon' => 'users'],
                ['label' => 'Gross Sales', 'value' => '₱1.28M', 'change' => '+12.7%', 'trend' => 'up', 'icon' => 'shopping-bag'],
                ['label' => 'Active Sellers', 'value' => '1,284', 'change' => '+4.1%', 'trend' => 'up', 'icon' => 'store'],
                ['label' => 'Pending Complaints', 'value' => '18', 'change' => '5 urgent', 'trend' => 'alert', 'icon' => 'message-square-warning'],
            ],
            'salesByMonth' => [58, 68, 64, 78, 73, 88, 96, 91, 109, 118, 126, 139],
            'activity' => [
                ['title' => 'Seller registration approved', 'meta' => 'Mara Home Goods • 12 minutes ago', 'type' => 'success'],
                ['title' => 'High-risk product flagged', 'meta' => 'TechVault PH • 31 minutes ago', 'type' => 'danger'],
                ['title' => 'Complaint moved to review', 'meta' => 'Case DSP-1048 • 46 minutes ago', 'type' => 'warning'],
                ['title' => 'Platform announcement published', 'meta' => 'Shipping advisory • 2 hours ago', 'type' => 'info'],
            ],
            'systemNotices' => [
                ['title' => 'Registration queue', 'text' => '4 applications have complete documents and are ready for review.', 'action' => 'Review now', 'route' => 'admin.registrations'],
                ['title' => 'Compliance review', 'text' => '3 flagged listings require an admin decision today.', 'action' => 'Open compliance', 'route' => 'admin.compliance'],
                ['title' => 'Dispute SLA', 'text' => '2 complaints are nearing the 24-hour response target.', 'action' => 'View disputes', 'route' => 'admin.disputes'],
            ],
        ]));
    }

    public function registrations(): View
    {
        return view('admin.registrations', $this->base([
            'applications' => [
                ['id' => 'REG-2041', 'name' => 'Sofia Mendoza', 'role' => 'Seller', 'email' => 'sofia@example.test', 'submitted' => 'Aug 24, 2026', 'status' => 'Pending', 'category' => 'Jewelry & Watches', 'documents' => ['Government ID', 'Business Permit']],
                ['id' => 'REG-2042', 'name' => 'Noah Santos', 'role' => 'Courier', 'email' => 'noah@example.test', 'submitted' => 'Aug 24, 2026', 'status' => 'Pending', 'category' => 'Motorcycle', 'documents' => ['Driver License', 'OR/CR']],
                ['id' => 'REG-2043', 'name' => 'Bianca Lim', 'role' => 'Buyer', 'email' => 'bianca@example.test', 'submitted' => 'Aug 23, 2026', 'status' => 'Pending', 'category' => '—', 'documents' => ['Government ID']],
                ['id' => 'REG-2044', 'name' => 'Ethan Cruz', 'role' => 'Seller', 'email' => 'ethan@example.test', 'submitted' => 'Aug 23, 2026', 'status' => 'Needs Review', 'category' => 'Food & Gourmet', 'documents' => ['Government ID', 'Business Permit']],
                ['id' => 'REG-2045', 'name' => 'Leah Ramos', 'role' => 'Courier', 'email' => 'leah@example.test', 'submitted' => 'Aug 22, 2026', 'status' => 'Pending', 'category' => 'Sedan', 'documents' => ['Driver License', 'OR/CR']],
            ],
        ]));
    }

    public function users(): View
    {
        return view('admin.users', $this->base([
            'users' => [
                ['id' => 'USR-8812', 'name' => 'Andrea Flores', 'email' => 'andrea@example.test', 'role' => 'Buyer', 'joined' => 'Jul 11, 2026', 'status' => 'Active'],
                ['id' => 'USR-7719', 'name' => 'Mara Home Goods', 'email' => 'mara@example.test', 'role' => 'Seller', 'joined' => 'Jun 28, 2026', 'status' => 'Active'],
                ['id' => 'USR-6915', 'name' => 'Jared Molina', 'email' => 'jared@example.test', 'role' => 'Courier', 'joined' => 'Jun 18, 2026', 'status' => 'Active'],
                ['id' => 'USR-6507', 'name' => 'TechVault PH', 'email' => 'techvault@example.test', 'role' => 'Seller', 'joined' => 'Jun 02, 2026', 'status' => 'Suspended'],
                ['id' => 'USR-6021', 'name' => 'Paolo Reyes', 'email' => 'paolo@example.test', 'role' => 'Buyer', 'joined' => 'May 21, 2026', 'status' => 'Deactivated'],
                ['id' => 'USR-5418', 'name' => 'Nina Villanueva', 'email' => 'nina@example.test', 'role' => 'Buyer', 'joined' => 'Apr 09, 2026', 'status' => 'Active'],
            ],
        ]));
    }

    public function compliance(): View
    {
        return view('admin.compliance', $this->base([
            'audits' => [
                ['id' => 'PRD-9934', 'product' => 'Classic Steel Watch', 'seller' => 'Chrono Alley', 'registered' => 'Jewelry & Watches', 'listed' => 'Jewelry & Watches', 'risk' => 'Low', 'status' => 'Compliant'],
                ['id' => 'PRD-9935', 'product' => 'Portable Power Bank', 'seller' => 'TechVault PH', 'registered' => 'Electronics', 'listed' => 'Electronics', 'risk' => 'Medium', 'status' => 'Review'],
                ['id' => 'PRD-9936', 'product' => 'Unverified Herbal Capsules', 'seller' => 'Daily Wellness Hub', 'registered' => 'Food & Gourmet', 'listed' => 'Health Products', 'risk' => 'High', 'status' => 'Flagged'],
                ['id' => 'PRD-9937', 'product' => 'Office Task Chair', 'seller' => 'Mara Home Goods', 'registered' => 'Furniture & Office', 'listed' => 'Furniture & Office', 'risk' => 'Low', 'status' => 'Compliant'],
            ],
            'flagged' => [
                ['id' => 'FLAG-310', 'product' => 'Unverified Herbal Capsules', 'seller' => 'Daily Wellness Hub', 'reason' => 'Product does not match registered seller category and requires policy review.', 'risk' => 'High', 'warnings' => 1],
                ['id' => 'FLAG-311', 'product' => 'Replica Luxury Watch', 'seller' => 'Prime Finds MNL', 'reason' => 'Listing contains authenticity claims that require manual verification.', 'risk' => 'High', 'warnings' => 2],
                ['id' => 'FLAG-312', 'product' => 'High-output Laser Pointer', 'seller' => 'Gizmo Stop', 'reason' => 'Potentially restricted product category.', 'risk' => 'Medium', 'warnings' => 0],
            ],
        ]));
    }

    public function disputes(): View
    {
        return view('admin.disputes', $this->base([
            'disputes' => [
                ['id' => 'DSP-1048', 'subject' => 'Item arrived damaged', 'buyer' => 'Karen Yu', 'seller' => 'Mara Home Goods', 'courier' => 'Jared Molina', 'amount' => '₱2,480', 'priority' => 'High', 'status' => 'Under Review', 'opened' => 'Aug 24, 9:18 AM'],
                ['id' => 'DSP-1047', 'subject' => 'Missing accessory', 'buyer' => 'Theo Garcia', 'seller' => 'TechVault PH', 'courier' => 'Noah Santos', 'amount' => '₱1,299', 'priority' => 'Medium', 'status' => 'Awaiting Seller', 'opened' => 'Aug 23, 4:42 PM'],
                ['id' => 'DSP-1046', 'subject' => 'Delivery marked completed early', 'buyer' => 'Liza Ong', 'seller' => 'Everyday Finds', 'courier' => 'Leah Ramos', 'amount' => '₱849', 'priority' => 'Medium', 'status' => 'Coordinating', 'opened' => 'Aug 23, 11:07 AM'],
            ],
            'evidence' => [
                ['label' => 'Buyer photo', 'type' => 'Image', 'meta' => 'damaged-package.jpg • 1.8 MB'],
                ['label' => 'Order invoice', 'type' => 'Document', 'meta' => 'invoice-1048.pdf • 284 KB'],
                ['label' => 'Courier proof', 'type' => 'Image', 'meta' => 'delivery-proof.jpg • 1.1 MB'],
            ],
            'timeline' => [
                ['time' => '9:18 AM', 'text' => 'Buyer submitted complaint and photo evidence.'],
                ['time' => '9:36 AM', 'text' => 'Seller acknowledged the case and requested parcel photos.'],
                ['time' => '10:02 AM', 'text' => 'Courier uploaded delivery proof.'],
                ['time' => '10:24 AM', 'text' => 'Admin review started.'],
            ],
        ]));
    }

    public function commissions(): View
    {
        $ledger = [
            ['date' => 'Aug 24, 2026', 'order' => 'ORD-50192', 'seller' => 'Mara Home Goods', 'gross' => 2480.00],
            ['date' => 'Aug 24, 2026', 'order' => 'ORD-50188', 'seller' => 'Chrono Alley', 'gross' => 6390.00],
            ['date' => 'Aug 23, 2026', 'order' => 'ORD-50171', 'seller' => 'Everyday Finds', 'gross' => 1299.00],
            ['date' => 'Aug 23, 2026', 'order' => 'ORD-50154', 'seller' => 'TechVault PH', 'gross' => 4299.00],
            ['date' => 'Aug 22, 2026', 'order' => 'ORD-50111', 'seller' => 'Mara Home Goods', 'gross' => 3190.00],
        ];

        $ledger = array_map(function ($row) {
            $row['commission'] = $row['gross'] * 0.10;
            $row['sellerNet'] = $row['gross'] * 0.90;
            return $row;
        }, $ledger);

        return view('admin.commissions', $this->base([
            'rate' => 10,
            'ledger' => $ledger,
        ]));
    }

    public function reports(): View
    {
        return view('admin.reports', $this->base([
            'reportKpis' => [
                ['label' => 'Net Sales', 'value' => '₱1,154,820', 'note' => '+11.2% vs previous period'],
                ['label' => 'Orders', 'value' => '8,421', 'note' => '+7.8% vs previous period'],
                ['label' => 'Platform Commission', 'value' => '₱128,313', 'note' => '10% configured rate'],
                ['label' => 'Avg. Order Value', 'value' => '₱152.40', 'note' => '+2.1% vs previous period'],
            ],
            'salesSeries' => [64, 71, 69, 78, 86, 82, 93, 99, 106, 112, 121, 134],
            'topSellers' => [
                ['seller' => 'Mara Home Goods', 'sales' => '₱184,210', 'commission' => '₱18,421'],
                ['seller' => 'Chrono Alley', 'sales' => '₱142,880', 'commission' => '₱14,288'],
                ['seller' => 'Everyday Finds', 'sales' => '₱119,520', 'commission' => '₱11,952'],
                ['seller' => 'TechVault PH', 'sales' => '₱96,410', 'commission' => '₱9,641'],
            ],
        ]));
    }

    public function settings(): View
    {
        return view('admin.settings', $this->base([
            'announcements' => [
                ['title' => 'Weekend Shipping Advisory', 'audience' => 'All users', 'status' => 'Published', 'date' => 'Aug 23, 2026'],
                ['title' => 'Seller Verification Reminder', 'audience' => 'Sellers', 'status' => 'Scheduled', 'date' => 'Aug 25, 2026'],
            ],
            'policy' => [
                'title' => 'Marketplace Policy',
                'updated' => 'Aug 18, 2026',
                'body' => "Bearly connects buyers, sellers, and couriers through a trusted marketplace. Sellers are responsible for accurate listings, compliant products, and timely order fulfillment. Users must keep account information current and use platform communication tools responsibly.",
            ],
        ]));
    }

    public function messages(): View
    {
        return view('admin.messages', $this->base([
            'conversations' => [
                ['id' => 1, 'name' => 'Mara Home Goods', 'role' => 'Seller', 'preview' => 'We uploaded the additional photos.', 'time' => '6:42 PM', 'unread' => 2, 'initials' => 'MH'],
                ['id' => 2, 'name' => 'Karen Yu', 'role' => 'Buyer', 'preview' => 'Thank you for reviewing my complaint.', 'time' => '5:18 PM', 'unread' => 0, 'initials' => 'KY'],
                ['id' => 3, 'name' => 'Jared Molina', 'role' => 'Courier', 'preview' => 'Delivery proof has been uploaded.', 'time' => '3:11 PM', 'unread' => 0, 'initials' => 'JM'],
                ['id' => 4, 'name' => 'TechVault PH', 'role' => 'Seller', 'preview' => 'Can we clarify the compliance notice?', 'time' => '1:54 PM', 'unread' => 1, 'initials' => 'TV'],
            ],
            'messages' => [
                ['from' => 'them', 'text' => 'Good afternoon. We uploaded the additional photos requested for case DSP-1048.', 'time' => '6:34 PM'],
                ['from' => 'me', 'text' => 'Received. We are reviewing the evidence from all parties now.', 'time' => '6:36 PM'],
                ['from' => 'them', 'text' => 'Thank you. Please let us know if you need a clearer copy of the packing photo.', 'time' => '6:42 PM'],
            ],
        ]));
    }

    public function account(): View
    {
        return view('admin.account', $this->base([
            'profile' => [
                'first_name' => 'Alex',
                'last_name' => 'Rivera',
                'email' => 'admin@bearly.test',
                'phone' => '+63 917 555 0198',
                'role' => 'Super Admin',
            ],
            'admins' => [
                ['name' => 'Alex Rivera', 'email' => 'admin@bearly.test', 'role' => 'Super Admin', 'status' => 'Active'],
                ['name' => 'Mika Santos', 'email' => 'mika.admin@bearly.test', 'role' => 'Operations Admin', 'status' => 'Active'],
                ['name' => 'Drew Lim', 'email' => 'drew.admin@bearly.test', 'role' => 'Support Admin', 'status' => 'Active'],
            ],
        ]));
    }
}

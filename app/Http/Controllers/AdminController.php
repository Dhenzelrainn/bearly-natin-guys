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
        return view('admin.registrations.index', $this->base([
            'applications' => [
                ['id' => 'REG-2041', 'name' => 'Sofia Mendoza', 'role' => 'Seller', 'email' => 'sofia@example.test', 'submitted' => 'Aug 24, 2026', 'status' => 'Pending', 'category' => 'Jewelry & Watches', 'documents' => ['Government ID', 'Business Permit']],
                ['id' => 'REG-2042', 'name' => 'Noah Santos', 'role' => 'Courier', 'email' => 'noah@example.test', 'submitted' => 'Aug 24, 2026', 'status' => 'Pending', 'category' => 'Motorcycle', 'documents' => ['Driver License', 'OR/CR']],
                ['id' => 'REG-2043', 'name' => 'Bianca Lim', 'role' => 'Buyer', 'email' => 'bianca@example.test', 'submitted' => 'Aug 23, 2026', 'status' => 'Pending', 'category' => '—', 'documents' => ['Government ID']],
                ['id' => 'REG-2044', 'name' => 'Ethan Cruz', 'role' => 'Seller', 'email' => 'ethan@example.test', 'submitted' => 'Aug 23, 2026', 'status' => 'Needs Review', 'category' => 'Food & Gourmet', 'documents' => ['Government ID', 'Business Permit']],
                ['id' => 'REG-2045', 'name' => 'Leah Ramos', 'role' => 'Courier', 'email' => 'leah@example.test', 'submitted' => 'Aug 22, 2026', 'status' => 'Pending', 'category' => 'Sedan', 'documents' => ['Driver License', 'OR/CR']],
            ],
        ]));
    }

    public function buyerApplications(): View
    {
        return view('admin.registrations.buyers', $this->base([
            'applications' => [
                [
                    'id' => 'BUY-2043',
                    'first_name' => 'Bianca',
                    'middle_initial' => 'M.',
                    'last_name' => 'Lim',
                    'name' => 'Bianca M. Lim',
                    'sex' => 'Female',
                    'email' => 'bianca@example.test',
                    'contact' => '0917 845 2031',
                    'birthday' => 'March 18, 2004',
                    'age' => 22,

                    'province' => 'Laguna',
                    'municipality' => 'San Pablo City',
                    'barangay' => 'San Francisco',
                    'street' => 'Mabini Street',
                    'house_number' => '24',

                    'submitted' => 'Aug 23, 2026',
                    'status' => 'Pending',

                    'documents' => [
                        'Government ID',
                    ],
                ],

                [
                    'id' => 'BUY-2046',
                    'first_name' => 'Carlo',
                    'middle_initial' => 'D.',
                    'last_name' => 'Reyes',
                    'name' => 'Carlo D. Reyes',
                    'sex' => 'Male',
                    'email' => 'carlo.reyes@example.test',
                    'contact' => '0918 632 4185',
                    'birthday' => 'November 7, 2002',
                    'age' => 23,

                    'province' => 'Laguna',
                    'municipality' => 'Calamba City',
                    'barangay' => 'Real',
                    'street' => 'Rizal Street',
                    'house_number' => '108',

                    'submitted' => 'Aug 24, 2026',
                    'status' => 'Pending',

                    'documents' => [
                        'Government ID',
                    ],
                ],

                [
                    'id' => 'BUY-2049',
                    'first_name' => 'Angela',
                    'middle_initial' => 'P.',
                    'last_name' => 'Torres',
                    'name' => 'Angela P. Torres',
                    'sex' => 'Female',
                    'email' => 'angela.torres@example.test',
                    'contact' => '0920 554 7712',
                    'birthday' => 'January 26, 2005',
                    'age' => 21,

                    'province' => 'Laguna',
                    'municipality' => 'Los Baños',
                    'barangay' => 'Batong Malake',
                    'street' => 'National Highway',
                    'house_number' => '67',

                    'submitted' => 'Aug 25, 2026',
                    'status' => 'Needs Review',

                    'documents' => [
                        'Government ID',
                    ],
                ],

                [
                    'id' => 'BUY-2053',
                    'first_name' => 'Miguel',
                    'middle_initial' => 'S.',
                    'last_name' => 'Navarro',
                    'name' => 'Miguel S. Navarro',
                    'sex' => 'Male',
                    'email' => 'miguel.navarro@example.test',
                    'contact' => '0916 782 9924',
                    'birthday' => 'June 2, 2001',
                    'age' => 25,

                    'province' => 'Laguna',
                    'municipality' => 'Santa Cruz',
                    'barangay' => 'Poblacion II',
                    'street' => 'Pedro Guevara Avenue',
                    'house_number' => '315',

                    'submitted' => 'Aug 26, 2026',
                    'status' => 'Pending',

                    'documents' => [
                        'Government ID',
                    ],
                ],

                [
                    'id' => 'BUY-2057',
                    'first_name' => 'Nicole',
                    'middle_initial' => 'A.',
                    'last_name' => 'Villanueva',
                    'name' => 'Nicole A. Villanueva',
                    'sex' => 'Female',
                    'email' => 'nicole.v@example.test',
                    'contact' => '0995 384 1160',
                    'birthday' => 'September 13, 2003',
                    'age' => 22,

                    'province' => 'Laguna',
                    'municipality' => 'Pila',
                    'barangay' => 'Santa Clara Norte',
                    'street' => 'Bonifacio Street',
                    'house_number' => '42',

                    'submitted' => 'Aug 26, 2026',
                    'status' => 'Pending',

                    'documents' => [
                        'Government ID',
                    ],
                ],
            ],
        ]));
    }

    public function sellerApplications(): View
    {
        return view('admin.registrations.sellers', $this->base([
            'applications' => [
                [
                    'id' => 'SEL-3101',
                    'first_name' => 'Sofia',
                    'middle_initial' => 'R.',
                    'last_name' => 'Mendoza',
                    'name' => 'Sofia R. Mendoza',
                    'sex' => 'Female',
                    'email' => 'sofia.mendoza@example.test',
                    'contact' => '0917 451 9820',
                    'birthday' => 'April 14, 1998',
                    'age' => 28,

                    'province' => 'Laguna',
                    'municipality' => 'San Pablo City',
                    'barangay' => 'San Rafael',
                    'street' => 'Maharlika Highway',
                    'house_number' => '118',

                    'business_name' => 'Sofia Luxe Finds',
                    'business_category' => 'Jewelry & Watches',

                    'submitted' => 'Aug 24, 2026',
                    'status' => 'Pending',

                    'documents' => [
                        'Government ID',
                        'Business Permit',
                    ],
                ],

                [
                    'id' => 'SEL-3104',
                    'first_name' => 'Ethan',
                    'middle_initial' => 'C.',
                    'last_name' => 'Cruz',
                    'name' => 'Ethan C. Cruz',
                    'sex' => 'Male',
                    'email' => 'ethan.cruz@example.test',
                    'contact' => '0918 773 5412',
                    'birthday' => 'September 5, 1995',
                    'age' => 30,

                    'province' => 'Laguna',
                    'municipality' => 'Calamba City',
                    'barangay' => 'Parian',
                    'street' => 'National Highway',
                    'house_number' => '206',

                    'business_name' => 'Ethan Gourmet Hub',
                    'business_category' => 'Food & Gourmet',

                    'submitted' => 'Aug 23, 2026',
                    'status' => 'Needs Review',

                    'documents' => [
                        'Government ID',
                        'Business Permit',
                    ],
                ],

                [
                    'id' => 'SEL-3108',
                    'first_name' => 'Maria',
                    'middle_initial' => 'L.',
                    'last_name' => 'Santos',
                    'name' => 'Maria L. Santos',
                    'sex' => 'Female',
                    'email' => 'maria.santos@example.test',
                    'contact' => '0921 335 8804',
                    'birthday' => 'December 21, 1999',
                    'age' => 26,

                    'province' => 'Laguna',
                    'municipality' => 'Los Baños',
                    'barangay' => 'Anos',
                    'street' => 'Lopez Avenue',
                    'house_number' => '74',

                    'business_name' => 'Mara Home Goods',
                    'business_category' => 'Home & Living',

                    'submitted' => 'Aug 25, 2026',
                    'status' => 'Pending',

                    'documents' => [
                        'Government ID',
                        'Business Permit',
                    ],
                ],

                [
                    'id' => 'SEL-3112',
                    'first_name' => 'Daniel',
                    'middle_initial' => 'P.',
                    'last_name' => 'Reyes',
                    'name' => 'Daniel P. Reyes',
                    'sex' => 'Male',
                    'email' => 'daniel.reyes@example.test',
                    'contact' => '0998 710 2461',
                    'birthday' => 'February 8, 1997',
                    'age' => 29,

                    'province' => 'Laguna',
                    'municipality' => 'Santa Cruz',
                    'barangay' => 'Poblacion IV',
                    'street' => 'Regidor Street',
                    'house_number' => '91',

                    'business_name' => 'TechVault PH',
                    'business_category' => 'Electronics & Gadgets',

                    'submitted' => 'Aug 26, 2026',
                    'status' => 'Pending',

                    'documents' => [
                        'Government ID',
                        'Business Permit',
                    ],
                ],
            ],
        ]));
    }

    public function logisticsApplications(): View
    {
        return view('admin.registrations.logistics', $this->base([
            'applications' => [
                [
                    'id' => 'LOG-4101',
                    'first_name' => 'Nathan',
                    'middle_initial' => 'D.',
                    'last_name' => 'Garcia',
                    'name' => 'Nathan D. Garcia',
                    'sex' => 'Male',
                    'email' => 'nathan.garcia@example.test',
                    'contact' => '0917 582 1147',
                    'birthday' => 'July 11, 1992',
                    'age' => 34,

                    'province' => 'Laguna',
                    'municipality' => 'Santa Cruz',
                    'barangay' => 'Poblacion I',
                    'street' => 'Pedro Guevara Avenue',
                    'house_number' => '412',

                    'business_name' => 'Laguna Central Logistics',
                    'submitted' => 'Aug 24, 2026',
                    'status' => 'Pending',

                    'documents' => [
                        'Government ID',
                        'Business / DTI Permit',
                    ],
                ],

                [
                    'id' => 'LOG-4105',
                    'first_name' => 'Clarisse',
                    'middle_initial' => 'M.',
                    'last_name' => 'Villanueva',
                    'name' => 'Clarisse M. Villanueva',
                    'sex' => 'Female',
                    'email' => 'clarisse.v@example.test',
                    'contact' => '0920 718 4620',
                    'birthday' => 'October 3, 1990',
                    'age' => 35,

                    'province' => 'Laguna',
                    'municipality' => 'Calamba City',
                    'barangay' => 'Real',
                    'street' => 'Mayapa Road',
                    'house_number' => '68',

                    'business_name' => 'South Laguna Sorting Hub',
                    'submitted' => 'Aug 25, 2026',
                    'status' => 'Needs Review',

                    'documents' => [
                        'Government ID',
                        'Business / DTI Permit',
                    ],
                ],

                [
                    'id' => 'LOG-4109',
                    'first_name' => 'Paolo',
                    'middle_initial' => 'A.',
                    'last_name' => 'Fernandez',
                    'name' => 'Paolo A. Fernandez',
                    'sex' => 'Male',
                    'email' => 'paolo.fernandez@example.test',
                    'contact' => '0995 330 8721',
                    'birthday' => 'May 19, 1989',
                    'age' => 37,

                    'province' => 'Laguna',
                    'municipality' => 'San Pablo City',
                    'barangay' => 'San Roque',
                    'street' => 'Colago Avenue',
                    'house_number' => '155',

                    'business_name' => 'Bearly South Distribution Center',
                    'submitted' => 'Aug 26, 2026',
                    'status' => 'Pending',

                    'documents' => [
                        'Government ID',
                        'Business / DTI Permit',
                    ],
                ],
            ],
        ]));
    }

    public function users(): View
    {
        return view('admin.users.index', $this->base([
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

    public function buyerUsers(): View
    {
        return view('admin.users.buyers', $this->base([
            'users' => [
                [
                    'id' => 'BUY-8812',
                    'name' => 'Andrea Flores',
                    'email' => 'andrea@example.test',
                    'contact' => '0917 551 2084',
                    'location' => 'San Pablo City, Laguna',
                    'joined' => 'Jul 11, 2026',
                    'orders' => 24,
                    'status' => 'Active',
                ],
                [
                    'id' => 'BUY-6021',
                    'name' => 'Paolo Reyes',
                    'email' => 'paolo@example.test',
                    'contact' => '0918 416 8220',
                    'location' => 'Calamba City, Laguna',
                    'joined' => 'May 21, 2026',
                    'orders' => 11,
                    'status' => 'Deactivated',
                ],
                [
                    'id' => 'BUY-5418',
                    'name' => 'Nina Villanueva',
                    'email' => 'nina@example.test',
                    'contact' => '0921 318 4057',
                    'location' => 'Pila, Laguna',
                    'joined' => 'Apr 09, 2026',
                    'orders' => 37,
                    'status' => 'Active',
                ],
                [
                    'id' => 'BUY-4927',
                    'name' => 'Marco Lim',
                    'email' => 'marco.lim@example.test',
                    'contact' => '0995 620 1148',
                    'location' => 'Los Baños, Laguna',
                    'joined' => 'Mar 18, 2026',
                    'orders' => 18,
                    'status' => 'Suspended',
                ],
            ],
        ]));
    }

    public function sellerUsers(): View
    {
        return view('admin.users.sellers', $this->base([
            'users' => [
                [
                    'id' => 'SEL-7719',
                    'name' => 'Mara Home Goods',
                    'owner' => 'Maria L. Santos',
                    'email' => 'mara@example.test',
                    'category' => 'Home & Living',
                    'joined' => 'Jun 28, 2026',
                    'products' => 48,
                    'status' => 'Active',
                ],
                [
                    'id' => 'SEL-6507',
                    'name' => 'TechVault PH',
                    'owner' => 'Daniel P. Reyes',
                    'email' => 'techvault@example.test',
                    'category' => 'Electronics & Gadgets',
                    'joined' => 'Jun 02, 2026',
                    'products' => 31,
                    'status' => 'Suspended',
                ],
                [
                    'id' => 'SEL-6128',
                    'name' => 'Chrono Alley',
                    'owner' => 'Luis Fernandez',
                    'email' => 'chrono@example.test',
                    'category' => 'Jewelry & Watches',
                    'joined' => 'May 15, 2026',
                    'products' => 27,
                    'status' => 'Active',
                ],
                [
                    'id' => 'SEL-5880',
                    'name' => 'Everyday Finds',
                    'owner' => 'Clarissa Go',
                    'email' => 'everyday@example.test',
                    'category' => 'Home & Living',
                    'joined' => 'Apr 26, 2026',
                    'products' => 54,
                    'status' => 'Active',
                ],
            ],
        ]));
    }

    public function logisticsUsers(): View
    {
        return view('admin.users.logistics', $this->base([
            'users' => [
                [
                    'id' => 'LOG-4101',
                    'name' => 'Laguna Central Logistics',
                    'manager' => 'Nathan D. Garcia',
                    'email' => 'laguna.central@example.test',
                    'location' => 'Santa Cruz, Laguna',
                    'riders' => 18,
                    'joined' => 'May 07, 2026',
                    'status' => 'Active',
                ],
                [
                    'id' => 'LOG-4105',
                    'name' => 'South Laguna Sorting Hub',
                    'manager' => 'Clarisse M. Villanueva',
                    'email' => 'southlaguna@example.test',
                    'location' => 'Calamba City, Laguna',
                    'riders' => 12,
                    'joined' => 'May 29, 2026',
                    'status' => 'Active',
                ],
                [
                    'id' => 'LOG-4109',
                    'name' => 'Bearly South Distribution Center',
                    'manager' => 'Paolo A. Fernandez',
                    'email' => 'bearlysouth@example.test',
                    'location' => 'San Pablo City, Laguna',
                    'riders' => 15,
                    'joined' => 'Jun 10, 2026',
                    'status' => 'Suspended',
                ],
            ],
        ]));
    }

    public function riderUsers(): View
    {
        return view('admin.users.riders', $this->base([
            'users' => [
                [
                    'id' => 'RID-6901',
                    'name' => 'Jared Molina',
                    'email' => 'jared@example.test',
                    'vehicle' => 'Motorcycle',
                    'plate' => 'NCR 4821',
                    'center' => 'Laguna Central Logistics',
                    'deliveries' => 184,
                    'joined' => 'Jun 18, 2026',
                    'status' => 'Active',
                ],
                [
                    'id' => 'RID-6905',
                    'name' => 'Noah Santos',
                    'email' => 'noah@example.test',
                    'vehicle' => 'Motorcycle',
                    'plate' => 'ABC 2918',
                    'center' => 'South Laguna Sorting Hub',
                    'deliveries' => 143,
                    'joined' => 'Jun 25, 2026',
                    'status' => 'Active',
                ],
                [
                    'id' => 'RID-6910',
                    'name' => 'Leah Ramos',
                    'email' => 'leah@example.test',
                    'vehicle' => 'Motorcycle',
                    'plate' => 'XYZ 7732',
                    'center' => 'Bearly South Distribution Center',
                    'deliveries' => 96,
                    'joined' => 'Jul 03, 2026',
                    'status' => 'Suspended',
                ],
            ],
        ]));
    }

    public function compliance(): View
    {
        return view('admin.compliance.index', $this->base([
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
        return view('admin.compliance.disputes', $this->base([
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

    public function productViolations(): View
    {
        return view('admin.compliance.violations', $this->base([
            'violations' => [
                [
                    'id' => 'VIO-4108',
                    'product_id' => 'PRD-9936',
                    'product' => 'Unverified Herbal Capsules',
                    'seller' => 'Daily Wellness Hub',
                    'registered_category' => 'Food & Gourmet',
                    'listed_category' => 'Health Products',
                    'violation' => 'Category mismatch',
                    'reason' => 'The listing does not match the seller\'s registered business category and requires policy review.',
                    'reported' => 'Aug 26, 2026',
                    'risk' => 'High',
                    'warnings' => 1,
                    'status' => 'Under Review',
                ],
                [
                    'id' => 'VIO-4109',
                    'product_id' => 'PRD-9941',
                    'product' => 'Replica Luxury Watch',
                    'seller' => 'Prime Finds MNL',
                    'registered_category' => 'Jewelry & Watches',
                    'listed_category' => 'Jewelry & Watches',
                    'violation' => 'Authenticity concern',
                    'reason' => 'The listing contains authenticity claims that require manual verification before it can remain active.',
                    'reported' => 'Aug 26, 2026',
                    'risk' => 'High',
                    'warnings' => 2,
                    'status' => 'Escalated',
                ],
                [
                    'id' => 'VIO-4110',
                    'product_id' => 'PRD-9947',
                    'product' => 'High-output Laser Pointer',
                    'seller' => 'Gizmo Stop',
                    'registered_category' => 'Electronics & Gadgets',
                    'listed_category' => 'Electronics & Gadgets',
                    'violation' => 'Restricted product review',
                    'reason' => 'The product has been flagged for manual review under marketplace product restrictions.',
                    'reported' => 'Aug 25, 2026',
                    'risk' => 'Medium',
                    'warnings' => 0,
                    'status' => 'Pending Review',
                ],
                [
                    'id' => 'VIO-4111',
                    'product_id' => 'PRD-9952',
                    'product' => 'Misleading 2TB Flash Drive',
                    'seller' => 'Digital Corner PH',
                    'registered_category' => 'Electronics & Gadgets',
                    'listed_category' => 'Electronics & Gadgets',
                    'violation' => 'Misleading product information',
                    'reason' => 'Multiple reports indicate that the advertised storage capacity may not match the actual product specification.',
                    'reported' => 'Aug 24, 2026',
                    'risk' => 'Medium',
                    'warnings' => 1,
                    'status' => 'Under Review',
                ],
            ],
        ]));
    }

    public function returnsRefunds(): View
    {
        return view('admin.compliance.returns-refunds', $this->base([
            'cases' => [
                [
                    'id' => 'REF-2208',
                    'order' => 'ORD-50192',
                    'buyer' => 'Karen Yu',
                    'seller' => 'Mara Home Goods',
                    'reason' => 'Item arrived damaged',
                    'amount' => '₱2,480',
                    'requested' => 'Aug 26, 2026',
                    'type' => 'Return & Refund',
                    'status' => 'Escalated',
                    'seller_response' => 'Seller requested additional parcel photos before approving the return.',
                    'buyer_request' => 'Buyer requests a full refund and return shipping assistance.',
                ],
                [
                    'id' => 'REF-2209',
                    'order' => 'ORD-50188',
                    'buyer' => 'Theo Garcia',
                    'seller' => 'TechVault PH',
                    'reason' => 'Missing accessory',
                    'amount' => '₱1,299',
                    'requested' => 'Aug 25, 2026',
                    'type' => 'Partial Refund',
                    'status' => 'Under Review',
                    'seller_response' => 'Seller confirmed that the accessory may have been omitted during packing.',
                    'buyer_request' => 'Buyer is requesting compensation for the missing accessory.',
                ],
                [
                    'id' => 'REF-2210',
                    'order' => 'ORD-50171',
                    'buyer' => 'Liza Ong',
                    'seller' => 'Everyday Finds',
                    'reason' => 'Wrong item received',
                    'amount' => '₱849',
                    'requested' => 'Aug 25, 2026',
                    'type' => 'Return & Refund',
                    'status' => 'Awaiting Seller',
                    'seller_response' => 'Seller has not yet submitted a final response.',
                    'buyer_request' => 'Buyer requests replacement or full refund after returning the incorrect item.',
                ],
                [
                    'id' => 'REF-2211',
                    'order' => 'ORD-50154',
                    'buyer' => 'Marco Lim',
                    'seller' => 'Chrono Alley',
                    'reason' => 'Product not as described',
                    'amount' => '₱6,390',
                    'requested' => 'Aug 24, 2026',
                    'type' => 'Refund Only',
                    'status' => 'Resolved',
                    'seller_response' => 'Seller agreed to the refund after reviewing the submitted evidence.',
                    'buyer_request' => 'Buyer requested a full refund based on significant differences from the listing.',
                ],
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

    public function messages(): View
    {
        return view('admin.communication.messages', $this->base([
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

    public function announcements(): View
    {
        return view('admin.communication.announcements', $this->base([
            'announcements' => [
                [
                    'id' => 'ANN-1201',
                    'title' => 'Weekend Shipping Advisory',
                    'audience' => 'All Users',
                    'status' => 'Published',
                    'publish_date' => 'Aug 23, 2026',
                    'publish_time' => '9:00 AM',
                    'author' => 'Admin Team',
                    'message' => 'Please expect possible shipping delays this weekend due to scheduled logistics maintenance in selected service areas.',
                ],
                [
                    'id' => 'ANN-1202',
                    'title' => 'Seller Verification Reminder',
                    'audience' => 'Sellers',
                    'status' => 'Scheduled',
                    'publish_date' => 'Aug 25, 2026',
                    'publish_time' => '8:00 AM',
                    'author' => 'Operations Admin',
                    'message' => 'Sellers are reminded to keep business permits and registered product categories updated to avoid compliance issues.',
                ],
                [
                    'id' => 'ANN-1203',
                    'title' => 'Buyer Account Security Notice',
                    'audience' => 'Buyers',
                    'status' => 'Draft',
                    'publish_date' => '—',
                    'publish_time' => '—',
                    'author' => 'Support Admin',
                    'message' => 'Buyers are encouraged to review their account information and avoid sharing verification details with other users.',
                ],
                [
                    'id' => 'ANN-1204',
                    'title' => 'Rider Service Area Update',
                    'audience' => 'Riders / Couriers',
                    'status' => 'Published',
                    'publish_date' => 'Aug 21, 2026',
                    'publish_time' => '2:30 PM',
                    'author' => 'Admin Team',
                    'message' => 'Updated delivery coverage and service-area assignments are now available through participating Logistics Centers.',
                ],
            ],
        ]));
    }

    public function settings(): View
    {
        return view('admin.system.settings', $this->base([
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

    public function policies(): View
    {
        return view('admin.system.policies', $this->base([
            'policies' => [
                [
                    'id' => 'POL-1001',
                    'title' => 'Marketplace Policy',
                    'category' => 'Marketplace',
                    'version' => 'v2.4',
                    'status' => 'Active',
                    'updated' => 'Aug 18, 2026',
                    'updated_by' => 'Admin Team',
                    'summary' => 'Defines the general rules and responsibilities of Buyers, Sellers, Logistics Centers, and Riders using Bearly.',
                    'body' => 'Bearly provides a marketplace where Buyers, Sellers, Logistics Centers, and Riders must follow platform rules, maintain accurate account information, and use platform services responsibly.',
                ],
                [
                    'id' => 'POL-1002',
                    'title' => 'Seller Product Compliance Policy',
                    'category' => 'Seller Compliance',
                    'version' => 'v1.8',
                    'status' => 'Active',
                    'updated' => 'Aug 20, 2026',
                    'updated_by' => 'Operations Admin',
                    'summary' => 'Covers registered seller categories, prohibited products, misleading listings, and compliance enforcement.',
                    'body' => 'Sellers must only offer products permitted under their registered business category. Prohibited, inappropriate, misleading, or non-compliant listings may be removed and may result in warnings, suspension, or account deactivation.',
                ],
                [
                    'id' => 'POL-1003',
                    'title' => 'Returns & Refunds Policy',
                    'category' => 'Transactions',
                    'version' => 'v1.5',
                    'status' => 'Active',
                    'updated' => 'Aug 22, 2026',
                    'updated_by' => 'Support Admin',
                    'summary' => 'Establishes rules for return requests, refund reviews, evidence submission, and escalated cases.',
                    'body' => 'Return and refund requests must include a valid reason and appropriate supporting evidence when required. Escalated cases may be reviewed by an Administrator before a final platform decision is issued.',
                ],
                [
                    'id' => 'POL-1004',
                    'title' => 'Account Conduct Policy',
                    'category' => 'Account',
                    'version' => 'v1.2',
                    'status' => 'Draft',
                    'updated' => 'Aug 24, 2026',
                    'updated_by' => 'Admin Team',
                    'summary' => 'Defines expected user conduct, account security responsibilities, and grounds for suspension or deactivation.',
                    'body' => 'Users must maintain accurate account information, protect their login credentials, and avoid abusive, fraudulent, or prohibited activity while using the platform.',
                ],
            ],
        ]));
    }

    public function auditLogs(): View
    {
        return view('admin.system.audit-logs', $this->base([
            'logs' => [
                [
                    'id' => 'LOG-9001',
                    'admin' => 'Alex Rivera',
                    'role' => 'Super Admin',
                    'action' => 'Approved registration',
                    'module' => 'Registration Management',
                    'target' => 'SEL-3101 • Sofia Luxe Finds',
                    'description' => 'Approved Seller registration after verifying submitted documents.',
                    'date' => 'Aug 26, 2026',
                    'time' => '10:42 AM',
                    'ip' => '192.168.1.18',
                    'severity' => 'Info',
                ],
                [
                    'id' => 'LOG-9002',
                    'admin' => 'Mika Santos',
                    'role' => 'Operations Admin',
                    'action' => 'Issued compliance warning',
                    'module' => 'Compliance & Disputes',
                    'target' => 'Daily Wellness Hub',
                    'description' => 'Issued a warning regarding a product listing outside the Seller\'s registered category.',
                    'date' => 'Aug 26, 2026',
                    'time' => '9:56 AM',
                    'ip' => '192.168.1.21',
                    'severity' => 'Warning',
                ],
                [
                    'id' => 'LOG-9003',
                    'admin' => 'Drew Lim',
                    'role' => 'Support Admin',
                    'action' => 'Resolved dispute',
                    'module' => 'Compliance & Disputes',
                    'target' => 'DSP-1046',
                    'description' => 'Marked complaint case as resolved after reviewing Buyer, Seller, and Rider evidence.',
                    'date' => 'Aug 25, 2026',
                    'time' => '4:31 PM',
                    'ip' => '192.168.1.24',
                    'severity' => 'Info',
                ],
                [
                    'id' => 'LOG-9004',
                    'admin' => 'Alex Rivera',
                    'role' => 'Super Admin',
                    'action' => 'Suspended user account',
                    'module' => 'User Management',
                    'target' => 'SEL-6507 • TechVault PH',
                    'description' => 'Seller account was suspended following repeated compliance violations.',
                    'date' => 'Aug 25, 2026',
                    'time' => '2:18 PM',
                    'ip' => '192.168.1.18',
                    'severity' => 'Critical',
                ],
                [
                    'id' => 'LOG-9005',
                    'admin' => 'Mika Santos',
                    'role' => 'Operations Admin',
                    'action' => 'Published announcement',
                    'module' => 'Communication',
                    'target' => 'ANN-1201 • Weekend Shipping Advisory',
                    'description' => 'Published a platform-wide shipping advisory for all users.',
                    'date' => 'Aug 23, 2026',
                    'time' => '9:00 AM',
                    'ip' => '192.168.1.21',
                    'severity' => 'Info',
                ],
                [
                    'id' => 'LOG-9006',
                    'admin' => 'Alex Rivera',
                    'role' => 'Super Admin',
                    'action' => 'Updated platform policy',
                    'module' => 'System Management',
                    'target' => 'POL-1001 • Marketplace Policy',
                    'description' => 'Published version v2.4 of the Marketplace Policy.',
                    'date' => 'Aug 18, 2026',
                    'time' => '11:15 AM',
                    'ip' => '192.168.1.18',
                    'severity' => 'Info',
                ],
            ],
        ]));
    }
}

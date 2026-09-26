<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AccountApplication;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Models\Dispute;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminController extends Controller
{
    private function base(array $data = []): array
    {
        return array_merge([
            'admin' => [
                'name' => 'Christian Joseph Aquino',
                'role' => 'Admin',
                'email' => 'admin123@example.com',
                'initials' => 'CA',
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

    public function registrations(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $role = (string) $request->query('role');
        $status = (string) $request->query('status');
        $reviewableStatuses = ['submitted', 'under_review', 'needs_revision'];

        $applications = AccountApplication::query()
            ->with(['user', 'requestedRole', 'businessCategory', 'documents'])
            ->whereIn('status', $reviewableStatuses)
            ->whereHas('requestedRole', fn ($query) => $query->whereIn('name', UserRole::adminApproved()))
            ->whereHas('user', fn ($query) => $query->whereIn('status', [
                AccountStatus::Pending->value,
                AccountStatus::NeedsRevision->value,
            ]))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('application_no', 'like', "%{$search}%")
                        ->orWhere('business_name', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($userQuery) => $userQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"));
                });
            })
            ->when(in_array($role, UserRole::adminApproved(), true), fn ($query) => $query
                ->whereHas('requestedRole', fn ($roleQuery) => $roleQuery->where('name', $role)))
            ->when(in_array($status, $reviewableStatuses, true), fn ($query) => $query->where('status', $status))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        return view('admin.registrations.index', $this->base([
            'applications' => $applications,
            'filters' => compact('search', 'role', 'status'),
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
        $accounts = User::query()
            ->whereIn('role', ['buyer', 'seller', 'logistics', 'rider'])
            ->whereIn('status', ['active', 'suspended', 'deactivated'])
            ->latest('created_at')
            ->get();

        return view('admin.users.index', $this->base([
            'users' => $accounts->map(fn (User $user) => [
                'id' => 'USR-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
                'name' => $user->name,
                'email' => $user->email,
                'role' => ucfirst($user->role),
                'joined' => $user->created_at?->format('M d, Y') ?? '—',
                'status' => ucfirst(str_replace('_', ' ', $user->status)),
            ])->all(),
            'userStats' => [
                'total' => $accounts->count(),
                'active' => $accounts->where('status', 'active')->count(),
                'suspended' => $accounts->where('status', 'suspended')->count(),
                'deactivated' => $accounts->where('status', 'deactivated')->count(),
            ],
        ]));
    }

    public function buyerUsers(): View
    {
        $buyers = User::query()
            ->where('role', UserRole::Buyer->value)
            ->whereIn('status', ['active', 'suspended', 'deactivated'])
            ->withCount('buyerOrders')
            ->latest('created_at')
            ->get();

        return view('admin.users.buyers', $this->base([
            'users' => $buyers->map(fn (User $user) => [
                'id' => 'BUY-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
                'name' => $user->name,
                'email' => $user->email,
                'contact' => $user->contact_number ?: '—',
                'location' => collect([$user->city, $user->province])->filter()->implode(', ') ?: '—',
                'joined' => $user->created_at?->format('M d, Y') ?? '—',
                'orders' => $user->buyer_orders_count,
                'status' => ucfirst(str_replace('_', ' ', $user->status)),
            ])->all(),
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
        $records = Dispute::query()
            ->with([
                'sellerOrder.store',
                'returnRequest',
                'shipment',
                'opener',
                'assignee',
                'participants',
                'evidence.uploader',
                'events.actor',
            ])
            ->whereNotIn('status', [
                'resolved',
                'closed',
            ])
            ->orderByRaw("
                CASE priority
                    WHEN 'urgent' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'medium' THEN 3
                    WHEN 'normal' THEN 4
                    WHEN 'low' THEN 5
                    ELSE 6
                END
            ")
            ->orderBy('response_due_at')
            ->orderByDesc('opened_at')
            ->get();

        $disputes = $records
            ->map(function (Dispute $dispute): array {
                $buyer = $dispute->participants
                    ->first(
                        fn ($participant) =>
                            $participant->pivot->participant_role === 'buyer'
                    );

                $seller = $dispute->participants
                    ->first(
                        fn ($participant) =>
                            $participant->pivot->participant_role === 'seller'
                    );

                $rider = $dispute->participants
                    ->first(
                        fn ($participant) =>
                            $participant->pivot->participant_role === 'rider'
                    );

                $logistics = $dispute->participants
                    ->first(
                        fn ($participant) =>
                            $participant->pivot->participant_role === 'logistics'
                    );

                $priority = Str::of($dispute->priority)
                    ->replace(['_', '-'], ' ')
                    ->title()
                    ->toString();

                $status = match ($dispute->status) {
                    'open' => 'Open',
                    'under_review' => 'Under Review',
                    'awaiting_buyer' => 'Awaiting Buyer',
                    'awaiting_seller' => 'Awaiting Seller',
                    'awaiting_logistics' => 'Awaiting Logistics',
                    'awaiting_evidence' => 'Awaiting Evidence',
                    'coordinating' => 'Coordinating',
                    'escalated' => 'Escalated',
                    'resolved' => 'Resolved',
                    'closed' => 'Closed',

                    default => Str::of($dispute->status)
                        ->replace(['_', '-'], ' ')
                        ->title()
                        ->toString(),
                };

                $evidence = $dispute->evidence
                    ->sortBy('created_at')
                    ->values()
                    ->map(function ($item): array {
                        $type = Str::lower((string) $item->type);

                        return [
                            'id' =>
                                $item->id,

                            'label' =>
                                $item->description
                                    ?: $item->original_name,

                            'type' =>
                                Str::contains($type, [
                                    'image',
                                    'photo',
                                    'picture',
                                ])
                                    ? 'Image'
                                    : 'Document',

                            'meta' =>
                                collect([
                                    $item->original_name,
                                    $item->uploader?->name,
                                ])
                                    ->filter()
                                    ->implode(' • '),

                            'download_url' =>
                                route(
                                    'admin.disputes.evidence.download',
                                    $item
                                ),
                        ];
                    })
                    ->all();

                $timeline = $dispute->events
                    ->sortBy('created_at')
                    ->values()
                    ->map(function ($event): array {
                        $eventLabel = Str::of(
                            $event->event_type
                        )
                            ->replace(['_', '-'], ' ')
                            ->title()
                            ->toString();

                        $actor = $event->actor?->name;

                        $text = $event->note;

                        if (! $text) {
                            $text = $actor
                                ? "{$actor}: {$eventLabel}"
                                : $eventLabel;
                        }

                        return [
                            'time' => $event->created_at
                                ?->format('g:i A')
                                ?? '—',

                            'text' => $text,
                        ];
                    })
                    ->all();

                /*
                * Internal notes belong to dispute_events,
                * not the final resolution column.
                */
                $latestInternalNote = $dispute->events
                    ->where('event_type', 'internal_note')
                    ->sortByDesc('created_at')
                    ->first();

                /*
                * Resolution outcome is stored in the
                * metadata of the resolved event.
                */
                $resolutionEvent = $dispute->events
                    ->where('event_type', 'resolved')
                    ->sortByDesc('created_at')
                    ->first();

                return [
                    'database_id' => $dispute->id,

                    'id' => $dispute->dispute_no,

                    'subject' => $dispute->subject,

                    'buyer' => $buyer?->name
                        ?? (
                            $dispute->opener?->role === 'buyer'
                                ? $dispute->opener->name
                                : '—'
                        ),

                    'buyer_user_id' =>
                        $buyer?->id
                        ?? (
                            $dispute->opener?->role === 'buyer'
                                ? $dispute->opener?->id
                                : null
                        ),

                    'seller' => $dispute->sellerOrder?->store?->name
                        ?? $seller?->name
                        ?? '—',

                    'seller_user_id' =>
                        $seller?->id,

                    'courier' => $rider?->name
                        ?? $logistics?->name
                        ?? '—',

                    'rider_user_id' =>
                        $rider?->id,

                    'logistics_user_id' =>
                        $logistics?->id,

                    'amount' => $dispute->amount_minor !== null
                        ? '₱'.number_format(
                            $dispute->amount_minor / 100,
                            2
                        )
                        : '—',

                    'priority' => $priority,

                    'status' => $status,

                    'opened' => $dispute->opened_at
                        ?->format('M j, g:i A')
                        ?? '—',

                    'summary' => $dispute->description,

                    'assignee' => $dispute->assignee?->name
                        ?? 'Unassigned',

                    'response_due' => $dispute->response_due_at
                        ?->format('M j, g:i A'),

                    'resolution' => $dispute->resolution,

                    /*
                    * These URLs are intentionally generated
                    * directly for now so this page can still
                    * render before we paste the new routes.
                    */
                    'note_url' => url(
                        "/admin/disputes/{$dispute->id}/notes"
                    ),

                    'resolve_url' => url(
                        "/admin/disputes/{$dispute->id}/resolve"
                    ),

                    'message_url' => route(
                        'admin.messages.disputes.open',
                        $dispute
                    ),

                    'update_url' => route(
                        'admin.disputes.update',
                        $dispute
                    ),

                    'evidence' => $evidence,

                    'timeline' => $timeline,

                    'internalNote' => $latestInternalNote?->note
                        ?? '',

                    'resolutionOutcome' =>
                        $resolutionEvent?->metadata['outcome']
                        ?? '',
                ];
            })
            ->values()
            ->all();

        $firstDispute = $disputes[0] ?? null;

        return view(
            'admin.compliance.disputes',
            $this->base([
                'disputes' => $disputes,
                'evidence' => $firstDispute['evidence'] ?? [],
                'timeline' => $firstDispute['timeline'] ?? [],
            ])
        );
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
        $returnRequests = ReturnRequest::query()
            ->with([
                'order',
                'buyer',
                'store',
                'sellerOrder',
                'items.orderItem',
                'evidence',
                'reviewer',
                'refunds',
            ])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();

        $cases = $returnRequests
            ->map(function (ReturnRequest $returnRequest): array {
                $requestType = match ($returnRequest->request_type) {
                    'return_refund',
                    'return_and_refund',
                    'return & refund' => 'Return & Refund',

                    'partial_refund',
                    'partial refund' => 'Partial Refund',

                    'refund_only',
                    'refund only' => 'Refund Only',

                    default => Str::of($returnRequest->request_type)
                        ->replace(['_', '-'], ' ')
                        ->title()
                        ->toString(),
                };

                $status = match ($returnRequest->status) {
                    'submitted' => 'Awaiting Seller',
                    'awaiting_seller' => 'Awaiting Seller',
                    'under_review' => 'Under Review',
                    'escalated' => 'Escalated',
                    'awaiting_evidence' => 'Awaiting Evidence',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                    'resolved' => 'Resolved',

                    default => Str::of($returnRequest->status)
                        ->replace(['_', '-'], ' ')
                        ->title()
                        ->toString(),
                };

                $reason = Str::of($returnRequest->reason_code)
                    ->replace(['_', '-'], ' ')
                    ->title()
                    ->toString();

                return [
                    'database_id' => $returnRequest->id,

                    'id' => $returnRequest->return_no,

                    'order' => $returnRequest->order?->order_no
                        ?? 'Order unavailable',

                    'buyer' => $returnRequest->buyer?->name
                        ?? 'Buyer unavailable',

                    'seller' => $returnRequest->store?->name
                        ?? 'Seller unavailable',

                    'reason' => $reason,

                    'amount' => '₱'.number_format(
                        $returnRequest->requested_amount_minor / 100,
                        2
                    ),

                    'requested' => $returnRequest->submitted_at
                        ?->format('M j, Y')
                        ?? '—',

                    'type' => $requestType,

                    'status' => $status,

                    'seller_response' => $returnRequest->seller_response
                        ?: 'No seller response submitted yet.',

                    'buyer_request' => $returnRequest->buyer_note
                        ?: 'No additional buyer note was provided.',
                ];
            })
            ->values()
            ->all();

        return view(
            'admin.compliance.returns-refunds',
            $this->base([
                'cases' => $cases,
            ])
        );
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

        return view('admin.finance.commissions', $this->base([
            'rate' => 10,
            'ledger' => $ledger,
        ]));
    }

    public function transactions(): View
    {
        $transactions = [
            ['id' => 'TXN-260824-0192', 'date' => '2026-08-24', 'order' => 'ORD-50192', 'seller' => 'Mara Home Goods', 'buyer' => 'Karen Yu', 'method' => 'GCash', 'gross' => 2480.00, 'refund' => 0.00, 'status' => 'Completed'],
            ['id' => 'TXN-260824-0188', 'date' => '2026-08-24', 'order' => 'ORD-50188', 'seller' => 'Chrono Alley', 'buyer' => 'Marco Lim', 'method' => 'Card', 'gross' => 6390.00, 'refund' => 0.00, 'status' => 'Completed'],
            ['id' => 'TXN-260823-0171', 'date' => '2026-08-23', 'order' => 'ORD-50171', 'seller' => 'Everyday Finds', 'buyer' => 'Bianca Lim', 'method' => 'Cash on Delivery', 'gross' => 1299.00, 'refund' => 0.00, 'status' => 'Pending'],
            ['id' => 'TXN-260823-0154', 'date' => '2026-08-23', 'order' => 'ORD-50154', 'seller' => 'TechVault PH', 'buyer' => 'Paolo Reyes', 'method' => 'Maya', 'gross' => 4299.00, 'refund' => 4299.00, 'status' => 'Refunded'],
            ['id' => 'TXN-260822-0111', 'date' => '2026-08-22', 'order' => 'ORD-50111', 'seller' => 'Mara Home Goods', 'buyer' => 'Angela Torres', 'method' => 'GCash', 'gross' => 3190.00, 'refund' => 0.00, 'status' => 'Completed'],
            ['id' => 'TXN-260821-0097', 'date' => '2026-08-21', 'order' => 'ORD-50097', 'seller' => 'Everyday Finds', 'buyer' => 'Carlo Reyes', 'method' => 'Card', 'gross' => 1875.00, 'refund' => 0.00, 'status' => 'Failed'],
        ];

        $transactions = array_map(function ($row) {
            $commissionBase = $row['status'] === 'Completed'
                ? max(0, $row['gross'] - $row['refund'])
                : 0;
            $row['commission'] = $commissionBase * 0.10;
            $row['sellerNet'] = $commissionBase * 0.90;

            return $row;
        }, $transactions);

        return view('admin.finance.transactions', $this->base([
            'rate' => 10,
            'transactions' => $transactions,
        ]));
    }

    public function payments(): View
    {
        return view('admin.finance.payments', $this->base([
            'payments' => [
                ['id' => 'PAY-2608-041', 'seller' => 'Mara Home Goods', 'period' => 'Aug 16–22, 2026', 'gross' => 184210.00, 'commission' => 18421.00, 'adjustments' => -1250.00, 'net' => 164539.00, 'due' => '2026-08-26', 'paid' => null, 'reference' => '—', 'status' => 'Pending'],
                ['id' => 'PAY-2608-040', 'seller' => 'Chrono Alley', 'period' => 'Aug 16–22, 2026', 'gross' => 142880.00, 'commission' => 14288.00, 'adjustments' => 0.00, 'net' => 128592.00, 'due' => '2026-08-26', 'paid' => null, 'reference' => '—', 'status' => 'Processing'],
                ['id' => 'PAY-2608-039', 'seller' => 'Everyday Finds', 'period' => 'Aug 9–15, 2026', 'gross' => 119520.00, 'commission' => 11952.00, 'adjustments' => -640.00, 'net' => 106928.00, 'due' => '2026-08-19', 'paid' => '2026-08-19', 'reference' => 'BNK-849301', 'status' => 'Paid'],
                ['id' => 'PAY-2608-038', 'seller' => 'TechVault PH', 'period' => 'Aug 9–15, 2026', 'gross' => 96410.00, 'commission' => 9641.00, 'adjustments' => -4299.00, 'net' => 82470.00, 'due' => '2026-08-19', 'paid' => null, 'reference' => '—', 'status' => 'On Hold'],
                ['id' => 'PAY-2608-037', 'seller' => 'Mara Home Goods', 'period' => 'Aug 9–15, 2026', 'gross' => 156780.00, 'commission' => 15678.00, 'adjustments' => 0.00, 'net' => 141102.00, 'due' => '2026-08-19', 'paid' => '2026-08-18', 'reference' => 'BNK-848775', 'status' => 'Paid'],
            ],
        ]));
    }

    public function reports(): View
    {
        return view('admin.finance.reports', $this->base([
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
            'settlementRows' => [
                ['seller' => 'Mara Home Goods', 'period' => 'Aug 16–22, 2026', 'net' => '₱164,539', 'status' => 'Pending'],
                ['seller' => 'Chrono Alley', 'period' => 'Aug 16–22, 2026', 'net' => '₱128,592', 'status' => 'Processing'],
                ['seller' => 'Everyday Finds', 'period' => 'Aug 9–15, 2026', 'net' => '₱106,928', 'status' => 'Paid'],
            ],
            'refundRows' => [
                ['case' => 'REF-2211', 'order' => 'ORD-50154', 'seller' => 'TechVault PH', 'amount' => '₱4,299', 'status' => 'Approved'],
                ['case' => 'REF-2209', 'order' => 'ORD-50132', 'seller' => 'Everyday Finds', 'amount' => '₱640', 'status' => 'Processing'],
                ['case' => 'REF-2204', 'order' => 'ORD-50088', 'seller' => 'Mara Home Goods', 'amount' => '₱1,250', 'status' => 'Completed'],
            ],
        ]));
    }

    public function messages(Request $request): View
    {
        $admin = $request->user();

        $recipients = User::query()
            ->where('id', '!=', $admin->id)
            ->where('status', 'active')
            ->whereHas('roles', function ($query) {
                $query->whereIn('name', [
                    'buyer',
                    'seller',
                    'logistics',
                    'rider',
                ]);
            })
            ->with([
                'roles:id,name',
                'sellerProfile.store',
                'logisticsProfile',
                'riderProfile',
            ])
            ->orderBy('name')
            ->get()
            ->map(function (User $user): array {
                $role = $user->roles
                    ->first(
                        fn ($role) => in_array(
                            $role->name,
                            [
                                'buyer',
                                'seller',
                                'logistics',
                                'rider',
                            ],
                            true
                        )
                    )
                    ?->name
                    ?? $user->role
                    ?? 'user';

                $displayName = match ($role) {
                    'seller' =>
                        $user->sellerProfile?->store?->name
                        ?? $user->name,

                    'logistics' =>
                        $user->logisticsProfile?->center_name
                        ?? $user->name,

                    default =>
                        $user->name,
                };

                return [
                    'id' => $user->id,
                    'name' => $displayName,
                    'account_name' => $user->name,
                    'email' => $user->email,
                    'role' => $role,
                ];
            })
            ->values()
            ->all();

        $records = Conversation::query()
            ->with([
                'creator',
                'participants.sellerProfile.store',
                'latestMessage.sender',
                'messages' => fn ($query) => $query
                    ->with([
                        'sender',
                        'attachments',
                    ])
                    ->orderBy('sent_at'),
            ])
            ->whereHas(
                'participants',
                fn ($query) => $query->where(
                    'users.id',
                    $admin->id
                )
            )
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->get();

        $conversations = $records
            ->map(function (Conversation $conversation) use ($admin): array {
                $adminParticipant = $conversation
                    ->participants
                    ->firstWhere('id', $admin->id);

                $otherParticipant = $conversation
                    ->participants
                    ->first(
                        fn ($participant) =>
                            $participant->id !== $admin->id
                    );

                $participantRole = $otherParticipant
                    ?->pivot
                    ?->participant_role
                    ?? $otherParticipant?->role
                    ?? 'User';

                $role = Str::of($participantRole)
                    ->replace(['_', '-'], ' ')
                    ->title()
                    ->toString();

                /*
                * Seller conversations should show the store
                * name when one exists. Other roles use the
                * participant's account name.
                */
                $name = $participantRole === 'seller'
                    ? (
                        $otherParticipant
                            ?->sellerProfile
                            ?->store
                            ?->name
                        ?? $otherParticipant?->name
                        ?? 'Unknown Seller'
                    )
                    : (
                        $otherParticipant?->name
                        ?? 'Unknown User'
                    );

                $initials = collect(
                    preg_split(
                        '/\s+/',
                        trim($name)
                    ) ?: []
                )
                    ->filter()
                    ->take(2)
                    ->map(
                        fn ($part) => Str::upper(
                            Str::substr($part, 0, 1)
                        )
                    )
                    ->implode('');

                $latestMessage = $conversation->latestMessage;

                /*
                * Count inbound messages newer than the
                * Admin participant's last-read timestamp.
                */
                $lastReadAt = $adminParticipant
                    ?->pivot
                    ?->last_read_at;

                $unread = $conversation
                    ->messages
                    ->filter(function ($message) use (
                        $admin,
                        $lastReadAt
                    ): bool {
                        if ($message->sender_id === $admin->id) {
                            return false;
                        }

                        if (! $lastReadAt) {
                            return true;
                        }

                        return $message->sent_at
                            && $message->sent_at->isAfter(
                                \Illuminate\Support\Carbon::parse(
                                    $lastReadAt
                                )
                            );
                    })
                    ->count();

                return [
                    'database_id' => $conversation->id,

                    'id' => $conversation->id,

                    'name' => $name,

                    'role' => $role,

                    'preview' => $latestMessage?->body
                        ?? 'No messages yet.',

                    'time' => $latestMessage?->sent_at
                        ?->format('g:i A')
                        ?? '',

                    'unread' => $unread,

                    'initials' => $initials ?: '—',

                    'subject' => $conversation->subject,

                    'status' => $conversation->status,

                    'type' => $conversation->type,

                    'messages' => $conversation
                        ->messages
                        ->map(
                            fn ($message) => [
                                'id' => $message->id,

                                'from' => $message->sender_id === $admin->id
                                    ? 'me'
                                    : 'them',

                                'text' => $message->body,

                                'time' => $message->sent_at
                                    ?->format('g:i A')
                                    ?? '',

                                'message_type' =>
                                    $message->message_type,

                                'attachments' =>
                                    $message->attachments
                                        ->map(
                                            fn ($attachment) => [
                                                'id' => $attachment->id,

                                                'name' =>
                                                    $attachment->original_name,

                                                'mime_type' =>
                                                    $attachment->mime_type,

                                                'size_bytes' =>
                                                    $attachment->size_bytes,

                                                'download_url' =>
                                                    route(
                                                        'admin.messages.attachments.download',
                                                        $attachment
                                                    ),
                                            ]
                                        )
                                        ->values()
                                        ->all(),
                            ]
                        )
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        $requestedConversationId =
            (int) $request->query(
                'conversation',
                0
            );

        $activeConversation =
            $requestedConversationId > 0
                ? collect($conversations)
                    ->first(
                        fn ($conversation) =>
                            (int) $conversation['database_id']
                            === $requestedConversationId
                    )
                : null;

        $activeConversation ??=
            $conversations[0] ?? null;

        return view(
            'admin.communication.messages',
            $this->base([
                'conversations' => $conversations,

                'messages' =>
                    $activeConversation['messages']
                    ?? [],

                'activeConversation' =>
                    $activeConversation,

                'recipients' =>
                    $recipients,
            ])
        );
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
                    'author' => 'Admin',
                    'message' => 'Please expect possible shipping delays this weekend due to scheduled logistics maintenance in selected service areas.',
                ],
                [
                    'id' => 'ANN-1202',
                    'title' => 'Seller Verification Reminder',
                    'audience' => 'Sellers',
                    'status' => 'Scheduled',
                    'publish_date' => 'Aug 25, 2026',
                    'publish_time' => '8:00 AM',
                    'author' => 'Admin',
                    'message' => 'Sellers are reminded to keep business permits and registered product categories updated to avoid compliance issues.',
                ],
                [
                    'id' => 'ANN-1203',
                    'title' => 'Buyer Account Security Notice',
                    'audience' => 'Buyers',
                    'status' => 'Draft',
                    'publish_date' => '—',
                    'publish_time' => '—',
                    'author' => 'Admin',
                    'message' => 'Buyers are encouraged to review their account information and avoid sharing verification details with other users.',
                ],
                [
                    'id' => 'ANN-1204',
                    'title' => 'Rider Service Area Update',
                    'audience' => 'Riders',
                    'status' => 'Published',
                    'publish_date' => 'Aug 21, 2026',
                    'publish_time' => '2:30 PM',
                    'author' => 'Admin',
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
                'body' => 'Bearly connects buyers, sellers, Logistics partners, and Riders through a trusted marketplace. Sellers are responsible for accurate listings, compliant products, and timely order fulfillment. Users must keep account information current and use platform communication tools responsibly.',
            ],
        ]));
    }

    public function account(): View
    {
        return view('admin.system.account', $this->base([
            'profile' => [
                'first_name' => 'Christian Joseph',
                'last_name' => 'Aquino',
                'email' => 'admin123@example.com',
                'phone' => '+63 917 555 0198',
                'role' => 'Admin',
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
                    'updated_by' => 'Admin',
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
                    'updated_by' => 'Admin',
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
                    'updated_by' => 'Admin',
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
                    'updated_by' => 'Admin',
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
                    'admin' => 'Christian Joseph Aquino',
                    'role' => 'Admin',
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
                    'admin' => 'Christian Joseph Aquino',
                    'role' => 'Admin',
                    'action' => 'Issued compliance warning',
                    'module' => 'Compliance & Disputes',
                    'target' => 'Daily Wellness Hub',
                    'description' => 'Issued a warning regarding a product listing outside the Seller\'s registered category.',
                    'date' => 'Aug 26, 2026',
                    'time' => '9:56 AM',
                    'ip' => '192.168.1.18',
                    'severity' => 'Warning',
                ],
                [
                    'id' => 'LOG-9003',
                    'admin' => 'Christian Joseph Aquino',
                    'role' => 'Admin',
                    'action' => 'Resolved dispute',
                    'module' => 'Compliance & Disputes',
                    'target' => 'DSP-1046',
                    'description' => 'Marked complaint case as resolved after reviewing Buyer, Seller, and Rider evidence.',
                    'date' => 'Aug 25, 2026',
                    'time' => '4:31 PM',
                    'ip' => '192.168.1.18',
                    'severity' => 'Info',
                ],
                [
                    'id' => 'LOG-9004',
                    'admin' => 'Christian Joseph Aquino',
                    'role' => 'Admin',
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
                    'admin' => 'Christian Joseph Aquino',
                    'role' => 'Admin',
                    'action' => 'Published announcement',
                    'module' => 'Communication',
                    'target' => 'ANN-1201 • Weekend Shipping Advisory',
                    'description' => 'Published a platform-wide shipping advisory for all users.',
                    'date' => 'Aug 23, 2026',
                    'time' => '9:00 AM',
                    'ip' => '192.168.1.18',
                    'severity' => 'Info',
                ],
                [
                    'id' => 'LOG-9006',
                    'admin' => 'Christian Joseph Aquino',
                    'role' => 'Admin',
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

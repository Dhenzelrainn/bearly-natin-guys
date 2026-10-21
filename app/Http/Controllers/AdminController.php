<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AccountApplication;
use App\Models\Conversation;
use App\Models\Dispute;
use App\Models\ProductViolation;
use App\Models\ReturnRequest;
use App\Models\SellerPayout;
use App\Models\User;
use App\Services\BuyerCatalogService;
use App\Services\ConversationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminController extends Controller
{
    private function base(array $data = []): array
    {
        return array_merge([
            'admin' => $this->adminIdentity(),
            'topNotifications' => [],
        ], $data);
    }

    /**
     * @return array<string, string>
     */
    private function adminIdentity(): array
    {
        $user = Auth::user();

        $name = trim((string) (
            $user?->name
            ?: trim(implode(' ', array_filter([
                $user?->first_name,
                $user?->last_name,
            ])))
        ));

        if ($name === '') {
            $name = 'Bearly Administrator';
        }

        $initials = collect(preg_split('/\s+/', $name) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $part) => strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        return [
            'name' => $name,
            'role' => 'Admin',
            'email' => (string) ($user?->email ?? ''),
            'initials' => $initials !== '' ? $initials : 'AD',
        ];
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

        $applications->through(function (AccountApplication $application): array {
            return [
                'database_id' => $application->user_id,
                'application_id' => $application->id,

                'id' => $application->application_no,

                'name' => $application->user?->name ?? 'Unknown applicant',
                'email' => $application->user?->email ?? '—',

                'role' => str($application->requestedRole?->name ?? 'unknown')
                    ->replace('_', ' ')
                    ->title()
                    ->toString(),

                'category' => $application->businessCategory?->name ?? '—',

                'submitted' => $application->submitted_at?->format('M j, Y')
                    ?? 'Not submitted',

                'status' => str($application->status)
                    ->replace('_', ' ')
                    ->title()
                    ->toString(),

                'documents' => $application->documents
                    ->map(fn ($document): array => [
                        'id' => $document->id,

                        'type' => $document->document_type,

                        'label' => str($document->document_type)
                            ->replace('_', ' ')
                            ->title()
                            ->toString(),
                    ])
                    ->all(),
            ];
        });

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
                        fn ($participant) => $participant->pivot->participant_role === 'buyer'
                    );

                $seller = $dispute->participants
                    ->first(
                        fn ($participant) => $participant->pivot->participant_role === 'seller'
                    );

                $rider = $dispute->participants
                    ->first(
                        fn ($participant) => $participant->pivot->participant_role === 'rider'
                    );

                $logistics = $dispute->participants
                    ->first(
                        fn ($participant) => $participant->pivot->participant_role === 'logistics'
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
                            'id' => $item->id,

                            'label' => $item->description
                                    ?: $item->original_name,

                            'type' => Str::contains($type, [
                                'image',
                                'photo',
                                'picture',
                            ])
                                    ? 'Image'
                                    : 'Document',

                            'meta' => collect([
                                $item->original_name,
                                $item->uploader?->name,
                            ])
                                ->filter()
                                ->implode(' • '),

                            'download_url' => route(
                                'admin.disputes.evidence.download',
                                $item
                            ),

                            'preview_url' => $type === 'image'
                                ? route(
                                    'admin.disputes.evidence.preview',
                                    $item
                                )
                                : null,
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

                    'buyer_user_id' => $buyer?->id
                        ?? (
                            $dispute->opener?->role === 'buyer'
                                ? $dispute->opener?->id
                                : null
                        ),

                    'seller' => $dispute->sellerOrder?->store?->name
                        ?? $seller?->name
                        ?? '—',

                    'seller_user_id' => $seller?->id,

                    'courier' => $rider?->name
                        ?? $logistics?->name
                        ?? '—',

                    'rider' => $rider?->name ?? 'Unavailable',

                    'logistics' => $logistics?->name ?? 'Unavailable',

                    'rider_user_id' => $rider?->id,

                    'logistics_user_id' => $logistics?->id,

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

                    'resolutionOutcome' => $resolutionEvent?->metadata['outcome']
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

    public function productViolations(Request $request): View
    {
        $openStatuses = ['flagged', 'under_review', 'correction_requested'];
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'risk' => ['nullable', Rule::in(['critical', 'high', 'medium', 'low'])],
            'status' => ['nullable', Rule::in([
                'flagged',
                'under_review',
                'correction_requested',
                'confirmed',
                'corrective_action',
                'dismissed',
                'resolved',
            ])],
        ]);

        $query = ProductViolation::query()
            ->with([
                'product' => fn ($query) => $query->withTrashed(),
                'product.store' => fn ($query) => $query->withTrashed(),
                'product.category',
                'check',
                'rule',
                'sellerProfile.approvedCategory',
                'sellerProfile.warnings' => fn ($query) => $query->where('status', 'active'),
                'actions.actor',
            ]);

        if (filled($filters['search'] ?? null)) {
            $search = trim($filters['search']);
            $like = '%'.$search.'%';

            $query->where(function ($violations) use ($like): void {
                $violations
                    ->where('violation_no', 'like', $like)
                    ->orWhere('violation_type', 'like', $like)
                    ->orWhere('reason', 'like', $like)
                    ->orWhere('detected_excerpt', 'like', $like)
                    ->orWhereHas('product', fn ($products) => $products
                        ->withTrashed()
                        ->where('name', 'like', $like))
                    ->orWhereHas('product.store', fn ($stores) => $stores
                        ->withTrashed()
                        ->where('name', 'like', $like));
            });
        }

        $query
            ->when(isset($filters['risk']) && $filters['risk'] !== '', fn ($violations) => $violations->where('severity', $filters['risk']))
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($violations) => $violations->where('status', $filters['status']));

        $violations = $query
            ->latest('created_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(function (ProductViolation $violation) use ($openStatuses): array {
                $status = $violation->status;
                $statusLabel = match ($status) {
                    'flagged' => 'Pending Review',
                    'under_review' => 'Under Review',
                    'correction_requested' => 'Correction Requested',
                    'confirmed' => 'Confirmed',
                    'dismissed' => 'Dismissed',
                    'corrective_action' => 'Corrective Action',
                    'resolved' => 'Resolved',
                    default => Str::of($status)->replace(['_', '-'], ' ')->title()->toString(),
                };

                $statusClass = match ($status) {
                    'flagged' => 'badge-warning',
                    'under_review', 'correction_requested' => 'badge-info',
                    'confirmed', 'corrective_action' => 'badge-danger',
                    'dismissed', 'resolved' => 'badge-success',
                    default => 'badge-neutral',
                };
                $product = $violation->product;
                $sellerProfile = $violation->sellerProfile;

                return [
                    'database_id' => $violation->id,
                    'id' => $violation->violation_no,
                    'product_id' => 'PRD-'.str_pad((string) $violation->product_id, 6, '0', STR_PAD_LEFT),
                    'product' => $product?->name ?? $violation->check?->name_snapshot ?? 'Product record unavailable',
                    'product_status' => $product?->product_status ?? 'unavailable',
                    'compliance_status' => $product?->compliance_status ?? 'unavailable',
                    'seller' => $product?->store?->name ?? $sellerProfile?->legal_business_name ?? 'Seller record unavailable',
                    'registered_category' => $sellerProfile?->approvedCategory?->name ?? '—',
                    'listed_category' => $product?->category?->name ?? '—',
                    'violation' => $violation->rule?->name
                        ?? Str::of($violation->violation_type)->replace(['_', '-'], ' ')->title()->toString(),
                    'source' => Str::of($violation->source)->replace(['_', '-'], ' ')->title()->toString(),
                    'reason' => $violation->reason,
                    'detected_excerpt' => $violation->detected_excerpt,
                    'content_mismatch' => $violation->detected_excerpt !== null
                        && $violation->rule?->rule_type === 'category_mismatch',
                    'reported' => $violation->created_at?->format('M j, Y') ?? '—',
                    'risk' => Str::title($violation->severity),
                    'warnings' => $sellerProfile?->warnings?->count() ?? 0,
                    'status' => $statusLabel,
                    'status_value' => $status,
                    'status_class' => $statusClass,
                    'actionable' => in_array($status, $openStatuses, true),
                    'actions' => $violation->actions->map(fn ($action): array => [
                        'label' => Str::of($action->action_type)->replace(['_', '-'], ' ')->title()->toString(),
                        'actor' => $action->actor?->name ?? 'Admin account unavailable',
                        'reason' => $action->reason ?: 'No reason recorded.',
                        'created_at' => $action->created_at?->format('M j, Y g:i A') ?? '—',
                    ])->all(),
                ];
            });

        return view('admin.compliance.violations', $this->base([
            'violations' => $violations,
            'openViolationCount' => ProductViolation::query()
                ->whereIn('status', $openStatuses)
                ->count(),
        ]));
    }

    public function returnsRefunds(BuyerCatalogService $catalog): View
    {
        $returnRequests = ReturnRequest::query()
            ->with([
                'order',
                'buyer',
                'store',
                'store.sellerProfile',
                'sellerOrder',
                'items.orderItem.product.images.variant',
                'items.orderItem.variant',
                'evidence',
                'reviewer',
                'refunds',
                'refunds.sellerRecovery.events.sellerPayout',
                'refunds.sellerRecovery.events.recorder',
                'events.actor',
                'returnShipment.waybill',
                'returnShipment.events',
            ])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();

        $sellerProfileIds = $returnRequests
            ->pluck('store.seller_profile_id')
            ->filter()
            ->unique()
            ->values();
        $paidSellerPayouts = SellerPayout::query()
            ->whereIn('seller_profile_id', $sellerProfileIds)
            ->whereIn('status', ['paid', 'completed'])
            ->whereNotNull('paid_at')
            ->latest('paid_at')
            ->get()
            ->groupBy('seller_profile_id');

        $cases = $returnRequests
            ->map(function (ReturnRequest $returnRequest) use ($paidSellerPayouts, $catalog): array {
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
                    'awaiting_seller' => 'Awaiting Seller',
                    'under_review' => 'Under Review',
                    'awaiting_evidence' => 'Awaiting Evidence',
                    'return_pickup_requested' => 'Return Pickup Requested',
                    'return_in_transit' => 'Return In Transit',
                    'awaiting_inspection' => 'Awaiting Seller Inspection',
                    'refund_pending' => 'Refund Payout Pending',
                    'resolved' => 'Resolved',
                    'rejected' => 'Rejected',
                    default => Str::of($returnRequest->status)->replace(['_', '-'], ' ')->title()->toString(),
                };

                $reason = Str::of($returnRequest->reason_code)
                    ->replace(['_', '-'], ' ')
                    ->title()
                    ->toString();

                $refund = $returnRequest->refunds->sortByDesc('id')->first();
                $items = $returnRequest->items->map(fn ($item): array => [
                    'name' => $item->orderItem?->product_name ?? 'Purchased item',
                    'variant' => $item->orderItem?->variant_name ?? 'Standard',
                    'sku' => $item->orderItem?->sku ?? '—',
                    'quantity' => (int) $item->quantity,
                    'amount' => '₱'.number_format((int) $item->refund_amount_minor / 100, 2),
                    'image_url' => $item->orderItem
                        ? $catalog->orderItemImageUrl($item->orderItem)
                        : '',
                ])->all();

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
                    'status_key' => $returnRequest->status,

                    'seller_response' => $returnRequest->seller_response
                        ?: 'No seller response submitted yet.',

                    'buyer_request' => $returnRequest->buyer_note
                        ?: 'No additional buyer note was provided.',
                    'submitted_at' => $returnRequest->submitted_at?->format('M j, Y · g:i A') ?? '—',
                    'items' => $items,
                    'seller_order' => $returnRequest->sellerOrder?->seller_order_no ?? '—',
                    'resolution' => $returnRequest->resolution,
                    'refund_id' => $refund?->id,
                    'refund_no' => $refund?->refund_no,
                    'refund_status' => $refund?->status,
                    'refund_amount' => $refund ? '₱'.number_format((int) $refund->amount_minor / 100, 2) : null,
                    'payout_amount' => $refund?->payout_amount_minor !== null
                        ? '₱'.number_format((int) $refund->payout_amount_minor / 100, 2)
                        : null,
                    'financially_responsible_party' => $refund?->financially_responsible_party,
                    'financial_responsibility_reason' => $refund?->financial_responsibility_reason,
                    'can_decide' => $returnRequest->status === 'under_review',
                    'can_record_responsibility' => $returnRequest->status === 'refund_pending'
                        && $refund?->status === 'pending'
                        && blank($refund?->financially_responsible_party),
                    'can_record_payout' => $returnRequest->status === 'refund_pending'
                        && $refund?->status === 'pending'
                        && in_array($refund?->financially_responsible_party, ['seller', 'platform'], true),
                    'seller_recovery' => $refund?->sellerRecovery ? [
                        'amount_minor' => (int) $refund->sellerRecovery->amount_minor,
                        'recovered_amount_minor' => (int) $refund->sellerRecovery->recovered_amount_minor,
                        'outstanding_minor' => $refund->sellerRecovery->outstandingMinor(),
                        'status' => $refund->sellerRecovery->status,
                        'events' => $refund->sellerRecovery->events->map(fn ($event): array => [
                            'method' => $event->method,
                            'amount_minor' => (int) $event->amount_minor,
                            'reference' => $event->external_reference,
                            'recovered_on' => $event->recovered_on?->format('M j, Y') ?? '—',
                            'note' => $event->note,
                            'payout_no' => $event->sellerPayout?->payout_no,
                            'actor' => $event->recorder?->name ?? 'Admin',
                        ])->all(),
                    ] : null,
                    'eligible_recovery_payouts' => $refund && $returnRequest->store?->seller_profile_id
                        ? ($paidSellerPayouts->get($returnRequest->store->seller_profile_id, collect())->map(fn (SellerPayout $payout): array => [
                            'id' => (int) $payout->id,
                            'number' => $payout->payout_no,
                            'paid_at' => $payout->paid_at?->format('M j, Y') ?? '—',
                            'reference' => $payout->reference,
                        ])->values()->all())
                        : [],
                    'payout_method' => $refund?->method,
                    'payout_reference' => $refund?->provider_reference,
                    'payout_date' => $refund?->external_paid_on?->format('M j, Y'),
                    'payout_receipt_url' => $refund?->status === 'completed' && filled($refund?->payout_receipt_path)
                        ? route('admin.compliance.returns-refunds.payout-receipt', $refund->id)
                        : null,
                    'evidence_files' => $returnRequest->evidence->map(function ($evidence): array {
                        $isImage = in_array(strtolower((string) $evidence->mime_type), [
                            'image/jpeg',
                            'image/png',
                            'image/webp',
                        ], true);

                        return [
                            'id' => $evidence->id,
                            'name' => $evidence->original_name,
                            'type' => $evidence->type,
                            'size' => (int) ($evidence->size_bytes ?? 0),
                            'is_image' => $isImage,
                            'preview_url' => $isImage
                                ? route('admin.compliance.returns-refunds.evidence.preview', $evidence->id)
                                : null,
                            'url' => route('admin.compliance.returns-refunds.evidence.download', $evidence->id),
                        ];
                    })->all(),
                    'return_waybill' => $returnRequest->returnShipment?->waybill?->waybill_no,
                    'return_shipment_status' => $returnRequest->returnShipment?->status,
                    'events' => $returnRequest->events->map(fn ($event): array => [
                        'type' => $event->event_type,
                        'actor' => $event->actor?->name ?? 'Bearly',
                        'note' => $event->note,
                        'at' => $event->created_at?->format('M j, Y · g:i A') ?? '—',
                    ])->all(),
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

    public function messages(Request $request, ConversationService $conversations): View
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
                    'seller' => $user->sellerProfile?->store?->name
                        ?? $user->name,

                    'logistics' => $user->logisticsProfile?->center_name
                        ?? $user->name,

                    default => $user->name,
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
                        'receipts',
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
            ->map(function (Conversation $conversation) use ($admin, $conversations): array {
                $adminParticipant = $conversation
                    ->participants
                    ->firstWhere('id', $admin->id);

                $otherParticipant = $conversation
                    ->participants
                    ->first(
                        fn ($participant) => $participant->id !== $admin->id
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
                                Carbon::parse(
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

                    'presence_user_ids' => $conversations->presenceUserIds(
                        $conversation,
                        $admin
                    ),

                    'delivery_url' => route(
                        'messages.delivered',
                        ['conversation' => $conversation->id]
                    ),

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

                                'message_type' => $message->message_type,

                                'receipt' => $conversations->receiptPayload(
                                    $message,
                                    $admin,
                                    $conversation->participants
                                ),

                                'attachments' => $message->attachments
                                    ->map(
                                        fn ($attachment) => [
                                            'id' => $attachment->id,

                                            'name' => $attachment->original_name,

                                            'mime_type' => $attachment->mime_type,

                                            'size_bytes' => $attachment->size_bytes,

                                            'is_image' => str_starts_with(
                                                (string) $attachment->mime_type,
                                                'image/'
                                            ),

                                            'is_video' => str_starts_with(
                                                (string) $attachment->mime_type,
                                                'video/'
                                            ),

                                            'is_previewable' => $conversations
                                                ->isPreviewableMime($attachment->mime_type),

                                            'download_url' => route(
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
                        fn ($conversation) => (int) $conversation['database_id']
                            === $requestedConversationId
                    )
                : null;

        $activeConversation ??=
            $conversations[0] ?? null;

        return view(
            'admin.communication.messages',
            $this->base([
                'conversations' => $conversations,

                'messages' => $activeConversation['messages']
                    ?? [],

                'activeConversation' => $activeConversation,

                'recipients' => $recipients,
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
        $admin = Auth::user();

        $identity = $this->adminIdentity();

        $nameParts = preg_split('/\s+/', $identity['name']) ?: [];
        $firstName = (string) ($admin?->first_name ?: (array_shift($nameParts) ?: ''));
        $lastName = (string) ($admin?->last_name ?: trim(implode(' ', $nameParts)));

        return view('admin.system.account', $this->base([
            'profile' => [
                'first_name' => $firstName !== '' ? $firstName : $identity['name'],
                'last_name' => $lastName,
                'email' => (string) ($admin?->email ?? ''),
                'phone' => (string) ($admin?->phone ?: $admin?->contact_number ?: ''),
                'role' => 'Administrator',
                'last_sign_in' => $admin?->last_login_at?->format('M j, Y · g:i A') ?? 'No sign-in recorded yet',
            ],
        ]));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $validated = $request->validate([
            'current_password' => [
                'required',
                'string',
            ],
            'new_password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        if (! Hash::check(
            $validated['current_password'],
            $admin->password
        )) {
            return redirect(
                route('admin.account').'#security'
            )->withErrors([
                'current_password' =>
                    'The current password is incorrect.',
            ]);
        }

        $admin->update([
            'password' => Hash::make(
                $validated['new_password']
            ),
        ]);

        return redirect(
            route('admin.account').'#security'
        )->with(
            'success',
            'Password updated successfully.'
        );
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

<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class LogisticsController extends Controller
{
    private function shared(): array
    {
        /** @var User|null $operator */
        $operator = Auth::user();

        $name = $operator?->name ?: 'Bearly Logistics';
        $initials = collect(preg_split('/\s+/', trim($name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part) => strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        $pendingRiderCount = 0;

        if ($operator && Schema::hasTable('users')) {
            $pendingRiderCount = User::query()
                ->where('role', UserRole::Rider->value)
                ->where('logistics_id', $operator->id)
                ->whereIn('status', [
                    AccountStatus::Pending->value,
                    AccountStatus::NeedsRevision->value,
                ])
                ->count();
        }

        return [
            'operator' => [
                'name' => $name,
                'initials' => $initials ?: 'BL',
                'email' => $operator?->email ?: '',
                'role' => 'Logistics Operator',
                'business_name' => $operator?->business_name ?: $name,
            ],
            'pendingRiderCount' => $pendingRiderCount,
            'topNotifications' => $pendingRiderCount > 0
                ? [[
                    'title' => $pendingRiderCount.' rider application'.($pendingRiderCount === 1 ? '' : 's').' awaiting review',
                    'time' => 'Current',
                    'type' => 'warning',
                ]]
                : [],
        ];
    }

    public function landing()
    {
        return view('logistics.landing.index');
    }

    public function register()
    {
        return view('logistics.applications.create');
    }

    public function submitRegistration(Request $request)
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            'first_name' => ['required', 'string', 'max:80'],
            'middle_initial' => ['nullable', 'string', 'max:2', 'regex:/^[A-Za-z][.]?$/D'],
            'last_name' => ['required', 'string', 'max:80'],
            'sex' => ['required', 'in:Male,Female,Prefer not to say'],
            'email' => ['required', 'email', 'unique:users,email'],
            'contact_number' => ['required', 'string', 'max:20'],
            'birthday' => ['required', 'date', 'before:today'],
            'province' => ['required', 'string', 'max:120'],
            'municipality' => ['required', 'string', 'max:120'],
            'barangay' => ['required', 'string', 'max:120'],
            'street' => ['required', 'string', 'max:180'],
            'house_number' => ['required', 'string', 'max:40'],
            'valid_id' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:5120',
            ],
            'business_permit' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:5120',
            ],
            'password' => ['required', 'confirmed', 'min:8', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/'],
        ]);

        $verification = app(EmailVerificationService::class);
        $verifiedAt = $verification->verifiedAt(
            $request,
            $validated['email']
        );

        $middleInitial = empty($validated['middle_initial'])
            ? null
            : strtoupper(substr($validated['middle_initial'], 0, 1)).'.';

        $user = User::create([
            'name' => trim($validated['first_name'].' '.($middleInitial ?? '').' '.$validated['last_name']),
            'first_name' => $validated['first_name'],
            'middle_initial' => $middleInitial,
            'last_name' => $validated['last_name'],
            'sex' => match ($validated['sex']) {
                'Male' => 'male',
                'Female' => 'female',
                default => 'prefer_not_to_say',
            },
            'birthday' => $validated['birthday'],
            'email' => $validated['email'],
            'email_verified_at' => $verifiedAt,
            'contact_number' => $validated['contact_number'],
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Pending->value,
            'province' => $validated['province'],
            'city' => $validated['municipality'],
            'barangay' => $validated['barangay'],
            'street_address' => trim($validated['house_number'].' '.$validated['street']),
            'business_name' => $validated['business_name'],
            'valid_id_path' => $request->file('valid_id')->store('registration-documents/logistics/valid-ids', 'local'),
            'business_permit_path' => $request->file('business_permit')->store('registration-documents/logistics/permits', 'local'),
            'password' => Hash::make($validated['password']),
        ]);

        $verification->forget($request);

        session([
            'logistics_application' => [
                'business_name' => $user->business_name,
                'representative_name' => $user->name,
                'email' => $user->email,
                'status' => 'Pending Administrator Approval',
            ],
        ]);

        return redirect()
            ->route('logistics.register')
            ->with('registration_pending', true);
    }

    public function dashboard()
    {
        $shared = $this->shared();

        $recentRiderApplications = User::query()
            ->where('role', UserRole::Rider->value)
            ->where('logistics_id', Auth::id())
            ->whereIn('status', [
                AccountStatus::Pending->value,
                AccountStatus::NeedsRevision->value,
            ])
            ->latest()
            ->take(4)
            ->get()
            ->map(fn (User $user) => [
                'time' => $user->created_at?->diffForHumans() ?? 'Recently',
                'title' => 'Rider application received',
                'detail' => $user->name.' • '.($user->vehicle_type ?: 'Vehicle not specified'),
            ])
            ->all();

        return view('logistics.dashboard.index', $shared + [
            'metrics' => [
                [
                    'label' => 'Pending Rider Applications',
                    'value' => $shared['pendingRiderCount'],
                    'icon' => 'user-round-check',
                    'trend' => $shared['pendingRiderCount'] > 0 ? 'Needs review' : 'Queue is clear',
                ],
                [
                    'label' => 'Incoming Parcels',
                    'value' => 146,
                    'icon' => 'package-open',
                    'trend' => '+18 since 8 AM',
                ],
                [
                    'label' => 'Active Sorting Queue',
                    'value' => 39,
                    'icon' => 'truck',
                    'trend' => '31 on schedule',
                ],
                [
                    'label' => 'Dispatched Shipments',
                    'value' => 17,
                    'icon' => 'route',
                    'trend' => 'Needs dispatch',
                ],
            ],
            'zones' => [
                [
                    'zone' => 'San Pablo North',
                    'parcels' => 38,
                    'ready' => 31,
                    'riders' => 6,
                ],
                [
                    'zone' => 'San Pablo South',
                    'parcels' => 41,
                    'ready' => 35,
                    'riders' => 7,
                ],
                [
                    'zone' => 'Calauan / Bay',
                    'parcels' => 29,
                    'ready' => 22,
                    'riders' => 4,
                ],
                [
                    'zone' => 'Pila / Sta. Cruz',
                    'parcels' => 38,
                    'ready' => 30,
                    'riders' => 5,
                ],
            ],
            'activity' => $recentRiderApplications,
        ]);
    }

    public function riders()
    {
        $applications = User::query()
            ->where('role', UserRole::Rider->value)
            ->where('logistics_id', Auth::id())
            ->whereIn('status', [
                AccountStatus::Pending->value,
                AccountStatus::NeedsRevision->value,
            ])
            ->latest()
            ->get()
            ->map(fn (User $user) => [
                'id' => 'RIDER-'.$user->id,
                'user_id' => $user->id,
                'name' => $user->name,
                'vehicle' => $user->vehicle_type ?: 'Not specified',
                'plate' => $user->plate_number ?: '—',
                'area' => $user->city ?: '—',
                'submitted' => $user->created_at?->format('M j, Y') ?? 'Recently',
                'status' => $user->status === AccountStatus::NeedsRevision->value
                    ? 'Needs Review'
                    : 'Pending',
            ])
            ->all();

        $riders = User::query()
            ->where('role', UserRole::Rider->value)
            ->where('logistics_id', Auth::id())
            ->whereIn('status', [
                AccountStatus::Active->value,
                AccountStatus::Suspended->value,
                AccountStatus::Deactivated->value,
            ])
            ->latest('approved_at')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'vehicle' => $user->vehicle_type ?: 'Not specified',
                'zone' => $user->city ?: 'Not assigned',
                'jobs' => 0,
                'rating' => null,
                'status' => match ($user->status) {
                    AccountStatus::Active->value => 'Active',
                    AccountStatus::Suspended->value => 'Suspended',
                    AccountStatus::Deactivated->value => 'Deactivated',
                    default => ucfirst(str_replace('_', ' ', $user->status)),
                },
            ])
            ->all();

        return view('logistics.riders.index', $this->shared() + [
            'applications' => $applications,
            'riders' => $riders,
        ]);
    }

    public function showRider(string $id): View
    {
        abort_unless(ctype_digit($id), 404);

        $user = User::query()
            ->whereKey((int) $id)
            ->where('role', UserRole::Rider->value)
            ->where('logistics_id', Auth::id())
            ->firstOrFail();

        $documents = array_values(array_filter([
            $user->driver_license_path
                ? [
                    'label' => "Driver's License / ID",
                    'filename' => basename($user->driver_license_path),
                    'status' => 'For review',
                ]
                : null,
            $user->or_cr_path
                ? [
                    'label' => 'Vehicle OR/CR',
                    'filename' => basename($user->or_cr_path),
                    'status' => 'For review',
                ]
                : null,
        ]));

        $address = collect([
            $user->street_address,
            $user->barangay,
            $user->city,
            $user->province,
        ])->filter()->implode(', ');

        return view('logistics.riders.show', $this->shared() + [
            'application' => [
                'id' => 'RIDER-'.$user->id,
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'contact' => $user->contact_number ?: '—',
                'birthday' => $user->birthday?->format('F j, Y') ?? 'Not provided',
                'sex' => $user->sex
                    ? ucwords(str_replace('_', ' ', $user->sex))
                    : 'Not provided',
                'address' => $address ?: 'Not provided',
                'vehicle' => $user->vehicle_type ?: 'Not specified',
                'plate' => $user->plate_number ?: '—',
                'area' => $user->city ?: '—',
                'submitted' => $user->created_at?->format('M j, Y') ?? 'Recently',
                'status' => ucwords(str_replace('_', ' ', $user->status)),
                'documents' => $documents,
            ],
        ]);
    }

    public function pickups()
    {
        return view('logistics.pickups.index', $this->shared() + [
            'pickups' => [
                [
                    'id' => 'PU-24091',
                    'seller' => 'TechVault PH',
                    'location' => 'Brgy. San Rafael, San Pablo City',
                    'parcels' => 6,
                    'window' => '2:30–3:30 PM',
                    'status' => 'Pending',
                ],
                [
                    'id' => 'PU-24090',
                    'seller' => 'Mara Home Goods',
                    'location' => 'Brgy. Del Remedio, San Pablo City',
                    'parcels' => 5,
                    'window' => '3:00–4:00 PM',
                    'status' => 'Pending',
                ],
                [
                    'id' => 'PU-24087',
                    'seller' => 'Little Sprout',
                    'location' => 'Brgy. Bagong Pook, Liliw',
                    'parcels' => 3,
                    'window' => '4:00–5:00 PM',
                    'status' => 'Verified',
                ],
            ],
        ]);
    }

    public function incoming(): View
    {
        return view('logistics.sorting.incoming', $this->shared() + [
            'incomingParcels' => [
                ['waybill' => 'BRL-983428', 'order' => 'ORD-50214', 'seller' => 'Mara Home Goods', 'rider' => 'Nico Flores', 'received' => 'Sep 20, 2026 · 2:28 PM', 'pieces' => 2, 'weight' => '3.4 kg', 'destination' => 'San Pablo North', 'status' => 'AT_SORTING_CENTER'],
                ['waybill' => 'BRL-983427', 'order' => 'ORD-50211', 'seller' => 'TechVault PH', 'rider' => 'Anne Cruz', 'received' => 'Sep 20, 2026 · 2:19 PM', 'pieces' => 1, 'weight' => '0.8 kg', 'destination' => 'Calauan / Bay', 'status' => 'AT_SORTING_CENTER'],
                ['waybill' => 'BRL-983426', 'order' => 'ORD-50208', 'seller' => 'Everyday Finds', 'rider' => 'Nico Flores', 'received' => 'Sep 20, 2026 · 1:54 PM', 'pieces' => 4, 'weight' => '6.1 kg', 'destination' => 'Pila / Sta. Cruz', 'status' => 'Logged'],
                ['waybill' => 'BRL-983425', 'order' => 'ORD-50202', 'seller' => 'Little Sprout', 'rider' => 'Marco Lim', 'received' => 'Sep 20, 2026 · 1:37 PM', 'pieces' => 1, 'weight' => '1.2 kg', 'destination' => 'San Pablo South', 'status' => 'Exception'],
            ],
        ]);
    }

    public function sorting()
    {
        return view('logistics.sorting.center', $this->shared() + [
            'parcels' => [
                [
                    'waybill' => 'BRL-983410',
                    'seller' => 'TechVault PH',
                    'destination' => 'San Pablo City',
                    'zone' => 'SP-N1',
                    'size' => 'Small',
                    'status' => 'Received',
                ],
                [
                    'waybill' => 'BRL-983411',
                    'seller' => 'TechVault PH',
                    'destination' => 'San Pablo City',
                    'zone' => 'SP-N2',
                    'size' => 'Medium',
                    'status' => 'Sorted',
                ],
                [
                    'waybill' => 'BRL-983412',
                    'seller' => 'Mara Home Goods',
                    'destination' => 'Pila',
                    'zone' => 'PILA-1',
                    'size' => 'Large',
                    'status' => 'Received',
                ],
                [
                    'waybill' => 'BRL-983413',
                    'seller' => 'Little Sprout',
                    'destination' => 'Calauan',
                    'zone' => 'CAL-1',
                    'size' => 'Small',
                    'status' => 'Sorted',
                ],
                [
                    'waybill' => 'BRL-983414',
                    'seller' => 'Mara Home Goods',
                    'destination' => 'Sta. Cruz',
                    'zone' => 'STC-2',
                    'size' => 'Medium',
                    'status' => 'Exception',
                ],
            ],
        ]);
    }

    public function dispatch()
    {
        return view('logistics.dispatch.index', $this->shared() + [
            'zones' => [
                [
                    'zone' => 'SP-N1',
                    'area' => 'San Pablo North',
                    'ready' => 12,
                    'riders' => ['Nico Flores', 'Jared Molina'],
                ],
                [
                    'zone' => 'SP-S2',
                    'area' => 'San Pablo South',
                    'ready' => 9,
                    'riders' => ['Anne Cruz'],
                ],
                [
                    'zone' => 'PILA-1',
                    'area' => 'Pila',
                    'ready' => 8,
                    'riders' => ['Marco Lim', 'Lara Mendoza'],
                ],
            ],
        ]);
    }

    public function monitoring()
    {
        return view('logistics.dispatch.monitoring', $this->shared() + [
            'deliveries' => [
                [
                    'id' => 'DL-8412',
                    'rider' => 'Nico Flores',
                    'zone' => 'SP-N1',
                    'parcels' => 8,
                    'progress' => 72,
                    'status' => 'OUT_FOR_DELIVERY',
                    'last' => 'Brgy. San Lucas • 2:02 PM',
                ],
                [
                    'id' => 'DL-8411',
                    'rider' => 'Anne Cruz',
                    'zone' => 'SP-S2',
                    'parcels' => 6,
                    'progress' => 100,
                    'status' => 'DELIVERED',
                    'last' => 'Completed • 1:48 PM',
                ],
                [
                    'id' => 'DL-8410',
                    'rider' => 'Marco Lim',
                    'zone' => 'PILA-1',
                    'parcels' => 5,
                    'progress' => 20,
                    'status' => 'ASSIGNED_TO_RIDER',
                    'last' => 'Sorting Center • 1:31 PM',
                ],
                [
                    'id' => 'DL-8408',
                    'rider' => 'Paolo Reyes',
                    'zone' => 'CAL-1',
                    'parcels' => 4,
                    'progress' => 55,
                    'status' => 'DELIVERY_FAILED',
                    'last' => 'Customer unavailable • 12:54 PM',
                ],
            ],
        ]);
    }

    public function reports()
    {
        return view('logistics.reports.index', $this->shared() + [
            'summary' => [
                'throughput' => 1284,
                'delivered' => 1176,
                'success_rate' => 91.6,
                'avg_sort_time' => '18m',
            ],
            'riderStats' => [
                [
                    'name' => 'Nico Flores',
                    'assigned' => 91,
                    'delivered' => 88,
                    'failed' => 3,
                    'rate' => '96.7%',
                ],
                [
                    'name' => 'Anne Cruz',
                    'assigned' => 84,
                    'delivered' => 80,
                    'failed' => 4,
                    'rate' => '95.2%',
                ],
                [
                    'name' => 'Marco Lim',
                    'assigned' => 76,
                    'delivered' => 70,
                    'failed' => 6,
                    'rate' => '92.1%',
                ],
            ],
            'dailyVolumes' => [118, 136, 129, 151, 164, 143, 158],
            'statusBreakdown' => [
                ['label' => 'Delivered', 'value' => 1176, 'share' => 91.6],
                ['label' => 'Out for Delivery', 'value' => 61, 'share' => 4.8],
                ['label' => 'Delivery Failed', 'value' => 29, 'share' => 2.3],
                ['label' => 'Returned', 'value' => 18, 'share' => 1.3],
            ],
        ]);
    }

    public function messages()
    {
        return view('logistics.messages.index', $this->shared() + [
            'conversations' => [
                [
                    'id' => 'techvault-ph',
                    'name' => 'TechVault PH',
                    'role' => 'Seller',
                    'initials' => 'TP',
                    'preview' => 'Pickup batch is ready at our counter.',
                    'time' => '2:07 PM',
                ],
                [
                    'id' => 'nico-flores',
                    'name' => 'Nico Flores',
                    'role' => 'Rider',
                    'initials' => 'NF',
                    'preview' => 'I finished the SP-N1 route.',
                    'time' => '1:51 PM',
                ],
                [
                    'id' => 'bearly-admin',
                    'name' => 'Bearly Admin',
                    'role' => 'Administrator',
                    'initials' => 'BA',
                    'preview' => 'Please review the pending rider credentials.',
                    'time' => '11:24 AM',
                ],
            ],
        ]);
    }

    public function account()
    {
        /** @var User $operator */
        $operator = Auth::user();

        $address = collect([
            $operator->street_address,
            $operator->barangay,
            $operator->city,
            $operator->province,
        ])->filter()->implode(', ');

        return view('logistics.profile.index', $this->shared() + [
            'facility' => [
                'business_name' => $operator->business_name ?: $operator->name,
                'contact' => $operator->contact_number ?: '',
                'address' => $address,
                'operating_hours' => '',
                'daily_capacity' => '',
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\PickupAssignment;
use App\Models\PickupRequest;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\AccountApplication;
use App\Models\LogisticsProfile;
use App\Models\RiderProfile;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\EmailVerificationService;
use App\Services\InternationalPhone;
use App\Services\RegistrationLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class LogisticsController extends Controller
{
    private function activeSortingCenter(
        ?User $operator
    ): ?SortingCenter {
        if (! $operator) {
            return null;
        }

        $profile = $operator->logisticsProfile;

        if (! $profile) {
            return null;
        }

        $activeCenter = $profile
            ->sortingCenters()
            ->with([
                'address',
                'zones',
            ])
            ->where('status', 'active')
            ->oldest('id')
            ->first();

        if ($activeCenter) {
            return $activeCenter;
        }

        return $profile
            ->sortingCenters()
            ->with([
                'address',
                'zones',
            ])
            ->oldest('id')
            ->first();
    }

    private function currentLogisticsProfile(
        ?User $operator
    ): ?LogisticsProfile {
        return $operator?->logisticsProfile;
    }

    private function riderApplicationStatus(
        string $status
    ): string {
        return match ($status) {
            'submitted' => 'Pending',

            /*
            * Keep the current UI vocabulary intact.
            * Both states mean that Logistics attention
            * is still required.
            */
            'under_review',
            'needs_revision' => 'Needs Review',

            'approved' => 'Approved',
            'rejected' => 'Rejected',

            default => ucwords(
                str_replace('_', ' ', $status)
            ),
        };
    }

    private function riderAccountStatus(
        string $status
    ): string {
        return match ($status) {
            AccountStatus::Active->value =>
                'Active',

            AccountStatus::Suspended->value =>
                'Suspended',

            AccountStatus::Deactivated->value =>
                'Deactivated',

            AccountStatus::Rejected->value =>
                'Rejected',

            default => ucwords(
                str_replace('_', ' ', $status)
            ),
        };
    }

    private function formattedAddress(
        ?\App\Models\Address $address
    ): string {
        if (! $address) {
            return 'Not provided';
        }

        return collect([
            $address->house_number,
            $address->street,
            $address->barangay,
            $address->city_municipality,
            $address->province,
            $address->postal_code,
        ])
            ->filter()
            ->implode(', ');
    }

    private function facilityAddress(
        ?SortingCenter $center,
        ?User $operator
    ): string {
        if ($center?->address) {
            return collect([
                $center->address->house_number,
                $center->address->street,
                $center->address->barangay,
                $center->address->city_municipality,
                $center->address->province,
                $center->address->postal_code,
            ])
                ->filter()
                ->implode(', ');
        }

        return collect([
            $operator?->street_address,
            $operator?->barangay,
            $operator?->city,
            $operator?->province,
        ])
            ->filter()
            ->implode(', ');
    }

    private function shared(): array
    {
        /** @var User|null $operator */
        $operator = Auth::user();

        $activeFacility = $this->activeSortingCenter(
            $operator
        );

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
                'contact' =>
                    $operator?->contact_number
                    ?: $operator?->phone
                    ?: '',
                'role' => 'Logistics Operator',
                'business_name' =>
                    $activeFacility?->name
                    ?: $operator?->business_name
                    ?: $name,
            ],
            'activeFacility' => [
                'id' => $activeFacility?->id,
                'name' =>
                    $activeFacility?->name
                    ?: $operator?->business_name
                    ?: $name,
                'code' => $activeFacility?->code,
                'status' => $activeFacility?->status,
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
            'terms' => ['accepted'],
            'password' => ['required', 'confirmed', 'min:8', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/'],
        ]);

        $validated['contact_number'] =
            app(InternationalPhone::class)->normalize(
                $validated['contact_number'],
                'PH'
            );
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
            'phone_country' => 'PH',
            'terms_accepted_at' => now(),
            'terms_version' => config('bearly-policies.version'),
            'privacy_version' => config('bearly-policies.version'),
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

        app(RegistrationLifecycleService::class)
            ->recordPendingApplication(
                $user,
                UserRole::Logistics->value,
                [
                    'house_number' => $validated['house_number'],
                    'street' => $validated['street'],
                    'barangay' => $validated['barangay'],
                    'municipality' => $validated['municipality'],
                    'province' => $validated['province'],
                    'postal_code' =>
                        $validated['postal_code'] ?? null,
                    'city_code' =>
                        $validated['city_code'] ?? null,
                ],
                [
                    'business_name' =>
                        $validated['business_name'],
                ],
                [
                    [
                        'type' => 'valid_id',
                        'path' => $user->valid_id_path,
                        'file' => $request->file('valid_id'),
                    ],
                    [
                        'type' => 'business_permit',
                        'path' => $user->business_permit_path,
                        'file' => $request->file(
                            'business_permit'
                        ),
                    ],
                ]
            );
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

        /** @var User $operator */
        $operator = Auth::user();

        $profile = $this->currentLogisticsProfile(
            $operator
        );

        $pendingPickupCount = $profile
            ? PickupRequest::query()
                ->forLogisticsProfile($profile->id)
                ->where('status', 'requested')
                ->count()
            : 0;

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
            'pendingPickupCount' => $pendingPickupCount,
        ]);
    }

    public function riders()
    {
        /** @var User $operator */
        $operator = Auth::user();

        $profile = $this->currentLogisticsProfile(
            $operator
        );

        $applications = collect();

        $riders = collect();

        if ($profile) {
            $applications = AccountApplication::query()
                ->where(
                    'sponsor_logistics_profile_id',
                    $profile->id
                )
                ->whereHas(
                    'requestedRole',
                    fn ($query) => $query->where(
                        'name',
                        UserRole::Rider->value
                    )
                )
                ->whereIn('status', [
                    'submitted',
                    'under_review',
                    'needs_revision',
                ])
                ->with([
                    'user.addresses',
                    'documents',
                ])
                ->latest('submitted_at')
                ->latest('id')
                ->get()
                ->map(function (
                    AccountApplication $application
                ): array {
                    $user = $application->user;

                    $address = $user?->addresses
                        ?->sortByDesc('id')
                        ->first();

                    return [
                        'id' =>
                            $application->application_no,

                        'application_id' =>
                            $application->id,

                        'user_id' =>
                            $application->user_id,

                        'name' =>
                            $user?->name
                            ?: 'Unknown Rider',

                        'vehicle' =>
                            $user?->vehicle_type
                            ?: 'Not specified',

                        'plate' =>
                            $user?->plate_number
                            ?: '—',

                        'area' =>
                            $address?->city_municipality
                            ?: $user?->city
                            ?: '—',

                        'submitted' =>
                            $application->submitted_at
                                ?->format('M j, Y')
                            ?? 'Recently',

                        'status' =>
                            $this->riderApplicationStatus(
                                $application->status
                            ),
                    ];
                });

            $riders = RiderProfile::query()
                ->where(
                    'logistics_profile_id',
                    $profile->id
                )
                ->whereHas(
                    'user',
                    fn ($query) => $query->whereIn(
                        'status',
                        [
                            AccountStatus::Active->value,
                            AccountStatus::Suspended->value,
                            AccountStatus::Deactivated->value,
                        ]
                    )
                )
                ->with([
                    'user',
                    'homeSortingCenter',
                    'currentZone',
                ])
                ->latest('id')
                ->get()
                ->map(function (
                    RiderProfile $rider
                ): array {
                    return [
                        /*
                        * Keep route IDs as User IDs because
                        * logistics.riders.show currently
                        * accepts the Rider user ID.
                        */
                        'id' =>
                            $rider->user_id,

                        'profile_id' =>
                            $rider->id,

                        'name' =>
                            $rider->user?->name
                            ?: 'Unknown Rider',

                        'vehicle' =>
                            $rider->vehicle_type
                            ?: 'Not specified',

                        'zone' =>
                            $rider->currentZone?->name
                            ?: 'Not assigned',

                        /*
                        * Fulfillment jobs and ratings do not
                        * have trustworthy sources yet.
                        */
                        'jobs' => 0,
                        'rating' => null,

                        'status' =>
                            $this->riderAccountStatus(
                                $rider->user?->status
                                ?? AccountStatus::Active->value
                            ),
                    ];
                });
        }

        return view(
            'logistics.riders.index',
            $this->shared() + [
                'applications' =>
                    $applications->all(),

                'riders' =>
                    $riders->all(),
            ]
        );
    }

    public function showRider(
        string $id
    ): View {
        abort_unless(
            ctype_digit($id),
            404
        );

        /** @var User $operator */
        $operator = Auth::user();

        $profile = $this->currentLogisticsProfile(
            $operator
        );

        abort_unless($profile, 404);

        $accountApplication =
            AccountApplication::query()
                ->where(
                    'user_id',
                    (int) $id
                )
                ->where(
                    'sponsor_logistics_profile_id',
                    $profile->id
                )
                ->whereHas(
                    'requestedRole',
                    fn ($query) => $query->where(
                        'name',
                        UserRole::Rider->value
                    )
                )
                ->with([
                    'user.addresses',
                    'documents',
                    'user.riderProfile.homeSortingCenter',
                    'user.riderProfile.currentZone',
                ])
                ->latest('id')
                ->firstOrFail();

        $user = $accountApplication->user;

        abort_unless($user, 404);

        $riderProfile = $user->riderProfile;

        $address = $user->addresses
            ->sortByDesc('id')
            ->first();

        $documents = $accountApplication
            ->documents
            ->sortBy('id')
            ->map(function ($document) use (
                $accountApplication
            ): array {
                return [
                    'id' =>
                        $document->id,

                    'application_id' =>
                        $accountApplication->id,

                    'type' =>
                        $document->document_type,

                    'label' => match (
                        $document->document_type
                    ) {
                        'driver_license' =>
                            "Driver's License / ID",

                        'or_cr' =>
                            'Vehicle OR/CR',

                        default =>
                            ucwords(
                                str_replace(
                                    '_',
                                    ' ',
                                    $document
                                        ->document_type
                                )
                            ),
                    },

                    'filename' =>
                        $document->original_name,

                    'status' => match (
                        $document
                            ->verification_status
                    ) {
                        'verified' =>
                            'Verified',

                        'rejected' =>
                            'Rejected',

                        default =>
                            'Pending',
                    },

                    'rejection_reason' =>
                        $document->rejection_reason,
                ];
            })
            ->values()
            ->all();

        $displayStatus =
            $accountApplication->status === 'approved'
                ? $this->riderAccountStatus(
                    $user->status
                )
                : $this->riderApplicationStatus(
                    $accountApplication->status
                );

        $assignmentCenters =
            SortingCenter::query()
                ->where(
                    'logistics_profile_id',
                    $profile->id
                )
                ->where(
                    'status',
                    'active'
                )
                ->with([
                    'zones' => function ($query) {
                        $query
                            ->where(
                                'status',
                                'active'
                            )
                            ->orderBy('name');
                    },
                ])
                ->orderBy('name')
                ->get()
                ->map(function (
                    SortingCenter $center
                ): array {
                    return [
                        'id' =>
                            $center->id,

                        'name' =>
                            $center->name,

                        'code' =>
                            $center->code,

                        'zones' =>
                            $center->zones
                                ->map(
                                    fn (
                                        SortingZone $zone
                                    ): array => [
                                        'id' =>
                                            $zone->id,

                                        'name' =>
                                            $zone->name,

                                        'code' =>
                                            $zone->code,
                                    ]
                                )
                                ->values()
                                ->all(),
                    ];
                })
                ->values()
                ->all();

        return view(
            'logistics.riders.show',
            $this->shared() + [
                'application' => [
                    'id' =>
                        $accountApplication
                            ->application_no,

                    'application_id' =>
                        $accountApplication->id,

                    'user_id' =>
                        $user->id,

                    'name' =>
                        $user->name,

                    'email' =>
                        $user->email,

                    'contact' =>
                        $user->contact_number
                        ?: '—',

                    'birthday' =>
                        $user->birthday
                            ?->format('F j, Y')
                        ?? 'Not provided',

                    'sex' =>
                        $user->sex
                            ? ucwords(
                                str_replace(
                                    '_',
                                    ' ',
                                    $user->sex
                                )
                            )
                            : 'Not provided',

                    'address' =>
                        $this->formattedAddress(
                            $address
                        ),

                    'vehicle' =>
                        $riderProfile?->vehicle_type
                        ?: $user->vehicle_type
                        ?: 'Not specified',

                    'plate' =>
                        $riderProfile?->plate_number
                        ?: $user->plate_number
                        ?: '—',

                    'area' =>
                        $riderProfile
                            ?->currentZone
                            ?->name
                        ?: $address
                            ?->city_municipality
                        ?: $user->city
                        ?: '—',

                    'submitted' =>
                        $accountApplication
                            ->submitted_at
                            ?->format('M j, Y')
                        ?? 'Recently',

                    'status' =>
                        $displayStatus,

                    'home_sorting_center_id' =>
                        $riderProfile?->home_sorting_center_id,

                    'current_zone_id' =>
                        $riderProfile?->current_zone_id,

                    'home_sorting_center' =>
                        $riderProfile
                            ?->homeSortingCenter
                            ?->name
                        ?? 'Not assigned',

                    'current_zone' =>
                        $riderProfile
                            ?->currentZone
                            ?->name
                        ?? 'Not assigned',

                    'can_manage_assignment' =>
                        $accountApplication->status === 'approved'
                        && $user->status === AccountStatus::Active->value
                        && $riderProfile !== null,

                    'documents' =>
                        $documents,
                ],

                'assignmentCenters' =>
                    $assignmentCenters,
            ]
        );
    }

    public function updateRiderAssignment(
        Request $request,
        User $user
    ): RedirectResponse {
        /** @var User $operator */
        $operator = Auth::user();

        $logisticsProfile =
            $this->currentLogisticsProfile(
                $operator
            );

        abort_unless(
            $logisticsProfile,
            404
        );

        $riderProfile =
            RiderProfile::query()
                ->where(
                    'user_id',
                    $user->id
                )
                ->where(
                    'logistics_profile_id',
                    $logisticsProfile->id
                )
                ->firstOrFail();

        $validated = $request->validate([
            'home_sorting_center_id' => [
                'required',
                'integer',
            ],

            'current_zone_id' => [
                'nullable',
                'integer',
            ],
        ]);

        $sortingCenter =
            SortingCenter::query()
                ->whereKey(
                    $validated[
                        'home_sorting_center_id'
                    ]
                )
                ->where(
                    'logistics_profile_id',
                    $logisticsProfile->id
                )
                ->where(
                    'status',
                    'active'
                )
                ->firstOrFail();

        $zoneId = null;

        if (
            ! empty(
                $validated['current_zone_id']
            )
        ) {
            $zone = SortingZone::query()
                ->whereKey(
                    $validated[
                        'current_zone_id'
                    ]
                )
                ->where(
                    'sorting_center_id',
                    $sortingCenter->id
                )
                ->where(
                    'status',
                    'active'
                )
                ->firstOrFail();

            $zoneId = $zone->id;
        }

        $riderProfile->forceFill([
            'home_sorting_center_id' =>
                $sortingCenter->id,

            'current_zone_id' =>
                $zoneId,
        ])->save();

        return back()->with(
            'success',
            'Rider assignment was updated.'
        );
    }

    public function pickups(): View
    {
        /** @var User $operator */
        $operator = Auth::user();

        $profile = $this->currentLogisticsProfile(
            $operator
        );

        abort_unless($profile, 404);

        $pickupRequests = PickupRequest::query()
            ->forLogisticsProfile($profile->id)
            ->with([
                'store.sellerProfile.user',
                'pickupAddress',
                'latestAssignment.riderProfile.user',
                'parcels' => fn ($query) => $query
                    ->whereHas(
                        'shipment',
                        fn ($shipmentQuery) =>
                            $shipmentQuery->where(
                                'logistics_profile_id',
                                $profile->id
                            )
                    )
                    ->with([
                        'waybill',
                        'shipment.sellerOrder',
                    ]),
            ])
            ->orderByDesc('requested_date')
            ->orderByDesc('id')
            ->get();

        $pickups = $pickupRequests
            ->map(function (
                PickupRequest $pickupRequest
            ): array {
                $assignment =
                    $pickupRequest->latestAssignment;

                $eligibleParcels =
                    $pickupRequest->parcels
                        ->filter(fn ($parcel) =>
                            $parcel->shipment
                                ?->logistics_profile_id
                                === $pickupRequest
                                    ->logistics_profile_id
                            && $parcel->shipment
                                ?->sellerOrder
                                ?->store_id
                                === $pickupRequest->store_id
                        )
                        ->values();

                $pickupRequest->setRelation(
                    'parcels',
                    $eligibleParcels
                );

                return [
                    'database_id' =>
                        $pickupRequest->id,
                    'id' =>
                        $pickupRequest->pickup_no,
                    'seller' =>
                        $pickupRequest->store?->name
                        ?: 'Unknown seller',
                    'location' =>
                        $this->formattedAddress(
                            $pickupRequest->pickupAddress
                        ),
                    'parcels' =>
                        $eligibleParcels->count(),
                    'window' =>
                        $pickupRequest->window_start
                            ->format('M j, Y · g:i A')
                        .'–'
                        .$pickupRequest->window_end
                            ->format('g:i A'),
                    'status' =>
                        $this->pickupStatusLabel(
                            $pickupRequest->status
                        ),
                    'status_key' =>
                        $pickupRequest->status,
                    'contact' =>
                        $pickupRequest->store
                            ?->contact_phone
                        ?: $pickupRequest->store
                            ?->sellerProfile
                            ?->user
                            ?->contact_number
                        ?: 'Not provided',
                    'instructions' =>
                        $pickupRequest
                            ->seller_instructions
                        ?: 'No seller instructions.',
                    'rider' =>
                        $assignment
                            ?->riderProfile
                            ?->user
                            ?->name
                        ?: 'Not assigned',
                    'assignment_status' =>
                        $assignment?->status,
                    'parcel_details' =>
                        $eligibleParcels
                            ->map(fn ($parcel): array => [
                                'parcel_no' =>
                                    $parcel->parcel_no,
                                'waybill_no' =>
                                    $parcel->waybill
                                        ?->waybill_no
                                    ?: '—',
                                'shipment_no' =>
                                    $parcel->shipment
                                        ?->shipment_no
                                    ?: '—',
                                'seller_order_no' =>
                                    $parcel->shipment
                                        ?->sellerOrder
                                        ?->seller_order_no
                                    ?: '—',
                            ])
                            ->values()
                            ->all(),
                ];
            })
            ->values();

        $availableRiders = RiderProfile::query()
            ->where('logistics_profile_id', $profile->id)
            ->where('verification_status', 'approved')
            ->where('availability_status', 'available')
            ->whereHas(
                'user',
                fn ($query) => $query->where(
                    'status',
                    AccountStatus::Active->value
                )
            )
            ->with('user')
            ->get()
            ->sortBy(fn (RiderProfile $rider) =>
                strtolower($rider->user?->name ?? '')
            )
            ->map(fn (RiderProfile $rider): array => [
                'id' => $rider->id,
                'name' => $rider->user?->name
                    ?: 'Unknown Rider',
                'vehicle' => $rider->vehicle_type,
                'plate' => $rider->plate_number,
            ])
            ->values()
            ->all();

        return view(
            'logistics.pickups.index',
            $this->shared() + [
                'pickups' => $pickups->all(),
                'availableRiders' => $availableRiders,
                'pickupMetrics' => [
                    'awaiting_review' =>
                        $pickupRequests
                            ->where('status', 'requested')
                            ->count(),
                    'scheduled_today' =>
                        $pickupRequests
                            ->filter(fn ($pickup) =>
                                $pickup->status === 'scheduled'
                                && $pickup->requested_date
                                    ?->isToday()
                            )
                            ->count(),
                    'scheduled_parcels' =>
                        $pickupRequests
                            ->filter(fn ($pickup) =>
                                $pickup->status === 'scheduled'
                                && $pickup->requested_date
                                    ?->isToday()
                            )
                            ->sum(fn ($pickup) =>
                                $pickup->parcels->count()
                            ),
                    'in_pickup' =>
                        $pickupRequests
                            ->filter(fn ($pickup) =>
                                $pickup->status === 'scheduled'
                                && in_array(
                                    $pickup->latestAssignment?->status,
                                    [
                                        'accepted',
                                        'arrived',
                                        'picked_up',
                                    ],
                                    true
                                )
                            )
                            ->count(),
                    'collected_today' =>
                        $pickupRequests
                            ->filter(fn ($pickup) =>
                                $pickup->status === 'completed'
                                && $pickup->completed_at
                                    ?->isToday()
                            )
                            ->sum(fn ($pickup) =>
                                $pickup->parcels->count()
                            ),
                    'completed_sellers' =>
                        $pickupRequests
                            ->filter(fn ($pickup) =>
                                $pickup->status === 'completed'
                                && $pickup->completed_at
                                    ?->isToday()
                            )
                            ->pluck('store_id')
                            ->unique()
                            ->count(),
                ],
            ]
        );
    }

    public function verifyPickup(
        PickupRequest $pickupRequest
    ): RedirectResponse {
        /** @var User $operator */
        $operator = Auth::user();

        $pickupRequest = $this->ownedPickupRequest(
            $operator,
            $pickupRequest->id
        );

        abort_unless(
            $pickupRequest->status === 'requested',
            409
        );

        abort_unless(
            $pickupRequest
                ->parcels()
                ->whereHas(
                    'shipment',
                    fn ($query) => $query
                        ->where(
                            'logistics_profile_id',
                            $pickupRequest
                                ->logistics_profile_id
                        )
                        ->whereHas(
                            'sellerOrder',
                            fn ($sellerOrderQuery) =>
                                $sellerOrderQuery->where(
                                    'store_id',
                                    $pickupRequest->store_id
                                )
                        )
                )
                ->exists(),
            409
        );

        $pickupRequest->update([
            'status' => 'verified',
            'verified_by' => $operator->id,
            'verified_at' => now(),
        ]);

        return back()->with(
            'success',
            "{$pickupRequest->pickup_no} was verified."
        );
    }

    public function cancelPickup(
        Request $request,
        PickupRequest $pickupRequest
    ): RedirectResponse {
        /** @var User $operator */
        $operator = Auth::user();

        $pickupRequest = $this->ownedPickupRequest(
            $operator,
            $pickupRequest->id
        );

        abort_unless(
            in_array(
                $pickupRequest->status,
                ['requested', 'verified'],
                true
            ),
            409
        );

        $validated = $request->validate([
            'cancellation_reason' => [
                'required',
                'string',
                'max:1000',
            ],
        ]);

        $pickupRequest->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => trim(
                $validated['cancellation_reason']
            ),
        ]);

        return back()->with(
            'success',
            "{$pickupRequest->pickup_no} was rejected."
        );
    }

    public function assignPickup(
        Request $request,
        PickupRequest $pickupRequest
    ): RedirectResponse {
        /** @var User $operator */
        $operator = Auth::user();

        $pickupRequest = $this->ownedPickupRequest(
            $operator,
            $pickupRequest->id
        );

        abort_unless(
            $pickupRequest->status === 'verified',
            409
        );

        $validated = $request->validate([
            'rider_profile_id' => [
                'required',
                'integer',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        $profile = $this->currentLogisticsProfile(
            $operator
        );

        abort_unless($profile, 404);

        $rider = RiderProfile::query()
            ->whereKey($validated['rider_profile_id'])
            ->where('logistics_profile_id', $profile->id)
            ->where('verification_status', 'approved')
            ->where('availability_status', 'available')
            ->whereHas(
                'user',
                fn ($query) => $query->where(
                    'status',
                    AccountStatus::Active->value
                )
            )
            ->firstOrFail();

        DB::transaction(function () use (
            $operator,
            $pickupRequest,
            $rider,
            $validated,
            $profile
        ): void {
            PickupAssignment::query()->create([
                'pickup_request_id' =>
                    $pickupRequest->id,
                'rider_profile_id' =>
                    $rider->id,
                'status' => 'assigned',
                'assigned_by' => $operator->id,
                'assigned_at' => now(),
                'notes' => isset($validated['notes'])
                    ? trim($validated['notes'])
                    : null,
            ]);

            $pickupRequest->update([
                'status' => 'scheduled',
            ]);

            $shipments = $pickupRequest
                ->parcels()
                ->whereHas(
                    'shipment',
                    fn ($query) => $query
                        ->where(
                            'logistics_profile_id',
                            $profile->id
                        )
                        ->where(
                            'status',
                            ShipmentStatus::ReadyForPickup->value
                        )
                        ->whereHas(
                            'sellerOrder',
                            fn ($sellerOrderQuery) =>
                                $sellerOrderQuery->where(
                                    'store_id',
                                    $pickupRequest->store_id
                                )
                        )
                )
                ->with('shipment')
                ->get()
                ->pluck('shipment')
                ->filter()
                ->unique('id');

            foreach ($shipments as $shipment) {
                $fromStatus = $shipment->status;

                $shipment->update([
                    'status' =>
                        ShipmentStatus::PickupAssigned->value,
                ]);

                ShipmentEvent::query()->create([
                    'shipment_id' => $shipment->id,
                    'event_type' => 'pickup_assigned',
                    'from_status' => $fromStatus,
                    'to_status' =>
                        ShipmentStatus::PickupAssigned->value,
                    'actor_user_id' => $operator->id,
                    'source' => 'logistics',
                    'notes' =>
                        "Assigned through {$pickupRequest->pickup_no}.",
                    'metadata' => [
                        'pickup_request_id' =>
                            $pickupRequest->id,
                        'rider_profile_id' =>
                            $rider->id,
                    ],
                    'occurred_at' => now(),
                ]);
            }
        }, 3);

        return back()->with(
            'success',
            "{$pickupRequest->pickup_no} was assigned for pickup."
        );
    }

    private function ownedPickupRequest(
        User $operator,
        int $pickupRequestId
    ): PickupRequest {
        $profile = $this->currentLogisticsProfile(
            $operator
        );

        abort_unless($profile, 404);

        return PickupRequest::query()
            ->forLogisticsProfile($profile->id)
            ->findOrFail($pickupRequestId);
    }

    private function pickupStatusLabel(
        string $status
    ): string {
        return match ($status) {
            'requested' => 'Pending',
            'verified' => 'Verified',
            'scheduled' => 'Scheduled',
            'completed' => 'Collected',
            'cancelled' => 'Rejected',
            default => ucwords(
                str_replace('_', ' ', $status)
            ),
        };
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

    public function account(): View
    {
        /** @var User $operator */
        $operator = Auth::user();

        $center = $this->activeSortingCenter(
            $operator
        );

        return view(
            'logistics.profile.index',
            $this->shared() + [
                'facility' => [
                    'id' => $center?->id,
                    'business_name' =>
                        $center?->name
                        ?: $operator->business_name
                        ?: $operator->name,
                    'code' => $center?->code ?: '',
                    'contact' =>
                        $center?->contact_phone
                        ?: $operator->contact_number
                        ?: '',
                    'address' => $this->facilityAddress(
                        $center,
                        $operator
                    ),
                    'operating_hours' =>
                        $center?->operating_hours
                        ?: '',
                    'daily_capacity' =>
                        $center?->daily_capacity
                        ?: '',
                    'status' =>
                        $center?->status
                        ?: 'active',
                ],
            ]
        );
    }

    public function updateProfile(Request $request)
    {
        /** @var User $operator */
        $operator = $request->user();

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:160',
            ],
            'contact' => [
                'required',
                'string',
                'max:30',
            ],
        ]);

        $operator->update([
            'name' => trim($validated['name']),
            'contact_number' => trim($validated['contact']),
        ]);

        return redirect(
            route('logistics.profile.index') . '#profile'
        )->with(
            'success',
            'Operator profile updated successfully.'
        );
    }

    public function updatePassword(Request $request)
    {
        /** @var User $operator */
        $operator = $request->user();

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
            $operator->password
        )) {
            return redirect(
                route('logistics.profile.index') . '#security'
            )->withErrors([
                'current_password' =>
                    'The current password is incorrect.',
            ]);
        }

        $operator->update([
            'password' => Hash::make(
                $validated['new_password']
            ),
        ]);

        return redirect(
            route('logistics.profile.index') . '#security'
        )->with(
            'success',
            'Password updated successfully.'
        );
    }

    public function updateFacility(Request $request)
    {
        /** @var User $operator */
        $operator = $request->user();

        $center = $this->activeSortingCenter(
            $operator
        );

        abort_unless($center, 404);

        $validated = $request->validate([
            'business_name' => [
                'required',
                'string',
                'max:160',
            ],
            'contact' => [
                'required',
                'string',
                'max:30',
            ],
            'operating_hours' => [
                'required',
                'string',
                'max:100',
            ],
            'daily_capacity' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        $center->update([
            'name' => trim(
                $validated['business_name']
            ),
            'contact_phone' => trim(
                $validated['contact']
            ),
            'operating_hours' => trim(
                $validated['operating_hours']
            ),
            'daily_capacity' =>
                $validated['daily_capacity'],
        ]);

        return redirect(
            route('logistics.profile.index') . '#facility'
        )->with(
            'success',
            'Facility settings updated successfully.'
        );
    }
}

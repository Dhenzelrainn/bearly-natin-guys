<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\ParcelStatus;
use App\Enums\ShipmentStatus;
use App\Enums\DeliveryAttemptOutcome;
use App\Enums\UserRole;
use App\Models\Parcel;
use App\Models\PickupAssignment;
use App\Models\PickupRequest;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\AccountApplication;
use App\Models\LogisticsProfile;
use App\Models\RiderProfile;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\DispatchBatch;
use App\Models\User;
use App\Models\Conversation;
use App\Models\Waybill;
use App\Services\DispatchService;
use App\Services\EmailVerificationService;
use App\Services\InternationalPhone;
use App\Services\ParcelSortingService;
use App\Services\RegistrationLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;

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

    /**
     * @return array<int, string>
     */
    private function sortingExceptionStatuses(): array
    {
        return [
            ParcelStatus::Failed->value,
            ParcelStatus::Lost->value,
            ParcelStatus::Damaged->value,
        ];
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

        $profile = $this->currentLogisticsProfile(
            $operator
        );

        if (
            $profile
            && Schema::hasTable('account_applications')
        ) {
            $pendingRiderCount =
                AccountApplication::query()
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

        $center = $this->activeSortingCenter(
            $operator
        );

        $receivedTodayCount = 0;
        $awaitingSortingCount = 0;
        $sortedReadyCount = 0;
        $dispatchedShipmentCount = 0;
        $activeRouteCount = 0;
        $zones = collect();
        $sortingExceptionCount = 0;

        if ($profile && $center) {
            $ownedParcels = fn () =>
                Parcel::query()
                    ->where(
                        'current_sorting_center_id',
                        $center->id
                    )
                    ->whereHas(
                        'shipment',
                        fn ($query) => $query->where(
                            'logistics_profile_id',
                            $profile->id
                        )
                    );

            $sortingExceptionCount =
                $ownedParcels()
                    ->whereIn(
                        'status',
                        $this->sortingExceptionStatuses()
                    )
                    ->count();

            $receivedTodayCount = $ownedParcels()
                ->whereHas(
                    'events',
                    fn ($query) => $query
                        ->where(
                            'event_type',
                            'received_at_center'
                        )
                        ->whereDate(
                            'occurred_at',
                            today()
                        )
                )
                ->count();

            $awaitingSortingCount = $ownedParcels()
                ->where(
                    'status',
                    ParcelStatus::Received->value
                )
                ->count();

            $sortedReadyCount = $ownedParcels()
                ->where(
                    'status',
                    ParcelStatus::Sorted->value
                )
                ->whereHas(
                    'shipment',
                    fn ($query) => $query
                        ->where(
                            'logistics_profile_id',
                            $profile->id
                        )
                        ->where(
                            'status',
                            ShipmentStatus::Sorted->value
                        )
                )
                ->whereDoesntHave(
                    'dispatchBatches',
                    fn ($query) => $query
                        ->whereNotIn(
                            'dispatch_batches.status',
                            [
                                'completed',
                                'cancelled',
                            ]
                        )
                )
                ->count();

            $dispatchedShipmentCount =
                Shipment::query()
                    ->where(
                        'logistics_profile_id',
                        $profile->id
                    )
                    ->whereIn('status', [
                        ShipmentStatus::Dispatched->value,
                        ShipmentStatus::OutForDelivery->value,
                    ])
                    ->count();

            $activeRouteCount =
                DispatchBatch::query()
                    ->where(
                        'sorting_center_id',
                        $center->id
                    )
                    ->whereNotIn('status', [
                        'completed',
                        'cancelled',
                    ])
                    ->count();

            $zoneModels = SortingZone::query()
                ->where(
                    'sorting_center_id',
                    $center->id
                )
                ->where(
                    'status',
                    'active'
                )
                ->orderBy('code')
                ->get();

            $zoneIds = $zoneModels->pluck('id');

            $availableRidersByZone =
                RiderProfile::query()
                    ->where(
                        'logistics_profile_id',
                        $profile->id
                    )
                    ->where(
                        'home_sorting_center_id',
                        $center->id
                    )
                    ->whereIn(
                        'current_zone_id',
                        $zoneIds
                    )
                    ->where(
                        'verification_status',
                        'approved'
                    )
                    ->where(
                        'availability_status',
                        'available'
                    )
                    ->whereHas(
                        'user',
                        fn ($query) => $query->where(
                            'status',
                            AccountStatus::Active->value
                        )
                    )
                    ->get([
                        'id',
                        'current_zone_id',
                    ])
                    ->groupBy('current_zone_id')
                    ->map(
                        fn ($riders) =>
                            $riders->count()
                    );

            $zones = $zoneModels
                ->map(function (
                    SortingZone $zone
                ) use (
                    $center,
                    $profile,
                    $availableRidersByZone
                ): array {
                    $parcelQuery = fn () =>
                        Parcel::query()
                            ->where(
                                'current_sorting_center_id',
                                $center->id
                            )
                            ->where(
                                'current_zone_id',
                                $zone->id
                            )
                            ->where(
                                'status',
                                ParcelStatus::Sorted->value
                            )
                            ->whereHas(
                                'shipment',
                                fn ($query) => $query->where(
                                    'logistics_profile_id',
                                    $profile->id
                                )
                            );

                    $parcelCount =
                        $parcelQuery()->count();

                    $readyCount =
                        $parcelQuery()
                            ->whereHas(
                                'shipment',
                                fn ($query) => $query
                                    ->where(
                                        'logistics_profile_id',
                                        $profile->id
                                    )
                                    ->where(
                                        'status',
                                        ShipmentStatus::Sorted->value
                                    )
                            )
                            ->whereDoesntHave(
                                'dispatchBatches',
                                fn ($query) =>
                                    $query->whereNotIn(
                                        'dispatch_batches.status',
                                        [
                                            'completed',
                                            'cancelled',
                                        ]
                                    )
                            )
                            ->count();

                    return [
                        'id' =>
                            $zone->id,

                        'zone' =>
                            $zone->name
                            ?: $zone->code,

                        'code' =>
                            $zone->code,

                        'parcels' =>
                            $parcelCount,

                        'ready' =>
                            $readyCount,

                        'riders' =>
                            (int) $availableRidersByZone
                                ->get(
                                    $zone->id,
                                    0
                                ),
                    ];
                })
                ->values();
        }

        $pendingPickupCount = $profile
            ? PickupRequest::query()
                ->forLogisticsProfile($profile->id)
                ->where('status', 'requested')
                ->count()
            : 0;

        $recentRiderActivity = collect();

        if ($profile) {
            $recentRiderActivity =
                AccountApplication::query()
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
                    ->with('user')
                    ->latest('submitted_at')
                    ->latest('id')
                    ->take(6)
                    ->get()
                    ->map(function (
                        AccountApplication $application
                    ): array {
                        $occurredAt =
                            $application->submitted_at
                            ?? $application->created_at;

                        return [
                            'time' =>
                                $occurredAt
                                    ?->diffForHumans()
                                ?? 'Recently',

                            'title' =>
                                'Rider application received',

                            'detail' =>
                                ($application->user?->name
                                    ?: 'Unknown Rider')
                                .' • '
                                .($application->user?->vehicle_type
                                    ?: 'Vehicle not specified'),

                            'occurred_at' =>
                                $occurredAt,
                        ];
                    });
        }

        $recentFulfillmentActivity = collect();

        if ($profile && $center) {
            $recentFulfillmentActivity =
                ShipmentEvent::query()
                    ->whereHas(
                        'shipment',
                        fn ($query) => $query->where(
                            'logistics_profile_id',
                            $profile->id
                        )
                    )
                    ->where(function ($query) use (
                        $center
                    ): void {
                        $query
                            ->whereNull(
                                'sorting_center_id'
                            )
                            ->orWhere(
                                'sorting_center_id',
                                $center->id
                            );
                    })
                    ->with([
                        'parcel:id,parcel_no',
                        'shipment:id,shipment_no',
                    ])
                    ->latest('occurred_at')
                    ->latest('id')
                    ->take(10)
                    ->get()
                    ->map(function (
                        ShipmentEvent $event
                    ): array {
                        $title = match (
                            $event->event_type
                        ) {
                            'received_at_center' =>
                                'Parcel received at center',

                            'parcel_sorted' =>
                                'Parcel sorted',

                            'parcel_resorted' =>
                                'Parcel reassigned to zone',

                            'pickup_assigned' =>
                                'Pickup assigned',

                            'parcel_dispatched' =>
                                'Parcel dispatched',

                            'parcel_out_for_delivery' =>
                                'Parcel out for delivery',

                            'parcel_delivered' =>
                                'Parcel delivered',

                            'parcel_delivery_failed' =>
                                'Delivery attempt failed',

                            'parcel_delivery_retried' =>
                                'Delivery retry started',

                            default =>
                                str($event->event_type)
                                    ->replace('_', ' ')
                                    ->title()
                                    ->toString(),
                        };

                        $reference =
                            $event->parcel?->parcel_no
                            ?: $event->shipment?->shipment_no
                            ?: 'Shipment activity';

                        return [
                            'time' =>
                                $event->occurred_at
                                    ?->diffForHumans()
                                ?? 'Recently',

                            'title' =>
                                $title,

                            'detail' =>
                                $reference
                                .($event->notes
                                    ? ' • '.$event->notes
                                    : ''),

                            'occurred_at' =>
                                $event->occurred_at
                                ?? $event->created_at,
                        ];
                    });
        }

        $recentActivity =
            $recentRiderActivity
                ->concat(
                    $recentFulfillmentActivity
                )
                ->sortByDesc(
                    fn (array $item) =>
                        $item['occurred_at']
                            ?->timestamp
                        ?? 0
                )
                ->take(6)
                ->map(function (array $item): array {
                    unset(
                        $item['occurred_at']
                    );

                    return $item;
                })
                ->values()
                ->all();

        $hour = Carbon::now('Asia/Manila')->hour;

        $greeting = match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };

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
                    'value' => $receivedTodayCount,
                    'icon' => 'package-open',
                    'trend' =>
                        $awaitingSortingCount
                        .' awaiting sorting',
                ],
                [
                    'label' => 'Active Sorting Queue',
                    'value' => $awaitingSortingCount,
                    'icon' => 'truck',
                    'trend' =>
                        $sortedReadyCount
                        .' sorted & ready',
                ],
                [
                    'label' => 'Dispatched Shipments',
                    'value' => $dispatchedShipmentCount,
                    'icon' => 'route',
                    'trend' =>
                        $activeRouteCount
                        .' active '
                        .($activeRouteCount === 1
                            ? 'route'
                            : 'routes'),
                ],
            ],
            'zones' => $zones,
            'activity' => $recentActivity,
            'pendingPickupCount' => $pendingPickupCount,
            'sortingExceptionCount' => $sortingExceptionCount,
            'greeting' => $greeting,
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
        $profile = request()->user()->logisticsProfile;

        abort_unless($profile, 404);

        $sortingCenters = $profile->sortingCenters()
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
        $centerIds = $sortingCenters->pluck('id');

        $waybills = Waybill::query()
            ->forLogisticsProfile($profile->id)
            ->whereHas(
                'parcels',
                fn ($query) => $query->whereIn(
                    'current_sorting_center_id',
                    $centerIds
                )
            )
            ->with([
                'shipment.sellerOrder.order',
                'shipment.sellerOrder.store',
                'parcels' => fn ($query) => $query
                    ->whereIn('current_sorting_center_id', $centerIds)
                    ->with([
                        'events',
                        'pickupRequests.latestAssignment.riderProfile.user',
                    ]),
            ])
            ->latest('updated_at')
            ->get();

        $exceptionStatuses =
            $this->sortingExceptionStatuses();

        $incomingParcels = $waybills->map(function (Waybill $waybill) use (
            $exceptionStatuses
        ): array {
            $parcels = $waybill->parcels;
            $order = $waybill->shipment?->sellerOrder?->order;
            $assignment = $parcels
                ->flatMap->pickupRequests
                ->sortByDesc('created_at')
                ->first()
                ?->latestAssignment;
            $receivedAt = $parcels
                ->flatMap->events
                ->where('event_type', 'received_at_center')
                ->sortByDesc('occurred_at')
                ->first()
                ?->occurred_at;

            $status = $parcels->contains(
                fn (Parcel $parcel) => in_array(
                    $parcel->status,
                    $exceptionStatuses,
                    true
                )
            )
                ? 'Exception'
                : ($parcels->isNotEmpty()
                    && $parcels->every(
                        fn (Parcel $parcel) =>
                            $parcel->status === ParcelStatus::Sorted->value
                    )
                        ? 'Sorted'
                        : 'Received');

            return [
                'waybill' => $waybill->waybill_no,
                'order' => $waybill->shipment
                    ?->sellerOrder
                    ?->seller_order_no ?: '—',
                'seller' => $waybill->shipment
                    ?->sellerOrder
                    ?->store
                    ?->name ?: 'Unknown Seller',
                'rider' => $assignment
                    ?->riderProfile
                    ?->user
                    ?->name ?: 'Not recorded',
                'received' => $receivedAt
                    ?->format('M j, Y · g:i A') ?: 'Not recorded',
                'pieces' => $parcels->count(),
                'weight' => number_format(
                    (float) $parcels->sum('weight_kg'),
                    2
                ).' kg',
                'destination' => collect([
                    $order?->city_municipality,
                    $order?->province,
                ])->filter()->implode(', ') ?: 'Not provided',
                'status' => $status,
            ];
        })->values();

        $receivedToday = Parcel::query()
            ->whereIn('current_sorting_center_id', $centerIds)
            ->whereHas(
                'shipment',
                fn ($query) => $query->where(
                    'logistics_profile_id',
                    $profile->id
                )
            )
            ->whereHas(
                'events',
                fn ($query) => $query
                    ->where('event_type', 'received_at_center')
                    ->whereDate('occurred_at', today())
            )
            ->with('shipment.sellerOrder')
            ->get();

        return view('logistics.sorting.incoming', $this->shared() + [
            'incomingParcels' => $incomingParcels,
            'sortingCenters' => $sortingCenters,
            'intakeMetrics' => [
                'received_today' => $receivedToday->count(),
                'seller_pickups' => $receivedToday
                    ->pluck('shipment.sellerOrder.store_id')
                    ->filter()
                    ->unique()
                    ->count(),
                'awaiting_sorting' => Parcel::query()
                    ->whereIn('current_sorting_center_id', $centerIds)
                    ->whereHas(
                        'shipment',
                        fn ($query) => $query->where(
                            'logistics_profile_id',
                            $profile->id
                        )
                    )
                    ->where('status', ParcelStatus::Received->value)
                    ->count(),
                'total_weight' => number_format(
                    (float) $receivedToday->sum('weight_kg'),
                    2
                ),
                'exceptions' => Parcel::query()
                    ->whereIn('current_sorting_center_id', $centerIds)
                    ->whereHas(
                        'shipment',
                        fn ($query) => $query->where(
                            'logistics_profile_id',
                            $profile->id
                        )
                    )
                    ->whereIn('status', $exceptionStatuses)
                    ->count(),
            ],
        ]);
    }

    public function sorting(): View
    {
        $profile = request()->user()->logisticsProfile;

        abort_unless($profile, 404);

        $sortingCenters = $profile->sortingCenters()
            ->where('status', 'active')
            ->with([
                'zones' => fn ($query) => $query
                    ->where('status', 'active')
                    ->orderBy('code'),
            ])
            ->orderBy('name')
            ->get();
        $centerIds = $sortingCenters->pluck('id');
        $zonesByCenter = $sortingCenters->mapWithKeys(
            fn (SortingCenter $center): array => [
                $center->id => $center->zones
                    ->map(fn (SortingZone $zone): array => [
                        'id' => $zone->id,
                        'code' => $zone->code,
                        'name' => $zone->name,
                    ])
                    ->values()
                    ->all(),
            ]
        );

        $exceptionStatuses =
            $this->sortingExceptionStatuses();

        $parcelRecords = Parcel::query()
            ->whereIn('current_sorting_center_id', $centerIds)
            ->whereIn('status', [
                ParcelStatus::Received->value,
                ParcelStatus::Sorted->value,
                ...$exceptionStatuses,
            ])
            ->whereHas(
                'shipment',
                fn ($query) => $query->where(
                    'logistics_profile_id',
                    $profile->id
                )
            )
            ->with([
                'waybill',
                'shipment.sellerOrder.order',
                'shipment.sellerOrder.store',
                'currentZone',
            ])
            ->latest('last_event_at')
            ->get();

        $parcels = $parcelRecords->map(function (Parcel $parcel) use (
            $exceptionStatuses,
            $zonesByCenter
        ): array {
            $order = $parcel->shipment?->sellerOrder?->order;
            $isException = in_array(
                $parcel->status,
                $exceptionStatuses,
                true
            );

            return [
                'id' => $parcel->id,
                'waybill' => $parcel->waybill?->waybill_no
                    ?: $parcel->parcel_no,
                'parcel_no' => $parcel->parcel_no,
                'seller' => $parcel->shipment
                    ?->sellerOrder
                    ?->store
                    ?->name ?: 'Unknown Seller',
                'destination' => collect([
                    $order?->city_municipality,
                    $order?->province,
                ])->filter()->implode(', ') ?: 'Not provided',
                'zone_id' => $parcel->current_zone_id,
                'zone' => $parcel->currentZone?->code ?: '',
                'zones' => $zonesByCenter
                    ->get($parcel->current_sorting_center_id, []),
                'size' => $parcel->size_class
                    ? ucwords($parcel->size_class)
                    : 'Not classified',
                'status' => $isException
                    ? 'Exception'
                    : ucfirst($parcel->status),
                'can_sort' => ! $isException,
            ];
        })->values();

        return view('logistics.sorting.center', $this->shared() + [
            'parcels' => $parcels,
            'sortingZones' => $sortingCenters
                ->flatMap->zones
                ->values(),
            'sortingMetrics' => [
                'unsorted' => $parcelRecords
                    ->where('status', ParcelStatus::Received->value)
                    ->count(),
                'sorted_today' => ShipmentEvent::query()
                    ->whereIn('sorting_center_id', $centerIds)
                    ->whereHas(
                        'shipment',
                        fn ($query) => $query->where(
                            'logistics_profile_id',
                            $profile->id
                        )
                    )
                    ->whereIn('event_type', [
                        'parcel_sorted',
                        'parcel_resorted',
                    ])
                    ->whereDate('occurred_at', today())
                    ->count(),
                'active_zones' => $sortingCenters
                    ->sum(fn (SortingCenter $center) =>
                        $center->zones->count()
                    ),
                'exceptions' => $parcelRecords
                    ->whereIn('status', $exceptionStatuses)
                    ->count(),
            ],
        ]);
    }

    public function sortParcel(
        Request $request,
        Parcel $parcel,
        ParcelSortingService $service
    ): RedirectResponse {
        $data = $request->validate([
            'sorting_zone_id' => [
                'required',
                'integer',
            ],
        ]);

        $profile = $request->user()->logisticsProfile;

        abort_unless($profile, 403);

        $parcel = Parcel::query()
            ->whereHas(
                'shipment',
                fn ($query) => $query->where(
                    'logistics_profile_id',
                    $profile->id
                )
            )
            ->whereHas(
                'currentSortingCenter',
                fn ($query) => $query
                    ->where('logistics_profile_id', $profile->id)
                    ->where('status', 'active')
            )
            ->findOrFail($parcel->id);

        $zone = SortingZone::query()
            ->where('sorting_center_id', $parcel->current_sorting_center_id)
            ->where('status', 'active')
            ->whereHas(
                'sortingCenter',
                fn ($query) => $query->where(
                    'logistics_profile_id',
                    $profile->id
                )
            )
            ->findOrFail($data['sorting_zone_id']);

        $service->sort($parcel, $zone, $request->user());

        return back()->with(
            'success',
            "{$parcel->parcel_no} was assigned to {$zone->code}."
        );
    }

    public function dispatch(): View
    {
        /** @var User $operator */
        $operator = Auth::user();

        $profile = $this->currentLogisticsProfile(
            $operator
        );

        abort_unless($profile, 404);

        $center = $this->activeSortingCenter(
            $operator
        );

        /*
        * Keep the page zero-safe for a valid Logistics account
        * that currently has no provisioned facility.
        */
        if (! $center) {
            return view(
                'logistics.dispatch.index',
                $this->shared() + [
                    'zones' => collect(),
                    'riderCapacities' => collect(),
                    'metrics' => [
                        'ready' => 0,
                        'ready_zones' => 0,
                        'available_riders' => 0,
                        'active_routes' => 0,
                        'in_transit' => 0,
                    ],
                    'dispatchCenter' => null,
                ]
            );
        }

        $zoneModels = $center
            ->zones()
            ->where('status', 'active')
            ->orderBy('code')
            ->get();

        $zoneIds = $zoneModels->pluck('id');

        /*
        * Load only riders that are valid dispatch candidates
        * for this provider and active Sorting Center.
        *
        * Their active batch parcel counts are used to derive
        * remaining capacity.
        */
        $riders = RiderProfile::query()
            ->where(
                'logistics_profile_id',
                $profile->id
            )
            ->where(
                'home_sorting_center_id',
                $center->id
            )
            ->whereIn(
                'current_zone_id',
                $zoneIds
            )
            ->where(
                'verification_status',
                'approved'
            )
            ->where(
                'availability_status',
                'available'
            )
            ->whereHas(
                'user',
                fn ($query) => $query->where(
                    'status',
                    AccountStatus::Active->value
                )
            )
            ->with([
                'user',
                'dispatchBatches' => fn ($query) =>
                    $query
                        ->whereNotIn(
                            'status',
                            [
                                'completed',
                                'cancelled',
                            ]
                        )
                        ->withCount('parcels'),
            ])
            ->orderBy('id')
            ->get()
            ->map(function (RiderProfile $rider): array {
                $activeLoad = (int) $rider
                    ->dispatchBatches
                    ->sum('parcels_count');

                $capacity = (int) (
                    $rider->parcel_capacity ?? 0
                );

                return [
                    'id' => $rider->id,

                    'zone_id' =>
                        $rider->current_zone_id,

                    'name' =>
                        $rider->user?->name
                        ?? 'Unnamed rider',

                    'load' =>
                        $activeLoad,

                    'capacity' =>
                        $capacity,

                    'remaining' =>
                        max(
                            0,
                            $capacity - $activeLoad
                        ),
                ];
            });

        $ridersByZone = $riders
            ->groupBy('zone_id');

        $zones = $zoneModels
            ->map(function (
                SortingZone $zone
            ) use (
                $center,
                $profile,
                $ridersByZone
            ): array {
                $ready = Parcel::query()
                    ->where(
                        'current_sorting_center_id',
                        $center->id
                    )
                    ->where(
                        'current_zone_id',
                        $zone->id
                    )
                    ->where(
                        'status',
                        ParcelStatus::Sorted->value
                    )
                    ->whereHas(
                        'shipment',
                        fn ($query) => $query
                            ->where(
                                'logistics_profile_id',
                                $profile->id
                            )
                            ->where(
                                'status',
                                ShipmentStatus::Sorted->value
                            )
                    )
                    ->whereDoesntHave(
                        'dispatchBatches',
                        fn ($query) =>
                            $query->whereNotIn(
                                'dispatch_batches.status',
                                [
                                    'completed',
                                    'cancelled',
                                ]
                            )
                    )
                    ->count();

                /*
                * DispatchService releases the whole ready zone
                * as one batch, so only show riders that can
                * currently carry the entire ready parcel count.
                */
                $eligibleRiders = collect(
                    $ridersByZone->get(
                        $zone->id,
                        collect()
                    )
                )
                    ->filter(
                        fn (array $rider) =>
                            $ready > 0
                            && $rider['remaining'] >= $ready
                    )
                    ->values();

                return [
                    'id' => $zone->id,
                    'zone' => $zone->code,
                    'area' => $zone->name,
                    'ready' => $ready,
                    'riders' => $eligibleRiders,
                ];
            })
            ->values();

        $readyTotal = (int) $zones->sum('ready');

        $readyZoneCount = $zones
            ->where('ready', '>', 0)
            ->count();

        $availableRiderCount = $riders
            ->where('remaining', '>', 0)
            ->count();

        $activeRoutes =
            DispatchBatch::query()
                ->whereHas(
                    'sortingCenter',
                    fn ($query) => $query->where(
                        'logistics_profile_id',
                        $profile->id
                    )
                )
                ->whereNotIn(
                    'status',
                    [
                        'completed',
                        'cancelled',
                    ]
                )
                ->count();

        $inTransit = Parcel::query()
            ->whereHas(
                'shipment',
                fn ($query) => $query->where(
                    'logistics_profile_id',
                    $profile->id
                )
            )
            ->whereIn(
                'status',
                [
                    ParcelStatus::Dispatched->value,
                    ParcelStatus::OutForDelivery->value,
                ]
            )
            ->count();

        return view(
            'logistics.dispatch.index',
            $this->shared() + [
                'zones' => $zones,

                'riderCapacities' =>
                    $riders->values(),

                'dispatchCenter' =>
                    $center,

                'metrics' => [
                    'ready' =>
                        $readyTotal,

                    'ready_zones' =>
                        $readyZoneCount,

                    'available_riders' =>
                        $availableRiderCount,

                    'active_routes' =>
                        $activeRoutes,

                    'in_transit' =>
                        $inTransit,
                ],
            ]
        );
    }

    public function dispatchZone(
        Request $request,
        SortingZone $zone,
        DispatchService $dispatchService
    ): RedirectResponse {
        /** @var User $operator */
        $operator = Auth::user();

        abort_unless(
            $operator instanceof User,
            403
        );

        $validated = $request->validate([
            'rider_profile_id' => [
                'required',
                'integer',
            ],
        ]);

        /*
        * The service still performs the authoritative
        * provider / facility / zone checks.
        */
        $rider = RiderProfile::query()
            ->findOrFail(
                $validated['rider_profile_id']
            );

        $batch = $dispatchService
            ->dispatchZone(
                $zone,
                $rider,
                $operator
            );

        $riderName =
            $batch->riderProfile
                ?->user
                ?->name
            ?? 'the selected rider';

        return redirect()
            ->route('logistics.dispatch.index')
            ->with(
                'success',
                "{$batch->batch_no} was dispatched to {$riderName}."
            );
    }

    public function monitoring(): View
    {
        /** @var User $operator */
        $operator = Auth::user();

        $profile = $this->currentLogisticsProfile(
            $operator
        );

        abort_unless($profile, 404);

        /*
        * Monitoring is read-only from the Logistics side.
        *
        * Delivery state comes from normalized dispatch batches,
        * parcels, and future Rider delivery attempts.
        */
        $batches = DispatchBatch::query()
            ->whereHas(
                'sortingCenter',
                fn ($query) => $query->where(
                    'logistics_profile_id',
                    $profile->id
                )
            )
            ->where(
                'status',
                '!=',
                'cancelled'
            )
            ->with([
                'sortingZone:id,code,name',
                'riderProfile.user:id,name',
                'parcels',
                'deliveryAttempts' => fn ($query) =>
                    $query->orderBy('attempted_at'),
            ])
            ->orderByDesc('dispatched_at')
            ->orderByDesc('id')
            ->get();

        $deliveries = $batches
            ->map(function (DispatchBatch $batch): array {
                $parcels = $batch->parcels;

                $stage = $this->deliveryMonitoringStage(
                    $parcels
                );

                $progress =
                    $this->deliveryMonitoringProgress(
                        $parcels
                    );

                $timeline =
                    $this->deliveryMonitoringTimeline(
                        $batch
                    );

                $latestUpdate =
                    collect($timeline)
                        ->sortByDesc('sort_at')
                        ->first();

                return [
                    'id' =>
                        $batch->batch_no,

                    'rider' =>
                        $batch
                            ->riderProfile
                            ?->user
                            ?->name
                        ?? 'Unassigned rider',

                    'zone' =>
                        $batch
                            ->sortingZone
                            ?->code
                        ?? 'No zone',

                    'area' =>
                        $batch
                            ->sortingZone
                            ?->name
                        ?? '',

                    'parcels' =>
                        $parcels->count(),

                    'progress' =>
                        $progress,

                    'status' =>
                        $stage,

                    'last' =>
                        $latestUpdate
                            ? $latestUpdate['label']
                                .' • '
                                .$latestUpdate['time']
                            : 'No recorded updates',

                    'timeline' =>
                        collect($timeline)
                            ->sortBy('sort_at')
                            ->values()
                            ->all(),
                ];
            })
            ->values();

        /*
        * Metrics remain provider-scoped.
        */
        $assigned = $deliveries
            ->where(
                'status',
                'ASSIGNED_TO_RIDER'
            )
            ->count();

        $outForDeliveryRoutes = $deliveries
            ->where(
                'status',
                'OUT_FOR_DELIVERY'
            )
            ->count();

        $outForDeliveryParcels =
            Parcel::query()
                ->whereHas(
                    'shipment',
                    fn ($query) => $query->where(
                        'logistics_profile_id',
                        $profile->id
                    )
                )
                ->where(
                    'status',
                    ParcelStatus::OutForDelivery->value
                )
                ->count();

        $deliveredToday =
            Parcel::query()
                ->whereHas(
                    'shipment',
                    fn ($query) => $query->where(
                        'logistics_profile_id',
                        $profile->id
                    )
                )
                ->where(
                    'status',
                    ParcelStatus::Delivered->value
                )
                ->whereDate(
                    'last_event_at',
                    today()
                )
                ->count();

        $exceptions =
            Parcel::query()
                ->whereHas(
                    'shipment',
                    fn ($query) => $query->where(
                        'logistics_profile_id',
                        $profile->id
                    )
                )
                ->whereIn(
                    'status',
                    [
                        ParcelStatus::Failed->value,
                        ParcelStatus::Returned->value,
                        ParcelStatus::Lost->value,
                        ParcelStatus::Damaged->value,
                    ]
                )
                ->count();

        return view(
            'logistics.dispatch.monitoring',
            $this->shared() + [
                'deliveries' =>
                    $deliveries,

                'metrics' => [
                    'assigned' =>
                        $assigned,

                    'out_for_delivery_parcels' =>
                        $outForDeliveryParcels,

                    'out_for_delivery_routes' =>
                        $outForDeliveryRoutes,

                    'delivered_today' =>
                        $deliveredToday,

                    'exceptions' =>
                        $exceptions,
                ],
            ]
        );
    }

    private function deliveryMonitoringStage(
        Collection $parcels
    ): string {
        $statuses = $parcels->pluck('status');

        if ($statuses->isEmpty()) {
            return 'ASSIGNED_TO_RIDER';
        }

        $terminalStatuses = [
            ParcelStatus::Delivered->value,
            ParcelStatus::Failed->value,
            ParcelStatus::Returned->value,
            ParcelStatus::Lost->value,
            ParcelStatus::Damaged->value,
        ];

        $exceptionStatuses = [
            ParcelStatus::Failed->value,
            ParcelStatus::Returned->value,
            ParcelStatus::Lost->value,
            ParcelStatus::Damaged->value,
        ];

        $allDelivered = $statuses->every(
            fn ($status) =>
                $status
                === ParcelStatus::Delivered->value
        );

        if ($allDelivered) {
            return 'DELIVERED';
        }

        $allResolved = $statuses->every(
            fn ($status) => in_array(
                $status,
                $terminalStatuses,
                true
            )
        );

        $hasException = $statuses->contains(
            fn ($status) => in_array(
                $status,
                $exceptionStatuses,
                true
            )
        );

        if (
            $allResolved
            && $hasException
        ) {
            return 'DELIVERY_FAILED';
        }

        /*
        * Once any parcel in the route has entered an
        * active or resolved delivery state, the route
        * remains in progress while unresolved parcels
        * still exist.
        */
        $hasDeliveryActivity = $statuses->contains(
            fn ($status) => in_array(
                $status,
                [
                    ParcelStatus::OutForDelivery->value,
                    ParcelStatus::Delivered->value,
                    ParcelStatus::Failed->value,
                    ParcelStatus::Returned->value,
                    ParcelStatus::Lost->value,
                    ParcelStatus::Damaged->value,
                ],
                true
            )
        );

        if ($hasDeliveryActivity) {
            return 'OUT_FOR_DELIVERY';
        }

        return 'ASSIGNED_TO_RIDER';
    }

    private function deliveryMonitoringProgress(
        Collection $parcels
    ): int {
        $total = $parcels->count();

        if ($total === 0) {
            return 0;
        }

        /*
        * Completion means the parcel reached a terminal
        * delivery outcome, successful or exceptional.
        */
        $resolved = $parcels
            ->filter(
                fn (Parcel $parcel) => in_array(
                    $parcel->status,
                    [
                        ParcelStatus::Delivered->value,
                        ParcelStatus::Failed->value,
                        ParcelStatus::Returned->value,
                        ParcelStatus::Lost->value,
                        ParcelStatus::Damaged->value,
                    ],
                    true
                )
            )
            ->count();

        return (int) round(
            ($resolved / $total) * 100
        );
    }

    /**
     * @return array<int, array{
     *     time: string,
     *     label: string,
     *     detail: string,
     *     sort_at: int
     * }>
     */
    private function deliveryMonitoringTimeline(
        DispatchBatch $batch
    ): array {
        $timeline = collect();

        if ($batch->assigned_at) {
            $timeline->push([
                'time' =>
                    $batch->assigned_at
                        ->format('M j, g:i A'),

                'label' =>
                    'Assigned to rider',

                'detail' =>
                    'Dispatch manifest assigned to '
                    .(
                        $batch
                            ->riderProfile
                            ?->user
                            ?->name
                        ?? 'the selected rider'
                    )
                    .'.',

                'sort_at' =>
                    $batch->assigned_at->timestamp,
            ]);
        }

        if ($batch->dispatched_at) {
            $timeline->push([
                'time' =>
                    $batch->dispatched_at
                        ->format('M j, g:i A'),

                'label' =>
                    'Released for delivery',

                'detail' =>
                    "{$batch->parcels->count()} "
                    .str(
                        'parcel'
                    )->plural(
                        $batch->parcels->count()
                    )
                    .' released from the sorting center.',

                'sort_at' =>
                    $batch->dispatched_at->timestamp,
            ]);
        }

        /*
        * Parcel status changes become visible even before
        * the Rider module starts persisting DeliveryAttempt.
        */
        foreach ($batch->parcels as $parcel) {
            if (
                ! $parcel->last_event_at
                || $parcel->status
                    === ParcelStatus::Dispatched->value
            ) {
                continue;
            }

            $eventAt = Carbon::parse(
                $parcel->last_event_at
            );

            $timeline->push([
                'time' =>
                    $eventAt->format(
                        'M j, g:i A'
                    ),

                'label' =>
                    $parcel->parcel_no
                    .': '
                    .str(
                        $parcel->status
                    )
                        ->replace('_', ' ')
                        ->title(),

                'detail' =>
                    'Latest recorded parcel status.',

                'sort_at' =>
                    $eventAt->timestamp,
            ]);
        }

        /*
        * Persisted Rider delivery attempts are included
        * in the Logistics monitoring timeline.
        */
        foreach (
            $batch->deliveryAttempts
            as $attempt
        ) {
            $attemptAt =
                $attempt->attempted_at
                ?? $attempt->created_at;

            if (! $attemptAt) {
                continue;
            }

            $timeline->push([
                'time' =>
                    $attemptAt->format(
                        'M j, g:i A'
                    ),

                'label' =>
                    'Delivery attempt #'
                    .$attempt->attempt_no
                    .': '
                    .str(
                        $attempt->outcome
                    )
                        ->replace('_', ' ')
                        ->title(),

                'detail' =>
                    $attempt->notes
                    ?: (
                        $attempt->failure_reason
                            ? 'Reason: '
                                .$attempt->failure_reason
                            : 'Delivery attempt recorded.'
                    ),

                'sort_at' =>
                    $attemptAt->timestamp,
            ]);
        }

        return $timeline->values()->all();
    }

    public function reports(
        Request $request
    ): View|RedirectResponse {
        $validated = $request->validate([
            'from' => [
                'nullable',
                'date_format:Y-m-d',
            ],
            'to' => [
                'nullable',
                'date_format:Y-m-d',
            ],
        ]);

        $reportTo = isset($validated['to'])
            ? Carbon::createFromFormat(
                'Y-m-d',
                $validated['to'],
                'Asia/Manila'
            )->endOfDay()
            : Carbon::now('Asia/Manila')->endOfDay();

        $reportFrom = isset($validated['from'])
            ? Carbon::createFromFormat(
                'Y-m-d',
                $validated['from'],
                'Asia/Manila'
            )->startOfDay()
            : $reportTo
                ->copy()
                ->subDays(6)
                ->startOfDay();

        if ($reportFrom->gt($reportTo)) {
            return redirect()
                ->route('logistics.reports.index')
                ->withErrors([
                    'to' =>
                        'The end date must be on or after the start date.',
                ]);
        }

        /** @var User $operator */
        $operator = $request->user();

        $profile = $this->currentLogisticsProfile(
            $operator
        );

        abort_unless($profile, 404);

        /*
        * All report event queries are scoped through the
        * authenticated Logistics provider's shipments.
        */
        $ownedEventQuery = fn () =>
            ShipmentEvent::query()
                ->whereHas(
                    'shipment',
                    fn ($query) => $query->where(
                        'logistics_profile_id',
                        $profile->id
                    )
                );

        /*
        * Throughput represents unique parcels received by
        * this provider during the selected reporting period.
        */
        $throughputEvents = $ownedEventQuery()
            ->where(
                'event_type',
                'received_at_center'
            )
            ->whereNotNull('parcel_id')
            ->whereBetween(
                'occurred_at',
                [
                    $reportFrom,
                    $reportTo,
                ]
            )
            ->orderBy('occurred_at')
            ->get([
                'parcel_id',
                'occurred_at',
            ]);

        $throughput = $throughputEvents
            ->pluck('parcel_id')
            ->unique()
            ->count();
        /*
        * Delivered represents unique parcels completed
        * during the selected reporting period.
        */
        $delivered = $ownedEventQuery()
            ->where(
                'event_type',
                'parcel_delivered'
            )
            ->whereNotNull('parcel_id')
            ->whereBetween(
                'occurred_at',
                [
                    $reportFrom,
                    $reportTo,
                ]
            )
            ->distinct()
            ->count('parcel_id');

        /*
        * Each persisted delivered / failed parcel event
        * represents a resolved delivery outcome.
        *
        * Failed attempts may later be retried, so they remain
        * part of the operational success-rate denominator.
        */
        $successfulDeliveryOutcomes =
            $ownedEventQuery()
                ->where(
                    'event_type',
                    'parcel_delivered'
                )
                ->whereNotNull('parcel_id')
                ->whereBetween(
                    'occurred_at',
                    [
                        $reportFrom,
                        $reportTo,
                    ]
                )
                ->count();

        $failedDeliveryOutcomes =
            $ownedEventQuery()
                ->where(
                    'event_type',
                    'parcel_delivery_failed'
                )
                ->whereNotNull('parcel_id')
                ->whereBetween(
                    'occurred_at',
                    [
                        $reportFrom,
                        $reportTo,
                    ]
                )
                ->count();

        $resolvedDeliveryOutcomes =
            $successfulDeliveryOutcomes
            + $failedDeliveryOutcomes;

        $successRate =
            $resolvedDeliveryOutcomes > 0
                ? round(
                    (
                        $successfulDeliveryOutcomes
                        / $resolvedDeliveryOutcomes
                    ) * 100,
                    1
                )
                : 0;

        /*
        * Average sort time uses the initial parcel_sorted
        * event completed inside the selected period.
        *
        * Its matching received_at_center event may have
        * occurred earlier, so long as it happened before
        * that sorting event.
        */
        $sortEvents = $ownedEventQuery()
            ->where(
                'event_type',
                'parcel_sorted'
            )
            ->whereNotNull('parcel_id')
            ->whereBetween(
                'occurred_at',
                [
                    $reportFrom,
                    $reportTo,
                ]
            )
            ->orderBy('occurred_at')
            ->get([
                'id',
                'parcel_id',
                'occurred_at',
            ])
            ->unique('parcel_id')
            ->values();

        $receivedEventsByParcel = collect();

        if ($sortEvents->isNotEmpty()) {
            $receivedEventsByParcel =
                $ownedEventQuery()
                    ->where(
                        'event_type',
                        'received_at_center'
                    )
                    ->whereIn(
                        'parcel_id',
                        $sortEvents
                            ->pluck('parcel_id')
                            ->unique()
                            ->values()
                    )
                    ->where(
                        'occurred_at',
                        '<=',
                        $reportTo
                    )
                    ->orderBy('occurred_at')
                    ->get([
                        'id',
                        'parcel_id',
                        'occurred_at',
                    ])
                    ->groupBy('parcel_id');
        }

        $sortDurations = $sortEvents
            ->map(function (
                ShipmentEvent $sortEvent
            ) use (
                $receivedEventsByParcel
            ): ?float {
                $receivedEvent = collect(
                    $receivedEventsByParcel->get(
                        $sortEvent->parcel_id,
                        collect()
                    )
                )
                    ->filter(
                        fn (ShipmentEvent $event) =>
                            $event->occurred_at
                                ->lte(
                                    $sortEvent->occurred_at
                                )
                    )
                    ->sortByDesc('occurred_at')
                    ->first();

                if (! $receivedEvent) {
                    return null;
                }

                return $receivedEvent
                    ->occurred_at
                    ->diffInMinutes(
                        $sortEvent->occurred_at
                    );
            })
            ->filter(
                fn ($minutes) =>
                    $minutes !== null
            );

        $averageSortTime =
            $sortDurations->isNotEmpty()
                ? round(
                    $sortDurations->avg()
                ).'m'
                : '—';

        /*
        * Build one throughput bucket for every day in the
        * selected reporting period, including zero-volume days.
        */
        $dailyVolumeByDate = $throughputEvents
            ->groupBy(
                fn (ShipmentEvent $event) =>
                    $event->occurred_at
                        ->copy()
                        ->timezone('Asia/Manila')
                        ->format('Y-m-d')
            )
            ->map(
                fn ($events) =>
                    $events
                        ->pluck('parcel_id')
                        ->unique()
                        ->count()
            );

        $dailyVolumes = [];
        $dailyLabels = [];

        for (
            $day = $reportFrom
                ->copy()
                ->startOfDay();
            $day->lte($reportTo);
            $day->addDay()
        ) {
            $dateKey = $day->format('Y-m-d');

            $dailyLabels[] =
                $day->format('M j');

            $dailyVolumes[] =
                (int) $dailyVolumeByDate->get(
                    $dateKey,
                    0
                );
        }

        /*
        * Delivery breakdown uses the latest delivery-relevant
        * state reached by each owned parcel during the period.
        *
        * This avoids counting a failed attempt twice when the
        * same parcel is subsequently retried.
        */
        $statusLabels = [
            ParcelStatus::Delivered->value =>
                'Delivered',

            ParcelStatus::OutForDelivery->value =>
                'Out for Delivery',

            ParcelStatus::Failed->value =>
                'Delivery Failed',

            ParcelStatus::Returned->value =>
                'Returned',
        ];

        $latestDeliveryStatuses =
            $ownedEventQuery()
                ->whereNotNull('parcel_id')
                ->whereBetween(
                    'occurred_at',
                    [
                        $reportFrom,
                        $reportTo,
                    ]
                )
                ->whereIn(
                    'to_status',
                    array_keys($statusLabels)
                )
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->get([
                    'id',
                    'parcel_id',
                    'to_status',
                    'occurred_at',
                ])
                ->groupBy('parcel_id')
                ->map(
                    fn ($events) =>
                        $events->last()->to_status
                );

        $statusTotal =
            $latestDeliveryStatuses->count();

        $statusBreakdown = collect($statusLabels)
            ->map(
                function (
                    string $label,
                    string $status
                ) use (
                    $latestDeliveryStatuses,
                    $statusTotal
                ): array {
                    $value =
                        $latestDeliveryStatuses
                            ->filter(
                                fn ($currentStatus) =>
                                    $currentStatus === $status
                            )
                            ->count();

                    return [
                        'label' => $label,

                        'value' => $value,

                        'share' =>
                            $statusTotal > 0
                                ? round(
                                    ($value / $statusTotal)
                                    * 100,
                                    1
                                )
                                : 0,
                    ];
                }
            )
            ->values()
            ->all();

        /*
        * Rider performance is scoped to riders owned by the
        * authenticated Logistics provider.
        *
        * Assigned counts unique parcels from non-cancelled
        * dispatch batches assigned during the selected period.
        *
        * Delivered and failed represent persisted delivery
        * attempt outcomes during the same reporting period.
        */
        $riderStats = RiderProfile::query()
            ->where(
                'logistics_profile_id',
                $profile->id
            )
            ->where(function ($query) use (
                $reportFrom,
                $reportTo,
                $profile
            ): void {
                $query
                    ->whereHas(
                        'dispatchBatches',
                        fn ($batchQuery) =>
                            $batchQuery
                                ->whereNotNull(
                                    'assigned_at'
                                )
                                ->whereBetween(
                                    'assigned_at',
                                    [
                                        $reportFrom,
                                        $reportTo,
                                    ]
                                )
                                ->where(
                                    'status',
                                    '!=',
                                    'cancelled'
                                )
                    )
                    ->orWhereHas(
                        'deliveryAttempts',
                        fn ($attemptQuery) =>
                            $attemptQuery
                                ->whereBetween(
                                    'attempted_at',
                                    [
                                        $reportFrom,
                                        $reportTo,
                                    ]
                                )
                                ->whereIn(
                                    'outcome',
                                    [
                                        DeliveryAttemptOutcome
                                            ::Delivered
                                            ->value,

                                        DeliveryAttemptOutcome
                                            ::Failed
                                            ->value,
                                    ]
                                )
                                ->whereHas(
                                    'parcel.shipment',
                                    fn ($shipmentQuery) =>
                                        $shipmentQuery->where(
                                            'logistics_profile_id',
                                            $profile->id
                                        )
                                )
                    );
            })
            ->with([
                'user:id,name',

                'dispatchBatches' => function (
                    $batchQuery
                ) use (
                    $reportFrom,
                    $reportTo,
                    $profile
                ): void {
                    $batchQuery
                        ->whereNotNull('assigned_at')
                        ->whereBetween(
                            'assigned_at',
                            [
                                $reportFrom,
                                $reportTo,
                            ]
                        )
                        ->where(
                            'status',
                            '!=',
                            'cancelled'
                        )
                        ->with([
                            'parcels' => fn ($parcelQuery) =>
                                $parcelQuery->whereHas(
                                    'shipment',
                                    fn ($shipmentQuery) =>
                                        $shipmentQuery->where(
                                            'logistics_profile_id',
                                            $profile->id
                                        )
                                ),
                        ]);
                },

                'deliveryAttempts' => function (
                    $attemptQuery
                ) use (
                    $reportFrom,
                    $reportTo,
                    $profile
                ): void {
                    $attemptQuery
                        ->whereBetween(
                            'attempted_at',
                            [
                                $reportFrom,
                                $reportTo,
                            ]
                        )
                        ->whereIn(
                            'outcome',
                            [
                                DeliveryAttemptOutcome
                                    ::Delivered
                                    ->value,

                                DeliveryAttemptOutcome
                                    ::Failed
                                    ->value,
                            ]
                        )
                        ->whereHas(
                            'parcel.shipment',
                            fn ($shipmentQuery) =>
                                $shipmentQuery->where(
                                    'logistics_profile_id',
                                    $profile->id
                                )
                        );
                },
            ])
            ->get()
            ->map(function (
                RiderProfile $rider
            ): array {
                $assigned = $rider
                    ->dispatchBatches
                    ->flatMap(
                        fn (DispatchBatch $batch) =>
                            $batch->parcels
                    )
                    ->pluck('id')
                    ->unique()
                    ->count();

                $delivered = $rider
                    ->deliveryAttempts
                    ->where(
                        'outcome',
                        DeliveryAttemptOutcome
                            ::Delivered
                            ->value
                    )
                    ->count();

                $failed = $rider
                    ->deliveryAttempts
                    ->where(
                        'outcome',
                        DeliveryAttemptOutcome
                            ::Failed
                            ->value
                    )
                    ->count();

                $resolved =
                    $delivered + $failed;

                $rate =
                    $resolved > 0
                        ? round(
                            ($delivered / $resolved)
                            * 100,
                            1
                        )
                        : 0;

                return [
                    'name' =>
                        $rider->user?->name
                        ?: 'Unknown Rider',

                    'assigned' =>
                        $assigned,

                    'delivered' =>
                        $delivered,

                    'failed' =>
                        $failed,

                    'rate' =>
                        number_format(
                            $rate,
                            1
                        ).'%',
                ];
            })
            ->sortBy(
                fn (array $rider) =>
                    strtolower($rider['name'])
            )
            ->values()
            ->all();

        return view(
            'logistics.reports.index',
            $this->shared() + [
                'reportRange' => [
                    'from' =>
                        $reportFrom->format('Y-m-d'),

                    'to' =>
                        $reportTo->format('Y-m-d'),
                ],

                'summary' => [
                    'throughput' =>
                        $throughput,

                    'delivered' =>
                        $delivered,

                    'success_rate' =>
                        $successRate,

                    'resolved_delivery_outcomes' =>
                        $resolvedDeliveryOutcomes,

                    'avg_sort_time' =>
                        $averageSortTime,
                ],

                'riderStats' =>
                    $riderStats,

                'dailyVolumes' =>
                    $dailyVolumes,

                'dailyLabels' =>
                    $dailyLabels,

                'statusBreakdown' =>
                    $statusBreakdown,
            ]
        );
    }

    private function messageRecipientDirectory(
        User $operator
    ): Collection {
        $profile =
            $this->currentLogisticsProfile(
                $operator
            );

        if (! $profile) {
            return collect();
        }

        /*
        * Admins:
        * Any active Admin account may receive
        * an operational support conversation.
        */
        $admins = User::query()
            ->where(
                'id',
                '!=',
                $operator->id
            )
            ->where(
                'status',
                AccountStatus::Active->value
            )
            ->get()
            ->filter(
                fn (User $user): bool =>
                    $user->hasRole(
                        UserRole::Admin->value
                    )
            )
            ->map(
                fn (User $user): array => [
                    'id' =>
                        $user->id,

                    'name' =>
                        $user->name,

                    'role' =>
                        UserRole::Admin->value,

                    'label' =>
                        'Administrator',
                ]
            );

        /*
        * Sellers:
        * Only Sellers that already have at least
        * one PickupRequest with this Logistics
        * provider are valid operational contacts.
        */
        $sellers = PickupRequest::query()
            ->forLogisticsProfile(
                $profile->id
            )
            ->with(
                'store.sellerProfile.user'
            )
            ->get()
            ->map(
                fn (
                    PickupRequest $pickupRequest
                ) =>
                    $pickupRequest
                        ->store
                        ?->sellerProfile
                        ?->user
            )
            ->filter(
                fn ($user): bool =>
                    $user instanceof User
                    && $user->status
                        === AccountStatus::Active->value
                    && $user->hasRole(
                        UserRole::Seller->value
                    )
            )
            ->unique('id')
            ->map(
                fn (User $user): array => [
                    'id' =>
                        $user->id,

                    'name' =>
                        $user->name,

                    'role' =>
                        UserRole::Seller->value,

                    'label' =>
                        'Seller',
                ]
            );

        /*
        * Riders:
        * Only approved Riders belonging to the
        * authenticated Logistics provider.
        *
        * Availability is intentionally irrelevant
        * to messaging. An offline Rider may still
        * receive an operational message.
        */
        $riders = RiderProfile::query()
            ->where(
                'logistics_profile_id',
                $profile->id
            )
            ->where(
                'verification_status',
                'approved'
            )
            ->with('user')
            ->get()
            ->map(
                fn (
                    RiderProfile $rider
                ) =>
                    $rider->user
            )
            ->filter(
                fn ($user): bool =>
                    $user instanceof User
                    && $user->status
                        === AccountStatus::Active->value
                    && $user->hasRole(
                        UserRole::Rider->value
                    )
            )
            ->unique('id')
            ->map(
                fn (User $user): array => [
                    'id' =>
                        $user->id,

                    'name' =>
                        $user->name,

                    'role' =>
                        UserRole::Rider->value,

                    'label' =>
                        'Rider',
                ]
            );

        return $admins
            ->concat($sellers)
            ->concat($riders)
            ->unique('id')
            ->sortBy(
                fn (array $recipient): string =>
                    $recipient['role']
                    .'|'
                    .strtolower(
                        $recipient['name']
                    )
            )
            ->values();
    }

    public function messages(
        Request $request
    ): View {
        /** @var User $operator */
        $operator = $request->user();

        $messageRecipients =
            $this->messageRecipientDirectory(
                $operator
            );

        $records = Conversation::query()
            ->whereHas(
                'participants',
                fn ($query) =>
                    $query->where(
                        'users.id',
                        $operator->id
                    )
            )
            ->with([
                'participants',

                'latestMessage',

                'messages' => fn ($query) =>
                    $query
                        ->orderBy('sent_at')
                        ->orderBy('id'),
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        $conversations = $records
            ->map(function (
                Conversation $conversation
            ) use (
                $operator
            ): array {
                $operatorParticipant =
                    $conversation
                        ->participants
                        ->first(
                            fn (User $participant): bool =>
                                (int) $participant->id
                                === (int) $operator->id
                        );

                $otherParticipant =
                    $conversation
                        ->participants
                        ->first(
                            fn (User $participant): bool =>
                                (int) $participant->id
                                !== (int) $operator->id
                        );

                $participantRole = strtolower(
                    (string) (
                        $otherParticipant
                            ?->pivot
                            ?->participant_role
                        ?? $otherParticipant?->role
                        ?? 'participant'
                    )
                );

                $role = match ($participantRole) {
                    'admin' =>
                        'Administrator',

                    'buyer' =>
                        'Buyer',

                    'seller' =>
                        'Seller',

                    'logistics' =>
                        'Logistics',

                    'rider' =>
                        'Rider',

                    default =>
                        ucwords(
                            str_replace(
                                '_',
                                ' ',
                                $participantRole
                            )
                        ),
                };

                $name =
                    $otherParticipant?->name
                    ?: 'Conversation';

                $initials = collect(
                    preg_split(
                        '/\s+/',
                        trim($name)
                    ) ?: []
                )
                    ->filter()
                    ->take(2)
                    ->map(
                        fn (string $part): string =>
                            strtoupper(
                                substr(
                                    $part,
                                    0,
                                    1
                                )
                            )
                    )
                    ->implode('');

                if ($initials === '') {
                    $initials = '?';
                }

                $lastReadValue =
                    $operatorParticipant
                        ?->pivot
                        ?->last_read_at;

                $lastReadAt =
                    $lastReadValue
                        ? Carbon::parse(
                            $lastReadValue
                        )
                        : null;

                $unread = $conversation
                    ->messages
                    ->filter(
                        function (
                            $message
                        ) use (
                            $operator,
                            $lastReadAt
                        ): bool {
                            if (
                                (int) $message->sender_id
                                === (int) $operator->id
                            ) {
                                return false;
                            }

                            if (! $message->sent_at) {
                                return false;
                            }

                            return
                                ! $lastReadAt
                                || $message
                                    ->sent_at
                                    ->isAfter(
                                        $lastReadAt
                                    );
                        }
                    )
                    ->count();

                $latestMessage =
                    $conversation
                        ->latestMessage;

                $latestBody = trim(
                    (string) (
                        $latestMessage?->body
                        ?? ''
                    )
                );

                return [
                    'id' =>
                        $conversation->id,

                    'name' =>
                        $name,

                    'role' =>
                        $role,

                    'initials' =>
                        $initials,

                    'preview' =>
                        $latestBody !== ''
                            ? $latestBody
                            : (
                                $latestMessage
                                    ? 'Attachment'
                                    : 'No messages yet.'
                            ),

                    'time' =>
                        $latestMessage
                            ?->sent_at
                            ?->copy()
                            ->timezone(
                                'Asia/Manila'
                            )
                            ->format(
                                'M j, g:i A'
                            )
                        ?? '',

                    'unread' =>
                        $unread,

                    'subject' =>
                        $conversation->subject,

                    'status' =>
                        $conversation->status,

                    'type' =>
                        $conversation->type,

                    'send_url' =>
                        route(
                            'logistics.messages.send',
                            $conversation
                        ),

                    'messages' =>
                        $conversation
                            ->messages
                            ->map(
                                fn ($message): array => [
                                    'id' =>
                                        $message->id,

                                    'mine' =>
                                        (int) $message->sender_id
                                        === (int) $operator->id,

                                    'text' =>
                                        $message->body,

                                    'time' =>
                                        $message
                                            ->sent_at
                                            ?->copy()
                                            ->timezone(
                                                'Asia/Manila'
                                            )
                                            ->format(
                                                'M j, g:i A'
                                            )
                                        ?? '',
                                ]
                            )
                            ->values()
                            ->all(),
                ];
            })
            ->values();

        return view(
            'logistics.messages.index',
            $this->shared() + [
                'conversations' =>
                    $conversations->all(),

                'conversationData' =>
                    $conversations
                        ->keyBy('id')
                        ->all(),

                'messageRecipients' =>
                    $messageRecipients->all(),
            ]
        );
    }

    public function storeConversation(
        Request $request
    ) {
        /** @var User $operator */
        $operator = $request->user();

        $validated = $request->validate([
            'recipient_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],

            'subject' => [
                'nullable',
                'string',
                'max:180',
            ],

            'message' => [
                'required',
                'string',
                'max:5000',
            ],
        ]);

        if (
            (int) $validated['recipient_id']
            === (int) $operator->id
        ) {
            throw ValidationException::withMessages([
                'recipient_id' =>
                    'You cannot start a conversation with yourself.',
            ]);
        }

        $allowedRecipient =
            $this
                ->messageRecipientDirectory(
                    $operator
                )
                ->first(
                    fn (
                        array $recipient
                    ): bool =>
                        (int) $recipient['id']
                        === (int) $validated[
                            'recipient_id'
                        ]
                );

        if (! $allowedRecipient) {
            throw ValidationException::withMessages([
                'recipient_id' =>
                    'The selected user is not an available Logistics messaging contact.',
            ]);
        }

        $body = trim(
            $validated['message']
        );

        if ($body === '') {
            throw ValidationException::withMessages([
                'message' =>
                    'Please enter a message.',
            ]);
        }

        $subject = trim(
            (string) (
                $validated['subject']
                ?? ''
            )
        );

        $recipient =
            User::query()
                ->findOrFail(
                    $allowedRecipient['id']
                );

        [
            $conversation,
            $message,
        ] = DB::transaction(
            function () use (
                $operator,
                $recipient,
                $allowedRecipient,
                $subject,
                $body
            ) {
                $now = now();

                $conversation =
                    Conversation::query()->create([
                        'subject' =>
                            $subject !== ''
                                ? $subject
                                : null,

                        'type' =>
                            'direct',

                        'status' =>
                            'open',

                        'created_by' =>
                            $operator->id,

                        'last_message_at' =>
                            $now,
                    ]);

                $conversation
                    ->participants()
                    ->attach(
                        $operator->id,
                        [
                            'participant_role' =>
                                UserRole::Logistics
                                    ->value,

                            'last_read_at' =>
                                $now,

                            'joined_at' =>
                                $now,
                        ]
                    );

                $conversation
                    ->participants()
                    ->attach(
                        $recipient->id,
                        [
                            'participant_role' =>
                                $allowedRecipient[
                                    'role'
                                ],

                            'last_read_at' =>
                                null,

                            'joined_at' =>
                                $now,
                        ]
                    );

                $message =
                    $conversation
                        ->messages()
                        ->create([
                            'sender_id' =>
                                $operator->id,

                            'body' =>
                                $body,

                            'message_type' =>
                                'text',

                            'sent_at' =>
                                $now,
                        ]);

                return [
                    $conversation,
                    $message,
                ];
            }
        );

        return response()->json(
            [
                'message' =>
                    'Conversation started successfully.',

                'conversation' => [
                    'id' =>
                        $conversation->id,

                    'recipient_id' =>
                        $recipient->id,

                    'recipient_name' =>
                        $recipient->name,

                    'recipient_role' =>
                        $allowedRecipient[
                            'role'
                        ],

                    'latest_message' =>
                        $message->body,
                ],

                'redirect_url' =>
                    route(
                        'logistics.messages.index'
                    ),
            ],
            201
        );
    }

    public function sendMessage(
        Request $request,
        Conversation $conversation
    ) {
        /** @var User $operator */
        $operator = $request->user();

        $validated = $request->validate([
            'message' => [
                'required',
                'string',
                'max:5000',
            ],
        ]);

        $isParticipant = $conversation
            ->participants()
            ->where(
                'users.id',
                $operator->id
            )
            ->exists();

        abort_unless(
            $isParticipant,
            404
        );

        $message = DB::transaction(
            function () use (
                $conversation,
                $operator,
                $validated
            ) {
                $lockedConversation =
                    Conversation::query()
                        ->lockForUpdate()
                        ->findOrFail(
                            $conversation->id
                        );

                if (
                    $lockedConversation->status
                    !== 'open'
                ) {
                    throw ValidationException::withMessages([
                        'message' =>
                            'Messages cannot be sent to a closed conversation.',
                    ]);
                }

                $now = now();

                $message =
                    $lockedConversation
                        ->messages()
                        ->create([
                            'sender_id' =>
                                $operator->id,

                            'body' =>
                                trim(
                                    $validated[
                                        'message'
                                    ]
                                ),

                            'message_type' =>
                                'text',

                            'sent_at' =>
                                $now,
                        ]);

                $lockedConversation->update([
                    'last_message_at' =>
                        $now,
                ]);

                DB::table(
                    'conversation_participants'
                )
                    ->where(
                        'conversation_id',
                        $lockedConversation->id
                    )
                    ->where(
                        'user_id',
                        $operator->id
                    )
                    ->update([
                        'last_read_at' =>
                            $now,
                    ]);

                return $message;
            }
        );

        return response()->json([
            'message' =>
                'Message sent successfully.',

            'data' => [
                'id' =>
                    $message->id,

                'mine' =>
                    true,

                'text' =>
                    $message->body,

                'time' =>
                    $message
                        ->sent_at
                        ?->copy()
                        ->timezone(
                            'Asia/Manila'
                        )
                        ->format(
                            'M j, g:i A'
                        )
                    ?? '',
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

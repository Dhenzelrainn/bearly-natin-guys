<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Enums\ParcelStatus;
use App\Models\PickupAssignment;
use App\Models\User;
use App\Services\RiderPickupService;
use App\Services\RiderDeliveryService;
use App\Services\EmailVerificationService;
use App\Services\InternationalPhone;
use App\Services\RegistrationLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class RiderController extends Controller
{
    private function shared(): array
    {
        /** @var User|null $user */
        $user = Auth::user();

        $name = $user?->name ?: 'Bearly Rider';

        $initials = collect(
            preg_split('/\s+/', trim($name))
        )
            ->filter()
            ->take(2)
            ->map(
                fn (string $part) =>
                    strtoupper(
                        mb_substr($part, 0, 1)
                    )
            )
            ->implode('');

        $riderProfile = $user
            ?->riderProfile()
            ->with([
                'logisticsProfile.user',
            ])
            ->first();

        /*
        * Normalized RiderProfile is authoritative.
        *
        * Legacy user vehicle fields remain only as a
        * compatibility fallback for accounts that have
        * not yet been normalized.
        */
        $vehicle =
            $riderProfile?->vehicle_type
            ?: $user?->vehicle_type
            ?: 'Not specified';

        $plate =
            $riderProfile?->plate_number
            ?: $user?->plate_number
            ?: '—';

        /*
        * Prefer the normalized Logistics relationship.
        *
        * Fall back to users.logistics_id only for legacy
        * Rider accounts that have no RiderProfile yet.
        */
        $logisticsUser =
            $riderProfile
                ?->logisticsProfile
                ?->user;

        if (! $logisticsUser && $user?->logistics_id) {
            $logisticsUser = User::query()
                ->whereKey($user->logistics_id)
                ->where(
                    'role',
                    UserRole::Logistics->value
                )
                ->first();
        }

        return [
            'rider' => [
                'name' => $name,

                'initials' =>
                    $initials ?: 'BR',

                'email' =>
                    $user?->email ?: '',

                'role' =>
                    'Rider',

                'vehicle' =>
                    $vehicle,

                'plate' =>
                    $plate,

                'status' =>
                    $user?->status
                        ? ucwords(
                            str_replace(
                                '_',
                                ' ',
                                $user->status
                            )
                        )
                        : 'Unknown',
            ],

            'logistics' => [
                'name' =>
                    $logisticsUser
                        ? (
                            $logisticsUser->business_name
                            ?: $logisticsUser->name
                        )
                        : 'Not assigned',
            ],

            'topNotifications' => [],
        ];
    }

    private function deliveryAssignmentsFor(
        ?User $user
    ): Collection {
        if (! $user) {
            return collect();
        }

        $profile = $user
            ->riderProfile()
            ->first();

        if (! $profile) {
            return collect();
        }

        $batches = $profile
            ->dispatchBatches()
            ->whereNotIn(
                'status',
                [
                    'cancelled',
                ]
            )
            ->with([
                'sortingZone:id,code,name',

                'parcels.shipment.waybill',

                'parcels.shipment.sellerOrder.order',
            ])
            ->orderByDesc('dispatched_at')
            ->orderByDesc('id')
            ->get();

        /*
        * First create one row per
        * batch + shipment combination.
        */
        $rows = $batches
            ->flatMap(
                function ($batch) {
                    return $batch
                        ->parcels
                        ->groupBy('shipment_id')
                        ->map(
                            function (
                                Collection $parcels
                            ) use ($batch) {
                                $shipment =
                                    $parcels
                                        ->first()
                                        ?->shipment;

                                $order =
                                    $shipment
                                        ?->sellerOrder
                                        ?->order;

                                if (
                                    ! $shipment
                                    || ! $order
                                ) {
                                    return null;
                                }

                                $address = collect([
                                    $order->address_line,
                                    $order->barangay,
                                    $order->city_municipality,
                                    $order->province,
                                    $order->postal_code,
                                ])
                                    ->filter()
                                    ->implode(', ');

                                $codMinor =
                                    (int) (
                                        $shipment
                                            ->cod_amount_minor
                                        ?? 0
                                    );

                                return [
                                    'shipment_id' =>
                                        $shipment->id,

                                    'id' =>
                                        $shipment
                                            ->shipment_no,

                                    'waybill' =>
                                        $shipment
                                            ->waybill
                                            ?->waybill_no
                                        ?? 'No waybill',

                                    'customer' =>
                                        $order
                                            ->recipient_name
                                        ?: 'Recipient',

                                    'contact' =>
                                        $order
                                            ->recipient_phone
                                        ?: '',

                                    'address' =>
                                        $address
                                        ?: 'Address unavailable',

                                    'payment_status' =>
                                        $order
                                            ->payment_status
                                        ?: 'unknown',

                                    'cod_minor' =>
                                        $codMinor,

                                    'zone' =>
                                        $batch
                                            ->sortingZone
                                            ?->code
                                        ?? 'Unassigned',

                                    'zone_name' =>
                                        $batch
                                            ->sortingZone
                                            ?->name
                                        ?? '',

                                    'batch_no' =>
                                        $batch->batch_no,

                                    'dispatched_at' =>
                                        $batch
                                            ->dispatched_at,

                                    'parcels' =>
                                        $parcels
                                            ->map(
                                                fn ($parcel) => [
                                                    'id' =>
                                                        $parcel
                                                            ->id,

                                                    'parcel_no' =>
                                                        $parcel
                                                            ->parcel_no,

                                                    'status' =>
                                                        $parcel
                                                            ->status,

                                                    'size_class' =>
                                                        $parcel
                                                            ->size_class,

                                                    'weight_kg' =>
                                                        $parcel
                                                            ->weight_kg,
                                                ]
                                            )
                                            ->values(),
                                ];
                            }
                        );
                }
            )
            ->filter();

        /*
        * A shipment can theoretically appear in
        * more than one dispatch batch. Merge those
        * rows into one customer delivery stop.
        */
        return $rows
            ->groupBy('shipment_id')
            ->map(
                function (Collection $rows) {
                    $first =
                        $rows->first();

                    $parcels = $rows
                        ->pluck('parcels')
                        ->flatten(1)
                        ->unique('id')
                        ->values();

                    $zones = $rows
                        ->pluck('zone')
                        ->filter()
                        ->unique()
                        ->values();

                    $zoneNames = $rows
                        ->pluck('zone_name')
                        ->filter()
                        ->unique()
                        ->values();

                    $batchNumbers = $rows
                        ->pluck('batch_no')
                        ->filter()
                        ->unique()
                        ->values();

                    return array_merge(
                        $first,
                        [
                            'parcels' =>
                                $parcels,

                            'parcel_count' =>
                                $parcels->count(),

                            'status' =>
                                $this
                                    ->deliveryAssignmentStatus(
                                        $parcels
                                    ),

                            'zone' =>
                                $zones->implode(', '),

                            'zone_name' =>
                                $zoneNames
                                    ->implode(', '),

                            'batch_no' =>
                                $batchNumbers
                                    ->implode(', '),

                            'dispatched_at' =>
                                $rows
                                    ->pluck(
                                        'dispatched_at'
                                    )
                                    ->filter()
                                    ->sort()
                                    ->first(),
                        ]
                    );
                }
            )
            ->sortByDesc('dispatched_at')
            ->values();
    }

    private function pickupAssignmentsFor(
        ?User $user
    ): Collection {
        if (! $user) {
            return collect();
        }

        $profile = $user
            ->riderProfile()
            ->first();

        if (! $profile) {
            return collect();
        }

        return $profile
            ->pickupAssignments()
            ->whereNotIn(
                'status',
                [
                    'completed',
                    'cancelled',
                ]
            )
            ->with([
                'pickupRequest.store.sellerProfile.user',
                'pickupRequest.pickupAddress',
                'pickupRequest.parcels.waybill',
            ])
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->get()
            ->map(
                fn (PickupAssignment $assignment): array =>
                    $this->pickupAssignmentRow(
                        $assignment
                    )
            )
            ->filter()
            ->values();
    }

    private function pickupAssignmentRow(
        PickupAssignment $assignment
    ): array {
        $pickup = $assignment->pickupRequest;

        if (! $pickup) {
            return [];
        }

        $store = $pickup->store;
        $address = $pickup->pickupAddress;

        $sellerName =
            $store?->name
            ?: $store?->sellerProfile?->user?->name
            ?: 'Unknown Seller';

        $contact =
            $address?->phone
            ?: $store?->contact_phone
            ?: $store?->sellerProfile?->user?->contact_number
            ?: 'Not provided';

        $addressText = collect([
            $address?->house_number,
            $address?->street,
            $address?->barangay
                ? 'Brgy. '.$address->barangay
                : null,
            $address?->city_municipality,
            $address?->province,
            $address?->postal_code,
        ])
            ->filter(
                fn ($value) =>
                    filled($value)
            )
            ->implode(', ');

        $window =
            $pickup->window_start
            && $pickup->window_end
                ? $pickup->window_start
                    ->format('M j, Y · g:i A')
                    .'–'
                    .$pickup->window_end
                        ->format('g:i A')
                : 'Not scheduled';

        $manifest = $pickup
            ->parcels
            ->map(
                fn ($parcel): array => [
                    'parcel_no' =>
                        $parcel->parcel_no,

                    'waybill' =>
                        $parcel->waybill?->waybill_no
                        ?: 'Not generated',

                    'size' =>
                        $parcel->size_class
                            ? ucwords(
                                str_replace(
                                    '_',
                                    ' ',
                                    $parcel->size_class
                                )
                            )
                            : 'Not specified',

                    'qty' => 1,

                    'status' =>
                        ucwords(
                            str_replace(
                                '_',
                                ' ',
                                $parcel->status
                            )
                        ),
                ]
            )
            ->values()
            ->all();

        return [
            'id' =>
                $pickup->pickup_no,

            'assignment_id' =>
                $assignment->id,

            'seller' =>
                $sellerName,

            'contact' =>
                $contact,

            'address' =>
                $addressText
                    ?: 'Pickup address unavailable',

            'parcels' =>
                count($manifest),

            'window' =>
                $window,

            'status' =>
                ucwords(
                    str_replace(
                        '_',
                        ' ',
                        $assignment->status
                    )
                ),

            'status_raw' =>
                $assignment->status,

            'assigned_at' =>
                $assignment->assigned_at
                    ?->format(
                        'M j, Y · g:i A'
                    )
                ?: 'Not recorded',

            'is_due_today' =>
                $pickup
                    ->requested_date
                    ?->isToday()
                ?? false,

            'instructions' =>
                $pickup->seller_instructions
                ?: 'No special seller instructions.',

            'assignment_notes' =>
                $assignment->notes,

            'manifest' =>
                $manifest,
        ];
    }

    private function deliveryAssignmentStatus(
        Collection $parcels
    ): string {
        $statuses =
            $parcels->pluck('status');

        if (
            $statuses->contains(
                fn ($status) => in_array(
                    $status,
                    [
                        ParcelStatus::Failed->value,
                        ParcelStatus::Returned->value,
                        ParcelStatus::Lost->value,
                        ParcelStatus::Damaged->value,
                    ],
                    true
                )
            )
        ) {
            return 'Delivery Failed';
        }

        if (
            $statuses->isNotEmpty()
            && $statuses->every(
                fn ($status) =>
                    $status
                    === ParcelStatus::Delivered->value
            )
        ) {
            return 'Delivered';
        }

        if (
            $statuses->contains(
                fn ($status) => in_array(
                    $status,
                    [
                        ParcelStatus::OutForDelivery->value,
                        ParcelStatus::Delivered->value,
                    ],
                    true
                )
            )
        ) {
            return 'Out for Delivery';
        }

        return 'Assigned';
    }

    public function landing()
    {
        return view('rider.landing.index');
    }

    public function register(): View
    {
        $logisticsPartners = Schema::hasTable('users')
            ? User::query()
                ->where('role', UserRole::Logistics->value)
                ->where('status', AccountStatus::Active->value)
                ->orderBy('business_name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => (string) $user->id,
                    'name' => $user->business_name ?: $user->name,
                ])
                ->all()
            : [];

        return view('rider.applications.create', compact('logisticsPartners'));
    }

    public function submitRegistration(Request $request)
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'logistics_partner' => ['required', 'integer', 'exists:users,id'],
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
            'vehicle_type' => ['required', 'string', 'max:80'],
            'plate_number' => ['required', 'string', 'max:30'],
            'or_cr' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:5120',
            ],
            'driver_license' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:5120',
            ],
            'terms' => ['accepted'],
            'password' => ['required', 'confirmed', 'min:8', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/'],
        ]);

        $logistics = User::query()
            ->whereKey($validated['logistics_partner'])
            ->where('role', UserRole::Logistics->value)
            ->where('status', AccountStatus::Active->value)
            ->firstOrFail();

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
            'role' => UserRole::Rider->value,
            'status' => AccountStatus::Pending->value,
            'logistics_id' => $logistics->id,
            'province' => $validated['province'],
            'city' => $validated['municipality'],
            'barangay' => $validated['barangay'],
            'street_address' => trim($validated['house_number'].' '.$validated['street']),
            'vehicle_type' => $validated['vehicle_type'],
            'plate_number' => strtoupper($validated['plate_number']),
            'or_cr_path' => $request->file('or_cr')->store('registration-documents/riders/or-cr', 'local'),
            'driver_license_path' => $request->file('driver_license')->store('registration-documents/riders/licenses', 'local'),
            'password' => Hash::make($validated['password']),
        ]);

        app(RegistrationLifecycleService::class)
            ->recordPendingApplication(
                $user,
                UserRole::Rider->value,
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
                [],
                [
                    [
                        'type' => 'or_cr',
                        'path' => $user->or_cr_path,
                        'file' => $request->file('or_cr'),
                    ],
                    [
                        'type' => 'driver_license',
                        'path' => $user->driver_license_path,
                        'file' => $request->file(
                            'driver_license'
                        ),
                    ],
                ],
                $logistics->id
            );
        $verification->forget($request);

        session([
            'rider_application' => [
                'full_name' => $user->name,
                'email' => $user->email,
                'logistics_partner' => $logistics->business_name ?: $logistics->name,
                'status' => 'Awaiting Logistics Approval',
            ],
        ]);

        return redirect()
            ->route('rider.register')
            ->with('registration_pending', true);
    }

    public function pickupsDashboard(): View
    {
        /** @var User|null $user */
        $user = Auth::user();

        $pickups =
            $this->pickupAssignmentsFor(
                $user
            );

        return view(
            'rider.dashboard.pickups',
            $this->shared() + [
                'pickups' =>
                    $pickups,

                'pickupMetrics' => [
                    'assigned' =>
                        $pickups
                            ->where(
                                'status_raw',
                                'assigned'
                            )
                            ->count(),

                    'in_progress' =>
                        $pickups
                            ->whereIn(
                                'status_raw',
                                [
                                    'accepted',
                                    'arrived',
                                    'picked_up',
                                ]
                            )
                            ->count(),

                    'parcels' =>
                        $pickups
                            ->sum('parcels'),

                    'due_today' =>
                        $pickups
                            ->where(
                                'is_due_today',
                                true
                            )
                            ->count(),
                ],
            ]
        );
    }

    public function deliveriesDashboard(): View
    {
        /** @var User|null $user */
        $user = Auth::user();

        $assignments =
            $this->deliveryAssignmentsFor(
                $user
            );

        $deliveries = $assignments
            ->filter(
                fn (array $delivery) =>
                    in_array(
                        $delivery['status'],
                        [
                            'Assigned',
                            'Out for Delivery',
                            'Delivery Failed',
                        ],
                        true
                    )
            )
            ->values();

        $parcels = $deliveries
            ->pluck('parcels')
            ->flatten(1);

        $assignedParcels =
            $parcels
                ->where(
                    'status',
                    ParcelStatus::Dispatched->value
                )
                ->count();

        $outForDelivery =
            $parcels
                ->where(
                    'status',
                    ParcelStatus::OutForDelivery->value
                )
                ->count();

        $codMinor = $deliveries
            ->sum(
                fn (array $delivery) =>
                    (int) $delivery['cod_minor']
            );

        return view(
            'rider.dashboard.deliveries',
            $this->shared() + [
                'deliveries' =>
                    $deliveries,

                'metrics' => [
                    'assigned_parcels' =>
                        $assignedParcels,

                    'out_for_delivery' =>
                        $outForDelivery,

                    'active_stops' =>
                        $deliveries->count(),

                    'cod_minor' =>
                        $codMinor,
                ],
            ]
        );
    }

    public function pickup(
        string $id
    ): View {
        /** @var User|null $user */
        $user = Auth::user();

        $profile = $user
            ?->riderProfile()
            ->first();

        abort_unless(
            $profile,
            404
        );

        $assignment = $profile
            ->pickupAssignments()
            ->whereNotIn(
                'status',
                [
                    'completed',
                    'cancelled',
                ]
            )
            ->whereHas(
                'pickupRequest',
                fn ($query) =>
                    $query->where(
                        'pickup_no',
                        $id
                    )
            )
            ->with([
                'pickupRequest.store.sellerProfile.user',
                'pickupRequest.pickupAddress',
                'pickupRequest.parcels.waybill',
            ])
            ->orderByDesc('id')
            ->firstOrFail();

        return view(
            'rider.orders.pickup',
            $this->shared() + [
                'job' =>
                    $this->pickupAssignmentRow(
                        $assignment
                    ),
            ]
        );
    }

    public function acceptPickup(
        string $id,
        RiderPickupService $pickupService
    ): RedirectResponse {
        /** @var User $user */
        $user = Auth::user();

        $pickupService->acceptPickup(
            $id,
            $user
        );

        return redirect()
            ->route(
                'rider.orders.pickup',
                $id
            )
            ->with(
                'job_status',
                'Pickup assignment accepted.'
            );
    }

    public function confirmPickup(
        string $id,
        RiderPickupService $pickupService
    ): RedirectResponse {
        /** @var User $user */
        $user = Auth::user();

        $pickupService->confirmPickup(
            $id,
            $user
        );

        return redirect()
            ->route(
                'rider.orders.pickup',
                $id
            )
            ->with(
                'job_status',
                'Pickup confirmed. Parcels are now marked as picked up.'
            );
    }

    public function deliver(
        string $id
    ): View {
        /** @var User|null $user */
        $user = Auth::user();

        $job = $this
            ->deliveryAssignmentsFor(
                $user
            )
            ->firstWhere(
                'id',
                $id
            );

        abort_unless(
            $job,
            404
        );

        return view(
            'rider.orders.delivery',
            $this->shared() + [
                'job' => $job,
            ]
        );
    }

    public function startDelivery(
        string $id,
        RiderDeliveryService $deliveryService
    ): RedirectResponse {
        /** @var User $user */
        $user = Auth::user();

        $deliveryService->startDelivery(
            $id,
            $user
        );

        return redirect()
            ->route(
                'rider.orders.delivery',
                $id
            )
            ->with(
                'job_status',
                'Delivery started. Assigned parcels are now out for delivery.'
            );
    }

    public function confirmDelivery(
        Request $request,
        string $id,
        RiderDeliveryService $deliveryService
    ): RedirectResponse {
        $validated = $request->validate([
            'recipient_name' => [
                'required',
                'string',
                'max:160',
            ],

            'proof_photo' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'latitude' => [
                'nullable',
                'numeric',
                'between:-90,90',
            ],

            'longitude' => [
                'nullable',
                'numeric',
                'between:-180,180',
            ],
        ]);

        /** @var User $user */
        $user = Auth::user();

        $deliveryService->completeDelivery(
            $id,
            $user,
            $request->file('proof_photo'),
            $validated['recipient_name'],
            $validated['notes'] ?? null,
            isset($validated['latitude'])
                ? (float) $validated['latitude']
                : null,
            isset($validated['longitude'])
                ? (float) $validated['longitude']
                : null
        );

        return redirect()
            ->route(
                'rider.orders.delivery',
                $id
            )
            ->with(
                'job_status',
                'Delivery completed successfully.'
            );
    }

    public function failDelivery(
        Request $request,
        string $id,
        RiderDeliveryService $deliveryService
    ): RedirectResponse {
        $validated = $request->validate([
            'failure_reason' => [
                'required',
                'string',
                'max:500',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'next_attempt_at' => [
                'nullable',
                'date',
                'after:now',
            ],

            'latitude' => [
                'nullable',
                'numeric',
                'between:-90,90',
            ],

            'longitude' => [
                'nullable',
                'numeric',
                'between:-180,180',
            ],
        ]);

        /** @var User $user */
        $user = Auth::user();

        $deliveryService->failDelivery(
            $id,
            $user,
            $validated['failure_reason'],
            $validated['notes'] ?? null,
            isset($validated['latitude'])
                ? (float) $validated['latitude']
                : null,
            isset($validated['longitude'])
                ? (float) $validated['longitude']
                : null,
            $validated['next_attempt_at'] ?? null
        );

        return redirect()
            ->route(
                'rider.orders.delivery',
                $id
            )
            ->with(
                'job_status',
                'Failed delivery attempt recorded.'
            );
    }

    public function retryDelivery(
        string $id,
        RiderDeliveryService $deliveryService
    ): RedirectResponse {
        /** @var User $user */
        $user = Auth::user();

        $deliveryService->retryDelivery(
            $id,
            $user
        );

        return redirect()
            ->route(
                'rider.orders.delivery',
                $id
            )
            ->with(
                'job_status',
                'Delivery retry started.'
            );
    }

    public function earnings()
    {
        return view('rider.earnings.index', $this->shared() + [
            'earnings' => [
                'today' => 420,
                'week' => 2840,
                'month' => 11260,
                'pending' => 780,
                'completed_jobs' => 63,
                'tips' => 640,
                'incentives' => 1200,
                'deductions' => 360,
            ],
            'logs' => [
                [
                    'date' => 'Sep 6, 2026',
                    'job' => 'DL-8411',
                    'type' => 'Delivery',
                    'amount' => 70,
                    'status' => 'Posted',
                ],
                [
                    'date' => 'Sep 6, 2026',
                    'job' => 'PU-24088',
                    'type' => 'Pickup',
                    'amount' => 50,
                    'status' => 'Posted',
                ],
                [
                    'date' => 'Sep 5, 2026',
                    'job' => 'DL-8399',
                    'type' => 'Delivery',
                    'amount' => 70,
                    'status' => 'Posted',
                ],
                [
                    'date' => 'Sep 5, 2026',
                    'job' => 'DL-8396',
                    'type' => 'Delivery',
                    'amount' => 70,
                    'status' => 'Pending',
                ],
            ],
        ]);
    }

    public function history()
    {
        return view('rider.history.index', $this->shared() + [
            'history' => [
                [
                    'date' => 'Sep 6, 2026',
                    'id' => 'DL-8411',
                    'customer' => 'Jessa Cruz',
                    'area' => 'San Pablo South',
                    'status' => 'Delivered',
                    'earning' => '₱70',
                ],
                [
                    'date' => 'Sep 5, 2026',
                    'id' => 'DL-8399',
                    'customer' => 'Carlo Tan',
                    'area' => 'San Pablo North',
                    'status' => 'Delivered',
                    'earning' => '₱70',
                ],
                [
                    'date' => 'Sep 5, 2026',
                    'id' => 'DL-8392',
                    'customer' => 'Nina Reyes',
                    'area' => 'San Pablo North',
                    'status' => 'Returned',
                    'earning' => '₱35',
                ],
                [
                    'date' => 'Sep 4, 2026',
                    'id' => 'DL-8384',
                    'customer' => 'Mark Lim',
                    'area' => 'San Pablo North',
                    'status' => 'Delivered',
                    'earning' => '₱70',
                ],
                [
                    'date' => 'Sep 4, 2026',
                    'id' => 'DL-8378',
                    'customer' => 'Elena Sy',
                    'area' => 'Calauan / Bay',
                    'status' => 'Canceled',
                    'earning' => '₱0',
                ],
            ],
        ]);
    }

    public function messages()
    {
        return view('rider.messages.index', $this->shared() + [
            'conversations' => [
                [
                    'id' => 'sorting-center',
                    'name' => 'Bearly Sorting Center',
                    'role' => 'Logistics',
                    'initials' => 'BS',
                    'preview' => 'Your next dispatch is ready at Bay 2.',
                    'time' => '2:10 PM',
                ],
                [
                    'id' => 'karen-yu',
                    'name' => 'Karen Yu',
                    'role' => 'Buyer',
                    'initials' => 'KY',
                    'preview' => 'Please call me when you are near.',
                    'time' => '1:55 PM',
                ],
                [
                    'id' => 'techvault-ph',
                    'name' => 'TechVault PH',
                    'role' => 'Seller',
                    'initials' => 'TP',
                    'preview' => 'The six pickup parcels are ready.',
                    'time' => '1:31 PM',
                ],
            ],
        ]);
    }

    public function updateProfile(
        Request $request
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $riderProfile = $user
            ->riderProfile()
            ->firstOrFail();

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:160',
            ],

            'sex' => [
                'required',
                'in:Male,Female,Prefer not to say',
            ],

            'contact' => [
                'required',
                'string',
                'max:30',
            ],

            'birthday' => [
                'nullable',
                'date',
                'before:today',
            ],

            'emergency_contact_name' => [
                'nullable',
                'string',
                'max:160',
            ],

            'emergency_contact_phone' => [
                'nullable',
                'string',
                'max:30',
            ],
        ]);

        $user->update([
            'name' =>
                trim($validated['name']),

            'sex' =>
                match ($validated['sex']) {
                    'Male' => 'male',
                    'Female' => 'female',
                    default => 'prefer_not_to_say',
                },

            'contact_number' =>
                trim($validated['contact']),

            'birthday' =>
                $validated['birthday'] ?? null,
        ]);

        $riderProfile->update([
            'emergency_contact_name' =>
                filled(
                    $validated['emergency_contact_name']
                        ?? null
                )
                    ? trim(
                        $validated[
                            'emergency_contact_name'
                        ]
                    )
                    : null,

            'emergency_contact_phone' =>
                filled(
                    $validated['emergency_contact_phone']
                        ?? null
                )
                    ? trim(
                        $validated[
                            'emergency_contact_phone'
                        ]
                    )
                    : null,
        ]);

        return redirect(
            route('rider.profile.index')
            . '#profile'
        )->with(
            'success',
            'Rider profile updated successfully.'
        );
    }

    public function updatePassword(
        Request $request
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

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
            $user->password
        )) {
            return redirect(
                route('rider.profile.index')
                . '#security'
            )->withErrors([
                'current_password' =>
                    'The current password is incorrect.',
            ]);
        }

        $user->update([
            'password' => Hash::make(
                $validated['new_password']
            ),
        ]);

        return redirect(
            route('rider.profile.index')
            . '#security'
        )->with(
            'success',
            'Password updated successfully.'
        );
    }

    public function updateHomeAddress(
        Request $request
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'house_number' => [
                'nullable',
                'string',
                'max:40',
            ],

            'street' => [
                'required',
                'string',
                'max:180',
            ],

            'barangay' => [
                'required',
                'string',
                'max:120',
            ],

            'city' => [
                'required',
                'string',
                'max:120',
            ],

            'province' => [
                'required',
                'string',
                'max:120',
            ],

            'postal_code' => [
                'nullable',
                'string',
                'max:10',
            ],
        ]);

        /*
        * Rider registration uses the normalized "Home"
        * label. Migrated accounts may not have one yet,
        * so create it without touching other addresses.
        */
        $address = $user
            ->addresses()
            ->where('label', 'Home')
            ->latest('id')
            ->first();

        if (! $address) {
            $address = $user
                ->addresses()
                ->make([
                    'label' => 'Home',
                    'is_default_shipping' => false,
                    'is_default_pickup' => false,
                ]);
        }

        $address->fill([
            'recipient_name' =>
                $user->name
                ?: trim(
                    ($user->first_name ?? '')
                    .' '
                    .($user->last_name ?? '')
                ),

            'phone' =>
                $user->contact_number ?: null,

            'house_number' =>
                filled($validated['house_number'] ?? null)
                    ? trim($validated['house_number'])
                    : null,

            'street' =>
                trim($validated['street']),

            'barangay' =>
                trim($validated['barangay']),

            'city_municipality' =>
                trim($validated['city']),

            'province' =>
                trim($validated['province']),

            'postal_code' =>
                filled($validated['postal_code'] ?? null)
                    ? trim($validated['postal_code'])
                    : null,
        ]);

        $address->save();

        return redirect(
            route('rider.profile.index')
            . '#addresses'
        )->with(
            'success',
            'Home address updated successfully.'
        );
    }

    public function storeAddress(
        Request $request
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'label' => [
                'required',
                'string',
                'max:50',

                function ($attribute, $value, $fail): void {
                    if (
                        strtolower(
                            trim((string) $value)
                        ) === 'home'
                    ) {
                        $fail(
                            'Use the Home address editor to update your primary address.'
                        );
                    }
                },
            ],

            'house_number' => [
                'nullable',
                'string',
                'max:40',
            ],

            'street' => [
                'required',
                'string',
                'max:180',
            ],

            'barangay' => [
                'required',
                'string',
                'max:120',
            ],

            'city' => [
                'required',
                'string',
                'max:120',
            ],

            'province' => [
                'required',
                'string',
                'max:120',
            ],

            'postal_code' => [
                'nullable',
                'string',
                'max:10',
            ],
        ]);

        $user
            ->addresses()
            ->create([
                'label' =>
                    trim($validated['label']),

                'recipient_name' =>
                    $user->name
                    ?: trim(
                        ($user->first_name ?? '')
                        .' '
                        .($user->last_name ?? '')
                    ),

                'phone' =>
                    $user->contact_number ?: null,

                'house_number' =>
                    filled($validated['house_number'] ?? null)
                        ? trim($validated['house_number'])
                        : null,

                'street' =>
                    trim($validated['street']),

                'barangay' =>
                    trim($validated['barangay']),

                'city_municipality' =>
                    trim($validated['city']),

                'province' =>
                    trim($validated['province']),

                'postal_code' =>
                    filled($validated['postal_code'] ?? null)
                        ? trim($validated['postal_code'])
                        : null,

                'is_default_shipping' =>
                    false,

                'is_default_pickup' =>
                    false,
            ]);

        return redirect(
            route('rider.profile.index')
            . '#addresses'
        )->with(
            'success',
            'Rider address added successfully.'
        );
    }

    public function account(): View
    {
        /** @var User $user */
        $user = Auth::user();

        $riderProfile = $user
            ->riderProfile()
            ->with([
                'logisticsProfile.user',
                'homeSortingCenter',
                'currentZone',
            ])
            ->first();

        $addressModels = $user
            ->addresses()
            ->orderByRaw(
                "CASE WHEN label = 'Home' THEN 0 ELSE 1 END"
            )
            ->orderBy('label')
            ->orderBy('id')
            ->get();

        /*
        * Rider registration stores its normalized
        * registration address under the "Home" label.
        *
        * Additional Rider addresses remain separate
        * and must never masquerade as the Home address.
        */

        $formatAddress = function ($item): string {
            if (! $item) {
                return 'No address on file';
            }

            return collect([
                $item->house_number,
                $item->street,

                filled($item->barangay)
                    ? 'Brgy. '.$item->barangay
                    : null,

                $item->city_municipality,
                $item->province,
                $item->postal_code,
            ])
                ->filter(
                    fn ($value) =>
                        filled($value)
                )
                ->implode(', ');
        };

        $homeAddress = $addressModels
            ->firstWhere('label', 'Home');

        $addressText = $formatAddress(
            $homeAddress
        );

        $additionalAddresses = $addressModels
            ->reject(
                fn ($item) =>
                    $item->label === 'Home'
            )
            ->map(
                fn ($item): array => [
                    'id' =>
                        $item->id,

                    'label' =>
                        $item->label,

                    'address' =>
                        $formatAddress($item),
                ]
            )
            ->values()
            ->all();

        $sex = match ($user->sex) {
            'male' =>
                'Male',

            'female' =>
                'Female',

            'prefer_not_to_say' =>
                'Prefer not to say',

            default =>
                'Prefer not to say',
        };

        $emergencyContact = collect([
            $riderProfile?->emergency_contact_name,
            $riderProfile?->emergency_contact_phone,
        ])
            ->filter(
                fn ($value) =>
                    filled($value)
            )
            ->implode(' · ');

        $currentZone =
            $riderProfile?->currentZone;

        if ($currentZone) {
            $preferredArea =
                $currentZone->name
                .(
                    filled($currentZone->code)
                        ? ' · '.$currentZone->code
                        : ''
                );
        } else {
            $preferredArea =
                $riderProfile
                    ?->homeSortingCenter
                    ?->name
                ?: 'Not assigned';
        }

        $verificationStatus =
            $riderProfile?->verification_status
            ?: 'pending';

        return view(
            'rider.profile.index',
            $this->shared() + [
                'profile' => [
                    'contact' =>
                        $user->contact_number ?: '',

                    'birthday' =>
                        $user->birthday
                            ?->format('Y-m-d')
                        ?? '',

                    'sex' =>
                        $sex,

                    'address' =>
                        $addressText,

                    'emergency_contact' =>
                        $emergencyContact,

                    'emergency_contact_name' =>
                        $riderProfile
                            ?->emergency_contact_name
                        ?: '',

                    'emergency_contact_phone' =>
                        $riderProfile
                            ?->emergency_contact_phone
                        ?: '',

                    'preferred_area' =>
                        $preferredArea,

                    'vehicle_type' =>
                        $riderProfile?->vehicle_type
                        ?: 'Not specified',

                    'plate_number' =>
                        $riderProfile?->plate_number
                        ?: '',

                    'vehicle_model' =>
                        $riderProfile?->vehicle_model
                        ?: '',

                    'parcel_capacity' =>
                        $riderProfile?->parcel_capacity
                        ?? '',

                    'verification_status' =>
                        $verificationStatus,

                    'verification_label' =>
                        ucwords(
                            str_replace(
                                '_',
                                ' ',
                                $verificationStatus
                            )
                        ),

                    'home_sorting_center' =>
                        $riderProfile
                            ?->homeSortingCenter
                            ?->name
                        ?: 'Not assigned',

                    'current_zone' =>
                        $currentZone?->name
                        ?: 'Not assigned',
                ],

                'homeAddress' => [
                    'house_number' =>
                        $homeAddress?->house_number ?: '',

                    'street' =>
                        $homeAddress?->street ?: '',

                    'barangay' =>
                        $homeAddress?->barangay ?: '',

                    'city' =>
                        $homeAddress?->city_municipality ?: '',

                    'province' =>
                        $homeAddress?->province ?: '',

                    'postal_code' =>
                        $homeAddress?->postal_code ?: '',
                ],

                'additionalAddresses' =>
                    $additionalAddresses,
            ]
        );
    }
}

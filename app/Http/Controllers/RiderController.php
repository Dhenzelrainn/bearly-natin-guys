<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\ParcelStatus;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\OrderItem;
use App\Models\PickupAssignment;
use App\Models\RiderProfile;
use App\Models\User;
use App\Services\ApplicationReviewService;
use App\Services\BuyerCatalogService;
use App\Services\CodCashCollectionService;
use App\Services\ConversationService;
use App\Services\EmailVerificationService;
use App\Services\InternationalPhone;
use App\Services\PostalCodeLookup;
use App\Services\RegistrationLifecycleService;
use App\Services\ReturnRefundService;
use App\Services\RiderDeliveryHistoryService;
use App\Services\RiderDeliveryService;
use App\Services\RiderEarningsService;
use App\Services\RiderPickupService;
use App\Services\RiderZoneAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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
                fn (string $part) => strtoupper(
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

                'initials' => $initials ?: 'BR',

                'email' => $user?->email ?: '',

                'role' => 'Rider',

                'vehicle' => $vehicle,

                'plate' => $plate,

                'status' => $user?->status
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
                'name' => $logisticsUser
                        ? (
                            $logisticsUser->business_name
                            ?: $logisticsUser->name
                        )
                        : 'Not assigned',
            ],

            'riderAvailabilityStatus' => $riderProfile?->availability_status
                ?: 'offline',

            'riderVerificationStatus' => $riderProfile?->verification_status
                ?: 'pending',

            'topNotifications' => [],
        ];
    }

    private function deliveryAssignmentsFor(
        ?User $user,
        ?BuyerCatalogService $catalog = null,
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

        $batchQuery = $profile
            ->dispatchBatches()
            ->whereNotIn(
                'status',
                [
                    'cancelled',
                ]
            );

        $relations = [
            'sortingZone:id,code,name',
            'parcels.shipment.waybill',
            'parcels.shipment.sellerOrder.order',
            'parcels.shipment.sellerOrder.store',
            'parcels.shipment.destinationAddress',
        ];

        if ($catalog) {
            $relations[] = 'parcels.shipment.sellerOrder.items.product.images.variant';
            $relations[] = 'parcels.shipment.sellerOrder.items.variant';
        }

        $batches = $batchQuery
            ->with($relations)
            ->orderByDesc('dispatched_at')
            ->orderByDesc('id')
            ->get();

        /*
        * First create one row per
        * batch + shipment combination.
        */
        $rows = $batches
            ->flatMap(
                function ($batch) use ($catalog) {
                    return $batch
                        ->parcels
                        ->groupBy('shipment_id')
                        ->map(
                            function (
                                Collection $parcels
                            ) use ($batch, $catalog) {
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

                                $isReturn = $shipment->purpose === 'return';
                                $destination = $shipment->destinationAddress;
                                $address = $isReturn
                                    ? collect([
                                        $destination?->house_number,
                                        $destination?->street,
                                        $destination?->barangay,
                                        $destination?->city_municipality,
                                        $destination?->province,
                                        $destination?->postal_code,
                                    ])->filter()->implode(', ')
                                    : collect([
                                        $order->address_line,
                                        $order->barangay,
                                        $order->city_municipality,
                                        $order->province,
                                        $order->postal_code,
                                    ])->filter()->implode(', ');

                                $codMinor =
                                    (int) (
                                        $shipment
                                            ->cod_amount_minor
                                        ?? 0
                                    );

                                return [
                                    'shipment_id' => $shipment->id,

                                    'id' => $shipment
                                        ->shipment_no,

                                    'waybill' => $shipment
                                        ->waybill
                                        ?->waybill_no
                                        ?? 'No waybill',

                                    'customer' => $isReturn
                                        ? 'Return to Seller · '.($shipment->sellerOrder?->store?->name ?: 'Seller')
                                        : ($order->recipient_name ?: 'Recipient'),

                                    'contact' => $isReturn
                                        ? (string) ($destination?->phone ?: $shipment->sellerOrder?->store?->contact_phone ?: '')
                                        : (string) ($order->recipient_phone ?: ''),

                                    'address' => $address
                                        ?: 'Address unavailable',

                                    'payment_status' => $order
                                        ->payment_status
                                        ?: 'unknown',

                                    'purpose' => $shipment->purpose,
                                    'purpose_label' => $isReturn ? 'Return to Seller' : 'Buyer delivery',

                                    'cod_minor' => $codMinor,

                                    'products' => $catalog
                                        ? $this->orderItemPreviewData(
                                            $shipment->sellerOrder?->items ?? collect(),
                                            $catalog,
                                        )
                                        : [],

                                    'zone' => $batch
                                        ->sortingZone
                                        ?->code
                                        ?? 'Unassigned',

                                    'zone_name' => $batch
                                        ->sortingZone
                                        ?->name
                                        ?? '',

                                    'batch_no' => $batch->batch_no,

                                    'dispatched_at' => $batch
                                        ->dispatched_at,

                                    'parcels' => $parcels
                                        ->map(
                                            fn ($parcel) => [
                                                'id' => $parcel
                                                    ->id,

                                                'parcel_no' => $parcel
                                                    ->parcel_no,

                                                'status' => $parcel
                                                    ->status,

                                                'size_class' => $parcel
                                                    ->size_class,

                                                'weight_kg' => $parcel
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
                            'parcels' => $parcels,

                            'parcel_count' => $parcels->count(),

                            'status' => $this
                                ->deliveryAssignmentStatus(
                                    $parcels
                                ),

                            'zone' => $zones->implode(', '),

                            'zone_name' => $zoneNames
                                ->implode(', '),

                            'batch_no' => $batchNumbers
                                ->implode(', '),

                            'dispatched_at' => $rows
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
                'pickupRequest.pickupAddress' => fn ($query) => $query->withTrashed(),
                'pickupRequest.parcels.waybill',
            ])
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->get()
            ->map(
                fn (PickupAssignment $assignment): array => $this->pickupAssignmentRow(
                    $assignment
                )
            )
            ->filter()
            ->values();
    }

    private function pickupAssignmentRow(
        PickupAssignment $assignment,
        ?BuyerCatalogService $catalog = null,
    ): array {
        $pickup = $assignment->pickupRequest;

        if (! $pickup) {
            return [];
        }

        $store = $pickup->store;
        $address = $pickup->pickupAddress;
        $addressSnapshot = (array) ($pickup->pickup_address_snapshot ?? []);
        $isReturnPickup = $pickup->return_request_id !== null && $addressSnapshot !== [];

        $sellerName =
            $store?->name
            ?: $store?->sellerProfile?->user?->name
            ?: 'Unknown Seller';

        $contact =
            ($isReturnPickup ? ($addressSnapshot['phone'] ?? null) : $address?->phone)
            ?: $address?->phone
            ?: $store?->contact_phone
            ?: $store?->sellerProfile?->user?->contact_number
            ?: 'Not provided';

        $addressText = collect($isReturnPickup ? [
            $addressSnapshot['house_number'] ?? null,
            $addressSnapshot['street'] ?? null,
            isset($addressSnapshot['barangay']) ? 'Brgy. '.$addressSnapshot['barangay'] : null,
            $addressSnapshot['city_municipality'] ?? null,
            $addressSnapshot['province'] ?? null,
            $addressSnapshot['postal_code'] ?? null,
        ] : [
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
                fn ($value) => filled($value)
            )
            ->implode(', ');

        $mapAddress = [
            'street' => collect($isReturnPickup ? [
                $addressSnapshot['house_number'] ?? null,
                $addressSnapshot['street'] ?? null,
            ] : [
                $address?->house_number,
                $address?->street,
            ])
                ->filter(fn ($value) => filled($value))
                ->implode(' '),

            'barangay' => $isReturnPickup
                ? ($addressSnapshot['barangay'] ?? null)
                : $address?->barangay,

            'city' => $isReturnPickup
                ? ($addressSnapshot['city_municipality'] ?? null)
                : $address?->city_municipality,

            'province' => $isReturnPickup
                ? ($addressSnapshot['province'] ?? null)
                : $address?->province,

            'postal' => $isReturnPickup
                ? ($addressSnapshot['postal_code'] ?? null)
                : $address?->postal_code,
        ];

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
            ->groupBy('shipment_id')
            ->map(function (Collection $parcels) use ($catalog): array {
                $firstParcel = $parcels->first();
                $shipment = $catalog ? $firstParcel?->shipment : null;

                return [
                    'waybill' => $firstParcel?->waybill?->waybill_no
                        ?: 'Not generated',
                    'products' => $catalog
                        ? $this->orderItemPreviewData(
                            $shipment?->sellerOrder?->items ?? collect(),
                            $catalog,
                        )
                        : [],
                    'parcels' => $parcels
                        ->map(fn ($parcel): array => [
                            'parcel_no' => $parcel->parcel_no,
                            'waybill' => $parcel->waybill?->waybill_no ?: 'Not generated',
                            'size' => $parcel->size_class
                                ? ucwords(str_replace('_', ' ', $parcel->size_class))
                                : 'Not specified',
                            'qty' => 1,
                            'status' => ucwords(str_replace('_', ' ', $parcel->status)),
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        return [
            'id' => $pickup->pickup_no,

            'assignment_id' => $assignment->id,

            'seller' => $isReturnPickup ? 'Buyer return pickup' : $sellerName,

            'location_label' => $isReturnPickup ? 'Buyer pickup location' : 'Seller pickup location',

            'contact_name' => $isReturnPickup
                ? (string) ($addressSnapshot['recipient_name'] ?? 'Buyer')
                : (string) ($address?->recipient_name ?: $sellerName),

            'contact' => $contact,

            'address' => $addressText
                    ?: 'Pickup address unavailable',

            'map_address' => $mapAddress,

            'latitude' => $isReturnPickup
                ? ($addressSnapshot['latitude'] ?? null)
                : $address?->latitude,

            'longitude' => $isReturnPickup
                ? ($addressSnapshot['longitude'] ?? null)
                : $address?->longitude,

            'parcels' => $pickup->parcels->count(),

            'window' => $window,

            'status' => ucwords(
                str_replace(
                    '_',
                    ' ',
                    $assignment->status
                )
            ),

            'status_raw' => $assignment->status,

            'assigned_at' => $assignment->assigned_at
                ?->format(
                    'M j, Y · g:i A'
                )
                ?: 'Not recorded',

            'is_due_today' => $pickup
                ->requested_date
                ?->isToday()
                ?? false,

            'instructions' => $pickup->seller_instructions
                ?: 'No special seller instructions.',

            'assignment_notes' => $assignment->notes,

            'manifest' => $manifest,
        ];
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     * @return list<array{name: string, variant: string, quantity: int, image_url: string}>
     */
    private function orderItemPreviewData(
        Collection $items,
        BuyerCatalogService $catalog,
    ): array {
        return $items
            ->map(fn (OrderItem $item): array => [
                'name' => $item->product_name ?: 'Unnamed product',
                'variant' => trim((string) $item->variant_name),
                'quantity' => (int) $item->quantity,
                'image_url' => $catalog->orderItemImageUrl($item),
            ])
            ->values()
            ->all();
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
                fn ($status) => $status
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

    public function register(Request $request, ApplicationReviewService $reviews): View
    {
        $applicationReview = $reviews->forSession($request);
        $logisticsPartners = Schema::hasTable('users')
            ? User::query()
                ->where('role', UserRole::Logistics->value)
                ->where('status', AccountStatus::Active->value)
                ->whereHas('logisticsProfile', fn ($query) => $query
                    ->where('status', AccountStatus::Active->value)
                    ->whereNotNull('psgc_city_code'))
                ->with('logisticsProfile.serviceAddress')
                ->orderBy('business_name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => (string) $user->id,
                    'name' => $user->business_name ?: $user->name,
                    'city_code' => (string) ($user->logisticsProfile?->psgc_city_code ?? ''),
                    'city' => (string) ($user->logisticsProfile?->serviceAddress?->city_municipality ?? ''),
                ])
                ->all()
            : [];

        return view('rider.applications.create', compact('logisticsPartners', 'applicationReview'));
    }

    private function uniqueRiderPlateRule(
        ?int $ignoreUserId = null,
        ?int $ignoreProfileId = null
    ): \Closure {
        return function (string $attribute, mixed $value, \Closure $fail) use (
            $ignoreUserId,
            $ignoreProfileId
        ): void {
            $plateNumber = strtoupper(trim((string) $value));

            $userQuery = User::withTrashed()
                ->where('role', UserRole::Rider->value)
                ->whereRaw('UPPER(TRIM(plate_number)) = ?', [$plateNumber]);

            if ($ignoreUserId !== null) {
                $userQuery->where('id', '<>', $ignoreUserId);
            }

            $profileQuery = RiderProfile::withTrashed()
                ->whereRaw('UPPER(TRIM(plate_number)) = ?', [$plateNumber]);

            if ($ignoreProfileId !== null) {
                $profileQuery->where('id', '<>', $ignoreProfileId);
            }

            if ($userQuery->exists() || $profileQuery->exists()) {
                $fail('This plate number is already registered to a Rider.');
            }
        };
    }

    public function submitRegistration(Request $request)
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
            'plate_number' => strtoupper(trim((string) $request->input('plate_number'))),
        ]);

        $contactNumber = trim((string) $request->input('contact_number'));

        if ($contactNumber !== '') {
            $request->merge([
                'contact_number' => app(InternationalPhone::class)
                    ->normalize($contactNumber, 'PH'),
            ]);
        }

        foreach (['first_name', 'last_name'] as $nameField) {
            $name = $request->input($nameField);

            if (is_string($name)) {
                $request->merge([$nameField => trim($name)]);
            }
        }

        $validated = $request->validate([
            'logistics_partner' => ['required', 'integer', 'exists:users,id'],
            'first_name' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[\pL\s\'\-]+$/u'],
            'middle_initial' => ['nullable', 'string', 'max:2', 'regex:/^[A-Za-z][.]?$/D'],
            'last_name' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[\pL\s\'\-]+$/u'],
            'sex' => ['required', 'in:Male,Female,Prefer not to say'],
            'email' => ['required', 'email', 'unique:users,email'],
            'contact_number' => [
                'required',
                'string',
                'max:17',
                Rule::unique('users', 'contact_number'),
                Rule::unique('users', 'phone'),
            ],
            'birthday' => [
                'required',
                'bail',
                'date',
                'before:today',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (Carbon::parse((string) $value)->gt(today()->subYears(18))) {
                        $fail('Riders must be at least 18 years old.');
                    }
                },
            ],
            'province' => ['required', 'string', 'max:120'],
            'municipality' => ['required', 'string', 'max:120'],
            'barangay' => ['required', 'string', 'max:120'],
            'street' => ['required', 'string', 'max:180'],
            'house_number' => ['required', 'string', 'max:40'],
            'postal_code' => ['required', 'digits:4'],
            'city_code' => ['required', 'regex:/^[0-9]{6,10}$/D'],
            'postal_manual' => ['nullable', 'boolean'],
            'vehicle_type' => ['required', 'string', 'max:80'],
            'plate_number' => [
                'required',
                'string',
                'max:30',
                $this->uniqueRiderPlateRule(),
            ],
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

        app(PostalCodeLookup::class)->validate([
            'province' => $validated['province'],
            'city' => $validated['municipality'],
            'city_code' => $validated['city_code'] ?? null,
            'postal_code' => $validated['postal_code'],
            'postal_manual' => $validated['postal_manual'] ?? false,
        ]);

        $logistics = User::query()
            ->whereKey($validated['logistics_partner'])
            ->where('role', UserRole::Logistics->value)
            ->where('status', AccountStatus::Active->value)
            ->firstOrFail();

        $logisticsProfile = $logistics->logisticsProfile;

        if (
            ! $logisticsProfile
            || $logisticsProfile->status !== AccountStatus::Active->value
            || (string) $logisticsProfile->psgc_city_code !== (string) $validated['city_code']
        ) {
            throw ValidationException::withMessages([
                'logistics_partner' => 'Choose the approved Logistics account serving your home municipality/city.',
            ]);
        }

        $verification = app(EmailVerificationService::class);
        $verifiedAt = $verification->verifiedAt(
            $request,
            $validated['email']
        );

        $middleInitial = empty($validated['middle_initial'])
            ? null
            : strtoupper(substr($validated['middle_initial'], 0, 1)).'.';

        $orCrPath = $request->file('or_cr')
            ->store('registration-documents/riders/or-cr', 'local');
        $driverLicensePath = $request->file('driver_license')
            ->store('registration-documents/riders/licenses', 'local');

        try {
            $user = DB::transaction(function () use (
                $validated,
                $middleInitial,
                $request,
                $verifiedAt,
                $logistics,
                $orCrPath,
                $driverLicensePath
            ): User {
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
                    'phone' => $validated['contact_number'],
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
                    'plate_number' => $validated['plate_number'],
                    'or_cr_path' => $orCrPath,
                    'driver_license_path' => $driverLicensePath,
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
                            'postal_code' => $validated['postal_code'] ?? null,
                            'city_code' => $validated['city_code'] ?? null,
                        ],
                        [],
                        [
                            [
                                'type' => 'or_cr',
                                'path' => $orCrPath,
                                'file' => $request->file('or_cr'),
                            ],
                            [
                                'type' => 'driver_license',
                                'path' => $driverLicensePath,
                                'file' => $request->file('driver_license'),
                            ],
                        ],
                        $logistics->id
                    );

                return $user;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete(
                array_filter([$orCrPath, $driverLicensePath])
            );

            throw $exception;
        }

        $verification->forget($request);

        session([
            'rider_application' => [
                'full_name' => $user->name,
                'email' => $user->email,
                'logistics_partner' => $logistics->business_name ?: $logistics->name,
                'status' => 'Awaiting Logistics Approval',
            ],
        ]);
        app(ApplicationReviewService::class)->remember($request, $user);

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
                'pickups' => $pickups,

                'pickupMetrics' => [
                    'assigned' => $pickups
                        ->where(
                            'status_raw',
                            'assigned'
                        )
                        ->count(),

                    'in_progress' => $pickups
                        ->whereIn(
                            'status_raw',
                            [
                                'accepted',
                                'arrived',
                                'picked_up',
                            ]
                        )
                        ->count(),

                    'parcels' => $pickups
                        ->sum('parcels'),

                    'due_today' => $pickups
                        ->where(
                            'is_due_today',
                            true
                        )
                        ->count(),
                ],
            ]
        );
    }

    public function updateAvailability(
        Request $request
    ): RedirectResponse {
        /** @var User|null $user */
        $user = $request->user();

        abort_unless(
            $user instanceof User
            && $user->status === AccountStatus::Active->value
            && $user->hasRole(UserRole::Rider->value),
            403
        );

        $validated = $request->validate([
            'availability_status' => [
                'required',
                'in:available,offline',
            ],
        ]);

        $riderProfile = $user
            ->riderProfile()
            ->firstOrFail();

        abort_unless(
            $riderProfile->verification_status === 'approved',
            403
        );

        $riderProfile->update([
            'availability_status' => $validated['availability_status'],
        ]);

        $message =
            $validated['availability_status'] === 'available'
                ? 'You are now available for new pickup assignments.'
                : 'You are now offline and hidden from new pickup assignments.';

        return back()->with('success', $message);
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
                fn (array $delivery) => in_array(
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
                fn (array $delivery) => (int) $delivery['cod_minor']
            );

        return view(
            'rider.dashboard.deliveries',
            $this->shared() + [
                'deliveries' => $deliveries,

                'metrics' => [
                    'assigned_parcels' => $assignedParcels,

                    'out_for_delivery' => $outForDelivery,

                    'active_stops' => $deliveries->count(),

                    'cod_minor' => $codMinor,
                ],
            ]
        );
    }

    public function pickup(
        string $id,
        BuyerCatalogService $catalog,
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
            ->whereIn(
                'status',
                [
                    'assigned',
                    'accepted',
                    'arrived',
                    'picked_up',
                    'completed',
                ]
            )
            ->whereHas(
                'pickupRequest',
                fn ($query) => $query->where(
                    'pickup_no',
                    $id
                )
            )
            ->with([
                'pickupRequest.store.sellerProfile.user',
                'pickupRequest.pickupAddress' => fn ($query) => $query->withTrashed(),
                'pickupRequest.parcels.waybill',
                'pickupRequest.parcels.shipment.sellerOrder.items.product.images.variant',
                'pickupRequest.parcels.shipment.sellerOrder.items.variant',
            ])
            ->orderByDesc('id')
            ->firstOrFail();

        return view(
            'rider.orders.pickup',
            $this->shared() + [
                'job' => $this->pickupAssignmentRow(
                    $assignment,
                    $catalog,
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
        Request $request,
        string $id,
        RiderPickupService $pickupService,
        ReturnRefundService $returns,
    ): RedirectResponse {
        /** @var User $user */
        $user = Auth::user();

        $data = $request->validate([
            'waybill_identifier' => ['nullable', 'string', 'max:120'],
            'scan_method' => ['nullable', Rule::in(['camera', 'barcode', 'manual', 'image'])],
        ]);

        $pickupService->confirmPickup(
            $id,
            $user,
            $returns,
            $data['waybill_identifier'] ?? null,
            $data['scan_method'] ?? 'manual',
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
        string $id,
        BuyerCatalogService $catalog,
    ): View {
        /** @var User|null $user */
        $user = Auth::user();

        $job = $this
            ->deliveryAssignmentsFor(
                $user,
                $catalog,
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
        RiderDeliveryService $deliveryService,
        ReturnRefundService $returns,
        CodCashCollectionService $codCash,
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

            'cod_collected_amount' => [
                'nullable',
                'string',
                'regex:/^\\d{1,8}(?:\\.\\d{1,2})?$/',
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

        $completion = $deliveryService->completeDelivery(
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
                : null,
            $returns,
            isset($validated['cod_collected_amount'])
                ? $this->amountToMinor($validated['cod_collected_amount'])
                : null,
            $codCash,
        );

        $message = $completion->alreadyCompleted
            ? 'This delivery was already completed. No extra proof, delivery attempt, COD collection, or tracking event was added.'
            : ($completion->shipment->codCashCollection?->status === 'disputed'
                ? 'Delivery completed. The COD cash difference was recorded for Logistics and Finance review.'
                : 'Delivery completed successfully.');

        return redirect()
            ->route(
                'rider.orders.delivery',
                $id
            )
            ->with(
                'job_status',
                $message
            );
    }

    private function amountToMinor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($amount), 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
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

    public function earnings(
        Request $request,
        RiderEarningsService $earningsService,
    ): View {
        $riderProfile = $this->authenticatedRiderProfile($request);
        $filters = $this->earningsFilters($request);

        return view('rider.earnings.index', $this->shared() + [
            'earnings' => $earningsService->summary($riderProfile),
            'logs' => $earningsService->paginate($riderProfile, $filters),
            'filters' => $filters,
            'statusOptions' => $earningsService->statusOptions($riderProfile),
            'typeOptions' => $earningsService->typeOptions($riderProfile),
        ]);
    }

    public function exportEarnings(
        Request $request,
        RiderEarningsService $earningsService,
    ): StreamedResponse {
        $riderProfile = $this->authenticatedRiderProfile($request);
        $filters = $this->earningsFilters($request);

        return response()->streamDownload(function () use (
            $earningsService,
            $riderProfile,
            $filters,
        ): void {
            $stream = fopen('php://output', 'wb');

            if ($stream === false) {
                return;
            }

            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, [
                'Earned at',
                'Job',
                'Linked source',
                'Type',
                'Amount',
                'Amount (centavos)',
                'Status',
                'Posted at',
                'Paid at',
                'Description',
                'Pickup assignment ID',
                'Delivery attempt ID',
                'Payout method',
                'Payout reference',
                'Payout date',
                'Payout note',
            ], ',', '"', '\\');

            foreach ($earningsService->exportQuery($riderProfile, $filters)->cursor() as $record) {
                fputcsv($stream, $earningsService->csvRow($record), ',', '"', '\\');
            }

            fclose($stream);
        }, 'bearly-rider-earnings-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function history(
        Request $request,
        RiderDeliveryHistoryService $historyService,
        BuyerCatalogService $catalog,
    ): View {
        $riderProfile = $this->authenticatedRiderProfile($request);
        $filters = $this->historyFilters($request);

        return view('rider.history.index', $this->shared() + [
            'metrics' => $historyService->summary($riderProfile),
            'history' => $historyService->paginate($riderProfile, $filters, $catalog),
            'filters' => $filters,
        ]);
    }

    public function exportHistory(
        Request $request,
        RiderDeliveryHistoryService $historyService
    ): StreamedResponse {
        $riderProfile = $this->authenticatedRiderProfile($request);
        $filters = $this->historyFilters($request);

        return response()->streamDownload(function () use (
            $historyService,
            $riderProfile,
            $filters
        ): void {
            $stream = fopen('php://output', 'wb');

            if ($stream === false) {
                return;
            }

            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, [
                'Date and time',
                'Shipment',
                'Parcel',
                'Recipient',
                'Service area',
                'Outcome',
                'Recorded earning',
                'Dispatch batch',
                'Attempt',
            ], ',', '"', '\\');

            foreach ($historyService->exportQuery($riderProfile, $filters)->cursor() as $record) {
                fputcsv($stream, $historyService->csvRow($record), ',', '"', '\\');
            }

            fclose($stream);
        }, 'bearly-rider-delivery-history-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function authenticatedRiderProfile(Request $request): RiderProfile
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user->riderProfile()->firstOrFail();
    }

    /**
     * @return array{search: string|null, status: string|null, type: string|null, date_from: string|null, date_to: string|null}
     */
    private function earningsFilters(Request $request): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:30'],
            'type' => ['nullable', 'string', 'max:30'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => [
                'nullable',
                'date_format:Y-m-d',
                function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                    $dateFrom = $request->input('date_from');

                    if (filled($value) && filled($dateFrom) && $value < $dateFrom) {
                        $fail('The end date must be on or after the start date.');
                    }
                },
            ],
        ]);

        return [
            'search' => filled($validated['search'] ?? null)
                ? trim((string) $validated['search'])
                : null,
            'status' => filled($validated['status'] ?? null)
                ? trim((string) $validated['status'])
                : null,
            'type' => filled($validated['type'] ?? null)
                ? trim((string) $validated['type'])
                : null,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
        ];
    }

    /**
     * @return array{search: string|null, outcome: string|null, date_from: string|null, date_to: string|null}
     */
    private function historyFilters(Request $request): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'outcome' => ['nullable', 'in:delivered,returned,failed,cancelled'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => [
                'nullable',
                'date_format:Y-m-d',
                function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                    $dateFrom = $request->input('date_from');

                    if (filled($value) && filled($dateFrom) && $value < $dateFrom) {
                        $fail('The end date must be on or after the start date.');
                    }
                },
            ],
        ]);

        return [
            'search' => $validated['search'] ?? null,
            'outcome' => $validated['outcome'] ?? null,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
        ];
    }

    /**
     * Participant-scoped inbox. Riders only ever see conversations they were
     * added to by an existing workflow (Admin messaging or their Logistics
     * provider). Rider-initiated conversations are intentionally not offered
     * until the counterpart rules are defined.
     */
    public function messages(Request $request): View
    {
        /** @var User $rider */
        $rider = $request->user();

        $records = Conversation::query()
            ->whereHas(
                'participants',
                fn ($query) => $query->where('users.id', $rider->id)
            )
            ->with([
                'participants',
                'latestMessage',
                'messages' => fn ($query) => $query
                    ->with([
                        'attachments',
                        'receipts',
                    ])
                    ->orderBy('sent_at')
                    ->orderBy('id'),
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        $conversations = $records
            ->map(function (Conversation $conversation) use ($rider): array {
                $participant = $conversation->participants
                    ->firstWhere('id', $rider->id);

                $counterpart = $conversation->participants
                    ->first(
                        fn (User $user): bool => (int) $user->id !== (int) $rider->id
                    );

                $counterpartRole = strtolower((string) (
                    $counterpart?->pivot?->participant_role
                    ?? $counterpart?->role
                    ?? 'participant'
                ));

                $role = match ($counterpartRole) {
                    'admin' => 'Administrator',
                    'buyer' => 'Buyer',
                    'seller' => 'Seller',
                    'logistics' => 'Logistics Center',
                    default => ucwords(
                        str_replace('_', ' ', $counterpartRole)
                    ),
                };

                $name = $counterpart?->name ?: 'Conversation';

                $lastReadAt = $participant?->pivot?->last_read_at
                    ? Carbon::parse($participant->pivot->last_read_at)
                    : null;

                $unread = $conversation->messages
                    ->filter(
                        function (Message $message) use ($rider, $lastReadAt): bool {
                            if (
                                (int) $message->sender_id === (int) $rider->id
                                || ! $message->sent_at
                            ) {
                                return false;
                            }

                            return ! $lastReadAt
                                || $message->sent_at->isAfter($lastReadAt);
                        }
                    )
                    ->count();

                $latestMessage = $conversation->latestMessage;

                $latestBody = trim((string) ($latestMessage?->body ?? ''));

                return [
                    'id' => (int) $conversation->id,
                    'name' => $name,
                    'role' => $role,
                    'initials' => $this->messageInitials($name),
                    'preview' => $latestBody !== ''
                        ? $latestBody
                        : ($latestMessage ? 'Attachment' : 'No messages yet.'),
                    'time' => $latestMessage?->sent_at
                        ?->copy()
                        ->timezone('Asia/Manila')
                        ->format('M j, g:i A') ?? '',
                    'unread' => $unread,
                    'subject' => $conversation->subject,
                    'status' => $conversation->status,
                    'type' => $conversation->type,
                    'presence_user_ids' => app(ConversationService::class)
                        ->presenceUserIds($conversation, $rider),
                    'send_url' => route('rider.messages.send', $conversation),
                    'read_url' => route('rider.messages.read', $conversation),
                    'thread_url' => route('rider.messages.thread', $conversation),
                    'delivery_url' => route('messages.delivered', $conversation),
                    'messages' => $conversation->messages
                        ->map(
                            fn (Message $message): array => $this->messageThreadEntry(
                                $rider,
                                $message,
                                $conversation->participants
                            )
                        )
                        ->values()
                        ->all(),
                ];
            })
            ->values();

        $requestedId = (int) $request->query('conversation', 0);

        $selectedId = $conversations->contains(
            fn (array $conversation): bool => $conversation['id'] === $requestedId
        )
            ? $requestedId
            : $conversations->first()['id'] ?? null;

        return view('rider.messages.index', $this->shared() + [
            'conversations' => $conversations->all(),
            'conversationData' => $conversations->keyBy('id')->all(),
            'selectedConversationId' => $selectedId,
        ]);
    }

    /**
     * Participant-scoped thread snapshot used by the messages page to keep
     * the open conversation current without a full page reload.
     */
    public function messageThread(
        Request $request,
        Conversation $conversation
    ): JsonResponse {
        /** @var User $rider */
        $rider = $request->user();

        abort_unless(
            $this->riderCanAccessConversation($conversation, $rider),
            404
        );

        $validated = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        $after = (int) ($validated['after'] ?? 0);

        $conversation->loadMissing('participants');

        $messages = $conversation->messages()
            ->with([
                'attachments',
                'receipts',
            ])
            ->when($after > 0, fn ($query) => $query->where('id', '>', $after))
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get()
            ->map(
                fn (Message $message): array => $this->messageThreadEntry(
                    $rider,
                    $message,
                    $conversation->participants
                )
            )
            ->values()
            ->all();

        return response()->json([
            'message' => 'Thread loaded.',
            'data' => [
                'messages' => $messages,
                'receipt_updates' => $after > 0
                    ? app(ConversationService::class)
                        ->receiptUpdatesForViewer($conversation, $rider)
                    : [],
            ],
        ]);
    }

    public function markMessageConversationRead(
        Request $request,
        Conversation $conversation
    ): JsonResponse {
        /** @var User $rider */
        $rider = $request->user();

        abort_unless(
            $this->riderCanAccessConversation($conversation, $rider),
            404
        );

        app(ConversationService::class)->markRead($conversation, $rider);

        return response()->json([
            'message' => 'Conversation marked as read.',
            'unread' => 0,
        ]);
    }

    public function sendMessage(
        Request $request,
        Conversation $conversation
    ): JsonResponse {
        /** @var User $rider */
        $rider = $request->user();

        abort_unless(
            $this->riderCanAccessConversation($conversation, $rider),
            404
        );

        $validated = $request->validate([
            'message' => [
                'nullable',
                'string',
                'max:5000',
                'required_without:attachment',
            ],

            'attachment' => [
                'nullable',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,webp,mp4,mov,webm,pdf,doc,docx,xls,xlsx,txt,csv,zip',
            ],
        ]);

        $message = app(ConversationService::class)->send(
            $conversation,
            $rider,
            $validated['message'] ?? null,
            $request->file('attachment')
        );

        $message->load('attachments');
        $conversation->loadMissing('participants');

        $entry = $this->messageThreadEntry(
            $rider,
            $message,
            $conversation->participants
        );

        return response()->json([
            'message' => 'Message sent successfully.',
            'data' => [
                'id' => $entry['id'],
                'mine' => true,
                'text' => $entry['text'],
                'preview' => $entry['text'] !== ''
                    ? $entry['text']
                    : 'Attachment',
                'time' => $entry['time'],
                'receipt' => $entry['receipt'],
                'attachments' => $entry['attachments'],
            ],
        ]);
    }

    public function downloadMessageAttachment(
        Request $request,
        MessageAttachment $attachment
    ): StreamedResponse {
        /** @var User $rider */
        $rider = $request->user();

        $conversation = app(ConversationService::class)
            ->conversationForAttachment($attachment);

        abort_unless($conversation !== null, 404);

        abort_unless(
            $this->riderCanAccessConversation($conversation, $rider),
            404
        );

        abort_unless(
            Storage::disk('local')->exists($attachment->file_path),
            404
        );

        return app(ConversationService::class)
            ->attachmentDownload($attachment);
    }

    private function riderCanAccessConversation(
        Conversation $conversation,
        User $rider
    ): bool {
        return $conversation->participants()
            ->where('users.id', $rider->id)
            ->exists();
    }

    private function messageInitials(string $name): string
    {
        $initials = collect(preg_split('/\s+/', trim($name)) ?: [])
            ->filter()
            ->take(2)
            ->map(
                fn (string $part): string => strtoupper(substr($part, 0, 1))
            )
            ->implode('');

        return $initials !== '' ? $initials : '?';
    }

    /**
     * @param  Collection<int, User>|null  $participants
     * @return array<string, mixed>
     */
    private function messageThreadEntry(User $rider, Message $message, $participants = null): array
    {
        return [
            'id' => (int) $message->id,

            'mine' => (int) $message->sender_id === (int) $rider->id,

            'text' => (string) $message->body,

            'receipt' => app(ConversationService::class)
                ->receiptPayload($message, $rider, $participants),

            'attachments' => $message->attachments
                ->map(
                    fn (MessageAttachment $attachment): array => [
                        'id' => (int) $attachment->id,

                        'name' => (string) $attachment->original_name,

                        'mime_type' => (string) $attachment->mime_type,

                        'size_bytes' => (int) $attachment->size_bytes,

                        'is_image' => str_starts_with(
                            (string) $attachment->mime_type,
                            'image/'
                        ),

                        'is_video' => str_starts_with(
                            (string) $attachment->mime_type,
                            'video/'
                        ),

                        'is_previewable' => app(ConversationService::class)
                            ->isPreviewableMime($attachment->mime_type),

                        'download_url' => route(
                            'rider.messages.attachments.download',
                            $attachment
                        ),
                    ]
                )
                ->values()
                ->all(),

            'time' => $message->sent_at
                ?->copy()
                ->timezone('Asia/Manila')
                ->format('M j, g:i A')
                ?? '',
        ];
    }

    public function updateProfile(
        Request $request
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $riderProfile = $user
            ->riderProfile()
            ->firstOrFail();

        $contact = trim((string) $request->input('contact'));

        if ($contact !== '') {
            try {
                $contact = app(InternationalPhone::class)
                    ->normalize($contact, 'PH');
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages([
                    'contact' => $exception->errors()['contact_number']
                        ?? ['Enter a valid Philippine mobile number.'],
                ]);
            }
        }

        $emergencyContactPhone = trim(
            (string) $request->input('emergency_contact_phone')
        );

        if ($emergencyContactPhone !== '') {
            try {
                $emergencyContactPhone = app(InternationalPhone::class)
                    ->normalize($emergencyContactPhone, 'PH');
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages([
                    'emergency_contact_phone' => $exception->errors()['contact_number']
                        ?? ['Enter a valid Philippine mobile number.'],
                ]);
            }
        }

        $request->merge([
            'contact' => $contact,
            'emergency_contact_phone' => $emergencyContactPhone,
        ]);

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
                'max:17',
                Rule::unique('users', 'contact_number')->ignore($user->id),
                Rule::unique('users', 'phone')->ignore($user->id),
            ],

            'birthday' => [
                'nullable',
                'bail',
                'date',
                'before:today',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (Carbon::parse((string) $value)->gt(today()->subYears(18))) {
                        $fail('Riders must be at least 18 years old.');
                    }
                },
            ],

            'emergency_contact_name' => [
                'nullable',
                'string',
                'max:160',
            ],

            'emergency_contact_phone' => [
                'nullable',
                'string',
                'max:17',
            ],
        ]);

        $user->update([
            'name' => trim($validated['name']),

            'sex' => match ($validated['sex']) {
                'Male' => 'male',
                'Female' => 'female',
                default => 'prefer_not_to_say',
            },

            'contact_number' => trim($validated['contact']),

            'phone' => trim($validated['contact']),

            'birthday' => $validated['birthday'] ?? null,
        ]);

        $riderProfile->update([
            'emergency_contact_name' => filled(
                $validated['emergency_contact_name']
                    ?? null
            )
                    ? trim(
                        $validated[
                            'emergency_contact_name'
                        ]
                    )
                    : null,

            'emergency_contact_phone' => filled(
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
            .'#profile'
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
                .'#security'
            )->withErrors([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        $user->update([
            'password' => Hash::make(
                $validated['new_password']
            ),
        ]);

        return redirect(
            route('rider.profile.index')
            .'#security'
        )->with(
            'success',
            'Password updated successfully.'
        );
    }

    public function updateHomeAddress(
        Request $request,
        RiderZoneAssignmentService $assignmentService,
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
            'city_code' => ['nullable', 'regex:/^[0-9]{6,10}$/D'],
        ]);

        $cityCode = app(PostalCodeLookup::class)->canonicalCityCode(
            $validated['city_code'] ?? null,
            $validated['province'],
            $validated['city'],
        );

        $riderProfile = $user->riderProfile()->firstOrFail();
        $logisticsProfile = $riderProfile->logisticsProfile;

        if (
            ! $cityCode
            || ! $logisticsProfile
            || (string) $cityCode !== (string) $logisticsProfile->psgc_city_code
        ) {
            throw ValidationException::withMessages([
                'city' => 'Your home municipality/city must remain within your sponsoring Logistics account’s service area.',
            ]);
        }

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
            'recipient_name' => $user->name
                ?: trim(
                    ($user->first_name ?? '')
                    .' '
                    .($user->last_name ?? '')
                ),

            'phone' => $user->contact_number ?: null,

            'house_number' => filled($validated['house_number'] ?? null)
                    ? trim($validated['house_number'])
                    : null,

            'street' => trim($validated['street']),

            'barangay' => trim($validated['barangay']),

            'city_municipality' => trim($validated['city']),

            'psgc_city_code' => $cityCode,

            'province' => trim($validated['province']),

            'postal_code' => filled($validated['postal_code'] ?? null)
                    ? trim($validated['postal_code'])
                    : null,
        ]);

        $address->save();

        $assignmentService->sync($riderProfile);

        return redirect(
            route('rider.profile.index')
            .'#addresses'
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
                'label' => trim($validated['label']),

                'recipient_name' => $user->name
                    ?: trim(
                        ($user->first_name ?? '')
                        .' '
                        .($user->last_name ?? '')
                    ),

                'phone' => $user->contact_number ?: null,

                'house_number' => filled($validated['house_number'] ?? null)
                        ? trim($validated['house_number'])
                        : null,

                'street' => trim($validated['street']),

                'barangay' => trim($validated['barangay']),

                'city_municipality' => trim($validated['city']),

                'province' => trim($validated['province']),

                'postal_code' => filled($validated['postal_code'] ?? null)
                        ? trim($validated['postal_code'])
                        : null,

                'is_default_shipping' => false,

                'is_default_pickup' => false,
            ]);

        return redirect(
            route('rider.profile.index')
            .'#addresses'
        )->with(
            'success',
            'Rider address added successfully.'
        );
    }

    public function updateVehicle(
        Request $request
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $riderProfile = $user
            ->riderProfile()
            ->firstOrFail();

        $request->merge([
            'plate_number' => strtoupper(
                trim((string) $request->input('plate_number'))
            ),
        ]);

        $validated = $request->validate([
            'vehicle_type' => [
                'required',
                'string',
                'max:80',
            ],

            'plate_number' => [
                'required',
                'string',
                'max:30',
                $this->uniqueRiderPlateRule($user->id, $riderProfile->id),
            ],

            'vehicle_model' => [
                'nullable',
                'string',
                'max:160',
            ],

            'parcel_capacity' => [
                'nullable',
                'integer',
                'min:1',
            ],
        ]);

        $vehicleType = trim(
            $validated['vehicle_type']
        );

        $plateNumber = trim($validated['plate_number']);

        DB::transaction(
            function () use (
                $user,
                $riderProfile,
                $validated,
                $vehicleType,
                $plateNumber
            ): void {
                /*
                * RiderProfile is authoritative.
                */
                $riderProfile->update([
                    'vehicle_type' => $vehicleType,

                    'plate_number' => $plateNumber,

                    'vehicle_model' => filled(
                        $validated['vehicle_model']
                        ?? null
                    )
                            ? trim(
                                $validated['vehicle_model']
                            )
                            : null,

                    'parcel_capacity' => $validated[
                            'parcel_capacity'
                        ] ?? null,
                ]);

                /*
                * Keep legacy compatibility fields
                * synchronized because lifecycle code
                * may still read these during migration.
                */
                $user->update([
                    'vehicle_type' => $vehicleType,

                    'plate_number' => $plateNumber,
                ]);
            }
        );

        return redirect(
            route('rider.profile.index')
            .'#vehicle'
        )->with(
            'success',
            'Vehicle details updated successfully.'
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
                    fn ($value) => filled($value)
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
                fn ($item) => $item->label === 'Home'
            )
            ->map(
                fn ($item): array => [
                    'id' => $item->id,

                    'label' => $item->label,

                    'address' => $formatAddress($item),
                ]
            )
            ->values()
            ->all();

        $sex = match ($user->sex) {
            'male' => 'Male',

            'female' => 'Female',

            'prefer_not_to_say' => 'Prefer not to say',

            default => 'Prefer not to say',
        };

        $emergencyContact = collect([
            $riderProfile?->emergency_contact_name,
            $riderProfile?->emergency_contact_phone,
        ])
            ->filter(
                fn ($value) => filled($value)
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
                    'contact' => $user->contact_number ?: '',

                    'birthday' => $user->birthday
                        ?->format('Y-m-d')
                        ?? '',

                    'sex' => $sex,

                    'address' => $addressText,

                    'emergency_contact' => $emergencyContact,

                    'emergency_contact_name' => $riderProfile
                        ?->emergency_contact_name
                        ?: '',

                    'emergency_contact_phone' => $riderProfile
                        ?->emergency_contact_phone
                        ?: '',

                    'preferred_area' => $preferredArea,

                    'vehicle_type' => $riderProfile?->vehicle_type
                        ?: 'Not specified',

                    'plate_number' => $riderProfile?->plate_number
                        ?: '',

                    'vehicle_model' => $riderProfile?->vehicle_model
                        ?: '',

                    'parcel_capacity' => $riderProfile?->parcel_capacity
                        ?? '',

                    'verification_status' => $verificationStatus,

                    'verification_label' => ucwords(
                        str_replace(
                            '_',
                            ' ',
                            $verificationStatus
                        )
                    ),

                    'home_sorting_center' => $riderProfile
                        ?->homeSortingCenter
                        ?->name
                        ?: 'Not assigned',

                    'current_zone' => $currentZone?->name
                        ?: 'Not assigned',
                ],

                'homeAddress' => [
                    'house_number' => $homeAddress?->house_number ?: '',

                    'street' => $homeAddress?->street ?: '',

                    'barangay' => $homeAddress?->barangay ?: '',

                    'city' => $homeAddress?->city_municipality ?: '',

                    'province' => $homeAddress?->province ?: '',

                    'postal_code' => $homeAddress?->postal_code ?: '',
                ],

                'additionalAddresses' => $additionalAddresses,
            ]
        );
    }
}

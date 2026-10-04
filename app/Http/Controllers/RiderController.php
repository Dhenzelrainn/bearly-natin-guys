<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Enums\ParcelStatus;
use App\Models\User;
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
        $initials = collect(preg_split('/\s+/', trim($name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part) => strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        $logistics = null;

        if ($user?->logistics_id) {
            $logistics = User::query()
                ->whereKey($user->logistics_id)
                ->where('role', UserRole::Logistics->value)
                ->first();
        }

        return [
            'rider' => [
                'name' => $name,
                'initials' => $initials ?: 'BR',
                'email' => $user?->email ?: '',
                'role' => 'Rider',
                'vehicle' => $user?->vehicle_type ?: 'Not specified',
                'plate' => $user?->plate_number ?: '—',
                'status' => $user?->status
                    ? ucwords(str_replace('_', ' ', $user->status))
                    : 'Unknown',
            ],
            'logistics' => [
                'name' => $logistics
                    ? ($logistics->business_name ?: $logistics->name)
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

    public function pickupsDashboard()
    {
        return view('rider.dashboard.pickups', $this->shared() + [
            'pickups' => [
                [
                    'id' => 'PU-24091',
                    'seller' => 'TechVault PH',
                    'address' => 'Brgy. San Rafael, San Pablo City',
                    'distance' => '2.3 km',
                    'parcels' => 6,
                    'window' => '2:30–3:30 PM',
                    'status' => 'Assigned',
                ],
                [
                    'id' => 'PU-24094',
                    'seller' => 'Mara Home Goods',
                    'address' => 'Brgy. Del Remedio, San Pablo City',
                    'distance' => '4.1 km',
                    'parcels' => 4,
                    'window' => '3:30–4:30 PM',
                    'status' => 'Available',
                ],
                [
                    'id' => 'PU-24095',
                    'seller' => 'Tiny Tails Pet Co.',
                    'address' => 'Brgy. San Roque, San Pablo City',
                    'distance' => '5.7 km',
                    'parcels' => 3,
                    'window' => '4:00–5:00 PM',
                    'status' => 'Available',
                ],
            ],
            'alerts' => [
                ['time' => '2 min ago', 'title' => 'New nearby pickup', 'detail' => 'Mara Home Goods · 4 parcels · 4.1 km'],
                ['time' => '18 min ago', 'title' => 'Pickup window updated', 'detail' => 'TechVault PH is ready from 2:30–3:30 PM'],
                ['time' => '35 min ago', 'title' => 'Sorting center reminder', 'detail' => 'Return collected parcels to Intake Bay 2'],
            ],
        ]);
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

    public function pickup(string $id)
    {
        return view('rider.orders.pickup', $this->shared() + [
            'job' => [
                'id' => $id,
                'seller' => 'TechVault PH',
                'contact' => '0917 555 0148',
                'address' => 'Unit 4, San Rafael Commercial Arcade, Brgy. San Rafael, San Pablo City',
                'window' => '2:30–3:30 PM',
                'distance' => '2.3 km',
                'instructions' => 'Use the loading entrance beside the pharmacy. Ask for the seller operations desk.',
                'manifest' => [
                    [
                        'waybill' => 'BRL-983410',
                        'size' => 'Small',
                        'qty' => 1,
                    ],
                    [
                        'waybill' => 'BRL-983411',
                        'size' => 'Medium',
                        'qty' => 1,
                    ],
                    [
                        'waybill' => 'BRL-983415',
                        'size' => 'Small',
                        'qty' => 1,
                    ],
                ],
            ],
        ]);
    }

    public function confirmPickup(Request $request, string $id)
    {
        return back()->with(
            'job_status',
            "Pickup {$id} confirmed. Front-end session state updated."
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
        string $id
    ) {
        return redirect()
            ->route(
                'rider.orders.delivery',
                $id
            )
            ->with(
                'job_status',
                'Delivery completion is not enabled yet.'
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

    public function account()
    {
        /** @var User $user */
        $user = Auth::user();

        $address = collect([
            $user->street_address,
            $user->barangay,
            $user->city,
            $user->province,
        ])->filter()->implode(', ');

        return view('rider.profile.index', $this->shared() + [
            'profile' => [
                'contact' => $user->contact_number ?: '',
                'birthday' => $user->birthday?->format('Y-m-d') ?? '',
                'sex' => $user->sex
                    ? ucwords(str_replace('_', ' ', $user->sex))
                    : 'Prefer not to say',
                'address' => $address,
                'emergency_contact' => '',
                'preferred_area' => collect([$user->city, $user->province])
                    ->filter()
                    ->implode(', '),
                'vehicle_model' => '',
                'parcel_capacity' => '',
            ],
        ]);
    }
}

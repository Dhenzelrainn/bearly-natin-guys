<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
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
        $validated = $request->validate([
            'logistics_partner' => ['required', 'integer', 'exists:users,id'],
            'first_name' => ['required', 'string', 'max:80'],
            'middle_initial' => ['nullable', 'string', 'max:5'],
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
            'password' => ['required', 'confirmed', 'min:8', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/'],
        ]);

        $logistics = User::query()
            ->whereKey($validated['logistics_partner'])
            ->where('role', UserRole::Logistics->value)
            ->where('status', AccountStatus::Active->value)
            ->firstOrFail();

        $user = User::create([
            'name' => trim($validated['first_name'].' '.($validated['middle_initial'] ?? '').' '.$validated['last_name']),
            'first_name' => $validated['first_name'],
            'middle_initial' => $validated['middle_initial'] ?? null,
            'last_name' => $validated['last_name'],
            'sex' => match ($validated['sex']) {
                'Male' => 'male',
                'Female' => 'female',
                default => 'prefer_not_to_say',
            },
            'birthday' => $validated['birthday'],
            'email' => $validated['email'],
            'contact_number' => $validated['contact_number'],
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

    public function deliveriesDashboard()
    {
        return view('rider.dashboard.deliveries', $this->shared() + [
            'deliveries' => [
                [
                    'id' => 'DL-8412',
                    'waybill' => 'BRL-983410',
                    'customer' => 'Karen Yu',
                    'address' => 'Brgy. San Lucas 1, San Pablo City',
                    'zone' => 'SP-N1',
                    'cod' => '₱1,290',
                    'status' => 'In Transit',
                ],
                [
                    'id' => 'DL-8413',
                    'waybill' => 'BRL-983411',
                    'customer' => 'Alyssa Tan',
                    'address' => 'Brgy. San Rafael, San Pablo City',
                    'zone' => 'SP-N1',
                    'cod' => 'Paid',
                    'status' => 'Assigned',
                ],
                [
                    'id' => 'DL-8414',
                    'waybill' => 'BRL-983416',
                    'customer' => 'Miguel Ramos',
                    'address' => 'Brgy. San Antonio 2, San Pablo City',
                    'zone' => 'SP-N1',
                    'cod' => '₱680',
                    'status' => 'Assigned',
                ],
            ],
            'availablePickups' => [
                ['id' => 'PU-24094', 'seller' => 'Mara Home Goods', 'area' => 'San Pablo South', 'parcels' => 4, 'distance' => '4.1 km'],
                ['id' => 'PU-24095', 'seller' => 'Tiny Tails Pet Co.', 'area' => 'San Pablo North', 'parcels' => 3, 'distance' => '5.7 km'],
            ],
        ]);
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

    public function deliver(string $id)
    {
        return view('rider.orders.delivery', $this->shared() + [
            'job' => [
                'id' => $id,
                'waybill' => 'BRL-983410',
                'customer' => 'Karen Yu',
                'contact' => '0918 441 2207',
                'address' => 'Blk 7 Lot 12, Brgy. San Lucas 1, San Pablo City',
                'payment' => 'Cash on Delivery',
                'amount' => '₱1,290',
                'notes' => 'Call upon arrival. Brown gate beside the pharmacy.',
                'distance' => '3.8 km',
                'estimated_time' => '14 minutes',
                'coordinates' => '14.0717° N, 121.3256° E',
            ],
        ]);
    }

    public function confirmDelivery(Request $request, string $id)
    {
        return back()->with(
            'job_status',
            "Delivery {$id} confirmed. Earnings preview updated."
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

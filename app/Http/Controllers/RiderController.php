<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class RiderController extends Controller
{
    private function shared(): array
    {
        return [
            'rider' => [
                'name' => 'Nico Flores',
                'initials' => 'NF',
                'email' => 'nico.rider@bearly.test',
                'role' => 'Active Rider',
                'vehicle' => 'Motorcycle',
                'plate' => 'NCR 4821',
            ],
            'topNotifications' => [
                [
                    'title' => 'New pickup job available nearby',
                    'time' => '4 min ago',
                    'type' => 'warning',
                ],
                [
                    'title' => '6 parcels assigned for delivery',
                    'time' => '13 min ago',
                    'type' => 'info',
                ],
                [
                    'title' => '₱420 payout posted',
                    'time' => '1 hr ago',
                    'type' => 'success',
                ],
            ],
        ];
    }

    public function landing()
    {
        return view('rider.landing');
    }

    public function login()
    {
        return view('rider.login');
    }

    public function submitLogin(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        // Front-end preview only.
        // Replace with real authentication later.
        session([
            'rider_preview_authenticated' => true,
            'rider_preview_email' => $validated['email'],
        ]);

        return redirect()
            ->route('rider.dashboard.deliveries')
            ->with('success', 'Welcome back, Rider.');
    }

    public function register()
    {
        // Preview list only.
        // Later this should come from approved Logistics accounts in the database.
        $logisticsPartners = [
            [
                'id' => 'jnt-laguna',
                'name' => 'J&T Express - Laguna Sorting Center',
            ],
            [
                'id' => 'spx-laguna',
                'name' => 'SPX Express - Laguna Hub',
            ],
            [
                'id' => 'flash-laguna',
                'name' => 'Flash Express - Laguna Hub',
            ],
        ];

        return view('rider.register', compact('logisticsPartners'));
    }

    public function submitRegistration(Request $request)
    {
        $validated = $request->validate([
            'logistics_partner' => ['required', 'string', 'max:120'],
            'full_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email'],
            'contact_number' => ['required', 'string', 'max:20'],
            'birthday' => ['required', 'date', 'before:today'],
            'province' => ['required', 'string', 'max:120'],
            'municipality' => ['required', 'string', 'max:120'],
            'barangay' => ['required', 'string', 'max:120'],
            'street' => ['required', 'string', 'max:180'],
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
        ]);

        // Front-end preview only.
        // Later: associate the Rider with a logistics_id
        // and notify the selected Logistics account.
        session([
            'rider_application' => [
                'full_name' => $validated['full_name'],
                'email' => $validated['email'],
                'logistics_partner' => $validated['logistics_partner'],
                'status' => 'Awaiting Logistics Approval',
            ],
        ]);

        return redirect()
            ->route('rider.register')
            ->with('registration_pending', true);
    }

    public function pickupsDashboard()
    {
        return view('rider.dashboard-pickups', $this->shared() + [
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
        ]);
    }

    public function deliveriesDashboard()
    {
        return view('rider.dashboard-deliveries', $this->shared() + [
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
        ]);
    }

    public function pickup(string $id)
    {
        return view('rider.pickup', $this->shared() + [
            'job' => [
                'id' => $id,
                'seller' => 'TechVault PH',
                'contact' => '0917 555 0148',
                'address' => 'Unit 4, San Rafael Commercial Arcade, Brgy. San Rafael, San Pablo City',
                'window' => '2:30–3:30 PM',
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
        return view('rider.deliver', $this->shared() + [
            'job' => [
                'id' => $id,
                'waybill' => 'BRL-983410',
                'customer' => 'Karen Yu',
                'contact' => '0918 441 2207',
                'address' => 'Blk 7 Lot 12, Brgy. San Lucas 1, San Pablo City',
                'payment' => 'Cash on Delivery',
                'amount' => '₱1,290',
                'notes' => 'Call upon arrival. Brown gate beside the pharmacy.',
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
        return view('rider.earnings', $this->shared() + [
            'earnings' => [
                'today' => 420,
                'week' => 2840,
                'month' => 11260,
                'pending' => 780,
                'completed_jobs' => 63,
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
        return view('rider.history', $this->shared() + [
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
            ],
        ]);
    }

    public function messages()
    {
        return view('rider.messages', $this->shared() + [
            'conversations' => [
                [
                    'name' => 'Bearly Sorting Center',
                    'role' => 'Logistics',
                    'initials' => 'BS',
                    'preview' => 'Your next dispatch is ready at Bay 2.',
                    'time' => '2:10 PM',
                ],
                [
                    'name' => 'Karen Yu',
                    'role' => 'Buyer',
                    'initials' => 'KY',
                    'preview' => 'Please call me when you are near.',
                    'time' => '1:55 PM',
                ],
                [
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
        return view('rider.account', $this->shared());
    }
}

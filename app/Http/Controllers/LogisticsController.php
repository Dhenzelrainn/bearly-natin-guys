<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LogisticsController extends Controller
{
    private function shared(): array
    {
        return [
            'operator' => [
                'name' => 'Mika Santos',
                'initials' => 'MS',
                'email' => 'ops@bearly.test',
                'role' => 'Sorting Center Manager',
            ],
            'topNotifications' => [
                [
                    'title' => '3 rider applications need review',
                    'time' => '5 min ago',
                    'type' => 'warning',
                ],
                [
                    'title' => '12 parcels entered the sorting center',
                    'time' => '18 min ago',
                    'type' => 'info',
                ],
                [
                    'title' => 'Dispatch SC-2406 completed',
                    'time' => '42 min ago',
                    'type' => 'success',
                ],
            ],
        ];
    }

    public function landing()
    {
        return view('logistics.landing');
    }

    public function login()
    {
        return view('logistics.login');
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
            'logistics_preview_authenticated' => true,
            'logistics_preview_email' => $validated['email'],
        ]);

        return redirect()
            ->route('logistics.dashboard')
            ->with('success', 'Welcome back to the Logistics Center.');
    }

    public function register()
    {
        return view('logistics.register');
    }

    public function submitRegistration(Request $request)
    {
        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            'representative_name' => ['required', 'string', 'max:120'],
            'sex' => ['required', 'in:Male,Female,Prefer not to say'],
            'email' => ['required', 'email'],
            'contact_number' => ['required', 'string', 'max:20'],
            'birthday' => ['required', 'date', 'before:today'],
            'province' => ['required', 'string', 'max:120'],
            'municipality' => ['required', 'string', 'max:120'],
            'barangay' => ['required', 'string', 'max:120'],
            'street' => ['required', 'string', 'max:180'],
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
        ]);

        // Front-end preview only.
        // Later: save application to database and notify Bearly Admin.
        session([
            'logistics_application' => [
                'business_name' => $validated['business_name'],
                'representative_name' => $validated['representative_name'],
                'email' => $validated['email'],
                'status' => 'Pending Administrator Approval',
            ],
        ]);

        return redirect()
            ->route('logistics.register')
            ->with('registration_pending', true);
    }

    public function dashboard()
    {
        return view('logistics.dashboard', $this->shared() + [
            'metrics' => [
                [
                    'label' => 'Pending Rider Applications',
                    'value' => 8,
                    'icon' => 'user-round-check',
                    'trend' => '3 added today',
                ],
                [
                    'label' => 'Parcels in Sorting Center',
                    'value' => 146,
                    'icon' => 'package-open',
                    'trend' => '+18 since 8 AM',
                ],
                [
                    'label' => 'Active Deliveries',
                    'value' => 39,
                    'icon' => 'truck',
                    'trend' => '31 on schedule',
                ],
                [
                    'label' => 'Unassigned Shipments',
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
            'activity' => [
                [
                    'time' => '2:14 PM',
                    'title' => 'Parcel batch received',
                    'detail' => '12 parcels from TechVault PH',
                ],
                [
                    'time' => '1:58 PM',
                    'title' => 'Rider approved',
                    'detail' => 'Jared Molina • Motorcycle',
                ],
                [
                    'time' => '1:32 PM',
                    'title' => 'Dispatch assigned',
                    'detail' => '9 parcels → Zone SP-N2',
                ],
                [
                    'time' => '12:47 PM',
                    'title' => 'Pickup request approved',
                    'detail' => 'Mara Home Goods • 5 parcels',
                ],
            ],
        ]);
    }

    public function riders()
    {
        return view('logistics.riders', $this->shared() + [
            'applications' => [
                [
                    'id' => 'RA-1048',
                    'name' => 'Jared Molina',
                    'vehicle' => 'Motorcycle',
                    'plate' => 'ABC 1234',
                    'area' => 'San Pablo North',
                    'submitted' => 'Sep 6, 2026',
                    'status' => 'Pending',
                ],
                [
                    'id' => 'RA-1047',
                    'name' => 'Lara Mendoza',
                    'vehicle' => 'Motorcycle',
                    'plate' => 'NCD 8831',
                    'area' => 'Pila / Sta. Cruz',
                    'submitted' => 'Sep 6, 2026',
                    'status' => 'Pending',
                ],
                [
                    'id' => 'RA-1045',
                    'name' => 'Paolo Reyes',
                    'vehicle' => 'E-bike',
                    'plate' => '—',
                    'area' => 'San Pablo South',
                    'submitted' => 'Sep 5, 2026',
                    'status' => 'Needs Review',
                ],
            ],
            'riders' => [
                [
                    'name' => 'Nico Flores',
                    'vehicle' => 'Motorcycle',
                    'zone' => 'SP-N1',
                    'jobs' => 18,
                    'rating' => '4.9',
                    'status' => 'Active',
                ],
                [
                    'name' => 'Anne Cruz',
                    'vehicle' => 'Motorcycle',
                    'zone' => 'SP-S2',
                    'jobs' => 15,
                    'rating' => '4.8',
                    'status' => 'Active',
                ],
                [
                    'name' => 'Marco Lim',
                    'vehicle' => 'Van',
                    'zone' => 'PILA-1',
                    'jobs' => 11,
                    'rating' => '4.7',
                    'status' => 'Inactive',
                ],
            ],
        ]);
    }

    public function pickups()
    {
        return view('logistics.pickups', $this->shared() + [
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

    public function sorting()
    {
        return view('logistics.sorting', $this->shared() + [
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
        return view('logistics.dispatch', $this->shared() + [
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
        return view('logistics.monitoring', $this->shared() + [
            'deliveries' => [
                [
                    'id' => 'DL-8412',
                    'rider' => 'Nico Flores',
                    'zone' => 'SP-N1',
                    'parcels' => 8,
                    'progress' => 72,
                    'status' => 'In Transit',
                    'last' => 'Brgy. San Lucas • 2:02 PM',
                ],
                [
                    'id' => 'DL-8411',
                    'rider' => 'Anne Cruz',
                    'zone' => 'SP-S2',
                    'parcels' => 6,
                    'progress' => 100,
                    'status' => 'Delivered',
                    'last' => 'Completed • 1:48 PM',
                ],
                [
                    'id' => 'DL-8410',
                    'rider' => 'Marco Lim',
                    'zone' => 'PILA-1',
                    'parcels' => 5,
                    'progress' => 20,
                    'status' => 'Assigned',
                    'last' => 'Sorting Center • 1:31 PM',
                ],
                [
                    'id' => 'DL-8408',
                    'rider' => 'Paolo Reyes',
                    'zone' => 'CAL-1',
                    'parcels' => 4,
                    'progress' => 55,
                    'status' => 'Failed',
                    'last' => 'Customer unavailable • 12:54 PM',
                ],
            ],
        ]);
    }

    public function reports()
    {
        return view('logistics.reports', $this->shared() + [
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
        ]);
    }

    public function messages()
    {
        return view('logistics.messages', $this->shared() + [
            'conversations' => [
                [
                    'name' => 'TechVault PH',
                    'role' => 'Seller',
                    'initials' => 'TP',
                    'preview' => 'Pickup batch is ready at our counter.',
                    'time' => '2:07 PM',
                ],
                [
                    'name' => 'Nico Flores',
                    'role' => 'Rider',
                    'initials' => 'NF',
                    'preview' => 'I finished the SP-N1 route.',
                    'time' => '1:51 PM',
                ],
                [
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
        return view('logistics.account', $this->shared());
    }
}

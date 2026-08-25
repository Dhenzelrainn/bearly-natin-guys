<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class CourierController extends Controller
{
    private function base(array $data = []): array
    {
        return array_merge([
            'courier' => [
                'name' => 'Adrian Cruz',
                'email' => 'adrian.courier@bearly.test',
                'initials' => 'AC',
                'vehicle' => 'Motorcycle Courier',
                'plate' => 'NCR 4821',
                'rating' => '4.9',
                'status' => 'Online',
            ],
            'topNotifications' => [
                ['title' => 'New pickup request nearby', 'time' => '2 min ago', 'type' => 'success'],
                ['title' => 'Customer updated delivery note', 'time' => '9 min ago', 'type' => 'warning'],
                ['title' => 'Weekly earnings summary is ready', 'time' => '1 hr ago', 'type' => 'info'],
            ],
        ], $data);
    }

    public function register(): View
    {
        return view('courier.register', $this->base([
            'vehicleTypes' => ['Motorcycle', 'Bicycle', 'Sedan / Car', 'Van', 'Pickup Truck'],
        ]));
    }

    public function pending(): View
    {
        return view('courier.pending', $this->base());
    }

    public function dashboard(): View
    {
        return view('courier.dashboard', $this->base([
            'kpis' => [
                ['label' => 'Completed Today', 'value' => '8', 'change' => '+2 vs yesterday', 'trend' => 'up', 'icon' => 'badge-check'],
                ['label' => 'Active Deliveries', 'value' => '1', 'change' => 'In transit', 'trend' => 'alert', 'icon' => 'navigation'],
                ['label' => 'Available Requests', 'value' => '6', 'change' => '3 nearby', 'trend' => 'up', 'icon' => 'package-search'],
                ['label' => "Today's Earnings", 'value' => '₱1,248', 'change' => '+14.8%', 'trend' => 'up', 'icon' => 'wallet-cards'],
            ],
            'earningsSeries' => [62, 74, 58, 88, 71, 96, 82],
            'deliveryNotices' => [
                ['title' => 'Pickup ready at Northstar Watches', 'text' => 'Order ORD-71042 is packed and ready for collection in Quezon City.', 'type' => 'success', 'route' => 'courier.pickup'],
                ['title' => 'New request 1.8 km away', 'text' => 'A small parcel delivery to San Juan is available for ₱128 payout.', 'type' => 'info', 'route' => 'courier.requests'],
                ['title' => 'Delivery note updated', 'text' => 'Customer requested lobby handoff for ORD-71038.', 'type' => 'warning', 'route' => 'courier.transit'],
            ],
            'pickupRequests' => [
                ['id' => 'REQ-4108', 'seller' => 'Northstar Watches', 'pickup' => 'Cubao, Quezon City', 'dropoff' => 'Greenhills, San Juan', 'distance' => '5.8 km', 'payout' => '₱148', 'eta' => '24 min'],
                ['id' => 'REQ-4109', 'seller' => 'Daily Finds MNL', 'pickup' => 'Mandaluyong City', 'dropoff' => 'BGC, Taguig', 'distance' => '7.1 km', 'payout' => '₱176', 'eta' => '31 min'],
                ['id' => 'REQ-4110', 'seller' => 'Homecraft PH', 'pickup' => 'Pasig City', 'dropoff' => 'Marikina City', 'distance' => '6.4 km', 'payout' => '₱159', 'eta' => '28 min'],
            ],
            'activity' => [
                ['title' => 'Delivery completed', 'meta' => 'ORD-71021 • ₱142 earned • 3:42 PM', 'type' => 'success'],
                ['title' => 'Pickup confirmed', 'meta' => 'ORD-71031 • Northstar Watches • 4:18 PM', 'type' => 'info'],
                ['title' => 'Customer message received', 'meta' => 'ORD-71038 • Lobby handoff note • 4:46 PM', 'type' => 'warning'],
            ],
        ]));
    }

    public function requests(): View
    {
        return view('courier.requests', $this->base([
            'jobs' => [
                ['id' => 'REQ-4108', 'order' => 'ORD-71042', 'seller' => 'Northstar Watches', 'pickup' => 'Gateway Tower 2, Cubao, Quezon City', 'dropoff' => 'Greenhills Shopping Center, San Juan', 'distance' => '5.8 km', 'payout' => '₱148', 'eta' => '24 min', 'size' => 'Small', 'priority' => 'Standard'],
                ['id' => 'REQ-4109', 'order' => 'ORD-71043', 'seller' => 'Daily Finds MNL', 'pickup' => 'Shaw Blvd., Mandaluyong City', 'dropoff' => 'High Street, BGC, Taguig', 'distance' => '7.1 km', 'payout' => '₱176', 'eta' => '31 min', 'size' => 'Medium', 'priority' => 'Priority'],
                ['id' => 'REQ-4110', 'order' => 'ORD-71044', 'seller' => 'Homecraft PH', 'pickup' => 'Ortigas Center, Pasig City', 'dropoff' => 'Riverbanks, Marikina City', 'distance' => '6.4 km', 'payout' => '₱159', 'eta' => '28 min', 'size' => 'Medium', 'priority' => 'Standard'],
                ['id' => 'REQ-4111', 'order' => 'ORD-71045', 'seller' => 'Tech Alley', 'pickup' => 'Tomas Morato, Quezon City', 'dropoff' => 'Katipunan Ave., Quezon City', 'distance' => '4.2 km', 'payout' => '₱126', 'eta' => '19 min', 'size' => 'Small', 'priority' => 'Priority'],
                ['id' => 'REQ-4112', 'order' => 'ORD-71046', 'seller' => 'Casa Living', 'pickup' => 'San Juan City', 'dropoff' => 'Makati CBD', 'distance' => '8.9 km', 'payout' => '₱214', 'eta' => '38 min', 'size' => 'Large', 'priority' => 'Standard'],
                ['id' => 'REQ-4113', 'order' => 'ORD-71047', 'seller' => 'Wear Local', 'pickup' => 'Makati City', 'dropoff' => 'Pasay City', 'distance' => '5.1 km', 'payout' => '₱139', 'eta' => '22 min', 'size' => 'Small', 'priority' => 'Standard'],
            ],
        ]));
    }

    public function pickup(): View
    {
        return view('courier.pickup', $this->base([
            'task' => [
                'order' => 'ORD-71042',
                'seller' => 'Northstar Watches',
                'seller_contact' => '+63 917 555 2841',
                'address' => 'Gateway Tower 2, General Roxas Ave., Cubao, Quezon City',
                'pickup_window' => '5:20 PM – 5:45 PM',
                'package' => 'Small parcel • 0.7 kg',
                'buyer' => 'Mika Reyes',
                'payout' => '₱148',
                'items' => [
                    ['name' => 'Classic Steel Watch', 'variant' => 'Silver / 42mm', 'qty' => 1],
                    ['name' => 'Protective Watch Pouch', 'variant' => 'Brown', 'qty' => 1],
                ],
            ],
        ]));
    }

    public function transit(): View
    {
        return view('courier.transit', $this->base([
            'delivery' => [
                'order' => 'ORD-71038',
                'customer' => 'Mika Reyes',
                'phone' => '+63 917 555 1038',
                'address' => 'One Wilson Square, Greenhills, San Juan City',
                'distance_remaining' => '2.4 km',
                'eta' => '11 min',
                'seller' => 'Northstar Watches',
                'instructions' => 'Please hand the parcel to the lobby receptionist if I am not downstairs. Call before arrival.',
            ],
        ]));
    }

    public function complete(): View
    {
        return view('courier.complete', $this->base([
            'delivery' => [
                'order' => 'ORD-71038',
                'customer' => 'Mika Reyes',
                'address' => 'One Wilson Square, Greenhills, San Juan City',
                'seller' => 'Northstar Watches',
                'amount' => '₱6,390',
                'payment' => 'Cashless / Paid',
                'payout' => '₱148',
            ],
        ]));
    }

    public function earnings(): View
    {
        return view('courier.earnings', $this->base([
            'summary' => [
                ['label' => 'Today', 'value' => '₱1,248', 'note' => '8 completed deliveries'],
                ['label' => 'This Week', 'value' => '₱6,840', 'note' => '+12.4% from last week'],
                ['label' => 'This Month', 'value' => '₱26,390', 'note' => '164 completed deliveries'],
                ['label' => 'Avg. / Delivery', 'value' => '₱160.91', 'note' => '+₱8.40 this month'],
            ],
            'series' => [68, 92, 74, 100, 84, 112, 96],
            'breakdown' => [
                ['label' => 'Base delivery pay', 'value' => '₱5,460'],
                ['label' => 'Distance incentives', 'value' => '₱870'],
                ['label' => 'Tips', 'value' => '₱690'],
                ['label' => 'Platform deductions', 'value' => '-₱180'],
            ],
            'rows' => [
                ['date' => 'Aug 25, 2026', 'deliveries' => 8, 'base' => '₱960', 'incentives' => '₱178', 'tips' => '₱130', 'deductions' => '₱20', 'net' => '₱1,248'],
                ['date' => 'Aug 24, 2026', 'deliveries' => 7, 'base' => '₱840', 'incentives' => '₱150', 'tips' => '₱90', 'deductions' => '₱18', 'net' => '₱1,062'],
                ['date' => 'Aug 23, 2026', 'deliveries' => 9, 'base' => '₱1,080', 'incentives' => '₱210', 'tips' => '₱145', 'deductions' => '₱26', 'net' => '₱1,409'],
                ['date' => 'Aug 22, 2026', 'deliveries' => 6, 'base' => '₱720', 'incentives' => '₱132', 'tips' => '₱75', 'deductions' => '₱16', 'net' => '₱911'],
            ],
        ]));
    }

    public function history(): View
    {
        return view('courier.history', $this->base([
            'deliveries' => [
                ['id' => 'ORD-71021', 'date' => 'Aug 25, 2026 • 3:42 PM', 'seller' => 'Daily Finds MNL', 'buyer' => 'Karen Yu', 'route' => 'Mandaluyong → Taguig', 'distance' => '7.4 km', 'payout' => '₱142', 'status' => 'Completed'],
                ['id' => 'ORD-71018', 'date' => 'Aug 25, 2026 • 2:16 PM', 'seller' => 'Homecraft PH', 'buyer' => 'Theo Garcia', 'route' => 'Pasig → Marikina', 'distance' => '6.1 km', 'payout' => '₱155', 'status' => 'Completed'],
                ['id' => 'ORD-71002', 'date' => 'Aug 25, 2026 • 11:04 AM', 'seller' => 'Wear Local', 'buyer' => 'Liza Ong', 'route' => 'Makati → Pasay', 'distance' => '4.9 km', 'payout' => '₱136', 'status' => 'Completed'],
                ['id' => 'ORD-70984', 'date' => 'Aug 24, 2026 • 7:31 PM', 'seller' => 'Tech Alley', 'buyer' => 'Paolo Reyes', 'route' => 'Quezon City → San Juan', 'distance' => '5.5 km', 'payout' => '₱0', 'status' => 'Cancelled'],
                ['id' => 'ORD-70961', 'date' => 'Aug 24, 2026 • 5:18 PM', 'seller' => 'Casa Living', 'buyer' => 'Nina Lim', 'route' => 'San Juan → Makati', 'distance' => '8.7 km', 'payout' => '₱92', 'status' => 'Returned'],
                ['id' => 'ORD-70947', 'date' => 'Aug 24, 2026 • 1:52 PM', 'seller' => 'Northstar Watches', 'buyer' => 'Mika Reyes', 'route' => 'Quezon City → San Juan', 'distance' => '5.9 km', 'payout' => '₱149', 'status' => 'Completed'],
            ],
        ]));
    }

    public function messages(): View
    {
        return view('courier.messages', $this->base([
            'conversations' => [
                ['name' => 'Mika Reyes', 'role' => 'Buyer', 'preview' => 'Please call when you reach the lobby.', 'time' => '5:02 PM', 'unread' => 1, 'initials' => 'MR'],
                ['name' => 'Northstar Watches', 'role' => 'Seller', 'preview' => 'The parcel is ready at counter 3.', 'time' => '4:38 PM', 'unread' => 0, 'initials' => 'NW'],
                ['name' => 'Bearly Support', 'role' => 'Admin', 'preview' => 'Your weekly courier summary is ready.', 'time' => '2:10 PM', 'unread' => 0, 'initials' => 'BS'],
                ['name' => 'Karen Yu', 'role' => 'Buyer', 'preview' => 'Thank you for the delivery!', 'time' => 'Yesterday', 'unread' => 0, 'initials' => 'KY'],
            ],
            'messages' => [
                ['from' => 'them', 'text' => 'Hi! Please call when you reach the lobby. I can meet you downstairs.', 'time' => '4:58 PM'],
                ['from' => 'me', 'text' => 'Sure. I am about 10 minutes away and will call before arrival.', 'time' => '5:00 PM'],
                ['from' => 'them', 'text' => 'Perfect, thank you!', 'time' => '5:02 PM'],
            ],
        ]));
    }

    public function account(): View
    {
        return view('courier.account', $this->base([
            'profile' => [
                'first_name' => 'Adrian',
                'last_name' => 'Cruz',
                'email' => 'adrian.courier@bearly.test',
                'phone' => '+63 917 555 4821',
                'birthday' => '1998-04-18',
                'sex' => 'Male',
                'base_location' => 'Cubao, Quezon City',
            ],
            'vehicle' => [
                'type' => 'Motorcycle',
                'model' => 'Honda Click 160',
                'plate' => 'NCR 4821',
                'orcr' => 'Verified',
                'license' => 'Verified',
            ],
            'addresses' => [
                ['label' => 'Primary base', 'address' => 'Cubao, Quezon City', 'note' => 'Preferred starting area'],
                ['label' => 'Secondary area', 'address' => 'Mandaluyong City', 'note' => 'Available on weekends'],
            ],
        ]));
    }
}

<?php

namespace Tests\Feature\Rider;

use App\Models\Address;
use App\Models\DeliveryAttempt;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\PickupAssignment;
use App\Models\PickupRequest;
use App\Models\RiderEarning;
use App\Models\RiderProfile;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class RiderEarningsTest extends TestCase
{
    use RefreshDatabase;

    private LogisticsProfile $logisticsProfile;

    private SortingCenter $sortingCenter;

    protected function setUp(): void
    {
        parent::setUp();

        $logisticsUser = User::factory()->create([
            'name' => 'Earnings Logistics',
            'role' => 'logistics',
            'status' => 'active',
        ]);

        $this->logisticsProfile = LogisticsProfile::query()->create([
            'user_id' => $logisticsUser->id,
            'legal_name' => 'Earnings Logistics Inc.',
            'display_name' => 'Earnings Logistics',
            'contact_phone' => '09170000001',
            'status' => 'active',
        ]);

        $address = $this->makeAddress('Earnings Center');

        $this->sortingCenter = SortingCenter::query()->create([
            'logistics_profile_id' => $this->logisticsProfile->id,
            'address_id' => $address->id,
            'name' => 'Earnings Sorting Center',
            'code' => 'EARNINGS-CENTER',
            'status' => 'active',
        ]);

        SortingZone::query()->create([
            'sorting_center_id' => $this->sortingCenter->id,
            'code' => 'EARNINGS-ZONE',
            'name' => 'Earnings Zone',
            'status' => 'active',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-18 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_earnings_are_rider_scoped_and_totals_exclude_reversed_and_cancelled_rows(): void
    {
        $rider = $this->makeRider('OWNER');
        $otherRider = $this->makeRider('OTHER');
        $pickup = $this->makePickupAssignment($rider, 'OWNER');
        $deliveryAttempt = $this->makeDeliveryAttempt($rider, 'OWNER');

        $this->makeEarning($rider, [
            'pickup_assignment_id' => $pickup->id,
            'type' => 'pickup',
            'amount_minor' => 7000,
            'status' => 'posted',
            'description' => 'Owner pickup earning',
            'earned_at' => Carbon::parse('2026-10-18 09:00:00'),
            'posted_at' => Carbon::parse('2026-10-18 09:15:00'),
        ]);
        $this->makeEarning($rider, [
            'delivery_attempt_id' => $deliveryAttempt->id,
            'type' => 'delivery',
            'amount_minor' => 3000,
            'status' => 'pending',
            'description' => 'Owner delivery earning',
            'earned_at' => Carbon::parse('2026-10-17 09:00:00'),
        ]);
        $this->makeEarning($rider, [
            'type' => 'adjustment',
            'amount_minor' => 5000,
            'status' => 'paid',
            'description' => 'Owner paid adjustment',
            'earned_at' => Carbon::parse('2026-10-03 09:00:00'),
            'posted_at' => Carbon::parse('2026-10-03 10:00:00'),
            'paid_at' => Carbon::parse('2026-10-04 10:00:00'),
        ]);
        $this->makeEarning($rider, [
            'type' => 'delivery',
            'amount_minor' => 9000,
            'status' => 'reversed',
            'description' => 'Owner reversed row',
            'earned_at' => Carbon::parse('2026-10-18 10:00:00'),
        ]);
        $this->makeEarning($rider, [
            'type' => 'delivery',
            'amount_minor' => -1000,
            'status' => 'cancelled',
            'description' => 'Owner cancelled row',
            'earned_at' => Carbon::parse('2026-10-17 10:00:00'),
        ]);
        $this->makeEarning($otherRider, [
            'type' => 'delivery',
            'amount_minor' => 99999,
            'status' => 'posted',
            'description' => 'Other rider private earning',
            'earned_at' => Carbon::parse('2026-10-18 08:00:00'),
        ]);

        $response = $this->actingAs($rider->user)
            ->get(route('rider.earnings.index'));

        $response->assertOk()
            ->assertSeeText('PU-EARNINGS-OWNER')
            ->assertSeeText('DL-EARNINGS-OWNER')
            ->assertSeeText('Pickup assignment #'.$pickup->id)
            ->assertSeeText('Delivery attempt #'.$deliveryAttempt->id)
            ->assertSeeText('Pending')
            ->assertSeeText('Posted')
            ->assertSeeText('Paid')
            ->assertSeeText('Reversed')
            ->assertSeeText('Cancelled')
            ->assertDontSeeText('Other rider private earning')
            ->assertDontSeeText('DL-8411')
            ->assertDontSeeText('₱420.00')
            ->assertDontSeeText('GCash ending')
            ->assertDontSeeText('Next release')
            ->assertViewHas('earnings', function (array $summary): bool {
                return $summary['today']['amount_minor'] === 7000
                    && $summary['today']['entries'] === 1
                    && $summary['week']['amount_minor'] === 10000
                    && $summary['week']['entries'] === 2
                    && $summary['month']['amount_minor'] === 15000
                    && $summary['month']['entries'] === 3
                    && $summary['active_total_minor'] === 15000
                    && $summary['active_entries'] === 3
                    && $summary['excluded_entries'] === 2;
            });
    }

    public function test_earnings_filters_paginate_and_export_the_full_server_side_result(): void
    {
        $rider = $this->makeRider('FILTERS');

        for ($index = 1; $index <= 25; $index++) {
            $this->makeEarning($rider, [
                'type' => 'delivery',
                'amount_minor' => 7000 + $index,
                'status' => 'posted',
                'description' => 'Bulk delivery '.$index,
                'earned_at' => Carbon::parse('2026-10-10 08:00:00')->addMinutes($index),
            ]);
        }

        $this->makeEarning($rider, [
            'type' => 'pickup',
            'amount_minor' => 5000,
            'status' => 'pending',
            'description' => 'Different filtered row',
            'earned_at' => Carbon::parse('2026-10-10 12:00:00'),
        ]);

        $otherRider = $this->makeRider('FILTERS-OTHER');
        $this->makeEarning($otherRider, [
            'type' => 'delivery',
            'amount_minor' => 8000,
            'status' => 'posted',
            'description' => 'Bulk delivery other rider',
            'earned_at' => Carbon::parse('2026-10-10 13:00:00'),
        ]);

        $filters = [
            'search' => 'Bulk delivery',
            'status' => 'posted',
            'type' => 'delivery',
            'date_from' => '2026-10-10',
            'date_to' => '2026-10-10',
        ];

        $response = $this->actingAs($rider->user)
            ->get(route('rider.earnings.index', $filters));

        $response->assertOk()
            ->assertSeeText('Bulk delivery 25')
            ->assertSeeText('Bulk delivery 6')
            ->assertDontSeeText('Different filtered row')
            ->assertDontSeeText('Bulk delivery other rider')
            ->assertViewHas('logs', function ($logs): bool {
                return $logs->total() === 25
                    && $logs->count() === 20;
            });

        $csv = $this->actingAs($rider->user)
            ->get(route('rider.earnings.export', $filters))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Amount (centavos)', $csv);
        $this->assertStringContainsString('Bulk delivery 1', $csv);
        $this->assertStringContainsString('Bulk delivery 25', $csv);
        $this->assertStringNotContainsString('Different filtered row', $csv);
        $this->assertStringNotContainsString('Bulk delivery other rider', $csv);
        $this->assertSame(25, substr_count($csv, 'Bulk delivery '));
    }

    public function test_earnings_show_an_honest_empty_state_and_validate_date_boundaries(): void
    {
        $rider = $this->makeRider('EMPTY');

        $this->actingAs($rider->user)
            ->get(route('rider.earnings.index'))
            ->assertOk()
            ->assertSeeText('No earning ledger entries are currently recorded for your Rider profile.')
            ->assertSeeText('₱0.00')
            ->assertDontSeeText('pending payout')
            ->assertDontSeeText('GCash');

        $this->actingAs($rider->user)
            ->get(route('rider.earnings.index', [
                'date_from' => '2026-10-20',
                'date_to' => '2026-10-19',
            ]))
            ->assertSessionHasErrors('date_to');
    }

    private function makeRider(string $suffix): RiderProfile
    {
        $user = User::factory()->create([
            'name' => 'Earnings Rider '.$suffix,
            'email' => Str::lower('earnings-'.$suffix.'@example.test'),
            'role' => 'rider',
            'status' => 'active',
            'logistics_id' => $this->logisticsProfile->user_id,
        ]);

        return RiderProfile::query()->create([
            'user_id' => $user->id,
            'logistics_profile_id' => $this->logisticsProfile->id,
            'home_sorting_center_id' => $this->sortingCenter->id,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'EARN-'.$suffix,
            'parcel_capacity' => 20,
            'availability_status' => 'available',
            'verification_status' => 'approved',
        ]);
    }

    private function makeEarning(RiderProfile $rider, array $attributes): RiderEarning
    {
        return RiderEarning::query()->create(array_merge([
            'rider_profile_id' => $rider->id,
            'type' => 'delivery',
            'amount_minor' => 0,
            'status' => 'pending',
            'earned_at' => now(),
        ], $attributes));
    }

    private function makePickupAssignment(RiderProfile $rider, string $suffix): PickupAssignment
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
        ]);
        $sellerProfile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Earnings Seller '.$suffix,
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $sellerProfile->id,
            'name' => 'Earnings Store '.$suffix,
            'slug' => Str::slug('earnings-store-'.$suffix),
            'publication_status' => 'published',
        ]);
        $pickupAddress = $this->makeAddress('Pickup '.$suffix);
        $pickup = PickupRequest::query()->create([
            'pickup_no' => 'PU-EARNINGS-'.$suffix,
            'store_id' => $store->id,
            'logistics_profile_id' => $this->logisticsProfile->id,
            'pickup_address_id' => $pickupAddress->id,
            'status' => 'scheduled',
            'requested_date' => '2026-10-18',
            'window_start' => '2026-10-18 08:00:00',
            'window_end' => '2026-10-18 12:00:00',
        ]);

        return PickupAssignment::query()->create([
            'pickup_request_id' => $pickup->id,
            'rider_profile_id' => $rider->id,
            'status' => 'assigned',
            'assigned_at' => '2026-10-18 07:00:00',
        ]);
    }

    private function makeDeliveryAttempt(RiderProfile $rider, string $suffix): DeliveryAttempt
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);
        $destination = $this->makeAddress('Delivery '.$suffix, $buyer->id);
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
        ]);
        $sellerProfile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Delivery Seller '.$suffix,
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $sellerProfile->id,
            'name' => 'Delivery Store '.$suffix,
            'slug' => Str::slug('delivery-store-'.$suffix),
            'publication_status' => 'published',
        ]);
        $order = Order::query()->create([
            'order_no' => 'ORD-EARNINGS-'.$suffix,
            'buyer_id' => $buyer->id,
            'shipping_address_id' => $destination->id,
            'recipient_name' => 'Delivery Recipient '.$suffix,
            'recipient_phone' => '09170000002',
            'address_line' => '1 Delivery Street',
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'postal_code' => '4000',
            'subtotal_minor' => 10000,
            'shipping_fee_minor' => 0,
            'discount_minor' => 0,
            'total_minor' => 10000,
            'status' => 'placed',
            'payment_status' => 'paid',
            'placed_at' => '2026-10-17 07:00:00',
        ]);
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => 'SORD-EARNINGS-'.$suffix,
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => 'confirmed',
            'subtotal_minor' => 10000,
            'discount_minor' => 0,
            'shipping_fee_minor' => 0,
            'total_minor' => 10000,
        ]);
        $shipment = Shipment::query()->create([
            'shipment_no' => 'DL-EARNINGS-'.$suffix,
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $this->logisticsProfile->id,
            'origin_address_id' => $destination->id,
            'destination_address_id' => $destination->id,
            'purpose' => 'outbound',
            'status' => 'out_for_delivery',
            'shipping_fee_minor' => 0,
            'cod_amount_minor' => 0,
        ]);
        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => 'WB-EARNINGS-'.$suffix,
            'scan_token' => hash('sha256', 'token-'.$suffix),
            'barcode_value' => 'BAR-EARNINGS-'.$suffix,
            'piece_count' => 1,
            'total_weight_kg' => 1,
            'generated_at' => '2026-10-17 08:00:00',
        ]);
        $parcel = Parcel::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_id' => $waybill->id,
            'parcel_no' => 'PARCEL-EARNINGS-'.$suffix,
            'piece_sequence' => 1,
            'weight_kg' => 1,
            'status' => 'out_for_delivery',
        ]);

        return DeliveryAttempt::query()->create([
            'parcel_id' => $parcel->id,
            'rider_profile_id' => $rider->id,
            'attempt_no' => 1,
            'outcome' => 'delivered',
            'attempted_at' => '2026-10-17 09:00:00',
        ]);
    }

    private function makeAddress(string $label, ?int $userId = null): Address
    {
        return Address::query()->create([
            'user_id' => $userId,
            'label' => $label,
            'recipient_name' => $label,
            'phone' => '09170000003',
            'street' => '1 Bearly Street',
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'postal_code' => '4000',
        ]);
    }
}

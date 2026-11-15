<?php

namespace Tests\Feature;

use App\Models\AccountApplication;
use App\Models\Address;
use App\Models\LinehaulRun;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Parcel;
use App\Models\PickupAssignment;
use App\Models\PickupRequest;
use App\Models\PlatformCampaignParticipation;
use App\Models\PlatformPromotion;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\RiderProfile;
use App\Models\RiderVehicleChangeRequest;
use App\Models\Role;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\ShipmentTransfer;
use App\Models\SortingCenter;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use App\Services\NavigationTaskCountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NavigationTaskCountServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_admin_registration_badges_match_each_persisted_review_queue(): void
    {
        $sellerRole = Role::query()->create(['name' => 'seller', 'display_name' => 'Seller']);
        $logisticsRole = Role::query()->create(['name' => 'logistics', 'display_name' => 'Logistics']);
        $buyerRole = Role::query()->create(['name' => 'buyer', 'display_name' => 'Buyer']);

        $sellerPending = $this->user('seller', ['status' => 'pending']);
        $sellerSubmitted = $this->user('seller', ['status' => 'pending']);
        $logisticsUnderReview = $this->user('logistics', ['status' => 'needs_revision']);
        $legacyBuyer = $this->user('buyer', ['status' => 'pending']);

        $this->application($sellerPending, $sellerRole, 'pending');
        $this->application($sellerSubmitted, $sellerRole, 'submitted');
        $this->application($logisticsUnderReview, $logisticsRole, 'under_review');
        $this->application($legacyBuyer, $buyerRole, 'submitted');

        $counts = app(NavigationTaskCountService::class)->forUser($this->user('admin'));

        $this->assertSame(2, $counts['admin.registrations']);
        $this->assertSame(2, $counts['admin.registrations.sellers']);
        $this->assertSame(1, $counts['admin.registrations.logistics']);

        $sellerPending->applications()->firstOrFail()->update(['status' => 'approved']);
        $counts = app(NavigationTaskCountService::class)->forUser($this->user('admin'));

        $this->assertSame(2, $counts['admin.registrations']);
        $this->assertSame(1, $counts['admin.registrations.sellers']);
    }

    public function test_seller_order_and_return_badges_are_store_scoped_and_follow_state_changes(): void
    {
        [$seller, $store] = $this->sellerAndStore('seller-task-owner');
        [, $otherStore] = $this->sellerAndStore('seller-task-other');
        $buyer = $this->user('buyer');

        $placed = $this->sellerOrder($store, $buyer, 'placed');
        $accepted = $this->sellerOrder($store, $buyer, 'accepted');
        $packing = $this->sellerOrder($store, $buyer, 'packing');
        $this->sellerOrder($store, $buyer, 'completed');
        $this->sellerOrder($otherStore, $buyer, 'placed');

        $awaitingSeller = $this->returnRequest($placed, 'awaiting_seller');
        $awaitingInspection = $this->returnRequest($accepted, 'awaiting_inspection');
        $awaitingEvidence = $this->returnRequest($packing, 'awaiting_evidence');
        $otherStoreReturn = $this->returnRequest($this->sellerOrder($otherStore, $buyer, 'placed'), 'awaiting_seller');
        $pendingQuarantine = $this->pendingQuarantineItem($awaitingEvidence);
        $this->pendingQuarantineItem($otherStoreReturn);

        $counts = app(NavigationTaskCountService::class)->forUser($seller);

        $this->assertSame(3, $counts['seller.orders']);
        $this->assertSame(3, $counts['seller.orders.returns']);
        $this->assertSame($counts, app(NavigationTaskCountService::class)->forUser($seller));

        $accepted->update(['status' => 'handed_to_logistics']);
        $awaitingSeller->update(['status' => 'under_review']);
        $counts = app(NavigationTaskCountService::class)->forUser($seller);

        $this->assertSame(2, $counts['seller.orders']);
        $this->assertSame(2, $counts['seller.orders.returns']);

        $awaitingInspection->update(['status' => 'resolved']);
        $this->assertSame(1, app(NavigationTaskCountService::class)->forUser($seller)['seller.orders.returns']);
        $pendingQuarantine->update(['quarantine_review_status' => 'completed']);
        $this->assertSame(0, app(NavigationTaskCountService::class)->forUser($seller)['seller.orders.returns']);
        $this->assertSame([], app(NavigationTaskCountService::class)->forUser($buyer));
    }

    public function test_seller_campaign_badge_counts_only_joinable_opportunities_for_its_store(): void
    {
        [$seller, $store] = $this->sellerAndStore('campaign-task-owner');
        [$otherSeller, $otherStore] = $this->sellerAndStore('campaign-task-other');
        $global = $this->campaign('Global campaign');
        $targetedToOwner = $this->campaign('Owner-only campaign');
        $targetedToOther = $this->campaign('Other-only campaign');
        $this->campaign('Expired campaign', [
            'join_deadline' => now('Asia/Manila')->subDay()->toDateString(),
        ]);

        $targetedToOwner->stores()->sync([$store->id]);
        $targetedToOther->stores()->sync([$otherStore->id]);

        $counts = app(NavigationTaskCountService::class)->forUser($seller);
        $this->assertSame(2, $counts['seller.products.pricing']);
        $this->assertSame(2, app(NavigationTaskCountService::class)->forUser($otherSeller)['seller.products.pricing']);

        $participation = PlatformCampaignParticipation::query()->create([
            'platform_promotion_id' => $global->id,
            'store_id' => $store->id,
            'status' => PlatformCampaignParticipation::STATUS_PENDING_REVIEW,
        ]);

        $this->assertSame(1, app(NavigationTaskCountService::class)->forUser($seller)['seller.products.pricing']);
        $this->assertSame(2, app(NavigationTaskCountService::class)->forUser($otherSeller)['seller.products.pricing']);

        $participation->update(['status' => PlatformCampaignParticipation::STATUS_WITHDRAWN]);
        $this->assertSame(1, app(NavigationTaskCountService::class)->forUser($seller)['seller.products.pricing']);

        $participation->update(['status' => 'rejected']);
        $this->assertSame(2, app(NavigationTaskCountService::class)->forUser($seller)['seller.products.pricing']);
    }

    public function test_logistics_and_rider_badges_are_provider_and_profile_scoped(): void
    {
        [$seller, $store] = $this->sellerAndStore('pickup-task-store');
        $address = $this->address($seller);
        $logisticsUser = $this->user('logistics');
        $otherLogisticsUser = $this->user('logistics');
        $provider = $this->logisticsProfile($logisticsUser);
        $otherProvider = $this->logisticsProfile($otherLogisticsUser);

        $localRiderUser = $this->user('rider');
        $otherRiderUser = $this->user('rider');
        $localRider = $this->riderProfile($localRiderUser, $provider, 'local');
        $otherRider = $this->riderProfile($otherRiderUser, $otherProvider, 'local');

        $this->vehicleChangeRequest($localRider);
        $this->vehicleChangeRequest($otherRider);

        $pickup = PickupRequest::query()->create([
            'pickup_no' => 'PK-'.str_pad((string) ++$this->sequence, 6, '0', STR_PAD_LEFT),
            'store_id' => $store->id,
            'logistics_profile_id' => $provider->id,
            'pickup_address_id' => $address->id,
            'status' => 'requested',
            'requested_date' => now()->toDateString(),
            'window_start' => now()->addHour(),
            'window_end' => now()->addHours(2),
        ]);
        PickupAssignment::query()->create([
            'pickup_request_id' => $pickup->id,
            'rider_profile_id' => $localRider->id,
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);

        $providerCounts = app(NavigationTaskCountService::class)->forUser($logisticsUser);
        $this->assertSame(1, $providerCounts['logistics.riders.vehicle-changes']);
        $this->assertSame(1, $providerCounts['logistics.pickups.index']);
        $this->assertSame(1, app(NavigationTaskCountService::class)->forUser($otherLogisticsUser)['logistics.riders.vehicle-changes']);

        $localCounts = app(NavigationTaskCountService::class)->forUser($localRiderUser);
        $this->assertSame(1, $localCounts['rider.dashboard.pickups']);
        $this->assertArrayNotHasKey('rider.courier.dashboard', $localCounts);
        $this->assertSame(0, app(NavigationTaskCountService::class)->forUser($otherRiderUser)['rider.dashboard.pickups']);

        $courierUser = $this->user('rider');
        $courier = $this->riderProfile($courierUser, $provider, 'courier');
        $centerAddress = $this->address($logisticsUser);
        $center = SortingCenter::query()->create([
            'logistics_profile_id' => $provider->id,
            'address_id' => $centerAddress->id,
            'name' => 'Navigation Test Center '.$this->sequence,
            'code' => 'NTC-'.str_pad((string) ++$this->sequence, 5, '0', STR_PAD_LEFT),
        ]);
        $run = LinehaulRun::query()->create([
            'run_no' => 'RUN-'.str_pad((string) ++$this->sequence, 6, '0', STR_PAD_LEFT),
            'origin_logistics_profile_id' => $provider->id,
            'destination_logistics_profile_id' => $otherProvider->id,
            'origin_sorting_center_id' => $center->id,
            'courier_rider_profile_id' => $courier->id,
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);

        $courierCounts = app(NavigationTaskCountService::class)->forUser($courierUser);
        $this->assertSame(1, $courierCounts['rider.courier.dashboard']);
        $this->assertArrayNotHasKey('rider.dashboard.pickups', $courierCounts);

        $run->update(['status' => 'completed']);
        $this->assertSame(0, app(NavigationTaskCountService::class)->forUser($courierUser)['rider.courier.dashboard']);
    }

    public function test_logistics_incoming_badge_counts_provider_waybills_matching_the_incoming_queue(): void
    {
        [$seller, $store] = $this->sellerAndStore('incoming-task-store');
        $buyer = $this->user('buyer');
        $provider = $this->logisticsProfile($this->user('logistics'));
        $otherProvider = $this->logisticsProfile($this->user('logistics'));
        $center = SortingCenter::query()->create([
            'logistics_profile_id' => $provider->id,
            'address_id' => $this->address($provider->user)->id,
            'name' => 'Incoming Test Center '.$this->sequence,
            'code' => 'ITC-'.str_pad((string) ++$this->sequence, 5, '0', STR_PAD_LEFT),
            'status' => 'active',
        ]);

        $this->incomingWaybill($store, $buyer, $provider, 'at_sorting_center', [
            ['status' => 'sorted', 'center_id' => $center->id],
            ['status' => 'in_transit', 'center_id' => $center->id],
        ]);
        $this->incomingWaybill($store, $buyer, $provider, 'at_sorting_center', [
            ['status' => 'picked_up', 'center_id' => null],
        ]);
        $this->incomingWaybill($store, $buyer, $provider, 'handoff_pending', [
            ['status' => 'picked_up', 'center_id' => null],
        ]);
        $this->incomingWaybill($store, $buyer, $otherProvider, 'at_sorting_center', [
            ['status' => 'sorted', 'center_id' => $center->id],
        ]);

        $counts = app(NavigationTaskCountService::class)->forUser($provider->user);

        $this->assertSame(2, $counts['logistics.sorting.incoming']);
    }

    public function test_city_handoff_badge_counts_ready_return_manifests_and_provider_incoming_transfers(): void
    {
        [, $store] = $this->sellerAndStore('city-handoff-task-store');
        $buyer = $this->user('buyer');
        $origin = $this->logisticsProfile($this->user('logistics'));
        $destination = $this->logisticsProfile($this->user('logistics'));
        $destinationWithoutCenter = $this->logisticsProfile($this->user('logistics'));
        $origin->update(['psgc_city_code' => '0403424000']);
        $destination->update(['psgc_city_code' => '0403422000']);
        $destinationWithoutCenter->update(['psgc_city_code' => '0403423000']);

        $originCenter = $this->sortingCenter($origin, 'City Handoff Origin');
        $destinationCenter = $this->sortingCenter($destination, 'City Handoff Destination');

        $readyReturn = $this->returnShipmentAtCenter(
            $store,
            $buyer,
            $origin,
            $originCenter,
            $destination->psgc_city_code,
        );
        $this->returnShipmentAtCenter(
            $store,
            $buyer,
            $origin,
            $originCenter,
            $destinationWithoutCenter->psgc_city_code,
        );
        $this->returnShipmentAtCenter(
            $store,
            $buyer,
            $origin,
            $originCenter,
            '0499999999',
        );

        $otherProviderReturn = $this->returnShipmentAtCenter(
            $store,
            $buyer,
            $destination,
            $destinationCenter,
            $origin->psgc_city_code,
        );

        $incomingWaybill = $this->incomingWaybill(
            $store,
            $buyer,
            $destination,
            'handoff_pending',
            [['status' => 'in_transit', 'center_id' => $destinationCenter->id]],
        );
        $incomingTransfer = ShipmentTransfer::query()->create([
            'shipment_id' => $incomingWaybill->shipment_id,
            'from_logistics_profile_id' => $destination->id,
            'to_logistics_profile_id' => $origin->id,
            'from_sorting_center_id' => $destinationCenter->id,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $counts = app(NavigationTaskCountService::class)->forUser($origin->user);
        $this->assertSame(2, $counts['logistics.transfers.index']);
        $this->assertSame(1, app(NavigationTaskCountService::class)->forUser($destination->user)['logistics.transfers.index']);
        $this->actingAs($origin->user)
            ->get(route('logistics.transfers.index'))
            ->assertOk()
            ->assertSee('aria-label="City Handoffs, 2 actionable tasks"', false)
            ->assertSee('<span class="nav-count" aria-hidden="true">2</span>', false);

        $readyReturn->parcels()->firstOrFail()->update(['status' => 'sorted']);
        $this->assertSame(1, app(NavigationTaskCountService::class)->forUser($origin->user)['logistics.transfers.index']);

        $incomingTransfer->update(['status' => 'accepted']);
        $this->assertSame(0, app(NavigationTaskCountService::class)->forUser($origin->user)['logistics.transfers.index']);
        $this->assertSame(1, app(NavigationTaskCountService::class)->forUser($destination->user)['logistics.transfers.index']);

        $this->assertSame('return', $otherProviderReturn->purpose);
    }

    private function user(string $role, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'status' => 'active',
        ], $overrides));
    }

    /** @return array{0: User, 1: Store} */
    private function sellerAndStore(string $slug): array
    {
        $seller = $this->user('seller');
        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Business '.$seller->id,
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => Str::headline($slug),
            'slug' => $slug.'-'.$seller->id,
            'publication_status' => 'published',
        ]);

        return [$seller->fresh('sellerProfile.store'), $store];
    }

    private function sellerOrder(Store $store, User $buyer, string $status): SellerOrder
    {
        $order = Order::query()->create([
            'order_no' => 'ORD-'.str_pad((string) ++$this->sequence, 8, '0', STR_PAD_LEFT),
            'buyer_id' => $buyer->id,
            'recipient_name' => $buyer->name,
            'recipient_phone' => '+639171234567',
            'address_line' => '10 Market Street',
            'barangay' => 'Balibago',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'currency' => 'PHP',
            'subtotal_minor' => 10000,
            'shipping_fee_minor' => 0,
            'discount_minor' => 0,
            'total_minor' => 10000,
            'status' => 'paid',
            'payment_status' => 'paid',
        ]);

        return SellerOrder::query()->create([
            'seller_order_no' => 'SO-'.str_pad((string) $this->sequence, 8, '0', STR_PAD_LEFT),
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => $status,
            'subtotal_minor' => 10000,
            'discount_minor' => 0,
            'shipping_fee_minor' => 0,
            'total_minor' => 10000,
        ]);
    }

    /**
     * @param  list<array{status: string, center_id: int|null}>  $parcels
     */
    private function incomingWaybill(
        Store $store,
        User $buyer,
        LogisticsProfile $provider,
        string $shipmentStatus,
        array $parcels,
    ): Waybill {
        $sellerOrder = $this->sellerOrder($store, $buyer, 'handed_to_logistics');
        $shipment = Shipment::query()->create([
            'shipment_no' => 'SHP-'.str_pad((string) ++$this->sequence, 8, '0', STR_PAD_LEFT),
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $provider->id,
            'purpose' => 'outbound',
            'status' => $shipmentStatus,
        ]);
        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => 'WB-'.str_pad((string) $this->sequence, 8, '0', STR_PAD_LEFT),
            'scan_token' => Str::random(64),
            'barcode_value' => 'NAV-WB-'.str_pad((string) $this->sequence, 8, '0', STR_PAD_LEFT),
            'piece_count' => count($parcels),
            'total_weight_kg' => 1,
            'generated_at' => now(),
        ]);

        foreach ($parcels as $index => $parcel) {
            Parcel::query()->create([
                'shipment_id' => $shipment->id,
                'waybill_id' => $waybill->id,
                'parcel_no' => 'NAV-P-'.str_pad((string) ($this->sequence * 10 + $index), 8, '0', STR_PAD_LEFT),
                'piece_sequence' => $index + 1,
                'weight_kg' => 1,
                'status' => $parcel['status'],
                'current_sorting_center_id' => $parcel['center_id'],
            ]);
        }

        return $waybill;
    }

    private function returnShipmentAtCenter(
        Store $store,
        User $buyer,
        LogisticsProfile $origin,
        SortingCenter $center,
        string $destinationCityCode,
    ): Shipment {
        $waybill = $this->incomingWaybill($store, $buyer, $origin, 'at_sorting_center', [
            ['status' => 'received', 'center_id' => $center->id],
        ]);
        $shipment = $waybill->shipment;
        $shipment->update([
            'purpose' => 'return',
            'destination_city_code' => null,
            'destination_address_snapshot' => [
                'city_municipality' => 'Destination City',
                'province' => 'Laguna',
                'psgc_city_code' => $destinationCityCode,
            ],
        ]);

        return $shipment->fresh();
    }

    private function sortingCenter(LogisticsProfile $profile, string $name): SortingCenter
    {
        return SortingCenter::query()->create([
            'logistics_profile_id' => $profile->id,
            'address_id' => $this->address($profile->user)->id,
            'name' => $name.' '.$this->sequence,
            'code' => 'NHC-'.str_pad((string) ++$this->sequence, 5, '0', STR_PAD_LEFT),
            'status' => 'active',
        ]);
    }

    private function returnRequest(SellerOrder $sellerOrder, string $status): ReturnRequest
    {
        return ReturnRequest::query()->create([
            'return_no' => 'RET-'.str_pad((string) ++$this->sequence, 8, '0', STR_PAD_LEFT),
            'order_id' => $sellerOrder->order_id,
            'seller_order_id' => $sellerOrder->id,
            'buyer_id' => $sellerOrder->order->buyer_id,
            'store_id' => $sellerOrder->store_id,
            'request_type' => 'return_refund',
            'reason_code' => 'damaged_item',
            'status' => $status,
            'requested_amount_minor' => 10000,
            'submitted_at' => now(),
        ]);
    }

    private function pendingQuarantineItem(ReturnRequest $returnRequest): ReturnItem
    {
        $orderItem = OrderItem::query()->create([
            'seller_order_id' => $returnRequest->seller_order_id,
            'product_name' => 'Navigation Quarantine Item',
            'variant_name' => 'Standard',
            'sku' => 'NAV-QUARANTINE-'.$returnRequest->id,
            'unit_price_minor' => 10000,
            'quantity' => 1,
            'discount_minor' => 0,
            'subtotal_minor' => 10000,
        ]);

        return ReturnItem::query()->create([
            'return_request_id' => $returnRequest->id,
            'order_item_id' => $orderItem->id,
            'quantity' => 1,
            'condition_code' => 'damaged',
            'resolution' => 'quarantine',
            'quarantine_review_status' => 'pending',
        ]);
    }

    private function campaign(string $name, array $overrides = []): PlatformPromotion
    {
        return PlatformPromotion::query()->create(array_merge([
            'name' => $name.' '.$this->sequence,
            'kind' => PlatformPromotion::KIND_SELLER_CAMPAIGN,
            'campaign_type' => PlatformPromotion::CAMPAIGN_MONTHLY_DEAL,
            'funding_type' => PlatformPromotion::FUNDING_SELLER,
            'discount_type' => PlatformPromotion::DISCOUNT_FIXED,
            'status' => PlatformPromotion::STATUS_OPEN,
            'minimum_spend_minor' => 0,
            'starts_on' => now('Asia/Manila')->toDateString(),
            'starts_time' => '00:00:00',
            'ends_on' => now('Asia/Manila')->addDays(8)->toDateString(),
            'ends_time' => '23:59:59',
            'join_deadline' => now('Asia/Manila')->addDays(2)->toDateString(),
            'requires_approval' => true,
            'is_claimable' => false,
            'is_public' => true,
        ], $overrides));
    }

    private function logisticsProfile(User $user): LogisticsProfile
    {
        return LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => 'Provider '.$user->id,
            'display_name' => 'Provider '.$user->id,
            'contact_phone' => '+639171234567',
            'status' => 'active',
        ]);
    }

    private function riderProfile(User $user, LogisticsProfile $provider, string $workType): RiderProfile
    {
        return RiderProfile::query()->create([
            'user_id' => $user->id,
            'logistics_profile_id' => $provider->id,
            'vehicle_type' => $workType === 'courier' ? 'Van' : 'Motorcycle',
            'work_type' => $workType,
            'verification_status' => 'approved',
        ]);
    }

    private function vehicleChangeRequest(RiderProfile $rider): RiderVehicleChangeRequest
    {
        return RiderVehicleChangeRequest::query()->create([
            'rider_profile_id' => $rider->id,
            'requested_work_type' => 'courier',
            'vehicle_type' => 'Van',
            'plate_number' => 'ABC-'.str_pad((string) ++$this->sequence, 4, '0', STR_PAD_LEFT),
            'or_cr_path' => 'rider-documents/or.png',
            'or_cr_original_name' => 'or.png',
            'or_cr_mime_type' => 'image/png',
            'or_cr_size_bytes' => 100,
            'driver_license_path' => 'rider-documents/license.png',
            'driver_license_original_name' => 'license.png',
            'driver_license_mime_type' => 'image/png',
            'driver_license_size_bytes' => 100,
            'status' => 'pending',
        ]);
    }

    private function address(User $user): Address
    {
        return Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Test address',
            'recipient_name' => $user->name,
            'phone' => '+639171234567',
            'street' => '10 Market Street',
            'barangay' => 'Balibago',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
        ]);
    }

    private function application(User $user, Role $role, string $status): AccountApplication
    {
        return AccountApplication::query()->create([
            'application_no' => 'APP-'.str_pad((string) ++$this->sequence, 8, '0', STR_PAD_LEFT),
            'user_id' => $user->id,
            'requested_role_id' => $role->id,
            'status' => $status,
        ]);
    }
}

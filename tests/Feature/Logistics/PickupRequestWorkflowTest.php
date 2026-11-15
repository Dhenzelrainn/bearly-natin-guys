<?php

namespace Tests\Feature\Logistics;

use App\Enums\AccountStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\AuditLog;
use App\Models\LogisticsLocation;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\PickupAssignment;
use App\Models\PickupRequest;
use App\Models\RiderLocation;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use App\Notifications\WorkflowNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PickupRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_logistics_lists_only_own_pickups_with_real_fulfillment_data(): void
    {
        $provider = $this->makeProvider('A');
        $foreignProvider = $this->makeProvider('B');
        $seller = $this->makeSeller('OWN');
        $foreignSeller = $this->makeSeller('FOREIGN');

        $own = $this->makePickup(
            $provider,
            $seller,
            'OWN',
            'requested',
            10000,
        );
        $own['sellerOrder']->items()->create([
            'product_name' => 'Pickup Preview Product',
            'variant_name' => 'Blue / Large',
            'sku' => 'PICKUP-PREVIEW-001',
            'unit_price_minor' => 18000,
            'quantity' => 1,
            'discount_minor' => 0,
            'subtotal_minor' => 18000,
            'product_image_disk' => 'public',
            'product_image_path' => 'seller-products/pickup-preview.png',
        ]);
        $own['sellerOrder']->items()->create([
            'product_name' => 'Pickup Product Without Image',
            'variant_name' => 'Standard',
            'sku' => 'PICKUP-PREVIEW-002',
            'unit_price_minor' => 5000,
            'quantity' => 1,
            'discount_minor' => 0,
            'subtotal_minor' => 5000,
        ]);
        $foreign = $this->makePickup(
            $foreignProvider,
            $foreignSeller,
            'FOREIGN'
        );

        $response = $this
            ->actingAs($provider['user'])
            ->get(route('logistics.pickups.index'));

        $response
            ->assertOk()
            ->assertSee($own['pickup']->pickup_no)
            ->assertSee($seller['store']->name)
            ->assertSee('Pickup window:')
            ->assertSee('Seller and pickup location')
            ->assertSee('Pickup details')
            ->assertSee('Requested pickup date')
            ->assertSee('Preferred time window')
            ->assertSee('Request activity')
            ->assertSee('Request submitted')
            ->assertSee('Total weight')
            ->assertSee('1.250 kg')
            ->assertSee('Pickup contact')
            ->assertSee('Seller operating hours')
            ->assertSee('Parcel and references')
            ->assertSee('pickup-products-cell', false)
            ->assertSee('pickup-manifest-products-panel', false)
            ->assertSee('pickup-manifest-reference-panel', false)
            ->assertSee('Shipment products')
            ->assertSee('References')
            ->assertDontSee('pickup-manifest-products-row', false)
            ->assertSee('Pickup Preview Product')
            ->assertSee('Pickup Product Without Image')
            ->assertSee('No image')
            ->assertSee('seller-products/pickup-preview.png', false)
            ->assertSee('Seller Order No.')
            ->assertSee('Shipment ID')
            ->assertSee('Waybill / Tracking No.')
            ->assertSee('Parcel ID')
            ->assertSee('Piece No.')
            ->assertSee('shipping-label number used for scanning')
            ->assertSee('View on Map')
            ->assertSee('Status and COD')
            ->assertSee('₱100.00')
            ->assertSee('Monday–Friday, 9:00 AM–5:00 PM')
            ->assertSee($own['parcel']->parcel_no)
            ->assertSee($own['waybill']->waybill_no)
            ->assertSee($own['shipment']->shipment_no)
            ->assertSee($own['sellerOrder']->seller_order_no)
            ->assertSee('24.00 × 16.00 × 8.00 cm')
            ->assertDontSee('Request overview')
            ->assertSee('data-pickup-rejection-form hidden', false)
            ->assertSee('data-pickup-reject-toggle', false)
            ->assertDontSee($foreign['pickup']->pickup_no)
            ->assertDontSee($foreignSeller['store']->name)
            ->assertDontSee('PU-24091');
    }

    public function test_owned_pickup_does_not_expose_or_mutate_foreign_provider_parcel(): void
    {
        $provider = $this->makeProvider('A');
        $foreignProvider = $this->makeProvider('B');
        $seller = $this->makeSeller('MIXED');
        $foreignSeller = $this->makeSeller('HIDDEN');
        $own = $this->makePickup($provider, $seller, 'MIXED', 'verified');
        $foreign = $this->makePickup(
            $foreignProvider,
            $foreignSeller,
            'HIDDEN'
        );

        $own['pickup']->parcels()->attach(
            $foreign['parcel']->id
        );

        $response = $this
            ->actingAs($provider['user'])
            ->get(route('logistics.pickups.index'));

        $response
            ->assertOk()
            ->assertSee($own['parcel']->parcel_no)
            ->assertDontSee($foreign['parcel']->parcel_no)
            ->assertDontSee($foreign['shipment']->shipment_no);

        $rider = $this->makeRider($provider, 'MIXED');

        $this
            ->actingAs($provider['user'])
            ->post(
                route(
                    'logistics.pickups.assign',
                    $own['pickup']
                ),
                ['rider_profile_id' => $rider->id]
            )
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ShipmentStatus::PickupAssigned->value,
            $own['shipment']->fresh()->status
        );
        $this->assertSame(
            ShipmentStatus::ReadyForPickup->value,
            $foreign['shipment']->fresh()->status
        );
    }

    public function test_logistics_can_verify_own_requested_pickup(): void
    {
        $provider = $this->makeProvider('VERIFY');
        $seller = $this->makeSeller('VERIFY');
        $pickup = $this->makePickup(
            $provider,
            $seller,
            'VERIFY'
        )['pickup'];

        $this
            ->actingAs($provider['user'])
            ->post(route('logistics.pickups.verify', $pickup))
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'success',
                "{$pickup->pickup_no} was verified."
            );

        $pickup->refresh();

        $this->assertSame('verified', $pickup->status);
        $this->assertSame(
            $provider['user']->id,
            $pickup->verified_by
        );
        $this->assertNotNull($pickup->verified_at);

        $this->actingAs($provider['user'])
            ->get(route('logistics.pickups.index'))
            ->assertOk()
            ->assertSee('Request verified')
            ->assertSee($provider['user']->name)
            ->assertSee($pickup->verified_at->format('M j, Y'));
    }

    public function test_logistics_cannot_view_or_change_foreign_pickup(): void
    {
        $provider = $this->makeProvider('OWNER');
        $foreignProvider = $this->makeProvider('FOREIGN');
        $seller = $this->makeSeller('FOREIGN-ACTION');
        $pickup = $this->makePickup(
            $foreignProvider,
            $seller,
            'FOREIGN-ACTION'
        )['pickup'];

        $this
            ->actingAs($provider['user'])
            ->post(route('logistics.pickups.verify', $pickup))
            ->assertNotFound();

        $this
            ->actingAs($provider['user'])
            ->patch(
                route('logistics.pickups.cancel', $pickup),
                ['cancellation_reason' => 'Not ours.']
            )
            ->assertNotFound();

        $this->assertSame(
            'requested',
            $pickup->fresh()->status
        );
    }

    public function test_pickup_status_transitions_are_enforced(): void
    {
        $provider = $this->makeProvider('TRANSITION');
        $seller = $this->makeSeller('TRANSITION');
        $pickup = $this->makePickup(
            $provider,
            $seller,
            'TRANSITION',
            'completed'
        )['pickup'];

        $this
            ->actingAs($provider['user'])
            ->post(route('logistics.pickups.verify', $pickup))
            ->assertStatus(409);

        $this
            ->actingAs($provider['user'])
            ->patch(
                route('logistics.pickups.cancel', $pickup),
                ['cancellation_reason' => 'Too late.']
            )
            ->assertStatus(409);

        $this->assertSame(
            'completed',
            $pickup->fresh()->status
        );
    }

    public function test_rejecting_pickup_requires_and_persists_reason(): void
    {
        $provider = $this->makeProvider('REJECT');
        $seller = $this->makeSeller('REJECT');
        $pickup = $this->makePickup(
            $provider,
            $seller,
            'REJECT'
        )['pickup'];

        $this->actingAs($provider['user'])
            ->get(route('logistics.pickups.index'))
            ->assertOk()
            ->assertSee('data-pickup-reject-open', false)
            ->assertSee('>Reject</button>', false);

        $this
            ->actingAs($provider['user'])
            ->patch(route('logistics.pickups.cancel', $pickup))
            ->assertSessionHasErrors('cancellation_reason');

        $this
            ->actingAs($provider['user'])
            ->patch(
                route('logistics.pickups.cancel', $pickup),
                ['cancellation_reason' => 'Parcel count mismatch.']
            )
            ->assertSessionHasNoErrors();

        $pickup->refresh();

        $this->assertSame('cancelled', $pickup->status);
        $this->assertSame(
            'Parcel count mismatch.',
            $pickup->cancellation_reason
        );
        $this->assertNotNull($pickup->cancelled_at);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $provider['user']->id,
            'action' => 'pickup_request.rejected',
            'module' => 'Logistics',
            'auditable_id' => $pickup->id,
        ]);

        $this->actingAs($provider['user'])
            ->get(route('logistics.pickups.index'))
            ->assertOk()
            ->assertSee('Request rejected')
            ->assertSee('Parcel count mismatch.')
            ->assertDontSee('data-pickup-rejection-form', false)
            ->assertDontSee('data-pickup-reject-toggle', false);
    }

    public function test_verified_pickup_can_be_assigned_to_owned_available_rider(): void
    {
        $provider = $this->makeProvider('ASSIGN');
        $seller = $this->makeSeller('ASSIGN');
        $fulfillment = $this->makePickup(
            $provider,
            $seller,
            'ASSIGN',
            'verified'
        );
        $rider = $this->makeRider($provider, 'ASSIGN');

        Notification::fake();

        $this
            ->actingAs($provider['user'])
            ->post(
                route(
                    'logistics.pickups.assign',
                    $fulfillment['pickup']
                ),
                [
                    'rider_profile_id' => $rider->id,
                    'notes' => 'Use the seller loading bay.',
                ]
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('pickup_assignments', [
            'pickup_request_id' => $fulfillment['pickup']->id,
            'rider_profile_id' => $rider->id,
            'status' => 'assigned',
            'assigned_by' => $provider['user']->id,
            'notes' => 'Use the seller loading bay.',
        ]);

        $this->assertSame(
            'scheduled',
            $fulfillment['pickup']->fresh()->status
        );
        $this->assertSame(
            ShipmentStatus::PickupAssigned->value,
            $fulfillment['shipment']->fresh()->status
        );
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $fulfillment['shipment']->id,
            'event_type' => 'pickup_assigned',
            'from_status' => ShipmentStatus::ReadyForPickup->value,
            'to_status' => ShipmentStatus::PickupAssigned->value,
            'actor_user_id' => $provider['user']->id,
            'source' => 'logistics',
        ]);

        Notification::assertSentTo(
            $rider->user,
            WorkflowNotification::class,
            fn (WorkflowNotification $notification): bool => $notification->eventKey() === 'rider_pickup_assigned'
                && $notification->meta()['pickup_request_id'] === $fulfillment['pickup']->id,
        );

        $this->actingAs($provider['user'])
            ->get(route('logistics.pickups.index'))
            ->assertOk()
            ->assertSee('Rider assigned')
            ->assertSee($rider->user->name)
            ->assertSee('Use the seller loading bay.');
    }

    public function test_logistics_can_replace_an_accepted_unpicked_rider_with_an_audited_same_provider_rider(): void
    {
        $provider = $this->makeProvider('REPLACE');
        $seller = $this->makeSeller('REPLACE');
        $fulfillment = $this->makePickup($provider, $seller, 'REPLACE', 'verified');
        $oldRider = $this->makeRider($provider, 'REPLACE-OLD');
        $replacementRider = $this->makeRider($provider, 'REPLACE-NEW');

        Notification::fake();

        $this->actingAs($provider['user'])
            ->post(route('logistics.pickups.assign', $fulfillment['pickup']), [
                'rider_profile_id' => $oldRider->id,
            ])
            ->assertRedirect();

        $oldAssignment = PickupAssignment::query()
            ->where('pickup_request_id', $fulfillment['pickup']->id)
            ->firstOrFail();
        $oldAssignment->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        $this->actingAs($provider['user'])
            ->patch(route('logistics.pickups.replace-rider', $fulfillment['pickup']), [
                'rider_profile_id' => $replacementRider->id,
                'reason' => 'The assigned Rider cannot reach this pickup area.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $oldAssignment->refresh();
        $replacementAssignment = PickupAssignment::query()
            ->where('pickup_request_id', $fulfillment['pickup']->id)
            ->where('rider_profile_id', $replacementRider->id)
            ->firstOrFail();

        $this->assertSame('cancelled', $oldAssignment->status);
        $this->assertNotNull($oldAssignment->cancelled_at);
        $this->assertStringContainsString('The assigned Rider cannot reach this pickup area.', $oldAssignment->notes);
        $this->assertSame('assigned', $replacementAssignment->status);
        $this->assertSame($provider['user']->id, $replacementAssignment->assigned_by);
        $this->assertSame('scheduled', $fulfillment['pickup']->fresh()->status);
        $this->assertSame(
            ShipmentStatus::PickupAssigned->value,
            $fulfillment['shipment']->fresh()->status,
        );
        $this->assertSame(
            $replacementAssignment->id,
            $fulfillment['pickup']->fresh()->activeAssignment?->id,
        );

        $event = ShipmentEvent::query()
            ->where('shipment_id', $fulfillment['shipment']->id)
            ->where('event_type', 'pickup_rider_reassigned')
            ->firstOrFail();
        $this->assertSame($provider['user']->id, $event->actor_user_id);
        $this->assertSame(ShipmentStatus::PickupAssigned->value, $event->from_status);
        $this->assertSame(ShipmentStatus::PickupAssigned->value, $event->to_status);
        $this->assertSame($oldAssignment->id, $event->metadata['previous_pickup_assignment_id']);
        $this->assertSame($replacementAssignment->id, $event->metadata['pickup_assignment_id']);
        $this->assertSame('The assigned Rider cannot reach this pickup area.', $event->metadata['reason']);

        $audit = AuditLog::query()
            ->where('action', 'pickup_assignment.replaced')
            ->where('auditable_id', $fulfillment['pickup']->id)
            ->firstOrFail();
        $this->assertSame($provider['user']->id, $audit->actor_user_id);
        $this->assertSame($oldRider->id, $audit->old_values['rider_profile_id']);
        $this->assertSame($replacementRider->id, $audit->new_values['rider_profile_id']);
        $this->assertSame('The assigned Rider cannot reach this pickup area.', $audit->new_values['reason']);

        Notification::assertSentTo(
            $oldRider->user,
            WorkflowNotification::class,
            fn (WorkflowNotification $notification): bool => $notification->eventKey() === 'rider_pickup_assignment_cancelled'
                && $notification->meta()['pickup_assignment_id'] === $oldAssignment->id,
        );
        Notification::assertSentTo(
            $replacementRider->user,
            WorkflowNotification::class,
            fn (WorkflowNotification $notification): bool => $notification->eventKey() === 'rider_pickup_assigned'
                && $notification->meta()['pickup_assignment_id'] === $replacementAssignment->id,
        );
        Notification::assertSentTo(
            $seller['user'],
            WorkflowNotification::class,
            fn (WorkflowNotification $notification): bool => $notification->eventKey() === 'seller_pickup_updates'
                && str_contains($notification->body(), $replacementRider->user->name),
        );

        $this->actingAs($provider['user'])
            ->get(route('logistics.pickups.index'))
            ->assertOk()
            ->assertSee('Replace Rider')
            ->assertSee(route('logistics.pickups.replace-rider', $fulfillment['pickup']), false)
            ->assertSee('Reason for replacement');
    }

    public function test_pickup_replacement_rejects_foreign_unavailable_and_already_picked_up_work(): void
    {
        $provider = $this->makeProvider('REPLACE-GUARD');
        $foreignProvider = $this->makeProvider('REPLACE-FOREIGN');
        $seller = $this->makeSeller('REPLACE-GUARD');
        $fulfillment = $this->makePickup($provider, $seller, 'REPLACE-GUARD', 'verified');
        $oldRider = $this->makeRider($provider, 'REPLACE-GUARD-OLD');
        $offlineRider = $this->makeRider($provider, 'REPLACE-GUARD-OFFLINE', 'offline');
        $foreignRider = $this->makeRider($foreignProvider, 'REPLACE-GUARD-FOREIGN');

        $this->actingAs($provider['user'])
            ->post(route('logistics.pickups.assign', $fulfillment['pickup']), [
                'rider_profile_id' => $oldRider->id,
            ])
            ->assertRedirect();

        $replacementRoute = route('logistics.pickups.replace-rider', $fulfillment['pickup']);
        $this->actingAs($provider['user'])
            ->from(route('logistics.pickups.index'))
            ->patch($replacementRoute, [
                'rider_profile_id' => $offlineRider->id,
                'reason' => ['not', 'a', 'string'],
            ])
            ->assertRedirect(route('logistics.pickups.index'))
            ->assertSessionHasErrors('reason');

        $this->actingAs($provider['user'])
            ->from(route('logistics.pickups.index'))
            ->patch($replacementRoute, [
                'rider_profile_id' => $offlineRider->id,
                'reason' => '     ',
            ])
            ->assertRedirect(route('logistics.pickups.index'))
            ->assertSessionHasErrors('reason');

        $this->actingAs($provider['user'])
            ->patch($replacementRoute, [
                'rider_profile_id' => $foreignRider->id,
                'reason' => 'Coverage change requested.',
            ])
            ->assertNotFound();

        $this->actingAs($provider['user'])
            ->patch($replacementRoute, [
                'rider_profile_id' => $offlineRider->id,
                'reason' => 'Coverage change requested.',
            ])
            ->assertStatus(409);

        $this->assertSame('assigned', $fulfillment['pickup']->fresh()->activeAssignment?->status);
        $this->assertDatabaseCount('pickup_assignments', 1);

        $fulfillment['pickup']->activeAssignment()->firstOrFail()->update([
            'status' => 'picked_up',
            'picked_up_at' => now(),
        ]);

        $this->actingAs($provider['user'])
            ->patch($replacementRoute, [
                'rider_profile_id' => $offlineRider->id,
                'reason' => 'Coverage change requested.',
            ])
            ->assertStatus(409);

        $this->assertSame('picked_up', $fulfillment['pickup']->activeAssignment()->firstOrFail()->status);
        $this->assertDatabaseCount('pickup_assignments', 1);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'pickup_assignment.replaced',
            'auditable_id' => $fulfillment['pickup']->id,
        ]);
    }

    public function test_pickup_cannot_be_assigned_to_foreign_or_unavailable_rider(): void
    {
        $provider = $this->makeProvider('A');
        $foreignProvider = $this->makeProvider('B');
        $seller = $this->makeSeller('RIDER-SCOPE');
        $pickup = $this->makePickup(
            $provider,
            $seller,
            'RIDER-SCOPE',
            'verified'
        )['pickup'];
        $foreignRider = $this->makeRider(
            $foreignProvider,
            'FOREIGN'
        );
        $unavailableRider = $this->makeRider(
            $provider,
            'OFFLINE',
            'offline'
        );

        $this
            ->actingAs($provider['user'])
            ->post(
                route('logistics.pickups.assign', $pickup),
                ['rider_profile_id' => $foreignRider->id]
            )
            ->assertNotFound();

        $this
            ->actingAs($provider['user'])
            ->post(
                route('logistics.pickups.assign', $pickup),
                ['rider_profile_id' => $unavailableRider->id]
            )
            ->assertNotFound();

        $this->assertSame(
            'verified',
            $pickup->fresh()->status
        );
        $this->assertDatabaseCount('pickup_assignments', 0);
    }

    public function test_pickup_specialization_and_same_day_schedule_conflicts_are_enforced(): void
    {
        $provider = $this->makeProvider('SPECIALIZATION');
        $seller = $this->makeSeller('SPECIALIZATION');
        $existing = $this->makePickup($provider, $seller, 'SPECIALIZATION-EXISTING', 'scheduled');
        $overlap = $this->makePickup($provider, $seller, 'SPECIALIZATION-OVERLAP', 'verified');
        $adjacent = $this->makePickup($provider, $seller, 'SPECIALIZATION-ADJACENT', 'verified');
        $start = Carbon::parse(today()->toDateString().' 09:00:00');

        $existing['pickup']->update([
            'window_start' => $start,
            'window_end' => $start->copy()->addHour(),
        ]);
        $overlap['pickup']->update([
            'window_start' => $start->copy()->addMinutes(30),
            'window_end' => $start->copy()->addHours(2),
        ]);
        $adjacent['pickup']->update([
            'window_start' => $start->copy()->addHour(),
            'window_end' => $start->copy()->addHours(2),
        ]);

        $deliveryOnly = $this->makeRider($provider, 'SPECIALIZATION-DELIVERY');
        $deliveryOnly->update(['specialization' => 'delivery_only']);
        $pickupOnly = $this->makeRider($provider, 'SPECIALIZATION-PICKUP');
        $pickupOnly->update(['specialization' => 'pickup_only']);

        PickupAssignment::query()->create([
            'pickup_request_id' => $existing['pickup']->id,
            'rider_profile_id' => $pickupOnly->id,
            'status' => 'assigned',
            'assigned_by' => $provider['user']->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($provider['user'])
            ->post(route('logistics.pickups.assign', $overlap['pickup']), [
                'rider_profile_id' => $deliveryOnly->id,
            ])
            ->assertNotFound();

        $this->actingAs($provider['user'])
            ->post(route('logistics.pickups.assign', $overlap['pickup']), [
                'rider_profile_id' => $pickupOnly->id,
            ])
            ->assertStatus(409);

        $this->actingAs($provider['user'])
            ->post(route('logistics.pickups.assign', $adjacent['pickup']), [
                'rider_profile_id' => $pickupOnly->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('pickup_assignments', [
            'pickup_request_id' => $adjacent['pickup']->id,
            'rider_profile_id' => $pickupOnly->id,
            'status' => 'assigned',
        ]);
        $this->assertSame('verified', $overlap['pickup']->fresh()->status);
    }

    public function test_pickup_ui_wires_status_actions_metrics_and_owned_available_riders(): void
    {
        $provider = $this->makeProvider('UI');
        $foreignProvider = $this->makeProvider('UI-FOREIGN');
        $seller = $this->makeSeller('UI');
        $pending = $this->makePickup(
            $provider,
            $seller,
            'UI-PENDING'
        )['pickup'];
        $verified = $this->makePickup(
            $provider,
            $seller,
            'UI-VERIFIED',
            'verified'
        )['pickup'];
        $availableRider = $this->makeRider(
            $provider,
            'UI-AVAILABLE'
        );
        $foreignRider = $this->makeRider(
            $foreignProvider,
            'UI-HIDDEN'
        );

        $response = $this
            ->actingAs($provider['user'])
            ->get(route('logistics.pickups.index'));

        $response
            ->assertOk()
            ->assertViewHas(
                'pickupMetrics',
                fn (array $metrics) => $metrics['awaiting_review'] === 1
                    && $metrics['awaiting_assignment'] === 1
            )
            ->assertSee(
                route('logistics.pickups.verify', $pending),
                false
            )
            ->assertSee(
                route('logistics.pickups.cancel', $pending),
                false
            )
            ->assertSee(
                route('logistics.pickups.assign', $verified),
                false
            )
            ->assertSee($availableRider->user->name)
            ->assertSee('Needs Rider assignment')
            ->assertSee('Assign Rider')
            ->assertSee('Assign Rider &amp; schedule pickup', false)
            ->assertSee('Live pickup map')
            ->assertSee(
                route('logistics.pickups.locations', $verified),
                false
            )
            ->assertDontSee($foreignRider->user->name)
            ->assertSee('Pickup requests are submitted by Sellers.')
            ->assertDontSee('data-preview-form="manualPickup"', false)
            ->assertDontSee('data-set-status=', false);
    }

    public function test_logistics_pickup_recommendation_uses_fresh_gps_before_manual_confirmation(): void
    {
        $provider = $this->makeProvider('GPS-RECOMMEND');
        $seller = $this->makeSeller('GPS-RECOMMEND');
        $seller['address']->update([
            'latitude' => 14.5995,
            'longitude' => 120.9842,
        ]);
        $pickup = $this->makePickup(
            $provider,
            $seller,
            'GPS-RECOMMEND',
            'verified',
        )['pickup'];
        $near = $this->makeRider($provider, 'GPS-NEAR');
        $far = $this->makeRider($provider, 'GPS-FAR');

        RiderLocation::query()->create([
            'rider_profile_id' => $near->id,
            'latitude' => 14.6000,
            'longitude' => 120.9842,
            'accuracy_meters' => 100,
            'last_seen_at' => now(),
        ]);
        RiderLocation::query()->create([
            'rider_profile_id' => $far->id,
            'latitude' => 14.7000,
            'longitude' => 120.9842,
            'accuracy_meters' => 10,
            'last_seen_at' => now(),
        ]);

        $response = $this
            ->actingAs($provider['user'])
            ->get(route('logistics.pickups.index'));

        $response
            ->assertOk()
            ->assertSee('Based on fresh accurate GPS and straight-line distance')
            ->assertSee('Fallback Rider suggestion: '.$near->user->name)
            ->assertViewHas(
                'pickups',
                function (array $pickups) use ($pickup, $near, $far): bool {
                    $row = collect($pickups)->firstWhere(
                        'database_id',
                        $pickup->id,
                    );
                    $recommendations = collect($row['rider_recommendations'] ?? []);

                    return $recommendations->first()['id'] === $near->id
                        && str_starts_with(
                            $recommendations->first()['recommendation_reason'],
                            'Fallback · nearest available Rider',
                        )
                        && $recommendations->first()['distance_km']
                            < $recommendations->last()['distance_km']
                        && $recommendations->pluck('id')->all() === [
                            $near->id,
                            $far->id,
                        ];
                },
            );

        $this->assertDatabaseCount('pickup_assignments', 0);
    }

    public function test_available_service_area_rider_remains_the_primary_recommendation_ahead_of_a_closer_out_of_zone_rider(): void
    {
        $provider = $this->makeProvider('ZONE-FIRST');
        $seller = $this->makeSeller('ZONE-FIRST');
        $seller['address']->update([
            'latitude' => 14.5995,
            'longitude' => 120.9842,
        ]);
        $pickup = $this->makePickup(
            $provider,
            $seller,
            'ZONE-FIRST',
            'verified',
        )['pickup'];
        $serviceAreaRider = $this->makeRider($provider, 'ZONE-FIRST-MATCH');
        $closerOutsideRider = $this->makeRider($provider, 'ZONE-FIRST-NEAR');

        $centerAddress = Address::query()->create([
            'user_id' => $provider['user']->id,
            'label' => 'Sorting Center',
            'recipient_name' => $provider['user']->name,
            'phone' => '09173333333',
            'house_number' => '1',
            'street' => 'Service Area Road',
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'postal_code' => '4000',
        ]);
        $center = SortingCenter::query()->create([
            'logistics_profile_id' => $provider['profile']->id,
            'address_id' => $centerAddress->id,
            'name' => 'Zone-first Recommendation Center',
            'code' => 'ZFRC-'.strtoupper(substr(sha1((string) $provider['profile']->id), 0, 10)),
            'contact_phone' => '09173333333',
            'operating_hours' => '08:00-18:00',
            'daily_capacity' => 500,
            'status' => 'active',
        ]);
        $zone = SortingZone::query()->create([
            'sorting_center_id' => $center->id,
            'code' => 'ZFR-'.strtoupper(substr(sha1((string) $center->id), 0, 8)),
            'name' => 'San Rafael Pickup Zone',
            'destination_rules' => [
                'province' => 'Laguna',
                'city_municipality' => 'San Pablo City',
                'barangays' => ['San Rafael'],
            ],
            'status' => 'active',
        ]);
        $serviceAreaRider->update([
            'home_sorting_center_id' => $center->id,
            'current_zone_id' => $zone->id,
        ]);

        RiderLocation::query()->create([
            'rider_profile_id' => $serviceAreaRider->id,
            'latitude' => 14.7000,
            'longitude' => 120.9842,
            'accuracy_meters' => 10,
            'last_seen_at' => now(),
        ]);
        RiderLocation::query()->create([
            'rider_profile_id' => $closerOutsideRider->id,
            'latitude' => 14.5996,
            'longitude' => 120.9842,
            'accuracy_meters' => 10,
            'last_seen_at' => now(),
        ]);

        $this->actingAs($provider['user'])
            ->get(route('logistics.pickups.index'))
            ->assertOk()
            ->assertSee('Service-area recommendation: '.$serviceAreaRider->user->name)
            ->assertViewHas(
                'pickups',
                function (array $pickups) use ($pickup, $serviceAreaRider, $closerOutsideRider): bool {
                    $row = collect($pickups)->firstWhere('database_id', $pickup->id);
                    $recommendations = collect($row['rider_recommendations'] ?? []);

                    return $recommendations->first()['id'] === $serviceAreaRider->id
                        && $recommendations->first()['area_match'] === true
                        && $recommendations->first()['distance_km']
                            > $recommendations->last()['distance_km']
                        && $recommendations->last()['id'] === $closerOutsideRider->id;
                },
            );

        $this->assertDatabaseCount('pickup_assignments', 0);
    }

    public function test_pickup_map_lists_all_schedulable_provider_riders_but_only_exposes_usable_coordinates(): void
    {
        $provider = $this->makeProvider('MAP');
        $foreignProvider = $this->makeProvider('MAP-FOREIGN');
        $seller = $this->makeSeller('MAP');
        $seller['address']->update([
            'latitude' => 14.5995,
            'longitude' => 120.9842,
        ]);
        $pickup = $this->makePickup(
            $provider,
            $seller,
            'MAP',
            'verified',
        )['pickup'];
        $legacyServiceAddress = Address::query()->create([
            'user_id' => $provider['user']->id,
            'label' => 'Sorting Center',
            'recipient_name' => $provider['user']->name,
            'phone' => '09173333333',
            'house_number' => '1',
            'street' => 'Logistics Facility Road',
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'postal_code' => '4000',
            'latitude' => 14.6100,
            'longitude' => 120.9900,
        ]);
        $centerAddress = Address::query()->create([
            'user_id' => null,
            'label' => 'Sorting Center',
            'recipient_name' => $provider['user']->name,
            'phone' => '09173333333',
            'house_number' => '2',
            'street' => 'Active Sorting Center Road',
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'postal_code' => '4000',
            'latitude' => 14.6200,
            'longitude' => 120.9800,
        ]);
        SortingCenter::query()->create([
            'logistics_profile_id' => $provider['profile']->id,
            'address_id' => $centerAddress->id,
            'name' => 'Active Pickup Map Center',
            'code' => 'SC-MAP-'.strtoupper(substr(sha1((string) $provider['profile']->id), 0, 10)),
            'contact_phone' => '09173333333',
            'operating_hours' => '08:00-18:00',
            'daily_capacity' => 500,
            'status' => 'active',
        ]);
        $freshRider = $this->makeRider($provider, 'MAP-FRESH');
        $lowAccuracyRider = $this->makeRider($provider, 'MAP-LOW-ACCURACY');
        $staleRider = $this->makeRider($provider, 'MAP-STALE');
        $missingLocationRider = $this->makeRider($provider, 'MAP-NO-LOCATION');
        $conflictedRider = $this->makeRider($provider, 'MAP-CONFLICTED');
        $foreignRider = $this->makeRider($foreignProvider, 'MAP-FOREIGN');
        $overlappingPickup = $this->makePickup($provider, $seller, 'MAP-OVERLAPPING', 'scheduled')['pickup'];

        PickupAssignment::query()->create([
            'pickup_request_id' => $overlappingPickup->id,
            'rider_profile_id' => $conflictedRider->id,
            'status' => 'assigned',
            'assigned_by' => $provider['user']->id,
            'assigned_at' => now(),
        ]);

        RiderLocation::query()->create([
            'rider_profile_id' => $freshRider->id,
            'latitude' => 14.6000,
            'longitude' => 120.9842,
            'accuracy_meters' => 8,
            'last_seen_at' => now(),
        ]);
        RiderLocation::query()->create([
            'rider_profile_id' => $lowAccuracyRider->id,
            'latitude' => 14.6000,
            'longitude' => 120.9842,
            'accuracy_meters' => 50000,
            'last_seen_at' => now(),
        ]);
        RiderLocation::query()->create([
            'rider_profile_id' => $staleRider->id,
            'latitude' => 14.6010,
            'longitude' => 120.9842,
            'accuracy_meters' => 12,
            'last_seen_at' => now()->subSeconds(181),
        ]);
        RiderLocation::query()->create([
            'rider_profile_id' => $foreignRider->id,
            'latitude' => 14.6020,
            'longitude' => 120.9842,
            'accuracy_meters' => 10,
            'last_seen_at' => now(),
        ]);
        $liveLogisticsLocation = LogisticsLocation::query()->create([
            'logistics_profile_id' => $provider['profile']->id,
            'latitude' => 14.6250,
            'longitude' => 120.9750,
            'accuracy_meters' => 10000,
            'last_seen_at' => now(),
        ]);
        LogisticsLocation::query()->create([
            'logistics_profile_id' => $foreignProvider['profile']->id,
            'latitude' => 15.6250,
            'longitude' => 121.9750,
            'accuracy_meters' => 10,
            'last_seen_at' => now(),
        ]);

        $response = $this
            ->actingAs($provider['user'])
            ->get(route('logistics.pickups.locations', $pickup));

        $response
            ->assertOk()
            ->assertJsonPath('data.pickup.latitude', 14.5995)
            ->assertJsonPath('data.pickup.longitude', 120.9842)
            ->assertJsonPath('data.facility.latitude', 14.62)
            ->assertJsonPath('data.facility.longitude', 120.98)
            ->assertJsonPath('data.logistics_location.latitude', 14.625)
            ->assertJsonPath('data.logistics_location.accuracy_meters', 10000)
            ->assertJsonPath('data.stale_after_seconds', 180)
            ->assertJsonPath('data.max_usable_accuracy_meters', 100)
            ->assertJsonCount(4, 'data.riders');

        $mapRiders = collect($response->json('data.riders'));
        $this->assertEqualsCanonicalizing(
            [$freshRider->id, $lowAccuracyRider->id, $staleRider->id, $missingLocationRider->id],
            $mapRiders->pluck('id')->all(),
        );
        $this->assertNull($mapRiders->firstWhere('id', $conflictedRider->id));
        $this->assertNull($mapRiders->firstWhere('id', $foreignRider->id));

        $freshLocation = $mapRiders->firstWhere('id', $freshRider->id);
        $this->assertSame(14.6, $freshLocation['latitude']);
        $this->assertSame('fresh', $freshLocation['location_state']);
        $this->assertSame(true, $freshLocation['location_usable']);

        $lowAccuracyLocation = $mapRiders->firstWhere('id', $lowAccuracyRider->id);
        $this->assertNull($lowAccuracyLocation['latitude']);
        $this->assertNull($lowAccuracyLocation['longitude']);
        $this->assertSame(50000.0, (float) $lowAccuracyLocation['accuracy_meters']);
        $this->assertSame('fresh', $lowAccuracyLocation['location_state']);
        $this->assertSame('low_accuracy', $lowAccuracyLocation['gps_quality']);
        $this->assertFalse($lowAccuracyLocation['location_usable']);

        $staleLocation = $mapRiders->firstWhere('id', $staleRider->id);
        $this->assertNull($staleLocation['latitude']);
        $this->assertNull($staleLocation['longitude']);
        $this->assertSame('stale', $staleLocation['location_state']);
        $this->assertFalse($staleLocation['location_usable']);

        $missingLocation = $mapRiders->firstWhere('id', $missingLocationRider->id);
        $this->assertNull($missingLocation['latitude']);
        $this->assertNull($missingLocation['longitude']);
        $this->assertSame('missing', $missingLocation['location_state']);
        $this->assertSame('unknown', $missingLocation['gps_quality']);
        $this->assertNull($missingLocation['last_seen_at']);

        $this
            ->actingAs($foreignProvider['user'])
            ->get(route('logistics.pickups.locations', $pickup))
            ->assertNotFound();

        $liveLogisticsLocation->update(['last_seen_at' => now()->subSeconds(181)]);
        $this->actingAs($provider['user'])
            ->getJson(route('logistics.pickups.locations', $pickup))
            ->assertOk()
            ->assertJsonPath('data.logistics_location', null)
            ->assertJsonPath('data.facility.latitude', 14.62);

        $centerAddress->update(['latitude' => 91]);
        $this->actingAs($provider['user'])
            ->get(route('logistics.pickups.locations', $pickup))
            ->assertOk()
            ->assertJsonPath('data.facility.latitude', null)
            ->assertJsonPath('data.facility.longitude', null);
    }

    public function test_pickup_recommendation_falls_back_to_the_riders_assigned_sorting_zone(): void
    {
        $provider = $this->makeProvider('ZONE-RECOMMEND');
        $seller = $this->makeSeller('ZONE-RECOMMEND');
        $seller['address']->update([
            'latitude' => 14.5995,
            'longitude' => 120.9842,
        ]);
        $pickup = $this->makePickup(
            $provider,
            $seller,
            'ZONE-RECOMMEND',
            'verified',
        )['pickup'];
        $zoneRider = $this->makeRider($provider, 'ZONE-MATCH');
        $lowAccuracyRider = $this->makeRider($provider, 'ZONE-LOW-ACCURACY');
        RiderLocation::query()->create([
            'rider_profile_id' => $lowAccuracyRider->id,
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'accuracy_meters' => 50000,
            'last_seen_at' => now(),
        ]);

        $centerAddress = Address::query()->create([
            'user_id' => $provider['user']->id,
            'label' => 'Sorting Center',
            'recipient_name' => $provider['user']->name,
            'phone' => '09173333333',
            'house_number' => '1',
            'street' => 'Sorting Center Road',
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'postal_code' => '4000',
        ]);
        $center = SortingCenter::query()->create([
            'logistics_profile_id' => $provider['profile']->id,
            'address_id' => $centerAddress->id,
            'name' => 'Zone Recommendation Center',
            'code' => 'ZRC-'.strtoupper(substr(uniqid(), -6)),
            'status' => 'active',
        ]);
        $zone = SortingZone::query()->create([
            'sorting_center_id' => $center->id,
            'code' => 'ZONE-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'San Rafael Zone',
            'destination_rules' => [
                'province' => 'Laguna',
                'city_municipality' => 'San Pablo City',
                'barangays' => ['San Rafael'],
            ],
            'status' => 'active',
        ]);
        $zoneRider->update([
            'home_sorting_center_id' => $center->id,
            'current_zone_id' => $zone->id,
        ]);

        $this->actingAs($provider['user'])
            ->get(route('logistics.pickups.index'))
            ->assertOk()
            ->assertViewHas(
                'pickups',
                function (array $pickups) use ($pickup, $zoneRider, $lowAccuracyRider): bool {
                    $row = collect($pickups)->firstWhere(
                        'database_id',
                        $pickup->id,
                    );
                    $recommendation = collect($row['rider_recommendations'] ?? [])
                        ->first();

                    $inaccurateRecommendation = collect($row['rider_recommendations'] ?? [])
                        ->firstWhere('id', $lowAccuracyRider->id);

                    return $recommendation['id'] === $zoneRider->id
                        && $recommendation['area_match'] === true
                        && $recommendation['distance_km'] === null
                        && $inaccurateRecommendation['location_state'] === 'fresh'
                        && $inaccurateRecommendation['gps_quality'] === 'low_accuracy'
                        && $inaccurateRecommendation['location_usable'] === false
                        && $inaccurateRecommendation['latitude'] === null
                        && $inaccurateRecommendation['distance_km'] === null;
                },
            );
    }

    public function test_dashboard_uses_owned_pending_pickup_count(): void
    {
        $provider = $this->makeProvider('DASHBOARD');
        $foreignProvider = $this->makeProvider('DASHBOARD-FOREIGN');
        $seller = $this->makeSeller('DASHBOARD');
        $foreignSeller = $this->makeSeller('DASHBOARD-FOREIGN');

        $this->makePickup(
            $provider,
            $seller,
            'DASHBOARD-PENDING'
        );
        $this->makePickup(
            $provider,
            $seller,
            'DASHBOARD-VERIFIED',
            'verified'
        );
        $this->makePickup(
            $foreignProvider,
            $foreignSeller,
            'DASHBOARD-HIDDEN'
        );

        $this
            ->actingAs($provider['user'])
            ->get(route('logistics.dashboard'))
            ->assertOk()
            ->assertSee('1 pickup request')
            ->assertDontSee('2 pickup requests');
    }

    private function makeProvider(string $suffix): array
    {
        $user = User::factory()->create([
            'name' => "Logistics {$suffix}",
            'email' => 'logistics-'.strtolower($suffix).'@example.test',
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
            'business_name' => "Logistics {$suffix}",
        ]);

        $user->roles()->syncWithoutDetaching([
            Role::where('name', UserRole::Logistics->value)
                ->value('id'),
        ]);

        $profile = LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => "Logistics {$suffix} Inc.",
            'display_name' => "Logistics {$suffix}",
            'contact_phone' => '09170000001',
            'status' => 'active',
        ]);

        return compact('user', 'profile');
    }

    private function makeSeller(string $suffix): array
    {
        $phone = $this->nextPhone();

        $user = User::factory()->create([
            'name' => "Seller {$suffix}",
            'email' => 'seller-'.strtolower($suffix).'@example.test',
            'role' => UserRole::Seller->value,
            'status' => AccountStatus::Active->value,
            'phone' => $phone,
            'contact_number' => $phone,
        ]);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Pickup',
            'recipient_name' => $user->name,
            'phone' => $phone,
            'house_number' => '12',
            'street' => "Seller {$suffix} Street",
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'postal_code' => '4000',
            'is_default_pickup' => true,
        ]);

        $profile = SellerProfile::query()->create([
            'user_id' => $user->id,
            'legal_business_name' => "Seller {$suffix} Shop",
            'pickup_address_id' => $address->id,
            'approved_at' => now(),
        ]);

        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => "Store {$suffix}",
            'slug' => 'store-'.strtolower($suffix),
            'contact_phone' => '09171111111',
            'operating_hours' => 'Monday–Friday, 9:00 AM–5:00 PM',
            'publication_status' => 'published',
            'published_at' => now(),
        ]);

        return compact(
            'user',
            'address',
            'profile',
            'store'
        );
    }

    private function nextPhone(): string
    {
        return sprintf('+63917%07d', ++$this->phoneSequence);
    }

    private function makeRider(
        array $provider,
        string $suffix,
        string $availability = 'available'
    ): RiderProfile {
        $user = User::factory()->create([
            'name' => "Rider {$suffix}",
            'email' => 'pickup-rider-'.strtolower($suffix).'@example.test',
            'role' => UserRole::Rider->value,
            'status' => AccountStatus::Active->value,
            'logistics_id' => $provider['user']->id,
        ]);

        return RiderProfile::query()->create([
            'user_id' => $user->id,
            'logistics_profile_id' => $provider['profile']->id,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => "RIDER-{$suffix}",
            'availability_status' => $availability,
            'verification_status' => 'approved',
        ]);
    }

    private function makePickup(
        array $provider,
        array $seller,
        string $suffix,
        string $status = 'requested',
        int $codAmountMinor = 0,
    ): array {
        $buyer = User::factory()->create([
            'email' => 'buyer-'.strtolower($suffix).'@example.test',
            'role' => UserRole::Buyer->value,
            'status' => AccountStatus::Active->value,
        ]);

        $order = Order::query()->create([
            'order_no' => "ORDER-{$suffix}",
            'buyer_id' => $buyer->id,
            'recipient_name' => $buyer->name,
            'recipient_phone' => '09172222222',
            'address_line' => 'Buyer Street',
            'barangay' => 'San Roque',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'postal_code' => '4000',
            'subtotal_minor' => 10000,
            'total_minor' => 10000,
            'status' => 'confirmed',
            'payment_status' => 'paid',
        ]);

        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => "SELLER-ORDER-{$suffix}",
            'order_id' => $order->id,
            'store_id' => $seller['store']->id,
            'status' => 'ready_for_pickup',
            'subtotal_minor' => 10000,
            'total_minor' => 10000,
            'ready_at' => now(),
        ]);

        $shipment = Shipment::query()->create([
            'shipment_no' => "SHIPMENT-{$suffix}",
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $provider['profile']->id,
            'origin_address_id' => $seller['address']->id,
            'purpose' => 'outbound',
            'status' => ShipmentStatus::ReadyForPickup->value,
            'cod_amount_minor' => $codAmountMinor,
        ]);

        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => "WAYBILL-{$suffix}",
            'scan_token' => hash('sha256', "scan-{$suffix}"),
            'barcode_value' => "BARCODE-{$suffix}",
            'piece_count' => 1,
            'total_weight_kg' => 1.250,
            'status' => 'printed',
            'generated_at' => now(),
            'printed_at' => now(),
            'print_count' => 1,
        ]);

        $parcel = Parcel::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_id' => $waybill->id,
            'parcel_no' => "PARCEL-{$suffix}",
            'piece_sequence' => 1,
            'weight_kg' => 1.250,
            'length_cm' => 24,
            'width_cm' => 16,
            'height_cm' => 8,
            'status' => 'ready_for_pickup',
        ]);

        $pickup = PickupRequest::query()->create([
            'pickup_no' => "PICKUP-{$suffix}",
            'store_id' => $seller['store']->id,
            'logistics_profile_id' => $provider['profile']->id,
            'pickup_address_id' => $seller['address']->id,
            'status' => $status,
            'requested_date' => today(),
            'window_start' => now()->addHour(),
            'window_end' => now()->addHours(2),
            'seller_instructions' => 'Use the loading bay.',
            'completed_at' => $status === 'completed'
                ? now()
                : null,
        ]);

        $pickup->parcels()->attach($parcel->id);

        return compact(
            'order',
            'sellerOrder',
            'shipment',
            'waybill',
            'parcel',
            'pickup'
        );
    }
}

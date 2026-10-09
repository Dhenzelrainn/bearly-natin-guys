<?php

namespace Tests\Feature\Admin;

use App\Models\Conversation;
use App\Models\Dispute;
use App\Models\DisputeEvent;
use App\Models\DisputeEvidence;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminDisputeTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithRole(
        string $role,
        string $status = 'active'
    ): User {
        $user = User::factory()->create([
            'role' => $role,
            'status' => $status,
        ]);

        $roleModel = Role::query()->firstOrCreate(
            ['name' => $role],
            ['display_name' => ucfirst($role)]
        );

        $user->roles()->syncWithoutDetaching([
            $roleModel->id,
        ]);

        return $user;
    }

    private function createAdmin(): User
    {
        return $this->createUserWithRole('admin');
    }

    private function createDispute(
        User $openedBy,
        string $status = 'open'
    ): Dispute {
        $nextNumber =
            (Dispute::query()->max('id') ?? 0) + 1;

        return Dispute::query()->create([
            'dispute_no' => 'DSP-CASE-'.str_pad(
                (string) $nextNumber,
                3,
                '0',
                STR_PAD_LEFT
            ),

            'seller_order_id' => null,

            'return_request_id' => null,

            'shipment_id' => null,

            'opened_by' => $openedBy->id,

            'subject' => 'Case update test dispute',

            'description' => 'Dispute created for participant update testing.',

            'priority' => 'normal',

            'status' => $status,

            'amount_minor' => 150000,

            'assigned_to' => null,

            'opened_at' => now()->subHour(),

            'response_due_at' => now()->addDay(),

            'resolved_at' => $status === 'resolved'
                    ? now()
                    : null,

            'closed_at' => $status === 'closed'
                    ? now()
                    : null,

            'resolution' => $status === 'resolved'
                    ? 'Previously resolved for testing.'
                    : null,
        ]);
    }

    private User $admin;

    private User $buyer;

    private Dispute $dispute;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(
            ['name' => 'admin'],
            [
                'display_name' => 'Admin',
                'description' => 'Platform administrator',
            ]
        );

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->admin->roles()->attach(
            $adminRole->id,
            [
                'assigned_at' => now(),
            ]
        );

        $this->buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $this->dispute = Dispute::create([
            'dispute_no' => 'DSP-TEST-001',
            'seller_order_id' => null,
            'return_request_id' => null,
            'shipment_id' => null,
            'opened_by' => $this->buyer->id,
            'subject' => 'Test dispute',
            'description' => 'A dispute created for Admin workflow testing.',
            'priority' => 'high',
            'status' => 'open',
            'amount_minor' => 250000,
            'assigned_to' => null,
            'opened_at' => now()->subHour(),
            'response_due_at' => now()->addDay(),
            'resolved_at' => null,
            'closed_at' => null,
            'resolution' => null,
        ]);

        $this->dispute->participants()->attach(
            $this->buyer->id,
            [
                'participant_role' => 'buyer',
                'joined_at' => now(),
            ]
        );
    }

    public function test_admin_can_save_internal_note_without_changing_status(): void
    {
        $response = $this
            ->actingAs($this->admin)
            ->postJson(
                route(
                    'admin.disputes.notes.store',
                    $this->dispute
                ),
                [
                    'note' => 'Buyer evidence has been reviewed.',
                ]
            );

        $response
            ->assertOk()
            ->assertJson([
                'message' => 'Internal note saved.',
            ]);

        $this->dispute->refresh();

        $this->assertSame(
            'open',
            $this->dispute->status
        );

        $this->assertNull(
            $this->dispute->resolved_at
        );

        $this->assertDatabaseHas(
            'dispute_events',
            [
                'dispute_id' => $this->dispute->id,
                'event_type' => 'internal_note',
                'actor_user_id' => $this->admin->id,
                'from_status' => 'open',
                'to_status' => 'open',
                'note' => 'Buyer evidence has been reviewed.',
            ]
        );

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'actor_user_id' => $this->admin->id,
                'action' => 'dispute.internal_note_added',
                'module' => 'Compliance & Disputes',
                'auditable_id' => $this->dispute->id,
            ]
        );
    }

    public function test_admin_can_resolve_dispute(): void
    {
        $response = $this
            ->actingAs($this->admin)
            ->postJson(
                route(
                    'admin.disputes.resolve',
                    $this->dispute
                ),
                [
                    'outcome' => 'buyer_favored',
                    'note' => 'The submitted evidence supports the buyer claim.',
                ]
            );

        $response
            ->assertOk()
            ->assertJson([
                'message' => 'Dispute resolved successfully.',
            ]);

        $this->dispute->refresh();

        $this->assertSame(
            'resolved',
            $this->dispute->status
        );

        $this->assertSame(
            $this->admin->id,
            $this->dispute->assigned_to
        );

        $this->assertNotNull(
            $this->dispute->resolved_at
        );

        $this->assertSame(
            'The submitted evidence supports the buyer claim.',
            $this->dispute->resolution
        );

        $this->assertDatabaseHas(
            'dispute_events',
            [
                'dispute_id' => $this->dispute->id,
                'event_type' => 'resolved',
                'actor_user_id' => $this->admin->id,
                'from_status' => 'open',
                'to_status' => 'resolved',
                'note' => 'The submitted evidence supports the buyer claim.',
            ]
        );

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'actor_user_id' => $this->admin->id,
                'action' => 'dispute.resolved',
                'module' => 'Compliance & Disputes',
                'auditable_id' => $this->dispute->id,
            ]
        );
    }

    public function test_resolution_event_stores_selected_outcome_in_metadata(): void
    {
        $this
            ->actingAs($this->admin)
            ->postJson(
                route(
                    'admin.disputes.resolve',
                    $this->dispute
                ),
                [
                    'outcome' => 'partial_refund_approved',
                    'note' => 'A partial refund was approved after evidence review.',
                ]
            )
            ->assertOk();

        $event = DisputeEvent::query()
            ->where(
                'dispute_id',
                $this->dispute->id
            )
            ->where(
                'event_type',
                'resolved'
            )
            ->firstOrFail();

        $this->assertSame(
            'partial_refund_approved',
            $event->metadata['outcome']
        );

        $this->assertSame(
            'Partial refund approved',
            $event->metadata['outcome_label']
        );
    }

    public function test_resolved_dispute_cannot_receive_new_internal_note(): void
    {
        $this
            ->actingAs($this->admin)
            ->postJson(
                route(
                    'admin.disputes.resolve',
                    $this->dispute
                ),
                [
                    'outcome' => 'seller_favored',
                    'note' => 'Seller evidence was sufficient.',
                ]
            )
            ->assertOk();

        $response = $this
            ->actingAs($this->admin)
            ->postJson(
                route(
                    'admin.disputes.notes.store',
                    $this->dispute
                ),
                [
                    'note' => 'This should not be accepted.',
                ]
            );

        $response->assertStatus(422);

        $this->assertSame(
            0,
            DisputeEvent::query()
                ->where(
                    'dispute_id',
                    $this->dispute->id
                )
                ->where(
                    'event_type',
                    'internal_note'
                )
                ->count()
        );
    }

    public function test_resolved_dispute_cannot_be_resolved_again(): void
    {
        $this
            ->actingAs($this->admin)
            ->postJson(
                route(
                    'admin.disputes.resolve',
                    $this->dispute
                ),
                [
                    'outcome' => 'buyer_favored',
                    'note' => 'Initial final decision.',
                ]
            )
            ->assertOk();

        $response = $this
            ->actingAs($this->admin)
            ->postJson(
                route(
                    'admin.disputes.resolve',
                    $this->dispute
                ),
                [
                    'outcome' => 'seller_favored',
                    'note' => 'Attempted second decision.',
                ]
            );

        $response->assertStatus(422);

        $this->dispute->refresh();

        $this->assertSame(
            'resolved',
            $this->dispute->status
        );

        $this->assertSame(
            'Initial final decision.',
            $this->dispute->resolution
        );

        $this->assertSame(
            1,
            DisputeEvent::query()
                ->where(
                    'dispute_id',
                    $this->dispute->id
                )
                ->where(
                    'event_type',
                    'resolved'
                )
                ->count()
        );
    }

    public function test_invalid_resolution_outcome_is_rejected(): void
    {
        $response = $this
            ->actingAs($this->admin)
            ->postJson(
                route(
                    'admin.disputes.resolve',
                    $this->dispute
                ),
                [
                    'outcome' => 'rider_investigation',
                    'note' => 'This is not a final resolution outcome.',
                ]
            );

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'outcome',
            ]);

        $this->dispute->refresh();

        $this->assertSame(
            'open',
            $this->dispute->status
        );
    }

    public function test_admin_can_send_case_update_to_active_dispute_participants(): void
    {
        $admin = $this->createAdmin();

        $buyer = $this->createUserWithRole('buyer');

        $seller = $this->createUserWithRole('seller');

        $dispute = $this->createDispute(
            openedBy: $buyer,
            status: 'open'
        );

        $dispute->participants()->attach(
            $buyer->id,
            [
                'participant_role' => 'buyer',
                'joined_at' => now(),
            ]
        );

        $dispute->participants()->attach(
            $seller->id,
            [
                'participant_role' => 'seller',
                'joined_at' => now(),
            ]
        );

        $response = $this
            ->actingAs($admin)
            ->postJson(
                route(
                    'admin.disputes.update',
                    $dispute
                ),
                [
                    'message' => 'Please submit the requested evidence.',
                ]
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'recipient_count',
                2
            )
            ->assertJsonPath(
                'event.text',
                'Please submit the requested evidence.'
            );

        $this->assertSame(
            2,
            Conversation::query()
                ->where(
                    'dispute_id',
                    $dispute->id
                )
                ->count()
        );

        $this->assertSame(
            2,
            Message::query()
                ->whereHas(
                    'conversation',
                    fn ($query) => $query->where(
                        'dispute_id',
                        $dispute->id
                    )
                )
                ->where(
                    'sender_id',
                    $admin->id
                )
                ->where(
                    'body',
                    'Please submit the requested evidence.'
                )
                ->count()
        );

        $this->assertDatabaseHas(
            'dispute_events',
            [
                'dispute_id' => $dispute->id,

                'event_type' => 'participant_update',

                'actor_user_id' => $admin->id,

                'note' => 'Please submit the requested evidence.',
            ]
        );
    }

    public function test_case_update_reuses_existing_dispute_conversations(): void
    {
        $admin = $this->createAdmin();

        $buyer = $this->createUserWithRole('buyer');

        $dispute = $this->createDispute(
            openedBy: $buyer,
            status: 'open'
        );

        $dispute->participants()->attach(
            $buyer->id,
            [
                'participant_role' => 'buyer',
                'joined_at' => now(),
            ]
        );

        $conversation =
            Conversation::query()->create([
                'subject' => "Dispute {$dispute->dispute_no}",

                'dispute_id' => $dispute->id,

                'type' => 'dispute',

                'status' => 'open',

                'created_by' => $admin->id,

                'last_message_at' => null,
            ]);

        $conversation
            ->participants()
            ->attach(
                $admin->id,
                [
                    'participant_role' => 'admin',

                    'last_read_at' => now(),

                    'joined_at' => now(),
                ]
            );

        $conversation
            ->participants()
            ->attach(
                $buyer->id,
                [
                    'participant_role' => 'buyer',

                    'last_read_at' => null,

                    'joined_at' => now(),
                ]
            );

        $response = $this
            ->actingAs($admin)
            ->postJson(
                route(
                    'admin.disputes.update',
                    $dispute
                ),
                [
                    'message' => 'Follow-up update.',
                ]
            );

        $response->assertOk();

        $this->assertSame(
            1,
            Conversation::query()
                ->where(
                    'dispute_id',
                    $dispute->id
                )
                ->count()
        );

        $this->assertDatabaseHas(
            'messages',
            [
                'conversation_id' => $conversation->id,

                'sender_id' => $admin->id,

                'body' => 'Follow-up update.',
            ]
        );
    }

    public function test_finalized_dispute_cannot_receive_case_update(): void
    {
        $admin = $this->createAdmin();

        $buyer = $this->createUserWithRole('buyer');

        $dispute = $this->createDispute(
            openedBy: $buyer,
            status: 'resolved'
        );

        $dispute->participants()->attach(
            $buyer->id,
            [
                'participant_role' => 'buyer',
                'joined_at' => now(),
            ]
        );

        $response = $this
            ->actingAs($admin)
            ->postJson(
                route(
                    'admin.disputes.update',
                    $dispute
                ),
                [
                    'message' => 'This should not be sent.',
                ]
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'dispute'
            );

        $this->assertDatabaseMissing(
            'messages',
            [
                'body' => 'This should not be sent.',
            ]
        );
    }

    public function test_admin_can_download_dispute_evidence(): void
    {
        Storage::fake('local');

        $path =
            'dispute-evidence/test-proof.txt';

        Storage::disk('local')->put(
            $path,
            'Evidence file contents.'
        );

        $evidence =
            DisputeEvidence::query()->create([
                'dispute_id' => $this->dispute->id,

                'uploaded_by' => $this->buyer->id,

                'type' => 'document',

                'file_path' => $path,

                'original_name' => 'buyer-proof.txt',

                'description' => 'Buyer proof',
            ]);

        $response = $this
            ->actingAs($this->admin)
            ->get(
                route(
                    'admin.disputes.evidence.download',
                    $evidence
                )
            );

        $response
            ->assertOk()
            ->assertDownload(
                'buyer-proof.txt'
            );

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'actor_user_id' => $this->admin->id,

                'action' => 'dispute.evidence_downloaded',

                'module' => 'Compliance & Disputes',

                'auditable_id' => $this->dispute->id,
            ]
        );
    }

    public function test_missing_dispute_evidence_file_returns_not_found(): void
    {
        Storage::fake('local');

        $evidence =
            DisputeEvidence::query()->create([
                'dispute_id' => $this->dispute->id,

                'uploaded_by' => $this->buyer->id,

                'type' => 'document',

                'file_path' => 'dispute-evidence/missing-file.pdf',

                'original_name' => 'missing-file.pdf',

                'description' => 'Missing evidence',
            ]);

        $this
            ->actingAs($this->admin)
            ->get(
                route(
                    'admin.disputes.evidence.download',
                    $evidence
                )
            )
            ->assertNotFound();
    }

    public function test_non_admin_cannot_download_dispute_evidence(): void
    {
        Storage::fake('local');

        $path =
            'dispute-evidence/private-proof.txt';

        Storage::disk('local')->put(
            $path,
            'Private dispute evidence.'
        );

        $evidence =
            DisputeEvidence::query()->create([
                'dispute_id' => $this->dispute->id,

                'uploaded_by' => $this->buyer->id,

                'type' => 'document',

                'file_path' => $path,

                'original_name' => 'private-proof.txt',

                'description' => 'Private evidence',
            ]);

        $this
            ->actingAs($this->buyer)
            ->get(
                route(
                    'admin.disputes.evidence.download',
                    $evidence
                )
            )
            ->assertForbidden();
    }
}

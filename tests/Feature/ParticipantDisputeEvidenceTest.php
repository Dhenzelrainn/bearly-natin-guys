<?php

namespace Tests\Feature;

use App\Models\Dispute;
use App\Models\DisputeEvent;
use App\Models\DisputeEvidence;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ParticipantDisputeEvidenceTest extends TestCase
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

    private function createDispute(
        User $openedBy,
        string $status = 'open'
    ): Dispute {
        return Dispute::query()->create([
            'dispute_no' => 'DSP-EVIDENCE-'.fake()->unique()->numerify('####'),

            'seller_order_id' => null,
            'return_request_id' => null,
            'shipment_id' => null,

            'opened_by' => $openedBy->id,

            'subject' => 'Evidence upload test',

            'description' => 'Dispute created for participant evidence testing.',

            'priority' => 'normal',

            'status' => $status,

            'amount_minor' => 100000,

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
                    ? 'Resolved before evidence upload.'
                    : null,
        ]);
    }

    public function test_participant_can_upload_dispute_evidence(): void
    {
        Storage::fake('local');

        $buyer =
            $this->createUserWithRole('buyer');

        $dispute =
            $this->createDispute($buyer);

        $dispute
            ->participants()
            ->attach(
                $buyer->id,
                [
                    'participant_role' => 'buyer',

                    'joined_at' => now(),
                ]
            );

        $file =
            UploadedFile::fake()->create(
                'buyer-proof.pdf',
                500,
                'application/pdf'
            );

        $response = $this
            ->actingAs($buyer)
            ->post(
                route(
                    'disputes.evidence.store',
                    $dispute
                ),
                [
                    'file' => $file,

                    'description' => 'Buyer proof document',
                ]
            );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'message',
                'Evidence submitted successfully.'
            )
            ->assertJsonPath(
                'evidence.name',
                'buyer-proof.pdf'
            );

        $this->assertDatabaseHas(
            'dispute_evidence',
            [
                'dispute_id' => $dispute->id,

                'uploaded_by' => $buyer->id,

                'original_name' => 'buyer-proof.pdf',

                'description' => 'Buyer proof document',
            ]
        );

        $evidence =
            DisputeEvidence::query()
                ->where(
                    'dispute_id',
                    $dispute->id
                )
                ->firstOrFail();

        Storage::disk('local')
            ->assertExists(
                $evidence->file_path
            );

        $this->assertDatabaseHas(
            'dispute_events',
            [
                'dispute_id' => $dispute->id,

                'event_type' => 'evidence_submitted',

                'actor_user_id' => $buyer->id,
            ]
        );
    }

    public function test_non_participant_cannot_upload_dispute_evidence(): void
    {
        Storage::fake('local');

        $buyer =
            $this->createUserWithRole('buyer');

        $otherBuyer =
            $this->createUserWithRole('buyer');

        $dispute =
            $this->createDispute($buyer);

        $dispute
            ->participants()
            ->attach(
                $buyer->id,
                [
                    'participant_role' => 'buyer',

                    'joined_at' => now(),
                ]
            );

        $file =
            UploadedFile::fake()->create(
                'unauthorized.pdf',
                250,
                'application/pdf'
            );

        $this
            ->actingAs($otherBuyer)
            ->post(
                route(
                    'disputes.evidence.store',
                    $dispute
                ),
                [
                    'file' => $file,
                ]
            )
            ->assertNotFound();

        $this->assertDatabaseMissing(
            'dispute_evidence',
            [
                'dispute_id' => $dispute->id,

                'uploaded_by' => $otherBuyer->id,
            ]
        );
    }

    public function test_finalized_dispute_cannot_receive_participant_evidence(): void
    {
        Storage::fake('local');

        $buyer =
            $this->createUserWithRole('buyer');

        $dispute =
            $this->createDispute(
                $buyer,
                'resolved'
            );

        $dispute
            ->participants()
            ->attach(
                $buyer->id,
                [
                    'participant_role' => 'buyer',

                    'joined_at' => now(),
                ]
            );

        $file =
            UploadedFile::fake()->create(
                'late-proof.pdf',
                250,
                'application/pdf'
            );

        $response = $this
            ->actingAs($buyer)
            ->postJson(
                route(
                    'disputes.evidence.store',
                    $dispute
                ),
                [
                    'file' => $file,
                ]
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'dispute'
            );

        $this->assertDatabaseMissing(
            'dispute_evidence',
            [
                'dispute_id' => $dispute->id,

                'uploaded_by' => $buyer->id,
            ]
        );
    }

    public function test_guest_cannot_upload_dispute_evidence(): void
    {
        Storage::fake('local');

        $buyer =
            $this->createUserWithRole('buyer');

        $dispute =
            $this->createDispute($buyer);

        $file =
            UploadedFile::fake()->create(
                'guest-proof.pdf',
                250,
                'application/pdf'
            );

        $this
            ->post(
                route(
                    'disputes.evidence.store',
                    $dispute
                ),
                [
                    'file' => $file,
                ]
            )
            ->assertRedirect(
                route('login')
            );
    }

    public function test_participant_only_sees_disputes_they_belong_to(): void
    {
        $buyer =
            $this->createUserWithRole('buyer');

        $otherBuyer =
            $this->createUserWithRole('buyer');

        $visibleDispute =
            $this->createDispute($buyer);

        $hiddenDispute =
            $this->createDispute($otherBuyer);

        $visibleDispute
            ->participants()
            ->attach(
                $buyer->id,
                [
                    'participant_role' => 'buyer',

                    'joined_at' => now(),
                ]
            );

        $hiddenDispute
            ->participants()
            ->attach(
                $otherBuyer->id,
                [
                    'participant_role' => 'buyer',

                    'joined_at' => now(),
                ]
            );

        $response = $this
            ->actingAs($buyer)
            ->getJson(
                route('disputes.index')
            );

        $response
            ->assertOk()
            ->assertJsonCount(
                1,
                'disputes'
            )
            ->assertJsonPath(
                'disputes.0.id',
                $visibleDispute->id
            );
    }

    public function test_non_participant_cannot_view_dispute_details(): void
    {
        $buyer =
            $this->createUserWithRole('buyer');

        $otherBuyer =
            $this->createUserWithRole('buyer');

        $dispute =
            $this->createDispute($buyer);

        $dispute
            ->participants()
            ->attach(
                $buyer->id,
                [
                    'participant_role' => 'buyer',

                    'joined_at' => now(),
                ]
            );

        $this
            ->actingAs($otherBuyer)
            ->getJson(
                route(
                    'disputes.show',
                    $dispute
                )
            )
            ->assertNotFound();
    }

    public function test_participant_dispute_detail_hides_internal_notes(): void
    {
        $buyer =
            $this->createUserWithRole('buyer');

        $admin =
            $this->createUserWithRole('admin');

        $dispute =
            $this->createDispute($buyer);

        $dispute
            ->participants()
            ->attach(
                $buyer->id,
                [
                    'participant_role' => 'buyer',

                    'joined_at' => now(),
                ]
            );

        DisputeEvent::query()
            ->create([
                'dispute_id' => $dispute->id,

                'event_type' => 'internal_note',

                'actor_user_id' => $admin->id,

                'from_status' => $dispute->status,

                'to_status' => $dispute->status,

                'note' => 'Admin-only internal note',

                'metadata' => null,

                'created_at' => now(),
            ]);

        DisputeEvent::query()
            ->create([
                'dispute_id' => $dispute->id,

                'event_type' => 'participant_update',

                'actor_user_id' => $admin->id,

                'from_status' => $dispute->status,

                'to_status' => $dispute->status,

                'note' => 'Visible case update',

                'metadata' => null,

                'created_at' => now(),
            ]);

        $response = $this
            ->actingAs($buyer)
            ->getJson(
                route(
                    'disputes.show',
                    $dispute
                )
            );

        $response
            ->assertOk()
            ->assertJsonMissing([
                'text' => 'Admin-only internal note',
            ])
            ->assertJsonFragment([
                'text' => 'Visible case update',
            ]);
    }

    public function test_finalized_dispute_detail_has_no_evidence_upload_url(): void
    {
        $buyer =
            $this->createUserWithRole('buyer');

        $dispute =
            $this->createDispute(
                $buyer,
                'resolved'
            );

        $dispute
            ->participants()
            ->attach(
                $buyer->id,
                [
                    'participant_role' => 'buyer',

                    'joined_at' => now(),
                ]
            );

        $this
            ->actingAs($buyer)
            ->getJson(
                route(
                    'disputes.show',
                    $dispute
                )
            )
            ->assertOk()
            ->assertJsonPath(
                'dispute.evidence_upload_url',
                null
            );
    }
}

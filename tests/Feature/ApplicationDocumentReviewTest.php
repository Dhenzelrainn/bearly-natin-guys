<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\AccountApplication;
use App\Models\ApplicationDocument;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicationDocumentReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@bearly.test')->firstOrFail();
        Storage::fake('local');
    }

    public function test_only_admin_can_view_a_private_application_document(): void
    {
        [$application, $document] = $this->makeDocumentReview();
        Storage::disk('local')->put($document->file_path, 'private document');

        $url = route('admin.application-documents.show', [$application, $document]);

        $this->get($url)->assertRedirect(route('login'));

        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => AccountStatus::Active->value,
        ]);
        $this->actingAs($buyer)->get($url)->assertForbidden();

        $this->actingAs($this->admin)
            ->get($url)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');
    }

    public function test_document_route_rejects_a_document_from_another_application(): void
    {
        [$application] = $this->makeDocumentReview();
        [, $otherDocument] = $this->makeDocumentReview('seller');

        $this->actingAs($this->admin)
            ->get(route('admin.application-documents.show', [$application, $otherDocument]))
            ->assertNotFound();
    }

    public function test_admin_can_verify_a_document_and_start_application_review(): void
    {
        [$application, $document] = $this->makeDocumentReview();

        $this->actingAs($this->admin)
            ->patch(route('admin.application-documents.update', [$application, $document]), [
                'verification_status' => 'verified',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success')
            ->assertSessionHas('review_application_id', $application->id);

        $document->refresh();
        $application->refresh();

        $this->assertSame('verified', $document->verification_status);
        $this->assertSame($this->admin->id, $document->verified_by);
        $this->assertNotNull($document->verified_at);
        $this->assertNull($document->rejection_reason);
        $this->assertSame('under_review', $application->status);
        $this->assertSame($this->admin->id, $application->reviewed_by);
        $this->assertNotNull($application->review_started_at);
    }

    public function test_document_rejection_requires_and_saves_a_reason(): void
    {
        [$application, $document] = $this->makeDocumentReview();
        $url = route('admin.application-documents.update', [$application, $document]);

        $this->actingAs($this->admin)
            ->from(route('admin.registrations'))
            ->patch($url, ['verification_status' => 'rejected'])
            ->assertSessionHasErrors('rejection_reason');

        $this->assertSame('pending', $document->fresh()->verification_status);

        $this->actingAs($this->admin)
            ->patch($url, [
                'verification_status' => 'rejected',
                'rejection_reason' => 'The uploaded ID is unreadable.',
            ])
            ->assertSessionHasNoErrors();

        $document->refresh();
        $this->assertSame('rejected', $document->verification_status);
        $this->assertSame('The uploaded ID is unreadable.', $document->rejection_reason);
    }

    /** @return array{AccountApplication, ApplicationDocument} */
    private function makeDocumentReview(string $role = 'buyer'): array
    {
        $user = User::factory()->create([
            'role' => $role,
            'status' => AccountStatus::Pending->value,
        ]);

        $application = AccountApplication::query()->create([
            'application_no' => 'APP-DOC-'.str()->upper(str()->random(10)),
            'user_id' => $user->id,
            'requested_role_id' => Role::where('name', $role)->value('id'),
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $document = $application->documents()->create([
            'document_type' => 'government_id',
            'file_path' => 'registration-documents/tests/'.str()->random(12).'.pdf',
            'original_name' => 'government-id.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 16,
            'verification_status' => 'pending',
        ]);

        return [$application, $document];
    }
}

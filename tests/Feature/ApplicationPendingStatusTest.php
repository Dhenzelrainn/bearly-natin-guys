<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AccountApplication;
use App\Models\Role;
use App\Models\User;
use App\Services\ApplicationReviewService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationPendingStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_pending_page_and_status_endpoint_reflect_a_live_approval_for_the_same_session(): void
    {
        $buyer = $this->pendingUser(UserRole::Buyer->value, 'buyer-pending@example.test');
        $application = $this->applicationFor($buyer, 'submitted');
        $other = $this->pendingUser(UserRole::Buyer->value, 'other-pending@example.test');
        $this->applicationFor($other, 'submitted');

        $this->post(route('login.submit'), [
            'email' => $buyer->email,
            'password' => 'password',
        ])->assertRedirect(route('application.pending'));

        $this->assertGuest();

        $this->get(route('application.pending', ['user_id' => $other->id, 'email' => $other->email]))
            ->assertOk()
            ->assertSee('Application received')
            ->assertSee('Pending review')
            ->assertSee('data-final="0"', false)
            ->assertDontSee($other->email)
            ->assertDontSee($other->name);

        $this->getJson(route('application.pending.status'))
            ->assertOk()
            ->assertJsonPath('data.state', 'pending')
            ->assertJsonPath('data.is_final', false)
            ->assertJsonPath('data.can_sign_in', false);

        $application->update(['status' => 'approved']);
        $buyer->update(['status' => AccountStatus::Active->value]);

        $this->getJson(route('application.pending.status'))
            ->assertOk()
            ->assertJsonPath('data.state', 'approved')
            ->assertJsonPath('data.status', 'Approved')
            ->assertJsonPath('data.is_final', true)
            ->assertJsonPath('data.can_sign_in', true);

        $this->get(route('application.pending'))
            ->assertOk()
            ->assertSee('Account Verified')
            ->assertSee('Proceed to Sign In')
            ->assertSee('data-final="1"', false);
    }

    public function test_pending_status_is_unavailable_without_a_server_side_registration_session(): void
    {
        $this->get(route('application.pending', [
            'email' => 'private@example.test',
            'user_id' => 999999,
        ]))
            ->assertOk()
            ->assertSee('Application status unavailable')
            ->assertDontSee('private@example.test');

        $this->getJson(route('application.pending.status'))
            ->assertNotFound();
    }

    public function test_rejected_and_revision_states_are_rendered_without_auto_login(): void
    {
        $rejected = $this->pendingUser(UserRole::Buyer->value, 'buyer-rejected@example.test');
        $rejectedApplication = $this->applicationFor($rejected, 'rejected');
        $rejectedApplication->update(['decision_reason' => 'The submitted ID could not be verified.']);
        $rejected->update(['status' => AccountStatus::Rejected->value]);

        $this->withSession([
            ApplicationReviewService::SESSION_KEY => $rejected->id,
        ])->get(route('application.pending'))
            ->assertOk()
            ->assertSee('Application not approved')
            ->assertSee('The submitted ID could not be verified.')
            ->assertSee('data-final="1"', false)
            ->assertSee('Return to sign in')
            ->assertDontSee('Proceed to Sign In');

        $this->assertGuest();

        $revision = $this->pendingUser(UserRole::Buyer->value, 'buyer-revision@example.test');
        $revisionApplication = $this->applicationFor($revision, 'needs_revision');
        $revisionApplication->update(['revision_notes' => 'Upload a clearer front-side ID image.']);
        $revision->update(['status' => AccountStatus::NeedsRevision->value]);

        $this->withSession([
            ApplicationReviewService::SESSION_KEY => $revision->id,
        ])->get(route('application.pending'))
            ->assertOk()
            ->assertSee('Your application needs an update')
            ->assertSee('Upload a clearer front-side ID image.')
            ->assertSee('data-final="0"', false)
            ->assertSee('Return to sign in')
            ->assertDontSee('Proceed to Sign In');

        $this->assertGuest();
    }

    public function test_logistics_and_rider_pending_screens_use_the_shared_persisted_status(): void
    {
        foreach ([
            UserRole::Logistics->value => 'logistics.register',
            UserRole::Rider->value => 'rider.register',
        ] as $role => $routeName) {
            $user = $this->pendingUser($role, "{$role}-pending@example.test");
            $application = $this->applicationFor($user, 'submitted');

            $this->withSession([
                ApplicationReviewService::SESSION_KEY => $user->id,
            ])->get(route($routeName))
                ->assertOk()
                ->assertSee('Pending review')
                ->assertSee('data-final="0"', false)
                ->assertSee('data-status-url="'.route('application.pending.status').'"', false);

            $application->update(['status' => 'approved']);
            $user->update(['status' => AccountStatus::Active->value]);

            $this->withSession([
                ApplicationReviewService::SESSION_KEY => $user->id,
            ])->get(route($routeName))
                ->assertOk()
                ->assertSee('Account Verified')
                ->assertSee('Proceed to Sign In')
                ->assertSee('data-final="1"', false);
        }
    }

    private function pendingUser(string $role, string $email): User
    {
        return User::factory()->create([
            'name' => Str::headline(Str::before($email, '@')),
            'email' => $email,
            'role' => $role,
            'status' => AccountStatus::Pending->value,
        ]);
    }

    private function applicationFor(User $user, string $status): AccountApplication
    {
        return AccountApplication::query()->create([
            'application_no' => 'APP-'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT),
            'user_id' => $user->id,
            'requested_role_id' => Role::query()->where('name', $user->role)->value('id'),
            'status' => $status,
            'submitted_at' => now(),
        ]);
    }
}

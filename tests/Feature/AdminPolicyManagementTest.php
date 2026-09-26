<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Policy;
use App\Models\PolicyVersion;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPolicyManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@bearly.test')->firstOrFail();
    }

    public function test_policy_page_uses_database_records_and_preserves_original_modals(): void
    {
        $policy = $this->policy();

        $this->actingAs($this->admin)
            ->get(route('admin.policies'))
            ->assertOk()
            ->assertSee($policy->title)
            ->assertSee('data-open-modal="create-policy"', false)
            ->assertSee('data-modal="create-policy"', false)
            ->assertSee('data-open-modal="policy-', false)
            ->assertSee('data-modal="policy-', false)
            ->assertDontSee('POL-1001');
    }

    public function test_admin_can_create_a_draft_policy_and_action_is_audited(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.policies.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $policy = Policy::with('latestVersion')->sole();
        $this->assertSame('draft', $policy->status);
        $this->assertSame('draft', $policy->latestVersion->status);
        $this->assertNull($policy->latestVersion->published_at);
        $this->assertSame('policy.created', AuditLog::sole()->action);
    }

    public function test_admin_can_publish_immediately_when_creating_a_policy(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.policies.store'), $this->payload(['status' => 'published']))
            ->assertSessionHasNoErrors();

        $policy = Policy::with('latestVersion')->sole();
        $this->assertSame('active', $policy->status);
        $this->assertSame('published', $policy->latestVersion->status);
        $this->assertNotNull($policy->latestVersion->published_at);
        $this->assertSame('policy.published', AuditLog::sole()->action);
    }

    public function test_published_content_requires_a_new_version_and_old_version_is_archived_on_publish(): void
    {
        $policy = $this->policy('published');

        $this->actingAs($this->admin)
            ->patch(route('admin.policies.update', $policy), $this->payload(['status' => null]))
            ->assertSessionHasErrors('version');

        $this->actingAs($this->admin)
            ->patch(route('admin.policies.update', $policy), $this->payload([
                'version' => 'v1.1',
                'body' => 'The new version of the policy body.',
                'status' => null,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('draft', $policy->fresh()->status);
        $this->assertDatabaseHas('policy_versions', ['policy_id' => $policy->id, 'version' => 'v1.1', 'status' => 'draft']);

        $this->actingAs($this->admin)
            ->post(route('admin.policies.publish', $policy))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('policy_versions', ['policy_id' => $policy->id, 'version' => 'v1.0', 'status' => 'archived']);
        $this->assertDatabaseHas('policy_versions', ['policy_id' => $policy->id, 'version' => 'v1.1', 'status' => 'published']);
        $this->assertSame('active', $policy->fresh()->status);
    }

    public function test_admin_can_archive_a_policy_and_all_current_versions(): void
    {
        $policy = $this->policy('published');

        $this->actingAs($this->admin)
            ->post(route('admin.policies.archive', $policy))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame('archived', $policy->fresh()->status);
        $this->assertSame('archived', $policy->latestVersion->fresh()->status);
        $this->assertSame('policy.archived', AuditLog::sole()->action);
    }

    private function policy(string $versionStatus = 'draft'): Policy
    {
        $policy = Policy::query()->create([
            'code' => 'POL-TEST-001',
            'title' => 'Test Marketplace Policy',
            'category' => 'Marketplace',
            'status' => $versionStatus === 'published' ? 'active' : 'draft',
        ]);
        $policy->versions()->create([
            'version' => 'v1.0',
            'summary' => 'Test summary.',
            'body' => 'Test policy body.',
            'status' => $versionStatus,
            'created_by' => $this->admin->id,
            'effective_at' => $versionStatus === 'published' ? now() : null,
            'published_at' => $versionStatus === 'published' ? now() : null,
        ]);

        return $policy->fresh('latestVersion');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Marketplace Rules',
            'category' => 'Marketplace',
            'version' => 'v1.0',
            'summary' => 'Rules for marketplace participants.',
            'body' => 'All marketplace participants must comply with platform requirements.',
            'status' => 'draft',
        ], $overrides);
    }
}

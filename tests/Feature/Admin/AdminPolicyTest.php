<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Policy;
use App\Models\PolicyVersion;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function adminFixture(): User
    {
        $admin = User::factory()->create([
            'name' => 'Admin User',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $adminRole = Role::query()->firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']);
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    private function policyPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Marketplace Policy',
            'category' => 'Marketplace',
            'version' => 'v1.0',
            'summary' => 'General marketplace rules.',
            'body' => 'Buyers, Sellers, Logistics Centers, and Riders must follow platform rules.',
            'action' => 'draft',
        ], $overrides);
    }

    public function test_admin_can_create_a_draft_policy_with_its_first_version(): void
    {
        $admin = $this->adminFixture();

        $this->actingAs($admin)
            ->from(route('admin.policies'))
            ->post(route('admin.policies.store'), $this->policyPayload())
            ->assertRedirect(route('admin.policies'));

        $policy = Policy::query()->firstOrFail();

        $this->assertStringStartsWith('POL-', $policy->code);
        $this->assertSame('Marketplace Policy', $policy->title);
        $this->assertSame('Marketplace', $policy->category);
        $this->assertSame('draft', $policy->status);

        $version = PolicyVersion::query()->firstOrFail();

        $this->assertSame($policy->id, $version->policy_id);
        $this->assertSame('v1.0', $version->version);
        $this->assertSame('draft', $version->status);
        $this->assertSame($admin->id, $version->created_by);
        $this->assertNull($version->published_at);

        $this->assertTrue(
            AuditLog::query()
                ->where('action', 'policy.created')
                ->where('module', 'policies')
                ->where('actor_user_id', $admin->id)
                ->exists()
        );
    }

    public function test_admin_can_publish_a_policy_immediately_on_creation(): void
    {
        $this->actingAs($this->adminFixture())
            ->post(route('admin.policies.store'), $this->policyPayload(['action' => 'publish']))
            ->assertRedirect();

        $policy = Policy::query()->firstOrFail();
        $version = PolicyVersion::query()->firstOrFail();

        $this->assertSame('active', $policy->status);
        $this->assertSame('published', $version->status);
        $this->assertNotNull($version->published_at);
        $this->assertNotNull($version->effective_at);
    }

    public function test_updating_a_policy_creates_a_new_version_and_preserves_previous_ones(): void
    {
        $admin = $this->adminFixture();

        $this->actingAs($admin)->post(route('admin.policies.store'), $this->policyPayload([
            'action' => 'publish',
        ]));

        $policy = Policy::query()->firstOrFail();

        $this->actingAs($admin)
            ->from(route('admin.policies'))
            ->patch(route('admin.policies.update', $policy), [
                'title' => 'Marketplace Policy v2',
                'category' => 'Transactions',
                'version' => 'v1.1',
                'summary' => 'Clarified transaction rules.',
                'body' => 'Updated marketplace rules with clarified transaction expectations.',
            ])
            ->assertRedirect(route('admin.policies'));

        $policy->refresh();

        $this->assertSame('Marketplace Policy v2', $policy->title);
        $this->assertSame('Transactions', $policy->category);
        $this->assertSame('active', $policy->status);

        $this->assertSame(2, $policy->versions()->count());

        $first = $policy->versions()->where('version', 'v1.0')->firstOrFail();
        $second = $policy->versions()->where('version', 'v1.1')->firstOrFail();

        $this->assertSame('published', $first->status);
        $this->assertSame('draft', $second->status);
        $this->assertSame($admin->id, $second->created_by);

        $this->assertTrue(
            AuditLog::query()
                ->where('action', 'policy.updated')
                ->where('auditable_id', $policy->id)
                ->exists()
        );
    }

    public function test_reusing_a_version_label_is_rejected_so_history_stays_intact(): void
    {
        $admin = $this->adminFixture();

        $this->actingAs($admin)->post(route('admin.policies.store'), $this->policyPayload());

        $policy = Policy::query()->firstOrFail();

        $this->actingAs($admin)
            ->from(route('admin.policies'))
            ->patch(route('admin.policies.update', $policy), [
                'title' => 'Marketplace Policy',
                'category' => 'Marketplace',
                'version' => 'v1.0',
                'summary' => 'Replacement attempt.',
                'body' => 'This attempt should be rejected.',
            ])
            ->assertSessionHasErrors(['version']);

        $this->assertSame(1, $policy->versions()->count());
        $this->assertSame(
            'Buyers, Sellers, Logistics Centers, and Riders must follow platform rules.',
            $policy->versions()->firstOrFail()->body
        );
    }

    public function test_publishing_a_draft_version_archives_the_previous_published_version(): void
    {
        $admin = $this->adminFixture();

        $this->actingAs($admin)->post(route('admin.policies.store'), $this->policyPayload([
            'action' => 'publish',
        ]));

        $policy = Policy::query()->firstOrFail();

        $this->actingAs($admin)->patch(route('admin.policies.update', $policy), [
            'title' => $policy->title,
            'category' => $policy->category,
            'version' => 'v1.1',
            'summary' => 'Second revision.',
            'body' => 'Second revision content.',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.policies'))
            ->post(route('admin.policies.publish', $policy))
            ->assertRedirect(route('admin.policies'));

        $policy->refresh();

        $this->assertSame('active', $policy->status);
        $this->assertSame(
            'archived',
            $policy->versions()->where('version', 'v1.0')->firstOrFail()->status
        );
        $this->assertSame(
            'published',
            $policy->versions()->where('version', 'v1.1')->firstOrFail()->status
        );

        $this->assertTrue(
            AuditLog::query()
                ->where('action', 'policy.published')
                ->where('auditable_id', $policy->id)
                ->exists()
        );
    }

    public function test_publishing_a_policy_without_a_draft_version_is_rejected(): void
    {
        $admin = $this->adminFixture();

        $this->actingAs($admin)->post(route('admin.policies.store'), $this->policyPayload([
            'action' => 'publish',
        ]));

        $policy = Policy::query()->firstOrFail();

        $this->actingAs($admin)
            ->from(route('admin.policies'))
            ->post(route('admin.policies.publish', $policy))
            ->assertRedirect(route('admin.policies'))
            ->assertSessionHas('warning');

        $this->assertSame(
            'published',
            $policy->versions()->firstOrFail()->status
        );
    }

    public function test_archived_policies_keep_versions_but_cannot_be_edited_or_published(): void
    {
        $admin = $this->adminFixture();

        $this->actingAs($admin)->post(route('admin.policies.store'), $this->policyPayload([
            'action' => 'publish',
        ]));

        $policy = Policy::query()->firstOrFail();

        $this->actingAs($admin)
            ->from(route('admin.policies'))
            ->post(route('admin.policies.archive', $policy))
            ->assertRedirect(route('admin.policies'));

        $this->assertSame('archived', $policy->refresh()->status);
        $this->assertSame(1, $policy->versions()->count());
        $this->assertTrue(
            AuditLog::query()
                ->where('action', 'policy.archived')
                ->where('auditable_id', $policy->id)
                ->exists()
        );

        $this->actingAs($admin)
            ->from(route('admin.policies'))
            ->patch(route('admin.policies.update', $policy), [
                'title' => $policy->title,
                'category' => $policy->category,
                'version' => 'v2.0',
                'summary' => null,
                'body' => 'Should not be saved.',
            ])
            ->assertRedirect(route('admin.policies'))
            ->assertSessionHas('warning');

        $this->assertSame(1, $policy->versions()->count());

        $this->actingAs($admin)
            ->from(route('admin.policies'))
            ->post(route('admin.policies.publish', $policy))
            ->assertRedirect(route('admin.policies'))
            ->assertSessionHas('warning');
    }

    public function test_policy_store_validates_required_and_allowed_values(): void
    {
        $this->actingAs($this->adminFixture())
            ->post(route('admin.policies.store'), [
                'title' => '',
                'category' => 'Unknown Category',
                'version' => '',
                'body' => '',
                'action' => 'archive',
            ])
            ->assertSessionHasErrors(['title', 'category', 'version', 'body', 'action']);

        $this->assertSame(0, Policy::query()->count());
    }

    public function test_admin_can_view_the_policy_library(): void
    {
        $admin = $this->adminFixture();

        $this->actingAs($admin)->post(route('admin.policies.store'), $this->policyPayload());

        $this->actingAs($admin)
            ->get(route('admin.policies'))
            ->assertOk()
            ->assertSee('Marketplace Policy')
            ->assertSee('POL-')
            ->assertSee('v1.0');
    }

    public function test_non_admin_users_cannot_manage_policies(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $this->actingAs($buyer)->get(route('admin.policies'))->assertForbidden();

        $this->actingAs($buyer)
            ->post(route('admin.policies.store'), $this->policyPayload())
            ->assertForbidden();

        $this->assertSame(0, Policy::query()->count());
    }
}

<?php

namespace Tests\Feature\Logistics;

use App\Events\ShippingPolicySubmissionAvailable;
use App\Models\AuditLog;
use App\Models\LogisticsProfile;
use App\Models\Policy;
use App\Models\Role;
use App\Models\ShippingPolicySetting;
use App\Models\User;
use App\Services\ShippingPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ShippingPolicyManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_assign_and_remove_the_shared_logistics_policy_manager_with_audit_reason(): void
    {
        $admin = $this->user('admin', 'active', 'Policy Admin');
        [$manager, $profile] = $this->logistics('manager');
        [$inactiveUser, $inactiveProfile] = $this->logistics('inactive', 'suspended');

        $this->actingAs($admin)
            ->get(route('admin.users.logistics'))
            ->assertOk()
            ->assertSee('Appointed policy manager')
            ->assertSee($profile->display_name);

        $this->actingAs($admin)
            ->from(route('admin.users.logistics'))
            ->post(route('admin.users.logistics.shipping-policy-manager'), [
                'action' => 'assign',
                'logistics_profile_id' => $profile->id,
                'reason' => 'Appointed for provider-wide policy maintenance.',
            ])
            ->assertRedirect(route('admin.users.logistics'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('shipping_policy_settings', [
            'provider_key' => config('shipping.provider_key'),
            'manager_logistics_profile_id' => $profile->id,
            'updated_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'shipping_policy_manager.updated',
            'actor_user_id' => $admin->id,
            'description' => 'Appointed for provider-wide policy maintenance.',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.users.logistics'))
            ->post(route('admin.users.logistics.shipping-policy-manager'), [
                'action' => 'assign',
                'logistics_profile_id' => $inactiveProfile->id,
                'reason' => 'Attempted inactive assignment.',
            ])
            ->assertRedirect(route('admin.users.logistics'))
            ->assertSessionHasErrors('logistics_profile_id');

        $this->assertSame($profile->id, ShippingPolicySetting::query()->firstOrFail()->manager_logistics_profile_id);
        $this->assertSame('suspended', $inactiveUser->fresh()->status);

        $this->actingAs($admin)
            ->from(route('admin.users.logistics'))
            ->post(route('admin.users.logistics.shipping-policy-manager'), [
                'action' => 'remove',
                'logistics_profile_id' => '',
                'reason' => 'Removed outgoing policy manager.',
            ])
            ->assertRedirect(route('admin.users.logistics'));

        $this->assertNull(ShippingPolicySetting::query()->firstOrFail()->manager_logistics_profile_id);
        $this->assertSame(2, AuditLog::query()->where('action', 'shipping_policy_manager.updated')->count());
        $this->assertDatabaseMissing('shipping_policy_settings', [
            'manager_logistics_profile_id' => $manager->logisticsProfile->id,
        ]);
    }

    public function test_only_the_appointed_active_logistics_account_can_manage_shared_policies(): void
    {
        $admin = $this->user('admin', 'active', 'Policy Admin');
        [$manager, $managerProfile] = $this->logistics('manager');
        [$other, $otherProfile] = $this->logistics('other');
        $this->appoint($admin, $managerProfile);

        $this->actingAs($manager->fresh())
            ->get(route('logistics.shipping-policies.index'))
            ->assertOk()
            ->assertSee('You are the appointed Bearly Logistics policy manager.')
            ->assertSee('Create a policy');

        $this->actingAs($manager)
            ->post(route('admin.users.logistics.shipping-policy-manager'), [
                'action' => 'assign',
                'logistics_profile_id' => $otherProfile->id,
                'reason' => 'Attempted unauthorized manager reassignment.',
            ])
            ->assertForbidden();

        $this->assertSame($managerProfile->id, ShippingPolicySetting::query()->firstOrFail()->manager_logistics_profile_id);

        $managerProfile->update(['status' => 'suspended']);

        $this->actingAs($manager->fresh())
            ->get(route('logistics.shipping-policies.index'))
            ->assertOk()
            ->assertSee('is still listed as the policy manager, but their Logistics profile or account is inactive.')
            ->assertDontSee('Create a policy');

        $this->actingAs($manager->fresh())
            ->post(route('logistics.shipping-policies.store'), $this->policyPayload())
            ->assertForbidden();

        $managerProfile->update(['status' => 'active']);

        $this->actingAs($other)
            ->get(route('logistics.shipping-policies.index'))
            ->assertOk()
            ->assertSee('Published rules are shared across Logistics accounts.')
            ->assertSee($manager->name)
            ->assertDontSee('Create a policy');

        $this->actingAs($other)
            ->post(route('logistics.shipping-policies.store'), $this->policyPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('policies', 0);
        $this->assertNotNull($otherProfile);
    }

    public function test_admin_approval_controls_publication_and_pending_versions_remain_private(): void
    {
        $admin = $this->user('admin', 'active', 'Policy Admin');
        [$manager, $managerProfile] = $this->logistics('manager');
        [$reader] = $this->logistics('reader');
        $this->appoint($admin, $managerProfile);

        $this->actingAs($manager)
            ->get(route('logistics.shipping-policies.index'))
            ->assertOk()
            ->assertSee('Save as Draft')
            ->assertDontSee('Publish policy');

        $this->actingAs($manager)
            ->from(route('logistics.shipping-policies.index'))
            ->post(route('logistics.shipping-policies.store'), $this->policyPayload())
            ->assertRedirect(route('logistics.shipping-policies.index'));

        $policy = Policy::query()->firstOrFail();
        $firstVersion = $policy->versions()->firstOrFail();
        $this->assertSame('shipping_provider', $policy->scope);
        $this->assertSame(config('shipping.provider_key'), $policy->scope_key);
        $this->assertSame('draft', $firstVersion->status);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $manager->id,
            'action' => 'shipping_policy.created',
            'auditable_type' => Policy::class,
            'auditable_id' => $policy->id,
            'description' => null,
        ]);

        $this->actingAs($manager)
            ->from(route('logistics.shipping-policies.index'))
            ->post(route('logistics.shipping-policies.store'), $this->policyPayload([
                'action' => 'publish',
            ]))
            ->assertRedirect(route('logistics.shipping-policies.index'))
            ->assertSessionHasErrors('action');

        $this->assertSame('draft', $firstVersion->fresh()->status);
        $this->assertDatabaseCount('policies', 1);

        $this->actingAs($manager)
            ->from(route('logistics.shipping-policies.index'))
            ->post(route('logistics.shipping-policies.submit-for-approval', $policy), [
                'reason' => 'Submitted without the source reference.',
            ])
            ->assertRedirect(route('logistics.shipping-policies.index'))
            ->assertSessionHasErrors('source_reference');

        $this->assertSame('draft', $firstVersion->fresh()->status);

        $this->actingAs($manager)
            ->from(route('logistics.shipping-policies.index'))
            ->post(route('logistics.shipping-policies.submit-for-approval', $policy), [
                'source_reference' => 'Bearly Logistics Standard BL-OPS-01, revision 3',
            ])
            ->assertRedirect(route('logistics.shipping-policies.index'))
            ->assertSessionHasErrors('reason');

        $this->assertSame('draft', $firstVersion->fresh()->status);

        $this->actingAs($manager)
            ->post(route('logistics.shipping-policies.submit-for-approval', $policy), [
                'source_reference' => 'Bearly Logistics Standard BL-OPS-01, revision 3',
                'reason' => 'Submitted the initial guidance for Admin review.',
            ])
            ->assertRedirect(route('logistics.shipping-policies.index'));

        $this->assertSame('pending_approval', $firstVersion->fresh()->status);
        $this->assertSame('Bearly Logistics Standard BL-OPS-01, revision 3', $firstVersion->fresh()->source_reference);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $firstVersion::class,
            'auditable_id' => $firstVersion->id,
            'action' => 'shipping_policy.submitted_for_approval',
            'description' => 'Submitted the initial guidance for Admin review.',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.shipping-policy-approvals.index'))
            ->assertOk()
            ->assertSee('Bearly Logistics Handling Rules')
            ->assertSee('Bearly Logistics Standard BL-OPS-01, revision 3')
            ->assertSee('Submitted the initial guidance for Admin review.');

        $this->actingAs($manager)
            ->post(route('admin.shipping-policy-approvals.approve', [$policy->id, $firstVersion->id]), [
                'source_reviewed' => '1',
                'reason' => 'Attempted approval by the submitting manager.',
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->from(route('admin.shipping-policy-approvals.index'))
            ->post(route('admin.shipping-policy-approvals.approve', [$policy->id, $firstVersion->id]), [
                'reason' => 'Tried to publish without confirming source review.',
            ])
            ->assertRedirect(route('admin.shipping-policy-approvals.index'))
            ->assertSessionHasErrors('source_reviewed');

        $this->assertSame('pending_approval', $firstVersion->fresh()->status);

        $this->actingAs($admin)
            ->post(route('admin.shipping-policy-approvals.approve', [$policy->id, $firstVersion->id]), [
                'source_reviewed' => '1',
                'reason' => 'Reviewed the cited source and approved the initial guidance.',
            ])
            ->assertRedirect(route('admin.shipping-policy-approvals.index'));

        $this->assertSame('published', $firstVersion->fresh()->status);
        $this->assertSame('active', $policy->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $firstVersion::class,
            'auditable_id' => $firstVersion->id,
            'action' => 'shipping_policy.approved_and_published',
            'description' => 'Reviewed the cited source and approved the initial guidance.',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.shipping-policy-approvals.index'))
            ->assertOk()
            ->assertSee('Approval history')
            ->assertSee('Bearly Logistics Handling Rules')
            ->assertSee('v1.0')
            ->assertSee('Policy Admin')
            ->assertSee('Reviewed the cited source and approved the initial guidance.')
            ->assertSee('Keep products in their original protective packaging.');

        $this->actingAs($reader)
            ->get(route('logistics.shipping-policies.index'))
            ->assertOk()
            ->assertSee('Bearly Logistics Handling Rules')
            ->assertSee('Keep products in their original protective packaging.')
            ->assertSee('v1.0');

        $this->actingAs($manager)
            ->patch(route('logistics.shipping-policies.update', $policy), [
                'title' => 'Revised Bearly Logistics Handling Rules',
                'category' => 'Restricted goods',
                'version' => 'v1.1',
                'summary' => 'Updated draft.',
                'body' => 'Draft-only handling guidance.',
                'source_reference' => 'Bearly Logistics Standard BL-OPS-01, revision 4',
            ])
            ->assertRedirect(route('logistics.shipping-policies.index'));

        $returnedVersion = $policy->versions()->where('version', 'v1.1')->firstOrFail();
        $this->assertSame('published', $firstVersion->fresh()->status);
        $this->assertSame('draft', $returnedVersion->status);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $manager->id,
            'action' => 'shipping_policy.updated',
            'auditable_type' => Policy::class,
            'auditable_id' => $policy->id,
            'description' => null,
        ]);

        $this->actingAs($reader)
            ->get(route('logistics.shipping-policies.index'))
            ->assertOk()
            ->assertSee('Bearly Logistics Handling Rules')
            ->assertSee('Keep products in their original protective packaging.')
            ->assertDontSee('Revised Bearly Logistics Handling Rules')
            ->assertDontSee('Draft-only handling guidance.')
            ->assertDontSee('v1.1');

        $this->actingAs($manager)
            ->post(route('logistics.shipping-policies.submit-for-approval', $policy), [
                'source_reference' => 'Bearly Logistics Standard BL-OPS-01, revision 4',
                'reason' => 'Submitted the revised guidance for Admin review.',
            ])
            ->assertRedirect(route('logistics.shipping-policies.index'));

        $this->assertSame('pending_approval', $returnedVersion->fresh()->status);

        $this->actingAs($reader)
            ->get(route('logistics.shipping-policies.index'))
            ->assertOk()
            ->assertSee('Bearly Logistics Handling Rules')
            ->assertSee('Keep products in their original protective packaging.')
            ->assertDontSee('Revised Bearly Logistics Handling Rules')
            ->assertDontSee('Draft-only handling guidance.')
            ->assertDontSee('v1.1');

        $sellerVisibleWhilePending = app(ShippingPolicyService::class)->publishedPolicies();
        $this->assertCount(1, $sellerVisibleWhilePending);
        $this->assertSame('v1.0', $sellerVisibleWhilePending[0]['published']['version']);
        $this->assertSame(
            'Keep products in their original protective packaging.',
            $sellerVisibleWhilePending[0]['published']['body'],
        );

        $this->actingAs($admin)
            ->from(route('admin.shipping-policy-approvals.index'))
            ->post(route('admin.shipping-policy-approvals.request-changes', [$policy->id, $returnedVersion->id]), [])
            ->assertRedirect(route('admin.shipping-policy-approvals.index'))
            ->assertSessionHasErrors('reason');

        $this->assertSame('pending_approval', $returnedVersion->fresh()->status);

        $this->actingAs($admin)
            ->post(route('admin.shipping-policy-approvals.request-changes', [$policy->id, $returnedVersion->id]), [
                'reason' => 'Clarify which team owns this handling step.',
            ])
            ->assertRedirect(route('admin.shipping-policy-approvals.index'));

        $this->assertSame('changes_requested', $returnedVersion->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $returnedVersion::class,
            'auditable_id' => $returnedVersion->id,
            'action' => 'shipping_policy.changes_requested',
            'description' => 'Clarify which team owns this handling step.',
        ]);

        $this->actingAs($reader)
            ->get(route('logistics.shipping-policies.index'))
            ->assertOk()
            ->assertSee('Bearly Logistics Handling Rules')
            ->assertDontSee('Revised Bearly Logistics Handling Rules')
            ->assertDontSee('Clarify which team owns this handling step.')
            ->assertDontSee('v1.1');

        $this->actingAs($manager)
            ->get(route('logistics.shipping-policies.index'))
            ->assertOk()
            ->assertSee('Admin returned v1.1 for changes.')
            ->assertSee('Admin’s note: Clarify which team owns this handling step.');

        $this->actingAs($manager)
            ->patch(route('logistics.shipping-policies.update', $policy), [
                'title' => 'Revised Bearly Logistics Handling Rules',
                'category' => 'Restricted goods',
                'version' => 'v1.2',
                'summary' => 'Updated after Admin review.',
                'body' => 'Updated handling guidance after review.',
                'source_reference' => 'Bearly Logistics Standard BL-OPS-01, revision 4',
            ])
            ->assertRedirect(route('logistics.shipping-policies.index'));

        $approvedVersion = $policy->versions()->where('version', 'v1.2')->firstOrFail();
        $this->actingAs($manager)
            ->post(route('logistics.shipping-policies.submit-for-approval', $policy), [
                'source_reference' => 'Bearly Logistics Standard BL-OPS-01, revision 5',
                'reason' => 'Submitted the corrected guidance for final review.',
            ])
            ->assertRedirect(route('logistics.shipping-policies.index'));

        $this->actingAs($admin)
            ->post(route('admin.shipping-policy-approvals.approve', [$policy->id, $approvedVersion->id]), [
                'source_reviewed' => '1',
                'reason' => 'Reviewed the updated source and approved the corrected guidance.',
            ])
            ->assertRedirect(route('admin.shipping-policy-approvals.index'));

        $this->actingAs($admin)
            ->get(route('admin.shipping-policy-approvals.index'))
            ->assertOk()
            ->assertSee('Approval history')
            ->assertSee('v1.0')
            ->assertSee('v1.2')
            ->assertDontSee('v1.1');

        $this->assertSame('archived', $firstVersion->fresh()->status);
        $this->assertSame('changes_requested', $returnedVersion->fresh()->status);
        $this->assertSame('published', $approvedVersion->fresh()->status);
        $this->assertSame('Revised Bearly Logistics Handling Rules', $policy->fresh()->title);

        $this->actingAs($reader)
            ->get(route('logistics.shipping-policies.index'))
            ->assertOk()
            ->assertSee('Revised Bearly Logistics Handling Rules')
            ->assertSee('Updated handling guidance after review.')
            ->assertDontSee('Keep products in their original protective packaging.')
            ->assertDontSee('Clarify which team owns this handling step.');

        $publishedForSeller = app(ShippingPolicyService::class)->publishedPolicies();
        $this->assertCount(1, $publishedForSeller);
        $this->assertSame('v1.2', $publishedForSeller[0]['published']['version']);
        $this->assertSame('Updated handling guidance after review.', $publishedForSeller[0]['published']['body']);
        $this->assertSame(10, AuditLog::query()->where('module', 'shipping_policy')->count());
    }

    public function test_platform_policy_library_does_not_expose_or_mutate_provider_scoped_policies(): void
    {
        $admin = $this->user('admin', 'active', 'Policy Admin');
        $policy = Policy::query()->create([
            'code' => 'SHP-SCOPE-ISOLATION',
            'title' => 'Provider-only policy',
            'category' => 'Restricted goods',
            'scope' => 'shipping_provider',
            'scope_key' => config('shipping.provider_key'),
            'status' => 'draft',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.policies'))
            ->assertOk()
            ->assertDontSee('Provider-only policy');

        $this->actingAs($admin)
            ->patch(route('admin.policies.update', $policy), [
                'title' => 'Changed by Admin',
                'category' => 'Marketplace',
                'version' => 'v1.0',
                'body' => 'Must not be changed through the platform policy route.',
            ])
            ->assertNotFound();

        $this->assertSame('Provider-only policy', $policy->fresh()->title);
    }

    public function test_admin_queue_updates_return_only_new_submissions_and_track_removed_pending_ids(): void
    {
        $admin = $this->user('admin', 'active', 'Policy Admin');
        [$manager, $managerProfile] = $this->logistics('manager');
        [$otherLogistics] = $this->logistics('other');
        $this->appoint($admin, $managerProfile);
        Event::fake([ShippingPolicySubmissionAvailable::class]);

        $this->actingAs($manager)
            ->post(route('logistics.shipping-policies.store'), $this->policyPayload())
            ->assertRedirect(route('logistics.shipping-policies.index'));
        $firstPolicy = Policy::query()->orderBy('id')->firstOrFail();
        $firstVersion = $firstPolicy->versions()->firstOrFail();

        $this->actingAs($manager)
            ->post(route('logistics.shipping-policies.submit-for-approval', $firstPolicy), [
                'source_reference' => 'Bearly Logistics Standard BL-OPS-01, revision 3',
                'reason' => 'Submitted the initial guidance for Admin review.',
            ])
            ->assertRedirect(route('logistics.shipping-policies.index'));

        $updatesUrl = route('admin.shipping-policy-approvals.updates');
        $this->actingAs($admin)
            ->get($updatesUrl)
            ->assertOk()
            ->assertJsonPath('pending_ids', [$firstVersion->id])
            ->assertJsonPath('pending_count', 1)
            ->assertJsonPath('added_html', fn (string $html): bool => str_contains($html, 'Bearly Logistics Handling Rules'));

        $this->actingAs($admin)
            ->get(route('admin.shipping-policy-approvals.updates', ['known_ids' => [$firstVersion->id]]))
            ->assertNoContent();

        $this->actingAs($manager)
            ->post(route('logistics.shipping-policies.store'), $this->policyPayload([
                'title' => 'Rider Carry and Handover Rules',
                'body' => 'Record the handover at every operational checkpoint.',
            ]))
            ->assertRedirect(route('logistics.shipping-policies.index'));
        $secondPolicy = Policy::query()->whereKeyNot($firstPolicy->id)->firstOrFail();
        $secondVersion = $secondPolicy->versions()->firstOrFail();

        $this->actingAs($manager)
            ->post(route('logistics.shipping-policies.submit-for-approval', $secondPolicy), [
                'source_reference' => 'Bearly Logistics Standard BL-OPS-02, revision 1',
                'reason' => 'Submitted the rider handover guidance for Admin review.',
            ])
            ->assertRedirect(route('logistics.shipping-policies.index'));

        $this->actingAs($admin)
            ->get(route('admin.shipping-policy-approvals.updates', ['known_ids' => [$firstVersion->id]]))
            ->assertOk()
            ->assertJsonPath('pending_ids', [$firstVersion->id, $secondVersion->id])
            ->assertJsonPath('pending_count', 2)
            ->assertJsonPath('added_html', fn (string $html): bool => str_contains($html, 'Rider Carry and Handover Rules')
                && ! str_contains($html, 'Bearly Logistics Handling Rules'));

        $this->actingAs($admin)
            ->get(route('admin.shipping-policy-approvals.updates', [
                'known_ids' => [$firstVersion->id, $secondVersion->id],
            ]))
            ->assertNoContent();

        $this->actingAs($admin)
            ->post(route('admin.shipping-policy-approvals.request-changes', [$firstPolicy->id, $firstVersion->id]), [
                'reason' => 'Clarify which team owns this handling step.',
            ])
            ->assertRedirect(route('admin.shipping-policy-approvals.index'));

        $this->actingAs($admin)
            ->get(route('admin.shipping-policy-approvals.updates', [
                'known_ids' => [$firstVersion->id, $secondVersion->id],
            ]))
            ->assertOk()
            ->assertJsonPath('pending_ids', [$secondVersion->id])
            ->assertJsonPath('pending_count', 1)
            ->assertJsonPath('added_html', '');

        $this->actingAs($otherLogistics)
            ->get($updatesUrl)
            ->assertForbidden();
    }

    public function test_policy_submission_broadcast_targets_only_active_admin_user_channels(): void
    {
        $admin = $this->user('admin', 'active', 'Policy Admin');
        $suspendedAdmin = $this->user('admin', 'suspended', 'Suspended Admin');
        $secondaryAdmin = $this->user('logistics', 'active', 'Secondary Admin');
        $adminRole = Role::query()->create([
            'name' => 'admin',
            'display_name' => 'Administrator',
        ]);
        $secondaryAdmin->roles()->attach($adminRole);
        [$manager, $managerProfile] = $this->logistics('manager');
        $this->appoint($admin, $managerProfile);

        $this->actingAs($manager)
            ->post(route('logistics.shipping-policies.store'), $this->policyPayload())
            ->assertRedirect(route('logistics.shipping-policies.index'));
        $policy = Policy::query()->firstOrFail();

        Event::fake([ShippingPolicySubmissionAvailable::class]);

        $this->actingAs($manager)
            ->post(route('logistics.shipping-policies.submit-for-approval', $policy), [
                'source_reference' => 'Bearly Logistics Standard BL-OPS-01, revision 3',
                'reason' => 'Submitted the initial guidance for Admin review.',
            ])
            ->assertRedirect(route('logistics.shipping-policies.index'));

        Event::assertDispatched(ShippingPolicySubmissionAvailable::class, function (ShippingPolicySubmissionAvailable $event) use ($admin, $secondaryAdmin, $suspendedAdmin): bool {
            $channels = collect($event->broadcastOn())
                ->map(fn ($channel): string => (string) $channel)
                ->all();
            $expectedAdminIds = [(int) $admin->id, (int) $secondaryAdmin->id];
            sort($expectedAdminIds);

            return $event->adminUserIds === $expectedAdminIds
                && ! in_array((int) $suspendedAdmin->id, $event->adminUserIds, true)
                && $channels === collect($expectedAdminIds)->map(fn (int $id): string => 'private-users.'.$id)->all()
                && $event->broadcastWith() === ['queue_updated' => true];
        });
    }

    public function test_manager_edits_the_latest_draft_in_place_and_cannot_edit_it_after_submission(): void
    {
        $admin = $this->user('admin', 'active', 'Policy Admin');
        [$manager, $managerProfile] = $this->logistics('manager');
        [$other] = $this->logistics('other');
        $this->appoint($admin, $managerProfile);

        $this->actingAs($manager)
            ->post(route('logistics.shipping-policies.store'), $this->policyPayload())
            ->assertRedirect(route('logistics.shipping-policies.index'));

        $policy = Policy::query()->firstOrFail();
        $draft = $policy->versions()->firstOrFail();
        $editPayload = [
            'title' => 'Revised Logistics Handling Rules',
            'category' => 'Restricted goods',
            'summary' => 'Revised draft summary.',
            'body' => 'Revised draft-only handling guidance.',
            'source_reference' => 'Bearly Logistics Standard BL-OPS-02, revision 1',
            'reason' => 'Corrected the unsent draft content.',
        ];

        $this->actingAs($other)
            ->patch(route('logistics.shipping-policies.versions.update', [$policy->id, $draft->id]), $editPayload)
            ->assertForbidden();

        $this->assertSame('Bearly Logistics Handling Rules', $draft->fresh()->title);

        $this->actingAs($manager)
            ->get(route('logistics.shipping-policies.index'))
            ->assertOk()
            ->assertSee('Edit draft v1.0');

        $missingEditReason = $editPayload;
        unset($missingEditReason['reason']);

        $this->actingAs($manager)
            ->from(route('logistics.shipping-policies.index'))
            ->patch(route('logistics.shipping-policies.versions.update', [$policy->id, $draft->id]), $missingEditReason)
            ->assertRedirect(route('logistics.shipping-policies.index'))
            ->assertSessionHasErrors('reason');

        $this->assertSame('Bearly Logistics Handling Rules', $draft->fresh()->title);

        $this->actingAs($manager)
            ->patch(route('logistics.shipping-policies.versions.update', [$policy->id, $draft->id]), $editPayload)
            ->assertRedirect(route('logistics.shipping-policies.index'))
            ->assertSessionHas('success', 'Draft v1.0 was updated. It has not been submitted to Admin.');

        $updatedDraft = $draft->fresh();
        $this->assertSame($draft->id, $updatedDraft->id);
        $this->assertSame('v1.0', $updatedDraft->version);
        $this->assertSame('draft', $updatedDraft->status);
        $this->assertSame('Revised Logistics Handling Rules', $updatedDraft->title);
        $this->assertSame('Restricted goods', $updatedDraft->category);
        $this->assertSame('Revised draft summary.', $updatedDraft->summary);
        $this->assertSame('Revised draft-only handling guidance.', $updatedDraft->body);
        $this->assertSame('Bearly Logistics Standard BL-OPS-02, revision 1', $updatedDraft->source_reference);
        $this->assertSame(1, $policy->versions()->count());

        $editAudit = AuditLog::query()
            ->where('auditable_type', $updatedDraft::class)
            ->where('auditable_id', $updatedDraft->id)
            ->where('action', 'shipping_policy.draft_updated')
            ->firstOrFail();

        $this->assertSame($manager->id, $editAudit->actor_user_id);
        $this->assertSame('Corrected the unsent draft content.', $editAudit->description);
        $this->assertSame([
            'title' => 'Bearly Logistics Handling Rules',
            'category' => 'Packaging and combinations',
            'summary' => 'Initial shipping handling guidance.',
            'body' => 'Keep products in their original protective packaging.',
            'source_reference' => null,
        ], $editAudit->old_values);
        $this->assertSame([
            'title' => 'Revised Logistics Handling Rules',
            'category' => 'Restricted goods',
            'summary' => 'Revised draft summary.',
            'body' => 'Revised draft-only handling guidance.',
            'source_reference' => 'Bearly Logistics Standard BL-OPS-02, revision 1',
        ], $editAudit->new_values);

        $this->actingAs($manager)
            ->patch(route('logistics.shipping-policies.update', $policy), $this->policyPayload([
                'version' => 'v1.1',
                'title' => 'Accidental duplicate draft',
            ]))
            ->assertStatus(409);

        $this->assertSame(1, $policy->versions()->count());

        $this->actingAs($manager)
            ->post(route('logistics.shipping-policies.submit-for-approval', $policy), [
                'source_reference' => $editPayload['source_reference'],
                'reason' => 'Submitted the edited draft for Admin review.',
            ])
            ->assertRedirect(route('logistics.shipping-policies.index'));

        $this->actingAs($manager)
            ->get(route('logistics.shipping-policies.index'))
            ->assertOk()
            ->assertDontSee('Edit draft v1.0');

        $this->actingAs($manager)
            ->patch(route('logistics.shipping-policies.versions.update', [$policy->id, $draft->id]), array_merge(
                $editPayload,
                ['title' => 'Changed after submission'],
            ))
            ->assertStatus(409);

        $this->assertSame('pending_approval', $updatedDraft->fresh()->status);
        $this->assertSame('Revised Logistics Handling Rules', $updatedDraft->fresh()->title);
        $this->assertSame(1, $policy->versions()->count());
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_type', $updatedDraft::class)
            ->where('auditable_id', $updatedDraft->id)
            ->where('action', 'shipping_policy.draft_updated')
            ->count());
    }

    /** @return array<string, mixed> */
    private function policyPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Bearly Logistics Handling Rules',
            'category' => 'Packaging and combinations',
            'version' => 'v1.0',
            'summary' => 'Initial shipping handling guidance.',
            'body' => 'Keep products in their original protective packaging.',
            'source_reference' => '',
        ], $overrides);
    }

    private function appoint(User $admin, LogisticsProfile $profile): void
    {
        $this->actingAs($admin)->post(route('admin.users.logistics.shipping-policy-manager'), [
            'action' => 'assign',
            'logistics_profile_id' => $profile->id,
            'reason' => 'Appointed for provider-wide policy maintenance.',
        ]);
    }

    private function user(string $role, string $status, string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => $role,
            'status' => $status,
        ]);
    }

    /** @return array{0: User, 1: LogisticsProfile} */
    private function logistics(string $suffix, string $userStatus = 'active'): array
    {
        $user = $this->user('logistics', $userStatus, 'Logistics '.ucfirst($suffix));
        $profile = LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => 'Logistics '.$suffix.' Inc.',
            'display_name' => 'Logistics '.ucfirst($suffix),
            'contact_phone' => '+639171234567',
            'status' => 'active',
        ]);

        return [$user->fresh(), $profile];
    }
}

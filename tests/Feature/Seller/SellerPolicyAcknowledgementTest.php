<?php

namespace Tests\Feature\Seller;

use App\Models\Policy;
use App\Models\PolicyAcceptance;
use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use App\Services\SellerPolicyAcknowledgmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class SellerPolicyAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_policy_does_not_block_seller_center(): void
    {
        [$seller] = $this->sellerFixture();
        $this->createPolicy('draft', 'draft');

        $this->actingAs($seller)
            ->get(route('seller.orders'))
            ->assertOk()
            ->assertDontSee('Acknowledge and continue');
    }

    public function test_published_policy_requires_version_specific_acknowledgment_and_reprompts_on_update(): void
    {
        [$seller] = $this->sellerFixture();
        $policy = $this->createPolicy('active', 'published', 'v1.0');

        $this->actingAs($seller)
            ->get(route('seller.orders'))
            ->assertRedirect(route('seller.policies.current'));

        $policyResponse = $this->get(route('seller.policies.current'));
        $policyResponse->assertOk()
            ->assertSee('Seller Conduct &amp; Off-Platform Transactions', false)
            ->assertSee('Version v1.0')
            ->assertSee('Use Bearly-controlled order, payment, COD, and shipment workflows.');
        $this->assertStringContainsString('no-store', (string) $policyResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $policyResponse->headers->get('Cache-Control'));

        $this->post(route('seller.policies.accept'), ['accepted' => '1'])
            ->assertRedirect(route('seller.dashboard'));

        $firstVersion = $policy->versions()->firstOrFail();
        $this->assertDatabaseHas('policy_acceptances', [
            'user_id' => $seller->id,
            'policy_version_id' => $firstVersion->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $seller->id,
            'action' => 'seller_policy.acknowledged',
            'auditable_id' => $firstVersion->id,
        ]);

        $this->get(route('seller.orders'))
            ->assertOk()
            ->assertSee('Seller Policies')
            ->assertSee(route('seller.policies.current'), false);

        $this->get(route('seller.policies.current'))
            ->assertOk()
            ->assertSee('Current Seller policy')
            ->assertSee('Acknowledged', false)
            ->assertSee('You acknowledged version v1.0.')
            ->assertSee(route('seller.policies.current'), false)
            ->assertDontSee('Acknowledge and continue')
            ->assertDontSee('id="seller-policy-accepted"', false);

        $firstVersion->update(['status' => 'archived']);
        $nextVersion = $policy->versions()->create([
            'version' => 'v1.1',
            'body' => 'Updated Seller conduct policy version.',
            'summary' => 'Updated rules.',
            'status' => 'published',
            'published_at' => now(),
            'effective_at' => now(),
        ]);

        $this->get(route('seller.orders'))
            ->assertRedirect(route('seller.policies.current'));

        $this->get(route('seller.policies.current'))
            ->assertOk()
            ->assertSee('Version v1.1')
            ->assertSee('Acknowledge and continue')
            ->assertSee('id="seller-policy-accepted"', false);

        $this->post(route('seller.policies.accept'), ['accepted' => '1'])
            ->assertRedirect(route('seller.dashboard'));

        $this->assertDatabaseHas('policy_acceptances', [
            'user_id' => $seller->id,
            'policy_version_id' => $nextVersion->id,
        ]);
        $this->assertSame(2, PolicyAcceptance::query()->where('user_id', $seller->id)->count());
        $this->get(route('seller.orders'))->assertOk();
    }

    public function test_seller_cannot_submit_acknowledgment_without_explicit_checkbox_consent(): void
    {
        [$seller] = $this->sellerFixture();
        $this->createPolicy('active', 'published');

        $this->actingAs($seller)
            ->from(route('seller.policies.current'))
            ->post(route('seller.policies.accept'), [])
            ->assertSessionHasErrors('accepted');

        $this->assertSame(0, PolicyAcceptance::query()->count());
    }

    public function test_policy_acknowledgments_cannot_be_updated_or_deleted_through_eloquent(): void
    {
        [$seller] = $this->sellerFixture();
        $this->createPolicy('active', 'published');

        $this->actingAs($seller)
            ->post(route('seller.policies.accept'), ['accepted' => '1'])
            ->assertRedirect(route('seller.dashboard'));

        $acceptance = PolicyAcceptance::query()->firstOrFail();
        $acceptance->accepted_at = now()->addHour();

        try {
            $acceptance->save();
            $this->fail('Policy acknowledgment updates must be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame('Policy acknowledgments are immutable.', $exception->getMessage());
        }

        try {
            $acceptance->delete();
            $this->fail('Policy acknowledgment deletion must be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame('Policy acknowledgments are immutable.', $exception->getMessage());
        }

        $this->assertDatabaseCount('policy_acceptances', 1);
    }

    /** @return array{0: User, 1: Store} */
    private function sellerFixture(): array
    {
        $seller = User::factory()->create([
            'name' => 'Policy Seller',
            'role' => 'seller',
            'status' => 'active',
        ]);
        $role = Role::query()->firstOrCreate(['name' => 'seller'], ['display_name' => 'Seller']);
        $seller->roles()->syncWithoutDetaching([$role->id]);

        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Policy Seller Business',
            'standing_status' => 'good_standing',
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => 'Policy Seller Store',
            'slug' => 'policy-seller-store',
            'publication_status' => 'published',
        ]);

        return [$seller, $store];
    }

    private function createPolicy(string $policyStatus, string $versionStatus, string $version = 'v1.0'): Policy
    {
        $policy = Policy::query()->create([
            'code' => SellerPolicyAcknowledgmentService::REQUIRED_POLICY_CODE,
            'title' => 'Seller Conduct & Off-Platform Transactions',
            'category' => 'Seller Compliance',
            'scope' => 'platform',
            'scope_key' => null,
            'status' => $policyStatus,
        ]);
        $policy->versions()->create([
            'version' => $version,
            'body' => 'Use Bearly-controlled order, payment, COD, and shipment workflows.',
            'summary' => 'Seller conduct rules.',
            'status' => $versionStatus,
            'published_at' => $versionStatus === 'published' ? now() : null,
            'effective_at' => $versionStatus === 'published' ? now() : null,
        ]);

        return $policy;
    }
}

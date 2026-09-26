<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\AccountApplication;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleSpecificRegistrationQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@bearly.test')->firstOrFail();
    }

    public function test_each_role_queue_only_displays_its_normalized_applications(): void
    {
        $buyer = $this->makeApplication('buyer', 'Real Buyer');
        $seller = $this->makeApplication('seller', 'Real Seller');
        $logistics = $this->makeApplication('logistics', 'Real Logistics');

        $cases = [
            'admin.registrations.buyers' => [$buyer, 'Real Seller', 'BUY-2043', 'buyer-application-'],
            'admin.registrations.sellers' => [$seller, 'Real Buyer', 'SEL-3101', 'seller-application-'],
            'admin.registrations.logistics' => [$logistics, 'Real Buyer', 'LOG-4101', 'logistics-application-'],
        ];

        foreach ($cases as $route => [$expected, $unexpected, $mockId, $modalPrefix]) {
            $this->actingAs($this->admin)
                ->get(route($route))
                ->assertOk()
                ->assertSee($expected->application_no)
                ->assertSee($expected->user->name)
                ->assertDontSee($unexpected)
                ->assertDontSee($mockId)
                ->assertSee('data-open-modal="'.$modalPrefix, false)
                ->assertSee('data-modal="'.$modalPrefix, false)
                ->assertSee(route('admin.applications.approve', $expected));
        }
    }

    public function test_buyer_queue_can_filter_by_sex(): void
    {
        $female = $this->makeApplication('buyer', 'Female Buyer', 'submitted', ['sex' => 'female']);
        $this->makeApplication('buyer', 'Male Buyer', 'submitted', ['sex' => 'male']);

        $this->actingAs($this->admin)
            ->get(route('admin.registrations.buyers', ['sex' => 'female']))
            ->assertOk()
            ->assertSee($female->application_no)
            ->assertSee('Female Buyer')
            ->assertDontSee('Male Buyer');
    }

    public function test_seller_queue_can_filter_by_category_status_and_search(): void
    {
        $category = Category::where('name', 'Jewelry and Watches')->firstOrFail();
        $matching = $this->makeApplication('seller', 'Matching Seller', 'under_review', [], $category);
        $this->makeApplication('seller', 'Other Seller');

        $this->actingAs($this->admin)
            ->get(route('admin.registrations.sellers', [
                'search' => 'Matching Seller',
                'status' => 'under_review',
                'category' => $category->id,
            ]))
            ->assertOk()
            ->assertSee($matching->application_no)
            ->assertSee('Matching Seller')
            ->assertDontSee('Other Seller');
    }

    private function makeApplication(
        string $role,
        string $name,
        string $status = 'submitted',
        array $userAttributes = [],
        ?Category $category = null,
    ): AccountApplication {
        $user = User::factory()->create(array_merge([
            'name' => $name,
            'role' => $role,
            'status' => $status === 'needs_revision'
                ? AccountStatus::NeedsRevision->value
                : AccountStatus::Pending->value,
            'business_name' => $role === 'buyer' ? null : $name.' Business',
        ], $userAttributes));

        $application = AccountApplication::query()->create([
            'application_no' => 'APP-ROLE-'.str()->upper(str()->random(10)),
            'user_id' => $user->id,
            'requested_role_id' => Role::where('name', $role)->value('id'),
            'business_name' => $user->business_name,
            'business_category_id' => $category?->id,
            'status' => $status,
            'submitted_at' => now(),
        ]);

        $application->documents()->create([
            'document_type' => 'government_id',
            'file_path' => 'test/government-id.pdf',
            'original_name' => 'government-id.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'verification_status' => 'pending',
        ]);

        return $application->load('user');
    }
}

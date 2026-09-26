<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductViolation;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase2To4AdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@bearly.test')->firstOrFail();
    }

    public function test_settings_are_typed_persisted_audited_and_keep_reset_modal(): void
    {
        $this->actingAs($this->admin)->get(route('admin.settings'))
            ->assertOk()->assertSee('data-modal="reset-platform-settings"', false)
            ->assertSee(route('admin.settings.update'));

        $this->actingAs($this->admin)->patch(route('admin.settings.update'), $this->settingsPayload([
            'maintenance_mode' => '1', 'commission_rate' => '12.5',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(true, SystemSetting::where('key', 'maintenance_mode')->firstOrFail()->value);
        $this->assertEquals(12.5, SystemSetting::where('key', 'commission_rate')->firstOrFail()->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated', 'module' => 'system_management']);

        $this->actingAs($this->admin)->post(route('admin.settings.reset'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('system_settings', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.reset']);
    }

    public function test_dashboard_and_audit_log_render_real_data_and_zero_safe_sales(): void
    {
        AuditLog::create(['actor_user_id'=>$this->admin->id,'action'=>'test.activity','module'=>'system_management',
            'description'=>'Real dashboard activity','severity'=>'info','created_at'=>now()]);

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()->assertSee('Test Activity')->assertSee('₱0.00');
        $this->actingAs($this->admin)->get(route('admin.audit-logs', ['search'=>'dashboard']))
            ->assertOk()->assertSee('Real dashboard activity')->assertSee('type="date"', false);
    }

    public function test_seller_products_persist_variants_inventory_and_trigger_scanner(): void
    {
        [$seller] = $this->sellerContext();
        $this->actingAs($seller)->post(route('seller.products.add'), [
            'name'=>'Firearm display item','category'=>'Fashion and Apparel','description'=>'A prohibited firearm listing',
            'sku'=>'TEST-001','price'=>'199.95','stock'=>'5','low_stock_threshold'=>'2','intent'=>'publish',
        ])->assertSessionHasNoErrors()->assertRedirect(route('seller.products'));

        $product = Product::with('variants')->sole();
        $this->assertSame('blocked', $product->compliance_status);
        $this->assertSame('hidden', $product->product_status);
        $this->assertSame(19995, $product->variants->first()->price_minor);
        $this->assertDatabaseHas('inventory_movements', ['variant_id'=>$product->variants->first()->id,'quantity_delta'=>5]);
        $this->assertDatabaseHas('product_compliance_checks', ['product_id'=>$product->id,'trigger'=>'create','status'=>'completed']);
        $this->assertDatabaseHas('product_violations', ['product_id'=>$product->id,'status'=>'flagged']);
    }

    public function test_admin_compliance_decision_is_persisted_audited_and_notifies_seller(): void
    {
        [$seller] = $this->sellerContext();
        $this->actingAs($seller)->post(route('seller.products.add'), [
            'name'=>'Firearm listing','category'=>'Fashion and Apparel','description'=>'firearm',
            'sku'=>'TEST-002','price'=>'50','stock'=>'1','low_stock_threshold'=>'1','intent'=>'publish',
        ]);
        $violation = ProductViolation::sole();

        $this->actingAs($this->admin)->get(route('admin.compliance'))
            ->assertOk()->assertSee($violation->violation_no)->assertSee('data-compliance-decision-form', false);
        $this->actingAs($this->admin)->post(route('admin.compliance.decide', $violation), [
            'decision'=>'warn_seller','note'=>'This prohibited listing violates marketplace policy.',
        ])->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $violation->fresh()->status);
        $this->assertDatabaseHas('seller_warnings', ['violation_id'=>$violation->id,'issued_by'=>$this->admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action'=>'compliance.warn_seller','auditable_id'=>$violation->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id'=>$seller->id]);
    }

    private function sellerContext(): array
    {
        $category = Category::where('name', 'Fashion and Apparel')->firstOrFail();
        $seller = User::factory()->create(['role'=>'seller','status'=>'active']);
        $profile = SellerProfile::create(['user_id'=>$seller->id,'legal_business_name'=>'Test Store',
            'approved_category_id'=>$category->id,'standing_status'=>'good_standing','approved_at'=>now()]);
        $store = Store::create(['seller_profile_id'=>$profile->id,'name'=>'Test Store','slug'=>'test-store','publication_status'=>'published']);

        return [$seller, $profile, $store, $category];
    }

    private function settingsPayload(array $overrides = []): array
    {
        return array_replace([
            'marketplace_name'=>'Bearly','commission_rate'=>'10','marketplace_description'=>'Marketplace description',
            'platform_active'=>'1','maintenance_mode'=>'0','registration_buyer'=>'1','registration_seller'=>'1',
            'registration_logistics'=>'1','registration_rider'=>'1','cancellation_hours'=>'24','settlement_days'=>'7',
        ], $overrides);
    }
}

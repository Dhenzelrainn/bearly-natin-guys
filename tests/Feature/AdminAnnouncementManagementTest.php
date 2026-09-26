<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AnnouncementPublishingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminAnnouncementManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@bearly.test')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_index_displays_database_announcements_and_supports_filters(): void
    {
        $buyer = Role::where('name', 'buyer')->firstOrFail();
        $seller = Role::where('name', 'seller')->firstOrFail();
        $buyerNotice = $this->announcement(['title' => 'Buyer maintenance notice', 'status' => 'published']);
        $buyerNotice->roles()->attach($buyer);
        $sellerNotice = $this->announcement(['title' => 'Seller payout reminder', 'status' => 'draft']);
        $sellerNotice->roles()->attach($seller);

        $this->actingAs($this->admin)
            ->get(route('admin.announcements', ['status' => 'published', 'audience' => 'buyer', 'search' => 'maintenance']))
            ->assertOk()
            ->assertSee('Buyer maintenance notice')
            ->assertDontSee('Seller payout reminder');
    }

    public function test_creation_form_remains_in_the_original_modal_layout(): void
    {
        $announcement = $this->announcement();
        $announcement->roles()->attach(Role::where('name', 'buyer')->firstOrFail());

        $this->actingAs($this->admin)
            ->get(route('admin.announcements'))
            ->assertOk()
            ->assertSee('New Announcement')
            ->assertSee('data-open-modal="create-announcement"', false)
            ->assertSee('data-modal="create-announcement"', false)
            ->assertSee('<th>Author</th>', false)
            ->assertSee('data-open-modal="announcement-', false);
    }

    public function test_all_users_audience_from_the_modal_maps_to_every_platform_role(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.announcements.store'), [
                'title' => 'Everyone notice',
                'body' => 'This notice is for every platform role.',
                'status' => 'draft',
                'audience' => 'all',
            ])
            ->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(
            ['buyer', 'seller', 'logistics', 'rider'],
            Announcement::with('roles')->sole()->roles->pluck('name')->all(),
        );
    }

    public function test_admin_can_create_a_draft_for_selected_audiences_and_action_is_audited(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.announcements.store'), [
                'title' => 'Holiday operations',
                'body' => 'Operating hours will change during the holiday.',
                'status' => 'draft',
                'audience_roles' => ['buyer', 'seller'],
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $announcement = Announcement::with('roles')->sole();
        $log = AuditLog::sole();

        $this->assertSame('draft', $announcement->status);
        $this->assertNull($announcement->publish_at);
        $this->assertNull($announcement->published_at);
        $this->assertEqualsCanonicalizing(['buyer', 'seller'], $announcement->roles->pluck('name')->all());
        $this->assertSame('announcement.created', $log->action);
        $this->assertSame($this->admin->id, $log->actor_user_id);
    }

    public function test_published_announcement_is_timestamped_immediately(): void
    {
        Carbon::setTestNow('2026-09-25 09:30:00');

        $this->actingAs($this->admin)
            ->post(route('admin.announcements.store'), [
                'title' => 'Published now',
                'body' => 'This message is immediately visible.',
                'status' => 'published',
                'expires_at' => '2026-09-26 09:30:00',
                'audience_roles' => ['buyer'],
            ])
            ->assertSessionHasNoErrors();

        $announcement = Announcement::sole();
        $this->assertSame('published', $announcement->status);
        $this->assertTrue($announcement->publish_at->equalTo(now()));
        $this->assertTrue($announcement->published_at->equalTo(now()));
    }

    public function test_scheduling_requires_a_future_publish_date(): void
    {
        Carbon::setTestNow('2026-09-25 09:30:00');

        $this->actingAs($this->admin)
            ->post(route('admin.announcements.store'), [
                'title' => 'Invalid schedule',
                'body' => 'This must not be saved.',
                'status' => 'scheduled',
                'publish_at' => '2026-09-25 09:00:00',
                'audience_roles' => ['buyer'],
            ])
            ->assertSessionHasErrors('publish_at');

        $this->assertDatabaseCount('announcements', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_admin_can_update_status_content_and_audiences(): void
    {
        Carbon::setTestNow('2026-09-25 09:30:00');
        $announcement = $this->announcement();
        $announcement->roles()->attach(Role::where('name', 'buyer')->firstOrFail());

        $this->actingAs($this->admin)
            ->patch(route('admin.announcements.update', $announcement), [
                'title' => 'Updated title',
                'body' => 'Updated announcement body.',
                'status' => 'scheduled',
                'publish_at' => '2026-09-26 10:00:00',
                'expires_at' => '2026-09-27 10:00:00',
                'audience_roles' => ['seller', 'logistics'],
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $announcement->refresh()->load('roles');
        $this->assertSame('Updated title', $announcement->title);
        $this->assertSame('scheduled', $announcement->status);
        $this->assertNull($announcement->published_at);
        $this->assertEqualsCanonicalizing(['seller', 'logistics'], $announcement->roles->pluck('name')->all());
        $this->assertSame('announcement.updated', AuditLog::sole()->action);
    }

    public function test_admin_can_delete_an_announcement_and_audience_links_are_removed(): void
    {
        $announcement = $this->announcement();
        $announcement->roles()->attach(Role::where('name', 'buyer')->firstOrFail());

        $this->actingAs($this->admin)
            ->delete(route('admin.announcements.destroy', $announcement))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('announcements', ['id' => $announcement->id]);
        $this->assertDatabaseMissing('announcement_roles', ['announcement_id' => $announcement->id]);
        $this->assertSame('announcement.deleted', AuditLog::sole()->action);
    }

    public function test_publishing_service_publishes_due_and_archives_expired_announcements(): void
    {
        Carbon::setTestNow('2026-09-25 10:00:00');
        $due = $this->announcement([
            'status' => 'scheduled',
            'publish_at' => now()->subMinute(),
        ]);
        $expired = $this->announcement([
            'status' => 'published',
            'publish_at' => now()->subDays(2),
            'published_at' => now()->subDays(2),
            'expires_at' => now()->subMinute(),
        ]);

        $result = app(AnnouncementPublishingService::class)->process();

        $this->assertSame(['published' => 1, 'archived' => 1], $result);
        $this->assertSame('published', $due->fresh()->status);
        $this->assertTrue($due->fresh()->published_at->equalTo($due->publish_at));
        $this->assertSame('archived', $expired->fresh()->status);
        $this->assertEqualsCanonicalizing(
            ['announcement.published', 'announcement.expired'],
            AuditLog::pluck('action')->all(),
        );
        $this->assertTrue(AuditLog::whereNull('actor_user_id')->count() === 2);
    }

    private function announcement(array $attributes = []): Announcement
    {
        return Announcement::query()->create(array_merge([
            'announcement_no' => 'ANN-'.fake()->unique()->numerify('########'),
            'title' => 'Test announcement',
            'body' => 'Test announcement body.',
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ], $attributes));
    }
}
